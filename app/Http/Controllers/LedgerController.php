<?php

namespace App\Http\Controllers;

use App\Models\LedgerEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LedgerController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('os/Money', [
            'entries' => LedgerEntry::with('contact')
                ->orderByRaw("status = 'open' desc")
                ->latest()
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        LedgerEntry::create($this->validated($request) + ['status' => 'open']);

        return back();
    }

    public function update(Request $request, LedgerEntry $entry): RedirectResponse
    {
        $entry->update($this->validated($request));

        return back();
    }

    public function toggle(LedgerEntry $entry): RedirectResponse
    {
        $entry->update($entry->status === 'open'
            ? ['status' => 'paid', 'paid_at' => now()]
            : ['status' => 'open', 'paid_at' => null]);

        return back();
    }

    public function destroy(LedgerEntry $entry): RedirectResponse
    {
        $entry->delete();

        return back();
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
