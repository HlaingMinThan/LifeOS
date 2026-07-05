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
            'due_time' => $parsed['due_time'] ?? null,
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
        $today = now()->toDateString();
        $tomorrow = now()->addDay()->toDateString();

        $batchInstructions = <<<INSTRUCTIONS

BATCH MODE for this request: the user pasted several lines at once.
Respond with ONLY a minified JSON array — one object per actionable ITEM,
using the same schema plus a "raw" field holding the source phrase.

CRITICAL — items vs lines: one line can hold SEVERAL items joined by
connectors (ပီးတော့, ပီးရင်, "and", "then", "also"). Split every one into
its own object — NEVER drop or merge any. Count the actions before you
answer: each amount + person pair is its own item.

CRITICAL — header lines: when a line is only a date or context ("July 6
2026 Monday", "မနက်ဖြန်", a person, a place), it is NOT an item. It MUST
NOT appear in the array. Instead its meaning applies to EVERY line below
it (until the next header): a date header sets "due" on all of them.
Times of day stay inside the target text; only the date goes in "due".

CRITICAL — SECTION headers decide the action of every line below them,
until the next header. Map the section meaning, whatever its exact words:
- money to pay (ပေးစရာငွေ, expenses, payables) → add_payable
- money coming in (ဝင်ငွေ, income, receivables) → add_receivable
- todo sections → add_todo; bucket comes from the header
  ("work todos" → work, "personal todos" → personal)
- a person's care section ("X အတွက်", care) → add_care_task
- idea sections (ideas, "work idea") → add_idea
In a pasted list, bare "Name - amount" lines describe existing state:
they are NEW records typed by their section — NEVER mark_paid and NEVER
income_received (nothing in a list "just happened").

SECTION EXAMPLE:
"ပေးစရာငွေ
ko ko - 200,000
Income
- shop A - 50000
work todos
- website update လုပ်ရန်
kaly အတွက်
- surprise gift ပို့ရန်
Work idea
- coffee shop"
→ [{"raw":"ko ko - 200,000","action":"add_payable","target":"Ko Ko","amount_mmk":200000,"confidence":0.9},
{"raw":"shop A - 50000","action":"add_receivable","target":"shop A","amount_mmk":50000,"confidence":0.9},
{"raw":"website update လုပ်ရန်","action":"add_todo","target":"website update လုပ်ရန်","bucket":"work","confidence":0.9},
{"raw":"surprise gift ပို့ရန်","action":"add_care_task","target":"surprise gift ပို့ရန်","confidence":0.9},
{"raw":"coffee shop","action":"add_idea","target":"coffee shop","confidence":0.9}]

CRITICAL — date references: "အဲ့နေ့" / "that day" / "same day" means the
most recently mentioned date in the message. Resolve it — never leave
"due" empty when a reference points to a known date.

BATCH EXAMPLE (assume today is {$today}, tomorrow is {$tomorrow}):
"ဒီနေ့ shop က 100k ဝင်ပြီ ပီးတော့ ko ko ဆီက မနက်ဖြန် 50k ဝင်မယ်
အဲ့နေ့ chit ကို 30k ပေးရမယ်
မနက် ၁၀ နာရီ ဈေးသွားရန်"
→ [{"raw":"ဒီနေ့ shop က 100k ဝင်ပြီ","action":"income_received","target":"shop","amount_mmk":100000,"due":"{$today}","confidence":0.9},
{"raw":"ko ko ဆီက မနက်ဖြန် 50k ဝင်မယ်","action":"add_receivable","target":"Ko Ko","amount_mmk":50000,"due":"{$tomorrow}","confidence":0.9},
{"raw":"အဲ့နေ့ chit ကို 30k ပေးရမယ်","action":"add_payable","target":"Chit","amount_mmk":30000,"due":"{$tomorrow}","confidence":0.85},
{"raw":"မနက် ၁၀ နာရီ ဈေးသွားရန်","action":"add_todo","target":"မနက် ၁၀ နာရီ ဈေးသွားရန်","amount_mmk":null,"due":null,"due_time":"10:00","bucket":"personal","confidence":0.85}]
INSTRUCTIONS;

        $response = Http::withHeaders([
            'x-api-key' => config('lifeos.anthropic.key'),
            'anthropic-version' => '2023-06-01',
        ])->timeout(120)->retry(2, 300)->post('https://api.anthropic.com/v1/messages', [
            'model' => config('lifeos.anthropic.model'),
            // Burmese is token-dense and every item carries raw + target;
            // a 30-line dump needs far more than a chat reply.
            'max_tokens' => 16000,
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

        // Defensive: strip markdown fences if the model wraps the array.
        $responseText = trim(preg_replace('/^```(?:json)?|```$/m', '', trim($responseText)));

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
                    'due_time' => $item['due_time'] ?? null,
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
        $nowTime = now()->format('H:i');
        $inTwenty = now()->addMinutes(20)->format('H:i');

        return <<<PROMPT
You convert one short life-management command into JSON. The user writes in
Burmese, English, or mixed. Respond with ONLY minified JSON, no markdown.

Today's date: {$today} · Current time: {$nowTime}

Burmese number units: သိန်း = 100,000 · သောင်း = 10,000 · ထောင် = 1,000
"500k" = 500,000. "7 သိန်း" = 700,000.

Known contacts: {$contacts}
Open payables/receivables: {$ledger}
Open todos: {$todos}

Match targets against the known lists above (fuzzy, either script).
If nothing matches, treat as a new record.

Tense decides the action for money:
- Future/expected ("ဝင်မယ်", "ရမယ်", "will come in", "expecting") →
  add_receivable (money coming) or add_payable ("ပေးရမယ်", to pay).
- Already happened ("ဝင်ပြီ", "ရပြီ", "received") → income_received.
  ("ပေးပြီးပြီ", "paid") → mark_paid.
Never mark expected money as received.

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
 "due_time": "HH:MM"|null, "bucket": "work"|"personal"|null,
 "confidence": 0-1}

Times: an explicit clock time ("10pm", "ည ၁၀ နာရီ") → due_time in 24h form,
and due defaults to today if no date is given (tomorrow if that time already
passed today). Relative times ("in 20 minutes", "နောက် ၁ နာရီနေရင်") →
compute due_time from the current time above. Keep the spoken time words in
the target text too.

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

"ည ၁၀ နာရီ ဆေးသောက်ရန်"
→ {"action":"add_todo","target":"ည ၁၀ နာရီ ဆေးသောက်ရန်","due":"{$today}","due_time":"22:00","bucket":"personal","confidence":0.85}

"in 20 mins check the rice cooker"
→ {"action":"add_todo","target":"in 20 mins check the rice cooker","due":"{$today}","due_time":"{$inTwenty}","bucket":"personal","confidence":0.85}

{$learned}
PROMPT;
    }
}
