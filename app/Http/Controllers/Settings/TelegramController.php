<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Telegram\TelegramClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The guided setup: BotFather → paste token → press Start → connected.
 *
 * Each step is verified against Telegram rather than trusted, so a typo fails
 * here with an explanation instead of silently producing a bot that never
 * answers.
 */
class TelegramController extends Controller
{
    public function __construct(private TelegramClient $telegram) {}

    public function edit(Request $request): Response
    {
        $user = $request->user();

        // An os/ page, not settings/ — app.ts picks the layout from that prefix,
        // and this is a Life OS feature, not a starter-kit account screen.
        return Inertia::render('os/Telegram', [
            // The token itself never leaves the server — only whether one exists.
            'hasToken' => filled($user->telegram_bot_token),
            'botUsername' => $user->telegram_bot_username,
            'chatId' => $user->telegram_chat_id,
            'linkedAt' => $user->telegram_linked_at,
            'usesWebhook' => (bool) config('lifeos.telegram.webhook_enabled'),
        ]);
    }

    /** Step 2: prove the token works, then keep it. */
    public function storeToken(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:200', 'regex:/^\d+:[\w-]+$/'],
        ], [
            'token.regex' => 'That does not look like a bot token. It should look like 123456789:AAE… — copy the whole line BotFather sent.',
        ]);

        $user = $request->user();

        // Two accounts polling one bot would answer each other's messages and
        // write to the wrong Life OS. The token is encrypted (so not queryable);
        // with a handful of users, comparing decrypted values is fine.
        $alreadyUsed = User::whereNotNull('telegram_bot_token')
            ->whereKeyNot($user->getKey())
            ->get()
            ->contains(fn (User $other) => $other->telegram_bot_token === $validated['token']);

        if ($alreadyUsed) {
            throw ValidationException::withMessages([
                'token' => 'That bot is already connected to another account. Create a separate bot with /newbot.',
            ]);
        }

        $me = $this->telegram->forToken($validated['token'])->getMe();

        if (! ($me['ok'] ?? false)) {
            throw ValidationException::withMessages([
                'token' => 'Telegram rejected that token: '.($me['description'] ?? 'unknown error').'.',
            ]);
        }

        $user->forceFill([
            'telegram_bot_token' => $validated['token'],
            'telegram_bot_username' => $me['result']['username'] ?? null,
            // A different bot means a different chat — relink before sending.
            'telegram_chat_id' => null,
            'telegram_webhook_secret' => Str::random(48),
            'telegram_linked_at' => null,
        ])->save();

        return back();
    }

    /** Step 3: find who pressed Start, then wire up delivery. */
    public function detect(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (blank($user->telegram_bot_token)) {
            throw ValidationException::withMessages(['token' => 'Add your bot token first.']);
        }

        $client = $this->telegram->forToken($user->telegram_bot_token);

        // getUpdates and a webhook are mutually exclusive: while a webhook is
        // registered Telegram refuses to hand updates back here. Clear it first
        // so re-running setup on an already-connected bot still works.
        $client->deleteWebhook();

        $chatId = collect($client->getUpdates(0, 0))
            ->pluck('message.chat.id')
            ->filter()
            ->last();

        if (! $chatId) {
            throw ValidationException::withMessages([
                'detect' => 'No message from you yet. Open the bot in Telegram, press Start, then try again.',
            ]);
        }

        $user->forceFill([
            'telegram_chat_id' => (string) $chatId,
            'telegram_webhook_secret' => $user->telegram_webhook_secret ?: Str::random(48),
            'telegram_linked_at' => now(),
            // Connected: the nudge has served its purpose.
            'telegram_prompt_dismissed_at' => now(),
        ])->save();

        if (config('lifeos.telegram.webhook_enabled')) {
            $response = $client->setWebhook(
                route('telegram.webhook', $user->telegram_webhook_secret),
                $user->telegram_webhook_secret,
            );

            if (! ($response['ok'] ?? false)) {
                throw ValidationException::withMessages([
                    'detect' => 'Connected, but Telegram refused the webhook: '
                        .($response['description'] ?? 'unknown error').'.',
                ]);
            }
        }

        $this->telegram->forUser($user)->send(
            "✅ Life OS connected.\nSend me anything — \"paid gon khaung 500k\" — or try /today.",
        );

        return back();
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        // Leave the bot with no webhook pointing at us, or Telegram keeps
        // retrying deliveries this account will no longer accept.
        if (filled($user->telegram_bot_token)) {
            $this->telegram->forToken($user->telegram_bot_token)->deleteWebhook();
        }

        $user->forceFill([
            'telegram_bot_token' => null,
            'telegram_bot_username' => null,
            'telegram_chat_id' => null,
            'telegram_webhook_secret' => null,
            'telegram_linked_at' => null,
        ])->save();

        return back();
    }

    /** "Not now" on the Home banner — never ask again. */
    public function dismissPrompt(Request $request): RedirectResponse
    {
        $request->user()->forceFill(['telegram_prompt_dismissed_at' => now()])->save();

        return back();
    }
}
