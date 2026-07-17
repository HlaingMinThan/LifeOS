<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `telegram:webhook-sync` — the deploy/ops path that registers webhooks for
 * accounts the setup wizard never walked (migrated-in, or a broken webhook).
 */
class TelegramWebhookSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['lifeos.telegram.webhook_enabled' => true]);
    }

    private function fakeWebhookInfo(string $url): void
    {
        Http::fake([
            'api.telegram.org/*getWebhookInfo*' => Http::response(['ok' => true, 'result' => ['url' => $url]]),
            'api.telegram.org/*' => Http::response(['ok' => true]),
        ]);
    }

    public function test_it_registers_a_webhook_for_a_bot_pointing_nowhere(): void
    {
        $user = User::factory()->withTelegram()->create();
        $this->fakeWebhookInfo(''); // Telegram reports no webhook set

        $this->artisan('telegram:webhook-sync')->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'setWebhook')
            && $request['secret_token'] === $user->telegram_webhook_secret
            && str_contains($request['url'], $user->telegram_webhook_secret));
    }

    public function test_it_skips_a_bot_already_pointed_here(): void
    {
        $user = User::factory()->withTelegram()->create();
        $this->fakeWebhookInfo(route('telegram.webhook', $user->telegram_webhook_secret));

        $this->artisan('telegram:webhook-sync')->assertSuccessful();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'setWebhook'));
    }

    public function test_force_re_registers_even_when_already_correct(): void
    {
        $user = User::factory()->withTelegram()->create();
        $this->fakeWebhookInfo(route('telegram.webhook', $user->telegram_webhook_secret));

        $this->artisan('telegram:webhook-sync --force')->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'setWebhook'));
    }

    public function test_it_mints_a_missing_secret_before_registering(): void
    {
        // The exact shape a migrated-in account could land in: token + chat, no secret.
        $user = User::factory()->withTelegram()->create(['telegram_webhook_secret' => null]);
        $this->fakeWebhookInfo('');

        $this->artisan('telegram:webhook-sync')->assertSuccessful();

        $secret = $user->fresh()->telegram_webhook_secret;
        $this->assertNotEmpty($secret);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'setWebhook')
            && $request['secret_token'] === $secret);
    }

    public function test_it_is_a_no_op_when_webhooks_are_disabled(): void
    {
        config(['lifeos.telegram.webhook_enabled' => false]);
        User::factory()->withTelegram()->create();
        Http::fake();

        $this->artisan('telegram:webhook-sync')
            ->expectsOutputToContain('disabled')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_a_disconnected_account_is_left_alone(): void
    {
        User::factory()->create(); // no bot
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->artisan('telegram:webhook-sync')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_a_telegram_failure_does_not_abort_the_rest(): void
    {
        $bad = User::factory()->withTelegram('111')->create();
        $good = User::factory()->withTelegram('222')->create();

        Http::fake([
            'api.telegram.org/*getWebhookInfo*' => Http::response(['ok' => true, 'result' => ['url' => '']]),
            'api.telegram.org/*setWebhook*' => Http::sequence()
                ->push(['ok' => false, 'description' => 'Bad Request'])
                ->push(['ok' => true]),
        ]);

        // Neither user throws the command off its feet.
        $this->artisan('telegram:webhook-sync')->assertSuccessful();

        Http::assertSentCount(4); // getWebhookInfo + setWebhook, twice
    }
}
