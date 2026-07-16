<?php

namespace App\Http\Controllers;

use App\Models\Todo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TodoController extends Controller
{
    /** Month calendar: per-day open/done counts + the undated bucket. */
    public function index(Request $request): Response
    {
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month'))
            ? $request->query('month')
            : now()->format('Y-m');
        $start = now()->parse($month.'-01');

        $counts = Todo::whereBetween('due_date', [
            $start->toDateString(), $start->endOfMonth()->toDateString(),
        ])->get(['due_date', 'status'])
            ->groupBy(fn ($todo) => $todo->due_date->toDateString())
            ->map(fn ($group) => [
                'open' => $group->where('status', 'open')->count(),
                'done' => $group->where('status', 'done')->count(),
            ]);

        return Inertia::render('os/Todos', [
            'month' => $start->format('Y-m'),
            'counts' => $counts,
            'undatedCount' => Todo::open()->whereNull('due_date')->count(),
        ]);
    }

    /** One day's todos ("undated" is the dateless bucket). */
    public function day(string $date): Response
    {
        $todos = Todo::orderByRaw("status = 'open' desc")->latest();

        $todos = $date === 'undated'
            ? $todos->whereNull('due_date')
            : $todos->whereDate('due_date', now()->parse($date)->toDateString());

        return Inertia::render('os/TodoDay', [
            'date' => $date,
            'todos' => $todos->get(),
        ]);
    }

    /** Full detail page with the rich-text description editor. */
    public function show(Todo $todo): Response
    {
        return Inertia::render('os/TodoDetail', ['todo' => $todo]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        Todo::create($data);

        return back();
    }

    public function update(Request $request, Todo $todo): RedirectResponse
    {
        $validated = $this->validated($request);

        // Rescheduling re-arms the reminder.
        if ($todo->due_date?->toDateString() !== ($validated['due_date'] ?? null)
            || substr($todo->due_time ?? '', 0, 5) !== ($validated['due_time'] ?? null)) {
            $validated['reminded_at'] = null;
        }

        $todo->update($validated);

        return back();
    }

    public function toggle(Todo $todo): RedirectResponse
    {
        $done = $todo->status === 'open';
        $todo->update($done
            ? ['status' => 'done', 'done_at' => now(), 'focused' => false]
            : ['status' => 'open', 'done_at' => null]);

        return back();
    }

    /** Pin one todo as the single focus (or clear it). */
    public function focus(Todo $todo): RedirectResponse
    {
        if ($todo->focused) {
            $todo->update(['focused' => false]);
        } else {
            Todo::where('focused', true)->update(['focused' => false]);
            $todo->update(['focused' => true]);
        }

        return back();
    }

    public function destroy(Todo $todo): RedirectResponse
    {
        $todo->delete();

        return back();
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:20000'],
            'bucket' => ['required', 'in:work,personal,money_task'],
            'due_date' => ['nullable', 'date'],
            'due_time' => ['nullable', 'date_format:H:i'],
        ]);

        $data['note'] = $this->sanitizeNote($data['note'] ?? null);

        return $data;
    }

    /** Keep only the formatting tags the editor emits; drop scripts etc. */
    private function sanitizeNote(?string $html): ?string
    {
        if ($html === null || trim(strip_tags($html)) === '') {
            return null;
        }

        $allowed = '<p><br><strong><b><em><i><u><s><ul><ol><li>'
            .'<h1><h2><h3><h4><code><pre><blockquote><a>';

        return strip_tags($html, $allowed);
    }
}
