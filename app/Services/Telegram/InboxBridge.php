<?php

namespace App\Services\Telegram;

use App\Models\InboxEvent;
use App\Services\DigestBuilder;
use App\Services\Inbox\BrainDumpParser;
use App\Services\Inbox\InboxApplier;
use App\Services\Inbox\ParserContract;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
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
        private BrainDumpParser $dumpParser,
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
            '/start' => "Life OS ready 🚀\nType things like \"paid gon khaung 500k\" or \"mom အတွက် ဆေးဝယ်ရန်\".\n/today — digest · /tomorrow · /yesterday · /todobydate · /undo",
            '/today' => $this->digest->build(),
            '/tomorrow', '/tmr' => $this->digest->forDate(today()->addDay()),
            '/yesterday' => $this->digest->forDate(today()->subDay()),
            '/todobydate' => $this->askForDate(),
            '/undo' => $this->undoLatest(),
            default => $this->freeText($text),
        };
    }

    private function freeText(string $text): string
    {
        // /todobydate asked a question; this message is the answer.
        if (Cache::pull('telegram:awaiting_date')) {
            if ($date = $this->resolveDate($text)) {
                return $this->digest->forDate($date);
            }

            Cache::put('telegram:awaiting_date', true, now()->addMinutes(5));

            return "🤔 Couldn't read that date — try like \"July 6\", \"2026-07-06\", or \"6.7\".";
        }

        // A multi-line message is a mini brain dump: one action per line.
        return str_contains($text, "\n")
            ? $this->applyMany($text)
            : $this->applyText($text);
    }

    private function askForDate(): string
    {
        Cache::put('telegram:awaiting_date', true, now()->addMinutes(5));

        return '📅 Which date? (e.g. "July 6", "2026-07-06", "6.7")';
    }

    private function resolveDate(string $text): ?CarbonInterface
    {
        // Burmese digits → Arabic so "၆.၇" works too.
        $text = strtr(trim($text), array_combine(
            ['၀', '၁', '၂', '၃', '၄', '၅', '၆', '၇', '၈', '၉'],
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
        ));

        // "6.7" / "6/7" → day.month of the current year.
        if (preg_match('/^(\d{1,2})[.\/](\d{1,2})$/', $text, $m)) {
            $text = now()->year."-{$m[2]}-{$m[1]}";
        }

        try {
            return \Illuminate\Support\Facades\Date::parse($text);
        } catch (Throwable) {
            return null;
        }
    }

    private function applyMany(string $text): string
    {
        $lines = [];

        foreach ($this->dumpParser->parse($text) as $item) {
            $parsed = $item['parsed'];

            if ($parsed['action'] === 'unknown' || $parsed['confidence'] < 0.7) {
                $lines[] = "🤔 skipped: {$item['raw_text']}";

                continue;
            }

            try {
                $this->applier->apply($parsed, $item['raw_text']);
                $label = self::ACTION_LABELS[$parsed['action']] ?? $parsed['action'];
                $amount = $parsed['amount_mmk'] ? ' · '.number_format($parsed['amount_mmk']).' Ks' : '';
                $lines[] = "✅ {$label}: {$parsed['target']}{$amount}";
            } catch (ValidationException $e) {
                $lines[] = "⚠️ {$item['raw_text']}: ".collect($e->errors())->flatten()->first();
            }
        }

        $lines[] = '';
        $lines[] = 'Wrong? /undo reverts one at a time.';

        return implode("\n", $lines);
    }

    private function applyText(string $text): string
    {
        try {
            $parsed = $this->parser->parse($text);
        } catch (Throwable $e) {
            report($e);

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
