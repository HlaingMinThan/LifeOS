<?php

namespace App\Services;

use App\Models\CareTask;
use App\Models\LedgerEntry;
use App\Models\Todo;

/**
 * The catch-up screen as text — sent as the 7 AM Telegram digest
 * and on demand via /today. Money and todos dated in the future stay
 * out of today's digest; they appear via /tomorrow and /todobydate.
 */
class DigestBuilder
{
    public function build(): string
    {
        $lines = ['🌅 '.now()->format('D, j M Y')];

        $care = CareTask::where('active', true)
            ->whereDate('next_run_at', '<=', today())->get();
        if ($care->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '💗 Care today:';
            foreach ($care as $task) {
                $lines[] = "  • {$task->title}";
            }
        }

        $overdue = Todo::overdue()->orderBy('due_date')->get();
        if ($overdue->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '🔴 Overdue:';
            foreach ($overdue as $todo) {
                $lines[] = "  • {$todo->title} (due {$todo->due_date->format('j M')})";
            }
        }

        $today = Todo::open()->whereDate('due_date', today())->get();
        if ($today->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '📌 Due today:';
            foreach ($today as $todo) {
                $lines[] = "  • {$todo->title}";
            }
        }

        // Undated todos would otherwise be invisible to every digest.
        $undated = Todo::open()->whereNull('due_date')->latest()->get();
        if ($undated->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '📝 Open (no date):';
            foreach ($undated->take(8) as $todo) {
                $lines[] = "  • {$todo->title}";
            }
            if ($undated->count() > 8) {
                $lines[] = '  … and '.($undated->count() - 8).' more';
            }
        }

        // Money dated in the future belongs to that day's view, not today's.
        $relevantToday = fn ($query) => $query->where(
            fn ($q) => $q->whereNull('due_date')->orWhereDate('due_date', '<=', today()),
        );

        $payables = $relevantToday(LedgerEntry::open()->payable())->with('contact')->get();
        if ($payables->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '💸 Expense ('.number_format($payables->sum('amount_mmk')).' Ks):';
            foreach ($payables as $entry) {
                $lines[] = '  • '.($entry->contact?->name ?? $entry->title).' — '.number_format($entry->amount_mmk).' Ks';
            }
        }

        $receivables = $relevantToday(LedgerEntry::open()->receivable())->with('contact')->get();
        if ($receivables->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '💰 Income ('.number_format($receivables->sum('amount_mmk')).' Ks):';
            foreach ($receivables as $entry) {
                $lines[] = '  • '.($entry->contact?->name ?? $entry->title).' — '.number_format($entry->amount_mmk).' Ks';
            }
        }

        if (count($lines) === 1) {
            $lines[] = '';
            $lines[] = 'Nothing needs you today 🎉';
        }

        return implode("\n", $lines);
    }

    /** Any single day: its todos (open ⭕ / done ✅) + care tasks landing then. */
    public function forDate(\Carbon\CarbonInterface $date): string
    {
        $lines = ['🗓 '.$date->format('D, j M Y')];

        $care = CareTask::where('active', true)
            ->whereDate('next_run_at', $date)->get();
        if ($care->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '💗 Care:';
            foreach ($care as $task) {
                $lines[] = "  • {$task->title}";
            }
        }

        $todos = Todo::whereDate('due_date', $date)->get();
        if ($todos->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '📌 Todos:';
            foreach ($todos as $todo) {
                $mark = $todo->status === 'done' ? '✅' : '⭕';
                $lines[] = "  {$mark} {$todo->title}";
            }
        }

        $money = LedgerEntry::open()->whereDate('due_date', $date)->with('contact')->get();
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
}
