<?php

namespace App\Console\Commands;

use App\Services\DigestBuilder;
use App\Services\Telegram\TelegramClient;
use Illuminate\Console\Command;

class SendMorningDigest extends Command
{
    protected $signature = 'digest:send';

    protected $description = 'Send the catch-up digest to Telegram';

    public function handle(DigestBuilder $digest, TelegramClient $telegram): int
    {
        $text = $digest->build();

        $telegram->send($text);
        $this->line($text);

        return self::SUCCESS;
    }
}
