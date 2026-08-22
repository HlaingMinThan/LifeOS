<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramClient
{
    public function configured(): bool
    {
        return (bool) (config('lifeos.telegram.token') && config('lifeos.telegram.chat_id'));
    }

    /** Send a message to the owner. Falls back to the log when unconfigured. */
    public function send(string $text): void
    {
        if (! $this->configured()) {
            Log::info("[telegram fallback] {$text}");

            return;
        }

        Http::timeout(10)->retry(2, 300)->post($this->url('sendMessage'), [
            'chat_id' => config('lifeos.telegram.chat_id'),
            'text' => $text,
        ]);
    }

    /** Long-poll for incoming messages (two-way magic box). */
    public function getUpdates(int $offset): array
    {
        $response = Http::timeout(60)->get($this->url('getUpdates'), [
            'offset' => $offset,
            'timeout' => 50,
            'allowed_updates' => json_encode(['message']),
        ]);

        return $response->json('result', []);
    }

    /** Get file metadata from Telegram (for downloading photos). */
    public function getFile(string $fileId): array
    {
        $response = Http::timeout(10)->get($this->url('getFile'), [
            'file_id' => $fileId,
        ]);

        return $response->json('result', []);
    }

    /** Download a file from Telegram and return its binary contents. */
    public function downloadFile(string $filePath): string
    {
        $url = 'https://api.telegram.org/file/bot'.config('lifeos.telegram.token')."/{$filePath}";

        return Http::timeout(30)->get($url)->body();
    }

    private function url(string $method): string
    {
        return 'https://api.telegram.org/bot'.config('lifeos.telegram.token')."/{$method}";
    }
}
