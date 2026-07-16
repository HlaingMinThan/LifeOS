<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonInterface;

/**
 * The catch-up screen as text — sent as the 7 AM Telegram digest
 * and on demand via /today. Money and todos dated in the future stay
 * out of today's digest; they appear via /tomorrow and /todobydate.
 */
class DigestBuilder
{
    public function build(User $user): string
    {
        $lines = ['🌅 '.now()->format('D, j M Y')];

        $care = $user->careTasks()->where('active', true)
            ->whereDate('next_run_at', '<=', today())->get();
        if ($care->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '💗 Care today:';
            foreach ($care as $task) {
                $lines[] = "  • {$task->title}";
            }
        }

        $overdue = $user->todos()->overdue()->orderBy('due_date')->get();
        if ($overdue->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '🔴 Overdue:';
            array_push($lines, ...$this->groupedTodoLines(
                $overdue->take(10),
                fn ($todo) => "• {$todo->title} (due {$todo->due_date->format('j M')})",
            ));
            if ($overdue->count() > 10) {
                $lines[] = '  … and '.($overdue->count() - 10).' more in the app';
            }
        }

        $today = $user->todos()->open()->whereDate('due_date', today())->get();
        if ($today->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '📌 Due today:';
            array_push($lines, ...$this->groupedTodoLines($today));
        }

        // Undated todos would otherwise be invisible to every digest.
        $undated = $user->todos()->open()->whereNull('due_date')->latest()->get();
        if ($undated->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '📝 Open (no date):';
            array_push($lines, ...$this->groupedTodoLines($undated->take(8)));
            if ($undated->count() > 8) {
                $lines[] = '  … and '.($undated->count() - 8).' more';
            }
        }

        // Money dated in the future belongs to that day's view, not today's.
        $relevantToday = fn ($query) => $query->where(
            fn ($q) => $q->whereNull('due_date')->orWhereDate('due_date', '<=', today()),
        );

        $payables = $relevantToday($user->ledgerEntries()->open()->payable())->with('contact')->get();
        if ($payables->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '💸 Expense ('.number_format($payables->sum('amount_mmk')).' Ks):';
            array_push($lines, ...$this->moneyLines($payables));
        }

        $receivables = $relevantToday($user->ledgerEntries()->open()->receivable())->with('contact')->get();
        if ($receivables->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '💰 Income ('.number_format($receivables->sum('amount_mmk')).' Ks):';
            array_push($lines, ...$this->moneyLines($receivables));
        }

        if (count($lines) === 1) {
            $lines[] = '';
            $lines[] = 'Nothing needs you today 🎉';
        }

        return implode("\n", $lines);
    }

    /** Any single day: its todos (open ⭕ / done ✅) + care tasks landing then. */
    public function forDate(User $user, CarbonInterface $date): string
    {
        $lines = ['🗓 '.$date->format('D, j M Y')];

        $care = $user->careTasks()->where('active', true)
            ->whereDate('next_run_at', $date)->get();
        if ($care->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '💗 Care:';
            foreach ($care as $task) {
                $lines[] = "  • {$task->title}";
            }
        }

        $todos = $user->todos()->whereDate('due_date', $date)->get();
        if ($todos->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '📌 Todos:';
            array_push($lines, ...$this->groupedTodoLines(
                $todos,
                fn ($todo) => ($todo->status === 'done' ? '✅' : '⭕')." {$todo->title}",
            ));
        }

        $money = $user->ledgerEntries()->open()->whereDate('due_date', $date)->with('contact')->get();
        if ($money->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '💵 Money due:';
            foreach ($money as $entry) {
                $arrow = $entry->direction === 'payable' ? '→ pay' : '← receive';
                $lines[] = '  • '.($entry->contact?->name ?? $entry->title).' — '
                    .number_format($entry->amount_mmk)." Ks {$arrow}";
            }
        }

        if (count($lines) === 1) {
            $lines[] = '';
            $lines[] = 'Nothing on this day 🌙';
        }

        return implode("\n", $lines);
    }

    /** Entry lines capped at 8 — totals in the header already tell the story. */
    private function moneyLines($entries): array
    {
        $lines = [];
        foreach ($entries->take(8) as $entry) {
            $lines[] = '  • '.($entry->contact?->name ?? $entry->title).' — '.number_format($entry->amount_mmk).' Ks';
        }
        if ($entries->count() > 8) {
            $lines[] = '  … and '.($entries->count() - 8).' more in the app';
        }

        return $lines;
    }

    /** Todos grouped by bucket (Work / Personal / Money), skipping empty groups. */
    private function groupedTodoLines($todos, ?callable $format = null): array
    {
        $format ??= fn ($todo) => "• {$todo->title}";
        $lines = [];

        foreach (['work' => 'Work', 'personal' => 'Personal', 'money_task' => 'Money'] as $bucket => $label) {
            $group = $todos->where('bucket', $bucket);
            if ($group->isEmpty()) {
                continue;
            }

            $lines[] = "  {$label}:";
            foreach ($group as $todo) {
                $lines[] = '   '.$format($todo);
            }
        }

        return $lines;
    }
}
