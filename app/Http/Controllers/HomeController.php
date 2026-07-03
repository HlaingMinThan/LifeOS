<?php

namespace App\Http\Controllers;

use App\Models\CareTask;
use App\Models\LedgerEntry;
use App\Models\Todo;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    /** The catch-up screen: who you owe, who owes you, today, overdue. */
    public function index(): Response
    {
        return Inertia::render('os/Home', [
            'payables' => LedgerEntry::open()->payable()->with('contact')
                ->orderBy('due_date')->get(),
            'receivables' => LedgerEntry::open()->receivable()->with('contact')
                ->orderBy('due_date')->get(),
            'today' => Todo::open()->whereDate('due_date', today())->get(),
            'careToday' => CareTask::where('active', true)
                ->whereDate('next_run_at', '<=', today())->get(),
            'overdue' => Todo::overdue()->orderBy('due_date')->get(),
        ]);
    }
}
