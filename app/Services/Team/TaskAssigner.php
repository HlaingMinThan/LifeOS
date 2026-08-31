<?php

namespace App\Services\Team;

use App\Models\Todo;
use App\Models\User;
use App\Services\Telegram\TelegramClient;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Creates a todo inside a teammate's own Life OS. The record belongs to them
 * (user_id), so it flows through their day view, digest and reminders like
 * anything else; assigned_by_id records who sent it and is what lets the
 * assigner see it later.
 */
class TaskAssigner
{
    public function __construct(private TelegramClient $telegram) {}

    /**
     * @param  array<string, mixed>  $parsed  a parsed add_todo action
     */
    public function assign(User $owner, User $assignee, array $parsed): Todo
    {
        if (! $owner->canAssignTo($assignee)) {
            throw ValidationException::withMessages([
                'text' => "{$assignee->name} is not on your team yet.",
            ]);
        }

        $dueTime = $parsed['due_time'] ?? null;
        $dueDate = $parsed['due'] ?? ($dueTime ? today()->toDateString() : null);

        $todo = $assignee->todos()->create([
            'title' => $parsed['target'] ?? 'Untitled',
            'note' => $parsed['note'] ?? null,
            'bucket' => $parsed['bucket'] ?? 'work',
            'due_date' => $dueDate,
            'due_time' => $dueTime,
        ]);

        $todo->assignedBy()->associate($owner);
        $todo->save();

        $this->notify(
            $assignee,
            "📥 New task from {$owner->name}\n{$todo->title}".$this->when($todo),
        );

        return $todo;
    }

    /** Tell the assigner their task got done — the point of tracking it. */
    public function notifyCompleted(Todo $todo): void
    {
        if (! $todo->isAssigned() || ! $todo->assignedBy) {
            return;
        }

        $this->notify(
            $todo->assignedBy,
            "✅ {$todo->user->name} completed: {$todo->title}",
        );
    }

    private function when(Todo $todo): string
    {
        if (! $todo->due_date) {
            return '';
        }

        $line = "\n📅 ".$todo->due_date->format('D j M');

        return $todo->due_time
            ? $line.' ⏰ '.strtolower(date('g:ia', strtotime($todo->due_time)))
            : $line;
    }

    /** Best-effort: a silent bot must never fail the assignment itself. */
    private function notify(User $user, string $message): void
    {
        if (! $user->hasTelegram()) {
            return;
        }

        try {
            $this->telegram->forUser($user)->send($message);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
