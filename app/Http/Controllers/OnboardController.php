<?php

namespace App\Http\Controllers;

use App\Services\Inbox\BrainDumpParser;
use App\Services\Inbox\InboxApplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class OnboardController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('os/Onboard');
    }

    /** Step 1: parse the whole dump — nothing is written yet. */
    public function dump(Request $request, BrainDumpParser $parser): JsonResponse
    {
        $validated = $request->validate(['text' => ['required', 'string', 'max:10000']]);

        return response()->json(['items' => $parser->parse($validated['text'], $request->user())]);
    }

    /** Step 2: persist the reviewed items, each as a normal inbox event. */
    public function confirm(Request $request, InboxApplier $applier): JsonResponse
    {
        $request->validate([
            'items' => ['required', 'array', 'max:200'],
            'items.*.raw_text' => ['required', 'string'],
            'items.*.parsed.action' => ['required', 'string'],
        ]);

        $applied = 0;
        $failed = [];

        foreach ($request->input('items') as $item) {
            try {
                $applier->apply($item['parsed'], $item['raw_text'], $request->user());
                $applied++;
            } catch (ValidationException) {
                $failed[] = $item['raw_text'];
            }
        }

        return response()->json(['applied' => $applied, 'failed' => $failed]);
    }
}
