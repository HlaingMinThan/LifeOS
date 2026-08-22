<?php

namespace Tests\Feature;

use App\Models\CareTask;
use App\Models\Contact;
use App\Models\LedgerEntry;
use App\Models\Todo;
use App\Services\DigestBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DigestTest extends TestCase
{
    use RefreshDatabase;

    public function test_digest_contains_all_sections(): void
    {
        // The entry's own title is what shows — a linked contact never overrides
        // it, so renaming an entry actually takes effect.
        $contact = Contact::factory()->create(['name' => 'Gon Khaung']);
        LedgerEntry::factory()->create([
            'contact_id' => $contact->id, 'direction' => 'payable', 'amount_mmk' => 500000,
            'title' => 'Gon Khaung car loan',
        ]);
        LedgerEntry::factory()->create(['direction' => 'receivable', 'title' => 'Cargo Pro fee', 'amount_mmk' => 780000]);
        Todo::factory()->create(['title' => 'Renew insurance', 'due_date' => today()->subDays(2)]);
        Todo::factory()->create(['title' => 'Ship order', 'due_date' => today()]);
        CareTask::factory()->create(['title' => 'Send flowers', 'next_run_at' => now()]);

        $digest = app(DigestBuilder::class)->build();

        $this->assertStringContainsString('Send flowers', $digest);
        $this->assertStringContainsString('Renew insurance', $digest);
        $this->assertStringContainsString('Ship order', $digest);
        $this->assertStringContainsString('Gon Khaung car loan — 500,000 Ks', $digest);
        $this->assertStringContainsString('Cargo Pro fee — 780,000 Ks', $digest);
    }

    public function test_digest_todos_are_grouped_by_bucket(): void
    {
        Todo::factory()->create(['title' => 'fb content plan', 'bucket' => 'work']);
        Todo::factory()->create(['title' => 'buy groceries', 'bucket' => 'personal']);

        $digest = app(DigestBuilder::class)->build();

        $work = strpos($digest, 'Work:');
        $personal = strpos($digest, 'Personal:');
        $this->assertNotFalse($work);
        $this->assertNotFalse($personal);
        $this->assertGreaterThan($work, strpos($digest, 'fb content plan'));
        $this->assertGreaterThan($personal, strpos($digest, 'buy groceries'));
        $this->assertLessThan($personal, strpos($digest, 'fb content plan'));
    }

    public function test_undated_open_todos_appear_in_daily_digest(): void
    {
        Todo::factory()->create(['title' => 'Journaling ပြန်ရေးရမယ်']);
        Todo::factory()->done()->create(['title' => 'finished undated']);

        $digest = app(DigestBuilder::class)->build();

        $this->assertStringContainsString('📝 Open (no date):', $digest);
        $this->assertStringContainsString('Journaling ပြန်ရေးရမယ်', $digest);
        $this->assertStringNotContainsString('finished undated', $digest);
    }

    public function test_empty_digest_celebrates(): void
    {
        $this->assertStringContainsString('Nothing needs you today', app(DigestBuilder::class)->build());
    }

    public function test_future_dated_money_stays_out_of_today(): void
    {
        LedgerEntry::factory()->create([
            'direction' => 'receivable', 'title' => 'Cargo Pro income',
            'amount_mmk' => 780000, 'due_date' => today()->addDay(),
        ]);
        LedgerEntry::factory()->create([
            'direction' => 'receivable', 'title' => 'No-date debt', 'amount_mmk' => 50000,
        ]);

        $digest = app(DigestBuilder::class)->build();

        $this->assertStringNotContainsString('Cargo Pro income', $digest);
        $this->assertStringContainsString('No-date debt', $digest); // undated stays visible
    }

    public function test_by_date_view_shows_money_due_that_day(): void
    {
        LedgerEntry::factory()->create([
            'direction' => 'receivable', 'title' => 'Cargo Pro income',
            'amount_mmk' => 780000, 'due_date' => today()->addDay(),
        ]);

        $view = app(DigestBuilder::class)->forDate(today()->addDay());

        $this->assertStringContainsString('Cargo Pro income — 780,000 Ks ← receive', $view);
    }
}
