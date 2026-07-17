<?php

namespace App\Console\Commands;

use App\Models\Todo;
use App\Notifications\BotPush;
use App\Services\Telegram\TelegramClient;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

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
            // Mark reminded BEFORE the push: a failing push must never leave the
            // guard unset, or the reminder re-fires every minute.
            $todo->update(['reminded_at' => now()]);

            // The PWA push is best-effort — it rides alongside Telegram and must
            // not disrupt the core flow if a subscription/endpoint misbehaves.
            try {
                $todo->user->notify(new BotPush(
                    "⏰ {$todo->title}",
                    $todo->note ? Str::limit(strip_tags($todo->note), 120) : null,
                ));
            } catch (Throwable $e) {
                report($e);
            }

            $this->info("Reminded: {$todo->title}");
        }

        return self::SUCCESS;
    }
}
