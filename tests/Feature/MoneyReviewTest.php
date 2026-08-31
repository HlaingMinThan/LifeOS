<?php

namespace Tests\Feature;

use App\Jobs\CategorizeLedgerEntries;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\Money\CategorizerContract;
use App\Services\Money\CategorizerService;
use App\Services\Money\ReviewService;
use App\Services\Telegram\InboxBridge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MoneyReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['lifeos.parser' => 'fake']);
        $this->user = User::factory()->create();
    }

    /** Settled money: the only kind the review counts. */
    private function settled(string $direction, int $amount, string $date, ?string $category = null): LedgerEntry
    {
        return LedgerEntry::factory()->for($this->user)->create([
            'direction' => $direction,
            'amount_mmk' => $amount,
            'status' => 'paid',
            'paid_at' => now()->parse($date.' 12:00'),
            'category' => $category,
        ]);
    }

    private function review(): ReviewService
    {
        return app(ReviewService::class);
    }

    // --- Summaries -------------------------------------------------------

    public function test_month_summary_sums_settled_entries_only(): void
    {
        $this->settled('receivable', 1_000_000, '2026-08-05');
        $this->settled('payable', 400_000, '2026-08-10');
        // Still owed → belongs to outstanding, never to the savings rate.
        LedgerEntry::factory()->for($this->user)->create([
            'direction' => 'payable', 'amount_mmk' => 900_000,
            'status' => 'open', 'due_date' => '2026-08-12',
        ]);

        $summary = $this->review()->monthSummary($this->user, '2026-08');

        $this->assertSame(1_000_000, $summary['income']);
        $this->assertSame(400_000, $summary['expenses']);
        $this->assertSame(600_000, $summary['net']);
        $this->assertSame(60, $summary['savings_rate']);
    }

    public function test_entries_outside_the_month_are_excluded(): void
    {
        $this->settled('payable', 100_000, '2026-07-31');
        $this->settled('payable', 200_000, '2026-08-01');
        $this->settled('payable', 300_000, '2026-09-01');

        $this->assertSame(200_000, $this->review()->monthSummary($this->user, '2026-08')['expenses']);
    }

    /** An entry settled before paid_at existed still has to count somewhere. */
    public function test_paid_entry_without_paid_at_falls_back_to_due_date(): void
    {
        LedgerEntry::factory()->for($this->user)->create([
            'direction' => 'payable', 'amount_mmk' => 50_000,
            'status' => 'paid', 'paid_at' => null, 'due_date' => '2026-08-09',
        ]);

        $this->assertSame(50_000, $this->review()->monthSummary($this->user, '2026-08')['expenses']);
    }

    public function test_savings_rate_is_null_without_income(): void
    {
        $this->settled('payable', 100_000, '2026-08-10');

        $summary = $this->review()->monthSummary($this->user, '2026-08');

        $this->assertNull($summary['savings_rate']);
        $this->assertSame(-100_000, $summary['net']);
    }

    public function test_month_summary_compares_against_the_previous_month(): void
    {
        $this->settled('payable', 100_000, '2026-07-10');
        $this->settled('payable', 150_000, '2026-08-10');

        $summary = $this->review()->monthSummary($this->user, '2026-08');

        $this->assertSame(100_000, $summary['previous']['expenses']);
        $this->assertSame(50, $summary['change']['expenses']); // +50%
    }

    public function test_change_is_null_when_there_is_no_baseline(): void
    {
        $this->settled('payable', 150_000, '2026-08-10');

        $this->assertNull($this->review()->monthSummary($this->user, '2026-08')['change']['expenses']);
    }

    public function test_another_users_money_never_enters_the_review(): void
    {
        $other = User::factory()->create();
        LedgerEntry::factory()->for($other)->create([
            'direction' => 'payable', 'amount_mmk' => 999_000,
            'status' => 'paid', 'paid_at' => now()->parse('2026-08-10 12:00'),
        ]);

        $this->assertSame(0, $this->review()->monthSummary($this->user, '2026-08')['expenses']);
    }

    // --- Categories ------------------------------------------------------

    public function test_spending_is_grouped_by_category_biggest_first(): void
    {
        $this->settled('payable', 300_000, '2026-08-05', 'Food & Drinks');
        $this->settled('payable', 100_000, '2026-08-06', 'Food & Drinks');
        $this->settled('payable', 600_000, '2026-08-07', 'Rent');
        // Income must not appear in a spending breakdown.
        $this->settled('receivable', 900_000, '2026-08-08', 'Salary');

        $categories = $this->review()->monthSummary($this->user, '2026-08')['categories'];

        $this->assertSame('Rent', $categories[0]['category']);
        $this->assertSame(600_000, $categories[0]['total']);
        $this->assertSame(60, $categories[0]['share']);
        $this->assertSame('Food & Drinks', $categories[1]['category']);
        $this->assertSame(400_000, $categories[1]['total']);
        $this->assertSame(2, $categories[1]['count']);
        $this->assertNotContains('Salary', array_column($categories, 'category'));
    }

    public function test_uncategorized_spending_is_grouped_under_a_label(): void
    {
        $this->settled('payable', 100_000, '2026-08-05', null);

        $categories = $this->review()->monthSummary($this->user, '2026-08')['categories'];

        $this->assertSame(LedgerEntry::UNCATEGORIZED, $categories[0]['category']);
    }

    public function test_tiny_categories_collapse_into_other(): void
    {
        $this->settled('payable', 970_000, '2026-08-05', 'Rent');
        $this->settled('payable', 10_000, '2026-08-06', 'Snacks');
        $this->settled('payable', 20_000, '2026-08-07', 'Stamps');

        $categories = $this->review()->monthSummary($this->user, '2026-08')['categories'];

        $this->assertCount(2, $categories);
        $this->assertSame('Other', $categories[1]['category']);
        $this->assertSame(30_000, $categories[1]['total']);
        $this->assertSame(2, $categories[1]['count']);
    }

    public function test_other_carries_its_members_so_they_stay_reachable(): void
    {
        $this->settled('payable', 970_000, '2026-08-05', 'Rent');
        $this->settled('payable', 10_000, '2026-08-06', 'Snacks');
        $this->settled('payable', 20_000, '2026-08-07', 'Stamps');

        $other = $this->review()->monthSummary($this->user, '2026-08')['categories'][1];

        $this->assertSame('Other', $other['category']);
        $this->assertSame(['Stamps', 'Snacks'], array_column($other['members'], 'category'));
        $this->assertSame([], $this->review()->monthSummary($this->user, '2026-08')['categories'][0]['members']);
    }

    // --- Category detail -------------------------------------------------

    public function test_category_detail_lists_transactions_biggest_first(): void
    {
        $this->settled('payable', 50_000, '2026-08-05', 'Food & Drinks');
        $this->settled('payable', 200_000, '2026-08-06', 'Food & Drinks');
        $this->settled('payable', 120_000, '2026-08-07', 'Food & Drinks');
        $this->settled('payable', 900_000, '2026-08-08', 'Rent');

        $detail = $this->review()->categoryDetail($this->user, 'Food & Drinks', '2026-08');

        $this->assertSame(370_000, $detail['total']);
        $this->assertSame(3, $detail['count']);
        $this->assertSame(123_333, $detail['average']);
        $this->assertSame(200_000, $detail['biggest']);
        $this->assertSame([200_000, 120_000, 50_000], array_column($detail['entries'], 'amount_mmk'));
        // Share is of the whole month's spending, matching the breakdown row.
        $this->assertSame(29, $detail['share']);
    }

    public function test_category_detail_excludes_other_categories_and_income(): void
    {
        $this->settled('payable', 100_000, '2026-08-05', 'Food & Drinks');
        $this->settled('payable', 900_000, '2026-08-06', 'Rent');
        $this->settled('receivable', 500_000, '2026-08-07', 'Food & Drinks');

        $detail = $this->review()->categoryDetail($this->user, 'Food & Drinks', '2026-08');

        $this->assertSame(1, $detail['count']);
        $this->assertSame(100_000, $detail['total']);
    }

    /** The label is a display name for NULL, so it has to resolve to those rows. */
    public function test_uncategorized_detail_finds_entries_with_no_label(): void
    {
        $this->settled('payable', 70_000, '2026-08-05', null);
        $this->settled('payable', 30_000, '2026-08-06', 'Rent');

        $detail = $this->review()->categoryDetail($this->user, LedgerEntry::UNCATEGORIZED, '2026-08');

        $this->assertSame(1, $detail['count']);
        $this->assertSame(70_000, $detail['total']);
    }

    public function test_category_detail_compares_against_last_month(): void
    {
        $this->settled('payable', 100_000, '2026-07-10', 'Transport');
        $this->settled('payable', 250_000, '2026-08-10', 'Transport');

        $detail = $this->review()->categoryDetail($this->user, 'Transport', '2026-08');

        $this->assertSame(100_000, $detail['previous']['total']);
        $this->assertSame(150, $detail['change']);
    }

    public function test_category_trend_covers_six_months_oldest_first(): void
    {
        $this->settled('payable', 40_000, '2026-06-10', 'Transport');
        $this->settled('payable', 90_000, '2026-08-10', 'Transport');
        // Outside the six-month window.
        $this->settled('payable', 999_000, '2026-01-10', 'Transport');

        $trend = $this->review()->categoryDetail($this->user, 'Transport', '2026-08')['trend'];

        $this->assertCount(6, $trend);
        $this->assertSame('2026-03', $trend[0]['month']);
        $this->assertSame('2026-08', $trend[5]['month']);
        $this->assertSame(0, $trend[0]['total']);
        $this->assertSame(40_000, $trend[3]['total']);
        $this->assertSame(90_000, $trend[5]['total']);
    }

    public function test_empty_category_detail_does_not_divide_by_zero(): void
    {
        $detail = $this->review()->categoryDetail($this->user, 'Rent', '2026-08');

        $this->assertSame(0, $detail['total']);
        $this->assertSame(0, $detail['count']);
        $this->assertSame(0, $detail['average']);
        $this->assertSame(0, $detail['share']);
        $this->assertNull($detail['change']);
    }

    public function test_category_page_renders(): void
    {
        $this->settled('payable', 100_000, now()->toDateString(), 'Food & Drinks');

        $this->actingAs($this->user)
            ->get('/money/category?name='.urlencode('Food & Drinks'))
            ->assertInertia(fn ($page) => $page
                ->component('os/MoneyCategory')
                ->where('detail.category', 'Food & Drinks')
                ->has('detail.entries', 1)
                ->has('detail.trend', 6));
    }

    public function test_category_page_needs_a_name(): void
    {
        $this->actingAs($this->user)->get('/money/category')->assertNotFound();
    }

    public function test_category_page_shows_nothing_of_another_users_spending(): void
    {
        $other = User::factory()->create();
        LedgerEntry::factory()->for($other)->create([
            'direction' => 'payable', 'amount_mmk' => 999_000, 'category' => 'Rent',
            'status' => 'paid', 'paid_at' => now(),
        ]);

        $this->actingAs($this->user)->get('/money/category?name=Rent')
            ->assertInertia(fn ($page) => $page->where('detail.total', 0)->has('detail.entries', 0));
    }

    public function test_category_page_requires_login(): void
    {
        $this->get('/money/category?name=Rent')->assertRedirect('/login');
    }

    // --- Outstanding -----------------------------------------------------

    public function test_outstanding_buckets_open_entries_by_lateness(): void
    {
        LedgerEntry::factory()->for($this->user)->create([
            'direction' => 'payable', 'amount_mmk' => 50_000, 'due_date' => today()->subDays(3),
        ]);
        LedgerEntry::factory()->for($this->user)->create([
            'direction' => 'receivable', 'amount_mmk' => 80_000, 'due_date' => today()->addDay(),
        ]);
        LedgerEntry::factory()->for($this->user)->create([
            'direction' => 'payable', 'amount_mmk' => 10_000, 'due_date' => null,
        ]);

        $out = $this->review()->outstanding($this->user);

        $this->assertSame(['count' => 1, 'total' => 50_000], $out['overdue']['payable']);
        $this->assertSame(['count' => 1, 'total' => 80_000], $out['this_week']['receivable']);
        $this->assertSame(['count' => 1, 'total' => 10_000], $out['no_date']['payable']);
        $this->assertSame(0, $out['later']['payable']['count']);
    }

    public function test_settled_entries_are_not_outstanding(): void
    {
        $this->settled('payable', 50_000, today()->subDays(3)->toDateString());

        $this->assertSame(0, $this->review()->outstanding($this->user)['overdue']['payable']['count']);
    }

    // --- Indicator -------------------------------------------------------

    public function test_indicator_is_red_when_spending_exceeds_income(): void
    {
        $this->settled('receivable', 100_000, '2026-08-01');
        $this->settled('payable', 300_000, '2026-08-02');

        $indicator = $this->review()->indicator(
            $this->review()->monthSummary($this->user, '2026-08'),
            $this->review()->outstanding($this->user),
        );

        $this->assertSame('bad', $indicator['level']);
    }

    public function test_indicator_is_green_when_saving_well_with_nothing_overdue(): void
    {
        $this->settled('receivable', 1_000_000, '2026-08-01');
        $this->settled('payable', 300_000, '2026-08-02');

        $indicator = $this->review()->indicator(
            $this->review()->monthSummary($this->user, '2026-08'),
            $this->review()->outstanding($this->user),
        );

        $this->assertSame('good', $indicator['level']);
        $this->assertStringContainsString('70%', $indicator['message']);
    }

    /** A thrifty-looking month built on unpaid bills is not a healthy one. */
    public function test_overdue_bills_downgrade_an_otherwise_green_month(): void
    {
        $this->settled('receivable', 1_000_000, '2026-08-01');
        $this->settled('payable', 100_000, '2026-08-02');
        LedgerEntry::factory()->for($this->user)->create([
            'direction' => 'payable', 'amount_mmk' => 700_000, 'due_date' => today()->subDay(),
        ]);

        $indicator = $this->review()->indicator(
            $this->review()->monthSummary($this->user, '2026-08'),
            $this->review()->outstanding($this->user),
        );

        $this->assertSame('watch', $indicator['level']);
        $this->assertStringContainsString('overdue', $indicator['message']);
    }

    public function test_indicator_is_neutral_with_no_settled_activity(): void
    {
        $indicator = $this->review()->indicator(
            $this->review()->monthSummary($this->user, '2026-08'),
            $this->review()->outstanding($this->user),
        );

        $this->assertSame('none', $indicator['level']);
    }

    // --- Pages -----------------------------------------------------------

    public function test_review_page_renders_every_section(): void
    {
        $this->settled('receivable', 500_000, now()->toDateString(), 'Salary');
        $this->settled('payable', 100_000, now()->toDateString(), 'Food & Drinks');

        $this->actingAs($this->user)->get('/money/review')
            ->assertInertia(fn ($page) => $page
                ->component('os/MoneyReview')
                ->has('monthly.categories', 1)
                ->has('thisWeek')
                ->has('lastWeek')
                ->has('outstanding')
                ->where('indicator.level', 'good'));
    }

    public function test_review_page_accepts_a_month_parameter(): void
    {
        $this->actingAs($this->user)->get('/money/review?month=2026-03')
            ->assertInertia(fn ($page) => $page->where('monthly.month', '2026-03'));
    }

    /** /money/review must not be swallowed by /money/day/{date}. */
    public function test_review_route_wins_over_the_day_route(): void
    {
        $this->actingAs($this->user)->get('/money/review')
            ->assertInertia(fn ($page) => $page->component('os/MoneyReview'));
    }

    public function test_money_page_carries_the_review_glance(): void
    {
        $this->actingAs($this->user)->get('/money')
            ->assertInertia(fn ($page) => $page->has('review.indicator')->has('review.savings_rate'));
    }

    public function test_review_page_requires_login(): void
    {
        $this->get('/money/review')->assertRedirect('/login');
    }

    // --- Category rename -------------------------------------------------

    public function test_renaming_a_category_updates_every_entry_carrying_it(): void
    {
        $a = $this->settled('payable', 10_000, '2026-08-01', 'Food');
        $b = $this->settled('payable', 20_000, '2026-08-02', 'Food');
        $c = $this->settled('payable', 30_000, '2026-08-03', 'Rent');

        $this->actingAs($this->user)
            ->post('/money/categories/rename', ['from' => 'Food', 'to' => 'Food & Drinks'])
            ->assertRedirect();

        $this->assertSame('Food & Drinks', $a->fresh()->category);
        $this->assertSame('Food & Drinks', $b->fresh()->category);
        $this->assertSame('Rent', $c->fresh()->category);
    }

    public function test_renaming_cannot_reach_another_users_entries(): void
    {
        $other = User::factory()->create();
        $theirs = LedgerEntry::factory()->for($other)->create(['category' => 'Food']);

        $this->actingAs($this->user)
            ->post('/money/categories/rename', ['from' => 'Food', 'to' => 'Hacked'])
            ->assertRedirect();

        $this->assertSame('Food', $theirs->fresh()->category);
    }

    // --- Categorization --------------------------------------------------

    public function test_a_new_entry_gets_a_category_without_a_queue_worker(): void
    {
        $this->actingAs($this->user)->post('/ledger', [
            'direction' => 'payable',
            'title' => 'lunch at shop',
            'amount_mmk' => 5_000,
        ])->assertRedirect();

        // afterResponse work runs on terminate; the test client triggers it.
        $this->assertSame('Food & Drinks', LedgerEntry::first()->category);
    }

    public function test_a_category_typed_by_hand_is_kept(): void
    {
        $this->actingAs($this->user)->post('/ledger', [
            'direction' => 'payable',
            'title' => 'lunch at shop',
            'amount_mmk' => 5_000,
            'category' => 'Business Meals',
        ])->assertRedirect();

        $this->assertSame('Business Meals', LedgerEntry::first()->category);
    }

    public function test_a_blank_category_is_stored_as_null_not_empty_string(): void
    {
        $entry = LedgerEntry::factory()->for($this->user)->create(['category' => 'Food']);

        $this->actingAs($this->user)->patch("/ledger/{$entry->id}", [
            'direction' => 'payable', 'title' => $entry->title,
            'amount_mmk' => 1_000, 'category' => '   ',
        ])->assertRedirect();

        $this->assertNull($entry->fresh()->category);
    }

    public function test_the_categorize_job_never_overwrites_an_existing_label(): void
    {
        $entry = LedgerEntry::factory()->for($this->user)->create([
            'title' => 'lunch at shop', 'category' => 'Client Lunches',
        ]);

        (new CategorizeLedgerEntries($this->user))->handle(app(CategorizerService::class));

        $this->assertSame('Client Lunches', $entry->fresh()->category);
    }

    /** One request creating many entries must cost one call, not one each. */
    public function test_a_whole_brain_dump_is_categorized_in_a_single_batch(): void
    {
        foreach (['lunch at shop', 'taxi to office', 'electric bill'] as $title) {
            LedgerEntry::factory()->for($this->user)->create([
                'title' => $title, 'category' => null,
            ]);
        }

        $spy = $this->mock(CategorizerContract::class);
        $spy->shouldReceive('categorize')->once()->andReturn([]);

        (new CategorizeLedgerEntries($this->user))->handle(app(CategorizerService::class));
    }

    /** Covers the shared InboxApplier hook: Telegram and screenshots ride it too. */
    public function test_an_entry_from_the_magic_box_gets_a_category(): void
    {
        $parsed = [
            'action' => 'add_payable', 'target' => 'lunch at shop',
            'amount_mmk' => 5_000, 'confidence' => 0.9,
        ];

        $this->actingAs($this->user)->postJson('/inbox/apply', [
            'raw_text' => 'lunch at shop 5000',
            'parsed' => $parsed,
        ])->assertOk();

        $this->assertSame('Food & Drinks', LedgerEntry::first()->category);
    }

    public function test_a_failing_categorizer_does_not_break_saving(): void
    {
        $this->mock(CategorizerContract::class)
            ->shouldReceive('categorize')->andThrow(new \RuntimeException('API down'));

        $this->actingAs($this->user)->post('/ledger', [
            'direction' => 'payable', 'title' => 'lunch at shop', 'amount_mmk' => 5_000,
        ])->assertRedirect();

        $this->assertSame(1, LedgerEntry::count());
        $this->assertNull(LedgerEntry::first()->category);
    }

    public function test_backfill_command_labels_only_unlabelled_entries(): void
    {
        $blank = LedgerEntry::factory()->for($this->user)->create([
            'title' => 'taxi to office', 'category' => null,
        ]);
        $kept = LedgerEntry::factory()->for($this->user)->create([
            'title' => 'lunch at shop', 'category' => 'Client Lunches',
        ]);

        $this->artisan('ledger:categorize')->assertSuccessful();

        $this->assertSame('Transport', $blank->fresh()->category);
        $this->assertSame('Client Lunches', $kept->fresh()->category);
    }

    public function test_existing_categories_are_offered_to_the_categorizer(): void
    {
        LedgerEntry::factory()->for($this->user)->create(['category' => 'Rent']);
        LedgerEntry::factory()->for($this->user)->create(['category' => 'Rent']);
        LedgerEntry::factory()->for($this->user)->create(['category' => LedgerEntry::UNCATEGORIZED]);

        $this->assertSame(['Rent'], app(CategorizerService::class)->existingCategories($this->user));
    }

    // --- Telegram --------------------------------------------------------

    public function test_review_command_reports_the_month(): void
    {
        $this->user->update(['telegram_chat_id' => '12345']);
        $this->settled('receivable', 1_000_000, now()->toDateString(), 'Salary');
        $this->settled('payable', 200_000, now()->toDateString(), 'Food & Drinks');

        $reply = app(InboxBridge::class)->handle(
            ['chat' => ['id' => '12345'], 'text' => '/review'], $this->user,
        );

        $this->assertStringContainsString('📊 Money review', $reply);
        $this->assertStringContainsString('1,000,000 Ks', $reply);
        $this->assertStringContainsString('Food & Drinks', $reply);
        $this->assertStringContainsString('80%', $reply);
    }

    public function test_review_command_lists_overdue_bills(): void
    {
        $this->user->update(['telegram_chat_id' => '12345']);
        LedgerEntry::factory()->for($this->user)->create([
            'direction' => 'payable', 'amount_mmk' => 75_000, 'due_date' => today()->subDays(2),
        ]);

        $reply = app(InboxBridge::class)->handle(
            ['chat' => ['id' => '12345'], 'text' => 'review'], $this->user,
        );

        $this->assertStringContainsString('Outstanding', $reply);
        $this->assertStringContainsString('75,000 Ks', $reply);
    }
}
