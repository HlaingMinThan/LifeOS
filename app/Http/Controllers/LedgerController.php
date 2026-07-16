<?php

namespace App\Http\Controllers;

use App\Models\LedgerEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LedgerController extends Controller
{
    /**
     * Open entries are bounded by real life; settled history is not —
     * it loads the recent slice unless "show all" is requested.
     */
    public function index(Request $request): Response
    {
        $settledQuery = $request->user()->ledgerEntries()->where('status', '!=', 'open')
            ->with('contact')->latest('updated_at');
        $settledCount = (clone $settledQuery)->count();

        return Inertia::render('os/Money', [
            'open' => $request->user()->ledgerEntries()->open()->with('contact')
                ->orderByRaw('due_date is null')->orderBy('due_date')->get(),
            'settled' => $request->boolean('all_settled')
                ? $settledQuery->get()
                : $settledQuery->take(15)->get(),
            'settledCount' => $settledCount,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->user()->ledgerEntries()->create($this->validated($request) + ['status' => 'open']);

        return back();
    }

    public function update(Request $request, int $entry): RedirectResponse
    {
        $this->find($request, $entry)->update($this->validated($request));

        return back();
    }

    public function toggle(Request $request, int $entry): RedirectResponse
    {
        $model = $this->find($request, $entry);

        $model->update($model->status === 'open'
            ? ['status' => 'paid', 'paid_at' => now()]
            : ['status' => 'open', 'paid_at' => null]);

        return back();
    }

    public function destroy(Request $request, int $entry): RedirectResponse
    {
        $this->find($request, $entry)->delete();

        return back();
    }

    /** Resolve through the owner, so another user's id is a 404, not a leak. */
    private function find(Request $request, int $id): LedgerEntry
    {
        return $request->user()->ledgerEntries()->findOrFail($id);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'direction' => ['required', 'in:payable,receivable'],
            'title' => ['required', 'string', 'max:255'],
            'amount_mmk' => ['required', 'integer', 'min:1'],
            'due_date' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
