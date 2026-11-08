# Tetraminator Payment Gateway Plugin: Design

Status: PRs 1 to 6 of section 13 are implemented. Plugin code: `tetraminator`.

Installable Toman payment gateway plugin for Amoobot, following the strict verification contract in
`docs/en/plugins/payment-gateway-plugin-sdk.md` and the layout of `docs/plugins/winapay-gateway/`. The plugin creates
invoices and asks Tetraminator whether they were paid. Amoobot does everything else: locking, amount check,
provisioning and notifications.

Tetraminator is harder to integrate than Winapay in three ways. The webhook has no signature, no body and no payment
reference, so it cannot say which payment it is about. The inquiry API documents only the paid answer. And an invoice
lives 60 minutes but stays payable while the shop may already have moved on. This design therefore treats the webhook
as a doorbell, polls with a timer, and keeps exactly one invoice on each `Payment` row.

## Summary

| Topic | Decision |
|---|---|
| Proof of payment | Only `GET /payment/inquiry/{pay_id}`, with the stored `pay_id` and an exact amount match |
| Webhook | Untrusted. Authenticated by a per-payment token. Triggers an inquiry and nothing else |
| Settlement | `PaymentSettlementService::settle()` only |
| Migration | None. Existing `Payment` columns are enough |
| Timer | New job `tetraminator`, every 2 minutes, at most 40 inquiries per run |
| Amounts | No conversion. Amoobot and Tetraminator both use Toman |
| `chat_id` | Off by default |
| Switch | `enabled` gateway field, off by default. Gates new invoices only |
| Core changes | Yes, small (section 13). A plugin alone cannot meet this brief |

## Where the code differs from the brief

These came up while reading the code. Each one changes the design, so they are listed first.

1. **There is no settlement idempotency key.** `settle()` is idempotent through the payment's status, checked under the
   order and payment locks (`PaymentSettlementService::run()`). The only payment-keyed idempotency key is the ledger key
   `WalletKeys::topUp($paymentId)` for wallet top-ups. Decision 1 uses those two and adds no new key.
2. **The core confirms an overpayment.** `AmountMatcher` returns `overpaid` and the core confirms it and flags it. It also
   confirms a shortfall within the tolerance. The brief requires an exact match, so the plugin must hide any other
   amount from the core (decision 1).
3. **`failed: true` would lose late money.** `recordGatewayFailure()` rejects with a source that `isRejectedUnpaid()` does
   not recognise, so `acceptsGatewayVerification()` turns false and a later payment is ignored. The plugin never returns
   `failed`, and expiry needs its own core path (decision 4).
4. **The generic callback route cannot be used, and it must be closed.** `/payment/callback/plugin/{code}` answers with
   payment state and has no rate limit. The adapter also accepts every callback unauthenticated, so anyone holding a
   `pay_id` could reach `verifyPayment()` through it (decision 2).
5. **`PaymentStarter` reuses open payments.** `claimPayment()` reuses the order's open payment, even when the customer
   switches gateway, and `createPayment()` runs after the order lock is released. Without a change this overwrites
   `pay_id` and the token while the old invoice is still payable, so the customer pays and nothing can see it
   (decision 3).
6. **The lock order differs by path.** The order "user, reseller, order, payment, wallet" is the reseller rule
   (`ResellerCheckout`). `settle()` takes order, payment, target service, then the user row and wallet
   (`WalletService::lock()`). Gateway payments are never reseller orders (`reseller_order_wallet_only`). See decision 10.
7. **There is no web checkout.** Every checkout goes through `PaymentStarter` and `StorePaymentMethodResolver`, from the
   bot and the Mini App. "Hidden on web" needs no separate work.
8. **The SDK forbids finding a payment by its own id (rule 2).** The brief asks for an internal reference plus a token.
   This design follows the brief as a deliberate exception: the id only selects the row, the token authenticates
   (decision 2).

## Gateway API

Base URL `https://api.tetraminator.com/v1`. Authentication header `X-API-KEY: <API_KEY>`.

| Call | Request | Success | Errors |
|---|---|---|---|
| `POST /invoice/create` | `price` (int, Toman, 50,000 to 10,000,000), `callback_url` (required), `chat_id` (optional) | 201: `status`, `message`, `pay_id`, `url`, `payment_link`, `mini_app_link`, `gateway_link` (only when enabled) | 400 bad data or amount too low, 401 invalid key, 403 store disabled |
| `GET /payment/inquiry/{pay_id}` | none | 200: `{status: true, payment_status: "paid", pay_id, amount}` | Undocumented |
| Webhook to `callback_url` | Plain GET, no body, no signature, sent after a successful payment | n/a | Retries and extra query parameters undocumented |

An invoice expires 60 minutes after creation.

## Flow

