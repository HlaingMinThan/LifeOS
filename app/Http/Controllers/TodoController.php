<?php

namespace App\Http\Controllers;

use App\Models\Todo;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class TodoController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('os/Todos', [
            'todos' => Todo::orderByRaw("status = 'open' desc")
                ->orderByRaw('due_date is null')
                ->orderBy('due_date')
                ->latest()
                ->get(),
        ]);
    }

    public function toggle(Todo $todo): RedirectResponse
    {
        $todo->update($todo->status === 'open'
            ? ['status' => 'done', 'done_at' => now()]
            : ['status' => 'open', 'done_at' => null]);

        return back();
    }

    public function destroy(Todo $todo): RedirectResponse
    {
        $todo->delete();

        return back();
    }
}
