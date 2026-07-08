<?php

namespace Tests\Feature;

use App\Models\Todo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TodoCalendarTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_calendar_shows_per_day_counts_and_undated(): void
    {
        Todo::factory()->create(['due_date' => '2026-07-08']);
        Todo::factory()->done()->create(['due_date' => '2026-07-08']);
        Todo::factory()->create(['due_date' => '2026-08-01']); // other month
        Todo::factory()->create(); // undated

        $this->actingAs($this->user)
            ->get('/todos?month=2026-07')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('os/Todos')
                ->where('month', '2026-07')
                ->where('counts.2026-07-08.open', 1)
                ->where('counts.2026-07-08.done', 1)
                ->missing('counts.2026-08-01')
                ->where('undatedCount', 1));
    }

    public function test_day_page_lists_only_that_day(): void
    {
        Todo::factory()->create(['title' => 'right day', 'due_date' => '2026-07-08']);
        Todo::factory()->create(['title' => 'wrong day', 'due_date' => '2026-07-09']);

        $this->actingAs($this->user)
            ->get('/todos/day/2026-07-08')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('os/TodoDay')
                ->where('date', '2026-07-08')
                ->has('todos', 1)
                ->where('todos.0.title', 'right day'));
    }

    public function test_undated_day_page(): void
    {
        Todo::factory()->create(['title' => 'floating task']);
        Todo::factory()->create(['title' => 'dated task', 'due_date' => '2026-07-08']);

        $this->actingAs($this->user)
            ->get('/todos/day/undated')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('os/TodoDay')
                ->has('todos', 1)
                ->where('todos.0.title', 'floating task'));
    }

    public function test_invalid_month_falls_back_to_current(): void
    {
        $this->actingAs($this->user)
            ->get('/todos?month=hax')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('month', now()->format('Y-m')));
    }
}
