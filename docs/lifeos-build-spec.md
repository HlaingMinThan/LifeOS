# Life OS — Build Spec (HLD-lite)

Multi-user Life OS — one isolated workspace per account. Laravel 12 / Inertia / Vue 3 / Tailwind, mobile-first PWA.
Goal: state persists so "catching up" = open one screen, never rewrite lists again.
Input philosophy: one magic text box (typed, Burmese/English/mixed) parsed by Claude into actions. No category pickers, no forms as the default path.

> **Status: V1 shipped and deployed.** Days 1–4 complete, plus post-V1 work (§9).
> This doc tracks what exists, not what was planned — the original 4-day plan is in §8 for history.
> Stack changed during the build: **Laravel 12 Vue starter kit** (not Breeze — Breeze is discontinued;
> the kit ships Fortify auth, TypeScript, shadcn-vue) and **MySQL** (migrated from SQLite July 8).

---

## 1. Scope

**In (V1):** money ledger (expense / income), todos (work + personal + money), care tasks (recurring + randomized surprises), idea parking lot, catch-up dashboard, magic inbox (AI parse → confirm → apply → undo), brain-dump onboarding, PWA installable, Telegram two-way bot + notifications.

**Out (V1):** multi-user, roles/policies, budgets/analytics, voice input, offline sync, native app.

**Added after V1:** todo calendar + day timeline, todo detail page with rich-text notes, focus mode, per-todo timed reminders, natural-language date queries, **multi-user + per-user Telegram bots** (§11), **PWA Web Push notifications** (§12). See §9 for the current backlog.

---

## 2. Database schema

Every table below carries `user_id` — one account, one isolated Life OS. See §11.

```
users            (Fortify + passkeys/2FA from the starter kit)
  … plus the per-user bot: telegram_bot_token (encrypted), telegram_bot_username,
  telegram_chat_id, telegram_webhook_secret (unique), telegram_linked_at,
  telegram_prompt_dismissed_at

contacts
  id, user_id, name, aliases (json: ["Gon Khaung","ဂွန်ခေါင်"]), timestamps

ledger_entries
  id, contact_id (nullable), direction ENUM(payable, receivable),
  title, amount_mmk BIGINT, amount_usd DECIMAL nullable,
  status ENUM(open, paid, cancelled), due_date nullable [indexed],
  paid_at nullable, note nullable, timestamps, softDeletes

todos
  id, title, note nullable (sanitized HTML — rich text),
  bucket ENUM(work, personal, money_task),
  status ENUM(open, done), focused BOOL,
  due_date nullable [indexed], due_time TIME nullable,
  reminded_at nullable, done_at nullable, timestamps, softDeletes

care_tasks
  id, title, schedule_type ENUM(daily, weekly, random),
  time_of_day TIME nullable, weekday TINYINT nullable,
  random_min_days / random_max_days TINYINT nullable,  -- e.g. surprise every 7–20 days
  next_run_at DATETIME, active BOOL, timestamps, softDeletes

care_task_logs
  id, care_task_id, ran_at, status ENUM(done, skipped)   -- written by care:run; not yet surfaced in UI

ideas
  id, title, note nullable, status ENUM(parked, active, dropped), timestamps, softDeletes

inbox_events        -- audit log of every magic-box action (powers Undo)
  id, raw_text, parsed_json JSON, applied BOOL,
  subject_type, subject_id,     -- what the apply touched, so undo hits the exact record
  reverted_at nullable, timestamps

parser_examples     -- self-learning loop; 10 most recent injected into the prompt
  id, raw_text, corrected_json JSON, timestamps
```

Undo = read `inbox_events.parsed_json`, apply the inverse (mark_paid → reopen, create → soft-delete). Keep it dumb and reliable. `parsed_json._created` records whether the apply created a record.

**Schema decisions made during the build:**
- `subject_type`/`subject_id` added to `inbox_events` — undo needs the exact target, not a re-match.
- `due_date` cast as `date:Y-m-d`. Midnight-Yangon serialized to UTC ISO shifted edit forms a day early.
- `todos.note` holds **HTML** from the rich-text editor, sanitized server-side (allowlist: `p br strong b em i u s ul ol li h1-h4 code pre blockquote a`). Plain text is valid too.
- `due_date` indexes on `todos` and `ledger_entries` — every screen runs date-scoped queries.
- **Timezone: `APP_TIMEZONE=Asia/Yangon`** (default was UTC, which put the "7 AM" digest at 1:30 PM local).
- `user_id` is **not** in any `$fillable` — ownership comes from the relation used to create the
  record (`$user->todos()->create(...)`), never from request input. `care_task_logs` has no
  `user_id`; it inherits its owner through `care_task_id`.

