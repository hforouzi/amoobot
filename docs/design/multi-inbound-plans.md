# Plans with several inbounds of one panel — design

Status: **design for review**, nothing implemented. Persian summary: [docs/fa/design/multi-inbound-plans.md](../fa/design/multi-inbound-plans.md).

**Requirement.** An admin attaches several inbounds of the **same panel** to a plan. A customer who buys it gets **one
client** attached to all of them. Multi-panel plans are out of scope. Existing plans and services keep exactly one
inbound and behave exactly as today.

Items marked **DECISION NEEDED** are decided here with a recommendation; the owner confirms them in section 8.

## 0. What the code has today

| Area | Today | Consequence |
|---|---|---|
| `Plan.inbound` | One `VpnInbound` (ManyToOne, SET NULL); `Plan::isAvailableForSale()` checks that inbound and its panel | Kept as the plan's **primary** inbound |
| `VpnService` | `panel`, `inbound` (one), `remoteId`, `clientUuid`, `clientEmail`, `subId`, `subscriptionUrl`, `configLinks`, `configText` | Kept; extra inbounds go in a new table |
| Remote id | `sanaei_3xui\|panel=..\|inbound=<local>\|remoteInbound=<remote>\|uuid=..\|email=..\|subId=..` (`Sanaei3xuiRemoteIdParser`) | **Unchanged** (primary inbound) |
| Modern API (`ModernClientsApi`) | Client-centric: `clients/add {client, inboundIds: [...]}`, `clients/{email}/attach {inboundIds}`, and `get/update/del/resetTraffic/traffic/links/{email}`, `subLinks/{subId}`, `clients/list` (with traffic) — all by **email** | One client row spans its inbounds; every operation after creation is by email |
| Modern driver (`Sanaei3xuiModernDriver`) | `createService` sends `inboundIds: [one]`, GETs the client, `attach`es the inbound if `inboundIds` lacks it, adopts by UUID after a lost answer; renew / traffic / suspend / activate / reset / delete / usage all by email | Multi-inbound needs changes only at **create / adopt / attach**; the rest already covers all inbounds |
| Legacy driver (`Sanaei3xuiDriver`) | Inbound endpoints: the client lives in one inbound's `settings.clients`; usage per inbound | No shared client across inbounds: **not supported** |
| Links | Modern create stores the panel's links (`links/{email}` + `subLinks/{subId}`); `VpnAccessLinkGenerator` builds links from **one** inbound (fallback, and "regenerate config" for non-legacy services) | Generator must loop over all inbounds, or prefer the panel's links |
| Usage sync | `getUsages()` reads `clients/list` traffic per email | Already one figure per client |
| Bot / Mini App / `/s/{token}` | Show `configLinks`, `subscriptionUrl`, QR; the bot's service detail shows the primary inbound's host/country/protocol | Show every server; links already a list |
| Trials | `TrialPlan.inbound` (one); `TrialAccountService` copies it into a hidden plan | Unchanged in this design (Q5) |
| Admin | `PlanCrudController` `AssociationField('inbound')`; Mini App `CatalogInput` field `inbound`; panel setup wizard links a plan to one inbound | Add an "additional inbounds" field |
| Tests | `tests/Support/Fake3xui/Fake3xuiPanel` already models `clients/add` with `inboundIds`, `attach` and `detach` | Most tests need no new fake |

## 1. One client, several inbounds: what is shared

**What the code and API shape prove** (MHSanaei/3x-ui clients API, which `ModernClientsApi` targets):

- A client is **one record keyed by email**, with `inboundIds` listing its inbounds. `add` takes the inbound list; `update`
  replaces the record (`totalGB`, `expiryTime`, `enable`, `limitIp`, `id`, `password`, `flow`, `subId`, ...), so
  **one UUID, one email, one subId, one quota, one expiry, one enable flag**.
- Traffic is read per email (`clients/traffic/{email}`, `clients/list`); Xray counts user traffic by email, so usage on
  every attached inbound adds up to **one shared quota**. `resetTraffic` and `del` are by email.
- `subLinks/{subId}` returns the links of a subscription, so **one subscription URL** (`SubscriptionUrl::build(panel,
  subId)`) lists every attached inbound.

**Must be verified on a real panel** (checklist in 7.3), per 3x-ui version the owner runs:

- usage on inbound B counts toward the quota and stops the client on all inbounds when exhausted or expired;
- `del/{email}` removes it from every inbound; `update` keeps `inboundIds` (it is read-only in our merge);
- `subLinks` and the subscription URL list every attached inbound;
- `attach` of an already attached inbound is harmless; whether `detach` exists (the fake models it; our API client has
  no method yet).

**Mixed protocols.** One record carries one `id` (UUID: VLESS, VMess), one `password` (Trojan, Shadowsocks; the panel
generates it when not sent) and one `flow`.

- VLESS + VMess + Trojan on one client: expected to work (different fields of the same record). Verify.
- `flow` (e.g. `xtls-rprx-vision`) is valid only on VLESS over TCP+TLS/REALITY (`inboundOptions.tlsFlowCapable`). One
  client with vision on a flow-capable inbound and also on a WS inbound: must be verified that the panel applies flow
  only where it is valid.
- Shadowsocks: the method is per inbound (`inboundOptions.ssMethod`); 2022 methods need a key of a method-specific
  length, so one password cannot serve two SS-2022 inbounds with different methods.

**DECISION NEEDED (D1).** Recommendation: allow VLESS, VMess and Trojan freely; allow Shadowsocks only when every SS
inbound of the plan has the same method; refuse (plan save) a VLESS-vision primary combined with non-flow-capable VLESS
inbounds until the real-panel check says the panel strips flow per inbound.

## 2. Data model

### 2.1 Plans

- `plan.inbound` stays: the **primary** inbound (required for a multi-inbound plan; every current reader keeps working).
- New `plan_additional_inbound` (`id`, `plan_id` FK CASCADE, `inbound_id` FK RESTRICT, `sort_order` int,
  `created_at`), unique `(plan_id, inbound_id)`. Holds only the **additional** inbounds, never the primary.
- `Plan::getInbounds()`: primary first, then additional by `sort_order`.
- Invariants (checked on save, web and Mini App): every additional inbound is on the primary's panel, is active, is not
  the primary; the panel is in modern mode.

Why "additional" instead of moving everything into one join table: no backfill, no second source of truth for the
primary, every existing plan is untouched (zero rows), and rolling back the feature is "the table is empty".

### 2.2 Services

- `vpn_service.inbound` and `remoteId` stay: the primary.
- New `vpn_service_inbound` (`id`, `service_id` FK CASCADE, `inbound_id` FK RESTRICT, `remote_inbound_id` varchar
  snapshot, `status` `attached|pending|failed|detached`, `attempts` int, `last_error` varchar NULL, `attached_at`,
  `updated_at`), unique `(service_id, inbound_id)`. One row per **additional** inbound the service should have.
- The remote id keeps one inbound: modern operations address the client by email, so nothing reads the extra inbounds
  from it, and a legacy-format remote id stays parseable. The table, not the remote id, is the record of attachments.

### 2.3 Migration and rollback

One additive migration: two new tables, no change to existing columns, no backfill (existing plans and services are
single-inbound by definition: no rows).

`down()` refuses (nothing changed) while either table has a row: dropping them would lose which inbounds a plan sells or
a service holds, and services would keep extra inbounds on the panel that the bot no longer knows about.

## 3. Provisioning and service operations

### 3.1 Modern mode only

A plan with additional inbounds requires the panel in modern mode.

- **Plan save:** refused on a legacy panel, with the message "Several inbounds need the panel in modern mode
  (Panels → test connection)".
- **Panel switched to legacy later:** provisioning of such a plan fails closed with the same message
  (PROVISIONING_FAILED, admin alert). It never silently provisions the primary only. The plan stops selling
  (`isAvailableForSale()` false) while the panel is legacy.

### 3.2 Create

`CreateVpnServiceRequest` gains `additionalInbounds` (list of `VpnInbound`). The modern driver:

1. `clients/add {client, inboundIds: [primary, ...additional]}` (one call).
2. `GET clients/get/{email}`, then for each expected inbound missing from `inboundIds`, `attach` the missing ones (one
   call).
3. `GET` again. Inbounds still missing are returned as **failed** in `CreatedVpnService.attachments`. The primary
   missing is an error, as today.

`VpnProvisioningService` writes one `vpn_service_inbound` row per additional inbound (`attached` or `failed` with the
error). Links come from `links/{email}` + `subLinks/{subId}` (every attached inbound).

**Partial failure.** The primary works, so the service is ACTIVE and the order is provisioned (the customer has paid and
can connect). Failed attachments:

