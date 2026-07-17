<?php

namespace App\Console\Commands;

use App\Models\CareTask;
use App\Notifications\BotPush;
use App\Services\Telegram\TelegramClient;
use Illuminate\Console\Command;

class RunCareTasks extends Command
{
    protected $signature = 'care:run';

    protected $description = 'Fire due care tasks: notify, log, and reschedule';

    public function handle(TelegramClient $telegram): int
    {
        // Still one query across everyone: each task carries its owner, so
        // the notification just follows $task->user to the right bot.
        $due = CareTask::with('user')
            ->where('active', true)
            ->where('next_run_at', '<=', now())
            ->get();

        foreach ($due as $task) {
            $telegram->forUser($task->user)->send("💗 {$task->title}");
            // Mirror the Telegram nudge as a PWA push (no-op without a subscription).
            $task->user->notify(new BotPush("💗 {$task->title}"));

            $task->logs()->create(['ran_at' => now(), 'status' => 'done']);

            // daily/weekly land on a fixed slot; random picks a fresh
            // offset each time — that keeps surprises unpredictable.
            $task->update(['next_run_at' => $task->nextRunAfter(now())]);

            $this->info("Fired: {$task->title} → next {$task->next_run_at}");
        }

        return self::SUCCESS;
    }
}
