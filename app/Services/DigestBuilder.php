<?php

namespace App\Services;

use App\Models\CareTask;
use App\Models\LedgerEntry;
use App\Models\Todo;

/**
 * The catch-up screen as text — sent as the 7 AM Telegram digest
 * and on demand via /today.
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

        $payables = LedgerEntry::open()->payable()->with('contact')->get();
        if ($payables->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '💸 You owe ('.number_format($payables->sum('amount_mmk')).' Ks):';
            foreach ($payables as $entry) {
                $lines[] = '  • '.($entry->contact?->name ?? $entry->title).' — '.number_format($entry->amount_mmk).' Ks';
            }
        }

        $receivables = LedgerEntry::open()->receivable()->with('contact')->get();
        if ($receivables->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '💰 Owed to you ('.number_format($receivables->sum('amount_mmk')).' Ks):';
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

    /** Preview of tomorrow: due todos + care tasks landing that day. */
    public function tomorrow(): string
    {
        $date = today()->addDay();
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

        $todos = Todo::open()->whereDate('due_date', $date)->get();
        if ($todos->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '📌 Due tomorrow:';
            foreach ($todos as $todo) {
                $lines[] = "  • {$todo->title}";
            }
        }

        if (count($lines) === 1) {
            $lines[] = '';
            $lines[] = 'Nothing scheduled for tomorrow 🌙';
        }

        return implode("\n", $lines);
    }
}
