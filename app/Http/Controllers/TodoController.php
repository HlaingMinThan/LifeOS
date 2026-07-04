<?php

namespace App\Http\Controllers;

use App\Models\Todo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function store(Request $request): RedirectResponse
    {
        Todo::create($request->validate([
            'title' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
            'bucket' => ['required', 'in:work,personal,money_task'],
            'due_date' => ['nullable', 'date'],
            'due_time' => ['nullable', 'date_format:H:i'],
        ]));

        return back();
    }

    public function update(Request $request, Todo $todo): RedirectResponse
    {
        $todo->update($request->validate([
            'title' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
            'bucket' => ['required', 'in:work,personal,money_task'],
            'due_date' => ['nullable', 'date'],
            'due_time' => ['nullable', 'date_format:H:i'],
        ]));

        return back();
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
