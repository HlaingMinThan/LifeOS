# Life OS — Build Spec (HLD-lite)

Personal, single-user Life OS. Laravel 11+ / Inertia / Vue 3 / Tailwind, mobile-first PWA.
Goal: state persists so "catching up" = open one screen, never rewrite lists again.
Input philosophy: one magic text box (typed, Burmese/English/mixed) parsed by Claude into actions. No category pickers, no forms as the default path.

---

## 1. Scope

**In (V1, 4 days):** money ledger (payables / receivables), todos (work + personal), care tasks (recurring + randomized surprises), idea parking lot, catch-up dashboard, magic inbox (AI parse → confirm → apply → undo), brain-dump onboarding, PWA installable, Telegram notifications.

**Out (V1):** multi-user, roles/policies, budgets/analytics, voice input, offline sync, native app.

---

## 2. Database schema

```
users            (single seeded user; standard Breeze)

contacts
  id, name, aliases (json: ["Gon Khaung","ဂွန်ခေါင်"]), created_at

ledger_entries
  id, contact_id (nullable), direction ENUM(payable, receivable),
  title, amount_mmk BIGINT, amount_usd DECIMAL nullable,
  status ENUM(open, paid, cancelled), due_date nullable,
  paid_at nullable, note nullable, timestamps

todos
  id, title, bucket ENUM(work, personal, money_task),
  status ENUM(open, done), due_date nullable,
  done_at nullable, timestamps

care_tasks
  id, title, schedule_type ENUM(daily, weekly, random),
  time_of_day TIME nullable, weekday TINYINT nullable,
  random_min_days / random_max_days TINYINT nullable,  -- e.g. surprise every 7–20 days
  next_run_at DATETIME, active BOOL, timestamps

care_task_logs
  id, care_task_id, ran_at, status ENUM(done, skipped)

ideas
  id, title, note nullable, status ENUM(parked, active, dropped), timestamps

inbox_events        -- audit log of every magic-box action (powers Undo)
  id, raw_text, parsed_json JSON, applied BOOL,
  reverted_at nullable, timestamps
```

Undo = read `inbox_events.parsed_json`, apply the inverse (mark_paid → reopen, create → soft-delete). Keep it dumb and reliable.

---

## 3. Routes

```
GET  /                     Catch-up dashboard (Inertia)
GET  /money                Ledger list
GET  /todos                Todos list
GET  /care                 Care tasks
GET  /ideas                Ideas

POST /inbox/parse          { text } → calls Claude → returns parsed action JSON (NOT applied)
POST /inbox/apply          { event_id or parsed payload } → applies, writes inbox_events
POST /inbox/undo/{event}   Reverts an applied event

POST /onboard/dump         { text } → Claude parses full brain dump → returns array of records
POST /onboard/confirm      Persists the confirmed array

PATCH /ledger/{id}/toggle  Swipe right: paid/reopen
PATCH /todos/{id}/toggle   Swipe right: done/reopen
DELETE (soft) on each      Swipe left
```

Two-step parse→apply is deliberate: the confirm chip in the UI sits between them.

---

## 4. Parser prompt (the heart of the app)

System prompt in English, few-shot in Burmese/mixed. Send the user's **live data snapshot** (contact names + aliases, open ledger titles, open todo titles) so the model resolves against real records instead of guessing.

```
SYSTEM:
You convert one short life-management command into JSON. The user writes in
Burmese, English, or mixed. Respond with ONLY minified JSON, no markdown.

Burmese number units: သိန်း = 100,000 · သောင်း = 10,000 · ထောင် = 1,000
"500k" = 500,000. "7 သိန်း" = 700,000.

Known contacts: {{contacts_with_aliases}}
Open payables/receivables: {{open_ledger_titles}}
Open todos: {{open_todo_titles}}

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
→ {"action":"add_care_task","target":"Send flowers","due":"{{next_friday}}","confidence":0.9}

"arkar ဆီက 1 သိန်း ရစရာရှိတယ်"
→ {"action":"add_receivable","target":"Arkar","amount_mmk":100000,"confidence":0.9}

"mushroom idea မှတ်ထား"
→ {"action":"add_idea","target":"Mushroom selling","confidence":0.9}
```

When you correct a wrong parse in the UI, append that pair to a
`parser_examples` table and inject the 10 most recent into the prompt.
The parser literally learns your phrasing over time.

---

## 5. Notifications (Telegram)

- Create bot via @BotFather, store bot token + your chat_id in `.env`.
- `php artisan schedule:run` every minute (cron), scheduler checks `care_tasks.next_run_at`.
- On fire: send Telegram message, log to `care_task_logs`, compute next `next_run_at`
  (daily/weekly = fixed; random = now + rand(min_days, max_days) — keeps surprises unpredictable).
- Morning digest 7:00 AM: today's care tasks + overdue todos + open payables. This digest
  IS the catch-up screen pushed to you.

---

## 6. Four-day plan

**Day 1 — Skeleton.** Breeze + Inertia + Vue, single seeded user, mobile-first layout shell
(bottom nav: Home / Money / Todos / Care / Ideas), PWA manifest + service worker (installable,
no offline logic), deploy pipeline to your existing server.

**Day 2 — Data + magic inbox.** Migrations/models above, ledger + todos CRUD (swipe toggle),
`/inbox/parse` with the prompt in §4, confirm-chip UI, `/inbox/apply` + undo via inbox_events.

**Day 3 — Recurrence + Telegram.** care_tasks engine, scheduler, Telegram service, random
surprise scheduling, morning digest, brain-dump onboarding endpoint (same parser, array mode).

**Day 4 — Catch-up screen + polish.** Dashboard queries (owed / incoming / today / overdue),
empty states, Burmese font check (Noto Sans Myanmar fallback), parser_examples self-learning
loop, deploy, install on phone, dogfood with your real list.

---

## 7. Definition of done

Open the app after 3 days away → one screen tells you: who you owe, who owes you,
what's overdue, what Kaly lay needs today. Type "paid gon khaung 500k" → confirmed
and applied in under 5 seconds. Zero forms touched.
