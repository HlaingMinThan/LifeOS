<?php

namespace Tests\Feature;

use App\Models\CareTask;
use App\Models\Contact;
use App\Models\Idea;
use App\Models\LedgerEntry;
use App\Models\Todo;
use App\Models\User;
use App\Services\DigestBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Before this, every table was global and any logged-in account could read and
 * mutate any record by id. These tests are the fence: each one fails loudly if
 * a query, a route binding or a prompt ever loses its owner again.
 */
class MultiUserScopingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $stranger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->stranger = User::factory()->create();
    }

    public function test_stranger_cannot_reach_another_users_todo(): void
    {
        $todo = Todo::factory()->for($this->owner)->create(['title' => 'private plan']);

        $this->actingAs($this->stranger)->get("/todos/{$todo->id}")->assertNotFound();
        $this->actingAs($this->stranger)->patch("/todos/{$todo->id}", [
            'title' => 'hijacked', 'bucket' => 'work',
        ])->assertNotFound();
        $this->actingAs($this->stranger)->patch("/todos/{$todo->id}/toggle")->assertNotFound();
        $this->actingAs($this->stranger)->patch("/todos/{$todo->id}/focus")->assertNotFound();
        $this->actingAs($this->stranger)->delete("/todos/{$todo->id}")->assertNotFound();

        $todo->refresh();
        $this->assertSame('private plan', $todo->title);
        $this->assertSame('open', $todo->status);
        $this->assertNull($todo->deleted_at);
    }

    public function test_stranger_cannot_reach_another_users_money(): void
    {
        $entry = LedgerEntry::factory()->for($this->owner)->create([
            'title' => 'Gon Khaung', 'status' => 'open',
        ]);

        $this->actingAs($this->stranger)->patch("/ledger/{$entry->id}", [
            'direction' => 'payable', 'title' => 'hijacked', 'amount_mmk' => 1,
        ])->assertNotFound();
        $this->actingAs($this->stranger)->patch("/ledger/{$entry->id}/toggle")->assertNotFound();
        $this->actingAs($this->stranger)->delete("/ledger/{$entry->id}")->assertNotFound();

        $this->assertSame('open', $entry->fresh()->status);
    }

    public function test_stranger_cannot_reach_another_users_care_or_ideas(): void
    {
        $task = CareTask::factory()->for($this->owner)->create();
        $idea = Idea::factory()->for($this->owner)->create();

        $this->actingAs($this->stranger)->patch("/care/{$task->id}/toggle")->assertNotFound();
        $this->actingAs($this->stranger)->delete("/care/{$task->id}")->assertNotFound();
        $this->actingAs($this->stranger)->delete("/ideas/{$idea->id}")->assertNotFound();

        $this->assertTrue($task->fresh()->active);
        $this->assertNull($idea->fresh()->deleted_at);
    }

    /** Undo rewrites records, so an unscoped lookup here would be the worst one. */
    public function test_stranger_cannot_undo_another_users_inbox_event(): void
    {
        $entry = LedgerEntry::factory()->for($this->owner)->create(['status' => 'paid']);
        $event = $this->owner->inboxEvents()->create([
            'raw_text' => 'paid gon khaung 500k',
            'parsed_json' => ['action' => 'mark_paid', '_created' => false],
            'applied' => true,
            'subject_type' => LedgerEntry::class,
            'subject_id' => $entry->id,
        ]);

        $this->actingAs($this->stranger)
            ->postJson("/inbox/undo/{$event->id}")
            ->assertNotFound();

        $this->assertSame('paid', $entry->fresh()->status);
        $this->assertNull($event->fresh()->reverted_at);
    }

    public function test_lists_only_show_your_own_records(): void
    {
        Todo::factory()->for($this->owner)->create(['title' => 'owner todo', 'due_date' => today()]);
        Todo::factory()->for($this->stranger)->create(['title' => 'stranger todo', 'due_date' => today()]);
        LedgerEntry::factory()->for($this->owner)->create(['direction' => 'payable', 'amount_mmk' => 900]);
        LedgerEntry::factory()->for($this->stranger)->create(['direction' => 'payable', 'amount_mmk' => 111]);

        $this->actingAs($this->owner)->get('/')
            ->assertInertia(fn (Assert $page) => $page
                ->has('todayTodos', 1)
                ->where('todayTodos.0.title', 'owner todo')
                ->where('money.toPay', 900));

        $this->actingAs($this->owner)->get('/todos/day/'.today()->toDateString())
            ->assertInertia(fn (Assert $page) => $page->has('todos', 1));

        $this->actingAs($this->stranger)->get('/money')
            ->assertInertia(fn (Assert $page) => $page->has('open', 1));
    }

    public function test_digest_only_reports_its_own_users_data(): void
    {
        Todo::factory()->for($this->owner)->create(['title' => 'owner errand', 'due_date' => today()]);
        Todo::factory()->for($this->stranger)->create(['title' => 'stranger errand', 'due_date' => today()]);

        $digest = app(DigestBuilder::class)->build($this->owner);

        $this->assertStringContainsString('owner errand', $digest);
        $this->assertStringNotContainsString('stranger errand', $digest);
    }

    /** A prompt is sent to a third party — the snapshot leaking would leave the app. */
    public function test_parser_prompt_never_contains_another_users_records(): void
    {
        config(['lifeos.parser' => 'claude', 'lifeos.anthropic.key' => 'test-key']);
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => json_encode([
                'action' => 'add_todo', 'target' => 'buy milk', 'confidence' => 0.9,
            ])]],
        ])]);

        // Names that cannot come from anywhere but the database — the prompt's
        // own few-shot examples mention "Gon Khaung", so that proves nothing.
        Contact::factory()->for($this->owner)->create(['name' => 'Zzyzx Ownersonly']);
        Todo::factory()->for($this->owner)->create(['title' => 'owner secret todo']);
        LedgerEntry::factory()->for($this->owner)->create(['title' => 'owner secret debt']);

        $this->actingAs($this->stranger)
            ->postJson('/inbox/parse', ['text' => 'buy milk'])
            ->assertOk();

        Http::assertSent(function ($request) {
            $system = $request['system'];

            $this->assertStringNotContainsString('Zzyzx Ownersonly', $system);
            $this->assertStringNotContainsString('owner secret todo', $system);
            $this->assertStringNotContainsString('owner secret debt', $system);
            // Guard against the assertions above passing on an empty prompt.
            $this->assertStringContainsString('Known contacts: (none yet)', $system);

            return true;
        });
    }

    public function test_applying_writes_to_the_acting_user(): void
    {
        $this->actingAs($this->stranger)->postJson('/inbox/apply', [
            'raw_text' => 'buy dog food',
            'parsed' => ['action' => 'add_todo', 'target' => 'buy dog food', 'confidence' => 0.9],
        ])->assertOk();

        $this->assertSame(1, $this->stranger->todos()->count());
        $this->assertSame(0, $this->owner->todos()->count());
    }

    /** Focus is single per person, not single across the whole install. */
    public function test_focusing_does_not_clear_another_users_focus(): void
    {
        $ownerFocus = Todo::factory()->for($this->owner)->create(['focused' => true]);
        $strangerTodo = Todo::factory()->for($this->stranger)->create();

        $this->actingAs($this->stranger)
            ->patch("/todos/{$strangerTodo->id}/focus")
            ->assertRedirect();

        $this->assertTrue($ownerFocus->fresh()->focused);
        $this->assertTrue($strangerTodo->fresh()->focused);
    }
}