---

## 3. Routes

Every route below is scoped to `$request->user()`. Records resolve through the owner's relation
(`$request->user()->todos()->findOrFail($id)`), **not** plain route-model binding — another
account's id must 404, not resolve.

```
GET  /                          Catch-up dashboard (Inertia)
GET  /profile                   Profile + logout + settings shortcuts

POST /telegram/webhook/{secret} Telegram delivery (public; see §5)

GET    /settings/telegram        Guided bot setup
POST   /settings/telegram/token  Validate via getMe, then store
POST   /settings/telegram/detect Read chat_id from getUpdates, register webhook
DELETE /settings/telegram        Disconnect (also deleteWebhook)
PATCH  /settings/telegram/dismiss  "Not now" on the Home nudge

POST /inbox/parse               { text } → Claude → parsed action JSON (NOT applied)
POST /inbox/apply               { raw_text, parsed, corrected? } → applies, writes inbox_events
POST /inbox/undo/{event}        Reverts an applied event

GET  /onboard                   Brain-dump import screen
POST /onboard/dump              { text } → batch parse → array of reviewable records
POST /onboard/confirm           Persists the confirmed array

GET  /money                     Ledger: balance tiles + urgency groups (?all_settled=1 for full history)
POST /money  … /ledger          store / update / toggle / destroy

GET  /todos                     Month calendar (?month=YYYY-MM) — per-day counts + "No date" bucket
GET  /todos/day/{date}          Day timeline. Virtual days: "undated", "overdue"
GET  /todos/{todo}              Detail page (rich-text notes, schedule, focus)
POST /todos                     store
PATCH /todos/{todo}             update
PATCH /todos/{todo}/toggle      done/reopen (also clears focus)
PATCH /todos/{todo}/focus       toggle single focus
DELETE /todos/{todo}            soft delete

GET  /care                      Care tasks
POST /care  · PATCH /care/{task} · PATCH /care/{task}/toggle (pause) · DELETE /care/{task}

GET  /ideas · DELETE /ideas/{idea}
```

Two-step parse→apply is deliberate: the **editable** confirm chip sits between them. Swipe right = done/paid, swipe left = delete (todos + money).

---

## 4. Parser (the heart of the app)

System prompt in English, few-shot in Burmese/mixed. Sends a **live data snapshot** (contacts + aliases, open ledger titles, open todo titles), the **10 most recent `parser_examples`**, and the **last 5 commands from 30 minutes** (short-term memory) so the model resolves against real records and recent context.

`ParserContract::parse(string $text, User $user)` — **every snapshot query is scoped to `$user`**. The prompt leaves the building, so a leak here would hand one person's contacts and debts to another's parse. `MultiUserScopingTest` asserts this against the request body; note the prompt's own few-shot examples mention "Gon Khaung", so a leak test must use a name that can only come from the database.

Config: `INBOX_PARSER=claude|fake` · model `claude-sonnet-5` · **thinking disabled** (adaptive thinking tripled latency and buried the JSON in a later content block) · single parse `max_tokens` 300, timeout 20s, 2 retries.

### Action schema

```json
{"action": "mark_paid|add_payable|add_receivable|income_received|add_todo|
            complete_todo|add_care_task|add_idea|show_day|unknown",
 "target": "string", "amount_mmk": "int|null", "due": "YYYY-MM-DD|null",
 "due_time": "HH:MM|null", "bucket": "work|personal|null", "confidence": "0-1"}
```

If confidence < 0.7 → `unknown`. The UI asks; never guess big.

### Prompt rules that were learned the hard way

Every one of these was a real bug found by dogfooding. **The lesson: rules alone don't stick — a worked example does.** Each rule below is paired with a few-shot example in the prompt.

| Rule | Why |
|---|---|
| **Verbatim titles** — new records keep the user's exact words and script; never translate or paraphrase. Matching actions return the existing record's title. | Claude rewrote Burmese into tidy English; the user couldn't recognize his own todos. |
| **Tense decides the action** — future (`ဝင်မယ်`, `ပေးရမယ်`) → `add_receivable`/`add_payable`; completed (`ဝင်ပြီ`, `ပေးပြီးပြီ`) → `income_received`/`mark_paid`. | `income_received` settles on apply, so expected income was created already-paid. |
| **Times** — a clock time ≥ the current minute is **today**; only strictly-earlier rolls to tomorrow. Relative times ("in 20 mins") computed from the injected current time. | "at 3:30pm" sent at 3:29pm was silently scheduled for the next day. |
| **Never invent dates** — no date in the text = `due: null`. | A guessed due date on money is worse than none. |
| **`show_day`** — a question about a date ("give me todos for july 5", "မနက်ဖြန် ဘာရှိလဲ") returns that day; writes nothing. | Questions were being turned into todos. |

