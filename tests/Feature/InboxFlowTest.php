<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\InboxEvent;
use App\Models\LedgerEntry;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InboxFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        config(['lifeos.parser' => 'fake']);
    }

    public function test_parse_endpoint_returns_action_without_writing(): void
    {
        $contact = Contact::factory()->create(['name' => 'Gon Khaung', 'aliases' => ['ဂွန်ခေါင်']]);
        LedgerEntry::factory()->create([
            'contact_id' => $contact->id, 'direction' => 'payable', 'title' => 'Gon Khaung loan',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/inbox/parse', ['text' => 'paid gon khaung 500k'])
            ->assertOk()
            ->assertJsonPath('parsed.action', 'mark_paid')
            ->assertJsonPath('parsed.amount_mmk', 500000);

        $this->assertSame('Gon Khaung', $response->json('parsed.target'));
        $this->assertSame(0, InboxEvent::count());
    }

    public function test_burmese_mark_paid_parse(): void
    {
        Contact::factory()->create(['name' => 'Gon Khaung', 'aliases' => ['ဂွန်ခေါင်']]);

        $this->actingAs($this->user)
            ->postJson('/inbox/parse', ['text' => 'ဂွန်ခေါင်ကို ၅ သိန်း ပေးပြီးပြီ'])
            ->assertOk()
            ->assertJsonPath('parsed.action', 'mark_paid')
            ->assertJsonPath('parsed.target', 'Gon Khaung')
            ->assertJsonPath('parsed.amount_mmk', 500000);
    }

    public function test_apply_mark_paid_then_undo_reopens(): void
    {
        $contact = Contact::factory()->create(['name' => 'Gon Khaung']);
        $entry = LedgerEntry::factory()->create([
            'contact_id' => $contact->id, 'direction' => 'payable',
            'title' => 'Gon Khaung loan', 'status' => 'open',
        ]);

        $eventId = $this->actingAs($this->user)->postJson('/inbox/apply', [
            'raw_text' => 'paid gon khaung 500k',
            'parsed' => ['action' => 'mark_paid', 'target' => 'Gon Khaung', 'amount_mmk' => 500000],
        ])->assertOk()->json('event_id');

        $this->assertSame('paid', $entry->fresh()->status);
        $this->assertTrue(InboxEvent::find($eventId)->applied);

        $this->actingAs($this->user)->postJson("/inbox/undo/{$eventId}")->assertOk();

        $this->assertSame('open', $entry->fresh()->status);
        $this->assertNotNull(InboxEvent::find($eventId)->reverted_at);
    }

    public function test_apply_add_receivable_creates_contact_and_undo_removes_entry(): void
    {
        $eventId = $this->actingAs($this->user)->postJson('/inbox/apply', [
            'raw_text' => 'arkar ဆီက 1 သိန်း ရစရာရှိတယ်',
            'parsed' => ['action' => 'add_receivable', 'target' => 'Arkar', 'amount_mmk' => 100000],
        ])->assertOk()->json('event_id');

        $entry = LedgerEntry::receivable()->first();
        $this->assertSame(100000, $entry->amount_mmk);
        $this->assertSame('Arkar', $entry->contact->name);

        $this->actingAs($this->user)->postJson("/inbox/undo/{$eventId}")->assertOk();

        $this->assertSame(0, LedgerEntry::count());
        $this->assertSoftDeleted($entry);
    }

    public function test_apply_complete_todo_fuzzy_matches_existing(): void
    {
        $todo = Todo::factory()->create(['title' => 'FB page video content', 'bucket' => 'work']);

        $this->actingAs($this->user)->postJson('/inbox/apply', [
            'raw_text' => 'fb video content ပြီးပြီ',
            'parsed' => ['action' => 'complete_todo', 'target' => 'fb video content'],
        ])->assertOk();

        $this->assertSame('done', $todo->fresh()->status);
    }

    public function test_unknown_action_returns_validation_error(): void
    {
        $this->actingAs($this->user)->postJson('/inbox/apply', [
            'raw_text' => 'hello how are you',
            'parsed' => ['action' => 'unknown', 'target' => null],
        ])->assertUnprocessable();
    }

    public function test_undo_twice_fails(): void
    {
        $eventId = $this->actingAs($this->user)->postJson('/inbox/apply', [
            'raw_text' => 'mushroom idea မှတ်ထား',
            'parsed' => ['action' => 'add_idea', 'target' => 'Mushroom selling'],
        ])->json('event_id');

        $this->actingAs($this->user)->postJson("/inbox/undo/{$eventId}")->assertOk();
        $this->actingAs($this->user)->postJson("/inbox/undo/{$eventId}")->assertUnprocessable();
    }
}
