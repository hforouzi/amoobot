# Admin activity group and daily report — design

Status: **design, not implemented.** Persian version: [docs/fa/design/activity-group-and-daily-report.md](../fa/design/activity-group-and-daily-report.md).
Companion design (customer guide): [help-guide.md](help-guide.md).

Items marked **DECISION NEEDED (Ax)** are decisions this document takes with a recommendation; the owner confirms or
changes them before the first PR. Everything else is decided by this document. All new behaviour is behind
`activity.enabled`, **default off**.

Goal: the admin adds the bot to a Telegram group (a plain group, or a supergroup with forum topics) and gets

- a **live activity feed**: payments, new customers and services, renewals, claims, reseller sales, alerts;
- a **daily report** at a configurable local time: money in, sales, wallet, refunds, failures, what expires soon.

Hard rules (from the brief, kept everywhere below):

1. Nothing of this may slow down or fail a sale, a payment, a provisioning or a delivery.
2. No service link, subscription URL or token, `/s/` page token, claim link or token, config link, credential, card
   number or full Telegram id ever reaches the group.
3. Bounded: a dead group, a burst or a bug never grows a table without limit.
4. Off by default, fail closed.

## 0. What exists today and is reused

Everything below was read for this design (paths are the source of truth).

| Area | Today | Used for |
|---|---|---|
| Admin Telegram alerts | `AdminPaymentAlerter` (`src/Payment/Application/AdminPaymentAlerter.php`): needs review, provisioning failed, stuck reminder, delivery failed (M2e), overpaid, wallet top-up credit failed, settlement interrupted. "Sent after the settlement transaction has committed …; failures are logged, never thrown." `CustomerAccessGuard` (unusable access link, once per service via `service_notification_log`), `InboundAttachAlerts` (multi-inbound attach failures), `ClaimNotifier` (invalid claim attempts, reset needs attention), `BroadcastStopAlert`, `PaymentReceiptAdminNotifier` (card receipts, with approve/reject buttons) | They keep sending their **private** DMs unchanged. Each one additionally records an activity event (section 2.2). |
| Recipients | `AdminAccessPolicy::telegramRecipients(AdminPermission)` (`src/Security/AdminAccessPolicy.php`): `TELEGRAM_ADMIN_CHAT_ID` plus every active linked admin with the permission | Unchanged. The group is an extra destination, never a replacement (a group cannot be permission-checked). |
| Telegram client | `TelegramApiClient` (`src/Bot/Infrastructure/TelegramApiClient.php`): `sendMessageStrict()` / `sendHtmlMessageStrict()` throw `TelegramApiRejected` when Telegram answered `ok=false`, another exception when the outcome is unknown; `getChatMember()` exists. `callApi()` keeps only `description`: **no `error_code`, no `parameters.retry_after`, no `parameters.migrate_to_chat_id`**. No `message_thread_id` parameter on any send method. Every send is written to `bot_message_log` by `BotMessageLogger`, which calls `EntityManager::flush()`. | Extended (section 3.6): error code and parameters on `TelegramApiRejected`, an optional thread id, a send that is not written to `bot_message_log`. |
| Update handling | `TelegramUpdateHandler::handle()` (`src/Bot/Application/TelegramUpdateHandler.php`) handles `message` and `callback_query` **without looking at the chat type**; `handleMessage()` resolves (and creates) a `User` for any sender via `TelegramUserResolver` and answers in the same chat. | **Prerequisite fix** (section 4.4): once the bot is in a group, every member who writes `/start@bot` would get a customer account and the bot's answer would be posted to the group, and an admin's admin menu would be posted where non-admins read it. |
| Broadcasts | `BroadcastSender` (`src/Broadcast/BroadcastSender.php`): delivery rows per chat, `usleep(50000)` between sends, stops itself on repeated parse errors; job `broadcasts` | Pattern for a durable per-message row and a stop-on-systematic-error rule. Not reused as such (different audience, different lifecycle). |
| Dedupe ledger | `service_notification_log` (`migrations/Version20260511120000.php`; unique `(service_id, type, key_name)`), used by `PaymentDeliveryLog` and `CustomerAccessGuard` with `INSERT IGNORE` as an atomic "first one wins" | Same technique (`INSERT IGNORE` on a unique key) for the outbox. Not the same table: its rows need a `service_id` and a `user_id`, and activity events (a new user, a daily report, a panel alert) have neither. |
| Scheduled jobs | `SCHEDULED_JOBS` in `deploy.sh` (name, lock group, `OnCalendar`, timeout, interval, command; "Only `app:automation:run` tasks: they honour the admin toggles"). `bin/check-scheduled-jobs.sh` (`amoobot-check-jobs`): max age = 3 × interval + timeout; `NEW` for a just-installed job; the hourly check timer starts 1 h after a deploy "so every job (longest interval 30 min) had a chance". `tests/ops/scheduled-jobs.test.sh` parses the table. | One new job `activity` (section 7). |
| Automation command | `AutomationRunCommand` (`src/Command/AutomationRunCommand.php`): `--only=…`, `--dry-run`, `--limit`; each task in its own `try`, a failure counted and logged, the entity manager reset between tasks (`TransactionRollback`) | New `--only=activity` with four isolated tasks. |
| Settings | `Setting` key/value; `SettingValueProvider` (one query per `get()`, no cache); `SettingsAdministration` (`*_KEYS` constant per tab, `xxxValues()` / `xxxProblem()` / `saveXxx()`, the caller audits); `SettingsAdminController` (tabs from `AdminTabs::SETTINGS`); `AdminAuditLogger` | New tab `activity` with `ACTIVITY_KEYS`. |
| Redaction | `SecretRedactor` (`src/Provisioning/Infrastructure/Sanaei3xui/Modern/Http/SecretRedactor.php`): `forLog()` (Telegram ids), `redactAccess()` / `maskAccess()` (config links, URL paths and queries, `/s/` tokens, UUIDs, sub ids, passwords, client emails), `redact()`; `ClaimToken::redact()`; `SafeErrorLog`; `RedactingLogger` (decorates `logger`) | Every free text that reaches the group or the outbox goes through `redactAccess(ClaimToken::redact(…))` (section 5.3), on top of the allow-list. |
| Admin permissions | `AdminPermission` enum + `AdminRolePermissions`: `SETTINGS_MANAGE` and `LOGS_VIEW` are super-admin only (`ROLE_ADMIN` excludes them) | Configuring the group needs `SETTINGS_MANAGE` (section 6.3). |
| Money | All shop amounts are integer Toman (`docs/en/payments/amount-units.md`); `payment.amount` int, `payment.payable_amount` int; crypto `verified_amount` / `verified_currency` are decimal strings; `Decimal` (`src/Payment/Domain/Money/Decimal.php`): `normalize`, `compare`, `subtract`, `multiply`, `floorShare`, `divideByPowerOfTen` — **no `add`**. Wallet ledger `wallet_transaction` (append-only, signed BIGINT, unique `idempotency_key`, types topup / purchase / refund / adjustment / reward / reversal). | The report sums integers in SQL and decimal strings with a new `Decimal::add()` (section 6.4). |
| Revenue definition | `DashboardOverview::today()` (`src/Admin/UI/Dashboard/DashboardOverview.php`): confirmed payments since midnight; method `wallet` excluded (already counted as its top-up); a top-up counts its ledger credit `topup:payment:{id}`, not the ordered amount | Extracted into one query class used by both, so the dashboard and the report can never disagree (section 6.4). |
| Reseller figures | `ResellerReport::summary($from, $to)` (`src/Reseller/Application/ResellerReport.php`): per reseller orders, wholesale, retail value, refunds | Reused as is for the report's reseller section. |
| Time | `Kernel::TIMEZONE = 'UTC'` (set on every boot; deploy pins `date.timezone = UTC`). `DashboardOverview` and `ResellerCheckout` use UTC midnight as "today". | The report is the first feature with a local day (section 6.2). |
| Indexes | `payment`: `idx_payment_status (status)` only (no index on `confirmed_at`); `user`, `vpn_service`, `wallet_transaction`: no index on `created_at`; `order`: `idx_order_reseller (reseller_id, status, created_at)` | New online indexes (section 7.2). Migrations use `ALGORITHM=INPLACE, LOCK=NONE` on MySQL platforms (`migrations/Version20261024090100.php`). |
| Concurrency tests | `tests/Support/ParallelStart.php`, helper scripts in `tests/Support/bin/` (e.g. `settle-payment.php`, `delivery.php`), MariaDB 10.11 | Same harness for the outbox tests (section 8). |

