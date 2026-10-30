# Plans with several inbounds of one panel — design

Status: **design for review**; phases **H0**, **M1** and **M2a** (section 9.7) are implemented, see "H0 as built", "M1 as built" and "M2a as built". Persian summary: [docs/fa/design/multi-inbound-plans.md](../fa/design/multi-inbound-plans.md).

**Updated 2026-10-29: 3x-ui Hosts.** New section 9 covers Hosts. Lines changed elsewhere are marked **[Hosts]**. Where they
disagree, section 9 overrides the phase plan (7.4) and adds to the open questions (8).

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
| Links | Modern create stores the panel's links (`links/{email}` + `subLinks/{subId}`); `VpnAccessLinkGenerator` builds links from **one** inbound (fallback, and "regenerate config" for non-legacy services) | Generator must loop over all inbounds, or prefer the panel's links. **[Hosts]** The generator knows nothing about Hosts. Decision (9.1): the panel's `subLinks` is the source of truth; see 9.1 and the phase-0 bug in 9.7 |
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
| Regenerate links | Modern: re-read `links/{email}` + `subLinks`; `VpnAccessLinkGenerator` fallback loops over primary + attached rows (VLESS/VMess from the UUID; Trojan/SS need the password from the client GET). **[Hosts]** Replaced by 9.1: modern = `subLinks` only; no generator fallback for an inbound that has Hosts |
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
  **[Hosts]** One link per (inbound × usable host); count cap and refresh rules in 9.2 and 9.3.
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

