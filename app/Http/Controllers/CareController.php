<?php

namespace App\Http\Controllers;

use App\Models\CareTask;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CareController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('os/Care', [
            'tasks' => $request->user()->careTasks()->orderByDesc('active')
                ->orderBy('next_run_at')
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $task = $request->user()->careTasks()->make($this->validated($request));
        $task->next_run_at = $task->initialNextRun();
        $task->save();

        return back();
    }

    public function update(Request $request, int $task): RedirectResponse
    {
        $model = $this->find($request, $task);
        $model->fill($this->validated($request));
        // Schedule may have changed — recompute from the new settings.
        $model->next_run_at = $model->initialNextRun();
        $model->save();

        return back();
    }

    public function toggle(Request $request, int $task): RedirectResponse
    {
        $model = $this->find($request, $task);
        $model->update(['active' => ! $model->active]);

        return back();
    }

    public function destroy(Request $request, int $task): RedirectResponse
    {
        $this->find($request, $task)->delete();

        return back();
    }

    /** Resolve through the owner, so another user's id is a 404, not a leak. */
    private function find(Request $request, int $id): CareTask
    {
        return $request->user()->careTasks()->findOrFail($id);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'schedule_type' => ['required', 'in:daily,weekly,random'],
            'time_of_day' => ['nullable', 'date_format:H:i'],
            'weekday' => ['nullable', 'required_if:schedule_type,weekly', 'integer', 'between:0,6'],
            'random_min_days' => ['nullable', 'required_if:schedule_type,random', 'integer', 'between:1,90'],
            'random_max_days' => ['nullable', 'required_if:schedule_type,random', 'integer', 'between:1,90', 'gte:random_min_days'],
        ]);
    }
}