### Batch mode (multi-line messages + brain dumps)

`ClaudeParser::parseMany()` — one call for the whole text, `max_tokens` **16000** (Burmese is token-dense; 4000 truncated mid-JSON and *silently* fell back to line-by-line, masking every other batch fix). Returns a JSON array with a `raw` field per item. Extra rules:

- **One object per ITEM, not per line.** Connectors (`ပီးတော့`, `ပီးရင်`, "and", "then") split into separate items — never dropped or merged.
- **Date headers** (`မနက်ဖြန်`, `July 6 2026 Monday`) are not items; they stamp their date on every line below until the next header.
- **Section headers** type every line below them: `ပေးစရာငွေ`/expenses → `add_payable`, `Income` → `add_receivable`, `work todos` → `add_todo` + bucket, `X အတွက်`/care → `add_care_task`, `Work idea` → `add_idea`. Bare "Name - amount" lines in a list are new records, never `mark_paid`.
- **Date references** (`အဲ့နေ့`, "that day") resolve to the most recently mentioned date.
- Falls back to line-by-line parsing if the batch call fails.

### Self-learning

Correcting a parse in the confirm chip (or Import review) posts `corrected: true`, which appends the pair to `parser_examples`. The 10 most recent are injected into every prompt. Verified live: a phrase that returned `unknown` parsed at 0.9 confidence after one correction.

---

## 5. Telegram

**Every user brings their own bot** (BotFather → Settings → Telegram, §11). Transport depends on
the environment, and both paths share one `InboxBridge::handle($message, $user)`:

- **Production → webhooks.** `POST /telegram/webhook/{secret}`. The URL secret identifies the
  account; the `X-Telegram-Bot-Api-Secret-Token` header (which Telegram echoes from `setWebhook`)
  authenticates the request. The URL alone is an identifier, not a key — it travels through logs
  and proxies. Always returns 200, or Telegram retry-storms. CSRF-exempt in `bootstrap/app.php`
  (Telegram has no session); note **tests cannot cover that exemption** — Laravel skips CSRF when
  running tests, so verify with a real POST against a served host.
- **Local dev → `php artisan telegram:listen`.** `lifeos.test` has no public HTTPS for Telegram to
  reach. `getUpdates` is per-bot, so the listener walks each connected bot per cycle: one bot gets
  a real 50s long poll, several take turns at 2s each.
- `config('lifeos.telegram.webhook_enabled')` picks the path, defaulting to whether `APP_URL` is
  https. The `.env` `TELEGRAM_BOT_TOKEN`/`TELEGRAM_CHAT_ID` are **legacy** — read only by the
  2026_07_17 migration, which moved the original single bot onto user 1.

- Messages from a chat other than the bot's linked `telegram_chat_id` are ignored.
- **Any sentence** → parse → apply → reply (no confirm chip in chat; confident parses apply directly). Replies are line-broken and show 📅 date (always for money, incl. "no date") and ⏰ time so a wrong parse is caught at entry.
- **Multi-line messages** route through batch mode (§4) and report per item (✅ applied / 🤔 skipped / ⚠️ failed).
- **Commands** work bare or slashed, case-insensitive, exact single word only (so "today buy milk" is still a todo):
  `today`/`tdy` · `tomorrow`/`tmr` · `yesterday` · `todobydate` (asks for a date; understands "July 6", "2026-07-06", "6.7", Burmese digits) · `care` · `idea`/`ideas` · `undo` · `start`/`help`
- Natural-language date questions work too, via the `show_day` action.

### Duplicate-reply protection (two layers)

Duplicates came from **two `telegram:listen` processes** polling concurrently — long-poll hands the same update to both before either advances the offset.

1. **Per-`update_id` idempotency** — `Cache::add('telegram:seen:{user}:{id}')` is atomic; only the first caller processes an update. Keyed by user: `update_id` is unique only *within a bot*, so two bots legitimately both send #7. The webhook reuses this against Telegram's own retries.
2. **Single-instance guard** — heartbeat key `telegram:listen:owner` (120s TTL, refreshed each poll); a second long-running listener exits immediately. Released on clean exit so a supervisor restart reclaims at once. `--once` skips the guard so cron/tests compose.