- are retried by `app:services:attach-inbounds` (scheduled, backoff, max attempts);
- can be retried from an admin "retry attach" button;
- are alerted to the admins once per service (like stuck payments);
- show on the admin service page.

**DECISION NEEDED (D2).** Recommendation as above (deliver the primary, retry the rest). The alternative, failing the
whole provisioning, would block a paid customer over one secondary inbound.

**Idempotent retry.** Every step is idempotent:

- `add` keeps today's adopt-by-UUID rule after a lost answer;
- `attach` is preceded by a GET and sends only the missing ids;
- the rows are upserted by `(service_id, inbound_id)`;
- a retry of a whole create (settlement retry) finds the client by UUID and only attaches what is missing.

**Adopt (`findExistingService`)**: same attach-missing step, only for a client matched by UUID (unchanged rule).

### 3.3 Other operations

| Operation | Modern, several inbounds |
|---|---|
| Renew, add traffic, suspend, resume (`updateAndVerify` by email) | Unchanged: one record |
| Reset usage, usage sync | Unchanged: by email |
| Delete | Unchanged: `del/{email}` (verify it clears every inbound); rows set `detached` |
| Regenerate links | Modern: re-read `links/{email}` + `subLinks`; `VpnAccessLinkGenerator` fallback loops over primary + attached rows (VLESS/VMess from the UUID; Trojan/SS need the password from the client GET) |
| Panel doctor / health | Lists services with `failed`/`pending` attachments |

## 4. Changing inbounds after sales

- **Editing a plan's inbounds affects new sales only.** Existing services keep what they have.
- **"Apply to existing services"** (explicit admin action on the plan, PLANS_MANAGE + SERVICES_MANAGE):
  - creates `pending` rows for the plan's live services missing an inbound;
  - a background job attaches them in batches, with progress (done / failed / remaining), retries and one audit entry
    per run (`plan.inbounds_applied`) plus per-service results;
  - it never touches suspended or deleted services unless the admin ticks "include suspended".
- **Removing an inbound from a plan:** new sales only. Detaching it from existing services is a separate action, built
  only after `detach` is verified on the owner's panel version (until then: rows stay, the admin page says "still
  attached on the panel"). **DECISION NEEDED (D4)**.
