<?php

namespace App\Services\Inbox;

use App\Models\Contact;
use App\Models\LedgerEntry;
use App\Models\ParserExample;
use App\Models\Todo;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The real parser: docs/lifeos-build-spec.md §4. Sends a live data
 * snapshot (contacts, open titles) so the model resolves against real
 * records, plus the 10 most recent corrected parses so it learns the
 * user's phrasing.
 */
class ClaudeParser implements ParserContract
{
    public function parse(string $text): array
    {
        $response = Http::withHeaders([
            'x-api-key' => config('lifeos.anthropic.key'),
            'anthropic-version' => '2023-06-01',
        ])->timeout(20)->retry(2, 300)->post('https://api.anthropic.com/v1/messages', [
            'model' => config('lifeos.anthropic.model'),
            'max_tokens' => 300,
            // Sonnet 5 defaults to adaptive thinking; a one-line parse doesn't
            // need it and it triples latency + cost.
            'thinking' => ['type' => 'disabled'],
            'system' => $this->systemPrompt(),
            'messages' => [['role' => 'user', 'content' => $text]],
        ]);

        if ($response->failed()) {
            throw new RuntimeException(
                $response->json('error.message') ?? 'Claude API request failed.'
            );
        }

        $responseText = collect($response->json('content', []))
            ->firstWhere('type', 'text')['text'] ?? '';

        $parsed = json_decode($responseText, true);

        if (! is_array($parsed) || ! isset($parsed['action'])) {
            throw new RuntimeException('Parser returned invalid JSON.');
        }

        return [
            'action' => $parsed['action'],
            'target' => $parsed['target'] ?? null,
            'amount_mmk' => $parsed['amount_mmk'] ?? null,
            'due' => $parsed['due'] ?? null,
            'bucket' => $parsed['bucket'] ?? null,
            'confidence' => (float) ($parsed['confidence'] ?? 0),
        ];
    }

