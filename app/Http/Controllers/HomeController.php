<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    /** The glance screen: next up, overdue, today, money strip, tomorrow peek. */
    public function index(Request $request): Response
    {
        $today = today()->toDateString();
        $tomorrow = today()->addDay()->toDateString();
        $user = $request->user();

        $ideas = $user->ideas()->where('status', 'parked')->orderBy('id')->get();

        return Inertia::render('os/Home', [
            'focus' => $user->todos()->open()->where('focused', true)->first(),
            // The single next thing to do: soonest today-or-later (or undated)
            // open todo, timed before untimed, excluding the focused one.
            'nextUp' => $user->todos()->open()->where('focused', false)
                ->where(fn ($q) => $q->whereNull('due_date')->orWhereDate('due_date', '>=', $today))
                ->orderByRaw('due_date is null')
                ->orderBy('due_date')
                ->orderByRaw('due_time is null')
                ->orderBy('due_time')
                ->first(),
            'overdue' => $user->todos()->overdue()->orderBy('due_date')->take(5)->get(),
            'overdueCount' => $user->todos()->overdue()->count(),
            'todayTodos' => $user->todos()->whereDate('due_date', $today)
                ->orderByRaw("status = 'open' desc")
                ->orderByRaw('due_time is null')
                ->orderBy('due_time')
                ->get(),
            'careToday' => $user->careTasks()->where('active', true)
                ->whereDate('next_run_at', '<=', $today)->get(),
            'money' => [
                'incoming' => (int) $user->ledgerEntries()->open()->receivable()->sum('amount_mmk'),
                'toPay' => (int) $user->ledgerEntries()->open()->payable()->sum('amount_mmk'),
                'dueThisWeek' => $user->ledgerEntries()->open()
                    ->whereBetween('due_date', [$today, today()->addDays(6)->toDateString()])
                    ->count(),
                'overdue' => $user->ledgerEntries()->open()->whereDate('due_date', '<', $today)->count(),
            ],
            'tomorrow' => [
                'todos' => $user->todos()->open()->whereDate('due_date', $tomorrow)->count(),
                'care' => $user->careTasks()->where('active', true)
                    ->whereDate('next_run_at', $tomorrow)->count(),
            ],
            // One parked idea, rotating weekly — keeps the lot alive.
            'parkedIdea' => $ideas->isEmpty()
                ? null
                : $ideas[now()->weekOfYear % $ideas->count()]->title,
            // Telegram is optional: nudge once, and never again after "Not now".
            'showTelegramPrompt' => ! $user->hasTelegram()
                && ! $user->telegram_prompt_dismissed_at,
        ]);
    }
}