Facts that shape the design:

1. **The bot does not ignore group chats today.** Adding it to a group without a fix exposes admin answers to group
   members and creates customer accounts for them. The fix ships first and is useful even if this feature is never
   switched on.
2. **`TelegramApiRejected` cannot tell a flood limit from a kicked bot.** Retry, back-off and chat migration need the
   error code and parameters.
3. **Sending through `TelegramApiClient` flushes the entity manager** (`BotMessageLogger::log()`); a worker that holds
   managed entities would flush them as a side effect. The activity sender works with DBAL rows only and does not
   write to `bot_message_log` (the outbox row is the record).
4. Nothing purges `bot_message_log` or `service_notification_log` today. This feature does not make that worse
   (fact 3) and brings its own purge.

## 1. Events

### 1.1 Catalogue

Categories group events for topics and for the switches. "Default" is the per-category default once the feature
itself is switched on (the master switch stays off).

| Category | Event | Source (section 2) | Default |
|---|---|---|---|
| `payments` | Payment confirmed (new service, renewal, add traffic, wallet top-up; gateway or admin approval) | scan `payment` | on |
| `payments` | Payment rejected / gateway failure | scan `payment` | **off** (noisy: abandoned gateway pages) |
| `payments` | Needs review; overpaid | `AdminPaymentAlerter` | on |
| `sales` | New service created (incl. trial) | scan `vpn_service` | on |
| `sales` | Renewal / add traffic applied | covered by "payment confirmed" (order type shown) | — |
| `customers` | New customer (first contact with the bot or the Mini App) | scan `user` | on |
| `resellers` | Reseller sale (order with `reseller_id`, wallet payment confirmed) | scan `payment` (same row, tagged) | on |
| `resellers` | Service claimed / returned | scan `service_ownership_event` | on |
| `wallet` | Manual wallet adjustment by an admin | scan `wallet_transaction` (type `adjustment`) | on |
| `alerts` | Provisioning failed, delivery failed, settlement interrupted, wallet credit failed, stuck reminder | `AdminPaymentAlerter` | on |
| `alerts` | Unusable access link (panel), multi-inbound attach failure, claim abuse / reset needs attention, broadcast stopped | `CustomerAccessGuard`, `InboundAttachAlerts`, `ClaimNotifier`, `BroadcastStopAlert` | on |
| `report` | Daily report | `activity` job (section 6) | on |
| `system` | Feed paused / resumed, events skipped, chat migrated, topic missing | the sender itself | always on (cannot be switched off while the feed runs) |

