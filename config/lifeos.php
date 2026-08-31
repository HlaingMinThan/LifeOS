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
        // Naming a spending category is a one-word classification, not a
        // parse — the small model is accurate enough and far cheaper.
        'fast_model' => env('CATEGORY_MODEL', 'claude-haiku-4-5'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Telegram
    |--------------------------------------------------------------------------
    |
    | Bots are per-user now: each account stores its own BotFather token via
    | Settings → Telegram. The token/chat_id below are legacy — they exist only
    | so the 2026_07_17 migration could move the original single bot onto the
    | first account. Nothing reads them at runtime; new setups leave them unset.
    |
    | webhook_enabled decides the transport. Telegram only delivers to public
    | HTTPS, so an https APP_URL means production (webhook) and anything else
    | means local dev (telegram:listen polls instead).
    |
    */

    'telegram' => [
        'token' => env('TELEGRAM_BOT_TOKEN'),
        'chat_id' => env('TELEGRAM_CHAT_ID'),

        'webhook_enabled' => env(
            'TELEGRAM_WEBHOOK_ENABLED',
            str_starts_with((string) env('APP_URL'), 'https://'),
        ),
    ],

];