- **Inbound disabled or deleted on the panel** (inbound sync sets `isActive=false` or reports it missing):
  - the plan stops selling (`isAvailableForSale()` checks every inbound, D3);
  - the plan list shows "inbound X inactive";
  - existing services are unchanged (their record on the panel is the panel's business);
  - inbound sync never deletes rows.
- **Deleting a local inbound** that a plan or service references is refused (FK RESTRICT) with an admin message
  listing the plans.

## 5. Delivery

- **Links / QR.** `configLinks` holds every attached inbound's link (from the panel); the bot and Mini App show them as
  today (a list), each with its own QR. The subscription URL (one) is the recommended way and covers all inbounds.
- **Subscription page `/s/{token}`.** Unchanged: it shows the stored subscription URL, its QR and the configs list.
- **Bot service detail / Mini App service view.** "Servers: <remark/host, protocol> × N" instead of one host line,
  read from primary + attached rows.
- **Trials.** Unchanged (`TrialPlan` keeps one inbound) in this design; Q5.
- **Reseller pricing.** Unchanged: the price is the plan's; inbounds do not change it.
- **Claim links (S2).** The claim updates `tgId`/`comment` with `update/{email}` (one record): works unchanged. "Reset
  my links" regenerates UUID/subId on the one record.

## 6. Admin UI

- **Plan form (web and Mini App):**
  - **Primary inbound:** the current field.
  - **Additional inbounds:** a multi-select listing only active inbounds of the primary's panel (title, protocol, port),
    excluding the primary.
  - **Server-side validation:** same panel, active, modern mode, protocol rules (D1).
  - **Warnings:**
    - a mix that needs the real-panel check;
    - an inbound disabled since it was chosen;
    - the panel not in modern mode.
- **Plan list:** an "Inbounds: N" column; a warning badge when any is inactive.
- **Service page:** attached inbounds with their status, and a "Retry attach" button (SERVICES_MANAGE).
- **Panel inbounds page:** "Used by plans" (primary / additional) and the count of services attached.
- **Feature switch:** `plans.multi_inbound_enabled`, default **off**. While off:
  - the field is hidden and refused server-side;
  - provisioning ignores additional inbounds;
  - nothing changes for anybody.

## 7. Risks, tests, phasing

### 7.1 Risks

- **Panel behaviour differs by 3x-ui version** (shared quota, flow per inbound, SS keys): real-panel checklist before
  enabling; feature off by default.
- **Partial attach leaves a paid customer with fewer servers:** visible state, retries, alert.
- **"Apply to existing" on many services hits the panel hard:** batches, pause between calls, resumable job.
- **Links from the panel missing for some inbounds:** generator fallback per inbound; subscription URL as primary
  delivery.

### 7.2 Automated tests (each phase)

- **Migration:**
  - up/down on empty tables;
  - down refused with a row, nothing changed.
- **Plan validation:**
  - other panel, inactive, primary repeated, legacy panel;
  - protocol rules;
  - feature off.
- **Create (Fake3xui):**
  - one `add` with all ids;
  - attach-missing after a panel that drops one;
  - lost answer then adopt by UUID;
  - a retry attaches only what is missing (no duplicate calls);
  - primary missing is an error;
  - rows written.
- **Partial failure:** service active, failed row, alert once, retry command attaches it, and attempts are capped.
- **Operations:** renew/suspend/resume/delete/reset send one request by email (no per-inbound loop).
- **Links:** stored from `links` + `subLinks`; generator fallback covers every inbound.
- **Apply to existing:**
  - pending rows;
  - job progress;
  - audit;
  - idempotent re-run;
  - suspended skipped unless chosen.
- **Display:** bot detail / Mini App / `/s/{token}` list every server; existing single-inbound snapshots unchanged.
- **Inbound sync:** a disabled additional inbound stops sales; no row deleted.

### 7.3 Real-panel manual checklist (owner's 3x-ui version, before enabling)

1. Create a plan: VLESS-REALITY primary + VMess-WS + Trojan; buy it; one client in the panel's client list with three
   inbounds, one email, one UUID, one subId.
2. Connect through each inbound; usage from all three adds up on the one client.
3. Exhaust the quota through inbound 2: the client stops on all three. Same for expiry.
4. Suspend, resume, renew, add traffic, reset usage from the bot/admin: reflected on all three.
5. The subscription URL imports all three configs; `links/{email}` returns three links.
6. Delete: the client is gone from every inbound.
7. VLESS-vision + VLESS-WS on one client: both connect (flow applied only where valid) — or note that it fails.
8. Two Shadowsocks inbounds (same method, then different 2022 methods): record what works.
9. `attach` an already attached inbound: no error, no duplicate; `detach` exists and works (or not).

### 7.4 Phases (each a small PR, safe with the switch off)

| Phase | Content | Safe because |
|---|---|---|
| M1 | Tables + migration, `Plan::getInbounds()`, the switch, plan form field (web) with validation, plan list column, panel inbound "used by" | Switch off: field hidden, provisioning unchanged |
| M2 | Provisioning: create/adopt with all ids, attach-missing, `vpn_service_inbound` rows, retry command + admin retry, alert, links from panel, generator fallback loop, `isAvailableForSale()` over all inbounds, display (bot, Mini App, service page) | Only plans with additional inbounds (none while off) take the new path |
| M3 | "Apply to existing services" job with progress, audit; Mini App admin plan field | Explicit admin action |
| M4 (optional) | Detach (after verification), trials with several inbounds | Separate decisions |

## 8. Open questions (prioritized, with recommendations)

1. **D1 — mixed protocols.** Allow VLESS/VMess/Trojan freely, Shadowsocks only with one method per plan, and refuse
   vision + non-flow-capable VLESS until checklist item 7 passes? *Recommended: yes.*
2. **D2 — partial attach at purchase.** Deliver the primary and retry the rest (service active, admin alerted), rather
   than fail the order? *Recommended: deliver and retry.*
3. **D3 — an additional inbound inactive at sale time.** Stop selling the plan (all-or-nothing promise), rather than sell
   with fewer servers? *Recommended: stop selling and show a warning.*
4. **D4 — removing an inbound from existing services.** New sales only; detach existing services only after `detach` is
   verified on your panel version? *Recommended: yes.*
5. **Trials.** Keep trial plans single-inbound for now? *Recommended: yes; revisit after M2 is live.*
6. **Panel version.** Which 3x-ui version(s) run in production? The checklist (7.3) must pass on each before the switch
   is turned on. *Recommended: run it on a staging panel of the same version.*