Not included (and why): card receipt submissions (`PaymentReceiptAdminNotifier` sends the receipt photo and action
buttons; a photo of a bank receipt with card digits must not go to a group, and "needs review" already covers the
decision); every bot message; expiry warnings to customers (volume; the daily report has "expiring soon").

**DECISION NEEDED (A1).** Confirm the catalogue and the defaults above, in particular *failed payments off* and
*receipt submissions excluded*.

### 1.2 Telegram DMs stay

The existing private alerts are not moved into the group. A group is shared, cannot be permission-checked and may be
muted; a payment that waits for a decision must still reach the people who can decide it. The group is a feed for
awareness. (A later switch "send alerts only to the group" is possible but not proposed.)

## 2. How events are captured

### 2.1 Alternatives

| | How | Never slows/fails a sale | Survives a crash after commit | Code touched in hot paths |
|---|---|---|---|---|
| A. Inline send | Send to Telegram where the event happens | **No** (HTTP call, up to the client timeout, sometimes inside a lock) | no | many |
| B. Transactional outbox | `INSERT` an outbox row in the same transaction as the business change | Mostly, but an outbox problem (missing table after a half deploy, lock wait) fails or delays the settlement itself | yes | settlement, wallet, claims |
| C. After-commit record | After the commit, one `INSERT IGNORE` in `try/catch`, errors logged and swallowed (the `AdminPaymentAlerter` pattern) | yes (one indexed insert, cannot throw) | **no**: a crash between commit and record loses the event | every emitter |
| D. Scan source tables | The job reads what happened from the business tables (`payment`, `user`, `vpn_service`, `service_ownership_event`, `wallet_transaction`) since its last run | yes: **zero** code in the sale path | yes | none |

### 2.2 Decision: D for facts, C for alerts

- **Facts** (things stored in a business table) are **scanned** by the job (D). The settlement, wallet, checkout and
  claim code are not touched at all. Latency is the job interval (2 minutes, section 7).
- **Alerts** that exist only as a message (provisioning failed, delivery failed, panel alerts, claim abuse) are
  **recorded after commit** (C) by the alerter classes that already send the DM, at the same place, with the same
  "never throw" rule. A lost alert event after a crash is acceptable: the DM is the primary channel, the event is a copy.

Alternative B was rejected because it adds a write (and a failure mode) to the settlement transaction for a feature
that is informational. Alternative A was rejected by the brief.

**DECISION NEEDED (A2).** Confirm D + C. (If the owner prefers one mechanism, C everywhere is simpler to read but
touches settlement, wallet and claim code and loses events on a crash.)

### 2.3 The scanner ("collect" task)

For each source a **cursor** is kept in `activity_state` (section 3.3): the last scanned timestamp. Each run reads
rows whose time column is in `[cursor − overlap, now − settle_delay]`, inserts one outbox row per event with
`INSERT IGNORE` on a unique `dedupe_key`, then moves the cursor to `now − settle_delay`.

- `overlap` (default 10 minutes) covers rows that were committed later than their timestamp says (a long
  settlement writes `confirmed_at` before it commits). Re-reading the overlap is free: the unique key drops the
  duplicates. A transaction longer than the overlap can be missed by the feed; the daily report is computed from the
  tables and never misses it.
- `settle_delay` (default 15 s) keeps a scan from reading a second that is still being written.
- Batch limit per source per run (default 500); when hit, the cursor moves only to the last row read, so a backlog is
  read over several runs, never skipped.
- **First enable:** cursors start at "now". Switching the feature on never floods the group with history.
- While the feature is off, the task does nothing (no cursor moves). Switching it on again restarts from "now" and
  posts one `system` line: "Feed resumed; events between X and Y were not posted."

| Source | Rows | Time column | Dedupe key |
|---|---|---|---|
| Payment confirmed | `payment.status = 'confirmed'` | `confirmed_at` | `payment:{id}:confirmed` |
| Payment failed | `payment.status = 'rejected'` | `failed_at` (set by `PaymentSettlementService::recordGatewayFailure()` and some gateways; whether `reject()` sets it is checked in A-3, see Q7) | `payment:{id}:failed` |
| New customer | `user` | `created_at` | `user:{id}:created` |
| New service | `vpn_service` | `created_at` | `service:{id}:created` |
| Claim / return | `service_ownership_event` | `created_at` | `ownership:{id}` |
| Wallet adjustment | `wallet_transaction.type = 'adjustment'` | `created_at` | `wallet_tx:{id}` |

A reseller sale is the "payment confirmed" row of an order with `reseller_id`; it is posted in the `resellers`
category instead of `payments` (one message, not two).

### 2.4 After-commit recording ("record" API)

One service, `ActivityRecorder::record(string $category, string $type, string $dedupeKey, array $fields)`:

- returns immediately when `activity.enabled` is off or the category is off (one cached settings read per request);
- validates `$fields` against the allow-list of its type (section 5.1) — unknown fields are dropped, not stored;
- one `INSERT IGNORE INTO activity_event …` through DBAL, inside `try/catch (\Throwable)`; an error is logged through
  the `logger` (redacted) and swallowed;
- never called inside a transaction: each call site is after the commit (every `AdminPaymentAlerter` method already
  is; `CustomerAccessGuard::alertOnce()` and `InboundAttachAlerts` run outside the settlement transaction — to be
  asserted by a test per call site, section 8).

## 3. Delivery

### 3.1 Outbox table `activity_event`

