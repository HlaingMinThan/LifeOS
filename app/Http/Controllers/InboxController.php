<?php

namespace App\Http\Controllers;

use App\Models\InboxEvent;
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
        ]);

        // validate() strips nested keys it has no rules for — pass the full payload.
        $event = $applier->apply($request->input('parsed'), $request->input('raw_text'));

        return response()->json(['event_id' => $event->id]);
    }

    public function undo(InboxEvent $event, InboxApplier $applier): JsonResponse
    {
        $applier->undo($event);

        return response()->json(['ok' => true]);
    }
}
