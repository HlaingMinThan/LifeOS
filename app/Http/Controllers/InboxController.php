<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Inbox\InboxApplier;
use App\Services\Inbox\ParserContract;
use App\Services\Team\MentionResolver;
use App\Services\Team\TaskAssigner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InboxController extends Controller
{
    /** Step 1: parse only — nothing is written until the user confirms. */
    public function parse(
        Request $request,
        ParserContract $parser,
        MentionResolver $mentions,
    ): JsonResponse {
        $validated = $request->validate(['text' => ['required', 'string', 'max:500']]);

        // "@zayarwin write the content plan" — the handle is resolved here and
        // stripped, so the parser only ever sees the task itself.
        $mention = $mentions->resolve($validated['text'], $request->user());
        $assignee = $mention['assignee'];

        if ($mention['handle'] && ! $assignee) {
            return response()->json([
                'message' => "No teammate matches @{$mention['handle']}. Invite them in Settings → Team.",
            ], 422);
        }

        return response()->json([
            'raw_text' => $validated['text'],
            'parsed' => $parser->parse($mention['text'], $request->user()),
            'assignee' => $assignee ? [
                'id' => $assignee->id,
                'name' => $assignee->name,
                'username' => $assignee->username,
            ] : null,
        ]);
    }

    /** Step 2: the confirm chip posts the parsed payload back to apply it. */
    public function apply(Request $request, InboxApplier $applier, TaskAssigner $assigner): JsonResponse
    {
        $request->validate([
            'raw_text' => ['required', 'string', 'max:500'],
            'parsed' => ['required', 'array'],
            'parsed.action' => ['required', 'string'],
            'corrected' => ['sometimes', 'boolean'],
            'assignee_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        // validate() strips nested keys it has no rules for — pass the full payload.
        $parsed = $request->input('parsed');

        // Assigned work lands in the teammate's list, not the sender's, so it
        // never touches this user's own inbox history.
        if ($assigneeId = $request->input('assignee_id')) {
            $assignee = User::findOrFail($assigneeId);
            $todo = $assigner->assign($request->user(), $assignee, $parsed);

            return response()->json(['assigned_to' => $assignee->name, 'todo_id' => $todo->id]);
        }

        $event = $applier->apply($parsed, $request->input('raw_text'), $request->user());

        // User fixed the parse in the UI → teach the parser (spec §4:
        // the 10 most recent corrections are injected into the prompt).
        if ($request->boolean('corrected')) {
            $request->user()->parserExamples()->create([
                'raw_text' => $request->input('raw_text'),
                'corrected_json' => collect($parsed)->except('confidence')->all(),
            ]);
        }

        return response()->json(['event_id' => $event->id]);
    }

    public function undo(Request $request, int $event, InboxApplier $applier): JsonResponse
    {
        // Resolve through the owner: undo rewrites records, so an unscoped
        // lookup here would let anyone revert someone else's history.
        $applier->undo($request->user()->inboxEvents()->findOrFail($event));

        return response()->json(['ok' => true]);
    }
}
