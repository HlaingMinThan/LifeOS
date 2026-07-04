<?php

namespace App\Http\Controllers;

use App\Models\InboxEvent;
use App\Models\ParserExample;
use App\Services\Inbox\InboxApplier;
use App\Services\Inbox\ParserContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InboxController extends Controller
{
    /** Step 1: parse only — nothing is written until the user confirms. */
    public function parse(Request $request, ParserContract $parser): JsonResponse
    {
        $validated = $request->validate(['text' => ['required', 'string', 'max:500']]);

        return response()->json([
            'raw_text' => $validated['text'],
            'parsed' => $parser->parse($validated['text']),
        ]);
    }

    /** Step 2: the confirm chip posts the parsed payload back to apply it. */
    public function apply(Request $request, InboxApplier $applier): JsonResponse
    {
        $request->validate([
            'raw_text' => ['required', 'string', 'max:500'],
            'parsed' => ['required', 'array'],
            'parsed.action' => ['required', 'string'],
            'corrected' => ['sometimes', 'boolean'],
        ]);

        // validate() strips nested keys it has no rules for — pass the full payload.
        $parsed = $request->input('parsed');
        $event = $applier->apply($parsed, $request->input('raw_text'));

        // User fixed the parse in the UI → teach the parser (spec §4:
        // the 10 most recent corrections are injected into the prompt).
        if ($request->boolean('corrected')) {
            ParserExample::create([
                'raw_text' => $request->input('raw_text'),
                'corrected_json' => collect($parsed)->except('confidence')->all(),
            ]);
        }

        return response()->json(['event_id' => $event->id]);
    }

    public function undo(InboxEvent $event, InboxApplier $applier): JsonResponse
    {
        $applier->undo($event);

        return response()->json(['ok' => true]);
    }
}
