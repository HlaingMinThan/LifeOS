<?php

namespace Tests\Feature;

use App\Models\Todo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TodoReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Mid-day, so "+1 hour" / "-1 minute" never cross midnight.
        $this->travelTo(now()->setTime(12, 0));
        config(['lifeos.telegram.token' => 'test-token', 'lifeos.telegram.chat_id' => '12345']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    }

    public function test_timed_todo_fires_once_when_time_arrives(): void
    {
        $todo = Todo::factory()->create([
            'title' => 'ည ၁၀ နာရီ ဆေးသောက်ရန်',
            'note' => 'blood pressure pills',
            'due_date' => today(),
            'due_time' => now()->subMinute()->format('H:i:s'),
        ]);

        $this->artisan('todos:remind')->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains($request['text'] ?? '', '⏰ ည ၁၀ နာရီ ဆေးသောက်ရန်')
            && str_contains($request['text'], 'blood pressure pills'));
        $this->assertNotNull($todo->fresh()->reminded_at);

        // Second run: already reminded, nothing sent.
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $this->artisan('todos:remind')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_future_time_and_done_todos_do_not_fire(): void
    {
        Todo::factory()->create([
            'due_date' => today(),
            'due_time' => now()->addHour()->format('H:i:s'),
        ]);
        Todo::factory()->done()->create([
            'due_date' => today(),
            'due_time' => now()->subHour()->format('H:i:s'),
        ]);

        $this->artisan('todos:remind')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_explicitly_past_time_is_rejected_with_clear_error(): void
    {
        $user = \App\Models\User::factory()->create();

        $response = $this->actingAs($user)->postJson('/inbox/apply', [
            'raw_text' => 'today 9am take medicine',
            'parsed' => [
                'action' => 'add_todo',
                'target' => 'take medicine',
                'due' => today()->toDateString(),
                'due_time' => now()->subHour()->format('H:i'),
            ],
        ]);

        $response->assertUnprocessable();
        $this->assertStringContainsString('already passed', $response->json('message'));
        $this->assertSame(0, Todo::count());
    }

    public function test_rescheduling_rearms_the_reminder(): void
    {
        $user = \App\Models\User::factory()->create();
        $todo = Todo::factory()->create([
            'due_date' => today(),
            'due_time' => '08:00:00',
            'reminded_at' => now(),
        ]);

        $this->actingAs($user)->patch("/todos/{$todo->id}", [
            'title' => $todo->title,
            'bucket' => $todo->bucket,
            'due_date' => today()->toDateString(),
            'due_time' => '21:00',
        ])->assertRedirect();

        $this->assertNull($todo->fresh()->reminded_at);
    }
}
