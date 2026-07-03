<?php

namespace App\Services\Inbox;

use App\Models\CareTask;
use App\Models\Contact;
use App\Models\Idea;
use App\Models\InboxEvent;
use App\Models\LedgerEntry;
use App\Models\Todo;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Applies a parsed inbox action and records it in inbox_events.
 * Undo reads the event back and applies the inverse — dumb and reliable.
 */
class InboxApplier
{
    public function apply(array $parsed, string $rawText): InboxEvent
    {
        return DB::transaction(function () use ($parsed, $rawText) {
            // Actions that create a record are undone by deleting it;
            // income_received sets this itself (it may match or create).
            $created = in_array($parsed['action'], [
                'add_payable', 'add_receivable', 'add_todo', 'add_care_task', 'add_idea',
            ]);

            $subject = match ($parsed['action']) {
                'mark_paid' => $this->markPaid($parsed),
                'income_received' => $this->incomeReceived($parsed, $created),
                'add_payable' => $this->addLedger($parsed, 'payable'),
                'add_receivable' => $this->addLedger($parsed, 'receivable'),
                'add_todo' => $this->addTodo($parsed),
                'complete_todo' => $this->completeTodo($parsed),
                'add_care_task' => $this->addCareTask($parsed),
                'add_idea' => Idea::create(['title' => $parsed['target']]),
                default => throw ValidationException::withMessages([
                    'text' => "I couldn't understand that — try rephrasing.",
                ]),
            };

            $parsed['_created'] = $created;

            $event = new InboxEvent([
                'raw_text' => $rawText,
                'parsed_json' => $parsed,
                'applied' => true,
            ]);
            $event->subject()->associate($subject);
            $event->save();

            return $event;
        });
    }

    public function undo(InboxEvent $event): void
    {
        if (! $event->applied || $event->reverted_at) {
            throw ValidationException::withMessages(['event' => 'Nothing to undo.']);
        }

        DB::transaction(function () use ($event) {
            $subject = $event->subject;
            $parsed = $event->parsed_json;

            if ($subject) {
                if ($parsed['_created'] ?? false) {
                    $subject->delete(); // soft delete — creations are reverted by removal
                } elseif ($subject instanceof LedgerEntry) {
                    $subject->update(['status' => 'open', 'paid_at' => null]);
                } elseif ($subject instanceof Todo) {
                    $subject->update(['status' => 'open', 'done_at' => null]);
                }
            }

            $event->update(['reverted_at' => now()]);
        });
    }

    private function markPaid(array $parsed): LedgerEntry
    {
        $entry = $this->findLedger($parsed['target'] ?? '');

        if (! $entry) {
            throw ValidationException::withMessages([
                'text' => "No open ledger entry matches \"{$parsed['target']}\".",
            ]);
        }

        $entry->update(['status' => 'paid', 'paid_at' => now()]);

        return $entry;
    }

    /** Mark a matching open receivable paid; record new income if none exists. */
    private function incomeReceived(array $parsed, bool &$created): LedgerEntry
    {
        if ($entry = $this->findLedger($parsed['target'] ?? '', 'receivable')) {
            $entry->update(['status' => 'paid', 'paid_at' => now()]);

            return $entry;
        }

        $created = true;

        return LedgerEntry::create([
            'contact_id' => $this->contactFor($parsed['target'] ?? null)?->id,
            'direction' => 'receivable',
            'title' => $parsed['target'] ?? 'Income',
            'amount_mmk' => $parsed['amount_mmk'] ?? 0,
            'status' => 'paid',
            'paid_at' => now(),
        ]);
    }

    private function addLedger(array $parsed, string $direction): LedgerEntry
    {
        return LedgerEntry::create([
            'contact_id' => $this->contactFor($parsed['target'] ?? null, createIfMissing: true)?->id,
            'direction' => $direction,
            'title' => $parsed['target'] ?? 'Untitled',
            'amount_mmk' => $parsed['amount_mmk'] ?? 0,
            'status' => 'open',
            'due_date' => $parsed['due'] ?? null,
        ]);
    }

    private function addTodo(array $parsed): Todo
    {
        return Todo::create([
            'title' => $parsed['target'] ?? 'Untitled',
            'bucket' => $parsed['bucket'] ?? 'personal',
            'due_date' => $parsed['due'] ?? null,
        ]);
    }

    private function completeTodo(array $parsed): Todo
    {
        $todo = $this->bestMatch(Todo::open()->get(), $parsed['target'] ?? '', fn ($t) => [$t->title]);

        if (! $todo) {
            throw ValidationException::withMessages([
                'text' => "No open todo matches \"{$parsed['target']}\".",
            ]);
        }

        $todo->update(['status' => 'done', 'done_at' => now()]);

        return $todo;
    }

    private function addCareTask(array $parsed): CareTask
    {
        $due = $parsed['due'] ? now()->parse($parsed['due']) : now()->addDay();

        // One-off phrases become weekly tasks anchored on the due weekday;
        // the Care screen is where schedules get refined.
        return CareTask::create([
            'title' => $parsed['target'] ?? 'Care task',
            'schedule_type' => 'weekly',
            'weekday' => $due->dayOfWeek,
            'time_of_day' => '09:00:00',
            'next_run_at' => $due->setTime(9, 0),
            'active' => true,
        ]);
    }

    private function findLedger(string $target, ?string $direction = null): ?LedgerEntry
    {
        $query = LedgerEntry::open()->with('contact');
        if ($direction) {
            $query->where('direction', $direction);
        }

        return $this->bestMatch(
            $query->get(),
            $target,
            fn ($e) => [$e->title, $e->contact?->name, ...($e->contact?->aliases ?? [])],
        );
    }

    private function contactFor(?string $name, bool $createIfMissing = false): ?Contact
    {
        if (! $name) {
            return null;
        }

        $existing = $this->bestMatch(Contact::all(), $name, fn ($c) => [$c->name, ...($c->aliases ?? [])]);

        if ($existing || ! $createIfMissing) {
            return $existing;
        }

        return Contact::create(['name' => $name, 'aliases' => []]);
    }

    /**
     * Pick the record whose candidate strings best overlap the target
     * (case-insensitive containment, then similar_text as tiebreaker).
     */
    private function bestMatch(Collection $records, string $target, callable $candidates): ?Model
    {
        $target = mb_strtolower(trim($target));
        if ($target === '') {
            return null;
        }

        $best = null;
        $bestScore = 0;

        foreach ($records as $record) {
            foreach ($candidates($record) as $candidate) {
                if (! $candidate) {
                    continue;
                }
                $candidate = mb_strtolower($candidate);

                if (str_contains($candidate, $target) || str_contains($target, $candidate)) {
                    $score = 100;
                } else {
                    similar_text($candidate, $target, $percent);
                    $score = $percent >= 60 ? $percent : 0;
                }

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = $record;
                }
            }
        }

        return $best;
    }
}
