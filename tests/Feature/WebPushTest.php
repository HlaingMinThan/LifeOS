<?php

namespace Tests\Feature;

use App\Models\CareTask;
use App\Models\Todo;
use App\Models\User;
use App\Notifications\BotPush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\TestCase;

/**
 * The PWA push that rides alongside every proactive Telegram send, plus the
 * per-browser subscription endpoints that make delivery possible.
 */
class WebPushTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->withTelegram()->create();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    }

    public function test_care_run_also_pushes_to_the_task_owner(): void
    {
        Notification::fake();
        CareTask::factory()->for($this->user)->create([
            'title' => 'Send flowers', 'next_run_at' => now()->subMinute(),
        ]);

        $this->artisan('care:run')->assertSuccessful();

        Notification::assertSentTo($this->user, BotPush::class);
    }

    /** Force the push channel to blow up, reproducing the missing-VAPID prod bug. */
    private function makePushThrow(): void
    {
        $this->app->bind(WebPushChannel::class, fn () => new class
        {
            public function send(mixed $notifiable, mixed $notification): void
            {
                throw new \RuntimeException('push boom');
            }
        });
    }

    public function test_reminder_marks_reminded_even_when_the_push_fails(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $this->makePushThrow();
        $todo = Todo::factory()->for($this->user)->create([
            'title' => 'take medicine',
            'due_date' => today(),
            'due_time' => now()->subMinute()->format('H:i:s'),
        ]);

        // A thrown push must not abort the command…
        $this->artisan('todos:remind')->assertSuccessful();

        // …nor leave the guard unset, or it re-fires every minute (the bug).
        $this->assertNotNull($todo->fresh()->reminded_at);
    }

    public function test_care_task_reschedules_even_when_the_push_fails(): void
    {
        $this->makePushThrow();
        $task = CareTask::factory()->for($this->user)->create([
            'title' => 'water plants', 'next_run_at' => now()->subMinute(),
        ]);

        $this->artisan('care:run')->assertSuccessful();

        $this->assertTrue($task->fresh()->next_run_at->isFuture());
    }

    public function test_todo_reminder_also_pushes(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        Notification::fake();
        Todo::factory()->for($this->user)->create([
            'title' => 'take medicine',
            'due_date' => today(),
            'due_time' => now()->subMinute()->format('H:i:s'),
        ]);

        $this->artisan('todos:remind')->assertSuccessful();

        Notification::assertSentTo($this->user, BotPush::class);
    }

    public function test_digest_also_pushes(): void
    {
        Notification::fake();

        $this->artisan('digest:send')->assertSuccessful();

        Notification::assertSentTo($this->user, BotPush::class);
    }

    public function test_botpush_only_uses_the_webpush_channel(): void
    {
        // Never mail or DB — this notification exists purely for the PWA.
        $this->assertSame(
            [WebPushChannel::class],
            (new BotPush('hi'))->via($this->user),
        );
    }

    public function test_subscribe_stores_a_subscription_for_the_caller(): void
    {
        $this->actingAs($this->user)->postJson('/push/subscribe', [
            'endpoint' => 'https://push.example/abc',
            'keys' => ['p256dh' => 'the-p256dh-key', 'auth' => 'the-auth-token'],
        ])->assertOk();

        $this->assertDatabaseHas('push_subscriptions', [
            'subscribable_id' => $this->user->id,
            'subscribable_type' => $this->user->getMorphClass(),
            'endpoint' => 'https://push.example/abc',
        ]);
    }

    public function test_subscribe_validates_the_payload(): void
    {
        $this->actingAs($this->user)->postJson('/push/subscribe', [
            'endpoint' => 'https://push.example/abc',
        ])->assertUnprocessable();
    }

    public function test_unsubscribe_removes_only_this_browser(): void
    {
        $this->user->updatePushSubscription('https://push.example/mine', 'k', 't');

        $this->actingAs($this->user)->deleteJson('/push/subscribe', [
            'endpoint' => 'https://push.example/mine',
        ])->assertOk();

        $this->assertDatabaseMissing('push_subscriptions', ['endpoint' => 'https://push.example/mine']);
    }

    public function test_a_user_cannot_unsubscribe_another_users_device(): void
    {
        $other = User::factory()->create();
        $other->updatePushSubscription('https://push.example/theirs', 'k', 't');

        $this->actingAs($this->user)->deleteJson('/push/subscribe', [
            'endpoint' => 'https://push.example/theirs',
        ])->assertOk(); // no error, but…

        // …the other user's subscription is untouched.
        $this->assertDatabaseHas('push_subscriptions', ['endpoint' => 'https://push.example/theirs']);
    }

    public function test_the_subscribe_routes_require_auth(): void
    {
        $this->postJson('/push/subscribe', [
            'endpoint' => 'x', 'keys' => ['p256dh' => 'a', 'auth' => 'b'],
        ])->assertUnauthorized();
    }

    public function test_home_nudges_until_subscribed_or_dismissed(): void
    {
        $this->actingAs($this->user)->get('/')
            ->assertInertia(fn ($page) => $page->where('showNotificationPrompt', true));

        // A subscription silences the nudge on its own.
        $this->user->updatePushSubscription('https://push.example/x', 'k', 't');
        $this->actingAs($this->user->fresh())->get('/')
            ->assertInertia(fn ($page) => $page->where('showNotificationPrompt', false));
    }

    public function test_dismissing_the_nudge_stops_it(): void
    {
        $this->actingAs($this->user)->patch('/settings/notifications/dismiss')->assertRedirect();

        $this->actingAs($this->user->fresh())->get('/')
            ->assertInertia(fn ($page) => $page->where('showNotificationPrompt', false));
    }

    public function test_settings_page_reports_subscription_state(): void
    {
        $this->actingAs($this->user)->get('/settings/notifications')
            ->assertInertia(fn ($page) => $page
                ->component('os/Notifications')
                ->where('hasSubscription', false));
    }
}
