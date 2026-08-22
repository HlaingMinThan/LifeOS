<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\BotPush;
use App\Services\DigestBuilder;
use App\Services\Telegram\TelegramClient;
use Illuminate\Console\Command;
use Throwable;

class SendMorningDigest extends Command
{
    protected $signature = 'digest:send';

    protected $description = 'Send the catch-up digest to Telegram';

    public function handle(DigestBuilder $digest, TelegramClient $telegram): int
    {
        // Only people who finished the Telegram setup — a digest with nowhere
        // to go is just a log line nobody reads.
        $users = User::whereNotNull('telegram_bot_token')
            ->whereNotNull('telegram_chat_id')
            ->get();

        foreach ($users as $user) {
            $text = $digest->build($user);

            $telegram->forUser($user)->send($text);
            // Best-effort push: one user's bad subscription must not abort the loop.
            try {
                $user->notify(new BotPush('🌅 Morning digest', 'Your day at a glance — tap to open Life OS.'));
            } catch (Throwable $e) {
                report($e);
            }
            $this->line("— {$user->email} —");
            $this->line($text);
        }

        return self::SUCCESS;
    }
}
