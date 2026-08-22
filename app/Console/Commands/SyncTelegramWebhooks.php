<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Telegram\TelegramClient;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Register the webhook for every connected bot, idempotently.
 *
 * This is the deploy/ops path. The setup wizard registers a webhook per user as
 * they connect, but two cases have no wizard step: an account migrated in with a
 * token already set (prod's user 1), and a bot whose webhook silently broke or
 * whose domain changed. Run this after a deploy, or any time delivery looks dead.
 */
class SyncTelegramWebhooks extends Command
{
    protected $signature = 'telegram:webhook-sync {--force : Re-register even when already pointed at us}';

    protected $description = 'Register the webhook for every connected bot (idempotent)';

    public function handle(TelegramClient $telegram): int
    {
        if (! config('lifeos.telegram.webhook_enabled')) {
            $this->warn('Webhooks are disabled (APP_URL is not https) — this environment delivers via telegram:listen.');

            return self::SUCCESS;
        }

        $users = User::whereNotNull('telegram_bot_token')
            ->whereNotNull('telegram_chat_id')
            ->get();

        if ($users->isEmpty()) {
            $this->info('No connected bots to sync.');

            return self::SUCCESS;
        }

        foreach ($users as $user) {
            $this->syncOne($user, $telegram);
        }

        return self::SUCCESS;
    }

    private function syncOne(User $user, TelegramClient $telegram): void
    {
        // A migrated-in account may hold a token and chat but never a secret;
        // mint one now so the header check on delivery has something to match.
        if (blank($user->telegram_webhook_secret)) {
            $user->forceFill(['telegram_webhook_secret' => Str::random(48)])->save();
        }

        $client = $telegram->forUser($user);
        $expected = route('telegram.webhook', $user->telegram_webhook_secret);

        if (! $this->option('force')
            && ($client->getWebhookInfo()['url'] ?? '') === $expected) {
            $this->line("✓ {$user->email} — already pointed here");

            return;
        }

        $response = $client->setWebhook($expected, $user->telegram_webhook_secret);

        if ($response['ok'] ?? false) {
            $user->forceFill(['telegram_linked_at' => now()])->save();
            $this->info("→ {$user->email} — webhook registered");
        } else {
            $this->error("✗ {$user->email} — ".($response['description'] ?? 'unknown error'));
        }
    }
}
