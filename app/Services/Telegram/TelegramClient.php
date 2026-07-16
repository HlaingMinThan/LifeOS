<?php

namespace App\Services\Telegram;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * One bot, one owner. Each user brings their own BotFather token, so every
 * call needs to know whose bot it is speaking through — get an instance via
 * forUser(), or forToken() during setup, before the token is stored.
 */
class TelegramClient
{
    private ?string $token = null;

    private ?string $chatId = null;

    /** Bound to a user's own bot: the normal path for sending anything. */
    public function forUser(User $user): self
    {
        $client = new self;
        $client->token = $user->telegram_bot_token;
        $client->chatId = $user->telegram_chat_id;

        return $client;
    }

    /** Used by the setup wizard to probe a token before it is trusted/stored. */
    public function forToken(string $token, ?string $chatId = null): self
    {
        $client = new self;
        $client->token = $token;
        $client->chatId = $chatId;

        return $client;
    }

    public function configured(): bool
    {
        return (bool) ($this->token && $this->chatId);
    }

    /** Send a message to the owner. Falls back to the log when unconfigured. */
    public function send(string $text): void
    {
        if (! $this->configured()) {
            Log::info("[telegram fallback] {$text}");

            return;
        }

        Http::timeout(10)->retry(2, 300)->post($this->url('sendMessage'), [
            'chat_id' => $this->chatId,
            'text' => $text,
        ]);
    }

    /** Identity of the bot behind a token — also how setup validates it. */
    public function getMe(): array
    {
        return Http::timeout(10)->get($this->url('getMe'))->json() ?? [];
    }

    /**
     * Long-poll for incoming messages (two-way magic box).
     *
     * $timeout is a parameter because the dev poller walks several bots per
     * cycle: a 50s hold per bot would make the last one wait minutes.
     */
    public function getUpdates(int $offset, int $timeout = 50): array
    {
        $response = Http::timeout($timeout + 10)->get($this->url('getUpdates'), [
            'offset' => $offset,
            'timeout' => $timeout,
            'allowed_updates' => json_encode(['message']),
        ]);

        return $response->json('result', []);
    }

    /**
     * Point this bot at our webhook. The secret travels back on every delivery
     * as X-Telegram-Bot-Api-Secret-Token, which is what proves the request
     * came from Telegram and not from someone who guessed the URL.
     */
    public function setWebhook(string $url, string $secret): array
    {
        return Http::timeout(10)->post($this->url('setWebhook'), [
            'url' => $url,
            'secret_token' => $secret,
            'allowed_updates' => json_encode(['message']),
            // A bot switching owners should not inherit a backlog of replies.
            'drop_pending_updates' => true,
        ])->json() ?? [];
    }

    public function deleteWebhook(): array
    {
        return Http::timeout(10)->post($this->url('deleteWebhook'))->json() ?? [];
    }

    private function url(string $method): string
    {
        return "https://api.telegram.org/bot{$this->token}/{$method}";
    }
}
