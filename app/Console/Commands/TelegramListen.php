<?php

namespace App\Console\Commands;

use App\Services\Telegram\InboxBridge;
use App\Services\Telegram\TelegramClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

class TelegramListen extends Command
{
    protected $signature = 'telegram:listen {--once : Poll a single batch and exit}';

    protected $description = 'Long-poll Telegram so messages to the bot flow through the magic inbox';

    /** Seconds the ownership heartbeat lives; refreshed every poll. */
    private const OWNER_TTL = 120;

    private const OWNER_KEY = 'telegram:listen:owner';

    public function handle(TelegramClient $telegram, InboxBridge $bridge): int
    {
        if (! $telegram->configured()) {
            $this->error('Set TELEGRAM_BOT_TOKEN and TELEGRAM_CHAT_ID first.');

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
        // Persisted so a restart never replays messages already handled.
        $offset = Cache::get('telegram:offset', 0);

        try {
            do {
                if ($single) {
                    Cache::put(self::OWNER_KEY, $instance, self::OWNER_TTL); // heartbeat
                }

                try {
                    $updates = $telegram->getUpdates($offset);
                } catch (Throwable $e) {
                    $this->warn("Poll failed: {$e->getMessage()} — retrying in 5s");
                    sleep(5);

                    continue;
                }

                foreach ($updates as $update) {
                    $offset = $update['update_id'] + 1;
                    Cache::put('telegram:offset', $offset, now()->addYear());

                    if (! isset($update['message'])) {
                        continue;
                    }

                    // Idempotency: handle each update_id exactly once. Cache::add is
                    // atomic, so even if a second listener slips through or Telegram
                    // redelivers, only the first caller proceeds — no double replies.
                    if (! Cache::add('telegram:seen:'.$update['update_id'], true, now()->addHour())) {
                        continue;
                    }

                    try {
                        $reply = $bridge->handle($update['message']);
                    } catch (Throwable $e) {
                        report($e);
                        $reply = '⚠️ Something went wrong — check the app.';
                    }

                    if ($reply !== null) {
                        $telegram->send($reply);
                        $this->line('→ '.str_replace("\n", ' | ', $reply));
                    }
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
}
