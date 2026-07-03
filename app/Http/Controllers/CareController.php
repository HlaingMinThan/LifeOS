<?php

namespace App\Http\Controllers;

use App\Models\CareTask;
use Inertia\Inertia;
use Inertia\Response;

class CareController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('os/Care', [
            'tasks' => CareTask::orderByDesc('active')
                ->orderBy('next_run_at')
                ->get(),
        ]);
    }
}
