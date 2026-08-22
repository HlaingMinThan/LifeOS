<?php

namespace Tests\Feature;

use App\Models\CareTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CareEngineTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->withTelegram()->create();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    }

    public function test_due_task_fires_logs_and_reschedules(): void
    {
        $task = CareTask::factory()->for($this->user)->create([
            'title' => 'Good morning message',
            'next_run_at' => now()->subMinute(),
            'time_of_day' => '09:00:00',
        ]);

        $this->artisan('care:run')->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && str_contains($request['text'], 'Good morning message'));

        $this->assertSame(1, $task->logs()->count());
        $this->assertTrue($task->fresh()->next_run_at->isAfter(now()));
    }

    public function test_random_task_reschedules_within_bounds(): void
    {
        $task = CareTask::factory()->for($this->user)->random(7, 20)->create([
            'next_run_at' => now()->subMinute(),
        ]);

        $this->artisan('care:run')->assertSuccessful();

        $days = now()->startOfDay()->diffInDays($task->fresh()->next_run_at->startOfDay());
        $this->assertGreaterThanOrEqual(7, $days);
        $this->assertLessThanOrEqual(20, $days);
    }

    public function test_future_and_inactive_tasks_do_not_fire(): void
    {
        CareTask::factory()->for($this->user)->create(['next_run_at' => now()->addHour()]);
        CareTask::factory()->for($this->user)->create(['next_run_at' => now()->subHour(), 'active' => false]);

        $this->artisan('care:run')->assertSuccessful();

        Http::assertNothingSent();
    }
}