    /**
     * Batch mode: parse a whole pasted list in one call, so header lines
     * ("မနက်ဖြန်", "for the shop") give context to the lines below them.
     * Returns [['raw_text' => ..., 'parsed' => [...]], ...].
     */
    public function parseMany(string $text): array
    {
        $tomorrow = now()->addDay()->toDateString();

        $batchInstructions = <<<INSTRUCTIONS

BATCH MODE for this request: the user pasted several lines at once.
Respond with ONLY a minified JSON array — one object per actionable item,
using the same schema plus a "raw" field holding the source line.

CRITICAL — header lines: when a line is only a date or context ("July 6
2026 Monday", "မနက်ဖြန်", a person, a place), it is NOT an item. It MUST
NOT appear in the array. Instead its meaning applies to EVERY line below
it (until the next header): a date header sets "due" on all of them.
Times of day stay inside the target text; only the date goes in "due".

BATCH EXAMPLE (assume tomorrow is {$tomorrow}):
"မနက်ဖြန်
မနက် ၁၀ နာရီ ဈေးသွားရန်
၁၁ နာရီ လျှော်ဖွပ်ရန်"
→ [{"raw":"မနက် ၁၀ နာရီ ဈေးသွားရန်","action":"add_todo","target":"မနက် ၁၀ နာရီ ဈေးသွားရန်","amount_mmk":null,"due":"{$tomorrow}","bucket":"personal","confidence":0.85},
{"raw":"၁၁ နာရီ လျှော်ဖွပ်ရန်","action":"add_todo","target":"၁၁ နာရီ လျှော်ဖွပ်ရန်","amount_mmk":null,"due":"{$tomorrow}","bucket":"personal","confidence":0.85}]
INSTRUCTIONS;

        $response = Http::withHeaders([
            'x-api-key' => config('lifeos.anthropic.key'),
            'anthropic-version' => '2023-06-01',
        ])->timeout(60)->retry(2, 300)->post('https://api.anthropic.com/v1/messages', [
            'model' => config('lifeos.anthropic.model'),
            'max_tokens' => 4000,
            'thinking' => ['type' => 'disabled'],
            'system' => $this->systemPrompt().$batchInstructions,
            'messages' => [['role' => 'user', 'content' => $text]],
        ]);

        if ($response->failed()) {
            throw new RuntimeException(
                $response->json('error.message') ?? 'Claude API request failed.'
            );
        }

        $responseText = collect($response->json('content', []))
            ->firstWhere('type', 'text')['text'] ?? '';

        $items = json_decode($responseText, true);

        if (! is_array($items) || array_is_list($items) === false) {
            throw new RuntimeException('Batch parser returned invalid JSON.');
        }

        return collect($items)
            ->filter(fn ($item) => is_array($item) && isset($item['action'])
                && trim($item['target'] ?? '') !== '') // header leftovers have no target
            ->map(fn ($item) => [
                'raw_text' => $item['raw'] ?? ($item['target'] ?? ''),
                'parsed' => [
                    'action' => $item['action'],
                    'target' => $item['target'] ?? null,
                    'amount_mmk' => $item['amount_mmk'] ?? null,
                    'due' => $item['due'] ?? null,
                    'bucket' => $item['bucket'] ?? null,
                    'confidence' => (float) ($item['confidence'] ?? 0),
                ],
            ])
            ->values()
            ->all();
    }

    private function systemPrompt(): string
    {
        $contacts = Contact::all()->map->promptLabel()->implode(', ') ?: '(none yet)';

        $ledger = LedgerEntry::open()->get()
            ->map(fn ($e) => "\"{$e->title}\" ({$e->direction}, {$e->amount_mmk} MMK)")
            ->implode(', ') ?: '(none)';

        $todos = Todo::open()->get()
            ->map(fn ($t) => "\"{$t->title}\" ({$t->bucket})")
            ->implode(', ') ?: '(none)';

        $learned = ParserExample::latest()->take(10)->get()
            ->map(fn ($ex) => "\"{$ex->raw_text}\"\n→ ".json_encode($ex->corrected_json, JSON_UNESCAPED_UNICODE))
            ->implode("\n\n");

        $today = now()->toDateString();
        $tomorrow = now()->addDay()->toDateString();
        $nextFriday = now()->next(5)->toDateString();

        return <<<PROMPT
You convert one short life-management command into JSON. The user writes in
Burmese, English, or mixed. Respond with ONLY minified JSON, no markdown.

Today's date: {$today}

Burmese number units: သိန်း = 100,000 · သောင်း = 10,000 · ထောင် = 1,000
"500k" = 500,000. "7 သိန်း" = 700,000.

Known contacts: {$contacts}
Open payables/receivables: {$ledger}
Open todos: {$todos}

Match targets against the known lists above (fuzzy, either script).
If nothing matches, treat as a new record.

Target rules:
- Matching an existing record (mark_paid, complete_todo, income_received):
  return that record's exact title or the contact's canonical name.
- Creating a NEW record (add_todo, add_idea, add_care_task, add_payable,
  add_receivable): keep "target" in the user's own words and script,
  exactly as written. NEVER translate, paraphrase, or reword — copy the
  user's words verbatim, keeping English words in English and Burmese
  words in Burmese. The user must recognize their own phrasing later.
  Only strip amounts and dates.
  For new ledger entries where the target is a person, use the contact
  name only.

Schema:
{"action": one of [mark_paid, add_payable, add_receivable, income_received,
  add_todo, complete_todo, add_care_task, add_idea, unknown],
 "target": string, "amount_mmk": int|null, "due": "YYYY-MM-DD"|null,
 "bucket": "work"|"personal"|null, "confidence": 0-1}

If confidence < 0.7 set action to "unknown" — the UI will ask, never guess big.

EXAMPLES:
"paid gon khaung 500k"
→ {"action":"mark_paid","target":"Gon Khaung","amount_mmk":500000,"confidence":0.95}

"cargo pro က 780k ဝင်ပြီ"
→ {"action":"income_received","target":"Cargo Pro","amount_mmk":780000,"confidence":0.95}

"ဂွန်ခေါင်ကို ၅ သိန်း ပေးပြီးပြီ"
→ {"action":"mark_paid","target":"Gon Khaung","amount_mmk":500000,"confidence":0.9}

"fb video content ပြီးပြီ"
→ {"action":"complete_todo","target":"FB page video content","bucket":"work","confidence":0.9}

"သောကြာနေ့ ပန်းစည်း ပို့ရန်"
→ {"action":"add_care_task","target":"ပန်းစည်း ပို့ရန်","due":"{$nextFriday}","confidence":0.9}

"arkar ဆီက 1 သိန်း ရစရာရှိတယ်"
→ {"action":"add_receivable","target":"Arkar","amount_mmk":100000,"confidence":0.9}

"mushroom idea မှတ်ထား"
→ {"action":"add_idea","target":"mushroom idea","confidence":0.9}

"mom အတွက် ဆေးဝယ်ရန် မနက်ဖြန်"
→ {"action":"add_todo","target":"mom အတွက် ဆေးဝယ်ရန်","due":"{$tomorrow}","bucket":"personal","confidence":0.85}

{$learned}
PROMPT;
    }
}