1. **Create.** The customer picks the method. `PaymentStarter` calls the plugin's `createPayment()`. The plugin generates
   the callback token, calls `POST /invoice/create` with the payable amount, and stores `pay_id`, the token's hash, the
   expected amount and the expiry on the payment. The customer gets a link: `url` in the bot, `mini_app_link` in the Mini
   App.
2. **Webhook.** Tetraminator sends a GET to `{callback_base_url}/payment/notify/tetraminator/{paymentId}/{token}`. Amoobot
   checks the token, then runs the same reconcile step as the timer. The answer is always the same neutral page.
3. **Verify.** `GET /payment/inquiry/{pay_id}` with the **stored** `pay_id`. Only the strict rule in decision 1 reports a
   paid amount.
4. **Timer.** Asks about open payments on a back-off schedule, because the webhook may never arrive. Expires them once the
   invoice is dead.
5. **Result.** `settle()` confirms and provisions. The customer hears from our bot, and an open Mini App page updates by
   polling.

## Decisions

### 1. Trust model

The webhook proves nothing. It carries no data and no signature, so it only causes an inquiry. `verifyPayment()` is the
single place that reads the inquiry answer, and it never reads the request.

The payment is paid only when all of these hold on the same HTTP 200 JSON answer:

- `status === true` (the boolean, not `"true"` or `1`)
- `payment_status === "paid"` (exact case)
- returned `pay_id`, as a string, equals the stored `pay_id`
- `amount` is an int or a string of digits, and equals the expected Toman amount exactly

| Inquiry answer | Plugin result | What Amoobot does |
|---|---|---|
| All four rules hold | `success, paid`, `amount` = reported, `currency` = `IRT`, `transactionId` and `refId` = `pay_id` | Confirms |
| Claims paid, any rule fails: other amount, other or missing `pay_id`, non-numeric amount, `status` not strictly `true`, `payment_status` paid in another case | `success, paid`, **no amount**, evidence in `verifyPayload` | NEEDS_REVIEW, admin alerted, customer told. Nothing is provisioned |
| `status: true` with any other `payment_status` | `success: true, paid: false`, value logged | Stays pending |
| HTTP 200 with `status: false`, or HTTP 404 with a JSON body | `success: true, paid: false` | Stays pending |
| 401, 403 | `success: false`, `rawResponse.error` = `unauthorized` or `store_disabled` | Stays pending. Timer run fails (decision 9) |
| 400, 429, 5xx, timeout, TLS error, HTML, empty, oversized, redirect | `success: false` | Stays pending. Retried by the next trigger |

**Why a mismatch reports no amount.** If the plugin reported a higher amount, the core would confirm it as `overpaid`.
Reporting no amount makes `AmountMatcher` answer `missing`, which is never confirmed. The review reason will read
"missing", so the plugin records the real difference with `$payment->setVerifyPayload()`: reason code, reported amount,
reported `pay_id` match yes or no. `settle()` flushes this before it re-reads the row.

**No tolerance.** `underpayment_tolerance_percent` is left out of `configSchema`. A tolerance typed into the config by hand
has no effect, because any inexact amount is already reported as no amount.

**Idempotency.** Duplicate webhooks and polls are no-ops, by two existing mechanisms:

- `settle()` re-reads the payment under its lock and returns `ALREADY_PROCESSED` unless it is still settleable.
- A wallet top-up credit uses `WalletKeys::topUp($paymentId)`, so a retry finds the first ledger row.

Unlike Winapay's `PaymentVerification`, the inquiry is a repeatable read. Asking twice uses nothing up, so the reconciler
needs none of Winapay's `101` handling and goes straight to `settle()`.

### 2. Callback URL and endpoint

```text
{callback_base_url}/payment/notify/tetraminator/{paymentId}/{token}
```

- `token`: 32 random bytes, base64url, 43 characters, generated in `createPayment()`. Only `sha256(token)` is stored, in
  `Payment::authority`. Comparison uses `hash_equals()`.
- **Why not the return token.** `returnToken` opens the paid page, which shows the customer's VPN links. Sending it to a
  third party would hand them those links.
- **Why the token is in the path.** A query string could collide with parameters the provider may append, which are
  undocumented. A path survives them.
- **SDK rule 2 exception.** `paymentId` only selects one row by primary key, so the token check is a constant-time compare
  on one row instead of a lookup on an unindexed column. The id alone gets nothing.
- `callback_base_url` must be `https`, with a host and no user info. This is stricter than Winapay, which also accepts
  `http`. It stays on the main domain, as for other gateways: the brand domain is not involved.

**Endpoint.** A new thin controller, `App\Payment\UI\TetraminatorNotifyController`, `GET` only. Steps, stopping at the first
failure:

