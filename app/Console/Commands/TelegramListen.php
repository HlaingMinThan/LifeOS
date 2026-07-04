<?php

namespace App\Console\Commands;

use App\Services\Telegram\InboxBridge;
use App\Services\Telegram\TelegramClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class TelegramListen extends Command
{
    protected $signature = 'telegram:listen {--once : Poll a single batch and exit}';

    protected $description = 'Long-poll Telegram so messages to the bot flow through the magic inbox';

    public function handle(TelegramClient $telegram, InboxBridge $bridge): int
    {
        if (! $telegram->configured()) {
            $this->error('Set TELEGRAM_BOT_TOKEN and TELEGRAM_CHAT_ID in .env first.');

            return self::FAILURE;
        }

        $this->info('Listening for Telegram messages… (Ctrl+C to stop)');
        // Persisted so a restart never replays messages already handled.
        $offset = Cache::get('telegram:offset', 0);

        do {
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

        return self::SUCCESS;
    }
}
