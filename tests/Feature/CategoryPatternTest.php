<?php

namespace Tests\Feature;

use App\Models\CategoryRule;
use App\Models\Contact;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\Money\CategorizerContract;
use App\Services\Money\CategorizerService;
use App\Services\Money\PatternDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Recurring merchants scatter because every entry is classified alone.
 * These pin the detection, the fix, and the rule that stops it recurring.
 */
class CategoryPatternTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['lifeos.parser' => 'fake']);
        $this->user = User::factory()->create();
    }

    private function entry(string $title, ?string $category, int $amount = 10_000, ?Contact $contact = null): LedgerEntry
    {
        return LedgerEntry::factory()->for($this->user)->create([
            'direction' => 'payable',
            'title' => $title,
            'category' => $category,
            'amount_mmk' => $amount,
            'contact_id' => $contact?->id,
        ]);
    }

    private function detect(): array
    {
        return app(PatternDetector::class)->detect($this->user);
    }

    // --- Detection -------------------------------------------------------

    /** The real case: one petrol station under two different categories. */
    public function test_a_merchant_split_across_categories_is_flagged(): void
    {
        $this->entry('Max Energy-Thein Phyu', 'Shopping', 100_000);
        $this->entry('Max Energy-Sanchaung', 'Business', 164_000);

        $found = $this->detect();

        $this->assertCount(1, $found);
        $this->assertSame('title:max energy', $found[0]['key']);
        $this->assertSame(2, $found[0]['count']);
        $this->assertSame(264_000, $found[0]['total']);
        $this->assertTrue($found[0]['conflicted']);
        $this->assertEqualsCanonicalizing(['Shopping', 'Business'], $found[0]['current']);
    }

    public function test_a_half_labelled_merchant_is_flagged(): void
    {
        $this->entry('Buy DataPack U9', null);
        $this->entry('Buy DataPack U9', 'Shopping');

        $this->assertTrue($this->detect()[0]['conflicted']);
    }

    public function test_a_merchant_with_no_labels_at_all_is_flagged(): void
    {
        $this->entry('Arkar', null);
        $this->entry('Arkar', null);

        $found = $this->detect();

        $this->assertTrue($found[0]['unlabelled']);
        $this->assertFalse($found[0]['conflicted']);
    }

    /** Consistently filed is not a problem — that would be noise. */
    public function test_a_consistently_categorized_merchant_is_not_flagged(): void
    {
        $this->entry('ChicKing', 'Business');
        $this->entry('ChicKing', 'Business');
        $this->entry('ChicKing', 'Business');

        $this->assertSame([], $this->detect());
    }

    public function test_a_one_off_entry_is_not_a_pattern(): void
    {
        $this->entry('Some shop', null);

        $this->assertSame([], $this->detect());
    }

    /** A linked contact survives spelling drift that a title prefix would not. */
    public function test_entries_cluster_by_contact_before_title(): void
    {
        $contact = Contact::factory()->for($this->user)->create(['name' => 'Kyaw Zaya Min Htut']);
        $this->entry('Kyaw Zaya', 'Shopping', 300_000, $contact);
        $this->entry('KZ Min Htut', null, 317_000, $contact);

        $found = $this->detect();

        $this->assertCount(1, $found);
        $this->assertSame("contact:{$contact->id}", $found[0]['key']);
        $this->assertSame('Kyaw Zaya Min Htut', $found[0]['label']);
        $this->assertSame(617_000, $found[0]['total']);
    }

    public function test_income_is_not_scanned_for_spending_patterns(): void
    {
        LedgerEntry::factory()->count(2)->for($this->user)->create([
            'direction' => 'receivable', 'title' => 'Cargo Pro', 'category' => null,
        ]);

        $this->assertSame([], $this->detect());
    }

    public function test_biggest_money_is_suggested_first(): void
    {
        $this->entry('Small Shop', null, 1_000);
        $this->entry('Small Shop', null, 1_000);
        $this->entry('Big Shop', null, 500_000);
        $this->entry('Big Shop', null, 500_000);

        $this->assertSame('Big Shop', $this->detect()[0]['label']);
    }

    public function test_another_users_entries_are_never_clustered_in(): void
    {
        $other = User::factory()->create();
        LedgerEntry::factory()->count(2)->for($other)->create([
            'direction' => 'payable', 'title' => 'Max Energy', 'category' => null,
        ]);

        $this->assertSame([], $this->detect());
    }

    // --- Applying --------------------------------------------------------

    public function test_accepting_moves_every_entry_and_remembers_the_rule(): void
    {
        $a = $this->entry('Max Energy-Thein Phyu', 'Shopping');
        $b = $this->entry('Max Energy-Sanchaung', 'Business');

        $this->actingAs($this->user)->post('/money/patterns/apply', [
            'key' => 'title:max energy', 'category' => 'Fuel', 'label' => 'Max Energy',
        ])->assertRedirect();

        $this->assertSame('Fuel', $a->fresh()->category);
        $this->assertSame('Fuel', $b->fresh()->category);
        $this->assertDatabaseHas('category_rules', [
            'user_id' => $this->user->id, 'cluster_key' => 'title:max energy', 'category' => 'Fuel',
        ]);
    }

    public function test_an_accepted_cluster_is_not_suggested_again(): void
    {
        $this->entry('Max Energy-Thein Phyu', 'Shopping');
        $this->entry('Max Energy-Sanchaung', 'Business');

        $this->actingAs($this->user)->post('/money/patterns/apply', [
            'key' => 'title:max energy', 'category' => 'Fuel', 'label' => 'Max Energy',
        ]);

        $this->assertSame([], $this->detect());
    }

    public function test_dismissing_silences_a_cluster_without_filing_it(): void
    {
        $a = $this->entry('Arkar', null);

        $this->actingAs($this->user)->post('/money/patterns/dismiss', [
            'key' => 'title:arkar', 'label' => 'Arkar',
        ])->assertRedirect();

        $this->assertNull($a->fresh()->category);
        $this->assertSame([], $this->detect());
    }

    /** The cluster may have grown since the page rendered. */
    public function test_applying_catches_entries_added_after_the_page_loaded(): void
    {
        $this->entry('Max Energy-Thein Phyu', 'Shopping');
        $this->entry('Max Energy-Sanchaung', 'Business');
        $late = $this->entry('Max Energy-Hledan', null);

        $this->actingAs($this->user)->post('/money/patterns/apply', [
            'key' => 'title:max energy', 'category' => 'Fuel', 'label' => 'Max Energy',
        ]);

        $this->assertSame('Fuel', $late->fresh()->category);
    }

    public function test_applying_cannot_touch_another_users_entries(): void
    {
        $other = User::factory()->create();
        $theirs = LedgerEntry::factory()->for($other)->create([
            'direction' => 'payable', 'title' => 'Max Energy', 'category' => 'Shopping',
        ]);
        $this->entry('Max Energy-Thein Phyu', null);

        $this->actingAs($this->user)->post('/money/patterns/apply', [
            'key' => 'title:max energy', 'category' => 'Fuel', 'label' => 'Max Energy',
        ]);

        $this->assertSame('Shopping', $theirs->fresh()->category);
    }

    public function test_patterns_require_login(): void
    {
        $this->post('/money/patterns/apply', [
            'key' => 'title:x', 'category' => 'Fuel', 'label' => 'X',
        ])->assertRedirect('/login');
    }

    // --- The rule pays off later ------------------------------------------

    /** A ruled merchant is filed without the model being asked at all. */
    public function test_a_rule_files_future_entries_with_no_api_call(): void
    {
        CategoryRule::create([
            'user_id' => $this->user->id,
            'cluster_key' => 'title:max energy',
            'category' => 'Fuel',
            'label' => 'Max Energy',
        ]);
        $entry = $this->entry('Max Energy-Hledan', null);

        $this->mock(CategorizerContract::class)->shouldNotReceive('categorize');

        app(CategorizerService::class)->categorize($this->user, collect([$entry]));

        $this->assertSame('Fuel', $entry->fresh()->category);
    }

    public function test_unruled_entries_still_reach_the_model(): void
    {
        CategoryRule::create([
            'user_id' => $this->user->id,
            'cluster_key' => 'title:max energy',
            'category' => 'Fuel',
            'label' => 'Max Energy',
        ]);
        $ruled = $this->entry('Max Energy-Hledan', null);
        $unknown = $this->entry('lunch at shop', null);

        app(CategorizerService::class)->categorize($this->user, collect([$ruled, $unknown]));

        $this->assertSame('Fuel', $ruled->fresh()->category);
        $this->assertSame('Food & Drinks', $unknown->fresh()->category);
    }

    /** A dismissal silences suggestions; it must not file anything. */
    public function test_a_dismissal_rule_does_not_categorize(): void
    {
        CategoryRule::create([
            'user_id' => $this->user->id,
            'cluster_key' => 'title:arkar',
            'category' => null,
            'label' => 'Arkar',
        ]);
        $entry = $this->entry('Arkar something', null);

        app(CategorizerService::class)->categorize($this->user, collect([$entry]));

        $this->assertNull($entry->fresh()->category);
    }

    // --- Naming ----------------------------------------------------------

    public function test_naming_returns_a_category_per_cluster(): void
    {
        $this->entry('lunch at shop', null);
        $this->entry('lunch at shop', 'Shopping');

        $this->actingAs($this->user)->postJson('/money/patterns/name')
            ->assertOk()
            ->assertJsonPath('suggestions.title:lunch at', 'Food & Drinks');
    }

    public function test_a_failing_categorizer_reports_instead_of_erroring(): void
    {
        $this->entry('Arkar', null);
        $this->entry('Arkar', null);

        $this->mock(CategorizerContract::class)
            ->shouldReceive('categorize')->andThrow(new \RuntimeException('API down'));

        $this->actingAs($this->user)->postJson('/money/patterns/name')
            ->assertStatus(422)
            ->assertJsonStructure(['error']);
    }

    public function test_review_page_carries_detected_patterns(): void
    {
        $this->entry('Max Energy-Thein Phyu', 'Shopping');
        $this->entry('Max Energy-Sanchaung', 'Business');

        $this->actingAs($this->user)->get('/money/review')
            ->assertInertia(fn ($page) => $page->has('patterns', 1)
                ->where('patterns.0.label', 'Max Energy-Thein Phyu'));
    }
}
