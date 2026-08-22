<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IdeaController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('os/Ideas', [
            'ideas' => $request->user()->ideas()->latest()->get(),
        ]);
    }

    public function destroy(Request $request, int $idea): RedirectResponse
    {
        // Resolve through the owner, so another user's id is a 404, not a leak.
        $request->user()->ideas()->findOrFail($idea)->delete();

        return back();
    }
}
