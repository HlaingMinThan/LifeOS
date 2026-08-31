<?php

namespace App\Http\Controllers;

use App\Models\Todo;
use App\Services\Team\TaskAssigner;
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

        $counts = $request->user()->todos()->whereBetween('due_date', [
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
            'undatedCount' => $request->user()->todos()->open()->whereNull('due_date')->count(),
        ]);
    }

    /** One day's todos. "undated" = dateless bucket, "overdue" = all past-due open. */
    public function day(Request $request, string $date): Response
    {
        // assignedBy drives the "from <name>" badge on delegated work.
        $todos = $request->user()->todos()->with('assignedBy:id,name')->latest();

        $todos = match ($date) {
            'undated' => $todos->orderByRaw("status = 'open' desc")->whereNull('due_date'),
            'overdue' => $todos->overdue()->orderBy('due_date'),
            default => $todos->orderByRaw("status = 'open' desc")
                ->whereDate('due_date', now()->parse($date)->toDateString()),
        };

        return Inertia::render('os/TodoDay', [
            'date' => $date,
            'todos' => $todos->get(),
        ]);
    }

    /** Full detail page with the rich-text description editor. */
    public function show(Request $request, int $todo): Response
    {
        $model = $this->findAccessible($request, $todo);

        return Inertia::render('os/TodoDetail', [
            'todo' => $model,
            // Whose list this lives in decides the header and what may be edited.
            'viewerIsAssigner' => $model->assigned_by_id === $request->user()->id
                && $model->user_id !== $request->user()->id,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->user()->todos()->create($this->validated($request));

        return back();
    }

    public function update(Request $request, int $todo): RedirectResponse
    {
        $model = $this->findAccessible($request, $todo);
        $validated = $this->validated($request);

        // Rescheduling re-arms the reminder.
        if ($model->due_date?->toDateString() !== ($validated['due_date'] ?? null)
            || substr($model->due_time ?? '', 0, 5) !== ($validated['due_time'] ?? null)) {
            $validated['reminded_at'] = null;
        }

        $model->update($validated);

        return back();
    }

    public function toggle(Request $request, int $todo, TaskAssigner $assigner): RedirectResponse
    {
        $model = $this->findAccessible($request, $todo);
        $done = $model->status === 'open';
        $model->update($done
            ? ['status' => 'done', 'done_at' => now(), 'focused' => false]
            : ['status' => 'open', 'done_at' => null]);

        // Tracking assigned work is the point — tell the assigner it landed.
        if ($done && $model->assigned_by_id !== $request->user()->id) {
            $assigner->notifyCompleted($model->fresh(['user', 'assignedBy']));
        }

        return back();
    }

    /** Pin one todo as the single focus (or clear it). */
    public function focus(Request $request, int $todo): RedirectResponse
    {
        $model = $this->find($request, $todo);

        if ($model->focused) {
            $model->update(['focused' => false]);
        } else {
            // Scoped: clearing focus must not touch anyone else's pinned todo.
            $request->user()->todos()->where('focused', true)->update(['focused' => false]);
            $model->update(['focused' => true]);
        }

        return back();
    }

    public function destroy(Request $request, int $todo): RedirectResponse
    {
        $model = $this->findAccessible($request, $todo);
        $model->delete();

        // Deleting from the todo's own detail page: back() would return to that
        // now-soft-deleted page and 404. Send them to the todo's day instead.
        // Other callers (the day list) keep their place via back().
        // The query flag is what the detail page sends; the Referer check is a
        // fallback for a cached older bundle that does not send it yet.
        $refererPath = parse_url((string) $request->headers->get('referer'), PHP_URL_PATH);
        if ($request->query('from') === 'detail' || $refererPath === "/todos/{$todo}") {
            return redirect()->route('todos.day', $model->due_date?->toDateString() ?? 'undated');
        }

        return back();
    }

    /**
     * Own todos, plus ones I assigned to a teammate — and nothing else of
     * theirs. Assignment is the only crack in the per-account wall, so it is
     * kept to exactly the rows this user created in someone else's list.
     */
    private function findAccessible(Request $request, int $id): Todo
    {
        $user = $request->user();

        return Todo::with(['user:id,name,username', 'assignedBy:id,name,username'])
            ->where('id', $id)
            ->where(fn ($q) => $q->where('user_id', $user->id)
                ->orWhere('assigned_by_id', $user->id))
            ->firstOrFail();
    }

    private function find(Request $request, int $id): Todo
    {
        return $request->user()->todos()->findOrFail($id);
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
