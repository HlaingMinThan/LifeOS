<?php

namespace Tests\Feature;

use App\Models\LedgerEntry;
use App\Models\User;
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

    public function test_money_page_caps_settled_history(): void
    {
        LedgerEntry::factory()->create(); // open
        LedgerEntry::factory()->paid()->count(20)->create();

        $this->actingAs($this->user)->get('/money')
            ->assertInertia(fn ($page) => $page
                ->has('open', 1)
                ->has('settled', 15)
                ->where('settledCount', 20));

        $this->actingAs($this->user)->get('/money?all_settled=1')
            ->assertInertia(fn ($page) => $page->has('settled', 20));
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
        $contact = \App\Models\Contact::factory()->create(['name' => 'Cargo Pro']);
        $entry = LedgerEntry::factory()->create([
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

        $this->actingAs($this->user)->get('/money')
            ->assertInertia(fn ($page) => $page
                ->where('open.0.title', 'Cargo Pro — March delivery batch'));

        $this->assertStringContainsString(
            'Cargo Pro — March delivery batch',
            app(\App\Services\DigestBuilder::class)->build(),
        );
    }

    public function test_entry_can_be_edited(): void
    {
        $entry = LedgerEntry::factory()->create([
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
        $entry = LedgerEntry::factory()->paid()->create(['amount_mmk' => 100000]);

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