1. Rate limit per client IP: `payment_notify_ip`, sliding window, 60 per minute.
2. Check formats by regex: `\d{1,18}` and `[A-Za-z0-9_-]{43}`. No database access yet.
3. `find()` the payment by primary key. Require plugin code `tetraminator` and `hash_equals()` on the token hash.
4. Rate limit per payment: `payment_notify_payment`, 6 per 10 minutes, so a valid URL cannot be used to hammer the provider.
5. `TetraminatorReconciler::reconcile($paymentId, 'tetraminator_webhook')`, inline, like every other gateway callback.

Every outcome returns identical bytes: HTTP 200, a constant short page ("Received. You can go back to the bot.", in
Persian and English), `Cache-Control: no-store`, `Referrer-Policy: no-referrer`, `X-Robots-Tag: noindex, nofollow`. It works
for a server and for a browser, in case the provider's GET turns out to be a browser redirect (open question 5). A
wrong token costs at most one primary key read. Response time still differs for a valid token, but only someone who
already holds that token can observe it.

**Closing the generic route.** `PaymentWebhookController::run()` answers 404 for `tetraminator`, through a small list of
plugins with their own endpoint. Otherwise `/payment/callback/plugin/tetraminator?transaction_id=<pay_id>` would bypass the
limiter and return the outcome code.

**Access log.** The URL holds a credential, so it gets the same treatment as `/telegram/webhook/`: its own nginx location
`^~ /payment/notify/` with `access_log off` and `error_log ... crit`, inside `app_locations()` in `deploy.sh`. It includes the
maintenance file, so a deploy answers 503. If the provider does not retry, the timer covers it.

### 3. Storage

No migration. Every value fits an existing column.

| Value | Column | Set by | Notes |
|---|---|---|---|
| `pay_id` | `gatewayTransactionId` | `PaymentStarter`, from `transactionId` | Provider type undocumented, normalised to a string, must match `^[A-Za-z0-9_-]{1,128}$` |
| Token hash | `authority` | `PaymentStarter`, from `authority` | Hex SHA-256. Never the raw token |
| Expected amount | `expectedAmount`, `expectedCurrency` = `IRT` | Plugin, `setExpectation()` | Frozen at invoice time. `AmountMatcher::expectationFor()` already reads it |
| Expiry | `expiresAt` | Plugin | No other code reads or writes `Payment::expiresAt` today |
| Created | Derived: `expiresAt` minus 60 minutes | n/a | `createdAt` is the row's age, which can be older than the invoice |
| Bot link | `paymentUrl` | `PaymentStarter` | |
| Mini App link | `requestPayload.mini_app_link` | Plugin result | `requestPayload` holds an allowlist: `status`, `message`, `pay_id`, `mini_app_link`. Anything echoed back, such as a callback URL, is dropped |

**One `Payment` row is one invoice, for life.** This is the rule that makes the columns safe. Core changes in
`PaymentStarter::claimPayment()`, under the order lock it already holds:

- A live invoice, same gateway: the plugin returns the stored link and calls no API.
- A live invoice, customer switches gateway: the old row is closed with source `gateway_invoice_superseded`, and a new row
  is created. The old invoice can still be paid, so that money reaches review.
- An expired invoice: closed the same way, and a new row is created.
- Two concurrent starts: `createPayment()` runs outside the lock. For a gateway declaring an invoice lifetime, the claim
  writes `expiresAt = now + 90 seconds` as a reservation. A second start that sees a reservation with no `pay_id` gets a
  "try again" failure. `createPayment()` replaces the reservation, and a failure clears it. A reservation left behind by a crash has no
  `pay_id`, so after its 90 seconds the row is simply reused.

### 4. Reconciliation timer

A new automation task `app:automation:run --only=tetraminator`, served by `App\Payment\Application\Reconcile\TetraminatorReconciler`,
which is also the webhook's step 5. The core cannot name plugin classes, so it uses a `PLUGIN_CODE` constant as
`WinapayReconciler` does.

```text
tetraminator|tetraminator|*:0/2|240|120|app:automation:run --only=tetraminator
```

| Field | Value | Why |
|---|---|---|
| Lock group | `tetraminator`, its own | `lifecycle` can be held for up to 25 minutes by `sync`, longer than the back-off steps |
| Schedule | Every 2 minutes | Webhook loss should cost the customer minutes |
| Timeout | 240 s | Allows for one provisioning near the end of the 150 s budget |
| Max age in `amoobot-check-jobs` | 600 s | Existing rule: 3 × 120 + 240 |

**Schedule per invoice.** Age is counted from invoice creation. The back-off needs no new column: a payment is due when its
age crossed a multiple of the step since the previous tick. A skipped tick (a deploy, for example) just skips that poll.

