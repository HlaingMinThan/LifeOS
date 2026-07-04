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
→ {"action":"add_care_task","target":"Send flowers","due":"{$nextFriday}","confidence":0.9}

"arkar ဆီက 1 သိန်း ရစရာရှိတယ်"
→ {"action":"add_receivable","target":"Arkar","amount_mmk":100000,"confidence":0.9}

"mushroom idea မှတ်ထား"
→ {"action":"add_idea","target":"Mushroom selling","confidence":0.9}

{$learned}
PROMPT;
    }
}
