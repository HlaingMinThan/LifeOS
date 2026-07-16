<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\DigestBuilder;
use App\Services\Telegram\TelegramClient;
use Illuminate\Console\Command;

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
            $this->line("— {$user->email} —");
            $this->line($text);
        }

        return self::SUCCESS;
    }
}