| Age | Poll | Applies to |
|---|---|---|
| Under 2 min | Never | The customer cannot have paid yet |
| 2 to 20 min | Every 2 min | Open and rejected-unpaid |
| 20 to 70 min | Every 6 min | Open and rejected-unpaid |
| 70 min and over | Next tick | Open: a well-formed unpaid answer expires it |
| 70 min to 24 h, last answer was an error | Every 10 min | Open: stays open until a well-formed answer |
| 24 h | Once | Open: expired without an answer, logged as an error |
| About 6 h and 24 h | One sweep each | Rejected-unpaid: catches a status that became paid after a lost webhook |

That is about 20 inquiries for an abandoned invoice.

**Rejected-unpaid payments are polled too.** A customer who cancelled in the bot can still pay the invoice, and an admin may
set the incomplete-order expiry as low as 1 hour. A later `paid` reaches `settle()` and parks in review through
`parkLatePayment()`, the existing path.

**Limits per run.** At most 40 inquiries, a 150 s time budget, stop after 3 consecutive transport or 5xx errors, stop at the
first 401 or 403. Open invoices inside the window go first, then overdue ones, then late sweeps. Overflow logs a warning
and waits for the next tick. At 40 per run the timer handles about 60 new invoices an hour at full polling.

**Exit code.** `amoobot-job-alert` fires on a failed run and repeats at most hourly, so a failed run must mean a person is
needed.

| Run result | Exit |
|---|---|
| Nothing to do, including while the plugin is not installed | 0 |
| Transport or 5xx errors | 0, with warnings |
| A 401 or 403, or the driver is unavailable with open payments | 1 |
| An open payment still has no answer 1 hour past `expiresAt` | 1 |

**As implemented (PR 5).** `TetraminatorReconciler::runDue()` around `reconcile()`, with the schedule in
`TetraminatorPollSchedule`. A run is placed on its 2-minute tick (now rounded down), so a run that starts late sees the same
ages. An open payment at 24 hours gets one last inquiry: paid settles, anything else expires it (an error answer with an
error log). `--only=all` does not run the task: it would bypass the `tetraminator` lock group and mix its exit code into the
other tasks'.

**`NEW` state.** A full `deploy.sh` installs the timer, and `amoobot-check-jobs` shows `NEW` until the first successful run.
`--app-only` does not install it. The first run has nothing to do and exits 0, so the job turns `OK` without the plugin.
`tests/ops/scheduled-jobs.test.sh` and the job count in the docs change with it.

**Marking expired.** New method `PaymentSettlementService::expireUnpaid(Payment, string $source)`. It takes `lock()`, changes
only a `pending` payment, sets `rejected` with the new source `Payment::SOURCE_GATEWAY_INVOICE_EXPIRY` and leaves the order
open, so the customer can start again. `isRejectedUnpaid()` learns both new sources, so late money reaches review.
`PaymentDisplayState::forCustomer()` shows `expired`, and `parkLatePayment()` gets its wording for an open order.
`failedAt` is not set, and the customer gets no message, matching the order-expiry job. Settlement stays the only writer of
this transition.

### 5. Amounts

**There is no conversion.** `Payment::amount` and `payableAmount` are Toman (`docs/en/payments/amount-units.md`), and
`price` is Toman, so the value is sent as is: `$payment->getPayableAmount() ?? $payment->getAmount()`. `AmountNormalizer::toRial()`
is not called. `Payment::currency` is a legacy label and is not used for amount checks.

**Rounding.** None. Amounts are integers on both sides. A reported decimal such as `50000.0` counts as a mismatch and goes to
review, rather than being rounded into a match. If the provider really sends that format, the first real payment
shows it (section 15), and the rule can be relaxed knowingly.

**Inquiry unit.** The docs do not state the unit of `amount` in the inquiry. Toman is assumed. If it is Rial, every payment
reaches review and nothing is confirmed wrongly. Open question 4.

**Limits, 50,000 to 10,000,000 Toman.** The plugin refuses anything outside the range in `createPayment()`, before any API
call. To hide the method rather than fail after choosing it, a new optional core interface, implemented by the plugin
and exposed by `PluginPaymentGatewayDriverAdapter`:

```php
interface PaymentGatewayConstraintsInterface
{
    /** @param array<string, mixed> $config */
    public function isUsable(array $config): bool;      // decision 8

    public function acceptsAmount(int $toman): bool;    // this decision

    public function invoiceLifetimeSeconds(): ?int;     // decision 3: non-null means one invoice per row
}
```

`StorePaymentMethodResolver::evaluateMethods()` checks it next to `isAmountAllowed()`, adding the skip reasons
`gateway_not_usable` and `amount_out_of_range`. `getOfferableMethods()` checks `isUsable()`. Both the bot and the Mini App read
from there. Admins do not have to remember to fill in `StorePaymentMethod::minAmount` and `maxAmount`, though those still
apply and can narrow the range.

### 6. Which link the customer gets

| Where | First choice | Fallback |
|---|---|---|
| Bot | `url` | `payment_link`, then `gateway_link` |
| Mini App | `mini_app_link` | `url` |

