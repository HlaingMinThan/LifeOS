<?php

namespace App\Http\Controllers;

use App\Models\CareTask;
use App\Models\Idea;
use App\Models\LedgerEntry;
use App\Models\Todo;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    /** The glance screen: next up, overdue, today, money strip, tomorrow peek. */
    public function index(): Response
    {
        $today = today()->toDateString();
        $tomorrow = today()->addDay()->toDateString();

        $ideas = Idea::where('status', 'parked')->orderBy('id')->get();

        return Inertia::render('os/Home', [
            'focus' => Todo::open()->where('focused', true)->first(),
            'nextUp' => Todo::open()->whereDate('due_date', $today)
                ->whereNotNull('due_time')
                ->where('due_time', '>=', now()->format('H:i:s'))
                ->orderBy('due_time')
                ->first(),
            'overdue' => Todo::overdue()->orderBy('due_date')->take(5)->get(),
            'overdueCount' => Todo::overdue()->count(),
            'todayTodos' => Todo::whereDate('due_date', $today)
                ->orderByRaw("status = 'open' desc")
                ->orderByRaw('due_time is null')
                ->orderBy('due_time')
                ->get(),
            'careToday' => CareTask::where('active', true)
                ->whereDate('next_run_at', '<=', $today)->get(),
            'money' => [
                'incoming' => (int) LedgerEntry::open()->receivable()->sum('amount_mmk'),
                'toPay' => (int) LedgerEntry::open()->payable()->sum('amount_mmk'),
                'dueThisWeek' => LedgerEntry::open()
                    ->whereBetween('due_date', [$today, today()->addDays(6)->toDateString()])
                    ->count(),
                'overdue' => LedgerEntry::open()->whereDate('due_date', '<', $today)->count(),
            ],
            'tomorrow' => [
                'todos' => Todo::open()->whereDate('due_date', $tomorrow)->count(),
                'care' => CareTask::where('active', true)
                    ->whereDate('next_run_at', $tomorrow)->count(),
            ],
            // One parked idea, rotating weekly — keeps the lot alive.
            'parkedIdea' => $ideas->isEmpty()
                ? null
                : $ideas[now()->weekOfYear % $ideas->count()]->title,
        ]);
    }
}
