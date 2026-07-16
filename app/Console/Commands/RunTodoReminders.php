<?php

namespace App\Console\Commands;

use App\Models\Todo;
use App\Services\Telegram\TelegramClient;
use Illuminate\Console\Command;

class RunTodoReminders extends Command
{
    protected $signature = 'todos:remind';

    protected $description = 'Ping Telegram when a timed todo reaches its due time';

    public function handle(TelegramClient $telegram): int
    {
        $due = Todo::with('user')->dueForReminder()->get()
            ->filter(fn (Todo $todo) => $todo->due_date
                ->setTimeFromTimeString($todo->due_time)
                ->isPast());

        foreach ($due as $todo) {
            $message = "⏰ {$todo->title}";
            if ($todo->note) {
                $message .= "\n{$todo->note}";
            }

            $telegram->forUser($todo->user)->send($message);
            $todo->update(['reminded_at' => now()]);

            $this->info("Reminded: {$todo->title}");
        }

        return self::SUCCESS;
    }
}
