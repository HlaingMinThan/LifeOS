<?php

namespace Tests\Feature;

use App\Mail\TeamInvitation;
use App\Models\LedgerEntry;
use App\Models\TeamMember;
use App\Models\Todo;
use App\Models\User;
use App\Services\Team\MentionResolver;
use App\Services\Team\TeamService;
use App\Services\Telegram\InboxBridge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TeamAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['lifeos.parser' => 'fake']);

        $this->owner = User::factory()->create(['name' => 'Boss', 'username' => 'boss']);
        $this->member = User::factory()->create([
            'name' => 'Zayar Win', 'username' => 'zayarwin', 'email' => 'zayar@example.com',
        ]);
    }

    private function joinTeam(): TeamMember
    {
        return $this->owner->teamMembers()->create([
            'member_id' => $this->member->id,
            'email' => $this->member->email,
            'status' => 'accepted',
            'token' => TeamMember::newToken(),
            'accepted_at' => now(),
        ]);
    }

    // --- invitations -----------------------------------------------------

    public function test_inviting_an_existing_account_links_it_and_mails_them(): void
    {
        $this->actingAs($this->owner)
            ->post('/settings/team', ['email' => 'zayar@example.com'])
            ->assertRedirect();

        $invitation = TeamMember::first();
        $this->assertSame('pending', $invitation->status);
        $this->assertSame($this->member->id, $invitation->member_id);
        Mail::assertSent(TeamInvitation::class);
    }

    public function test_an_unregistered_email_can_still_be_invited(): void
    {
        $this->actingAs($this->owner)
            ->post('/settings/team', ['email' => 'nobody@example.com'])
            ->assertRedirect();

        $this->assertNull(TeamMember::first()->member_id);
    }

    public function test_accepting_requires_the_invited_address(): void
    {
        $this->actingAs($this->owner)->post('/settings/team', ['email' => 'zayar@example.com']);
        $token = TeamMember::first()->token;
        $stranger = User::factory()->create();

        // A forwarded link must not be claimable by whoever opens it.
        $this->actingAs($stranger)->post("/invite/{$token}")->assertSessionHasErrors('token');
        $this->assertSame('pending', TeamMember::first()->status);

        $this->actingAs($this->member)->post("/invite/{$token}")->assertRedirect();
        $this->assertSame('accepted', TeamMember::first()->fresh()->status);
    }

    // --- mentions --------------------------------------------------------

    public function test_mention_resolves_a_teammate_and_strips_the_handle(): void
    {
        $this->joinTeam();

        $result = app(MentionResolver::class)
            ->resolve('@zayarwin complete 5 content plan on monday', $this->owner);

        $this->assertTrue($result['assignee']?->is($this->member));
        $this->assertSame('complete 5 content plan on monday', $result['text']);
    }

    public function test_mention_of_a_non_teammate_does_not_resolve(): void
    {
        $stranger = User::factory()->create(['username' => 'stranger']);

        $result = app(MentionResolver::class)->resolve('@stranger do a thing', $this->owner);

        $this->assertNull($result['assignee']);
        $this->assertSame('stranger', $result['handle']);
    }

    public function test_parse_reports_an_unknown_handle_instead_of_assigning(): void
    {
        $this->actingAs($this->owner)
            ->postJson('/inbox/parse', ['text' => '@nobody do a thing'])
            ->assertStatus(422);
    }

    public function test_assigned_todo_lands_in_the_members_list_not_mine(): void
    {
        $this->joinTeam();

        $this->actingAs($this->owner)->postJson('/inbox/apply', [
            'raw_text' => '@zayarwin send the deck',
            'parsed' => ['action' => 'add_todo', 'target' => 'send the deck', 'bucket' => 'work'],
            'assignee_id' => $this->member->id,
        ])->assertOk()->assertJsonPath('assigned_to', 'Zayar Win');

        $todo = Todo::first();
        $this->assertSame($this->member->id, $todo->user_id);
        $this->assertSame($this->owner->id, $todo->assigned_by_id);
        $this->assertSame(0, $this->owner->todos()->count());
    }

    public function test_cannot_assign_to_someone_outside_my_team(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($this->owner)->postJson('/inbox/apply', [
            'raw_text' => 'do a thing',
            'parsed' => ['action' => 'add_todo', 'target' => 'do a thing'],
            'assignee_id' => $stranger->id,
        ])->assertStatus(422);

        $this->assertSame(0, Todo::count());
    }

    public function test_telegram_mention_assigns_and_confirms(): void
    {
        $this->joinTeam();

        $reply = app(InboxBridge::class)->handle(
            ['chat' => ['id' => '1'], 'text' => '@zayarwin buy dog food tomorrow'],
            $this->owner,
        );

        $this->assertStringContainsString('📤 Assigned to Zayar Win', $reply);
        $this->assertSame($this->member->id, Todo::first()->user_id);
    }

    public function test_telegram_mention_of_an_unknown_handle_explains_itself(): void
    {
        $reply = app(InboxBridge::class)->handle(
            ['chat' => ['id' => '1'], 'text' => '@ghost do a thing'],
            $this->owner,
        );

        $this->assertStringContainsString('No teammate matches @ghost', $reply);
        $this->assertSame(0, Todo::count());
    }

    public function test_guest_opening_an_invite_returns_to_it_after_signing_up(): void
    {
        $token = app(TeamService::class)
            ->invite($this->owner, 'new@example.com')->token;

        // As a guest — no actingAs, so the invite must send them to register.
        $this->get("/invite/{$token}")
            ->assertRedirect(route('register', ['email' => 'new@example.com']));

        // Fortify consumes url.intended after registering, landing them back.
        $this->assertSame(route('team.invitation.show', $token), session('url.intended'));
    }

    // --- the privacy boundary -------------------------------------------

    public function test_owner_sees_only_the_todos_they_assigned(): void
    {
        $invitation = $this->joinTeam();

        $assigned = $this->member->todos()->create(['title' => 'the assigned one']);
        $assigned->assignedBy()->associate($this->owner)->save();
        $this->member->todos()->create(['title' => 'private personal todo']);

        $this->actingAs($this->owner)->get("/settings/team/{$invitation->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('os/TeamMember')
                ->has('todos', 1)
                ->where('todos.0.title', 'the assigned one'));
    }

    public function test_owner_cannot_open_a_members_unassigned_todo(): void
    {
        $this->joinTeam();
        $private = $this->member->todos()->create(['title' => 'private']);

        $this->actingAs($this->owner)->get("/todos/{$private->id}")->assertNotFound();
        $this->actingAs($this->owner)->patch("/todos/{$private->id}", [
            'title' => 'hijacked', 'bucket' => 'work',
        ])->assertNotFound();
        $this->actingAs($this->owner)->delete("/todos/{$private->id}")->assertNotFound();
    }

    public function test_being_in_a_team_exposes_nothing_else_of_the_owners(): void
    {
        $this->joinTeam();
        LedgerEntry::factory()->for($this->owner)->create(['title' => 'secret debt']);
        $ownerTodo = $this->owner->todos()->create(['title' => 'owner private todo']);

        // Membership is one-way: the member gains no sight of the owner at all.
        $this->actingAs($this->member)->get("/todos/{$ownerTodo->id}")->assertNotFound();
        $this->actingAs($this->member)->get('/money')
            ->assertInertia(fn ($page) => $page->has('thisWeek', 0)->has('noDate', 0));
    }

    public function test_members_cannot_assign_back_to_the_owner(): void
    {
        $this->joinTeam();

        // One-way by design: the member has no team of their own.
        $this->actingAs($this->member)->postJson('/inbox/apply', [
            'raw_text' => 'do a thing',
            'parsed' => ['action' => 'add_todo', 'target' => 'do a thing'],
            'assignee_id' => $this->owner->id,
        ])->assertStatus(422);
    }

    // --- assigner rights on what they gave -------------------------------

    public function test_owner_can_edit_and_withdraw_an_assigned_todo(): void
    {
        $this->joinTeam();
        $todo = $this->member->todos()->create(['title' => 'draft', 'bucket' => 'work']);
        $todo->assignedBy()->associate($this->owner)->save();

        $this->actingAs($this->owner)->get("/todos/{$todo->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('viewerIsAssigner', true));

        $this->actingAs($this->owner)->patch("/todos/{$todo->id}", [
            'title' => 'clearer brief', 'bucket' => 'work',
        ])->assertRedirect();
        $this->assertSame('clearer brief', $todo->fresh()->title);

        $this->actingAs($this->owner)->delete("/todos/{$todo->id}")->assertRedirect();
        $this->assertSoftDeleted($todo);
    }

    public function test_assignee_sees_it_as_their_own_task(): void
    {
        $this->joinTeam();
        $todo = $this->member->todos()->create(['title' => 'do it']);
        $todo->assignedBy()->associate($this->owner)->save();

        $this->actingAs($this->member)->get("/todos/{$todo->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('viewerIsAssigner', false)
                ->where('todo.assigned_by.name', 'Boss'));
    }

    public function test_revoking_leaves_the_members_todos_alone(): void
    {
        $invitation = $this->joinTeam();
        $todo = $this->member->todos()->create(['title' => 'still mine']);
        $todo->assignedBy()->associate($this->owner)->save();

        $this->actingAs($this->owner)->delete("/settings/team/{$invitation->id}")->assertRedirect();

        // The task stays in their list; only the owner's access ends.
        $this->assertNotSoftDeleted($todo);
        $this->assertFalse($this->owner->fresh()->canAssignTo($this->member));
    }
}
