<?php

namespace App\Http\Controllers;

use App\Models\LedgerEntry;
use Illuminate\Http\RedirectResponse;
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
}
