# A plan's inbound changes: reaching existing services — design

Status: **design for review**; **P1, P2, P3, P4, P5, P6, P8 as built** (section 9.1). Built so far:

- the notices;
- the panel's detach primitive;
- the schema and the switch;
- **phase 1 of the move, from the command line only** (`app:plan:apply-inbounds`): attach B, switch the service,
  refresh its links;
- **the display guard** (5.5): while a moved service's links were not refreshed after the change, no customer surface
  shows its stored single configs. The subscription URL is shown with "Your links are being updated";
- **phase 2 and the run controls, from the command line only**: the old inbound A is removed after the grace period
  (or at once with `--retire-now`), and `--pause-run`, `--resume`, `--cancel`, `--undo`;
- **the scheduled task** `inbound-moves`: it continues open runs (P8, built before P7).

Not built yet (P7, P9, P10): the pages and the customer message. The move has
been tested against the fake 3x-ui only, not against a real panel. Persian summary:
[docs/fa/design/plan-inbound-change.md](../fa/design/plan-inbound-change.md). This document continues
[multi-inbound-plans.md](multi-inbound-plans.md) section 4 ("Changing inbounds after sales") and phases M3/M4, which
were planned there and never built. Where they disagree, this document overrides section 4 and M3/M4. Everything
else in multi-inbound-plans.md stands, H0 and 9.1 in particular (links come from the panel's `subLinks` only).

Items marked **DECISION NEEDED (Pn)** carry a recommendation. The owner decides them in section 10.

Labels used for facts:

- **[ours]**: read in this repository at `develop/v2` b6b548e, with the file cited;
- **[3x-ui]**: read in MHSanaei/3x-ui source at tag `v3.11.0`. The routes are in `internal/web/controller/client.go`.
  `AttachByEmail` / `DetachByEmail(Many)` / `DeleteByEmail` are in `internal/web/service/client_crud.go`.
  `DelInboundClientByEmail` is in `client_inbound_apply.go`;
- **[unverified]**: an assumption. Each one is collected in 8.3 and must be checked on the real panel.

## 0. The reported problem

The owner changes the inbound of a plan. Existing services still show the **old** inbound's links, even after
"update" (regenerate links). He expects:

- links always come from the inbound, its Hosts and the panel;
- a changed plan inbound can reach the services that already exist.

## 1. What the code does today (each claim checked)

| # | Claim in the brief | Verdict | Evidence |
|---|---|---|---|
| 1 | A service has its own inbound | **Correct** | `VpnService.inbound` (ManyToOne, SET NULL), `src/Entity/VpnService.php:32-34`; written once, at sale: `src/Provisioning/Application/VpnProvisioningService.php:132` (`->setInbound($planInbound)`). The only other writers are the trial (`TrialAccountService.php:188`, which writes the **plan**) and a preview command. Nothing ever changes a service's inbound after the sale |
| 2 | On the panel, a client sits on that inbound | **Correct**, and the remote id records it too | Remote id `sanaei_3xui\|panel=..\|inbound=<local>\|remoteInbound=<remote>\|uuid=..\|email=..\|subId=..` (`Sanaei3xuiRemoteIdParser.php:12`) |
| 3 | Changing `Plan.inbound` affects new sales only | **Correct, with a nuance** | `VpnProvisioningService::orderInbounds()` (`:241-276`): a single-inbound order is provisioned on the plan's **current** primary, so an order taken before the change but paid after it gets the **new** inbound. A multi-inbound order uses the order's snapshot (M2c F4) while the snapshot's primary exists |
| 4 | "Update" / regenerate only re-reads the links of the service's current client | **Correct** for modern 3x-ui | `ServiceConfigRegenerator::regenerate()` → `PanelLinkSnapshot::refresh()` → driver `panelLinks($remoteId, $subId)` = `GET clients/subLinks/{subId}` (`PanelLinkSnapshot.php:103-116`). The panel renders the links of the inbounds the client is on (`inboundIds`) [3x-ui], so it answers with the old inbound's links. The links the bot shows are therefore **true**: the service really is on the old inbound. The plan is what changed |
| 5 | Regenerate entry points | **Name corrected** | There is no `app:service:regenerate-links`. The command is `app:service:regenerate-config` (`src/Command/ServiceRegenerateConfigCommand.php:20`). It and `app:inbound:regenerate-service-configs` (`InboundRegenerateServiceConfigsCommand.php:19`) use `ServiceLinkRegenerator`. The web and Mini App buttons and the claim "reset my links" use `ServiceConfigRegenerator` (`MiniAppAdminServiceController.php:181-191`). Both end in `PanelLinkSnapshot` for modern panels |
| 6 | Legacy 3x-ui regenerate | **Differs from modern** | `Sanaei3xuiDriver::refreshLegacyServiceConfig()` (`Sanaei3xuiDriver.php:563-623`) reads the inbound from the **remote id** (`$parsedRef->inboundId`) and **builds** links from the panel's inbound data (`generateLegacyConfigLinksFromPanel`). It is the only path that refreshes **before each bot delivery** (`ServiceConfigDeliveryRefresher`, legacy only). Hosts are out of reach of the legacy API (multi-inbound-plans.md 9.0) |
| 7 | Other panels | **Only a dummy driver exists** | `src/Provisioning/Infrastructure/`: `Sanaei3xui/` (modern + legacy) and `DummyVpnPanelDriver.php`. Links for non-3x-ui services are built from `service.inbound` (`VpnAccessLinkGenerator.php:27`, `FinalConfigLinkProvider.php:31-45`) |
| 8 | Multi-inbound tables, `ServiceInboundAttacher` attach extra inbounds to new sales | **Correct** | `plan_additional_inbound`, `vpn_service_inbound` (`src/Entity/VpnServiceInbound.php`); `ServiceInboundAttacher` (sale pass, "Retry attach", scheduled retry); `InboundAttachScheduler` |
| 9 | M3 (apply to existing) and M4 (detach) planned, not built | **Correct** | No "apply"/"inbounds_applied" code in `src/` or `templates/`; `ModernClientsApi` has `attach()` (`ModernClientsApi.php:183`) but **no `detach()`**. The test fake models both (`tests/Support/Fake3xui/Fake3xuiPanel.php:325,337,514-523`) |
| 10 | — (not in the brief) | **Services know their plan only through the order** | `vpn_service` has no `plan_id`. "Services of plan P" = `vpn_service.order_id → order.plan_id` (`Order.php:38`, index `IDX_F5299398C1821EFA`). `order` is SET NULL on a service if an order row were deleted. Renewals do not create services |
| 11 | — | **Three places change a plan's primary inbound** | Web `PlanCrudController` (`savePlan()`, `:161-190`, warnings through `PlanInboundWarnings::of()`); Mini App `MiniAppAdminPlanController` (`CatalogInput` field `inbound`, `CatalogInput.php:25`; pre-save `check` and `warnings()`, `:147-157,255`); and the **panel setup wizard** `PanelSetupController.php:195-205`, a bulk relink that calls `setInbound()` directly. The wizard bypasses `PlanAdminService::save()`, so it skips the `PlanInbounds` validator and the warnings. The bot cannot change a plan's inbound (`AdminPlansHandler` has no inbound field). The trial's `TrialPlan.inbound` is copied to its hidden backing plan (`TrialAccountService::syncBackingPlan()`) |
| 12 | — | **The scheduled reconcile trusts only answers that list the local primary** | `InboundAttachScheduler.php:165-168` (`WITHOUT_PRIMARY`). Suppose someone moves a multi-inbound service's client off its old inbound by hand in the panel. Every scheduled run then treats that service as unreadable (no change, no attempt, no alert) until `vpn_service.inbound` is updated. Single-inbound services have no rows and the scheduler never reads them |
| 13 | — | **No periodic link refresh** | H2 (`app:services:refresh-links`, `provisioning.links_refresh_enabled`) is not built (no such code). The modern snapshot changes only on regenerate, reset-links and after an attach (`ServiceInboundAttacher`) |

**3x-ui v3.11.0 facts this design relies on [3x-ui]:**

- `POST clients/{email}/attach {inboundIds}`: an inbound already attached is skipped. Any inbound that fails to load
  aborts the call before anything is written. Attach applies the client's flow per inbound (`clientWithInboundFlow`).
  The client record and its traffic are not touched.
- `POST clients/{email}/detach {inboundIds}` **exists** (`DetachByEmailMany`):
  - an inbound the client is not on is skipped;
  - the removal keeps the traffic (`DelInboundClientByEmail(..., keepTraffic=true, ...)`);
  - the client record (UUID, subId, expiry, `totalGB`, `limitIp`, `enable`) stays;
  - **it does not refuse to leave the client on zero inbounds.** That guard must be ours.
- `POST clients/del/{email}?keepTraffic=1` keeps the traffic. Without it, traffic and IP rows are deleted.
- `POST clients/update/{email}` does not read `inboundIds` from the body (only a query filter). We never send one
  (`ModernClientsApi::READ_ONLY_FIELDS`). Whether an update ever changes membership is [unverified] U3.
- A record is unique by email ("email already in use"). Re-adding the same email is allowed when the subId matches.

## 2. Why the owner sees old links

1. The plan's primary changes from A to B. New sales get B.
2. Existing service S stays `inbound = A`, and its client on the panel stays on A.
3. "Update" asks the panel for S's subscription. The panel answers with A's links (plus A's Hosts), correctly.
4. The bot stores that answer (H0: panel links only, never merged).

The links are not stale relative to the panel. **The service was never moved.** "Update" cannot fix this, and it
must not try: building B's links for a client the panel does not serve on B would give dead links (rule 9.1).

**Interim workaround on a modern panel, today, without new code** (documented in P1):

1. In 3x-ui, attach the client to B and detach it from A.
2. Press "Regenerate config". The links now come from B.
3. Caveat: the bot's service detail still names A's host, country and protocol (`ServiceManagementService.php:1453`
   reads `service.inbound`).
4. Caveat for a **multi-inbound** service: the scheduled reconcile ignores it from then on (fact 12).

## 3. Part 1: make today's behaviour visible (P1, no behaviour change)

### 3.1 What is counted

The new class `PlanInboundDrift`, read-only, takes one query per call, or one per page for a list:

```sql
SELECT s.inbound_id, s.status, COUNT(*) AS n
FROM vpn_service s JOIN `order` o ON o.id = s.order_id
WHERE o.plan_id = :plan AND s.status <> 'deleted' AND (s.inbound_id IS NULL OR s.inbound_id <> :primary)
GROUP BY s.inbound_id, s.status
```

- **Primary drift:** services of the plan whose `inbound_id` is not the plan's primary, broken down by their current
  inbound (title) and status (active / suspended / expired).
- **Additional drift** (only while `plans.multi_inbound_enabled` is on): services of the plan that miss a
  `vpn_service_inbound` row in `attached|pending|failed` for one of the plan's additional inbounds. Also counted:
  services that hold a row for an inbound the plan no longer has. That is M4's case: "still attached on the panel".
- **Across panels:** a service on another panel than the plan's new primary is counted separately. No attach can
  reach it (see 4.4).

### 3.2 Where it appears

| Place | When | Form |
|---|---|---|
| Web plan form, after save (`PlanCrudController::savePlan()` → `PlanInboundWarnings`) | primary or additional inbounds changed **and** drift > 0 | `warning` flash, text 3.3 (a) |
| Web plan edit page | drift > 0 (any time) | help text under the inbound field: 3.3 (b) |
| Web plan list | drift > 0 | badge "⚠ N on old inbound" with a title (one grouped query per page, like `PlanInboundUsage`) |
| Panel setup wizard (`PanelSetupController`, plans step) | after save, summed over the plans changed | `warning` flash, text 3.3 (c) |
| Web trial plan form (`TrialPlanCrudController`) | `TrialPlan.inbound` changed and drift > 0 on the backing plan | same as (a), "trial plan" wording |
| Mini App plan form: `check` endpoint and `warnings()` (`MiniAppAdminPlanController.php:147-157,255`) | **before** save, when the submitted inbound differs from the stored one | new warning kind `existing_services_other_inbound` in the confirm dialog: 3.3 (a) |
| Mini App plan edit page | drift > 0 | the same line in the form's warnings list |
| Mini App trial form (`MiniAppAdminTrialController`) | as the web trial form | as (a) |
| Service page, web and Mini App admin | `service.inbound` ≠ its plan's primary | info line 3.3 (d) |
| "Regenerate config" result (web, Mini App, both commands) | the same condition | suffix 3.3 (e) on the existing message |
| Admin guide (en, fa) | — | new section "Changing a plan's inbound", with the interim workaround of section 2 |

No new panel call, no write, no setting. Every query is bounded by a fixed count per page.

### 3.3 Texts

English (`admin` / `miniapp` domains):

- (a) `Plan "%plan%" now uses inbound "%new%" for new sales. %count% existing service(s) of this plan are on another inbound (%breakdown%) and keep it: changing a plan never moves existing services, and "Regenerate config" only re-reads the links of the inbound a service is on. See the admin guide, "Changing a plan's inbound".`
- (b) `%count% existing service(s) of this plan are not on this inbound (%breakdown%). They are not moved by saving.`
- (c) `%plans% plan(s) now use another inbound. %count% existing service(s) of these plans stay on their old inbound. See the admin guide, "Changing a plan's inbound".`
- (d) `This service is on inbound "%current%"; its plan now sells "%planInbound%". It is not moved automatically.`
- (e) `The panel still serves this service on inbound "%current%" (its plan now uses "%planInbound%"): the links shown are that inbound's. Regenerating does not move a service.`

`%breakdown%` is "12 on "DE-1", 3 on "NL-2"", with at most 3 inbounds and then "…".

Persian: in the Persian summary, section 3.

Once the move exists (P7), (a), (b) and (d) gain the sentence: `Use "Apply to existing services" on the plan to move them.`

## 4. Part 2: the move itself

### 4.1 Terms

- **Move** (primary): service S goes from primary A to primary B on the **same panel**.
- **Add**: S gains an additional inbound C. This is M3 for additional inbounds.
- **Remove**: S leaves an additional inbound D. This is M4.

### 4.2 Options

| | (a) attach B, then detach A ("make before break") | (b) delete the client, recreate it on B | (c) attach B, keep A |
|---|---|---|---|
| Panel calls (modern) | GET, attach, GET; later GET, detach, GET | GET, `del?keepTraffic=1`, `add` with the same email/UUID/subId, GET | GET, attach, GET |
| Customer outage | **None**: the client is on A and B in between | A window between `del` and `add`. If `add` fails, the **paid customer is cut off** until a retry | None |
| UUID, email, subId, subscription URL | Unchanged [3x-ui: record untouched] | Kept only if re-sent exactly. The add can still pick other secrets (Trojan/SS password unless sent) | Unchanged |
| Traffic counters | Kept [3x-ui: detach keeps traffic] | Kept with `keepTraffic=1` [3x-ui]; their re-link to the new record is [unverified] U4 | Kept |
| Expiry, `totalGB`, `limitIp`, `enable`, `tgId`, comment | Unchanged (record untouched) | Must be re-sent from our copy, which may lag the panel (usage sync interval) | Unchanged |
| Recovery from a crash mid-way | Every step idempotent (attach and detach skip what is done) | Crash after `del`: the client is gone; adopt-by-UUID logic needed | Idempotent |
| End state | Only B, which matches the plan | Only B | A and B: customers keep a server the owner wanted to retire; the link count grows |
| Legacy panels | Impossible (no shared client across inbounds) | The only technically possible way (4.5) | Impossible as one client |

**DECISION NEEDED (P2). Recommendation: (a) on modern panels, in two phases.**

1. **Phase 1, switch:** attach B, then point the local service at B (`vpn_service.inbound`, remote id), then refresh
   the snapshot. The customer's subscription now lists A and B.
2. **Phase 2, retire:** after a **grace period** (default 24 h, setting `plans.inbound_move_detach_grace_hours`,
   0–168), detach A and refresh again.

During the grace period a customer whose app has not yet fetched the subscription keeps working on A. After the
next "update subscription" the app has B. A customer who imported single configs (not the subscription) loses A
after the grace period. That is the optional notice in 6.3.

(c) is phase 1 with an infinite grace: `--keep-old` / "keep the old inbound". (b) is rejected on modern panels.

### 4.3 Per-service state machine (modern)

Table `service_inbound_move` (4.7). States: `planned → attaching → switched → detaching → done`, plus `failed`,
`skipped`, `undone`, `cancelled`.

1. **Claim** (short transaction; lock order 5.3):
   - lock `vpn_service` by primary key, then its `vpn_service_inbound` rows, then the move row, all `FOR UPDATE`;
   - check: status in scope, not deleted, `inbound_id = A`, same panel as B, B active, panel driver can attach;
   - set `attaching`, `claimed_at`, attempts + 1.

   Otherwise `skipped` with the reason (4.6).
2. **Panel, no lock, no transaction:**
   - `GET clients/get/{email}`;
   - the client is on neither A nor B → `failed` "the client is not on the expected inbound" (a hand edit); nothing
     sent;
   - B missing → `attach [B]`, then `GET` again;
   - B still not listed → `failed`, with back-off.
3. **Write** (short transaction, same lock order). Re-read: `inbound_id` still A; client UUID, subId and remote id
   unchanged (the same identity rule as `PanelLinkSnapshot::storeIfUnchanged()`). Then:
   - `vpn_service.inbound = B`;
   - remote id rewritten with `inbound=<B local>|remoteInbound=<B remote>`, other parts byte-identical;
   - `vpn_service.inbound_changed_at = now` (new column, 5.5);
   - if B was one of S's additional rows: that row is deleted (B is the primary now);
   - if A is still among the plan's additional inbounds: an `attached` row for A is created and nothing will be
     detached;
   - move row `switched`, `detach_after = now + grace` (NULL with `--keep-old`).

   If the identity changed meanwhile (a link reset), nothing is written. The row goes back to `planned` and is
   picked again.
4. **Snapshot:** `PanelLinkSnapshot::refresh()` (its own lock, its own rules). It stores `subLinks` exactly. An empty
   answer keeps the old snapshot. The display guard (5.5) then hides it.
5. **Retire** (when `detach_after` has passed). Claim as in 1, with the check `inbound_id = B`. Then:
   - `GET`; B missing → re-attach B first (step 2) and never detach A without B listed;
   - **refuse** to detach when A is the only inbound the client is on, or when A is the local primary (both are
     zero-inbound guards; 3x-ui has none);
   - `detach [A]`, `GET`; A gone → write `done`;
   - refresh the snapshot.

   A detach of an inbound gone on the panel fails to load [3x-ui]. When `inbounds/list` confirms A is gone, the step
   counts as done.

Why this order is safe with the existing machinery:

- The client is on the local primary at every instant: A until step 3, then A and B, then B. The reconcile's
  `WITHOUT_PRIMARY` rule (fact 12) never fires.
- The old primary is never a `vpn_service_inbound` row, so the reconcile never "re-attaches" it after detach.

### 4.4 What is not possible, and what fails closed

| Case | Behaviour |
|---|---|
| B on **another panel** than S | `skipped` `OTHER_PANEL`, which reuses `ServiceInboundAttacher::OTHER_PANEL`. Moving across panels means a new client on another panel: usage, expiry and subscription URL change. Out of scope; future design |
| Panel **legacy** or the driver cannot attach (`InboundAttachDriverInterface` missing) | `skipped`, reason `NOT_MODERN`, same text as `VpnProvisioningService::NOT_MODERN`: "the panel is not in modern mode (Panels → test connection)". Nothing sent. When the panel becomes modern, a new run picks the service up |
| Dummy driver | `skipped` "not a real panel" (tests use the fake 3x-ui) |
| B inactive locally, or missing on the panel | Run creation refused for B inactive. Missing at attach: the attach aborts [3x-ui]; `failed`, back-off |
| A disabled on the panel | Phase 1 is unaffected. The detach works on a disabled inbound [unverified U5] |
| A deleted locally | Allowed today for a primary: `vpn_service.inbound` is SET NULL (`VpnService.php:33`; M1 "Deleting"). A service with `inbound_id NULL` is moved like one on A (its remote id still names the remote A). The remote A is detached only if the client lists it |
| Multi-inbound plan whose new primary has a different protocol (D1 rules) | `PlanInbounds` already checks the plan. The move re-checks the service's resulting set: one SS method, and vision only with flow-capable inbounds (`inbounds/options`). Otherwise `skipped` "protocol mix" |

### 4.5 Legacy panels (why not, and the manual path)

The legacy API stores the client inside one inbound's `settings.clients`. There is no shared record, and usage is per
inbound (multi-inbound-plans.md 0).

A move would be (b):

- remove the client from A;
- add it to B with the same UUID and subId.

The email must stay unique on the panel [unverified U6], so the old one must go first (an outage window). Usage
counters are per inbound email row and would restart unless re-set.

**Not built.** Legacy services are `skipped`/`NOT_MODERN` in every report. The admin guide says: "switch the panel to
modern mode, or move the client in 3x-ui and use Regenerate (legacy regenerate reads the inbound from the remote id,
so the bot keeps reading A: not supported)."

**DECISION NEEDED (P3).** Confirm: legacy is report-only.

### 4.6 What stays, what changes

| Item | After a move |
|---|---|
| UUID, email, subId, subscription URL, `/s/{token}` public token | Unchanged |
| Expiry, traffic limit, IP limit, enable/suspended, `tgId`/comment | Unchanged on the panel (record untouched) and locally |
| Traffic used | Unchanged: panel counters by email, kept on detach [3x-ui]; local `trafficUsed*` untouched; next usage sync (`getUsages()` by email) unchanged |
| `vpn_service.inbound`, remote id | B (step 3) |
| Snapshot `configLinks`/`configText` | Replaced by `subLinks` after phase 1 (A and B) and after phase 2 (B). Never self-built, never merged (H0) |
| `vpn_service_inbound` rows | Only as 4.3 step 3 (B row removed if present; A row added if A stays additional) |
| Order, payment, wallet, reseller rows | Never read for writing, never locked |
| Bot service detail "host / country / protocol" | B's, read from `service.inbound` |

## 5. Part 3: operation model

### 5.1 Runs

Table `inbound_move_run`:

- `id`, `plan_id` (CASCADE);
- `kind` `primary|add|remove`; target inbound(s);
- scope (JSON, 6.2);
- `status` `planned|running|paused|done|cancelled`, `pause_reason`;
- `grace_hours`, `keep_old`;
- `requested_by` (admin user id), `created_at`, `started_at`, `finished_at`;
- counters: planned / done / failed / skipped / remaining, cached for the page.

A run freezes its **target** when it is created. A later plan edit never changes a running run. The admin sees
"plan changed since this run was created" and can cancel it.

One open run (`planned|running|paused`) per plan. A second one is refused with the open run's id.

### 5.2 Entry points

- **CLI**: `app:plan:apply-inbounds <planId>`:
  - **dry run unless `--apply`**;
  - scope options: `--service=<id>` (one service, the recommended first test), `--from-inbound=<id>`,
    `--include-suspended`, `--include-expired`, `--include-trials`;
  - `--keep-old`, `--grace-hours=N`;
  - batching: `--batch-size=20`, `--pause=2` (seconds between batches), `--limit=N`, `--after-id=<serviceId>`;
  - `--json`.

  The dry run prints every service with: current inbound, target, the action, and why it is skipped. It also reads
  the panel (GET only, budgeted) to report who is already on B.

  `--apply` creates the run and processes it in the foreground. Exit codes: 0 all done or skipped, 1 some failed,
  2 could not start.
- **Web / Mini App** (P7, P9): "Apply to existing services" on the plan page. The page shows the dry-run counts
  computed locally, without panel calls. The admin confirms and the run is created `planned`. The scheduled task
  processes it.
- **Scheduled task** `inbound-moves`:
  - runs `app:automation:run --only=inbound-moves`, deploy job `inbound-moves|lifecycle|*:20/30|600|1800`, in the
    **lifecycle** lock group like `attach-inbounds`. It writes the same client, which must not overlap sync, suspend
    or claims;
  - processes open runs in id order, and items whose `next_attempt_at` or `detach_after` is due;
  - needs a **full** `deploy.sh`.

  The foreground CLI takes the same lock name (a MariaDB named lock, see P8 in 9.1). It refuses with "a scheduled run
  is in progress" instead of waiting.

### 5.3 Transactions and locks

- **Lock order:** `vpn_service` by primary key → its `vpn_service_inbound` rows → its `service_inbound_move` row.
  That is the order of `ServiceInboundAttacher` with one more row at the end. The run row is updated only by
  autocommit counter `UPDATE`s, never while a service is locked. No `order`, `payment`, `wallet`, `user` or
  `reseller` row is ever locked.
- **Isolation:** `READ COMMITTED` for the short transactions, as `PanelLinkSnapshot`.
- **Panel calls:** never inside a transaction (asserted like `afterSale()`: `isTransactionActive()` → refuse).
- **Budget:** `PanelCallBudget` of 30 s per service step. `recordHealth=false`, so a bad run never marks the panel
  degraded or stops its sales (as H0 bulk runs and M2b).

### 5.4 Idempotency, retries, interruptions

- **Idempotent:** attach and detach skip what is done [3x-ui]. Writes are conditional on the state read. A re-run
  of a done item is a no-op.
- **Retries:** the existing machinery, same column names and rules:
  - `attempts`, `next_attempt_at` from `MultiInboundSettings::nextAttemptAt()` (`plans.attach_retry_delay_minutes`
    × 2^(n-1), capped at 6 h);
  - give up at `plans.attach_max_attempts`;
  - `alert_state`/`alerted_at` claimed by a conditional `UPDATE`;
  - alerts through `InboundAttachAlerts`: one per run when it pauses or finishes with failures, and one per service
    when it gives up. Ids and titles only, error masked (`VpnServiceInbound::safeError()`).
- **Stale claim:** `attaching`/`detaching` older than `ServiceInboundAttacher::STALE_CLAIM` (5 min) is taken over.
  A process killed mid-step leaves one of:

| Killed during | Panel | Local | Next pass |
|---|---|---|---|
| attach | on A, maybe also on B | A | GET finds B or attaches it (skip if present) |
| write (step 3) | A + B | A (rolled back) | GET finds B, writes step 3 |
| snapshot | A + B | B | Snapshot refreshed on the next pass; until then the display guard (5.5) applies |
| detach | A + B or B | B | GET; detach skips if A already gone |

- **Pause:**
  - a run button or `--pause-run=<id>`: the runner finishes the item in hand and takes no more;
  - **automatic** after 5 failed panel reads in a row (as `InboundAttachScheduler`), with `pause_reason` "panel not
    answering" and one alert;
  - resume is a button or the CLI with `--resume=<runId>`. `--after-id` is for CLI dry runs and partial manual
    passes.
- **Undo** (run-level button, or `--undo=<runId>`):
  - for items `switched` and not yet detached: detach B (only if B is not one of the plan's additional inbounds), set
    `inbound = A`, restore the remote id, refresh links, state `undone`;
  - for `done` items: undo is a **new run** B → A. It needs A to exist and be active. The page says "the old inbound
    was already removed from N services".

  That is why the grace period is the safety net.
- **Cancel:** `planned` items become `cancelled`. Items in flight finish their current step.

### 5.5 Display guard: never show an inbound the service left

There is a new column `vpn_service.inbound_changed_at` (NULL). It is set in step 3.

`FinalConfigLinkProvider::getFinalLinkSetForService()` gets one more rule for 3x-ui services: when
`inbound_changed_at > lastAccessInfoSyncedAt`, the stored single links are **withheld**. Only the subscription URL is
shown (it is live, served by the panel), together with "Your links are being updated; use the subscription link or
try again in a few minutes." It reuses the `accessWithheld` path of `SubscriptionPageView`, and the bot uses the same
text. The next successful refresh clears the condition.

No panel call happens on view (rule 9.1 stays).

### 5.6 Visibility

- **Plan page** (web, Mini App):
  - the open or last run: status, counts (done / failed / skipped by reason / remaining), started by, started at;
  - buttons: pause, resume, cancel, undo, "retry failed now".
- **Service page:**
  - "Inbound move #run: A → B, state, attempts, next try, last error, old inbound removed at / due at";
  - its own "retry now" (rate limiter like `admin_service_attach`).
- **Services list:** filter "being moved / move failed" (one query per page).
- **Audit:**
  - `plan.inbounds_apply_started` / `…_paused` / `…_finished` / `…_undone`, with counts only;
  - per service, the move row is the record (no audit row per service, to keep the audit log small).
- **Panel doctor / `app:panel:doctor`:** counts of failed and given-up moves.
- **`app:service:verify-links`:** a service whose move is `switched` must have B on the client. When it is `done`,
  A must not be on the client (DIFF otherwise).

### 5.7 No effect on sales

- No change in `PaymentSettlementService`, `VpnProvisioningService`, `PlanSaleRule`, `PlanCatalog` or checkout.
- A sale during a run gets the plan's current primary, as today. It is not part of a run frozen before it.
- The runner never locks order or payment rows (5.3), so lock waits on the settlement path are unchanged.
- Tested in 8.1 T14.

## 6. Part 4: triggers and scope

### 6.1 Trigger

**DECISION NEEDED (P4).** Recommendation: **explicit only.**

- The admin presses "Apply to existing services", or runs the CLI, after the notice of P1.
- Automatic on plan save is rejected:
  - a typo in the inbound field would rewrite every customer's panel client within minutes;
  - there is no dry run and no "test on one service first";
  - the wizard bulk relink (fact 11) would trigger it for many plans at once.
- The plan form may offer a checkbox "after saving, open Apply to existing services". It opens the dry-run page and
  never starts a run.

### 6.2 Scope

| Option | Default | Note |
|---|---|---|
| One service (`--service`, service page "move this service to the plan's inbound") | — | **Recommended first step on production** |
| Only services now on inbound X (`--from-inbound`) | all | For plans whose services are spread over several old inbounds (sold under different primaries over time); each item records its own A |
| Active | **in** | |
| Suspended | out (`--include-suspended`) | Attach and detach work on a disabled client [unverified U5]; links refresh too |
| Expired | out (`--include-expired`) | Usually not worth panel calls; renewal later keeps the old inbound unless moved |
| Deleted | **never** | |
| Services on another panel | always skipped `OTHER_PANEL` | 4.4 |
| Legacy panel | always skipped `NOT_MODERN` | 4.5 |
| Trials | out (`--include-trials`) | Trial services are short-lived; the trial backing plan is single-inbound (M2d B5), so only `kind=primary` applies |
| Services with an unfinished move in another run | skipped "in run #N" | One move per service at a time |
| Moved more than 3 times in 24 h | skipped "moved too often" | Flap guard, like `MAX_REATTACH_CYCLES` / `REATTACH_WINDOW_HOURS` |

### 6.3 Reseller-sold and claimed services (D5)

- Moved like any other: the client is the customer's, and the reseller's price does not change.
- The reseller views (`MiniAppResellerServiceController` uses `SubscriptionPageViewFactory::summary()`, no links) are
  unchanged. The run page lists service ids and titles, never links, and no message goes to a reseller.
- **Optional customer notice (DECISION NEEDED P5).** After phase 1 there is one message, sent to the service's
  **current** owner only. The owner is read fresh as in M2e: the same abandon rules (`owner_changed`,
  `service_not_active`). The message carries **no link**: "The server of your service #id has changed. Open your app
  and update the subscription; old single configs stop working on <date>." It is claimed once per move row.
  Recommendation: on, but only after phase 1 has run on one test service.

### 6.4 Additional inbounds (`kind=add|remove`, the old M3/M4)

- **add:**
  - creates `pending` `vpn_service_inbound` rows with `last_error` "added by run #N" and `next_attempt_at = now`;
  - the existing `InboundAttachScheduler` / `ServiceInboundAttacher` attach them, with no new attach code;
  - the run counts rows by state;
  - requires `plans.multi_inbound_enabled`.
- **remove:**
  - the run's own detach step (4.3 step 5 rules: never the primary, never the last inbound);
  - the row becomes `detached`;
  - snapshot refresh.

  The reconcile already leaves `detached` rows alone (`InboundAttachScheduler.php:252-257` handles `attached`,
  `failed` and stale `pending` only). To be pinned by a test.

## 7. Part 5: link correctness audit

Where a customer or admin can see a stored link, where it is read from, and when it is refreshed [ours]:

| Place | Reads | Refreshed |
|---|---|---|
| Bot: customer subscription / resend config / QR (`ServiceManagementService.php:214,267,303`) | Legacy: panel first (`ServiceConfigDeliveryRefresher`), then stored. Modern: stored snapshot only (`FinalConfigLinkProvider::shouldUseStoredLinksOnly()`, `:67-74`). Other: stored ∪ generated from `service.inbound` | Legacy: on every delivery. Modern: never on view |
| Bot: admin service detail (`:171,419,1546`) | same | same |
| `/s/{token}` and the Mini App customer service page (`SubscriptionPageViewFactory::create()`, `:46-86`; `MiniAppCustomerController`) | `CustomerAccessGuard` over `FinalConfigLinkProvider`; subscription URL and its QR = the panel's public URL (live) | Never on view |
| Delivery message of a new service (`PaymentCustomerNotifier`, M2e `PaymentDeliveryLog`) | links from provisioning (panel `subLinks`) | At sale; resends within 60 min use the stored set |
| Renewal / add-traffic confirmations (`PaymentCustomerNotifier.php:431,469`; legacy refresh `:535`) | `FinalConfigLinkProvider` | Legacy only |
| Follow-up "N more servers" (`service.inbounds_attached`) | no link | — |
| Payment return page (`PaymentReturnController.php:73`) | raw `configText` through `CustomerAccessGuard` | Never |
| Web admin service page (`VpnServiceCrudController.php:202`), Mini App admin service detail, `app:service:debug-links` | `FinalConfigLinkProvider` / stored | Never on view |
| "Regenerate config" (web, Mini App), claim "reset my links", `app:service:regenerate-config`, `app:inbound:regenerate-service-configs`, web inbound action, `sync-inbounds --regenerate-configs` | Modern: panel `subLinks` (H0). Legacy: legacy builder from the remote id's inbound | On demand |
| After "Retry attach" / scheduled attach | `PanelLinkSnapshot` | After each successful attach |
| Exports | Reseller report CSV (`ResellerAdminController.php:274-289`): no links. No other export carries links (grep for CSV/export in `src/`) | — |
| Broadcasts | no service links | — |

**Minimal change so a customer never sees links of an inbound the service is no longer on:**

1. The move refreshes the snapshot right after it switches the local inbound, and again after the detach (4.3).
2. Display guard 5.5 covers the gap: refresh failed, empty answer, or process killed.
3. Nothing else. Today, without moves, no service is "no longer on" its inbound except through hand edits in the
   panel. For those, Regenerate already gives the panel's truth (modern). The P1 suffix (e) explains why it still
   names A.

**"Update" when the panel still answers with A** (the owner's case): the button keeps doing exactly what it does. Its
message gains suffix (e). After P7 it adds "use Apply to existing services". It never builds B's links.

**Not changed (out of scope, already listed in H0 "Not changed"):**

- creation-time and display-time generator fallbacks for a modern service with no stored links;
- the missing scheduled refresh (H2).

Both are separate from moves. H2 would shorten how long a hand edit in the panel stays invisible.

## 8. Part 6 and 7: switches, safety, tests

### 8.1 Switches and limits

- **`plans.inbound_move_enabled`, default off**, plans tab. While off:
  - no run can be created;
  - the runner does nothing;
  - the CLI dry run still works (read-only), and so do the P1 notices and the display guard.

  Primary moves do **not** need `plans.multi_inbound_enabled` (single-inbound plans are the common case). `kind=add`
  does.
- `plans.inbound_move_detach_grace_hours` (24, 0–168), `plans.inbound_move_batch_size` (20, 1–100),
  `plans.inbound_move_pause_seconds` (2, 0–60).
- Retries reuse `plans.attach_retry_enabled`, `plans.attach_max_attempts` and `plans.attach_retry_delay_minutes`.
- **Panel call budget:** per service step at most 4 calls (GET, attach/detach, GET, `subLinks`), within 30 s. Per run
  pass at most `batch_size` services, then the pause. At most 5 failures in a row, then pause and alert.
- **Slow or down panel:** the run pauses itself. No customer-facing change: links stay as they were, A still serves.
- **Flap:** one open run per plan, one move per service, at most 3 moves per service per 24 h. A plan changed back
  (B → A) while a run is open is told to cancel or undo the run.

### 8.2 Automated tests

Fake 3x-ui (`Fake3xuiPanel`): give its `detach` the v3.11.0 semantics. That means skip if not attached, keep
traffic, and **allow** zero inbounds, so our guard is what is tested.

- **T1** P1 counts: by inbound and status, deleted excluded, multi-inbound rows, other-panel split. A fixed query
  count per page (`QueryCounter`).
- **T2** P1 texts on every surface of 3.2, the wizard and the Mini App `check` endpoint included. No write, no panel
  call.
- **T3** Dry run: no write, no panel write (request log has GETs only).
- **T4** One service, modern:
  - phase 1: attach B; local B; remote id rewritten (other parts byte-identical); snapshot = `subLinks` (A + B);
    `inbound_changed_at` set;
  - phase 2 after grace: detach A; snapshot B only;
  - UUID, subId, email, expiry, limits and traffic unchanged on the fake.
- **T5** Guards:
  - never detach the last inbound or the local primary;
  - client on neither A nor B → failed, nothing sent;
  - B on another panel, legacy panel, dummy → skipped with the reason, zero requests.
- **T6** Idempotency: re-run after done = no request. B already attached by hand → no attach call.
- **T7** Retries: back-off, give-up, alert once, "retry now" ignores back-off.
- **T8** Display guard: snapshot older than `inbound_changed_at` → single links withheld on bot, `/s/{token}`, Mini
  App and payment return page; subscription URL shown.
- **T9** Multi-inbound interplay:
  - B was an additional row → row removed;
  - A stays additional → attached row added;
  - reconcile never sees `WITHOUT_PRIMARY` during or after a move;
  - `detached` rows never re-attached.
- **T10** Undo of switched items; undo refused for done items (new run offered).
- **T11** Switch off: nothing runs; existing rows untouched.
- **T12** D5: no message to a reseller; customer notice to the fresh owner only; abandoned on owner change. No link
  in any message.
- **T13** Migration up/down; down refused while rows exist.

**Real two-process tests on MariaDB 10.11** (`ParallelStart` barrier, `tests/Support/bin/start-at.php`, as the M2c
and M2e suites):

- **P-1** two runners on the same run: each service moved once; the loser finds nothing to claim;
- **P-2** runner vs "Retry attach" on the same multi-inbound service;
- **P-3** runner vs claim "reset my links" (`rotateCredentials`): the identity check discards; the move is re-picked;
  the final snapshot belongs to the new credentials;
- **P-4** runner vs Regenerate at the same time: no lost update, final snapshot from after the switch;
- **P-5** runner vs renew / suspend (`updateClient`): both effects present;
- **P-6** `kill -9` in each step of 4.3 (the 5.4 table): the next pass finishes, with no duplicate call that changes
  state;
- **P-7** runner vs a settlement of a new order of the same plan: the settlement's order-row lock wait is unchanged
  (in the `real-time` group, like `MultiInboundLockHoldTest`);
- **P-8** foreground CLI vs scheduled task: one refuses.

### 8.3 Real-panel checklist (3x-ui v3.11.0, a test panel or test inbounds)

1. Two test inbounds A (VLESS-REALITY) and B (VLESS-WS, or another port) on one modern panel; a plan on A; buy 2
   services; record UUID, subId, usage and expiry from the panel.
2. Change the plan to B. Expect notice (a) with "2 on A"; services unchanged; Regenerate gives A's links plus suffix
   (e).
3. `app:plan:apply-inbounds <plan> --service=<s1>` (dry run): lists s1 A → B; no panel write in the panel's log.
4. `--apply --service=<s1> --grace-hours=0 --keep-old`: the panel shows s1 on A and B; same UUID, email, subId,
   expiry, `totalGB`, `limitIp`; usage unchanged.
5. The phone's "update subscription" on s1's subscription URL lists A and B; both connect.
6. Run the detach (`--grace-hours=0`, without `--keep-old`, or the run's "retire now"): s1 only on B. Its usage
   counter keeps the old value (**U1**), and new usage through B adds to it (**U2**).
7. Phone: update the subscription → B only; an old A config stops working.
8. `app:service:verify-links <s1>`: PASS.
9. Suspend s1 from the bot, resume, renew, add traffic: reflected on B; membership unchanged after each update
   (**U3**).
10. Undo test: move s2 with `--keep-old`, press undo: s2 back on A only; links A.
11. Delete B's client membership by hand and re-run: the move re-attaches. Detach A by hand during the grace period:
    the move notices and finishes without error.
12. Panel down (stop the panel) during a run of several services: the run pauses after 5 failures, one alert, nothing
    changed for customers; start the panel, resume: finishes.
13. Delete A on the panel during a grace period, then let the retire run: note the error of the detach, and that
    `inbounds/list` no longer lists A (**U9**, **U10**).
14. Note the answers for U1–U10 below and update this document.

[unverified] list:

- U1: detach keeps the client's traffic counters as one figure (code: `keepTraffic=true`);
- U2: usage on B after detaching A keeps counting toward the same quota;
- U3: `clients/update` without an `inboundIds` filter never changes membership;
- U4: `del?keepTraffic=1` then `add` of the same email re-links the kept counters (only relevant if (b) is ever
  chosen);
- U5: attach and detach work on a disabled (suspended) client and a disabled inbound;
- U6: legacy panels refuse a duplicate email across inbounds (4.5);
- U7 (P4): `POST clients/{email}/attach` keeps the client's UUID, subId, expiry, limits and traffic counters. The fake
  and phase 1 assume so (checklist step 4);
- U8 (P4): after the attach, `clients/subLinks/{subId}` lists the configs of both A and B, and the subscription URL is
  unchanged. Phase 1 stores what the panel gives (checklist step 5);
- U9 (P6): `GET panel/api/inbounds/list` has the shape the fake gives it, and lists every inbound of the panel;
- U10 (P6): what the panel answers to a detach of an inbound that was deleted. The retire does not rely on the text:
  it counts the step as done only once `inbounds/list` confirms A is gone.

## 9. Part 8: PRs (each small, reviewable, revertible)

| # | Scope | Main files | Tests | Migration / timer / deploy |
|---|---|---|---|---|
| **P1** | Visibility only: `PlanInboundDrift`; texts 3.3 on web plan form, list, wizard, trial forms, Mini App `check`/form/trial, service pages, regenerate result suffix; admin guide en/fa "Changing a plan's inbound" with the interim workaround | `src/Shop/Application/Catalog/PlanInboundDrift.php`, `PlanInboundWarnings`, `PlanCrudController`, `PanelSetupController`, `TrialPlanCrudController`, `MiniAppAdminPlanController`, `MiniAppAdminTrialController`, `VpnServiceCrudController`, `MiniAppAdminServiceController`, `ServiceConfigRegeneration` message, `translations/*`, `docs/en/admin-guide.md`, `docs/fa/admin-guide.md` | T1, T2 | none; app-only deploy |
| **P2** | Panel primitive: `ModernClientsApi::detach()`; driver `detachInbounds()` with the zero-inbound and primary guards; fake `detach` to v3.11.0 semantics. Not wired to anything | `ModernClientsApi.php`, `Sanaei3xuiModernDriver.php`, `InboundAttachDriverInterface` (or a new `InboundDetachDriverInterface`), `Fake3xuiPanel.php` | driver unit tests, T5 (guards) | none |
| **P3** | Schema and switch: `inbound_move_run`, `service_inbound_move`, `vpn_service.inbound_changed_at`; entities; settings keys (off) | `migrations/Version2026111…`, `src/Entity/*`, `MultiInboundSettings` (or `InboundMoveSettings`), settings tab | T13 | **migration** (additive; `down()` refuses with rows) |
| **P4** | Runner, CLI only, phase 1 (`--keep-old` forced: no detach yet), dry run + `--apply`, one service and batches, locks, retries, alerts | `src/Provisioning/Application/InboundMoveRunner.php`, `InboundMovePlanner.php`, `src/Command/PlanApplyInboundsCommand.php`, `InboundAttachAlerts` | T3, T4 (phase 1), T6, T7, T9, T11, P-1…P-5, P-7 | none (CLI) |
| **P5** | Display guard 5.5 | `FinalConfigLinkProvider`, `SubscriptionPageViewFactory`, bot texts | T8 | none |
| **P6** | Phase 2 (detach after grace), undo, cancel, pause/resume in CLI | runner, command | T4 (phase 2), T10, P-6 | none |
| **P7** | Web admin: plan "Apply to existing services" (dry-run page, confirm), run status, buttons, service page status, services filter, doctor counts; P1 texts gain the "use Apply" sentence | `PlanCrudController` + new controller + templates | controller tests, CSRF, permissions (PLANS_MANAGE + SERVICES_MANAGE) | none |
| **P8** | Scheduled task `inbound-moves` (lifecycle group), CLI/task mutual lock | `AutomationRunCommand`, `deploy.sh` manifest + allowlist, ops tests | P-8, ops manifest tests | **new timer → full deploy** |
| **P9** | Mini App admin: the same actions as P7 | `MiniAppAdminPlanController`, templates, `miniapp` translations | Mini App tests | none |
| **P10** | Optional customer notice (P5 decision) | `PaymentDeliveryLog`-style claim, translations | T12 | none |
| **P11** | `kind=add` (M3) and `kind=remove` (M4) on the same runner | runner, `ServiceInboundAttacher` reuse | 6.4 tests | none |

Order: P1 → P2 → P3 → P4 → P5 → P6 → P7 → P8 → P9 → (P10, P11). P1 is independent and can ship at once. P2–P6 change
no customer-visible behaviour while the switch is off. Each PR follows the repository's process: phpunit and phpstan
green; a fresh independent review; the pushed head tested from a clean checkout (M2c process rule).

### 9.1 P1, P2, P3, P4, P5, P6, P8 as built

**P1** (visibility, #94): `PlanInboundDrift` and the texts of 3.3 on every surface of 3.2; the admin guide (en/fa)
"Changing a plan's inbound" with the manual move. No write, no panel call.

**P2** (panel primitive, #95): `ModernClientsApi::detach()`; a new `InboundDetachDriverInterface` (the attach
interface is unchanged), implemented by `Sanaei3xuiModernDriver::detachInbounds()` with its result
`InboundDetachResult`. It refuses before any request on a non-modern panel, another panel, no inbound, or the primary
(local or as the remote id records it). It reads the client first (GET); it refuses a detach that would leave no
inbound; it sends only the ids still listed; it reports done only when a read afterwards confirms. The fake panel's
`detach` has the v3.11.0 semantics. Nothing calls it yet.

**P3** (schema and switch). Nothing reads or writes any of it yet; every existing behaviour is unchanged.

- Migration `Version20261104090000` (additive; online-safe; the new column starts NULL):
  - `inbound_move_run` (5.1):
    - `plan_id` (CASCADE);
    - `kind` (`primary|add|remove`);
    - `target_inbound_id` (SET NULL; one target per run);
    - `scope` (JSON);
    - `status` (`planned|running|paused|done|cancelled`), `pause_reason`;
    - `grace_hours`, `keep_old`;
    - `requested_by` (admin user id, no foreign key, as the audit log);
    - `created_at`, `started_at`, `finished_at`;
    - the cached counters `planned_count`, `done_count`, `failed_count`, `skipped_count`, `remaining_count`.
  - **One open run per plan** is enforced by the database. `open_plan_id` is the plan's id while the status is open,
    NULL otherwise, under a unique index. A CHECK constraint keeps it in step with the status for every writer. A
    second open run fails on the unique index; the runner (P4) turns that into "refused, open run #N".
  - `service_inbound_move` (the table 4.3 refers to as "4.7"; this is its definition):
    - `run_id` (CASCADE), `service_id` (CASCADE), with a unique key on (`run_id`, `service_id`): one move per service
      per run;
    - `from_inbound_id` and `to_inbound_id` (SET NULL), with `from_remote_inbound_id` and `to_remote_inbound_id`. These
      keep the panel's inbound ids, so A can still be named after a local delete (4.4, 6.2). They hold the inbound id
      only, never the service's remote id string;
    - `state` (the 4.3 states), `skip_reason`;
    - the retry columns of `vpn_service_inbound` (5.4): `attempts`, `next_attempt_at`, `alert_state`, `alerted_at`;
    - `claimed_at`, `detach_after` (NULL with keep-old), `detached_at`, `last_error` (masked);
    - `created_at`, `updated_at`;
    - indexes for the due items, (`state`, `next_attempt_at`) and (`state`, `detach_after`).
  - Rows are claimed and locked by primary key (5.3). Inbound references are SET NULL, so a run or a move never
    blocks deleting an inbound.
  - `vpn_service.inbound_changed_at` (5.5), NULL.
  - `down()` refuses, and changes nothing, while either table has a row or any service has `inbound_changed_at` set.
- Entities `InboundMoveRun` and `ServiceInboundMove` (constants for the kinds, statuses and states);
  `VpnService::getInboundChangedAt()` / `setInboundChangedAt()`, never called.
- `InboundMoveSettings` (8.1), on the plans settings tab. Each key is validated on save, and a stored value that is
  not a whole number in range reads as the default:
  - `plans.inbound_move_enabled` (off);
  - `plans.inbound_move_detach_grace_hours` (24, 0–168);
  - `plans.inbound_move_batch_size` (20, 1–100);
  - `plans.inbound_move_pause_seconds` (2, 0–60).

  The tab says that they are not active in this release. Retries will reuse the `plans.attach_*` keys of
  `MultiInboundSettings`.
- Not in P3, as planned:
  - the runner, CLI, timer and panel calls (P4, P6, P8);
  - the display guard (P5);
  - the pages (P7, P9);
  - the customer-notice claim of 6.3 (P10).

**P4** (runner, command line only, phase 1). Admin guide (en/fa) "Changing a plan's inbound": dry run, one service
first, what phase 1 does and does not do.

- `app:plan:apply-inbounds <planId>` (`src/Command/PlanApplyInboundsCommand.php`):
  - a dry run unless `--apply`: per service its inbound, the target, "move" or "skip" with the reason (table or
    `--json`). It makes only budgeted GETs (one client read per service, `inbounds/options` once per panel when 4.4
    needs it) and stops reading after 5 failed reads in a row. Nothing is written, and it works with the switch off;
  - `--apply` needs `plans.inbound_move_enabled` (otherwise exit 2 with the reason). `--service`, `--from-inbound`,
    `--include-suspended`, `--include-expired`, `--include-trials`, `--limit` and `--after-id` make the scope (deleted
    services never); `--batch-size` and `--pause` override the settings for this pass;
  - `--resume=<runId>` continues an open run of the plan (paused, interrupted, or with failed services due again). It
    takes no scope option: the target and the scope are frozen in the run row (`target_inbound_id`, `scope` JSON);
  - one open run per plan: a second `--apply` is refused and names the open run (the P3 unique key turns a race into
    the same refusal);
  - exit codes 0 / 1 (some failed, or the run paused) / 2 (could not start). The output holds ids and titles only.
- `InboundMovePlanner`:
  - the scope and the skip reasons of 6.2 and 4.4: other panel, not modern, not a real panel, remote id not
    structured, in another unfinished run, moved 3 times within 24 h, protocol mix, already on the target;
  - run creation: `keep_old = 1` forced, `grace_hours` stored but unused, kind=primary only; an inactive target
    refuses the run. It writes the audit entry `plan.inbounds_apply_started` (counts only).
- `InboundMoveRunner::process()`: the states planned → attaching → switched, plus failed and skipped (`done` belongs to
  phase 2, P6). Per service, 4.3 steps 1-4:
  1. Claim: a READ COMMITTED transaction locks `vpn_service` → `vpn_service_inbound` → `service_inbound_move` by
     primary key, re-checks every condition under the locks, and sets `claimed_at` and `attempts + 1`. The order type
     (trial) is a plain read: no order, payment, wallet, user or reseller row is ever locked or written.
  2. Panel step, with no transaction open: `InboundMoveDriverInterface::attachForMove()` (new, in
     `Sanaei3xuiModernDriver`). It reads the client (GET), refuses with nothing sent when the client is on neither A
     nor B, attaches B only if it is missing, and reads again (GET) to confirm. With the link refresh that is at most 4
     calls per service, inside `PanelCallBudget` 30 s, with `recordHealth=false`.
  3. Write: a transaction with the same lock order and the identity check (inbound, UUID, subId, remote id). It:
     - sets `inbound_id = B`;
     - rewrites only the `inbound=` / `remoteInbound=` parts of the remote id
       (`Sanaei3xuiRemoteIdParser::withInbound()`);
     - sets `inbound_changed_at`;
     - deletes B's additional row;
     - adds an additional row for A when the plan still lists A (attached when the read listed it, otherwise failed and
       due).

     An identity change writes nothing: the move goes back to planned and is picked again once in the same pass.
  4. `PanelLinkSnapshot::refresh()`, with its rules (identity check; an empty answer keeps the links). A switched
     service whose links were not refreshed is refreshed on the next pass, and the run stays open meanwhile.
- Retries and alerts:
  - failures back off with the `plans.attach_*` settings; when the attempts are used up the move is given up, with
    one `moveGaveUp` alert per service;
  - a claim older than `ServiceInboundAttacher::STALE_CLAIM` is taken over;
  - 5 failed panel reads in a row pause the run, with one `moveRunEnded` alert; only `--resume` restarts it;
  - a run is done when nothing in it can still change. Then it writes the audit entry `plan.inbounds_apply_finished`
    (counts) and, if some failed, sends one alert;
  - all alerts go through `InboundAttachAlerts`.
- Errors that are shown, logged, stored or alerted pass through `SafeText`: `ServiceErrorText` / `SecretRedactor`, the
  client email masked, and the panel's token and password removed (read through `PanelCredentials`).
- Found and fixed while testing: an exception in the panel step that was not a panel answer left the move "attaching"
  and stopped the pass. It is now a masked failure with back-off.
- Left as is (outside P4): the existing per-request log `sanaei_modern_request` of `ModernPanelHttpClient` names the
  request path. That path holds the client email; its Telegram id is masked by `RedactingLogger`.
- Tests in `tests/Shop/InboundMove/`, on the fake 3x-ui (run locally on MariaDB 11.4.5; CI uses 10.11):
  - T3, T4 (phase 1), T6 and T11: `InboundMovePhaseOneTest`;
  - T5, with a sale during an open run: `InboundMoveGuardsTest`;
  - T7: `InboundMoveRetryTest`;
  - T9: `InboundMoveMultiInboundTest`;
  - redaction with `zend.exception_ignore_args` 0 and 1: `InboundMoveRedactionTest`;
  - sales, settlement, regenerate and plan save unchanged with the switch on: `InboundMoveSwitchHasNoEffectTest`;
  - the driver step: `tests/Provisioning/Sanaei/ModernInboundMoveAttachDriverTest.php`;
  - two processes, through `tests/Support/bin/inbound-move.php` (`Fake3xuiPanel::$onRequest` stops a helper at an
    exact request, outside the fake's lock):
    - P-1 … P-5, and P-6 for the phase-1 steps: `InboundMoveParallelTest`;
    - P-7, in the `real-time` group: `InboundMoveLockHoldTest`;
    - P-6 for the detach steps: `InboundMoveRetireParallelTest` (P6), and P-8: `InboundMoveScheduledParallelTest` (P8).
- Not tested: a real 3x-ui panel. U7 and U8 were added to 8.3.

**P5** (display guard 5.5, no panel call, no schema change, no switch). Admin guide (en/fa) "What customers see during
a move".

- The rule, in one place: `FinalConfigLinkProvider::linksPending()`. A service is pending when all of these hold:
  - its panel is `sanaei_3xui` in modern mode (legacy panels rebuild links before each bot delivery; other types have
    no snapshot);
  - `vpn_service.inbound_changed_at` is set (only the move runner sets it; NULL for every service until a move);
  - `last_access_info_synced_at` is NULL or older, compared in whole seconds as the columns store them, the same test
    as the runner's `LINKS_PENDING_SQL`.

  A successful `PanelLinkSnapshot` store writes `last_access_info_synced_at` under the row lock, after its identity
  check, for "updated" and "unchanged" alike. So the next successful refresh clears the condition by itself (the
  runner's next pass, Regenerate, reset links). A failed read, an empty answer or a credential change meanwhile write
  nothing, and the guard stays.
- `getFinalLinkSetForService()` returns no links with `VpnConfigLinkSetResult::$linksPending` set (generated fallback
  links included: they are not the panel's word either). It logs
  `[FinalConfigLinkProvider] links_pending service_id=… source_flow=…` (ids only).
- One text for every surface: `subscription:link.updating` (fa/en, `LinksUpdatingNotice`). It names no link, token,
  UUID or owner. The places of section 7 and where they read now:
  - `/s/{token}` and the Mini App subscription view: `SubscriptionPageViewFactory::create()`, through the provider.
    `SubscriptionPageView::$linksUpdating` sits next to `accessWithheld` in `subscription/_link.html.twig`: the
    subscription URL and its QR with the text, or the text alone when there is no subscription URL (never "missing"
    or "contact support"). It never counts as withheld (no admin alert). The Mini App service summary and the
    reseller views carry no links and are unchanged (D5);
  - payment return page: `PaymentReturnController` read the raw `configText`. It now asks `linksPending()` first and
    shows the text (Persian page, Persian text);
  - bot: `VpnAccessMessageFormatter` (service detail, "Subscription", "Configs", the delivery of a new service, the
    missing-delivery resend), the config-QR fallback, the admin service detail, and the renewal / add-traffic
    confirmations (`PaymentCustomerNotifier::configLinkLines()`). All read the provider's result; while pending they
    send the subscription URL with the text, or the text alone. "My services" lists buttons only;
  - QR: the subscription URL's QR is unchanged (live); a config QR is never made while pending;
  - exports: none carries links (section 7).
- Unchanged on purpose: the web admin service page and `app:service:debug-links` still show the stored snapshot (an
  admin's diagnostic view, with the P1 notice). Regenerate, the runner, sales, settlement and provisioning are
  untouched.
- With `inbound_changed_at` NULL, every surface renders byte-identical to before P5. This was checked once against
  `develop/v2` on 22 renders (page fa/en, Mini App fa/en, payment return, four bot buttons, admin detail, renewal and
  new-service messages, with and without a subscription URL).
- Tests: `tests/Shop/InboundMove/InboundMoveDisplayGuardTest.php`:
  - T8 end to end: the runner moves a service while `subLinks` fails; every surface fa/en, with no panel request; a
    real refresh brings the links back;
  - the text alone without a subscription URL;
  - never withheld for NULL, refreshed after or in the same second, a legacy panel, or another panel type (each
    surface identical to the NULL case);
  - escaping, fa/en key parity, and logs with `zend.exception_ignore_args` 0 and 1.
- Not tested: a real 3x-ui panel.

**P6** (phase 2 "retire" and the run controls, CLI only; no schema change, the P3 tables were enough). Admin guide
(en/fa) "Moving existing services with the command".

- Phase 2 (4.3 step 5), in `InboundMoveRunner`. A move in state `switched` whose `detach_after` has passed is retired:
  - claim with the check `inbound_id = B`, lock order `vpn_service` -> its `vpn_service_inbound` rows -> its move row;
  - then, with no transaction and no lock, a GET of the client. A not listed: done with no detach. B missing: B is
    attached again first and the retire is picked up again as its own step (at most 4 calls in a step, within
    `PanelCallBudget`). Neither A nor B: failed, nothing sent. A the only inbound, or A the local primary: refused;
  - detach [A], GET; A gone: `done`, `detached_at`, then the refresh (links only from `subLinks`, never self-built or
    merged). A deleted on the panel counts as done once `inbounds/list` confirms it (`panelHasInbound()`, NULL =
    unreadable, which is a failure and not a confirmation);
  - a failed retire is `failed` with `detach_after` still set (a phase-1 failure has it NULL). It backs off, gives up
    and alerts with the `plans.attach_*` settings. Attempts restart at the retire phase.
- `detach_after` = now + the run's `grace_hours` (`--grace-hours`, or `plans.inbound_move_detach_grace_hours`, default
  24, 0-168). `--keep-old` at creation: NULL, never retired automatically. With grace 0 the retire happens in the same
  pass.
- Due retirements from the command line (P8 added the timer). `--resume=<runId>` handles phase 1 and due
  retirements in one pass, idempotently. `--apply` does the same for the run it creates; **while a run is open a second
  `--apply` stays refused** (5.1, one open run), and names the run to resume.
- `--retire-now[=<runId>]` (default: the plan's latest run; `--service=<id>` for one) sets the moves due. It does not
  call the panel by itself: combine it with `--resume=<runId>`, or run that next. It also re-arms failed retires
  (attempts 0, alert reset) and reopens a finished run as paused.
- `--pause-run`: the run stops after the service in hand (`stillRunning()` is checked before each item). One audit entry
  and one alert.
- `--cancel`: planned moves, and phase-1 failures waiting for a retry, become `cancelled`. A move in flight finishes
  its step. The run stays open while moved services wait for their retire. A dead process's `attaching` row in a
  cancelled run can still be taken over by `--resume`. Audit `plan.inbounds_apply_cancelled` (not in the original list).
- `--undo`: for moves switched and not detached: claim (state `detaching`), GET, attach A again if needed, then the
  local write (inbound A, remote id, `inbound_changed_at`), then detach B unless it is one of the plan's additional
  inbounds, then the refresh. `undone` with `next_attempt_at` set means the removal of B is pending. Services not moved
  yet are cancelled. `done` moves are not undone: the output says a new run B -> A is needed, how many services, and
  "the old inbound was already removed from N services". Exit code 1 when anything was left. A service whose A was
  deleted locally fails: it cannot be put back.
- **Undo has no per-service back-off or give-up alert** (a deviation from "the same retries"): it is a foreground admin
  action, retried by running `--undo` again, and the task of P8 does not retry it. The 5-in-a-row pause, the one run alert
  and the masked errors do apply.
- Audit: `plan.inbounds_apply_paused` (`by`: `admin` or `automatic`), `plan.inbounds_apply_undone`, counts only.
- **Second-precision stamps.** `inbound_changed_at` and `last_access_info_synced_at` are stored in whole seconds, so a
  retire in the same second as the last refresh would read as "already refreshed" if the process died before its own
  refresh (the P-6 kill test found this): the run closed with A's link stored and shown. Every write of
  `inbound_changed_at` in the runner (switch, retire, undo) now also clears `last_access_info_synced_at`, which
  `linksPending()` and `LINKS_PENDING_SQL` read as pending. Nothing else reads that column.
- The retire sets `inbound_changed_at` (not in 4.3 step 5; it follows 5.5): A's link is hidden until the refresh. A
  `done` row with links pending is refreshed by the next pass, and the run waits for it.
- **Known limit.** The retire does not change `PanelLinkSnapshot`'s identity (the remote id stays), so a Regenerate
  that read `subLinks` BEFORE the detach and stores AFTER the runner's refresh leaves A's link in the stored snapshot
  until the next refresh. No state is lost, and the subscription URL is live. `PanelLinkSnapshot` was left alone (out of
  scope). `testAStaleRegenerateCannotUndoTheRetire` pins what holds in that order.
- Off the sale path: no order, payment, wallet, user or reseller row is locked or written; `PaymentSettlementService`,
  `VpnProvisioningService`, `PlanSaleRule`, `PlanCatalog` and checkout are untouched. Panel calls are never in a
  transaction; `PanelCallBudget` per step; `recordHealth=false`.
- Fixed on the way: a skip at the claim was reported as "busy" (P4); it is now reported as skipped.
- Tests (`tests/Shop/InboundMove`, MariaDB and the fake panel):
  - `InboundMoveRetireTest`: T4 phase 2, T5 in the retire, idempotency, retries and give-up, the automatic pause, the
    same-second refresh;
  - `InboundMoveControlsTest`: T10, cancel, pause and resume, the switch off;
  - `InboundMoveRetireParallelTest` (real processes): P-6 kill -9 after the attach check, after the detach, before the
    snapshot and after a re-attach of B; two runners; suspend, renew, link reset and Regenerate on a service being
    retired;
  - `InboundMoveRedactionTest`: masked errors with `zend.exception_ignore_args` 0 and 1, and D5 for reseller-sold and
    claimed services through both phases and the undo.
- Not tested: a real 3x-ui panel. U9 and U10 were added to 8.3.

**P8** (the scheduled task, built before P7 so that a run created from the web has something to process it; no schema
change, no change to what a pass does to a service). Admin guide (en/fa), `docs/*/automation.md`.

- `InboundMoveScheduler`, `app:automation:run --only=inbound-moves` (also part of `--only=all`): the runs in `planned` or
  `running`, of `kind=primary`, in order of id. All the work is `InboundMoveRunner::process()`, so a due item is the
  runner's own rule (phase 1 leftovers, retries past `next_attempt_at`, retirements past `detach_after`, links to
  refresh), with `plans.inbound_move_batch_size`, `plans.inbound_move_pause_seconds`, `PanelCallBudget`, the 5-in-a-row
  pause and the `plans.attach_*` rules. `--limit` does not apply. `--dry-run` counts what is due; no lock, no panel, no write.
- `process()` gained two arguments whose defaults change nothing for P4 and P6: `leavePaused` (a paused run is not
  resumed, also when the pause arrives between the task's query and the runner's first write) and `stopAt`.
- With the switch off: nothing at all, not even the lock; success.
- **Time budget:** `TIME_BUDGET_SECONDS` = 420 s, for a job timeout of 600 s. No service starts after it, the pause between
  batches is cut to what is left, and the service in hand may take about 120 s more (two steps with their refreshes). The
  rest stays due. `tests/ops/scheduled-jobs.test.sh` ties the constant to the manifest.
- **The lock is not a Symfony lock, as 5.2 first said.** `symfony/lock` is not a dependency. `InboundMoveLock` is a
  MariaDB `GET_LOCK` with timeout 0, on a connection of its own, named after a hash of the database name (a named lock
  is server-wide). The server releases it when the connection ends, so a killed process leaves no lock, and there is no
  file shared between the www-data jobs and an admin's shell. If that connection is dropped by the server during a pass
  the lock is lost silently; the claims of the moves still keep one item to one runner.
- The command takes the lock for `--apply`, `--resume`, `--retire-now` and `--undo`, and refuses with "a scheduled run is in
  progress, try again in a minute" (exit 2). The check comes after the option checks and before the run checks. Two
  commands now refuse each other too. `--pause-run`, `--cancel` and the dry run do not take it.
- The task skips with one log line (`inbound_move_task_skipped`) and exit 0. A panel that does not answer pauses the run: an
  alert, not a failed job. Only a run that throws fails the job (logged masked), and the next run still goes.
- A run that `--cancel` left open (moved services waiting for their retire) stays `running` (P6), so the task finishes those
  retires.
- Deploy: `inbound-moves|lifecycle|*:20/30|600|1800`, on the allowlist, max age 6000 s, `NEW` until the first run. It
  needs a **full** deploy.
- **:20 and :50 are also `expiry`'s minutes** in the lifecycle group (`*:5/15`). The group lock serialises them, so they
  never overlap: the later one waits up to 120 s, then skips one slot. A long pass can make the next lifecycle jobs skip
  a slot too, at any offset. The free offset nearest to :20 is `*:21/30` (no timer of any group on :21 or :51).
- Tests: `InboundMoveScheduledTest`, `InboundMoveScheduledParallelTest` (P-8 with real processes: both directions, a
  simultaneous start, a pause during a pass, kill -9 after the attach, before the snapshot, after the detach), and the
  ops tests in `tests/ops/scheduled-jobs.test.sh`. Tests of two runners on one item now call the runner directly
  (helper mode `runner`), since two commands refuse each other.
- Not tested: a real 3x-ui panel, a real server or systemd timer, or a real `deploy.sh` run.

## 10. Open questions for the owner

Marked **[choice]** where your decision changes the build. The others have a recommendation that will be built unless
you say otherwise.

1. **P1 [choice].** Ship the visibility PR first on its own, before any move code? *Recommended: yes.*
2. **P2 [choice].** Model: (a) attach-then-detach in two phases, with a grace period (default 24 h)? (c) "keep old"
   as an option; (b) rejected. *Recommended: yes; grace 24 h.*
3. **P3 [choice].** Legacy panels: report-only (skipped `NOT_MODERN`), no automated move? *Recommended: yes.*
4. **P4 [choice].** Trigger: explicit admin action only, never on plan save? *Recommended: yes.*
5. **P5 [choice].** Customer notice after the switch (no links, to the current owner only)? *Recommended: on, after a
   one-service test.*
6. Default scope: active only; suspended, expired and trials opt-in; deleted never? *Recommended: yes.*
7. **[choice]** Moving to an inbound on **another panel**: out of scope (skipped)? It would need a new client on the
   other panel, a new subscription URL and the loss of panel usage history. *Recommended: out of scope; separate
   design if needed.*
8. Retire the old inbound automatically after the grace period, or only when the admin presses "retire now"?
   *Recommended: automatically, as the run's setting; the admin can choose "keep old".*
9. Switch name and default: `plans.inbound_move_enabled`, off? *Recommended: yes.*
10. **[choice]** Panel setup wizard: it bypasses `PlanAdminService::save()` and the `PlanInbounds` validator (fact
    11). P1 adds only the notice. Route the wizard through `PlanAdminService::save()` in its own small PR? That is a
    behaviour change: invalid combinations would then be refused there too. *Recommended: yes, separately.*
11. H2 (scheduled snapshot refresh) remains unbuilt. Should it be scheduled before or after this feature? *Recommended:
    after P6; it is independent.*
