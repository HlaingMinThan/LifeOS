<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\DigestBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LedgerCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_money_page_returns_calendar_data_and_capped_lists(): void
    {
        LedgerEntry::factory()->for($this->user)->create(['due_date' => now()->addDay()]); // open, this week

        $this->actingAs($this->user)->get('/money')
            ->assertInertia(fn ($page) => $page
                ->has('month')
                ->has('counts')
                ->has('thisWeek', 1)
                ->has('later')
                ->has('overdue')
                ->has('noDate'));
    }

    public function test_entry_can_be_created_from_money_page(): void
    {
        $this->actingAs($this->user)->post('/ledger', [
            'direction' => 'receivable',
            'title' => 'Cargo Pro delivery fee',
            'amount_mmk' => 780000,
            'due_date' => '2026-07-08',
            'note' => 'second batch',
        ])->assertRedirect();

        $entry = LedgerEntry::first();
        $this->assertSame('receivable', $entry->direction);
        $this->assertSame(780000, $entry->amount_mmk);
        $this->assertSame('open', $entry->status);
        $this->assertSame('2026-07-08', $entry->due_date->toDateString());
        $this->assertSame('second batch', $entry->note);
    }

    public function test_renaming_an_entry_with_a_linked_contact_is_reflected_everywhere(): void
    {
        // Magic-box entries link a contact whose name matches the original
        // title; the rename must win over that link, in app and digest.
        $contact = Contact::factory()->for($this->user)->create(['name' => 'Cargo Pro']);
        $entry = LedgerEntry::factory()->for($this->user)->create([
            'contact_id' => $contact->id,
            'direction' => 'receivable',
            'title' => 'Cargo Pro',
            'amount_mmk' => 780000,
        ]);

        $this->actingAs($this->user)->patch("/ledger/{$entry->id}", [
            'direction' => 'receivable',
            'title' => 'Cargo Pro — March delivery batch',
            'amount_mmk' => 780000,
        ])->assertRedirect();

        $entry->refresh();
        $this->assertSame('Cargo Pro — March delivery batch', $entry->title);

        $this->assertStringContainsString(
            'Cargo Pro — March delivery batch',
            app(DigestBuilder::class)->build($this->user),
        );
    }

    public function test_renaming_away_from_a_contact_drops_the_stale_link(): void
    {
        $contact = Contact::factory()->for($this->user)->create(['name' => 'Gon Khaung']);
        $entry = LedgerEntry::factory()->for($this->user)->create([
            'contact_id' => $contact->id, 'title' => 'Gon Khaung', 'direction' => 'payable',
        ]);

        // Renamed to something unrelated → the link no longer holds.
        $this->actingAs($this->user)->patch("/ledger/{$entry->id}", [
            'direction' => 'payable', 'title' => 'မိန်းမ', 'amount_mmk' => 50000,
        ])->assertRedirect();

        $this->assertNull($entry->fresh()->contact_id);
    }

    public function test_renaming_that_still_names_the_contact_keeps_the_link(): void
    {
        $contact = Contact::factory()->for($this->user)->create([
            'name' => 'Gon Khaung', 'aliases' => ['ဂွန်ခေါင်'],
        ]);
        $entry = LedgerEntry::factory()->for($this->user)->create([
            'contact_id' => $contact->id, 'title' => 'Gon Khaung', 'direction' => 'payable',
        ]);

        // Alias still refers to the same person → keep it.
        $this->actingAs($this->user)->patch("/ledger/{$entry->id}", [
            'direction' => 'payable', 'title' => 'ဂွန်ခေါင် ကားချေးငွေ', 'amount_mmk' => 50000,
        ])->assertRedirect();

        $this->assertSame($contact->id, $entry->fresh()->contact_id);
    }

    public function test_entry_can_be_edited(): void
    {
        $entry = LedgerEntry::factory()->for($this->user)->create([
            'direction' => 'payable', 'title' => 'old', 'amount_mmk' => 100000,
        ]);

        $this->actingAs($this->user)->patch("/ledger/{$entry->id}", [
            'direction' => 'receivable',
            'title' => 'corrected title',
            'amount_mmk' => 250000,
            'due_date' => '2026-07-09',
        ])->assertRedirect();

        $entry->refresh();
        $this->assertSame('receivable', $entry->direction);
        $this->assertSame('corrected title', $entry->title);
        $this->assertSame(250000, $entry->amount_mmk);
    }

    public function test_amount_must_be_positive_integer(): void
    {
        $this->actingAs($this->user)->post('/ledger', [
            'direction' => 'payable',
            'title' => 'bad amount',
            'amount_mmk' => 0,
        ])->assertSessionHasErrors('amount_mmk');

        $this->assertSame(0, LedgerEntry::count());
    }

    public function test_editing_does_not_change_status_or_paid_at(): void
    {
        $entry = LedgerEntry::factory()->for($this->user)->paid()->create(['amount_mmk' => 100000]);

        $this->actingAs($this->user)->patch("/ledger/{$entry->id}", [
            'direction' => $entry->direction,
            'title' => $entry->title,
            'amount_mmk' => 120000,
        ])->assertRedirect();

        $entry->refresh();
        $this->assertSame('paid', $entry->status);
        $this->assertNotNull($entry->paid_at);
        $this->assertSame(120000, $entry->amount_mmk);
    }
}