- **Rationale.** `url` follows the store's gateway priority, set in the provider's panel, so the shop owner stays in control.
  `mini_app_link` keeps a Mini App customer inside Telegram.
- **Validation.** `https`, no user info, no port. A response without a valid link is a failed create. Hosts are not pinned yet,
  unlike Winapay, because the docs do not list them. Open question 6.
- **Bot.** The existing `paymentOnlineActionMenu`, with its pay button and check button. The check button calls
  `verifyPayment()`, and `settle()` handles the result, with no change.
- **Mini App.** The payment page reads `mini_app_link` from `requestPayload` for this gateway. A `t.me` link should open with
  `openTelegramLink`, and `app.js` always uses `openLink` for an external main button, so it needs a small change.
  **As implemented (PR 6).** Generic, not tied to this plugin: `PaymentTelegramLink` reads `mini_app_link` from a plugin
  gateway's stored payload and checks it again (https, host exactly `t.me`, no user info, port or fragment, a plain path,
  at most 512 characters). With a valid link the page's main button (`data-main-button-telegram`) and its open button
  (`a[data-telegram-link]`) use `openTelegramLink`; otherwise both open `url` with `openLink` as before. The button text
  stays the generic one. A second tap on a live invoice reuses the row and its stored link.
- **Result.** The API has no return URL, so the customer is never sent back to `/payment/return/{token}`. The result arrives
  as our bot's normal confirmation or under-review message from `PaymentCustomerNotifier`. An open Mini App page polls its
  read-only status endpoint and reloads when the state changes. The page should say that the invoice is valid for 60 minutes.

### 7. `chat_id`

Config field `send_chat_id`, boolean, default off. With it off, `chat_id` is not sent.

Rationale: it sends the customer's Telegram id to a third party, and the shop gains nothing it needs, because our own bot
already reports the result. Turning it on is the shop owner's decision, and the admin guide must state this. The value
comes from `$order->getUser()->getTelegramAccount()?->getTelegramId()`, and is left out if missing. It is never stored in
`requestPayload`. `RedactingLogger` already masks `chat_id` in logs.

The provider's message may arrive before our confirmation, or say "paid" for a payment that we park for review. That is one
more reason to keep it off by default.

### 8. Secrets and the switch

**API key.** Gateway config field `api_key`, type `password`, stored like every gateway secret in `PaymentGateway::config`.
The name `api_key` is already on the masked list in `PaymentGatewayCrudController`. The admin audit records field names, never
values.

**Redaction.**

- The plugin masks the exact key in every message and stored response, as Winapay does with `mask()` and `sanitize()`.
- The reconciler passes every provider or exception text through `SecretRedactor::redact()`, with the key and `pay_id` as
  exact secrets.
- `SecretRedactor::redact()` gains patterns for `X-API-KEY` and `api_key`, which it does not cover today. `forLog()`, used by
  `RedactingLogger`, only handles Telegram ids and stack trace arguments. So the exact-value mask is the real protection, and
  the pattern is a backstop.
- Logs carry the payment id only. `pay_id`, the callback URL and customer links are never logged.

**`pay_id` is not a credential**, because it cannot be used without the API key. It is kept out of logs for the same reason as
Winapay's Authority. The payment links do bear it, and they let anyone open the invoice, so they are treated as sensitive.
Docs and prompts use `<API_KEY>`, never a real key.

**Switch.** Config field `enabled`, boolean, default off. `isUsable()` returns true only when `enabled` is on, `api_key` is
non-empty and `callback_base_url` passes decision 2. Anything else hides the method, and `createPayment()` refuses the
same way. Missing, empty or unknown values count as off.

**The switch gates new invoices only.** Turning it off does not stop inquiries or the webhook for invoices already issued,
because the customer can still pay them for up to 60 minutes. To cut everything, an admin removes the key: inquiries then
fail and open payments are expired at 24 hours with an error.

### 9. HTTP client

A new `TetraminatorApi`, following `WinapayApi`: 5 s inactivity timeout, 15 s total, TLS verification on, no redirects, 64 KiB
body cap, `Accept: application/json`, JSON objects only.

**No retry on create.** The API has no idempotency key, so a retry after a timeout can leave a second invoice. A failed
create returns `success: false`, and the customer can press the button again. If the first call did reach the provider,
that invoice is an orphan nobody has a link to, so it can never be paid.

**No retry inside an inquiry either.** The next tick is the retry.

| Case | Create | Inquiry |
|---|---|---|
| 201 (200 accepted), `status === true`, valid `pay_id` and link | Success | n/a |
| 400 | Failed: amount or data refused | Error, stays pending |
| 401 | Failed. `adminNote` says invalid key | Error, stays pending, run exits 1 |
| 403 | Failed. `adminNote` says store disabled | Error, stays pending, run exits 1 |
| 404 | Failed | Not paid, if the body is JSON |
| 429, 5xx, timeout, transport | Failed, no retry | Error, stays pending |

