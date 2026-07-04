<?php

namespace App\Services\Telegram;

use App\Models\InboxEvent;
use App\Services\DigestBuilder;
use App\Services\Inbox\InboxApplier;
use App\Services\Inbox\ParserContract;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The magic box over Telegram: incoming text → parse → apply → reply.
 * Confident parses apply immediately (there is no confirm chip in chat);
 * /undo reverts the latest applied event.
 */
class InboxBridge
{
    private const ACTION_LABELS = [
        'mark_paid' => 'Marked paid',
        'add_payable' => 'You owe',
        'add_receivable' => 'Owed to you',
        'income_received' => 'Income received',
        'add_todo' => 'Todo added',
        'complete_todo' => 'Todo done',
        'add_care_task' => 'Care task added',
        'add_idea' => 'Idea parked',
    ];

    public function __construct(
        private ParserContract $parser,
        private InboxApplier $applier,
        private DigestBuilder $digest,
    ) {}

    /** Returns the reply text, or null when the message should be ignored. */
    public function handle(array $message): ?string
    {
        $chatId = (string) ($message['chat']['id'] ?? '');
        if ($chatId !== (string) config('lifeos.telegram.chat_id')) {
            return null; // single-user app: ignore strangers
        }

        $text = trim($message['text'] ?? '');
        if ($text === '') {
            return null;
        }

        return match ($text) {
            '/start' => "Life OS ready 🚀\nType things like \"paid gon khaung 500k\" or \"mom အတွက် ဆေးဝယ်ရန်\".\n/today — digest · /undo — revert last",
            '/today' => $this->digest->build(),
            '/undo' => $this->undoLatest(),
            default => $this->applyText($text),
        };
    }

    private function applyText(string $text): string
    {
        try {
            $parsed = $this->parser->parse($text);
        } catch (Throwable) {
            return '⚠️ Parser unavailable — try again in a moment.';
        }

        if ($parsed['action'] === 'unknown' || $parsed['confidence'] < 0.7) {
            return "🤔 Not sure what to do with that.\nTry rephrasing, or use the app's confirm box to teach me.";
        }

        try {
            $this->applier->apply($parsed, $text);
        } catch (ValidationException $e) {
            return '⚠️ '.collect($e->errors())->flatten()->first();
        }

        $label = self::ACTION_LABELS[$parsed['action']] ?? $parsed['action'];
        $amount = $parsed['amount_mmk'] ? ' · '.number_format($parsed['amount_mmk']).' Ks' : '';

        return "✅ {$label}: {$parsed['target']}{$amount}\nWrong? /undo";
    }

    private function undoLatest(): string
    {
        $event = InboxEvent::where('applied', true)
            ->whereNull('reverted_at')
            ->latest('id')
            ->first();

        if (! $event) {
            return 'Nothing to undo.';
        }

        $this->applier->undo($event);

        return "↩️ Undone: {$event->raw_text}";
    }
}
