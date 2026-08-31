<?php

namespace Tests\Feature;

use App\Models\Todo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Production transport. This route is public, so its guards are the perimeter. */
class TelegramWebhookTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['lifeos.parser' => 'fake']);
        $this->user = User::factory()->withTelegram('12345')->create();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    }

    private function deliver(array $payload, ?string $secret = null, ?string $header = 'valid'): TestResponse
    {
        $secret ??= $this->user->telegram_webhook_secret;

        return $this->postJson(
            "/telegram/webhook/{$secret}",
            $payload,
            $header === null ? [] : [
                'X-Telegram-Bot-Api-Secret-Token' => $header === 'valid'
                    ? $this->user->telegram_webhook_secret
                    : $header,
            ],
        );
    }

    private function update(int $id, string $text, int $chatId = 12345): array
    {
        return [
            'update_id' => $id,
            'message' => ['chat' => ['id' => $chatId], 'text' => $text],
        ];
    }

    public function test_an_update_is_applied_for_the_bots_owner(): void
    {
        $this->deliver($this->update(1, 'buy dog food tomorrow'))->assertNoContent();

        $this->assertSame(1, $this->user->todos()->count());
        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && str_contains($request['text'], '✅ Todo added'));
    }

    public function test_an_unknown_secret_is_a_404(): void
    {
        $this->deliver($this->update(1, 'buy dog food'), secret: 'not-a-real-secret')
            ->assertNotFound();

        $this->assertSame(0, Todo::count());
    }

    /** Whoever guessed the URL is not Telegram unless they can echo the secret. */
    public function test_a_wrong_secret_token_header_is_a_403(): void
    {
        $this->deliver($this->update(1, 'buy dog food'), header: 'wrong-header')
            ->assertForbidden();

        $this->assertSame(0, Todo::count());
    }

    public function test_a_missing_secret_token_header_is_a_403(): void
    {
        $this->deliver($this->update(1, 'buy dog food'), header: null)
            ->assertForbidden();

        $this->assertSame(0, Todo::count());
    }

    /** Telegram redelivers anything it did not see a 200 for. */
    public function test_a_replayed_update_is_ignored(): void
    {
        $this->deliver($this->update(7, 'buy dog food tomorrow'))->assertNoContent();
        $this->deliver($this->update(7, 'buy dog food tomorrow'))->assertNoContent();

        $this->assertSame(1, $this->user->todos()->count());
    }

    /** update_ids are unique per bot, so two bots can legitimately both send #7. */
    public function test_the_same_update_id_on_a_different_bot_still_applies(): void
    {
        $other = User::factory()->withTelegram('99999')->create();

        $this->deliver($this->update(7, 'buy dog food tomorrow'))->assertNoContent();

        $this->postJson(
            "/telegram/webhook/{$other->telegram_webhook_secret}",
            ['update_id' => 7, 'message' => ['chat' => ['id' => 99999], 'text' => 'buy cat food tomorrow']],
            ['X-Telegram-Bot-Api-Secret-Token' => $other->telegram_webhook_secret],
        )->assertNoContent();

        $this->assertSame(1, $this->user->todos()->count());
        $this->assertSame(1, $other->todos()->count());
    }

    public function test_a_message_from_another_chat_is_ignored(): void
    {
        $this->deliver($this->update(1, 'buy dog food tomorrow', chatId: 99999))
            ->assertNoContent();

        // Nothing is written — that is the security guarantee.
        $this->assertSame(0, Todo::count());

        // The only reply is the notice telling them their chat id, so the
        // owner can authorize them; the message itself is never acted on.
        Http::assertSent(fn ($request) => str_contains($request['text'] ?? '', "You're not authorized")
            && str_contains($request['text'] ?? '', '99999'));
    }

    public function test_a_non_message_update_is_accepted_quietly(): void
    {
        $this->deliver(['update_id' => 3])->assertNoContent();

        Http::assertNothingSent();
    }
}
