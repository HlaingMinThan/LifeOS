<?php

namespace App\Services\Inbox;

use App\Models\Contact;

/**
 * Keyword-based stand-in for the Claude parser. Same output schema,
 * works offline — good enough to exercise the confirm/apply/undo flow
 * with the spec's example phrases. Not meant to be clever.
 */
class FakeParser implements ParserContract
{
    public function parse(string $text): array
    {
        $t = $this->normalize($text);
        $amount = $this->extractAmount($t);
        $contact = $this->matchContact($t);

        return match (true) {
            $this->hasAny($t, ['ဝင်ပြီ', 'received', 'ရရှိ']) => $this->result('income_received', $contact ?? $this->cleanTitle($t), $amount),
            $this->hasAny($t, ['paid', 'ပေးပြီး', 'ဆပ်ပြီး']) => $this->result('mark_paid', $contact ?? $this->cleanTitle($t), $amount),
            $this->hasAny($t, ['ပြန်ရပြီ', 'paid me back']) => $this->result('mark_paid', $contact ?? $this->cleanTitle($t), $amount),
            $this->hasAny($t, ['ရစရာ', 'owes me']) => $this->result('add_receivable', $contact ?? $this->cleanTitle($t), $amount),
            $this->hasAny($t, ['ချေး', 'i owe', 'borrowed']) => $this->result('add_payable', $contact ?? $this->cleanTitle($t), $amount),
            $this->hasAny($t, ['ပြီးပြီ', 'done', 'finished']) => $this->result('complete_todo', $this->cleanTitle($t)),
            $this->hasAny($t, ['idea', 'မှတ်ထား']) => $this->result('add_idea', $this->cleanTitle($t)),
            $this->hasAny($t, ['care:', 'flowers', 'ပန်းစည်း']) => $this->result('add_care_task', $this->cleanTitle($t)),
            str_word_count(preg_replace('/[^\x20-\x7E]/u', 'x', $t)) >= 2 || mb_strlen($t) >= 6
                => $this->result('add_todo', $this->cleanTitle($t), null, 0.75, 'personal'),
            default => $this->result('unknown', $text, null, 0.3),
        };
    }

    private function result(string $action, ?string $target, ?int $amount = null, float $confidence = 0.9, ?string $bucket = null): array
    {
        return [
            'action' => $action,
            'target' => $target !== null && $target !== '' ? $target : null,
            'amount_mmk' => $amount,
            'due' => null,
            'due_time' => null,
            'bucket' => $bucket,
            'confidence' => $confidence,
        ];
    }

    private function normalize(string $text): string
    {
        // Burmese digits → Arabic so one regex handles both scripts.
        $text = strtr($text, array_combine(
            ['၀', '၁', '၂', '၃', '၄', '၅', '၆', '၇', '၈', '၉'],
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
        ));

        return mb_strtolower(trim($text));
    }

    private function hasAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function extractAmount(string $text): ?int
    {
        // သိန်း = 100,000 · သောင်း = 10,000 · ထောင် = 1,000 · k = 1,000 · m = 1,000,000
        if (! preg_match('/(\d+(?:\.\d+)?)\s*(သိန်း|သောင်း|ထောင်|k|m)?/u', $text, $m) || $m[1] === '') {
            return null;
        }

        $value = (float) $m[1];
        $unit = $m[2] ?? '';

        return (int) round($value * match ($unit) {
            'သိန်း' => 100_000,
            'သောင်း' => 10_000,
            'ထောင်', 'k' => 1_000,
            'm' => 1_000_000,
            default => 1,
        });
    }

    private function matchContact(string $text): ?string
    {
        foreach (Contact::all() as $contact) {
            $candidates = [$contact->name, ...($contact->aliases ?? [])];
            foreach ($candidates as $candidate) {
                if ($candidate && str_contains($text, mb_strtolower($candidate))) {
                    return $contact->name;
                }
            }
        }

        return null;
    }

    /** Strip keywords and amounts so what remains can serve as a title. */
    private function cleanTitle(string $text): string
    {
        $noise = [
            'ဝင်ပြီ', 'received', 'ရရှိ', 'paid', 'ပေးပြီးပြီ', 'ပေးပြီး', 'ဆပ်ပြီး', 'ပြန်ရပြီ',
            'ရစရာရှိတယ်', 'ရစရာ', 'owes me', 'i owe', 'borrowed', 'ချေးထားတယ်', 'ပြီးပြီ',
            'done', 'finished', 'idea', 'မှတ်ထား', 'care:', 'ဆီက', 'ကို', 'က',
        ];
        $text = str_replace($noise, ' ', $text);
        $text = preg_replace('/\d+(?:\.\d+)?\s*(သိန်း|သောင်း|ထောင်|k|m)?/u', ' ', $text);

        return trim(preg_replace('/\s+/u', ' ', $text));
    }
}