Both need an **atomic cache driver** (`database`/redis — not `file`/`array`). Still run exactly one listener (supervisor `numprocs=1`).

### Scheduler (`schedule:work` locally, cron on prod)

```
care:run      everyMinute   fire due care tasks → Telegram → log → reschedule
todos:remind  everyMinute   ⏰ ping when a timed todo reaches its due time (reminded_at guards repeats)
digest:send   dailyAt 07:00 the catch-up screen, pushed
```

`care:run` and `todos:remind` still query across everyone — each record carries its owner, so the
notification follows `$task->user` to the right bot. `digest:send` loops users with a linked chat.

Random care tasks reschedule to `now + rand(min_days, max_days)` on every fire — the unpredictability is the feature.

**Digest content:** care today · overdue (todos grouped Work/Personal/Money, capped 10) · due today · **Open (no date)** · expense/income totals + lines (capped 8). Future-dated money stays out of `/today` and appears in that day's view instead.

---

## 6. UI

- **Theme:** dark purple primary + pink secondary, `bg-gradient-brand` / `text-gradient-brand` utilities, gradient titles/CTAs/nav pill, form open/close transitions. Light + dark.
- **Home = glance dashboard:** greeting + date · magic box · 🎯 **Focus** · ⚡ **Next up** (the next actionable todo) · 🔴 **Overdue** (capped 5 + "+N more →") · 📌 **Today** (todos + care, inline ✓, 🎯) · 💵 **Money strip** (net + due this week + overdue badge) · 🌙 **Tomorrow** peek · 💡 weekly rotating parked idea · all-clear state. Everything taps through; Home is a router, not a destination.
- **Todos:** month calendar (per-day count badges, ✓ when all done, today in gradient) → **day timeline** with a left time gutter (timed sorted → Anytime → Done) → **detail page** (TipTap rich text, explicit Update button, green/blue/red outline actions).
- **Focus mode:** single focus enforced server-side; pin from any day row, Home row, or the detail page. Completing or deleting clears it.
- **Money:** balance tiles (incoming / to pay / net, color-coded) + **urgency groups** (Overdue / This week / Later / No date / Settled). Settled capped at 15 with "Show all N".
- **Care:** full CRUD, schedule form incl. the random surprise window, pause/resume.
- Dates/times use **VueDatePicker v14** via a shared `DateTimeField` (note: v14 renamed `.dp--theme-*` and moved time props into `time-config` — v11 docs are wrong).
- Amounts always display as plain digits (`500,000 Ks`); spoken units (`5 သိန်း`) are input-only. Money wording is **Expense / Income** (internals stay `payable`/`receivable`).
- Burmese: Noto Sans Myanmar in the font stack.

---

## 7. Definition of done — met

Open the app after 3 days away → one screen tells you: expense, income, what's overdue, what Kaly lay needs today. Type "paid gon khaung 500k" → confirmed and applied in seconds. Zero forms touched. Plus: the same works over Telegram from anywhere.

---

## 8. Original four-day plan (history)

**Day 1** Skeleton + PWA · **Day 2** Data + magic inbox · **Day 3** Recurrence + Telegram · **Day 4** Catch-up screen + polish.
Delivered on schedule (Days 1–3 finished a day early); Day 4 expanded into the post-V1 work below.

---

## 9. Post-V1 status & backlog

### Shipped after V1
Todo calendar + day timeline · todo detail page + rich-text notes · focus mode · timed todo reminders · `show_day` queries · `/care` `/idea` `/tomorrow` `/yesterday` `/todobydate` commands · bare-word commands · purple/pink redesign · profile/logout · scalability pass (capped lists, indexes) · Telegram duplicate fix · **multi-user data scoping + per-user Telegram bots with guided setup** (§11).

### Operational / deploy
- Production at `/var/www/LifeOS`. **Any deploy touching `routes/`, `config/`, or `.env` must rebuild caches** — a stale `bootstrap/cache/routes-v7.php` makes new routes 404 (this bit us on the focus route):
  ```bash
  git pull && composer install --no-dev -o && php artisan migrate --force
  npm ci && npm run build
  php artisan optimize:clear && php artisan optimize
  php artisan queue:restart && sudo supervisorctl restart <listener>
  ```
