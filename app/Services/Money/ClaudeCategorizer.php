<?php

namespace App\Services\Money;

use App\Models\LedgerEntry;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Names a spending category for ledger entries. One call handles many
 * entries, so the backfill of a whole history costs a single request per
 * chunk rather than one per row.
 *
 * The user's existing categories are sent every time so the model reuses
 * them instead of inventing a new synonym ("Food" vs "Food & Drinks") —
 * without that the breakdown fragments into unusable one-entry groups.
 */
class ClaudeCategorizer implements CategorizerContract
{
    public function categorize(array $titles, array $existing): array
    {
        if ($titles === []) {
            return [];
        }

        $response = Http::withHeaders([
            'x-api-key' => config('lifeos.anthropic.key'),
            'anthropic-version' => '2023-06-01',
        ])->timeout(30)->retry(2, 300)->post('https://api.anthropic.com/v1/messages', [
            'model' => config('lifeos.anthropic.fast_model'),
            'max_tokens' => 2000,
            'thinking' => ['type' => 'disabled'],
            'system' => $this->systemPrompt($existing),
            'messages' => [['role' => 'user', 'content' => json_encode(
                $titles, JSON_UNESCAPED_UNICODE
            )]],
        ]);

        if ($response->failed()) {
            throw new RuntimeException(
                $response->json('error.message') ?? 'Claude API request failed.'
            );
        }

        $text = collect($response->json('content', []))
            ->firstWhere('type', 'text')['text'] ?? '';

        // Defensive: strip markdown fences if the model wraps the object.
        $text = trim(preg_replace('/^```(?:json)?|```$/m', '', trim($text)));

        $map = json_decode($text, true);

        if (! is_array($map)) {
            throw new RuntimeException('Categorizer returned invalid JSON.');
        }

        // Only ids we asked about, only non-empty labels — a hallucinated key
        // must never write a category onto an entry that was not in the batch.
        $clean = [];
        foreach ($titles as $id => $_) {
            $label = trim((string) ($map[$id] ?? ''));
            if ($label !== '') {
                $clean[$id] = mb_substr($label, 0, 60);
            }
        }

        return $clean;
    }

    private function systemPrompt(array $existing): string
    {
        $list = $existing ? implode(', ', $existing) : '(none yet — you are naming the first ones)';
        $fallback = LedgerEntry::UNCATEGORIZED;

        return <<<PROMPT
        You label personal money entries with a spending category. The user is
        in Myanmar and writes in Burmese, English, or a mix.

        The user's existing categories: {$list}

        Rules:
        - REUSE an existing category whenever the entry plausibly belongs to it.
          Only invent a new one when nothing above fits.
        - A new category must be a short, general English noun phrase in Title
          Case (2-3 words max): "Food & Drinks", "Transport", "Utilities",
          "Rent", "Groceries", "Health", "Family", "Salary", "Shopping".
        - Categories describe a KIND of spending, never a person or a one-off
          event. "Ko Ko" is not a category — money lent to a friend is "Loans",
          a gift for them is "Gifts".
        - If an entry is too vague to place, use "{$fallback}".

        You receive a JSON object of {"entry id": "text"}.
        Respond with ONLY minified JSON of {"entry id": "Category"} — the same
        keys, no extras, no markdown, no commentary.

        EXAMPLE
        in:  {"3":"KBZ pay ဆိုင်ကယ်ဆီ 15000","7":"lunch at shop","9":"Cargo Pro delivery fee"}
        out: {"3":"Transport","7":"Food & Drinks","9":"Business"}
        PROMPT;
    }
}