**Inquiry down.** Nothing changes state. Payments stay pending, the circuit breaker in decision 4 stops the run, and no
payment expires without a well-formed answer until 24 hours have passed.

Customer-facing messages are generic. The admin detail goes to `adminNote` and contains no key. `PaymentStarter` already stores
the failure message there.

**Account errors at create (closed in PR 3).** A 401 or 403 at create now alerts the admins in Telegram, at most once an
hour per gateway: the plugin reports `rawResponse.error` `unauthorized` or `store_disabled`, and the core's
`PaymentGatewayCredentialAlert` (called by `PaymentStarter`, any plugin may use the two codes) sends a fixed text with no key.

### 10. Concurrency

The design adds no new lock order. Everything uses the existing `lock()`: order, then payment, by primary key, after a plain
read. Inside `settle()` come the target service for a renewal, then the user row and the wallet for a top-up. The reseller
rule (user, reseller, order, payment, wallet) is not touched, since gateway payments are never reseller orders.

Inquiries run outside any transaction, so no lock is held during an HTTP call.

| Race | Result |
|---|---|
| Webhook and timer both see `paid` | Both call `settle()`. The first confirms, the second gets `ALREADY_PROCESSED` |
| Two webhooks, bot check button, return page | The same |
| Both see a mismatch | The first parks, the second gets `ALREADY_PROCESSED`. One alert |
| Timer expires, webhook settles | Whichever locks first wins. Settle first: expiry skips it. Expiry first: `settle()` parks it as late payment, and the money is kept |
| Customer cancel or order expiry, and settlement | The order lock serialises them, as today |
| Second payment on the same order pays | `settle()` finds the order fulfilled and parks it |
| Two timer runs | `flock` on the `tetraminator` group, run skipped |

After a failed settlement the entity manager is cleared, so the reconciler uses `SettlementResult::$payment` and re-reads by id
for each payment, as `WinapayReconciler` does.

### 11. Failure modes

| Failure | What happens | Customer impact |
|---|---|---|
| Webhook spoof, no valid token | Neutral page, at most one primary key read | None |
| Replay with a valid token | Per-payment limit. A settled payment is skipped with no inquiry, otherwise one inquiry and `settle()` is a no-op | None |
| Token leaked | The holder can only trigger a rate-limited inquiry | None |
| `pay_id` leaked | Generic route closed. Useless without the key | None |
| Amount mismatch | NEEDS_REVIEW, alert, verify payload holds the evidence | Told it is under review |
| `pay_id` mismatch | The same | The same |
| Webhook never arrives | Timer settles within about 2 to 6 minutes | Short wait |
| Webhook arrives before the row is flushed | Token not found, neutral page, timer settles | Short wait |
| Late payment after our expiry | Webhook or sweep, then `parkLatePayment()`, review | Told it is under review |
| Customer cancels, then pays | The same | The same |
| Customer switches gateway, then pays the old invoice | Old row was closed as superseded, then review | The same |
| Paid status arrives after the last sweep and the webhook is lost | Not detected. Admin compares with the panel if the customer reports it | Support case |
| Provider down during checkout | Create fails, no retry | Picks another method or tries again |
| Provider down after payment | Stays pending, polled until answered, alert after 1 hour | Delay, no loss |
| Key revoked | Create fails, timer run fails if payments are open | Method fails until fixed. Hidden only when the key is removed |
| Store disabled (403) | The same, with its own note | The same |
| Unknown `payment_status` | Treated as not paid, value logged | Delay. Reviewed by hand if paid |
| Provisioning fails after payment | Existing `PROVISIONING_FAILED`, retry list | Existing message |
| Deploy during a webhook | 503 from the maintenance file, timer settles | Short wait |
| Timer down | `amoobot-check-jobs` reports it. Webhook still works | Lost webhooks wait |

### 12. Open questions for the provider

Send these before writing code. The answers change the code in the places marked.

1. All `payment_status` values, and which are final? (Decision 1, `TetraminatorStatus`)
2. What exactly does the inquiry return for unpaid, expired and unknown invoices: which HTTP status and which body? Today an
   HTTP 200 with `status: false` and a 404 with JSON are assumed to mean not paid. (Decisions 1 and 4)
3. Does the webhook retry? How often, and for how long? Which response counts as received, and what is its timeout? Does the
   URL get extra query parameters? (Decision 2)
4. Can `amount` differ from `price`, for example through fees? What is its unit and JSON type? (Decisions 1 and 5)
5. Is the webhook sent by a server, or is the buyer's browser redirected there? Is there any return URL for the buyer?
   (Decisions 2 and 6)
