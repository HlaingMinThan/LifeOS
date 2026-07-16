<?php

namespace Tests\Feature;

use App\Models\Todo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TodoDetailFocusTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_detail_page_renders(): void
    {
        $todo = Todo::factory()->for($this->user)->create(['title' => 'plan trip']);

        $this->actingAs($this->user)->get("/todos/{$todo->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('os/TodoDetail')
                ->where('todo.title', 'plan trip'));
    }

    public function test_rich_note_keeps_formatting_but_strips_scripts(): void
    {
        $todo = Todo::factory()->for($this->user)->create();

        $this->actingAs($this->user)->patch("/todos/{$todo->id}", [
            'title' => 'notes',
            'bucket' => 'personal',
            'note' => '<p><strong>buy</strong> milk</p><script>alert(1)</script><ul><li>eggs</li></ul>',
        ])->assertRedirect();

        $note = $todo->fresh()->note;
        $this->assertStringContainsString('<strong>buy</strong>', $note);
        $this->assertStringContainsString('<li>eggs</li>', $note);
        $this->assertStringNotContainsString('<script>', $note);
    }

    public function test_empty_html_note_stored_as_null(): void
    {
        $todo = Todo::factory()->for($this->user)->create(['note' => 'old']);

        $this->actingAs($this->user)->patch("/todos/{$todo->id}", [
            'title' => 'notes',
            'bucket' => 'personal',
            'note' => '<p></p>',
        ])->assertRedirect();

        $this->assertNull($todo->fresh()->note);
    }

    public function test_focus_is_single_and_toggles(): void
    {
        $a = Todo::factory()->for($this->user)->create(['focused' => true]);
        $b = Todo::factory()->for($this->user)->create();

        // Focusing b clears a.
        $this->actingAs($this->user)->patch("/todos/{$b->id}/focus")->assertRedirect();
        $this->assertFalse($a->fresh()->focused);
        $this->assertTrue($b->fresh()->focused);

        // Toggling b again clears it.
        $this->actingAs($this->user)->patch("/todos/{$b->id}/focus")->assertRedirect();
        $this->assertFalse($b->fresh()->focused);
    }

    public function test_completing_a_focused_todo_clears_focus(): void
    {
        $todo = Todo::factory()->for($this->user)->create(['focused' => true, 'status' => 'open']);

        $this->actingAs($this->user)->patch("/todos/{$todo->id}/toggle")->assertRedirect();

        $todo->refresh();
        $this->assertSame('done', $todo->status);
        $this->assertFalse($todo->focused);
    }

    public function test_home_exposes_focused_todo(): void
    {
        Todo::factory()->for($this->user)->create(['title' => 'the one thing', 'focused' => true]);

        $this->actingAs($this->user)->get('/')
            ->assertInertia(fn (Assert $page) => $page->where('focus.title', 'the one thing'));
    }
}
