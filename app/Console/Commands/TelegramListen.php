<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Telegram\InboxBridge;
use App\Services\Telegram\TelegramClient;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Local-dev transport. Production uses the webhook (POST /telegram/webhook/…)
 * because lifeos.test has no public HTTPS for Telegram to reach.
 *
 * getUpdates is per-bot and every user brings their own token, so this walks
 * each connected bot in turn rather than holding one long poll.
 */
class TelegramListen extends Command
{
    protected $signature = 'telegram:listen {--once : Poll a single batch and exit}';

    protected $description = 'Long-poll Telegram so messages to each connected bot flow through the magic inbox';

    /** Seconds the ownership heartbeat lives; refreshed every poll. */
    private const OWNER_TTL = 120;

    private const OWNER_KEY = 'telegram:listen:owner';

    /**
     * One bot can hold a real long poll. Several must take turns, so each gets
     * a short wait — otherwise the last bot in the cycle waits minutes.
     */
    private const SOLO_POLL_TIMEOUT = 50;

    private const SHARED_POLL_TIMEOUT = 2;

    public function handle(TelegramClient $telegram, InboxBridge $bridge): int
    {
        if ($this->linkedUsers()->isEmpty()) {
            $this->error('No account has connected a bot yet — do it in Settings → Telegram.');

            return self::FAILURE;
        }

        // Single-instance guard (long-running mode only). A heartbeat key claims
        // ownership and is refreshed every poll; a second listener can't claim it
        // and exits, while it expires ~2 min after this process dies so a
        // supervisor restart can reclaim. --once skips this so cron/tests compose.
        $single = ! $this->option('once');
        $instance = (string) Str::uuid();

        if ($single && ! Cache::add(self::OWNER_KEY, $instance, self::OWNER_TTL)) {
            $this->warn('Another telegram:listen instance is already running — exiting.');

            return self::SUCCESS;
        }

        $this->info('Listening for Telegram messages… (Ctrl+C to stop)');

        try {
            do {
                if ($single) {
                    Cache::put(self::OWNER_KEY, $instance, self::OWNER_TTL); // heartbeat
                }

                // Re-read each cycle so a bot connected just now starts working
                // without a restart.
                $users = $this->linkedUsers();
                $timeout = $users->count() === 1
                    ? self::SOLO_POLL_TIMEOUT
                    : self::SHARED_POLL_TIMEOUT;

                foreach ($users as $user) {
                    $this->pollFor($user, $telegram, $bridge, $timeout);
                }
            } while (! $this->option('once'));
        } finally {
            // Release ownership on a clean exit so a restart reclaims immediately.
            if ($single && Cache::get(self::OWNER_KEY) === $instance) {
                Cache::forget(self::OWNER_KEY);
            }
        }

        return self::SUCCESS;
    }

    private function pollFor(User $user, TelegramClient $telegram, InboxBridge $bridge, int $timeout): void
    {
        $client = $telegram->forUser($user);
        // Persisted per bot so a restart never replays messages already handled.
        $offsetKey = "telegram:offset:{$user->id}";
        $offset = Cache::get($offsetKey, 0);

        try {
            $updates = $client->getUpdates($offset, $timeout);
        } catch (Throwable $e) {
            $this->warn("Poll failed for {$user->email}: {$e->getMessage()}");

            return;
        }

        foreach ($updates as $update) {
            $offset = $update['update_id'] + 1;
            Cache::put($offsetKey, $offset, now()->addYear());

            if (! isset($update['message'])) {
                continue;
            }

            // Idempotency: handle each update exactly once. Cache::add is atomic,
            // so even if a second listener slips through or Telegram redelivers,
            // only the first caller proceeds — no double replies. Keyed by user
            // because update_ids are only unique within a bot.
            if (! Cache::add("telegram:seen:{$user->id}:{$update['update_id']}", true, now()->addHour())) {
                continue;
            }

            try {
                $reply = $bridge->handle($update['message'], $user);
            } catch (Throwable $e) {
                report($e);
                $reply = '⚠️ Something went wrong — check the app.';
            }

            if ($reply !== null) {
                $client->send($reply);
                $this->line("→ [{$user->email}] ".str_replace("\n", ' | ', $reply));
            }
        }
    }

    /** @return Collection<int, User> */
    private function linkedUsers(): Collection
    {
        return User::whereNotNull('telegram_bot_token')
            ->whereNotNull('telegram_chat_id')
            ->get();
    }
}