6. Which hosts can appear in `url`, `payment_link`, `mini_app_link` and `gateway_link`, so that they can be pinned? (Decision 6)
7. What is the type and maximum length of `pay_id`? (Decision 3)
8. Which IP addresses send webhooks, for allowlisting? The token stays required either way.
9. Can an invoice be paid after 60 minutes, for example a card-to-card payment confirmed late by an operator? Can an invoice be
   cancelled through the API? (Decisions 3 and 4)
10. Are there API rate limits? (Decision 4)
11. Is there a sandbox or test key? Does creating an invoice cost anything?
12. Is a refund or chargeback signalled in any way after `paid`?

Until questions 1 and 2 are answered, the real-payment checklist below stays the only proof of those branches.

### 13. PR split

Core first, each PR small and usable alone. The gateway stays unavailable until the last one is deployed, because the plugin
ships as a ZIP nobody has installed.

| PR | Content | Migration | Deploy |
|---|---|---|---|
| 1. Core constraints | `PaymentGatewayConstraintsInterface`, adapter, resolver checks | No | `--app-only` |
| 2. One invoice per row | `claimPayment()` rules, reservation, new sources, `isRejectedUnpaid()`, display state, `expireUnpaid()` | No | `--app-only` |
| 3. Plugin | `docs/plugins/tetraminator-gateway/`: manifest, `src/`, `tests/`, `build.sh`, `README.md` (create and verify, HTTP mocked) | No | ZIP, not enabled |
| 4. Notify endpoint | Controller, limiters, generic-route guard, `security.yaml`, redaction patterns, nginx location | No | **Full** deploy |
| 5. Reconciler | `TetraminatorReconciler`, `--only=tetraminator`, `SCHEDULED_JOBS`, ops test, docs | No | **Full** deploy |
| 6. Mini App link | Payment page, `app.js` | No | `--app-only` |

The plugin must not be enabled before PR 5 is deployed. Without the timer, a lost webhook means a paid customer with no
service. PR 2 changes payment behaviour for every gateway only when `expiresAt` is set, which only this plugin does.

### 14. Test plan

**Unit, plugin (mocked HTTP, like `WinapayGatewayPluginTest`).** Every row of the verification table. Strict types: `status: "true"`,
`status: 1`, `payment_status: "PAID"`, amount `50000.0`, `"50000"`, `50000`, negative, null. `pay_id` as an int and a string. The
stored `pay_id` is used, never the payload. Create: range limits at 49,999, 50,000, 10,000,000 and 10,000,001. No call on
refusal. `chat_id` absent by default. Reuse of a live invoice without an API call. Link choice and fallback. Rejected links.
`http` base URL refused. `isUsable()` for each missing field. The key never appears in any message or stored response. The token
is not stored raw. `failed` is never true.

**Unit, core.** `AmountMatcher` confirms nothing for a paid result without an amount. Back-off due calculation at each tier
edge. `isRejectedUnpaid()` and `forCustomer()` for the new sources. `SecretRedactor` patterns.

**Functional, on MariaDB 10.11 (port 3307).**

- Endpoint: wrong token, malformed token, unknown id, other gateway's payment, over limit. All give byte-identical responses and
  no inquiry. Valid token settles once.
- Generic routes return 404 for `tetraminator`.
- Full paths: new service, renewal, add traffic, wallet top-up, each settled by webhook and by timer.
- A top-up credits once under repeated webhooks.
- Mismatch parks and alerts once.
- Late payment after expiry, after cancel and after a gateway switch each reach review.
- Second start reuses, gateway switch closes the old row, expired invoice gets a new row.
- Timer limits, order, circuit breaker, and every exit code.
- `amoobot-check-jobs` shows `NEW`, then `OK`.
- Resolver hides the method out of range, switched off and misconfigured, in bot and Mini App flows.

**Multi-process races (real processes against MariaDB, as in `WinapayReconcileParallelTest`).**

- Webhook and timer racing one paid payment: one confirmation, one provisioning, one delivery message.
- The same for a top-up: one ledger row.
- `expireUnpaid()` against `settle()`: ends confirmed or in review, never rejected with money ignored.
- Two concurrent `start()` calls: one provider call, one `pay_id`.
- `start()` with a gateway switch against a webhook.

**Ops.** `tests/ops/scheduled-jobs.test.sh` with the new job. `deploy.sh --dry-run` shows the nginx location.

### 15. Real-payment checklist

Use the real gateway on production, with a test plan, and with the switch on only for this test.

- [ ] Provider has answered open questions 1 to 5, or the unanswered ones are written down.
- [ ] `validate-package` and `doctor` report no errors. All six PRs are deployed, with a full deploy.
- [ ] `amoobot-check-jobs` shows `tetraminator` as `OK`.
- [ ] `https://` base URL is reachable from outside. The notify URL with a wrong token returns the neutral page.
- [ ] `grep` of logs and `journalctl` after the test: no key, no token, no `pay_id`, no payment link. The nginx access log has no
      notify line.
