<?php

namespace App\Http\Controllers;

use App\Models\CareTask;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function store(Request $request): RedirectResponse
    {
        $task = new CareTask($this->validated($request));
        $task->next_run_at = $task->initialNextRun();
        $task->save();

        return back();
    }

    public function update(Request $request, CareTask $task): RedirectResponse
    {
        $task->fill($this->validated($request));
        // Schedule may have changed — recompute from the new settings.
        $task->next_run_at = $task->initialNextRun();
        $task->save();

        return back();
    }

    public function toggle(CareTask $task): RedirectResponse
    {
        $task->update(['active' => ! $task->active]);

        return back();
    }

    public function destroy(CareTask $task): RedirectResponse
    {
        $task->delete();

        return back();
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