- Confirm on prod: `APP_TIMEZONE=Asia/Yangon`, `CACHE_STORE=database`, exactly one `telegram:listen`.
- Local: `php artisan schedule:work` + `php artisan telegram:listen` must be running. Restart the listener after any parser/bridge change (it holds old code in memory). Use `lifeos.test` (Herd), not `artisan serve` — that's single-threaded and blocks during a dump.

### Open items
- **Registration is open to anyone with the URL** and parses bill the app owner's single
  `ANTHROPIC_API_KEY`. Deliberate for now; the cheap mitigations are a throttle on `/inbox/parse`
  + `/onboard/dump`, or an invite gate.
- Re-import real brain dump (data lost in the MySQL switch); set real care schedules.
- Change seeded password (`lifeos-2026`); PWA install on phone.

### Ideas parked
Funding links (an expense knows which incomes pay for it) · per-person money page · **care done-tracking + streaks** (`care_task_logs` exists, unused — best next feature) · Telegram inline buttons ([✓ Done] [Skip]) · voice input (Claude can't take audio; needs Whisper/Google STT `my-MM` → existing parser) · web push · auto-detect care schedules from dump text (`အမြဲ`→daily, `တစ်ပတ်ခါ`→weekly, `ကြိုမပြောပဲ`→random) · queue the brain-dump parse · restyle starter-kit settings pages.

---

## 10. Testing

148 tests (`php artisan test`). `INBOX_PARSER=fake` is pinned in `phpunit.xml` so tests never hit the paid API; `TodoReminderTest` freezes the clock at noon to avoid midnight flakes. Frontend changes: run the suite + `npm run build` — **the user tests the UI himself**, don't launch a preview browser.

**Tests run on SQLite `:memory:`; dev and prod are MySQL.** Migrations must work on both — SQLite
cannot `ALTER TABLE ADD CONSTRAINT`, so a foreign key has to be declared when the column is added,
not bolted on afterwards. MySQL auto-creates an index for a FK; SQLite does not (harmless, since
only tests run there).

Domain factories default `user_id` to a **fresh** `User::factory()`, so a test that forgets
`->for($user)` fails loudly rather than silently reading someone else's data.
`User::factory()->withTelegram()` gives an account that has finished the setup wizard.

---

## 11. Multi-user + per-user Telegram bots (July 17)

### Why

The starter kit shipped `Features::registration()` enabled, but **no domain table had a `user_id`**
and every query was global (`Todo::open()`). Three accounts had signed up. They could all read and
edit each other's money. Route-model binding (`show(Todo $todo)`) resolved any id for anyone.

So "give each user their own bot token" could not ship alone: a second user's bot would have written
into the first user's ledger. Scoping the data was the prerequisite, not a follow-up.

### How it works

- **`BelongsToUser`** on the seven domain models: `user()` + `scopeForUser()`. Scoping is
  **explicit, not a global scope** — the scheduler, the webhook and the listener all run with no
  authenticated user, and a scope keyed on `Auth::id()` would silently return nothing there.
- Controllers read through the relation (`$request->user()->todos()`) and resolve ids with
  `findOrFail` on it. Services take a `User`: `parse($text, $user)`, `apply($parsed, $raw, $user)`,
  `build($user)`, `handle($message, $user)`.
- **One bot per account.** The token is `encrypted` at rest and in the model's `Hidden` list — it is
  a bearer credential, so it must never ride along in an Inertia prop. A token already registered to
  another account is refused (two accounts on one bot would answer each other's messages).
- **Setup order is forced by Telegram:** `getUpdates` and a webhook are mutually exclusive, so the
  wizard must read `chat_id` *before* calling `setWebhook` — and calls `deleteWebhook` first so
  re-running setup on a connected bot still works.

### Landmines

- **The wizard's steps are verified against Telegram, not trusted.** `getMe` proves the token before
  it is stored, so a typo fails in the UI instead of producing a bot that silently never answers.
- **Focus was global.** `Todo::where('focused', true)->update(...)` cleared *everyone's* pinned todo.
  Single-focus is per person.
- **The `awaiting_date` cache key was global**, so two people mid-`/todobydate` would have answered
  each other's question. Keyed per user now.
- **Deploy must rebuild caches** (§9) — new routes plus a changed `bootstrap/app.php`. A stale
  `bootstrap/cache/routes-v7.php` 404s the webhook exactly like it did the focus route.

### Prod cutover

Webhooks are *push*: once registered, Telegram POSTs to `/telegram/webhook/{secret}` and your web
server handles it like any request. There is **no delivery process** — `telegram:listen` (a
long-running poller) is removed entirely on prod, and it is **not** replaced by a cron.
`telegram:webhook-sync` is a one-time registration step, not a scheduled job. The scheduler cron
(`care:run`, `todos:remind`, `digest:send`) is unchanged.

1. **Check the owner first.** The migration backfills every existing row to the *lowest-id* account
   (`DB::table('users')->orderBy('id')->value('id')`). Registration is open, so confirm that account
   is actually the intended owner — `select id, email, created_at from users order by id limit 1` —
   before migrating. If it is not, the data attaches to the wrong person.
2. Back up the prod DB.
3. Deploy → `php artisan migrate --force` (data auto-assigns; moves the `.env` bot onto that account
   so the live bot survives) → `php artisan optimize:clear && php artisan optimize`.
4. `php artisan telegram:webhook-sync` — registers the webhook for every connected bot (idempotent;
   also the standing fix whenever a webhook breaks or the domain changes).
5. Only now **remove `telegram:listen` from supervisor** — delivery is the webhook from here on.

The connected card at Settings → Telegram shows each bot's real webhook state (from `getWebhookInfo`)
with a Register/Re-register button, so "Connected" can no longer claim a dead bot works.

---

## 12. PWA Web Push notifications (July 17)

Every proactive Telegram push — 7 AM digest, timed todo reminders, care-task fires — now **also**
sends a Web Push notification from Life OS itself, so the user is alerted in the app even when it is
backgrounded or closed. Both channels fire; the push does not replace Telegram.

**Deliberately no custom sound.** Custom notification sounds work only while a page is focused and are
impossible on background web push (iOS ignores them; Android dropped support). Using the OS default
sound makes this plain Web Push that works in every app state, which is what the user chose after we
walked through the trade-off.

### How it works
- **`laravel-notification-channels/webpush`** (`User` is `Notifiable` + `HasPushSubscriptions`).
  Provides the `push_subscriptions` table, VAPID handling, and 410-Gone cleanup.
- **`App\Notifications\BotPush`** — `webpush` channel only — is fired via `$user->notify(...)` right
  after the `->send()` in `RunTodoReminders`, `RunCareTasks`, `SendMorningDigest`. No-ops when the
  user has no subscription, so it is safe for everyone.
- **`public/sw.js`** gained `push` (→ `showNotification`, OS sound, every state) and `notificationclick`
  (→ focus/open the app) handlers. Still installability + passthrough otherwise.
- **`PushSubscriptionController`** (`POST`/`DELETE /push/subscribe`) stores/removes a browser's
  subscription, scoped through `$request->user()`. The VAPID **public** key is shared to the client
  via `HandleInertiaRequests` (`vapidPublicKey`); the private key never leaves the server.
- **Client:** `resources/js/lib/pushNotifications.ts` (`enablePush`/`disablePush`, permission +
  subscribe + POST). Settings → Notifications (`os/Notifications.vue`, mobile shell, Profile menu) is
  a single enable/disable toggle with an **iOS "install to Home Screen first"** hint. A dismissible
  Home nudge (`showNotificationPrompt`) mirrors the Telegram one, shown until a device subscribes or
  the user taps Not now.

### Landmines
- **iOS:** Web Push works only for an **installed** PWA (Add to Home Screen, iOS 16.4+); the prompt is
  a no-op in a plain Safari tab. The settings page shows the install hint.
- **VAPID keys are per-environment and must stay stable** — regenerating invalidates every existing
  subscription. Deploy sets `VAPID_SUBJECT`/`VAPID_PUBLIC_KEY`/`VAPID_PRIVATE_KEY`; run
  `php artisan webpush:vapid` once on prod and keep them. Web Push needs HTTPS (prod/localhost qualify).
- **The server needs the `gmp` (or `bcmath`) PHP extension.** Without it, `minishlink/web-push`
  emits an E_USER_NOTICE on client construction; in production Laravel turns that into a thrown
  `ErrorException`, so every push fails — silently, because the crons catch it (Telegram still sends,
  reminders don't repeat, no push). `tinker` masks it (Psy shows the notice as a warning, not a
  throw), so it only bites the scheduled path. Fix: `apt-get install php8.4-gmp && systemctl restart
  php8.4-fpm`; verify `php -m | grep gmp`. This bit prod on 2026-07-18.
- **A running Vite dev server makes Inertia SSR issue an HTTP request** to `:5173/__inertia_ssr` during
  a full page render. A bare `Http::assertNothingSent()` in a test that renders an Inertia page will
  catch it — assert about the specific host (`api.telegram.org`) instead.
