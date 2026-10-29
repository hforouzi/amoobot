# Resellers and service claim links — design

Status: **S1 (reseller core) and S2 (claim links) implemented** (admin guide: [docs/en/resellers.md](../en/resellers.md)); S3 not yet. Persian summary: [docs/fa/design/reseller-and-claim.md](../fa/design/reseller-and-claim.md). D4 and D5 were confirmed as recommended. Where S2 differs from the text below, see "S2 as built" at the end of section 7.

Items marked **DECISION NEEDED** are decisions this document takes, with a recommendation, that the owner should
confirm or change before S1 starts. Everything else is decided.

## 0. Given (owner decisions) and what the code has today

Given:

- A reseller buys at a **wholesale price from his own wallet**. Services are created under the reseller's account.
  The end customer does not need the bot; if he wants it, the reseller hands the service over with a **claim link**.
- A claim link is **single use, expiring**, and transfers ownership of **one** service to a Telegram user.
- The wallet is on for everybody. Every reseller money movement goes **only through `WalletService`**, with unique
  idempotency keys, and `app:wallet:reconcile` covers it.
- Referrals come later and must reuse the same deep-link infrastructure.

What exists and is reused (read for this design):

| Area | Today | Used for |
|---|---|---|
| `WalletService` | Only writer of `wallet` / `wallet_transaction`; row lock (user, then wallet); unique `idempotency_key`; `reverse()` once per row (unique `reversal_of`); `credit_limit`; `frozen` | All reseller money |
| `WalletPayment` | `open()` debits `purchase:payment:{id}` and creates a `wallet` payment in one transaction; `pay()` settles through `PaymentSettlementService::settle()`; held debit kept on `provisioning_failed`, reversed (`reversal:payment:{id}`) on reject/cancel/expire | Reseller purchase = a wallet payment of a reseller order |
| `WalletReconciler` | Ledger vs cached balance; payments vs ledger keys (10 problem codes) | Extended (section 4.4) |
| `PaymentSettlementService` | Locks order then payment; amount check; fulfilment; `PROVISIONING_FAILED` + admin retry | Unchanged |
| Orders | `order.amount` + `metadata.priceSnapshot` (base, global discount, code, final); `PaymentStarter::payableAmount()` reads the snapshot | Wholesale snapshot added |
| Pricing | `PlanPricingService` (base, global discount %), `TrafficAddonPricingService`, renewals priced from the current plan with the source order's traffic/duration | Wholesale computed on top |
| Ownership | `vpn_service.user_id`; `CustomerServiceFinder::ownedBy()` is the ownership check for bot and Mini App | Claim changes `user_id` |
| Subscription page | `vpn_service.public_token` (random, rotatable: `rotatePublicToken()`), independent of panel `subId` | Rotated on claim |
| Panel client | Modern 3x-ui driver writes `tgId` (buyer's Telegram id) and `comment` (`amoobot order #N`) on create; legacy driver writes no `tgId`; `ModernClientsApi::updateClient()` exists | Updated on claim (best effort) |
| Admin roles | `AdminPermission` enum, `AdminRolePermissions` matrix, `AdminPermissionVoter`; `AdminAuditLogger` with sources `web`, `bot`, `cli`, `miniapp` (admin actors only) | New permissions; customer-side events need their own table |
| Mini App auth | `initData` HMAC check, `mini_app_session` (hashed token, telegram id, UA hash, expiry, revocation); `start_param` is parsed (`ValidatedInitData::$startParam`) but **unused** | Claim entry via `startapp` |
| Bot `/start` | `TelegramUpdateHandler` matches `'/start'` **exactly**; `/start <payload>` is not handled | New payload router |
| Rate limiters | `framework.rate_limiter` (sliding windows per IP / per Telegram user) | New `claim_*` limiters |
| Migrations | Wallet migrations refuse `down()` when it would lose data (`abortIf(... 'Irreversible: ...')`) | Same rule |

Two facts that shape the design:

1. **There is no deep-link payload handling yet**, in the bot or the Mini App. S2 adds one router that referrals reuse.
2. **`AdminAuditLog` records admin actors only.** A claim is done by a customer and a link is made by a reseller, so
   ownership changes need their own append-only history table (`service_ownership_event`).

## 1. Data model

All money is integer Toman, like `order.amount` and the wallet. Percentages are integer **basis points**
(`1 bp = 0.01 %`), so no floats are stored.

### 1.1 `reseller` (S1)

| Column | Type | Rule |
|---|---|---|
| `id` | int PK | |
| `user_id` | int, FK `user.id` **ON DELETE RESTRICT**, **UNIQUE** | One reseller profile per customer account |
| `status` | varchar(16) | `active` \| `disabled` |
| `discount_bp` | int, `0..9000` | Default wholesale discount off the plan's base price |
| `daily_spend_limit` | int NULL | Max wholesale spent per day (app timezone); NULL = no cap (section 5) |
| `display_name` | varchar(64) NULL | Shown to the claimer ("from: …"); never the reseller's Telegram data |
| `note` | varchar(255) NULL | Admin note |
| `created_at`, `updated_at`, `disabled_at` | datetime | |
| `created_by_admin_id` | int NULL | |

Index: `(status)`.

The credit limit is **not** a reseller column: it is the existing `wallet.credit_limit`, set through
`WalletAdministration::setCreditLimit()` (`WALLETS_MANAGE`, audited `wallet.credit_limit`). The reseller edit page
shows it and links to the wallet page, so there is one source of truth.

### 1.2 `reseller_plan_price` (S1) — per-plan overrides

| Column | Type | Rule |
|---|---|---|
| `id` | int PK | |
| `reseller_id` | FK `reseller.id` ON DELETE CASCADE | |
| `plan_id` | FK `plan.id` ON DELETE RESTRICT | |
| `discount_bp` | int NULL, `0..9000` | Percent override for this plan |
| `fixed_amount` | int NULL | Fixed wholesale price; **fixed (non-customizable) plans only** |
| `updated_at`, `updated_by_admin_id` | | |

Unique `(reseller_id, plan_id)`. Exactly one of `discount_bp` / `fixed_amount` is set (checked in the service and by a
DB `CHECK` where the server supports it).

**DECISION NEEDED (D1) — the wholesale rule.** Recommendation: per-reseller `discount_bp`, optionally overridden per
plan by a percent or (fixed plans only) a fixed amount. A fixed amount on a customizable plan (price per GB × days)
has no meaning, so it is refused.

### 1.3 Floor price (S1)

- Setting `reseller.floor_bp` (default `5000` = 50 % of the plan's base price), plus
- New nullable column `plan.reseller_floor_amount` (fixed plans; for customizable plans the floor is
  `floor_bp` of the computed base).

Effective floor = `max(floor_bp × base, plan.reseller_floor_amount ?? 0)`. The wholesale price is never below it,
whatever discount the admin typed (section 2.2). `PlanPriceSanityChecker` gains a warning when a reseller rule would be
clipped by the floor.

**DECISION NEEDED (D2) — floor default.** Recommendation: 50 % global floor plus the optional per-plan floor amount.

### 1.4 Order and service attribution (S1)

`order`:

| Column | Type | Rule |
|---|---|---|
| `reseller_id` | int NULL, FK `reseller.id` ON DELETE RESTRICT | Set when a reseller bought it (new service, renewal, add traffic) |
| `retail_amount` | int NULL | What a retail customer would have paid at that moment (after the global discount, no code) |

Index `(reseller_id, status, created_at)` for the dashboard and reports.

`order.amount` stays **what is paid** = the wholesale price. `metadata.priceSnapshot` gains a `reseller` block:
`{resellerId, baseAmount, retailAmount, rule: "reseller"|"plan_percent"|"plan_fixed", discountBp, floorAmount,
floorApplied, roundedTo, wholesaleAmount}`. `PaymentStarter::payableAmount()` keeps reading `finalAmount`, which is the
wholesale amount, so settlement and wallet code paths are unchanged.

`vpn_service`:

| Column | Type | Rule |
|---|---|---|
| `sold_by_reseller_id` | int NULL, FK `reseller.id` ON DELETE RESTRICT | Set at provisioning from `order.reseller_id`; **kept after a claim** |

Index `(sold_by_reseller_id, status)`.

### 1.5 `service_claim` (S2)

| Column | Type | Rule |
|---|---|---|
| `id` | int PK | |
| `service_id` | FK `vpn_service.id` ON DELETE RESTRICT | |
| `reseller_id` | FK NULL | NULL when an admin made the link |
| `created_by_user_id` / `created_by_admin_id` | int NULL | Exactly one set |
| `token_hash` | char(64), **UNIQUE** | `sha256(token)` hex; the token itself is never stored or logged |
| `status` | varchar(16) | `open` \| `claimed` \| `revoked` \| `expired` |
| `open_service_id` | int NULL, **UNIQUE** | `= service_id` while `open`, NULL otherwise: at most one open link per service (MySQL/MariaDB have no partial index) |
| `expires_at` | datetime | Default now + 168 h (7 days); max 30 days (setting `reseller.claim_ttl_hours`) |
| `created_at`, `claimed_at`, `revoked_at` | datetime | |
| `claimed_by_user_id` | int NULL FK `user.id` | |
| `claimed_by_telegram_id` | varchar(32) NULL | As seen at claim time |
| `revoked_by_user_id` / `revoked_by_admin_id` | int NULL | |
| `panel_synced_at` | datetime NULL | Set when the panel client's `tgId`/`comment` were updated after the claim |

Indexes: `(reseller_id, status, created_at)`, `(status, expires_at)` for the expiry sweep.

Token: **32 random bytes** (`random_bytes(32)`, 256 bit), base64url without padding = 43 characters.
`claim_` + 43 = 49 characters, inside Telegram's 64-character limit for `start` / `startapp` and its
`[A-Za-z0-9_-]` alphabet. A plain SHA-256 is enough because the token has 256 bits of entropy (no dictionary to try);
lookup is by `token_hash` with an index, so there is no timing oracle on the token itself.

Making a new link for a service that has an open one **revokes the old one in the same transaction** (one open link per
service, enforced by the unique `open_service_id`).

### 1.6 `service_ownership_event` (S2) — append-only

`id, service_id, from_user_id, to_user_id, claim_id NULL, actor_type (customer|admin|system), actor_admin_id NULL,
reason varchar(255) NULL, created_at`. Index `(service_id, id)`. Rows are never updated or deleted. This is the
ownership history for support and for the reconcile check (4.4). Admin actions are **also** written to
`AdminAuditLog` (section 6.4).

### 1.7 Migrations and rollback

One migration per phase. Each `down()` follows the wallet migrations:

- S1 `down()`: `abortIf` any `reseller` row, any `order.reseller_id IS NOT NULL`, any
  `vpn_service.sold_by_reseller_id IS NOT NULL`, or any `plan.reseller_floor_amount IS NOT NULL`, with an
  `Irreversible: … Nothing was changed.` message. Otherwise drop columns, then tables.
- S2 `down()`: `abortIf` any `service_claim` or `service_ownership_event` row.
- Additive only (`ADD COLUMN … NULL`, new tables), so `up()` is online-safe on the existing tables; no backfill.
- Migration tests in the style of `WalletPlanMigrationTest`: down refused with data and nothing changed; down/up
  round trip on an empty database.

## 2. Pricing and purchase

### 2.1 Where the price comes from

A new `ResellerPricing` service (Shop/Application) is the **only** place the wholesale price is computed. It takes the
same inputs as retail (plan, traffic GB, days; for add-traffic the add-on plan) and returns a `WholesaleQuote`
(snapshot fields of 1.4). The client (bot callback data, Mini App JSON) sends only ids and options, **never an
amount**.

### 2.2 Formula (integer arithmetic)

```
base      = PlanPricingService base price (before any discount)          # renewals/add-traffic: their own base
retail    = base after the global discount (what a customer pays, no code)
rule_bp   = plan override discount_bp ?? reseller.discount_bp            # or plan fixed_amount → candidate = fixed_amount
candidate = base - intdiv(base * rule_bp, 10000)
candidate = ceil_to(candidate, reseller.rounding)                        # setting, default 1,000 Toman
floor     = max(intdiv(base * floor_bp, 10000), plan.reseller_floor_amount ?? 0)
wholesale = min(max(candidate, floor), retail)
```

- **No stacking**: discount codes and the global discount are never applied on top of the wholesale price. A code
  sent with a reseller order is refused (`discount_not_for_resellers`).
- `min(…, retail)`: a reseller never pays more than a retail customer during a shop-wide promotion.
- Rounding goes **up** (in the shop's favour) and happens before the floor check, so rounding can never take the
  price under the floor.
- `wholesale = 0` is refused (`price_unavailable`), like a broken plan today.

**DECISION NEEDED (D3) — reseller price during a global promotion.** Recommendation: `min(wholesale, retail)`, as
above (the reseller is never worse off than a customer; the promotion does not stack).

### 2.3 Flow (bot and Mini App share one application service)

`ResellerCheckout` (Shop/Application/Checkout), used by the bot handler and the Mini App controller:

1. Check: `reseller.enabled` setting on, reseller `active`, wallet not frozen, plan active and visible to resellers,
   daily cap not exceeded (section 5).
2. Quote with `ResellerPricing` and show it: plan, wholesale, retail, margin, wallet balance after.
3. On confirm (with an idempotency key, the Mini App `Idempotency-Key` header / a bot callback nonce):
   create the order (`reseller_id`, `retail_amount`, snapshot, `WAITING_PAYMENT`), then `WalletPayment::open()` +
   `pay()` **in the same request**. Reseller orders are **wallet-only**: if the wallet (balance + credit limit) does
   not cover it, no order is created and the reseller is sent to top up.
4. Settlement, provisioning, `PROVISIONING_FAILED` and retry are the existing ones. On provisioning,
   `vpn_service.user = reseller's user` and `sold_by_reseller_id = order.reseller_id`.

Renewal and add-traffic: same flow with `targetService`. Allowed when `targetService.sold_by_reseller_id` is this
reseller (**including after a claim**) and the service is not deleted. The renewal keeps today's rule (current plan
price, source order's traffic and duration) and applies the wholesale formula to it.

### 2.4 Price changes between order and payment

Because the order is created and paid in one request, the window is the request itself. The snapshot on the order is
authoritative for that payment (settlement compares the payment with `payableAmount()`, i.e. the snapshot). If an
order is left `WAITING_PAYMENT` anyway (crash between create and `open()`), it is **not** reused for a new attempt:
`IncompleteOrderExpiryService` expires it as today and the next attempt re-quotes. A reseller order is never paid by
a gateway, so a stale snapshot cannot be paid later from outside.

## 3. Claim flow (S2)

### 3.1 Create a link

- Who: the reseller, for a service where `sold_by_reseller_id = his reseller id` **and** `user_id = his user id`
  (still his); or an admin with `SERVICES_MANAGE`, for any live service (support case).
- Not claimable: trial services, services in `deleted` status, services whose order is not a reseller order, services
  with an unfinished order (`PROVISIONING_FAILED`, renewal in settlement).
- Output: `https://t.me/<bot>?start=claim_<token>` and `https://t.me/<bot>/<app>?startapp=claim_<token>`, plus a QR
  image. The token is shown **once**; the list shows status, service, expiry, never the token.
- Rate: `claim_create` limiter, 30 per hour per reseller.

### 3.2 Deep-link router (shared with referrals)

New `StartPayload` value object and `StartPayloadRouter` (Bot/Application): parses `/start <payload>` in the bot and
`start_param` in the Mini App, with a registry of handlers keyed by prefix (`claim_` in S2; `ref_` later). Unknown or
malformed payloads fall back to the normal `/start`. `TelegramUpdateHandler` changes from `'/start' === $text` to
"`/start` or `/start <payload>`". Payload syntax is validated (`^[a-z]+_[A-Za-z0-9_-]{1,58}$`) before any lookup.

### 3.3 What the claimer sees

1. A preview (no change yet): plan name, traffic left, expiry, the reseller's `display_name`, and two buttons:
   **Add to my account** / Cancel. Link preview bots and prefetches never claim, because the claim is a button press
   (bot callback / Mini App POST with CSRF), not the link open.
2. The required-channel gate and the normal account creation for a first-time user run first, as for `/start`.
3. On success: "The service is now in My services", the subscription page link and the usual service view.

Errors are one generic message for "unknown / expired / revoked / already claimed" to the claimer (no oracle), with
the specific reason in the server log and on the reseller's list.

### 3.4 What changes on claim (one transaction)

Lock order (every claim-link write; S2 review fixes): `service_claim` rows **by primary key** (found by a plain read,
locked by id in ascending order, checked again; never through `open_service_id` / `service_id` / `status`, which takes
the index entry before the row and deadlocks with a claim that takes the row before the index entry), then the
service's `order` / `payment` rows under a **shared** lock (`LOCK IN SHARE MODE`: the in-flight check sees every
settlement step committed before and keeps them from moving on until the change commits), then the `vpn_service` row.
Settlement locks order → payment → user → wallet and writes the service row last, so there is no cycle. Run in READ
COMMITTED with a bounded retry of deadlocks and lock wait timeouts, then "busy" (`ClaimTransactions`). No wallet row
is touched or locked.

Checks under the lock: claim `open`, `expires_at > now`, service still owned by the creator, service not deleted, no
order of that service in settlement, claimer ≠ creator.

Writes: `vpn_service.user_id = claimer`; `rotatePublicToken()`; claim → `claimed` (`claimed_at`, `claimed_by_*`,
`open_service_id = NULL`); one `service_ownership_event`. After commit: the panel update (below) and bot messages to
the claimer and the reseller.

**Panel client:** after commit, a best-effort job sets `tgId = claimer's Telegram id` and
`comment = "amoobot order #N, claimed"` with `ModernClientsApi::updateClient()` (modern mode). Legacy panels have no
`tgId` support today and are skipped. A failure is logged and retried by `app:claims:expire` (claimed rows with
`panel_synced_at IS NULL`, backing off); the database stays the source of truth for ownership.

**Subscription credentials. DECISION NEEDED (D4).** Recommendation: **rotate `public_token`** (the reseller had the
`/s/…` link) but **keep** the panel UUID / `subId` / config links, because the reseller has usually already put them
on the customer's phone. The claimer gets a "Reset my connection links" action (new UUID + subId through the existing
regeneration path), with a warning that installed apps must re-import. Rotating the panel credentials automatically
would break the customer's phone at the moment of handover.

### 3.5 After the claim: what the reseller can still do

Recommendation (decided unless D5 changes it): the reseller **can renew and add traffic at wholesale** for services he
sold (`sold_by_reseller_id`), because that is how resellers keep their customers, and it only adds value to the
customer's service. He sees, for those services only: plan, status, expiry, traffic, "claimed on <date>". He does
**not** see the claimer's Telegram id, name or username, the customer's other services, orders, payments, wallet, or
the subscription page / config links after the claim. He cannot suspend, delete, reset, regenerate, or make a new claim
link for a claimed service.

**DECISION NEEDED (D5).** Confirm the above.

### 3.6 Revoke, expiry, leaks, wrong person

- **Revoke** (reseller for his own links, admin for any): `open` → `revoked`, `open_service_id = NULL`. Idempotent.
- **Expiry**: checked at claim time; a cron (`app:claims:expire`, every 15 min) marks `open` past `expires_at` as
  `expired` so lists are honest and a new link can be made.
- **Leaked link before claim**: the reseller revokes and makes a new one.
- **Wrong person claimed**: no self-service undo. An admin "Return to reseller" action (`SERVICES_MANAGE`, reason
  required) moves ownership back, rotates `public_token`, writes an ownership event and an audit row. The admin may
  then reset the panel credentials (D4 action).
- **Two claims at once**: the row lock serializes them; the second sees `claimed` and gets the generic error.
  `open_service_id` UNIQUE makes "two open links for one service" impossible even under concurrent creation.

### 3.7 Rate limits and brute force

- `claim_attempt_user`: 10 claim previews or submits per 10 min per Telegram user; `claim_attempt_ip` for the Mini App
  route: 30 per minute per IP. Counted for invalid tokens too.
- 256-bit tokens make guessing infeasible; the limits protect the database and logs, and stop enumeration noise.
- Five invalid tokens from one Telegram user in an hour → an info line in the admin log channel (no automatic ban).

## 4. Money rules

### 4.1 Ledger types and keys

| Movement | Type | Key | Reference | Phase |
|---|---|---|---|---|
| Reseller pays an order | `purchase` (existing) | `purchase:payment:{paymentId}` (existing `WalletKeys::purchase`) | payment | S1 |
| Debit given back (reject / cancel / expire) | `reversal` (existing) | `reversal:payment:{paymentId}` | payment | S1 |
| Admin refund of a provisioned reseller order | `refund` (existing type, first writer) | `refund:order:{orderId}` (new `WalletKeys::orderRefund`) | order | S1 |
| Referral reward (later) | `reward` (existing type) | `reward:referral:{inviteeUserId}` | referral | later |

**DECISION NEEDED (D6) — ledger type for the reseller purchase.** The brief asks for new types. Recommendation:
**reuse `purchase`** with the existing key, and identify reseller purchases by `order.reseller_id`. A new
`reseller_purchase` type would fork `WalletPayment`, settlement and every existing reconcile check for no gain; the
reseller dimension lives on the order, where reports need it. New keys are added only for genuinely new movements
(order refund, later rewards). All keys stay unique in the ledger, so retries are idempotent.

### 4.2 Refund, cancel, failed provisioning

- **Before provisioning** (rejected, cancelled, expired wallet payment): the existing reversal, once.
- **Provisioning failed**: the debit stays held, as for every wallet payment; the admin retries (no new debit) or
  cancels the order, which reverses the debit (existing path, reconciled by `held_debit_order_closed`).
- **After provisioning**: no automatic refund. An admin with `WALLETS_MANAGE` can refund a reseller order **only after
  the service is suspended or deleted**, for **at most the wholesale amount paid**, once per order (unique key), with a
  reason; audited `reseller.order_refund`. Partial refunds are allowed up to that amount, but one refund row per order.
- A claimed service is not refunded to the reseller without the admin deciding; the customer's access is the
  reseller's deliverable.

### 4.3 Frozen wallet, disabled reseller

- **Frozen wallet**: no new reseller purchase, renewal or add-traffic (existing `wallet_frozen`). Claim links keep
  working (no money moves).
- **Disabled reseller**: no purchase, renewal, add-traffic, new links; open links are **revoked** in the same
  transaction as the disable; the reseller menu disappears; his Mini App sessions are revoked. Existing services keep
  running (they are paid). The customer-side services, claimed or not, are unaffected.
- Re-enabling does not restore revoked links.

### 4.4 Reporting and reconcile

Report (per reseller and in total, date range, confirmed orders only): orders by type, `SUM(order.amount)` = wholesale
paid, `SUM(order.retail_amount)` = retail value, **discount given** = retail − wholesale, refunds, claims made / open /
expired / claimed. The reseller's real resale price is unknown to the shop, so the report says "retail value", not
"reseller profit".

`app:wallet:reconcile` gains (read only, same snapshot transaction):

| Code | Finds |
|---|---|
| `reseller_order_not_wallet_paid` | A confirmed payment of an order with `reseller_id` whose method is not `wallet` |
| `reseller_order_below_floor` | `order.amount` < the snapshot floor, or ≠ snapshot `wholesaleAmount` |
| `reseller_service_attribution_mismatch` | `vpn_service.sold_by_reseller_id` ≠ its order's `reseller_id` |
| `refund_without_order` / `refund_exceeds_paid` | A `refund:order:N` row with no order N, or above that order's confirmed wallet debit |
| `refund_on_live_service` | A refund whose service is still active (warning) |
| `claim_owner_mismatch` | A `claimed` claim whose service owner differs from `claimed_by_user_id` with no later ownership event |
| `claim_open_owner_changed`, `claim_open_service_mismatch`, `claim_open_reseller_disabled`, `claim_without_ownership_event`, `ownership_event_owner_mismatch` | S2 as built (section 7): an open link that can never be used, an `open_service_id` out of step, an open link of a disabled reseller, a claim without its event, an owner other than the last event's |

## 5. Abuse and fraud

| Case | Mitigation |
|---|---|
| Selling below cost by stacking discounts | Codes and global discount never stack (2.2); floor after rounding; wholesale is computed server-side only |
| Discount codes on top of wholesale | Refused for reseller orders; reconcile `reseller_order_below_floor` |
| Admin typo (e.g. 90 % discount) | `discount_bp` max 9000, floor still applies; sanity warning on the edit page; audit row with before/after |
| Trial accounts resold or claimed | Trials are never claimable; reseller flows cannot create trials; trial limits per Telegram user unchanged |
| Claim farming (many accounts claiming) | Only reseller-sold, live services are claimable; one open link per service; per-user and per-IP limits; ownership history |
| Self-claim / referral loops | Claimer ≠ creator; later, a referral reward is never paid for an invitee who arrived through a claim link or whose first purchase was a reseller order (hook in 6.5) |
| Reseller account takeover (stolen Telegram session) | Every purchase needs explicit confirmation; optional `daily_spend_limit`; bot message to the reseller for every purchase, link and claim; admin disable revokes links and Mini App sessions at once; Mini App sessions already bound to Telegram id + UA hash |
| Reseller renews after claim while the customer also renews | Both are paid orders that each extend the service; renewal checks rights at order time under the order lock; no shared money row, so no race on money |
| Claim during a reseller renewal in settlement | Claim refuses while a service order is in settlement (3.4) |
| Credit-limit abuse (reseller runs up debt and disappears) | Credit limit is an explicit admin decision (`WALLETS_MANAGE`), default 0; daily cap; reports show negative balances |
| Leaked admin-made link | Same expiry, revoke and single-use rules |

**DECISION NEEDED (D7) — daily spend cap default.** Recommendation: NULL (no cap) by default, set per reseller by
the admin; the bot notification covers the common case.

## 6. Surfaces

### 6.1 Bot (S1 for buying, S2 for links)

For active resellers only, an extra main-menu button **"🧑‍💼 Reseller"**:

- Balance and credit (top-up uses the existing wallet top-up).
- **New service for a customer**: plan picker at wholesale (retail shown for comparison) → confirm → paid from the
  wallet → the service view with "Make claim link" (S2).
- **My sold services**: paged list (newest first, search by username); renew / add traffic at wholesale; claim link
  (S2) while still his.
- **Claim links** (S2): open links with expiry; revoke.

### 6.2 Mini App (S3)

`/app/reseller` section, shown in the nav for active resellers: dashboard (balance, credit, this month's wholesale
spent, retail value, services sold, open links), **New service**, **Sold services** (filters: mine / claimed /
expiring), service detail (renew, add traffic, claim link with QR and share button), **Claim links** (revoke). Claims
by customers enter through `startapp=claim_…` on the existing auth (S2 includes the minimal claim screen).

### 6.3 EasyAdmin (S1)

- **Resellers** CRUD: list (user, status, discount, balance, credit limit, services, last purchase), edit
  (status, `discount_bp`, display name, daily cap, note, plan overrides as a sub-form), enable/disable actions.
- **Reseller report** page (4.4) with CSV export.
- Order and service detail: "Sold by reseller" field; service detail lists its claims and ownership history (S2).
- Plan edit: `reseller_floor_amount`.

### 6.4 Permissions and audit

New `AdminPermission` cases: `RESELLERS_VIEW` (`PERM_RESELLERS_VIEW`) and `RESELLERS_MANAGE`
(`PERM_RESELLERS_MANAGE`). Matrix: SUPER_ADMIN all; ADMIN both; SUPPORT `RESELLERS_VIEW` only. Refunds also need
`WALLETS_MANAGE`; claim revert and admin-made links need `SERVICES_MANAGE`.

The reseller himself is **not** an admin. His rights come from a `ResellerVoter` (`RESELLER_ACT`, subject: the
current user), granted when `reseller.enabled` is on and his profile is `active`; used by the bot handler and the
Mini App controllers.

Audit actions (`AdminAuditLogger`, sources web/bot/miniapp/cli as today): `reseller.create`, `reseller.update`
(before/after of discount, cap, status), `reseller.enable`, `reseller.disable` (with the number of links revoked),
`reseller.plan_price`, `reseller.order_refund`, `claim.create_admin`, `claim.revoke_admin`, `claim.revert`.
Customer and reseller events live in `service_claim` and `service_ownership_event`.

### 6.5 Hook for referrals

- `StartPayloadRouter` handler registry: S2 registers `claim_`; referrals register `ref_`.
- A domain event `ServiceClaimed` (S2) and `FirstPaidOrderCompleted` (raised by settlement for a user's first
  confirmed non-top-up order; added with referrals) are the integration points.
- The reward is `WalletService::credit(..., REWARD, 'reward:referral:{inviteeUserId}', ...)`: one reward per invitee,
  by key.

## 7. Phases (each a small PR, off by default)

Settings: `reseller.enabled` (S1, default **false**) and `reseller.claims_enabled` (S2, default **false**). With them
off, every new route returns 404, no menu shows, and no behaviour changes.

### S1 — reseller core

- **Touches:** `src/Entity/Reseller.php`, `ResellerPlanPrice.php`; `Order` and `VpnService` new columns; `Plan`
  `reseller_floor_amount`; migration; `src/Reseller/Application/{ResellerPricing, ResellerCheckout, ResellerAdministration,
  ResellerSettings, ResellerReport}.php`; `ResellerVoter`; `WalletKeys::orderRefund`; `WalletReconciler` new checks;
  provisioning sets `sold_by_reseller_id`; bot reseller menu (buy, sold services, renew, add traffic);
  `AdminPermission` + matrix; EasyAdmin `ResellerCrudController`, report page; translations en/fa; docs.
- **Not touched:** `PaymentSettlementService`, `WalletService` internals, gateway code.
- **Tests:** pricing table tests (base, percent, plan percent, fixed, floor, rounding, promotion min, zero);
  checkout with frozen wallet / insufficient funds / disabled reseller / feature off; discount code refused;
  **parallel-process tests** (like `WalletParallelPaymentTest`): two reseller purchases racing on one wallet near its
  limit (only one succeeds, ledger consistent), a purchase racing an admin disable; refund idempotency (same key twice,
  two processes); reconcile tests for every new code; migration down refused / round trip; permission matrix
  (SUPPORT cannot edit, ADMIN can); audit rows.
- **Review checklist:** no amount accepted from the client; every money path ends in `WalletService` with a unique key;
  `order.amount == snapshot.wholesaleAmount ≥ floor`; feature-off = no behaviour change (test); migration `down()` refuses
  data loss; reconcile covers every new movement; no new query per row in lists.

### S2 — claim links

- **Touches:** `ServiceClaim`, `ServiceOwnershipEvent` entities + migration; `src/Reseller/Application/{ClaimLinks,
  ClaimService}.php`; `StartPayload`, `StartPayloadRouter` and the `/start` change in `TelegramUpdateHandler`;
  Mini App `start_param` handling and a minimal claim screen; panel `tgId`/`comment` update job; `app:claims:expire`
  command; rate limiters; admin revoke/revert actions; reconcile `claim_owner_mismatch`; translations; docs.
- **Tests:** token format and hash-only storage (the token never appears in DB, logs, audit); single use; expiry;
  revoke; generic error for all failure kinds; claimer = creator refused; trial not claimable; **parallel-process
  tests**: two processes claim one token (exactly one wins, one ownership event), claim vs revoke race, two link
  creations for one service (one open link); claim vs renewal in settlement; rate limits; required-channel gate runs
  first; `/start` without payload unchanged (snapshot test); panel update failure does not roll back the claim;
  admin revert.
- **Review checklist:** lock order claim → service, no money rows touched; no token in logs; one generic error;
  `public_token` rotated; ownership check (`CustomerServiceFinder`) is the only gate for the new owner; reseller loses
  link/config access after the claim.

#### S2 as built

Differences from the plan above, all decided while implementing it:

- Settings: `reseller.claims_enabled` (not `claim_enabled`) and `reseller.claim_ttl_hours`, default **168 h (7 days)**, 1..720. Claims work only with `reseller.enabled` on too.
- `service_claim` also has `from_user_id` (the owner the link moves the service away from, checked again at claim time: for an admin-made link the current owner) and, instead of `panel_synced_at` alone, `panel_sync_status` (pending / synced / skipped / failed), `panel_sync_attempts`, `panel_sync_next_at`, `panel_sync_error`, plus `expired_at` and `revoke_reason`. `reseller_id` is NULL for an admin-made link (a reseller disable revokes only his own links). CHECK constraints: the status values, and `open_service_id` set exactly while open.
- Confirm step: the preview looks the claim up by `token_hash`; the confirm button / Mini App form then carries the claim id and a **ticket** (HMAC over claim id, Telegram user id, token hash and issue time; 30 minutes), never the token. The claim transaction therefore locks the `service_claim` row **by id**, then `vpn_service`: the same lock order. A second delivery of the claimer's own confirmation answers "already yours" without writing.
- "In settlement" (`ServiceOrdersInFlight`): the service's own order or an order targeting it is paid / processing / payment_pending / provisioning_failed, or waits for payment with a payment submitted, in review or provisioning_failed, or a wallet payment pending (debit held). The claim answers `service_busy`, which is not the generic error (the person holds a valid link).
- Unknown or malformed payloads, and payloads whose handler is off, are **not routed**: the bot then does exactly what it did before (a plain `/start` is unchanged; `/start <anything else>` gets what any unknown text gets), so with claims off nothing changes.
- D4 "Reset my connection links": there was no credential rotation in the code, so drivers gained `CredentialRotationDriverInterface` (modern 3x-ui: new UUID and subId through `clients/update`, read back; password-based clients are refused); the links are then rebuilt by `ServiceConfigRegenerator`. Only for the owner whose ownership came from a claim, VLESS / VMess, no order in settlement.
- D5 enforcement: `PaymentCustomerNotifier` sends a renewal / add-traffic confirmation **without links** to a payer who no longer owns the service, and the usual message to the owner.
- The panel owner update is `ClientOwnerDriverInterface::updateClientOwner()` (modern driver: `updateAndVerify` with tgId and comment). Retries back off 5 min × 2^n up to 6 h, given up after 12 attempts.
- Tokens are masked in `bot_message_log` (`ClaimToken::redact`, in `BotMessageLogger`).
- Reconcile codes: `claim_open_owner_changed`, `claim_open_service_mismatch`, `claim_open_reseller_disabled`, `claim_without_ownership_event`, `claim_owner_mismatch`, `ownership_event_owner_mismatch`, reported with the payments by `app:wallet:reconcile`.
- Admin pages are `ClaimAdminController` (SERVICES_VIEW / SERVICES_MANAGE), not part of EasyAdmin CRUD. The "info line in the admin log channel" is a Telegram message to admins with LOGS_VIEW plus a log line.
- Not built: claim history in the reseller report (claims made / open / expired / claimed counts), the claimer's reset in the Mini App (bot only), a table row for a claimer's link reset (logged only).

### S3 — reseller Mini App

- **Touches:** `MiniAppResellerController`, templates `miniapp/reseller/*`, nav entry, app.js only if a new widget is
  needed, translations, screenshots (`docs/screenshots/miniapp-reseller/`).
- **Tests:** role gating (non-reseller 404, disabled reseller 404); CSRF + `Idempotency-Key` on purchase (a double tap
  is one order, parallel-process test); no customer data of claimed services in any response; query budget for the
  dashboard; translations complete.
- **Review checklist:** reuses `ResellerCheckout` and `ClaimLinks` (no logic in controllers); same responses as the
  bot for the same state; screenshots light/dark at 390 px.

## 8. Open questions (prioritized)

1. **D1/D2** Wholesale rule and floor: per-reseller percent + per-plan percent/fixed, global floor 50 % + per-plan
   floor amount — confirm the numbers.
2. **D4** On claim: rotate only the subscription page token (recommended) or also the panel credentials?
3. **D5** After a claim, may the reseller still renew / add traffic at wholesale (recommended yes)?
4. **D6** Reuse the `purchase` ledger type for reseller purchases (recommended) instead of a new type?
5. **D3** During a shop-wide promotion, should the reseller pay `min(wholesale, retail)` (recommended)?
6. Who may become a reseller: only admins create profiles (recommended), or a request button in the bot?
7. Should resellers be allowed to sell on credit at all (wallet `credit_limit` > 0), and what maximum?
8. **D7** Daily spend cap: none by default (recommended) or a default value?

## DECISION NEEDED summary

| Id | Decision | Recommendation |
|---|---|---|
| D1 | Wholesale rule | Per-reseller `discount_bp`, per-plan override by percent or (fixed plans) fixed amount |
| D2 | Floor | `max(50 % of base, plan.reseller_floor_amount)`, applied after rounding up to 1,000 Toman |
| D3 | Promotion | `wholesale = min(max(candidate, floor), retail)`; no stacking with codes or the global discount |
| D4 | Credentials on claim | Rotate `public_token`; keep panel UUID/subId; claimer can reset links |
| D5 | Reseller after claim | Renew / add traffic at wholesale; no access to customer data or links |
| D6 | Ledger type | Reuse `purchase` + existing keys; new key `refund:order:{id}`; later `reward:referral:{userId}` |
| D7 | Daily spend cap | Off by default; per-reseller setting |
