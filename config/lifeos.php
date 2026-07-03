<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Magic Inbox Parser
    |--------------------------------------------------------------------------
    |
    | "claude" sends commands to the Anthropic API using the prompt in
    | docs/lifeos-build-spec.md §4. "fake" is a keyword-based parser for
    | local testing without API credits — same output schema.
    |
    */

    'parser' => env('INBOX_PARSER', 'fake'),

    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('INBOX_MODEL', 'claude-sonnet-5'),
    ],

];