The exact steps for 3x-ui v3.11.0, and which of them must pass before `plans.multi_inbound_enabled` is turned on, are
in the admin guide: [admin-guide.md, "Before turning the switch on"](../en/admin-guide.md#before-turning-the-switch-on-real-panel-checklist-3x-ui-v3110).

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

**[Hosts]** Superseded by 9.7, which puts phases H0 to H2 before M1. The M-phases below are unchanged in content.

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
   is turned on. *Recommended: run it on a staging panel of the same version.* **[Hosts]** Answered: v3.11.0. The
   Hosts questions are in 9.8.

## 9. Hosts (new, 2026-10-29)

Production runs 3x-ui **v3.11.0**: 27 hosts, 11 enabled. Sources are labelled throughout:

- **[code]** read in the MHSanaei/3x-ui source at tag `v3.11.0`, mainly `internal/sub/host_sub.go`, `host_order.go`,
  `links.go`, `service.go`, `internal/web/controller/host.go`, `client.go`, `api.go`, `internal/database/model/model.go`
  (`Host`, `Client.ClientHostRuleIds`), `internal/web/service/host.go`;
- **[doc]** the official API reference `docs/content/docs/en/reference/api/hosts.mdx` and
  `operations/multi-node.mdx`;
- **[ours]** this repository;
- **[unverified]** an assumption. Each one is listed in 9.6 step 9 and must be checked on the real panel.

### 9.0 What the panel does with Hosts [code]

- **Storage.** A host group (`groupId`) is stored as one `hosts` row **per inbound × address**. The "Address"
  multi-value field (`HostGroup.hosts[]`, each item may carry `:port`) produces one row per address
  (`buildHostRows`). Each row has:
  - its own `id`, `sortOrder`, `isDisabled`, `excludeFromSubTypes`;
  - overrides: `security` (`same|tls|none|reality`), `sni`/`snis[]`, `hostHeader`, `path`, `alpn`, `fingerprint`, …
- **Links per host.** For each inbound, every row that is enabled and not excluded for the format renders **one link**
  (`subscriptionHostVariants`, `hostEndpoints`). The row is turned into an `externalProxy` entry that *replaces* the
  inbound's own `externalProxy` (`injectExternalProxy`). So **hosts supersede legacy externalProxy** when at least one
  row applies. With none, the panel falls back to the legacy path "byte-identical".
- **Not one fixed link per host.** One host can render a link that varies between requests:
  - **CIDR address:** an address in CIDR form renders `randomCount` (1–100) random IPs from the range, each its own
    link, with remark suffixes " - 1", " - 2", ….
  - **SNI pool:** with more than one SNI, each request picks one at random (`randomSni`), or one per group when
    `sniSharedRandom` is set.
  - **Remark:** the host remark is a template expanded per client (`renderHostRemark`, vars such as `{{HOST}}`).
  - **VLESS route:** `vlessRoute` rewrites bytes 6–7 of the **UUID inside the link** (`applyVlessRoute`). The UUID in
    the link then differs from the client's UUID.
- **Fields stored but not used by the v3.11.0 subscription renderers.** `isHidden`, `shuffleHost` and `nodeGuids` are
  not referenced anywhere in `internal/sub` [code].
- **Per-client binding.** `Client.clientHostRuleIds` (host **row** ids) restricts a client to those rows, one link each:
  - `nil` keeps the stored binding;
  - an empty list clears it;
  - a binding to a deleted row "fails closed" on that inbound (`host_tombstones`): **no link** at all on that inbound.
- **Sub formats** are exactly `raw`, `json` and `clash`. `excludeFromSubTypes` holds these strings.
- **Ordering.** `subHostSortMode`, default `inbound-first`:
  - `inbound-first`: links follow inbound order, then each inbound's hosts by `sort_order, id`;
  - `hosts-first`: host groups by `sortOrder, remark, groupId`, then inbound `subSortIndex`, inbound id, host id.
- **Address of a host-less inbound** (`resolveInboundAddress`), first that is set: node address / listen /
  `shareAddr` (by `shareAddrStrategy`), then the panel's Sub domain, then its Web domain, then **the Host header of the
  request**.
- **The API is the subscription.**
  - `GET panel/api/clients/subLinks/{subId}` runs the same `getSubs` as the public `/sub/{subId}` (raw format). It
    includes per-client bindings and per-client external links. It never adds the "info node" dummy link
    (`subscriptionBody` is false for the API).
  - `GET panel/api/clients/links/{email}` applies hosts too, **per inbound** (`LinksForClient`).
- **Who can call it.** Hosts endpoints are under `panel/api/hosts`, Bearer token, `admin` scope. The `monitor` and
  `node-sync` scope allow-lists contain neither `/hosts/*` nor `/clients/subLinks` or `/clients/links`.

**What the bot does today [ours]**:

- **Create (modern).** Stores `links/{email}` ∪ `subLinks/{subId}` (`Sanaei3xuiModernDriver::fetchLinks`), so new
  services get Hosts-correct links.
- **Display** (`FinalConfigLinkProvider`). For `sanaei_3xui` with stored links, shows the stored links only: a
  **snapshot from creation time**. It is never refreshed when hosts change.
- **"Regenerate config"** (`ServiceConfigRegenerator`), the claim "reset my links" (which calls it),
  `app:service:regenerate-config` and `app:inbound:regenerate-service-configs`. On a **modern** service these call
  `VpnAccessLinkGenerator`, which builds from the inbound + legacy `externalProxyList` and ignores Hosts:
  - the regenerator **adds** those links to the panel's ones (dedupe is exact text without the fragment);
  - the two commands **replace** the stored links with them.

  Result: links to an inbound address or legacy proxy the panel no longer advertises, a missing host list, and a VLESS
  route lost. This is the **phase-0 bug**.
- **Legacy (`Sanaei3xuiConfigGenerator`).** Reads only `externalProxy`; Hosts are out of reach of the legacy API.

### 9.1 Source of truth for customer links — DECISION (H1)

**Decision: (b), fetched through the authenticated API, with a stored last-known-good snapshot.**

- **What is stored.** `vpn_service.configLinks` / `configText` = the parsed lines of
  `GET panel/api/clients/subLinks/{subId}`. Fall back to `links/{email}` only when the service has no subId.
- **Who reads it.** The bot's config button, the `/s/{token}` list, the per-link QR codes and copy all read the
  snapshot (as today). The subscription URL and its QR stay the panel's public URL (unchanged).
- **Never fetched from the bot:** the public `/sub/{subId}` URL. Its side effects and variations, all verified [code]:
  HWID enforcement, recording the fetch, format chosen from the User-Agent, base64 body, the info-node dummy link.
  The API gives the same `getSubs` output over the connection and token the bot already trusts (`panel.base_url`), so
  there is **no new outbound URL and no SSRF surface**.
- **Our generator.** `VpnAccessLinkGenerator` / `Sanaei3xuiConfigGenerator` are no longer used for modern 3x-ui
  services. They are kept unchanged for legacy panels and non-3x-ui drivers.

**Why**

| | (a) generate ourselves | (b) panel output | (c) hybrid: panel, else generate |
|---|---|---|---|
| Correct today | Must reimplement everything in 9.0: CIDR, SNI pools, remark templates, VLESS route, bindings, tombstones, sort modes, ECH/pinning/mux/finalmask, node addresses | Yes by construction: same code as the customer's subscription | Same as (b) until the panel fails |
| Future panel versions | Breaks silently on every new host field (v3.11 already has 30+) | Follows automatically | Fallback output silently diverges |
| Panel down | Works (inbound data cached) | Last snapshot keeps being shown; refresh retried | Shows **wrong** links when the panel is down: the worst moment to mislead |
| Security | No panel call | Same authenticated client as provisioning; no new egress; no subId URL fetched | Same as (b) |
| Rotation / claim (S2, D4) | Must rebuild from the new UUID (today's bug) | Rotation already reads `subLinks` back after `clients/update` | — |

Rejected:

- **(a)**: correctness and maintenance; the screenshots alone show more host behaviour than the bot could track.
- **Fetching the public sub URL**: side effects, format negotiation, base64. It also needs a second URL allow-list,
  because the sub domain may sit behind a CDN.
- **(c) with generation as fallback**: when the panel cannot be reached, a stale but correct snapshot is better than a
  fresh but wrong set.

**Rule kept from (c):** if no snapshot exists *and* the panel is unreachable at creation, the order still succeeds (as
today) and the customer gets the subscription URL only. The snapshot is filled by the refresh job (9.3). The generator
is used only for an inbound the Hosts API reports with **zero hosts** *and* only when the panel is unreachable
(byte-identical legacy path [code]). With the Hosts API unknown (permission or old panel), no generator fallback runs.

**Performance.** One GET per service per refresh, no fetch on page view. Existing HTTP client limits: 3 s connect, at most 10 s per request, GET retries only for transient failures. The
`/s/{token}` page never waits on the panel.

**Exposing the panel's own address.** A host-less inbound with no listen/shareAddr and no Sub/Web domain gets the
**Host header of our API request**: the panel's base URL host, often `127.0.0.1` or a private name [code]. Today
`CustomerAccessGuard` withholds such links and alerts the admins once [ours]. That stays the last line of defence. The
admin preview (9.4) warns before sale. We never rewrite a link's address: that would be (a) again.

**Token rotation and claim (S2, D4).**

- `public_token` rotation does not touch panel links: unchanged.
- "Reset my links" (`rotateCredentials`) already reads the new links from `subLinks` after the update [ours]. The
  follow-up `ServiceConfigRegenerator` call must stop merging generated links (phase H0). Otherwise the old UUID is
  gone, but host-less links of the new UUID are added.
- **VLESS route:** a link's UUID may differ from `clientUuid` in bytes 6–7. Any code that matches links to a client by
  UUID must compare with those bytes masked (9.6).

### 9.2 Link-generation rules (needed for the preview and the verifier only)

Under (b) the bot never emits these links. The rules below are what the admin preview (9.4) predicts and what the
verifier (9.6) checks. If the panel disagrees, the panel wins and the verifier reports the difference.

- **Included hosts.** For each attached inbound, take the rows with `isDisabled=false` and `raw` not in
  `excludeFromSubTypes`. If the client has `clientHostRuleIds`, keep only those rows of this inbound. If the binding
  points only at deleted rows of this inbound, the inbound gives **0** links.
- **Count per row:** 1, or `max(randomCount,1)` when the address is a CIDR.
- **Inbound with no usable row:** 1 link on the legacy path. That is one link per legacy `externalProxy` entry if any,
  else one for the inbound address (9.0).
- **Not counted:** inbounds with `excludeFromSub` (no link); the client's own "external links" are counted separately.
- **Ordering:** as `subHostSortMode` (9.0). The preview reads the setting; it does not guess.
- **Remark:** the host remark template expanded per client; legacy rows use the panel's remark composition. The
  preview shows the raw template.
- **Override per field.** Applied over the inbound's stream; empty or false means "inbound value"
  (`hostToExternalProxyMap`, `applyEndpointHostPath`):
  - address, port;
  - security (`same` = the inbound's);
  - SNI (`overrideSniFromAddress` → the address; `keepSniBlank` → none);
  - fingerprint, ALPN, cipher suites, pinned cert SHA-256, verify-by-name, allowInsecure, ECH;
  - Host header and path: **only on transports that already carry them** (ws, httpupgrade, xhttp);
  - mux, sockopt, finalmask (json/clash only);
  - VLESS route (UUID bytes 6–7);
  - mihomo fields (clash only).
- **Protocols:**
  - VLESS: UUID, plus `flow` from the client;
  - VMess: `vmess://` + base64 JSON, so the verifier decodes it;
  - Trojan: password;
  - Shadowsocks: `ss://` SIP002 userinfo.

  Host overrides apply to all four the same way, through the externalProxy path. Whether a REALITY inbound with a TLS
  host override renders as the panel UI suggests is **[unverified]**.
- **Cap.** Telegram messages and QR codes scale with N.
  - **Soft cap 20 links per service:** preview warning.
  - **Display cap 30:** the bot and `/s/{token}` show the first 30 in panel order, then "N more are in your
    subscription link". The full set stays stored.
  - **No hard refusal:** the panel is the authority, and a refusal would block sales over a CDN list.

### 9.3 Data model, cache, invalidation

- **No hosts table.** Hosts are read live for the admin preview and the verifier only; customer links come from the
  snapshot.
- **Existing columns only:**
  - `configLinks`, `configText`: the snapshot;
  - `lastAccessInfoSyncedAt`: the time of the last successful refresh.

  No migration for Hosts.
- **Per-plan host selection.** **Default: all usable hosts of each attached inbound** (the panel decides; zero bot
  configuration). An optional per-plan allow-list is possible through `clientHostRuleIds` at create/attach time [code],
  but it is **not built now** (H4, optional):
  - row ids are per inbound × address;
  - a deleted row fails the inbound closed for bound clients;
  - an admin editing hosts in the panel would silently cut paid customers off.
- **Hosts cache (preview only).** Per panel, in the app cache:
  - TTL 10 min;
  - a "Refresh" button in admin clears it;
  - when the API fails, the preview shows "Hosts unknown" with the reason. It never shows "0 hosts".
- **Snapshot refresh** (H2), a scheduled job (`app:services:refresh-links`):
  - which services: live 3x-ui modern services whose snapshot is older than **6 h**, in batches with a pause;
  - additional triggers: on regenerate, on reset-links, after attach, after renew/resume;
  - an admin action "Refresh links for services on inbound X / whole panel" for after editing hosts;
  - **failure:** the old snapshot is kept; the last error is logged and counted in panel doctor;
  - **empty answer** while the client exists: the snapshot is kept, admins alerted once (an empty list is never
    stored over a non-empty one).
- **Hosts API unavailable:**
  - 404 without JSON envelope → "panel without Hosts API";
  - 401/403 → "token lacks permission (needs admin scope)";
  - customer links are unaffected (they use `clients/subLinks`); only the preview and verifier lose host detail.

### 9.4 Admin UX

- **Plan page, read-only "Links preview"** for the plan's inbounds (primary + additional). One row per inbound:
  remark, protocol, port, usable hosts (address:port, security, SNI, remark template), disabled or excluded-from-raw
  hosts in grey with the reason, and the predicted link count (9.2). Total at the bottom.
- **Warnings:**
  - an inbound with 0 usable hosts *and* a non-public resolved address (link will be withheld);
  - only disabled hosts;
  - a host excluded from `raw` (the format the bot consumes);
  - a CIDR or SNI-pool host ("links vary per refresh");
  - total above 20 (soft cap) or 30 (display cap);
  - Hosts API unavailable or not permitted (reason shown);
  - per-client bindings found on existing services (`clientHostRuleIds` non-empty; read from `clients/list`).
- **Feature detection, never versions.** Extend `SanaeiCapabilityProbe` with `GET panel/api/hosts/list`:
  - `success:true` + list → `hosts=yes`;
  - 404 without the envelope → `hosts=no`;
  - 401/403 → `hosts=forbidden`.

  Stored on the panel next to the API mode; shown on the panel page and by `app:panel:doctor`.
- **Service page:** "Links (from panel, refreshed <time>)", a "Refresh from panel" button, and the verifier's last
  result.

### 9.5 Interaction with multi-inbound plans

- **Link count** = Σ over the service's attached inbounds of (usable host rows × CIDR count, or 1 when none), plus
  the client's external links, minus the inbounds that a binding fails closed. The panel computes it; the preview
  predicts it.
- **Partial attach (D2).** `subLinks` lists only the inbounds actually attached, so a `failed` inbound simply has no
  links. After a successful retry, the attach step triggers a snapshot refresh.
- **Existing services.** No data migration. H0 makes "regenerate" Hosts-correct. H2 refreshes every live modern
  service once (batched), then by TTL. Legacy-mode services keep the legacy path.
- **Admin changes host attachments in the panel later.** Customers' subscription URLs follow at once (served by the
  panel). Stored links follow at the next refresh (≤ 6 h), or at once with "Refresh links" on the inbound or panel.
  "Apply to existing services" (section 4) is about inbounds, not hosts: hosts need no per-service action unless H4
  bindings are used.

### 9.6 Verification procedure (read-only command, first implementation PR)

`app:service:verify-links <serviceId> [--json]`: no writes, no panel writes.

1. Load the service and its panel. Refuse a non-modern panel ("legacy: Hosts not applicable").
2. Fetch `clients/get/{email}` (UUID, subId, `clientHostRuleIds`), `clients/subLinks/{subId}`, `clients/links/{email}`
   and, if `hosts=yes`, `hosts/byInbound/{id}` for each of the client's `inboundIds`, plus `inbounds/options`.
3. Parse every link to a tuple:
   - scheme, address, port, security, sni, type, host, path;
   - UUID/password: VLESS UUID with bytes 6–7 masked; VMess decoded from base64;
   - remark, decoded.
4. Compare the **stored snapshot vs `subLinks`**. Pass = the same multiset of tuples, ignoring `sni` for SNI-pool
   hosts, the address for CIDR hosts, and remarks with a template var.
5. Compare **`subLinks` vs the 9.2 prediction:** per inbound, the count and the set of (address, port, security, sni,
   host, path). Every difference is reported with its field.
6. Report:
   - **public-address check:** any link whose address fails `PublicUrlPolicy` (e.g. `127.0.0.1`);
   - **UUID check:** each link's credential matches the client, modulo VLESS route;
   - **duplicates** between `links/{email}` and `subLinks`.
7. Exit code: 0 = PASS (steps 4–6 clean), 1 = DIFF (differences listed), 2 = cannot check (panel unreachable, not
   permitted, no subId).

**Steps for the owner on production** (a test service on a plan whose inbound has ≥ 2 enabled hosts, one disabled host,
and if possible one host with 2 addresses):

1. `php bin/console app:panel:doctor <panelId>`: expect `hosts=yes` (the line H0 adds).
2. `php bin/console app:service:verify-links <serviceId>`: expect PASS, or DIFF on step 4 only (an old snapshot, which
   is the bug H0/H2 fix).
3. In the panel, disable one enabled host. Run again: expect DIFF "snapshot has a link the panel no longer gives".
   Then use "Refresh links" on the service and run again: expect PASS. Re-enable the host.
4. Add a second address to a host. Refresh, verify: expect +1 link per address.
5. Mark a host "Exclude from formats: raw". Refresh, verify: expect the link gone. Revert.
6. Compare with what a phone sees: `curl -s '<subscription URL>' | base64 -d` (run by the owner, not the bot). Expect
   the same links as `subLinks`, plus at most one `socks://127.0.0.1` info line if the info node is on.
7. Pass = steps 2–6 behave as stated. Any other DIFF is a 9.2 rule to correct before H3.
8. Toggle `shuffleHost` on a multi-address host, refresh twice: record whether count or order change (9.0 says no).
9. **[unverified] list:**
   - U1: `shuffleHost`, `isHidden` and `nodeGuids` have no effect on raw links in v3.11.0;
   - U2: the API `subLinks` never contains the info-node line;
   - U3: with a REALITY inbound, a host `security=tls` override renders as the UI suggests;
   - U4: the panel's Sub/Web domain is set in production, so no host-less inbound resolves to the request host;
   - U5: our bot token has `admin` scope;
   - U6: `links/{email}` and `subLinks` give the same set for a single-inbound client.

### 9.7 Phase plan (replaces the order of 7.4)

| Phase | Scope | Tests | Rollback | Off by default |
|---|---|---|---|---|
| **H0** (first; fixes existing single-inbound services) — **built**, see below | Modern 3x-ui: regenerate / reset-links / both regenerate commands re-read the snapshot from the panel (`fetchLinks`) instead of `VpnAccessLinkGenerator`; `fetchLinks` = `subLinks` only (fallback `links/{email}` without subId); never store an empty list over a non-empty one; `app:service:verify-links` (9.6); capability probe `hosts=yes/no/forbidden` | Fake3xui `subLinks` with hosts fixture: regenerate stores panel links only; generator not called for modern; empty answer keeps old; reset-links stores new-UUID panel links; verifier parsing (vless/vmess/trojan/ss, VLESS route mask, CIDR/SNI-pool tolerance); real panel: 9.6 steps 1–7 | Revert PR (no schema) | Nothing to switch: a bug fix. The commands print "source=panel" |
| **H1** | Admin read-only links preview (9.4) + Hosts cache + warnings; panel page shows Hosts capability | Preview count per 9.2 against fixtures (disabled, excluded, CIDR, no hosts, bindings); API 404/403 → "unknown" not "0"; real panel: preview count = verifier count for the test plan | Revert | Preview only; no customer effect |
| **H2** | Scheduled snapshot refresh (TTL 6 h, batches), triggers after attach/renew/resume, admin "Refresh links" per service / inbound / panel, doctor counters, display cap 30 with "N more" | Job batches and pause; failure keeps snapshot; alert once on empty; display cap text; real panel: 9.6 step 3 | Disable the schedule entry; revert | `provisioning.links_refresh_enabled` default **off** (manual buttons work regardless) |
| M1–M3 | As 7.4; M2 uses `subLinks` (no generator loop). **M1 and M2a built**, see below | As 7.2 | As 7.4 | `plans.multi_inbound_enabled` off |
| **H4** (optional) | Per-plan host allow-list via `clientHostRuleIds` | Binding written at create/attach; deleted row → verifier warns | Clear bindings (empty list) | `plans.host_selection_enabled` off |

#### H0 as built

Branch `fix/hosts-h0-sublinks`. No migration and no switch. Admin guide: [vpn-panels.md, "Config links and 3x-ui
Hosts"](../en/vpn-panels.md#config-links-and-3x-ui-hosts).

- **Panel calls.** `ModernClientsApi::subLinks()` / `links()`:
  - the existing client: Bearer token, TLS verification, 3 s connect / 10 s total, no redirects;
  - the answer is capped at 1 MiB (not retried) and at most 500 links of 8 KiB;
  - only `vless`, `vmess`, `trojan`, `ss`, `hysteria2`/`hy2`, `tuic` and `wireguard` items without spaces or control
    characters are kept; the others are counted and logged by scheme only;
  - the subId never appears in a log or an error, and transport errors no longer quote the request URL.
- **`fetchLinks()`** (creation and credential rotation) = `subLinks` only; `links/{email}` only without a subId.
- **`PanelLinkSnapshot`** (one place for the rules of 9.1/9.3):
  - an answer with links replaces the snapshot; a shorter one too, logged as `panel_links_snapshot_shrank` and
    reported;
  - an empty answer or a failure keeps the snapshot and reports an admin-only error.
- **Users of the snapshot:**
  - `ServiceConfigRegenerator`, and so the web and Mini App "Regenerate config" buttons and the claim "reset my
    links". Their locking, rate limit and recheck are unchanged; the admin message shows "(before: N)";
  - `app:service:regenerate-config`;
  - `app:inbound:regenerate-service-configs`.
- **Also routed through it.** Two more paths did the same Hosts-blind rebuild: the web "regenerate configs of the
  inbound" action and `app:panel:sync-inbounds --regenerate-configs`.
- **Commands.** Both regenerate commands are **dry runs unless `--apply`**: old vs new count, + / - links without
  credentials. The bulk one runs in batches, resumes with `--after-id`, and goes on past failures.
- **Hosts probe.** `hosts/list` gives `hosts=yes/no/forbidden/unknown`. It is printed by `app:panel:doctor` and
  `app:service:verify-links` and **not stored** on the panel; it is never a switch.
- **`app:service:verify-links <serviceId> [--json]`** as in 9.6:
  - U2 and U6 are automatic checks;
  - U1 and U3–U5 are manual steps in the admin guide;
  - link order is not compared.
- **After the independent review:**
  - the 1 MiB cap holds for compressed answers: `Accept-Encoding: identity`, and the body is counted after decoding
    while it arrives;
  - error texts lose the subId, base path and URLs themselves (not by matching the request URL);
  - `PanelLinkSnapshot` writes each service on its own. The panel is read with no lock held. Then the row is locked
    by primary key, and the links are written only if the client UUID, subId and remote id are still the ones they
    were read for. Otherwise ("changed meanwhile", e.g. a link reset) they are discarded and read once more;
  - bulk runs (both commands, `sync-inbounds --regenerate-configs`, now also a dry run unless `--apply`):
    - pause between batches;
    - stop after 5 failures in a row;
    - never count towards the panel's "degraded" state;
  - the web inbound action is a POST with a CSRF token, for at most 10 services;
  - duplicates are dropped before the 500-link cap; rejected items are logged as counts per scheme;
  - verify-links also checks the stored subId and exits 2 on any unexpected error.
- **Not changed in H0** (these still build Hosts-blind links; to fix in H2):
  - at creation, when the panel gives no links, `VpnProvisioningService` stores links built from the inbound;
  - at display, a modern service with **no** stored links gets links built from the inbound
    (`FinalConfigLinkProvider`);
  - a reset whose new links cannot be read leaves the snapshot empty, so the display fallback above applies.

  `vpn-panels.md` has a read-only SQL count of the services this affects.
- **Not tested against a real panel:**
  - the 9.6 production steps;
  - U1–U6;
  - the 1 MiB cap with curl (tested with the mock transport);
  - VMess/Trojan/SS link parsing in the verifier against real panel output.

#### M1 as built

Branch `feat/multi-inbound-m1`. Owner's decisions: D1 to D4 and Q5 as recommended in section 8. Admin guide:
[admin-guide.md, "Plans with several inbounds"](../en/admin-guide.md#plans-with-several-inbounds-preview).

- **Migration `Version20261101090000`:**
  - `plan_additional_inbound` and `vpn_service_inbound` as in 2.1 and 2.2: plan / service CASCADE, inbound RESTRICT,
    unique (plan, inbound) and (service, inbound);
  - no backfill; `down()` refuses (nothing changed) while either table has a row;
  - `vpn_service_inbound` stays empty until M2.
- **`Plan`:**
  - `getInbounds()` = primary, then additional by `sort_order`;
  - `plan.inbound` and all its readers are unchanged;
  - saving keeps the rows of inbounds that stay, so a reorder never hits the unique key.
- **Fail closed:** a plan with any additional inbound is **not sold** (`isAvailableForSale()`), whatever the switch
  says, until M2 attaches them.
  - The plan list shows "not sold yet"; the form and the warnings say "takes effect when provisioning support ships".
  - `PlanCatalog::forSale()` now loads plans, primary inbound, panel and additional rows in one query.
- **Switch** `plans.multi_inbound_enabled` (off): its own web settings tab "Plans", because the Mini App sales form
  rewrites every sales key.
  - With it off, the plan form, plan list and inbound page send no query to the new tables, and a crafted POST with
    additional inbounds is refused (nothing saved).
- **Save rules** (`PlanInbounds` constraint on `Plan`: web inline errors; bot and Mini App through
  `PlanAdminService::save()`):
  - not changeable with the switch off;
  - primary required;
  - same panel, not the primary, newly chosen ones active;
  - panel 3x-ui in effective modern mode when inbounds or primary change;
  - at most 20;
  - **customizable plans stay single-inbound** (the design is silent: decided here);
  - D1 protocols: VLESS/VMess/Trojan/Shadowsocks only. The SS-method and vision/flow-capable rules read
    `inbounds/options` (not stored locally), only when they apply and something changed; an unreadable panel refuses the
    save.
- **Warnings (section 6), not refusals:**
  - not sold yet;
  - an inbound inactive since it was chosen;
  - panel not modern;
  - a protocol mix to check on a real panel.
- **Admin pages (switch on):**
  - plan form multi-select (ids not offered are form errors; repeats are stored once);
  - plan list "Inbounds: N" with badges;
  - panel inbound page "Used by plans" (primary / additional).
  - A fixed number of queries per page (`PlanInboundUsage`).
- **Deleting** an inbound that is an additional inbound of a plan (or held by a service row), or its panel (inbounds
  cascade from the panel), is refused with the plans named. Deleting a **primary** inbound is unchanged (SET NULL), by
  the owner's decision.
- **Not in M1** (as 7.4): provisioning / attach / retries, display, the Mini App plan field, "apply to existing
  services", trials (single-inbound). The Mini App's "copy plan" copies no additional inbounds.

#### M2a as built

Branch `feat/multi-inbound-m2a`. Owner's decisions: D2 (deliver the primary, retry the rest), D3 (an inactive inbound
stops the plan selling), modern panels only, links from the panel's `subLinks` (9.1; the generator loop of 3.3 is not
built). Admin guide: [admin-guide.md, "Plans with several inbounds"](../en/admin-guide.md#plans-with-several-inbounds-preview).
No migration (M1's tables have every column).

- **Create (modern driver):**
  - one `clients/add` with `inboundIds: [primary, ...additional]`; `GET clients/get/{email}`; the inbounds the client is
    not on are attached in one call with only those ids; `GET` again;
  - an additional inbound still missing is returned as **failed** with a safe error text (no URL, token or base path),
    never thrown; the primary missing is an error, as before;
  - when the panel refuses the whole add (e.g. one additional inbound was deleted on the panel), the primary alone is
    added; when it refuses the batch attach, the ids are attached one by one, so one bad inbound does not keep the
    others off (D2);
  - without additional inbounds the requests are exactly the single-inbound ones.
- **Adopt** (`findExistingService`, after a lost answer or on a settlement retry): the same attach-missing step, only
  for a client matched by UUID. A client matched by its generated name only is adopted unchanged and its additional
  inbounds are recorded as failed ("use Retry attach").
- **Fail closed.** A plan with additional inbounds on a panel whose driver cannot attach (legacy mode, another panel
  type) fails provisioning with "Several inbounds need the panel in modern mode (Panels → test connection)."
  (PROVISIONING_FAILED, admin alert); nothing is sent to the panel. The legacy driver refuses such a request too.
- **`VpnProvisioningService`:**
  - one `vpn_service_inbound` row per additional inbound: `attached`, or `failed` with `last_error` and `attempts`,
    upserted by (service, inbound);
  - the service is ACTIVE and the order provisioned as soon as the primary works; a failed attachment never changes
    the payment, order or wallet outcome. Lock order and settlement rules are unchanged;
  - recorded as failed without asking the panel: an additional inbound inactive since the sale, and every additional
    inbound when the switch was turned off after the order was taken (**decided here**: the paid customer gets the
    primary, the rest waits visibly for "Retry attach");
  - links: the panel's `subLinks` snapshot (every attached inbound). A multi-inbound service is never given links
    built from the primary alone: without the panel's links it has its subscription URL only (9.1).
- **"Retry attach"** (`ServiceInboundAttacher`; admin service page, SERVICES_MANAGE, POST + CSRF, rate limiter
  `admin_service_attach` 5 per service per 10 min, audited `service.retry_attach` with counts only):
  - the failed rows are claimed in a short transaction (service row locked by primary key, then its rows; failed →
    pending); the panel is called with no lock or transaction held (GET, attach of the missing ids only, GET); the
    result is written in a second short transaction to the rows still holding this claim; `attempts` + 1;
  - a second retry meanwhile finds nothing to claim ("in progress") and calls nothing; a claim left by a dead process
    is taken over after 5 minutes;
  - never creates a client (a client gone from the panel fails the rows);
  - refused while the switch is off or the panel is not modern; after an attach the link snapshot is refreshed
    (`PanelLinkSnapshot`, its own lock rules).
- **Sale rule** (**not built in M2a, see "M2c as built"**; `PlanSaleRule`, the one place that reads the switch; `Plan::isAvailableForSale()` /
  `multiInboundSaleBlocker()` hold the rule): a plan with additional inbounds is sold only with the switch on, every
  inbound (primary and additional) active and on the primary's panel, and that panel a 3x-ui panel in effective modern
  mode (plus the usual: plan active, panel usable). Used by `PlanCatalog` (bot, Mini App, resellers), the bot's
  payment-method step, draft confirmation, payment start and wallet payment. The last three checked "plan active" only
  and still do for single-inbound plans. With the switch off, a plan with additional inbounds is not sold (as in M1).
- **Admin:** plan list badge "not sold" and the save warning now give the reason; service detail page (switch on)
  lists each additional inbound with its state, attempts and last error.
- **Other operations** (3.3) unchanged and verified by tests: renew, add traffic, suspend, resume, reset usage, usage
  sync are one record by email; delete is `del/{email}` (the client leaves every inbound) and the rows become
  `detached`; claim (tgId/comment) and "reset my links" keep every inbound; regenerate (H0) stores the `subLinks` of
  every attached inbound; the reseller wholesale price is the plan's.
- **`app:service:verify-links`:** for a service with rows, one more check: the client must be on the primary and
  every row recorded as attached (DIFF otherwise); failed or pending rows are reported (INFO), not a difference.
- **Not in M2a (seams left):** the scheduled retry (`ServiceInboundAttacher::retry()` is what it would call), the
  admin alert once per service, display in the bot / Mini App / service lists, panel doctor counts, "apply to existing
  services", the Mini App plan field, detach.
- **Not tested against a real panel:** everything above runs against the fake 3x-ui; the checklist in the admin guide
  (7.3) is the real-panel test.

#### M2b as built

Branch `feat/multi-inbound-m2b`. Owner's decisions: additive migration; the customer's primary server line stays as it
was, plus "Servers: N" and a neutral line; an inbound found removed on the panel becomes failed and is retried
automatically; only ACTIVE services. Admin guide: [admin-guide.md, "Scheduled retry and alerts"](../en/admin-guide.md#scheduled-retry-and-alerts).

- **Migration `Version20261102090000`** (additive): `vpn_service_inbound.next_attempt_at`, `checked_at`, `alert_state`,
  `alerted_at`, index (`status`, `next_attempt_at`). Nothing backfilled: a failed row with `next_attempt_at` NULL is due
  at once. M1's `down()` now refuses while this migration is applied.
- **Settings** (plans tab): `plans.attach_retry_enabled` (true), `plans.attach_max_attempts` (12, 1–100, the sale's
  attempt included), `plans.attach_retry_delay_minutes` (5, 1–1440). Back-off after the n-th failure: the delay × 2^(n-1),
  capped at max(delay, 6 h); none once the attempts are used up (computed as `attempts >= max`, so raising the limit
  re-enables a row).
- **`InboundAttachScheduler`** (`app:services:attach-inbounds [--limit --batch-size --pause --dry-run]`,
  `app:automation:run --only=attach-inbounds`): nothing at all while `plans.multi_inbound_enabled` is off; with
  `plans.attach_retry_enabled` off only the alerts are sent. ACTIVE services with a due row (failed with attempts left
  and due, pending with a stale claim, attached/failed not compared for 6 h), in id order, in batches with a pause:
  - **reconcile**: one `GET clients/get/{email}` with no lock held; then a short transaction (service row by primary key,
    then its rows, `FOR UPDATE`) writes only rows unchanged since they were read: attached but not on the panel →
    failed, attempts 0, `last_error` "no longer on this inbound", alert state cleared (**fixes the M2a limit**: removed
    by hand in 3x-ui); failed or stale pending already on the panel → attached, no attempt counted; the rest
    `checked_at` only. A client missing from the panel leaves the rows (checked_at aside);
  - **retry**: `ServiceInboundAttacher::retry($service, 'scheduled_attach', scheduled: true)`: claims only the due
    failed rows with attempts left (plus stale pending), the same claim/write rules as M2a, `next_attempt_at` from the
    back-off; manual "Retry attach" ignores back-off and limit;
  - a panel that cannot be read ends that service (no write, no attempt used); after 5 in a row the panel is skipped
    for the rest of the run, other panels go on. No call of the run counts towards a panel's degraded state (as H0
    bulk runs). Two runs at once (or a run and "Retry attach") are safe: the conditional writes and the claim leave the
    loser nothing to do (real two-process test).
- **Alerts** (`InboundAttachAlerts`, the stuck-payment mechanism: a conditional UPDATE of `alert_state` claims each row,
  then `AdminAccessPolicy::telegramRecipients(SERVICES_MANAGE)` + `TelegramApiClient`): one message per service and
  kind: failed for the first time, attempts used up, attached again (recovery). Nothing is claimed while no admin chat
  exists. The text names ids and titles and quotes the stored safe error with the client email masked.
- **Scheduling:** `deploy.sh` job `attach-inbounds|lifecycle|*:25/30|300|1800` (lifecycle: it attaches the client and
  refreshes its links, which must not overlap sync/suspend of the same client; :25/:55 is free); allowlist and ops
  tests updated (eight jobs); `scheduled-job.sh`, `amoobot-check-jobs` and `post-deploy-check` handle it from the
  manifest like the others; logs in `/var/log/amoobot`. Installed only by a full deploy, not `--app-only`.
- **Visibility** (`ServiceInboundCounts`, all null/empty with the switch off): bot service detail and Mini App service
  page "Servers: N" (+ "N server(s) still being connected…") only for a service with rows, nothing else changes; admin
  services list badge and tab "Inbounds not attached" (`?inbounds=not_connected`, one query per page); service page:
  next automatic try, "retries used up" or "retry off"; panel detail page and `app:panel:doctor` count failed /
  given up / pending rows of active services. Reseller views show no service links and are unchanged (D5).
- **(a) Adopt by name:** not new in M2a. Before M2a `VpnProvisioningService` already adopted a client matched by its
  generated name (and refused a customer-chosen name), and the driver attached inbounds only on a UUID match. M2a keeps
  both rules; such rows are failed and now retried by the scheduled job.
- **Not in M2b:** a marker in the customer service lists (optional, left out: lists unchanged), the Mini App admin
  service view, "apply to existing services", the Mini App plan field, detach.
- **Not tested against a real panel:** the fake 3x-ui only; checklist step 11 is the real-panel test of reconcile.

#### M2c as built

Branch `fix/multi-inbound-m2c`: the corrective phase after the independent review of M1, M2a and M2b (findings F1 to
F14). Admin guide: [admin-guide.md, "Plans with several inbounds"](../en/admin-guide.md#plans-with-several-inbounds-preview),
with the real-panel checklist rewritten. Migration `Version20261103090000` (additive).

- **Correction to "M2a as built".** The sale rule (`PlanSaleRule`) and the checks at payment start, wallet payment,
  draft confirmation and the catalog described above were **never committed** in M2a: the files were written in the
  M2a session, moved aside to repair a container-compile baseline, and never restored after a context compaction; the
  docs were committed as if they were built. Until M2c only M1's fail-closed check in `Plan::isAvailableForSale()`
  existed (no sale of such a plan at all). Process rule since: before reporting anything as built, `git status` is
  clean, every new file is committed, and the pushed head is checked out into a clean directory and tested there.
- **Sale rule (F1).** `MultiInboundSale::blocker()` (domain, one decider: switched_off, no_primary, inactive_inbound,
  other_panel, not_modern) behind `Plan::isAvailableForSale($switch)` / `multiInboundSaleBlocker()`, and
  `PlanSaleRule` (the one service that reads the switch, memoized per request): `isSellable` / `sellable` for the
  catalog (`PlanCatalog`: bot plan list and choice, the bot's card-payment step, Mini App, reseller offers, quotes and
  purchases), `acceptsOrders` for draft confirmation and the bot's custom draft, `acceptsPayment(Order)` in
  `PaymentStarter` (gateway and card) and `WalletPayment` (customers and resellers), judged on the order's snapshot (F4),
  else the live plan. Plans without additional inbounds are unaffected. **Decided:** renewals and add-traffic are not
  gated (they change the existing client only, so a multi-inbound service keeps renewing with the switch off); a card
  receipt already submitted is not refused (the money may be sent; provisioning applies F3); there is no admin
  order-creation path besides dev commands. Admin plan list: badge "not sold" with the reason (`data-not-sold`, title),
  the save warning gives the reason.
- **Order snapshot (F4).** `Order` (`#[PrePersist]`, new-service orders with a plan) stores
  `metadata.planInbounds = {primary, additional[]}`; a metadata rewrite keeps it. Provisioning uses the snapshot: an
  inbound removed from the plan after the order is still attached, one added is not. At provisioning, an additional
  inbound of the snapshot that is inactive or on another panel is a failed row with a safe reason
  (`ServiceInboundAttacher::OTHER_PANEL` for the latter; the retry never calls the panel for it); one **deleted** cannot
  have a row: it is recorded in `metadata.planInboundsMissing`, logged, and the admins get one alert after the commit
  (`InboundAttachAlerts::missingInbounds`). The primary is always delivered. Orders without a snapshot (taken before
  M2c) use the live plan.
- **Switch off at provisioning (F3).** With the switch off a paid order for a plan with additional inbounds gets its
  primary on any panel type: the additional inbounds are not attempted and are recorded failed with
  `VpnProvisioningService::SWITCHED_OFF` (no panel call, no driver check). "Retry attach" attaches them once the switch
  is on. Switch on and a panel that cannot attach (legacy, other type): fail closed as in M2a (PROVISIONING_FAILED +
  alert).
- **Unconfirmed attach (F2).** When the read back after an attach fails (`GET clients/get` error), the paid order never
  fails: if the primary was confirmed before, the additional inbounds are known-attached (on the client as read before),
  refused (failed with the panel's reason), or **pending** (`NOT_CONFIRMED`, an attempt counted); the order is
  provisioned. The scheduled run takes a pending row over once its claim is stale (5 min): it finds it attached on the
  panel or attaches it. A failed read when the primary itself was among the missing ids still throws (as before).
- **Inline budget (F5).** Under the settlement locks the driver calls the panel for the additional inbounds for at most
  `Sanaei3xuiModernDriver::INLINE_ATTACH_BUDGET_SECONDS` = 20 s in all (checked before each call; a running call may
  overrun by its own 10 s timeout) and at most `INLINE_MAX_SINGLE_ATTACHES` = 3 one-by-one attaches after a refused
  batch. The rest is pending (`NOT_TRIED`, no attempt counted); out of time, the read back is skipped when the primary is
  already confirmed. "Retry attach" and the scheduled run hold no lock and are not bounded. Constants, not settings.
- **Reconcile (F6).** A panel answer that lists the client's inbounds **without the primary** is treated as unreadable:
  no state change, no attempt, no alert (`InboundAttachScheduler::WITHOUT_PRIMARY`).
- **Re-attach loops (F7).** Migration `Version20261103090000`: `vpn_service_inbound.reattach_count` (SMALLINT, 0) and
  `reattach_since`. Each removal found by reconcile counts one cycle in a window of `REATTACH_WINDOW_HOURS` = 24 from the
  window's first removal (a removal after the window starts a new count; attempts are reset per removal as in M2b, the
  cycle count is not). The removal after `MAX_REATTACH_CYCLES` = 3 cycles stops it: failed, `last_error`
  `REATTACH_STOPPED`, attempts = `MultiInboundSettings::MAX_MAX_ATTEMPTS` (no setting retries it), and one "attempts
  used up" alert that says it kept being removed. "Retry attach" still works. M2b's `down()` refuses while this
  migration is applied.
- **Alerts (F8).** Unchanged mechanism. Found removed and attached again in the same run sends **nothing** (the admin
  guide promised an alert; the guide is corrected, the code sends nothing extra); an alert goes out only when the
  re-attach fails (failed, then recovered), on a stopped loop (one), and for snapshot inbounds deleted before
  provisioning (one, immediate).
- **Customer's Telegram id (F9).** `SecretRedactor::maskAccess()` masks `tg_<id>_order_<n>` as
  `tg_[redacted]_order_<n>`; `VpnServiceInbound::safeError()` applies it to every `last_error` write (entity and the
  attacher's SQL write), the admin service page masks again on display (rows written before M2c), the alert's error line
  and the modern client's request log lines (`sanaei_modern_request[_failed]`, whose path holds the email) too. The
  scheduler's read-failed log goes through `ServiceErrorText` (which ends in `redactAccess`). The service page's
  "client email" field is left as it was (pre-existing, admin only; the user page shows the Telegram id anyway).
- **Customer text (F10).** `ServiceInboundCounts::servers()` splits the not-connected rows into `retrying` (pending, or
  failed with attempts left, while `plans.attach_retry_enabled` is on) and `stuck` (attempts used up, a stopped loop, or
  the retry off). Bot (fa) and Mini App (en/fa): "N server(s) still being connected…" for the first, "N server(s) not
  connected yet. Please contact support." for the second; never which, where or why.
- **Malformed `inboundIds` (F11).** `ModernClientsApi::normaliseClient()` always yields a list of ints or drops the
  field: a list keeps its numeric entries, a number or a `"1,2"` / `"[1,2]"` string becomes those ids, null / other text
  / bool / float are "not listed". The driver checks `is_array` before every `in_array` / `array_diff`. Requests are
  byte-identical whatever the shape (test on the single-inbound path).
- **Tests (F12, F13).** The scheduled attach step never marks a panel degraded (test). `RegenerateResetRaceTest`:
  the regenerate helper waits up to 120 s for "go" and fails loudly (it went on silently after 10 s, the flake), the
  reset holds its lock until a writer is seen waiting (`INNODB_TRX`), the test polls with a deadline. The reseller claim
  race tests start their processes at a barrier (`ParallelStart`, `bin/start-at.php`: each process says it booted, then
  the test sets the start) instead of a fixed "now + 4 s". The multi-inbound parallel tests use order-tolerant outcomes
  and needed no change. New real two-process tests (MariaDB): F2 (two settlements with a failing read, then the
  scheduled run and "Retry attach" at once), F5 (the second settlement waits only for the bounded work), F4 (a plan
  edited during provisioning), F7 (two runs count one cycle; a stop alerts once).
- **Ops (F14).** The manifest's max age was already 3 × interval + timeout (attach-inbounds: 5700 s), so one skipped run
  never shows STALE (now pinned by a test). New: `deploy.sh` stamps `<job>.installed` the first time it installs a job;
  `check-scheduled-jobs.sh` reports a job with no success yet but installed within its max age as **NEW** (a warning,
  exit 0), NEVER after that, FAILED if its first run failed; `post-deploy-check` passes with an INFO warning naming
  them. Applies to every job.
- **Not tested against a real panel:** all of the above runs against the fake 3x-ui; the admin guide's checklist
  (rewritten for F1 to F4) is the real-panel test.

### 9.8 Open questions and risks (Hosts)

Open questions, each with the recommended default:

1. **H1 — source of truth.** Panel `subLinks` as the stored snapshot; no generated fallback for host-bearing inbounds?
   *Recommended: yes.*
2. **Multi-value Address.** One link per address, or one chosen?
   - Verified [code]: one link per address. Each address is its own row, all rendered; "Shuffle host" is not used by
     the v3.11.0 renderers.
   - To test (9.6 steps 4 and 8): count and order on the real panel.
   - *Recommended: accept the panel's output (one per address); count it in the cap.*
3. **Nodes.**
   - Verified [code]: an inbound on a node is advertised with the node's address (`resolveInboundAddress`); host
     `nodeGuids` is stored but unused by the v3.11.0 renderers. Our model already pins a plan to one panel, and
     inbounds carry `nodeId` (`inbounds/options`).
   - The subscription URL is the **master** panel's (`subLinks` and `/sub` are served by the panel the client lives
     on).
   - *Recommended: no node-specific logic; show the node id per inbound in the preview; re-check `nodeGuids` when the
     panel is upgraded.*
4. **Exclude from formats.**
   - Formats: `raw`, `json`, `clash` [code]. The bot stores `subLinks` = **raw**; customers' apps may fetch json or
     clash through the subscription URL.
   - Links excluded from raw never reach our snapshot; links excluded only from json/clash still appear in the bot.
   - *Recommended: consume raw only; the preview flags hosts whose exclusions differ between formats ("apps using
     json/clash will see a different list").*
5. **Cap.** Soft 20 (warning), display 30 ("N more in your subscription link")? *Recommended: yes.*
6. **Refresh.** TTL 6 h, plus manual refresh, plus triggers? *Recommended: yes; the scheduled job is off until H2 has run
   on production once by hand.*
7. **Per-plan host selection (H4).** Build it? *Recommended: not now; default "all usable hosts".*
8. **Info node.** If the owner enables the subscription info node, apps see a `socks://127.0.0.1` line. *Recommended:
   leave it to the owner; the bot never stores it (API, U2).*

Risks:

- **A stale snapshot** after host edits (≤ TTL). Mitigation: the subscription URL is always live; "Refresh links"
  buttons.
- **Random per refresh** (CIDR, SNI pools): QR codes change between refreshes. Mitigation: refresh only on TTL or
  triggers, not per view; preview warning.
- **Request-host leak** for host-less inbounds without Sub/Web domain: withheld by `CustomerAccessGuard` plus a preview
  warning (U4).
- **Per-client bindings set by hand in the panel** can make an inbound give zero links: the verifier reports it.
- **A panel upgrade changes the rendering:** none for customers under (b). The 9.2 prediction may drift, and the
  verifier flags it.
- **Token scope** narrower than admin breaks provisioning and links alike: the probe reports `forbidden`.
