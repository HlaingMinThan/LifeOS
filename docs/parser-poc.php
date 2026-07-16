<?php

/**
 * Life OS parser proof-of-concept.
 *
 * Tests the §4 magic-inbox prompt against the real Claude API with
 * Burmese/English/mixed phrases before we build the app around it.
 *
 * Usage:  ANTHROPIC_API_KEY=sk-ant-... php parser-poc.php [model]
 * Default model: claude-sonnet-5 (try claude-haiku-4-5-20251001 for speed/cost comparison)
 */
$apiKey = getenv('ANTHROPIC_API_KEY');
if (! $apiKey) {
    fwrite(STDERR, "Set ANTHROPIC_API_KEY first: ANTHROPIC_API_KEY=sk-ant-... php parser-poc.php\n");
    exit(1);
}
$model = $argv[1] ?? 'claude-sonnet-5';

// ---- Simulated live data snapshot (what the app will inject from the DB) ----
$contacts = 'Gon Khaung (aliases: ဂွန်ခေါင်), Arkar (aliases: အာကာ), Cargo Pro (aliases: ကာဂိုပရို)';
$openLedger = '"Gon Khaung loan" (payable, 500000 MMK), "Arkar delivery fee" (receivable, 100000 MMK)';
$openTodos = '"FB page video content" (work), "Renew car insurance" (personal)';
$nextFriday = date('Y-m-d', strtotime('next friday'));
$today = date('Y-m-d');

$system = <<<PROMPT
You convert one short life-management command into JSON. The user writes in
Burmese, English, or mixed. Respond with ONLY minified JSON, no markdown.

Today's date: {$today}

Burmese number units: သိန်း = 100,000 · သောင်း = 10,000 · ထောင် = 1,000
"500k" = 500,000. "7 သိန်း" = 700,000.

Known contacts: {$contacts}
Open payables/receivables: {$openLedger}
Open todos: {$openTodos}

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
PROMPT;

// ---- Test phrases: spec examples + harder variations ----
$tests = [
    // From the spec (sanity)
    'paid gon khaung 500k',
    'ဂွန်ခေါင်ကို ၅ သိန်း ပေးပြီးပြီ',
    'cargo pro က 780k ဝင်ပြီ',
    'fb video content ပြီးပြီ',
    'arkar ဆီက 1 သိန်း ရစရာရှိတယ်',
    'mushroom idea မှတ်ထား',
    // Harder: fuzzy alias, Burmese digits, units
    'အာကာ ပိုက်ဆံ ပြန်ရပြီ',                       // Arkar paid me back → mark receivable paid
    'ကိုကို့ကို ၃ သောင်း ချေးထားတယ်',                // new contact "Ko Ko", payable 30,000
    'mom ကို ဆေးဝယ်ပေးရမယ် မနက်ဖြန်',              // todo with due tomorrow
    'car insurance သက်တမ်းတိုးပြီးပြီ',              // complete existing todo (fuzzy match)
    'နောက်အပတ် ကြာသပတေးနေ့ presentation တင်ရမယ်',   // work todo, due next Thursday
    'coffee shop စလုပ်ရင် ကောင်းမလား',              // vague → idea or unknown?
    'hello how are you',                              // garbage → must be unknown
];

function callClaude(string $apiKey, string $model, string $system, string $user): array
{
    $payload = json_encode([
        'model' => $model,
        'max_tokens' => 300,
        // Sonnet 5 defaults to adaptive thinking; a one-line parse doesn't
        // need it and it triples latency + cost.
        'thinking' => ['type' => 'disabled'],
        'system' => $system,
        'messages' => [['role' => 'user', 'content' => $user]],
    ]);
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: '.$apiKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_TIMEOUT => 30,
    ]);
    $start = microtime(true);
    $res = curl_exec($ch);
    $ms = (int) round((microtime(true) - $start) * 1000);
    if ($res === false) {
        return ['error' => curl_error($ch), 'ms' => $ms];
    }
    curl_close($ch);
    $body = json_decode($res, true);
    if (isset($body['error'])) {
        return ['error' => $body['error']['message'], 'ms' => $ms];
    }
    $text = '';
    foreach ($body['content'] ?? [] as $block) {
        if (($block['type'] ?? '') === 'text') {
            $text = $block['text'];
            break;
        }
    }

    return ['text' => $text, 'ms' => $ms,
        'in' => $body['usage']['input_tokens'] ?? 0, 'out' => $body['usage']['output_tokens'] ?? 0];
}

echo "Model: {$model}\n";
echo str_repeat('=', 100)."\n";
$totalMs = 0;
foreach ($tests as $i => $phrase) {
    $r = callClaude($apiKey, $model, $system, $phrase);
    $totalMs += $r['ms'];
    printf("%2d. %s\n", $i + 1, $phrase);
    if (isset($r['error'])) {
        printf("    ERROR (%d ms): %s\n", $r['ms'], $r['error']);

        continue;
    }
    $parsed = json_decode($r['text'], true);
    $ok = $parsed !== null ? 'valid JSON' : 'INVALID JSON!';
    printf("    → %s\n    [%d ms, %d in / %d out tokens, %s]\n", trim($r['text']), $r['ms'], $r['in'], $r['out'], $ok);
}
printf("\nAvg latency: %d ms over %d phrases\n", $totalMs / count($tests), count($tests));
