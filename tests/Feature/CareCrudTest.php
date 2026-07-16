<?php

namespace Tests\Feature;

use App\Models\CareTask;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CareCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_weekly_care_task_can_be_created_with_next_run(): void
    {
        $this->actingAs($this->user)->post('/care', [
            'title' => 'ပန်းစည်း ပို့ရန်',
            'schedule_type' => 'weekly',
            'time_of_day' => '09:00',
            'weekday' => 5,
        ])->assertRedirect();

        $task = CareTask::first();
        $this->assertSame('ပန်းစည်း ပို့ရန်', $task->title);
        $this->assertSame(5, (int) $task->weekday);
        $this->assertNotNull($task->next_run_at);
        $this->assertSame(5, $task->next_run_at->dayOfWeek);
        $this->assertTrue($task->next_run_at->isFuture());
    }

    public function test_random_care_task_requires_valid_bounds(): void
    {
        $this->actingAs($this->user)->post('/care', [
            'title' => 'Surprise',
            'schedule_type' => 'random',
            'random_min_days' => 20,
            'random_max_days' => 7, // max < min
        ])->assertSessionHasErrors('random_max_days');
    }

    public function test_update_recomputes_next_run(): void
    {
        $task = CareTask::factory()->for($this->user)->create([
            'schedule_type' => 'daily',
            'next_run_at' => now()->addDay(),
        ]);

        $this->actingAs($this->user)->patch("/care/{$task->id}", [
            'title' => $task->title,
            'schedule_type' => 'random',
            'time_of_day' => '10:00',
            'random_min_days' => 5,
            'random_max_days' => 10,
        ])->assertRedirect();

        $task->refresh();
        $this->assertSame('random', $task->schedule_type);
        $days = (int) now()->startOfDay()->diffInDays($task->next_run_at->startOfDay());
        $this->assertGreaterThanOrEqual(5, $days);
        $this->assertLessThanOrEqual(10, $days);
    }

    public function test_pause_and_delete(): void
    {
        $task = CareTask::factory()->for($this->user)->create(['active' => true]);

        $this->actingAs($this->user)->patch("/care/{$task->id}/toggle")->assertRedirect();
        $this->assertFalse($task->fresh()->active);

        $this->actingAs($this->user)->delete("/care/{$task->id}")->assertRedirect();
        $this->assertSoftDeleted($task);
    }

    public function test_todo_can_be_created_from_todos_page(): void
    {
        $this->actingAs($this->user)->post('/todos', [
            'title' => 'buy chargers',
            'note' => 'two type-c cables',
            'bucket' => 'personal',
            'due_date' => '2026-07-06',
            'due_time' => '14:30',
        ])->assertRedirect();

        $todo = Todo::first();
        $this->assertSame('buy chargers', $todo->title);
        $this->assertSame('two type-c cables', $todo->note);
        $this->assertSame('open', $todo->status);
    }

    public function test_todo_can_be_edited_with_note_and_time(): void
    {
        $todo = Todo::factory()->for($this->user)->create(['title' => 'old title']);

        $this->actingAs($this->user)->patch("/todos/{$todo->id}", [
            'title' => 'new title',
            'note' => 'meet at the mall entrance',
            'bucket' => 'personal',
            'due_date' => '2026-07-06',
            'due_time' => '13:00',
        ])->assertRedirect();

        $todo->refresh();
        $this->assertSame('new title', $todo->title);
        $this->assertSame('meet at the mall entrance', $todo->note);
        $this->assertSame('2026-07-06', $todo->due_date->toDateString());
        $this->assertStringStartsWith('13:00', $todo->due_time);
    }
}
