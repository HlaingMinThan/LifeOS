<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Telegram\InboxBridge;
use App\Services\Telegram\TelegramClient;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Production transport: Telegram pushes each update here.
 *
 * The URL secret says which account's bot this is; the secret_token header
 * proves the request actually came from Telegram. Both must match — the URL
 * alone travels through logs and proxies, so it is an identifier, not a key.
 */
class TelegramWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        string $secret,
        InboxBridge $bridge,
        TelegramClient $telegram,
    ): Response {
        $user = User::where('telegram_webhook_secret', $secret)->first();

        if (! $user) {
            abort(404);
        }

        // Telegram echoes the secret we registered on every delivery. Anyone who
        // learned the URL but not this header is not Telegram.
        if (! hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token'))) {
            abort(403);
        }

        $update = $request->all();
        $updateId = $update['update_id'] ?? null;
        $senderChatId = (string) ($update['message']['chat']['id'] ?? '');

        // Verify the sender is the owner or an authorized collaborator.
        if ($senderChatId && ! $user->isTelegramAuthorized($senderChatId)) {
            // Unknown sender: tell them their chat ID so the owner can authorize them.
            $telegram->forToken($user->telegram_bot_token, $senderChatId)
                ->send("⚠️ You're not authorized.\nAsk the bot owner to add your chat ID: {$senderChatId}");

            return response()->noContent();
        }

        // Telegram retries anything it does not see a 200 for, so the same update
        // can arrive twice. Cache::add is atomic — only the first caller applies
        // it. Keyed per user: update_ids are unique only within a bot.
        $fresh = $updateId === null
            || Cache::add("telegram:seen:{$user->id}:{$updateId}", true, now()->addHour());

        // Reply to the sender's chat, not necessarily the owner's.
        $replyClient = $telegram->forToken($user->telegram_bot_token, $senderChatId);

        if ($fresh && isset($update['message'])) {
            try {
                $reply = $bridge->handle($update['message'], $user);

                if ($reply !== null) {
                    $replyClient->send($reply);
                }
            } catch (Throwable $e) {
                report($e);
                $replyClient->send('⚠️ Something went wrong — check the app.');
            }
        }

        // Always 200: a non-2xx makes Telegram redeliver, and a message that
        // already blew up once will only blow up again.
        return response()->noContent();
    }
}