| Column | Type | Rule |
|---|---|---|
| `id` | BIGINT PK | order of posting |
| `dedupe_key` | varchar(128), **UNIQUE** | e.g. `payment:123:confirmed`; `INSERT IGNORE` makes every producer idempotent |
| `category` | varchar(16) | section 1.1 |
| `type` | varchar(32) | e.g. `payment_confirmed` |
| `occurred_at` | datetime (UTC) | when it happened (shown in the message, local time) |
| `fields` | JSON | **allow-listed, already redacted** values (ids, amounts, labels); never message text |
| `status` | varchar(12) | `pending` · `sending` · `sent` · `failed` · `dropped` · `coalesced` |
| `attempts` | smallint | |
| `next_attempt_at` | datetime | back-off |
| `claim_token` | char(32) NULL | which run holds it |
| `claimed_until` | datetime NULL | lease; an expired lease is free again |
| `sent_at` | datetime NULL | |
| `telegram_message_id` | int NULL | for a later edit, not used in v1 |
| `last_error` | varchar(255) NULL | `SecretRedactor::redact()`-ed, short |
| `created_at` | datetime | purge |

Indexes: unique `dedupe_key`; `(status, next_attempt_at, id)` for the sender; `(created_at)` for the purge.

The message text is rendered at send time from `fields` and the current settings (language, detail level), so a
settings change applies to queued events and no rendered text (with a possible mistake) is ever stored.

### 3.2 Sender ("send" task)

1. **Claim** a batch without a long lock, with one statement MariaDB 10.11 supports:
   `UPDATE activity_event SET status='sending', claim_token=?, claimed_until=NOW()+INTERVAL 5 MINUTE
    WHERE (status='pending' AND next_attempt_at <= NOW()) OR (status='sending' AND claimed_until < NOW())
    ORDER BY id LIMIT ?`, then `SELECT … WHERE claim_token = ?`. Two workers can never claim the same row (the
   `UPDATE` takes row locks; the second sees `status='sending'`). The job's lock group already keeps runs from
   overlapping; the claim makes a manual `app:automation:run --only=activity` next to the timer safe too.
2. **Send outside any transaction**, oldest first, through the extended client (section 3.6).
3. **Mark** each row with a conditional `UPDATE … WHERE id=? AND claim_token=?` (a row whose lease expired and was
   claimed again by another run is not touched by the first one).

Ordering is best-effort FIFO: a row that is retried does not block later rows. Every message carries the event time,
so a late message is still readable.

### 3.3 State table `activity_state`

`name varchar(64) PK, value varchar(255), updated_at` — scanner cursors, the channel state (`ok`, `paused_until`,
`unreachable`, `topic_missing:<category>`), the skipped-events counter, last success, last error, last report date.
Separate from `setting` on purpose: `setting` rows are admin-editable through the generic Setting CRUD and audited as
admin changes; these are machine state.

### 3.4 Rate limits, bursts, retries

- Telegram allows about 20 messages per minute into one group. The sender posts at most
  `activity.max_messages_per_run` (default 12) per run, ≥ 1.1 s apart; with a run every 2 minutes that is ≤ 6 per
  minute, far below the limit and leaving room for the DMs the bot sends anyway.
