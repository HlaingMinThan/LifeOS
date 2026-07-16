<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The guided wizard: BotFather → paste token → press Start → connected. */
class TelegramSetupTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function fakeGetMe(bool $ok = true): void
    {
        Http::fake([
            'api.telegram.org/*getMe*' => Http::response($ok
                ? ['ok' => true, 'result' => ['id' => 123456789, 'username' => 'my_lifeos_bot']]
                : ['ok' => false, 'description' => 'Unauthorized'], $ok ? 200 : 401),
        ]);
    }

    public function test_setup_page_starts_at_the_token_step(): void
    {
        $this->actingAs($this->user)->get('/settings/telegram')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                // os/ so app.ts gives it the mobile shell, not the starter kit's.
                ->component('os/Telegram')
                ->where('hasToken', false)
                ->where('chatId', null));
    }

    public function test_a_malformed_token_is_rejected_before_telegram_is_called(): void
    {
        Http::fake();

        $this->actingAs($this->user)
            ->from('/settings/telegram')
            ->post('/settings/telegram/token', ['token' => 'not-a-token'])
            ->assertSessionHasErrors('token');

        Http::assertNothingSent();
        $this->assertNull($this->user->fresh()->telegram_bot_token);
    }

    public function test_a_token_telegram_rejects_is_not_stored(): void
    {
        $this->fakeGetMe(ok: false);

        $this->actingAs($this->user)
            ->from('/settings/telegram')
            ->post('/settings/telegram/token', ['token' => '123456789:AAE-wrongwrongwrong'])
            ->assertSessionHasErrors('token');

        $this->assertNull($this->user->fresh()->telegram_bot_token);
    }

    public function test_a_valid_token_is_stored_with_its_bot_username(): void
    {
        $this->fakeGetMe();

        $this->actingAs($this->user)
            ->post('/settings/telegram/token', ['token' => '123456789:AAE-realtokenhere'])
            ->assertRedirect();

        $user = $this->user->fresh();
        $this->assertSame('123456789:AAE-realtokenhere', $user->telegram_bot_token);
        $this->assertSame('my_lifeos_bot', $user->telegram_bot_username);
        // A token alone cannot send yet — the chat is still unknown.
        $this->assertNull($user->telegram_chat_id);
    }

    public function test_the_token_is_encrypted_at_rest(): void
    {
        $this->fakeGetMe();

        $this->actingAs($this->user)
            ->post('/settings/telegram/token', ['token' => '123456789:AAE-realtokenhere']);

        $raw = \DB::table('users')->where('id', $this->user->id)->value('telegram_bot_token');
        $this->assertNotSame('123456789:AAE-realtokenhere', $raw);
        $this->assertStringNotContainsString('AAE-realtokenhere', (string) $raw);
    }

    /** Two accounts on one bot would answer each other's messages. */
    public function test_a_bot_already_used_by_another_account_is_refused(): void
    {
        User::factory()->create(['telegram_bot_token' => '123456789:AAE-shared']);
        $this->fakeGetMe();

        $this->actingAs($this->user)
            ->from('/settings/telegram')
            ->post('/settings/telegram/token', ['token' => '123456789:AAE-shared'])
            ->assertSessionHasErrors('token');

        $this->assertNull($this->user->fresh()->telegram_bot_token);
    }

    public function test_detect_explains_itself_when_nobody_has_pressed_start(): void
    {
        $this->user->forceFill(['telegram_bot_token' => '123456789:AAE-real'])->save();
        Http::fake([
            'api.telegram.org/*getUpdates*' => Http::response(['ok' => true, 'result' => []]),
            'api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        $this->actingAs($this->user)
            ->from('/settings/telegram')
            ->post('/settings/telegram/detect')
            ->assertSessionHasErrors('detect');

        $this->assertNull($this->user->fresh()->telegram_chat_id);
    }

    public function test_detect_links_the_chat_registers_the_webhook_and_says_hello(): void
    {
        config(['lifeos.telegram.webhook_enabled' => true]);
        $this->user->forceFill(['telegram_bot_token' => '123456789:AAE-real'])->save();

        Http::fake([
            'api.telegram.org/*getUpdates*' => Http::response(['ok' => true, 'result' => [
                ['update_id' => 1, 'message' => ['chat' => ['id' => 6879686702], 'text' => '/start']],
            ]]),
            'api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        $this->actingAs($this->user)->post('/settings/telegram/detect')->assertRedirect();

        $user = $this->user->fresh();
        $this->assertSame('6879686702', $user->telegram_chat_id);
        $this->assertNotNull($user->telegram_linked_at);
        $this->assertNotEmpty($user->telegram_webhook_secret);

        // The webhook must carry the secret Telegram will echo back to us.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'setWebhook')
            && $request['secret_token'] === $user->telegram_webhook_secret
            && str_contains($request['url'], $user->telegram_webhook_secret));

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && str_contains($request['text'], 'Life OS connected'));
    }

    /** getUpdates returns nothing while a webhook is live, so setup clears it first. */
    public function test_detect_clears_any_existing_webhook_before_polling(): void
    {
        $this->user->forceFill(['telegram_bot_token' => '123456789:AAE-real'])->save();
        Http::fake([
            'api.telegram.org/*getUpdates*' => Http::response(['ok' => true, 'result' => [
                ['update_id' => 1, 'message' => ['chat' => ['id' => 42], 'text' => '/start']],
            ]]),
            'api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        $this->actingAs($this->user)->post('/settings/telegram/detect');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'deleteWebhook'));
    }

    public function test_local_dev_links_the_chat_without_registering_a_webhook(): void
    {
        config(['lifeos.telegram.webhook_enabled' => false]);
        $this->user->forceFill(['telegram_bot_token' => '123456789:AAE-real'])->save();

        Http::fake([
            'api.telegram.org/*getUpdates*' => Http::response(['ok' => true, 'result' => [
                ['update_id' => 1, 'message' => ['chat' => ['id' => 42], 'text' => '/start']],
            ]]),
            'api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        $this->actingAs($this->user)->post('/settings/telegram/detect')->assertRedirect();

        $this->assertSame('42', $this->user->fresh()->telegram_chat_id);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'setWebhook'));
    }

    public function test_disconnecting_clears_the_bot_and_unhooks_it(): void
    {
        $this->user = User::factory()->withTelegram()->create();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->actingAs($this->user)->delete('/settings/telegram')->assertRedirect();

        $user = $this->user->fresh();
        $this->assertNull($user->telegram_bot_token);
        $this->assertNull($user->telegram_chat_id);
        $this->assertNull($user->telegram_webhook_secret);

        // Otherwise Telegram keeps posting to a webhook we no longer honour.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'deleteWebhook'));
    }

    public function test_home_nudges_until_connected_or_dismissed(): void
    {
        $this->actingAs($this->user)->get('/')
            ->assertInertia(fn ($page) => $page->where('showTelegramPrompt', true));

        $this->actingAs($this->user)->patch('/settings/telegram/dismiss')->assertRedirect();

        $this->actingAs($this->user->fresh())->get('/')
            ->assertInertia(fn ($page) => $page->where('showTelegramPrompt', false));
    }

    public function test_a_connected_user_is_never_nudged(): void
    {
        $connected = User::factory()->withTelegram()->create();

        $this->actingAs($connected)->get('/')
            ->assertInertia(fn ($page) => $page->where('showTelegramPrompt', false));
    }
}