- [ ] Wrong key: the method fails, and the key appears nowhere. Then the right key.
- [ ] `enabled` off: the method is hidden in bot and Mini App. Orders of 49,999 and 10,000,001 Toman: hidden while enabled.
- [ ] **Minimum real payment, 50,000 Toman, from the bot.** The provider page shows 50,000 Toman, not 500,000 or 5,000.
- [ ] Inquiry `amount` equals 50,000 exactly. Record the real JSON in the admin payment. If it goes to review, stop and
      compare units.
- [ ] Webhook arrived. Record request method, user agent, query parameters and source IP, by turning on that location's
      access log for this test only. Turn it off again before the log check above.
- [ ] Order confirmed and provisioned once. Bot message received once. Reload and press the check button: nothing changes.
- [ ] 50,000 Toman from the Mini App, using `mini_app_link`. The page updates by itself.
- [ ] Wallet top-up of 50,000 Toman: wallet credited once.
- [ ] Lost webhook: block the notify location for one payment. The timer settles it within minutes.
- [ ] Unpaid invoice, left alone: record the real inquiry answer. Marked expired after about 70 minutes, and the order can be
      paid again.
- [ ] Cancel in the bot, then pay the still-open invoice: review, admin alert, customer told.
- [ ] Only after all of the above, turn the method on for customers. Check the first day's payments against the provider panel.

## Config fields

| Key | Type | Required | Notes |
|---|---|---|---|
| `enabled` | boolean | no (default `false`) | Gates new invoices. Decision 8 |
| `api_key` | password | yes | Sent as `X-API-KEY`. Never shown in messages, logs or stored responses |
| `callback_base_url` | url | yes | Public `https` base URL of Amoobot, e.g. `https://shop.example.com` |
| `send_chat_id` | boolean | no (default `false`) | Sends the customer's Telegram id to Tetraminator. Decision 7 |

`underpayment_tolerance_percent` is deliberately absent. Plugin manifest: `category` `payment`, `supportsWebhook` `false` (the
endpoint is the core's own), `supportsOnlinePayment` `true`, permissions `payment.create`, `payment.verify`, `http.outbound`.

## Setup in the Tetraminator panel

1. Create the API key and enter it as `api_key`. It appears only in Admin > Payment Gateways.
2. Set the store's gateway priority. It decides what `url` opens.
3. Make sure the store is enabled (otherwise `403`).

## Build, validate, install

The same as Winapay, with `docs/plugins/tetraminator-gateway/build.sh`, once the plugin exists:

```bash
docs/plugins/tetraminator-gateway/build.sh /tmp/tetraminator.zip
php bin/console app:plugin:validate-package /tmp/tetraminator.zip
php bin/console app:plugin:install /tmp/tetraminator.zip
php bin/console app:plugin:enable tetraminator
php bin/console app:plugin:doctor tetraminator
```

Then add the module in Admin > Payment Gateways, fill in the config, and create a store payment method that points to it.

---

## خلاصهٔ فارسی

این سند فقط طراحی افزونهٔ درگاه **تترامیناتور** (کد `tetraminator`) است و هنوز چیزی پیاده‌سازی نشده است. مبلغ در آموبات و تترامیناتور هر دو **تومان** است و تبدیلی انجام نمی‌شود.

- وب‌هوک امضا ندارد، پس فقط باعث استعلام می‌شود و هرگز چیزی را تأیید نمی‌کند. آدرس آن یک توکن تصادفی مخصوص هر پرداخت دارد و پاسخش همیشه یکسان است.
- پرداخت فقط وقتی تسویه می‌شود که استعلام `status === true` و `payment_status === "paid"` بدهد، `pay_id` با مقدار ذخیره‌شده برابر باشد و مبلغ دقیقاً برابر مبلغ مورد انتظار باشد. هر مغایرتی به بررسی دستی می‌رود.
- تایمر `tetraminator` هر ۲ دقیقه پرداخت‌های باز را با فاصلهٔ افزایشی استعلام می‌کند و حدود ۷۰ دقیقه پس از ساخت فاکتور آن‌ها را منقضی می‌کند. پرداخت دیرهنگام از بین نمی‌رود و به بررسی دستی می‌رود.
- هر ردیف `Payment` فقط یک فاکتور دارد و مهاجرت پایگاه‌داده لازم نیست.
- ارسال `chat_id` و خود درگاه به‌طور پیش‌فرض خاموش است. مبلغ‌های خارج از ۵۰٬۰۰۰ تا ۱۰٬۰۰۰٬۰۰۰ تومان این روش را پنهان می‌کنند.
- پیش از نوشتن کد باید پرسش‌های بخش ۱۲ از تترامیناتور پرسیده شود و اولین پرداخت واقعی با حداقل مبلغ ۵۰٬۰۰۰ تومان انجام شود.