- **Burst (coalescing):** when more than `max_messages_per_run` rows of one category are pending, the oldest rows of
  that category are folded into **one digest message** ("14 payments confirmed between 10:02 and 10:09, 3,450,000
  Toman in total; largest 600,000") and marked `coalesced`. Money in a digest is the exact integer sum of the folded
  rows. The group always gets a readable summary, never a backlog that takes hours.
- **429 `Too Many Requests`:** stop the run, store `paused_until = now + retry_after` (from
  `parameters.retry_after`), leave the rows `pending`. No retry inside the run.
- **Transport error / 5xx / unknown outcome:** `attempts + 1`, `next_attempt_at = now + backoff(attempts)` (1, 2, 5,
  15, 60 min). After 6 attempts or 24 h the row is `failed` (not sent again).
- **Unknown outcome = possible duplicate.** A timeout after Telegram accepted the message would post it twice when
  retried. For an informational feed a rare duplicate is better than a silent gap.
  **DECISION NEEDED (A3)**: at-least-once (recommended) or at-most-once (an unknown outcome is marked `failed`).
- **Systematic errors** (400 "can't parse entities" etc.): three in a row stop the run and set `last_error`; the
  admin sees it on the status panel. (Text is plain or escaped HTML generated by code, so this means a bug.)

### 3.5 Group unreachable, chat migrated, topic deleted, missing rights

| Telegram answer (code + description) | Meaning | Behaviour |
|---|---|---|
| 403 `bot was kicked` / `bot is not a member` / 400 `chat not found` | removed, or the group was deleted | Channel `unreachable`; sending stops; the scanner stops enqueuing (cursors still move, so nothing piles up); one DM to the `SETTINGS_MANAGE` recipients ("activity group unreachable; feed paused"), once per state change. Pending rows expire by the 24 h rule. "Test message" in settings that succeeds sets the channel back to `ok` and posts a `system` line with the gap. |
| 400 with `parameters.migrate_to_chat_id` | the group became a supergroup (new chat id) | The stored chat id is replaced with a conditional update (`WHERE value = old id`), the same message is retried at once to the new id, a `system` line is posted, and the change is written to the application log (redacted) and shown on the status panel. Telegram is the authority for this mapping; no admin action needed. |
| 400 `message thread not found` / `TOPIC_DELETED` / `TOPIC_CLOSED` | the category's topic is gone or closed | The message is sent to the group's General topic (no thread id) with a `⚠️ topic missing` prefix; `topic_missing:<category>` is set; the status panel shows it; the admin re-creates or re-maps the topic. |
| 400 `not enough rights to send text messages` / 403 `CHAT_WRITE_FORBIDDEN` | the bot may not post | as `unreachable` |
| 429 | flood limit | section 3.4 |

**Bounded growth, whatever happens:**
- `pending` + `sending` rows are capped (`activity.max_pending`, default 2,000). Above the cap the scanner and the
  recorder insert nothing and add to `skipped_events` instead; the next message that is sent starts with
  "N events were skipped".
- Retention: `sent`, `coalesced`, `failed`, `dropped` rows older than `activity.retention_days` (default 14) are
  deleted by the purge task in batches of 1,000 (`DELETE … ORDER BY id LIMIT 1000`, repeated up to a time budget).
  `activity_report` rows are kept 400 days (they are one per day).

### 3.6 Client changes (small, backward compatible)

- `TelegramApiRejected` gets `?int $errorCode` and `array $parameters` (`retry_after`, `migrate_to_chat_id`); existing
  `catch` sites are unaffected.
- `sendMessageStrict()` gets an optional `?int $messageThreadId`, and a flag (or a sibling method) that skips
  `BotMessageLogger` (fact 3 in section 0).
- `getChatMember()` (exists) and `getChat` / `createForumTopic` (new, used only by the settings page actions).

## 4. Group, forum topics and setup

### 4.1 Plain group vs forum topics

Both are supported; topics are optional.

- **Plain group / supergroup without topics:** everything goes to the chat; each message starts with the category
  emoji.
- **Forum supergroup:** a mapping category → `message_thread_id` (`activity.topic.<category>`). An unmapped category
  goes to General. The settings page has **"Create topics for me"**: with the bot allowed to manage topics it calls
  `createForumTopic` once per enabled category (names from the translations, e.g. "💳 Payments") and stores the ids.
  Manual mapping (thread id field) remains for groups where the bot may not create topics.

**DECISION NEEDED (A4).** One topic per category as above (recommended), or fewer fixed topics (e.g. "Money",
"Customers", "Alerts", "Reports").

### 4.2 Connecting the group

Typing a chat id (`-100…`) is error-prone. Recommended: **link code**.

1. In Settings → Activity, the admin presses "Connect a group": a one-time code (`activity_<16 random chars>`,
   10 minutes, stored as a SHA-256 hash, single use) and the instruction "add the bot to the group, then send
   `/link activity_<code>` in the group".
2. The bot accepts that one command in a group (section 4.4), only if the code is valid and unexpired, and only from a
   sender who is an admin of the shop (`AdminAccessPolicy::botActor()` with `SETTINGS_MANAGE`). It stores the chat id,
   detects whether the chat is a forum (`getChat` → `is_forum`), answers in the group "Connected", and the code is
   consumed. Wrong or expired codes get no answer (no oracle).
3. Manual chat id entry remains as a fallback (validated: `^-?\d{5,20}$`, then a test message must succeed before it
   is saved).

**DECISION NEEDED (A5).** Link code with manual fallback (recommended), or manual chat id only (smaller PR, harder
for admins).

### 4.3 Rights the bot needs

To post: "send messages" (in a group where members may not post, the bot must be an admin). With topics: "manage
topics" only for "Create topics for me". The settings page checks with `getChatMember(chat, bot id)` and shows what is
missing. Privacy mode may stay on: the bot needs only its own `/link` command.

### 4.4 Prerequisite: the bot ignores groups

`TelegramUpdateHandler::handle()` handles only updates whose chat is `private`, with one exception: the `/link` command
of section 4.2 in the configured (or to-be-linked) group. Everything else from a group, a supergroup or a channel is
dropped before `TelegramUserResolver` runs (no account is created, nothing is answered). Callback queries from group
messages are ignored (the feed has no buttons). `my_chat_member` updates (bot added/removed/promoted) update the channel
state (`unreachable` on removal) when they concern the configured chat, and are otherwise ignored.

This is PR 1 and is worth merging on its own: today nothing stops someone from adding the bot to a group.

## 5. Privacy and security

### 5.1 Allow-list, not block-list

Every event type has a fixed list of fields it may carry; the renderer reads only those. A new field is a code change
with a test.

| Field | Shown | Why |
|---|---|---|
| Customer | `#U<user id>` (internal id) | identifies the customer for an admin (searchable in the panel and the bot) without exposing anything to group members |
| Customer display name | **off** by default; when on, the first name only, cut to 16 characters, passed through `PlainText`-style control-character stripping | names are personal data and a group may contain non-admins (A6) |
| Telegram id / username / phone | **never** | |
| Service | `#S<service id>`, plan title, traffic GB, days | |
| Order / payment | `#O<order id>`, `#P<payment id>`, order type, gateway label (store method title or gateway type) | |
| Amount | integer Toman, formatted; crypto as `verified_amount verified_currency` | |
| Order tracking code | **never** | customers use it to look up their order; treat it as a bearer value |
| Gateway transaction id, authority, receipt, card number, admin note | **never** | |
| Error texts | only a fixed reason code per alert (e.g. `panel_unreachable`, `delivery_rejected`); free text only for the admin-facing `last_error` on the status panel, through `SecretRedactor::redactAccess(ClaimToken::redact(…))`, 200 chars | errors can quote links and panel responses |
| Panel | panel title and `#id` | |
| Reseller | reseller `display_name` (already customer-facing, `src/Entity/Reseller.php`) or `#R<id>` | |

**DECISION NEEDED (A6).** Customer identity in the group: internal id only (recommended), or internal id + first name
behind a switch (off by default).

### 5.2 Resellers and claims (D5)

`docs/design/reseller-and-claim.md` D5: a reseller has no access to the end customer's data or links after a claim.
The group is admin-side, but it may include people who are not admins, so:

- a reseller sale shows the reseller and `#S`/`#O`, never the reseller's customer label (`reseller_customer` names
  are the reseller's private notes);
- a claim shows `#S`, the reseller, and the new owner only as `#U<id>`;
- claim tokens and links never appear (they are not in any allow-list; `ClaimToken::redact()` is applied on top).

### 5.3 Defence in depth

1. Allow-list (5.1).
2. Every string value goes through `SecretRedactor::maskAccess(ClaimToken::redact(…))` when it is **stored** in
   `fields`, and the final text through `SecretRedactor::forLog()` + `redactAccess` style checks when it is **sent**;
   if the redacted text differs from the original at send time, the event is not sent (status `dropped`,
   `last_error = redaction_changed_text`) — a guard that turns a leak into a visible bug.
3. Messages are sent with `parse_mode` HTML and every value `htmlspecialchars`-escaped; or plain text. No inline
   buttons, no links in v1 (a link to the admin panel would hand the admin URL to group members; can be added later
   behind a switch).
4. Logs: the outbox and sender log only ids and statuses through `RedactingLogger`; the group chat id is logged under
   a `chat` key so the existing masking applies.

### 5.4 Who sees what

The bot cannot tell who is in a group. The settings page says so plainly: "Everybody in this group sees amounts and
customer numbers. Use a group with your team only." There is no per-member filtering. The daily report has the same
audience. A `detail` setting allows `full` (amounts) or `counts` (no amounts) for shops that share the group with
support staff (**DECISION NEEDED (A7)**: offer `counts` in v1, or later).

## 6. Daily report

### 6.1 Contents (one message, sections omitted when empty or switched off)

For the local day D (00:00–24:00 in the report time zone):

1. **Money in** (same definition as the dashboard): per gateway/method — count and Toman; wallet top-ups (their
   ledger credit); total. Crypto: per `verified_currency` the exact received amount (decimal), plus the Toman booked.
2. **Sales** by order type (new / renewal / add traffic): count and Toman, **including** wallet-paid orders (marked
   "of which from wallet"), so sales ≠ money in by exactly the wallet spend.
3. **Wallet**: top-ups, purchases (spend), refunds, admin adjustments (+/−), reversals — from `wallet_transaction` by
   type, integer sums.
4. **Refunds**: payments with `refunded_at` in D, and wallet `refund` rows.
5. **Resellers**: `ResellerReport::summary(D)` totals (orders, wholesale, retail value, refunds), top 5 resellers.
6. **Customers & services**: new customers, new services, trials, claims.
7. **Problems**: payments rejected/failed in D; open `needs_review` and `provisioning_failed` *now*; delivery failures
   in D; alert events in D by type (from `activity_event`, so only while the feed is on — marked as such).
8. **Expiring soon**: active services that expire in the next 3 days (count), and services that expired in D.

Everything is computed from the business tables and the ledger at report time — not from counters, not from the
activity feed (except 7's alert counts, labelled).

### 6.2 Time zone and schedule

- Settings: `activity.report_timezone` (IANA name, validated against `DateTimeZone::listIdentifiers()`, default
  `Asia/Tehran`), `activity.report_time` (`HH:MM`, default `09:00`), `activity.report_enabled` (on once the feature is on).
- The report for D is due at `report_time` on D+1, local. Boundaries are computed with `DateTimeImmutable` in the zone
  and converted to UTC for the queries, so a 23 h / 25 h day (zones with DST) is still exactly one local day; a
  `report_time` that does not exist that day (spring-forward gap) means the first instant after it.
- The `activity` job (every 2 minutes) checks "is a report due and not yet done?". Running often means a server that
  was down at 09:00 sends at 09:02 after it comes back, and the freshness check works on the normal 2-minute job.
- Only the latest due day is sent automatically. After downtime of several days the missed days are **not**
  sent automatically (they would flood the group); `system` line "reports for … were not sent; use
  `app:activity:report --date=…`".

**DECISION NEEDED (A8).** Default report time (09:00 Tehran recommended) and whether to auto-send missed days (no,
recommended).

### 6.3 Idempotency: one report per day

Table `activity_report`: `report_date DATE` **UNIQUE**, `status` (`claimed`, `sent`, `failed`), `snapshot JSON` (the
computed figures), `computed_at`, `sent_at`, `telegram_message_id`, `attempts`, `claim_token`, `claimed_until`,
`regenerated_from_id NULL`.

1. Claim: `INSERT IGNORE` a `claimed` row for D (or take over a row whose lease expired and is not `sent`).
   Only the process whose insert/update affected one row continues.
2. Compute the snapshot **once** and store it before sending; retries resend the stored snapshot, so a retry after a
   partial failure never shows different numbers.
3. Send; mark `sent` with the message id. Unknown outcome → the A3 rule (at-least-once: resend later, at most 3
   attempts).
4. Restarts, two timers, a manual run in parallel: the unique key and the lease make exactly one sender.

### 6.4 Exact money

- Integer Toman columns are summed in SQL (`SUM` of `INT`/`BIGINT` is exact in MariaDB: `DECIMAL` result) and read as
  strings, converted with a checked `int` cast (fails loudly on overflow, which is impossible at realistic volumes).
- Crypto decimal strings are summed in PHP with a new `Decimal::add()` (same canonical form as `subtract()`, unit
  tests with long fractions), never floats.
- The revenue rule lives in **one** new class (e.g. `App\Admin\Application\Revenue\RevenueQuery::between($from, $to)`)
  extracted from `DashboardOverview::today()`; the dashboard calls it with "since midnight". A test asserts that the
  dashboard's figure and the report's figure for the same range are equal.
- Formatting: thousands separators; Persian digits when the report language is `fa`.

### 6.5 Commands

- `app:activity:report --date=YYYY-MM-DD [--dry-run] [--send] [--regenerate]`
  - default / `--dry-run`: compute and print to the console; send nothing, store nothing.
  - `--send`: claim and send the report for that date if it has not been sent.
  - `--regenerate`: compute again and send as "corrected report for D" (new row linked with `regenerated_from_id`);
    for when the admin fixed data afterwards. Needs `--send`.
- `app:activity:status`: channel state, pending counts, cursors, last error (redacted), last report — for SSH support.
- `app:automation:run --only=activity [--dry-run]`: the job (section 7); `--dry-run` prints what would be collected
  and sent.

## 7. Settings, admin UI, operations

### 7.1 Settings (all `activity.*`)

| Key | Default | Notes |
|---|---|---|
| `activity.enabled` | `false` | master switch; fail closed |
| `activity.chat_id` | empty | set by link code or manual entry; empty = nothing is enqueued or sent |
| `activity.is_forum` | `false` | detected |
| `activity.category.<c>.enabled` | per 1.1 | |
| `activity.topic.<c>` | empty | thread id |
| `activity.language` | `fa` | message language |
| `activity.show_customer_name` | `false` | A6 |
| `activity.detail` | `full` | A7 |
| `activity.report_enabled` | `true` | applies only when `activity.enabled` |
| `activity.report_timezone` | `Asia/Tehran` | |
| `activity.report_time` | `09:00` | |
| `activity.max_messages_per_run` | `12` | 1–20 |
| `activity.max_pending` | `2000` | 100–10,000 |
| `activity.retention_days` | `14` | 1–90 |

Fail closed: any unreadable value means "off" for switches and the default for numbers; an invalid chat id means
nothing is enqueued; an unknown category is not sent.

### 7.2 Admin UI

Web: new tab **Activity** in Settings (`AdminTabs::SETTINGS` + `SettingsAdministration::ACTIVITY_KEYS`,
`activityValues()` / `activityProblem()` / `saveActivity()`, audited `settings.update` with `page: activity`).
Permission `SETTINGS_MANAGE` (super admin): the feed shows money and the setting decides where it goes.

- Form: switches, categories with topic mapping, language, identity, detail, report time zone and time, limits.
- Actions (POST, CSRF, audited): **Connect a group** (link code), **Send test message** (synchronous, shows Telegram's
  answer, redacted), **Check bot rights**, **Create topics for me**, **Disconnect**, **Send report now (for yesterday)**.
- Status box: channel state, last success, last error (redacted), pending / failed / skipped counts, cursors' age,
  last report date and status.

Mini App admin settings: later, after the web tab (not in the first PRs).

### 7.3 Job, migrations, docs

- `deploy.sh` `SCHEDULED_JOBS`: `"activity|activity|*:1/2|300|120|app:automation:run --only=activity"` — own lock
  group (never waits behind the lifecycle jobs), odd minutes (the `broadcasts` job runs on even minutes), max age
  3 × 120 + 300 = 660 s. The job succeeds (and stays fresh) when the feature is off: its tasks return at once.
- `app:automation:run --only=activity` runs four tasks, each in its own `try` like the `orders` tasks:
  `collect` → `report` (if due) → `send` → `purge`.
- `tests/ops/scheduled-jobs.test.sh` covers the new line automatically (it parses the table); the comment in
  `deploy.sh` ("longest interval 30 min") stays true.
- Migrations (additive; `down()` drops the new tables, they hold no business data):
  1. `activity_event`, `activity_state`, `activity_report`;
  2. online indexes for the report: `payment (status, confirmed_at)`, `user (created_at)`,
     `vpn_service (created_at)`, `wallet_transaction (created_at)` — `ALGORITHM=INPLACE, LOCK=NONE` as in
     `Version20261024090100`. **DECISION NEEDED (A9)**: add them now (recommended; the scanner reads these columns every
     2 minutes) or only when slow.
- Docs: `docs/en/activity-group.md` + `docs/fa/activity-group.md` (setup with screenshots, what is posted and what never
  is, privacy note, report contents); `docs/en|fa/automation.md` job row; `docs/en|fa/troubleshooting/activity-group.md`
  (nothing arrives; bot removed; topic missing; migrated; report not sent; rights).

## 8. Test plan

Unit:
- Renderer per event type: allow-list (an extra field in `fields` is not shown), escaping (`<`, `&` in a plan title),
  Persian/English, `detail=counts`, customer name switch and length cap.
- Leak guard: fixtures with a `vless://` link, a subscription URL, a `/s/` token, a claim link, a Telegram id in a
  plan title or error → never in the output; the "redaction changed the text" guard drops the event.
- Report boundaries: Tehran day ↔ UTC range; a DST zone's 23 h and 25 h days; `report_time` in a gap; due/not-due
  around midnight.
- `Decimal::add()`; digest sums; back-off schedule.

Integration (MariaDB 10.11, `IntegrationTestCase`):
- Scanner: each source creates exactly one event; re-running the overlap creates none; batch limit and cursor
  movement; first enable starts at now; disabled does nothing.
- Recorder: switch off → no row; DB error → swallowed and logged; each call site runs after commit (a test that the
  connection is not in a transaction when `record()` is called, per alerter).
- Sender with a mocked Telegram (`MockHttpResponder`): 429 → paused with `retry_after`; 403 kicked → unreachable,
  one DM; 400 migrate → chat id updated, message resent to the new id; thread not found → General + flag; 6 failures →
  `failed`; caps (`max_pending`, skipped counter, coalescing).
- Report: figures for a seeded day (confirmed, wallet-paid, top-up with tolerated underpayment, refund, reversal,
  reseller order, crypto) equal hand-computed values; dashboard and report agree; `--dry-run` writes nothing;
  `--regenerate` links rows.
- Update handler: group `/start`, admin text in a group, callback from a group → nothing created or answered; `/link`
  with a valid code from an admin → linked; invalid code / non-admin → silence.
- Settings form cases (`tests/Support/SettingsFormCases.php` pattern) and permission (non-super-admin → 403).

Parallel processes (real processes, `ParallelStart`, MariaDB 10.11):
- Two `send` workers started together on 200 pending rows → every row sent exactly once (mocked Telegram counts),
  no row in two claims.
- A worker killed after claiming → its rows are sent by the next run after the lease, once.
- Two `report` runs for the same day at the same instant → one report row, one send.
- Settlement of 20 payments in parallel while `collect` runs every few hundred ms → exactly 20 events; settlement
  timings unchanged within noise (the scanner does not lock payment rows: plain consistent reads).

Ops: `tests/ops/scheduled-jobs.test.sh` passes with the new job; `bin/check-scheduled-jobs.sh` reports `NEW`, then `OK`.

## 9. Pull requests

Each PR is small, revertible on its own, and leaves the feature off.

| # | Scope | Files (expected) | Tests | Migration / timer / deploy |
|---|---|---|---|---|
| A-1 | Bot ignores non-private chats (section 4.4), without `/link` | `TelegramUpdateHandler`, maybe a `ChatScope` helper | group updates create/answer nothing | no / no / normal deploy |
| A-2 | Telegram client: error code + parameters on `TelegramApiRejected`, thread id, no-log send, `getChat`, `createForumTopic` | `TelegramApiClient`, `TelegramApiRejected` | unit with `MockHttpResponder` | no / no / normal |
| A-3 | Schema + scanner + recorder (no sending yet): `activity_event`, `activity_state`, report indexes; `ActivityRecorder` calls in the alerters; `collect` + `purge` tasks; `--only=activity`; job line in `deploy.sh` | entities/migrations, `src/Activity/…`, alerters, `AutomationRunCommand`, `deploy.sh`, `docs/*/automation.md` | scanner/recorder integration, parallel settlement test | **yes / yes / full deploy** |
| A-4 | Sender + renderer + privacy guard + settings tab (connect by manual chat id, test message, status) | `src/Activity/…`, `SettingsAdministration`, `SettingsAdminController`, `templates/admin/settings/_activity.html.twig`, translations | renderer/leak/sender/parallel sender | no / no / normal |
| A-5 | Link code + forum topics ("Create topics for me", mapping, topic-missing handling) | update handler (`/link`), settings actions | link-code and topic tests | no (state in `activity_state`) / no / normal |
| A-6 | Daily report: `RevenueQuery` extraction (dashboard uses it), `Decimal::add`, `activity_report` table, report task, `app:activity:report` | `src/Activity/Report/…`, `DashboardOverview`, migration | report figures, boundaries, idempotency (parallel) | **yes** / no (same job) / full deploy |
| A-7 | Docs: admin guide en/fa, troubleshooting en/fa, `app:activity:status` | `docs/…`, command | command test | no |

Order: A-1 → A-2 → A-3 → A-4 → A-6 → A-5 → A-7 (A-5 is comfort; the feed works with a manual chat id). A-1 and A-2
can be reviewed in parallel. A-3 is the first PR that needs a full deploy (migration + new timer).

## 10. Open questions for the owner

- **A1** catalogue and defaults (failed payments off, receipts excluded).
- **A2** scan + after-commit (recommended) vs after-commit everywhere.
- **A3** at-least-once (recommended) vs at-most-once on unknown outcome.
- **A4** one topic per category (recommended) vs fewer fixed topics.
- **A5** link code + manual fallback (recommended) vs manual chat id only.
- **A6** customer shown as internal id only (recommended) vs optional first name.
- **A7** `detail=counts` mode in v1 or later.
- **A8** default report time 09:00 Asia/Tehran; no automatic catch-up of missed days.
- **A9** add the report/scanner indexes in A-3 (recommended) or later.
- **Q1** One group only (recommended for v1), or several destinations (e.g. money to one group, alerts to another)?
- **Q2** Should the report also go by DM to `SETTINGS_MANAGE` admins when no group is connected?
- **Q3** Report language: one setting (recommended) or both languages?
- **Q4** Retention 14 days for sent events: enough?
- **Q5** Is a 2-minute feed delay acceptable (it follows from scanning)? 1 minute is possible at the cost of more
  process starts.
- **Q6** Should trial claims be posted individually (can be many) or only counted in the report?
- **Q7** Failed payments: confirm the time column to scan (to verify in A-3 which code paths set `payment.failed_at`;
  if not all do, rejected payments are scanned by an `updated` time that A-3 would have to add).
