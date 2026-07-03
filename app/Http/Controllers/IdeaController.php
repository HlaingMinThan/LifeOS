<?php

namespace App\Http\Controllers;

use App\Models\Idea;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class IdeaController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('os/Ideas', [
            'ideas' => Idea::latest()->get(),
        ]);
    }

    public function destroy(Idea $idea): RedirectResponse
    {
        $idea->delete();

        return back();
    }
}
