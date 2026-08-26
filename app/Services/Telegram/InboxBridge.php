<?php

namespace App\Services\Telegram;

use App\Models\User;
use App\Services\DigestBuilder;
use App\Services\Inbox\BrainDumpParser;
use App\Services\Inbox\ClaudeParser;
use App\Services\Inbox\InboxApplier;
use App\Services\Inbox\ParserContract;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Storage;
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
        'add_payable' => 'Expense',
        'add_receivable' => 'Income',
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
        private TelegramClient $telegram,
    ) {}

    /**
     * Returns the reply text, or null when the message should be ignored.
     * $user is the bot's owner — everything this message touches is theirs.
     */
    public function handle(array $message, User $user): ?string
    {
        // Photo messages: download the image and parse with Claude vision.
        // Telegram sends compressed images as 'photo' and file-attached images as 'document'.
        if (! empty($message['photo'])) {
            $photos = $message['photo'];
            $fileId = end($photos)['file_id'];
            $caption = trim($message['caption'] ?? '');

            return $this->handlePhoto($fileId, $caption, $user);
        }

        if (! empty($message['document']) && str_starts_with($message['document']['mime_type'] ?? '', 'image/')) {
            $fileId = $message['document']['file_id'];
            $caption = trim($message['caption'] ?? '');

            return $this->handlePhoto($fileId, $caption, $user);
        }

        $text = trim($message['text'] ?? $message['caption'] ?? '');
        if ($text === '') {
            return null;
        }

        // Commands work with or without the slash: "today", "/today", "tdy"…
        return match (strtolower(ltrim($text, '/'))) {
            'start', 'help' => "Life OS ready 🚀\nType things like \"paid gon khaung 500k\" or \"mom အတွက် ဆေးဝယ်ရန်\".\nCommands (slash optional): today/tdy · tomorrow/tmr · yesterday · todobydate · care · idea · undo",
            'today', 'tdy' => $this->digest->build($user),
            'tomorrow', 'tmr' => $this->digest->forDate($user, today()->addDay()),
            'yesterday' => $this->digest->forDate($user, today()->subDay()),
            'todobydate' => $this->askForDate($user),
            'care' => $this->listCareTasks($user),
            'idea', 'ideas' => $this->listIdeas($user),
            'undo' => $this->undoLatest($user),
            default => $this->freeText($text, $user),
        };
    }

    private function handlePhoto(string $fileId, string $caption, User $user): string
    {
        try {
            $bot = $this->telegram->forUser($user);
            $fileInfo = $bot->getFile($fileId);
            $filePath = $fileInfo['file_path'] ?? null;

            if (! $filePath) {
                return '⚠️ Could not fetch the photo from Telegram.';
            }

            $imageData = $bot->downloadFile($filePath);

            $ext = pathinfo($filePath, PATHINFO_EXTENSION) ?: 'jpg';
            $storagePath = 'ledger/'.uniqid('tg_').'.'.$ext;
            Storage::disk('public')->put($storagePath, $imageData);

            $parser = app(ClaudeParser::class);
            $parsed = $parser->parseImage($imageData, $ext, $caption, $user);

            if ($parsed['action'] === 'unknown' || $parsed['confidence'] < 0.7) {
                return "🤔 I see the screenshot but couldn't parse the transaction.\nCaption it with details like \"KBZ pay 50k to shop A\" and resend.";
            }

            $parsed['_image'] = $storagePath;

            if ($caption !== '') {
                $parsed['note'] = $caption;
            }

            $this->applier->apply($parsed, $caption ?: 'Screenshot transaction', $user);

            $label = self::ACTION_LABELS[$parsed['action']] ?? $parsed['action'];
            $amount = $parsed['amount_mmk'] ? ' · '.number_format($parsed['amount_mmk']).' Ks' : '';

            $note = $parsed['note'] ?? null;

            return implode("\n", array_filter([
                "✅ {$label}",
                ($parsed['target'] ?? '').$amount,
                $this->fmtDue($parsed),
                $note ? "📝 {$note}" : null,
                'Wrong? /undo',
            ]));
        } catch (Throwable $e) {
            report($e);

            return '⚠️ Could not process the screenshot — try again or add a caption with details.';
        }
    }

    private function freeText(string $text, User $user): string
    {
        // /todobydate asked a question; this message is the answer.
        if (Cache::pull($this->awaitingDateKey($user))) {
            if ($date = $this->resolveDate($text)) {
                return $this->digest->forDate($user, $date);
            }

            Cache::put($this->awaitingDateKey($user), true, now()->addMinutes(5));

            return "🤔 Couldn't read that date — try like \"July 6\", \"2026-07-06\", or \"6.7\".";
        }

        // A multi-line message is a mini brain dump: one action per line.
        return str_contains($text, "\n")
            ? $this->applyMany($text, $user)
            : $this->applyText($text, $user);
    }

    private function askForDate(User $user): string
    {
        Cache::put($this->awaitingDateKey($user), true, now()->addMinutes(5));

        return '📅 Which date? (e.g. "July 6", "2026-07-06", "6.7")';
    }

    /** Per-user: two people mid-/todobydate must not answer each other's question. */
    private function awaitingDateKey(User $user): string
    {
        return "telegram:awaiting_date:{$user->id}";
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
            return Date::parse($text);
        } catch (Throwable) {
            return null;
        }
    }

    private function applyMany(string $text, User $user): string
    {
        $lines = [];

        foreach ($this->dumpParser->parse($text, $user) as $item) {
            $parsed = $item['parsed'];

            if ($parsed['action'] === 'unknown' || $parsed['confidence'] < 0.7) {
                $lines[] = "🤔 skipped: {$item['raw_text']}";

                continue;
            }

            try {
                $this->applier->apply($parsed, $item['raw_text'], $user);
                $label = self::ACTION_LABELS[$parsed['action']] ?? $parsed['action'];
                $amount = $parsed['amount_mmk'] ? ' · '.number_format($parsed['amount_mmk']).' Ks' : '';
                $time = ($parsed['due_time'] ?? null) ? ' ⏰ '.$this->fmtTime($parsed['due_time']) : '';
                $lines[] = "✅ {$label}: {$parsed['target']}{$amount}{$this->fmtDue($parsed)}{$time}";
            } catch (ValidationException $e) {
                $lines[] = "⚠️ {$item['raw_text']}: ".collect($e->errors())->flatten()->first();
            }
        }

        $lines[] = '';
        $lines[] = 'Wrong? /undo reverts one at a time.';

        return implode("\n", $lines);
    }

    private function applyText(string $text, User $user): string
    {
        try {
            $parsed = $this->parser->parse($text, $user);
        } catch (Throwable $e) {
            report($e);

            return '⚠️ Parser unavailable — try again in a moment.';
        }

        if ($parsed['action'] === 'unknown' || $parsed['confidence'] < 0.7) {
            return "🤔 Not sure what to do with that.\nTry rephrasing, or use the app's confirm box to teach me.";
        }

        // A question about a date answers with that day, applies nothing.
        if ($parsed['action'] === 'show_day') {
            return ($parsed['due'] ?? null)
                ? $this->digest->forDate($user, Date::parse($parsed['due']))
                : $this->askForDate($user);
        }

        try {
            $this->applier->apply($parsed, $text, $user);
        } catch (ValidationException $e) {
            return '⚠️ '.collect($e->errors())->flatten()->first();
        }

        $label = self::ACTION_LABELS[$parsed['action']] ?? $parsed['action'];
        $amount = $parsed['amount_mmk'] ? ' · '.number_format($parsed['amount_mmk']).' Ks' : '';
        $time = ($parsed['due_time'] ?? null) ? ' ⏰ '.$this->fmtTime($parsed['due_time']) : '';
        $when = trim($this->fmtDue($parsed).$time);

        return implode("\n", array_filter([
            "✅ {$label}",
            $parsed['target'].$amount,
            $when !== '' ? $when : null,
            'Wrong? /undo',
        ]));
    }

    /** "22:43" → "10:43pm" */
    private function fmtTime(string $time): string
    {
        return strtolower(date('g:ia', strtotime($time)));
    }

    /**
     * Date suffix for confirmation replies. Money entries always show one
     * ("no date" included) so a wrong or missing due date is caught early.
     */
    private function fmtDue(array $parsed): string
    {
        if ($parsed['due'] ?? null) {
            return ' 📅 '.date('D j M', strtotime($parsed['due']));
        }

        return in_array($parsed['action'], ['add_payable', 'add_receivable', 'income_received'])
            ? ' 📅 no date'
            : '';
    }

    private function listCareTasks(User $user): string
    {
        $tasks = $user->careTasks()->orderByDesc('active')->orderBy('next_run_at')->get();

        if ($tasks->isEmpty()) {
            return 'No care tasks yet — add one on the Care screen.';
        }

        $lines = ['💗 Care tasks:'];
        foreach ($tasks as $task) {
            $schedule = match ($task->schedule_type) {
                'daily' => 'daily'.($task->time_of_day ? ' '.$this->fmtTime($task->time_of_day) : ''),
                'weekly' => ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][$task->weekday ?? 1]
                    .($task->time_of_day ? ' '.$this->fmtTime($task->time_of_day) : ''),
                'random' => "every {$task->random_min_days}–{$task->random_max_days} days 🎲",
                default => $task->schedule_type,
            };
            $next = $task->active && $task->next_run_at
                ? ' · next '.$task->next_run_at->format('D j M')
                : ($task->active ? '' : ' · paused');
            $lines[] = "  • {$task->title} ({$schedule}){$next}";
        }

        return implode("\n", $lines);
    }

    private function listIdeas(User $user): string
    {
        $ideas = $user->ideas()->latest()->get();

        if ($ideas->isEmpty()) {
            return 'The parking lot is empty — "mushroom idea မှတ်ထား" to park one.';
        }

        $lines = ['💡 Ideas:'];
        foreach ($ideas as $idea) {
            $status = $idea->status === 'parked' ? '' : " ({$idea->status})";
            $lines[] = "  • {$idea->title}{$status}";
        }

        return implode("\n", $lines);
    }

    private function undoLatest(User $user): string
    {
        $event = $user->inboxEvents()->where('applied', true)
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
