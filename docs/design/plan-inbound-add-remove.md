# Adding or removing one inbound on a plan's existing services (P11) — design

Status: **design for review. Nothing here is built.** No code, no migration.

This document continues [plan-inbound-change.md](plan-inbound-change.md) (called "the move design" below), section 6.4
and the row P11 of its section 9. Where the two disagree, this document overrides 6.4. The differences are listed in
3.4. Everything else in the move design stands, the links rule (H0, multi-inbound-plans.md 9.1) in particular.

Items marked **DECISION NEEDED (Qn)** carry a recommendation. The owner decides them in 12.2.

Labels used for facts:

- **[ours]**: read in this repository at `develop/v2` 80f57ac, with the file cited;
- **[3x-ui]**: as recorded in section 1 of the move design (3x-ui `v3.11.0` source);
- **[unverified]**: an assumption, to check on a real panel. Each one is collected in 11.3.

## 1. Goal

Today a run does one thing: `kind=primary` moves a service's primary inbound A to B (the move design, 4.3). P11 adds
two kinds next to it, on the existing services of one plan:

| Kind | What happens to a service | Afterwards |
|---|---|---|
| `add` | The client gets one more inbound C. It keeps every inbound it is on | Primary unchanged, one more additional row, more links |
| `remove` | The client leaves one additional inbound D, without a replacement | Primary unchanged, one row less, fewer links |

One run has one target inbound. Two inbounds to add means two runs, one after the other. The primary inbound is never
the target of a `remove`: changing the primary is what `kind=primary` is for (Q4).

Not in P11: the customer message (P10), moving to another panel, legacy panels, and any automatic start. A run starts
only from an explicit admin action, as the move design 6.1 decided.

## 2. What the code has today (each claim checked)

| # | Fact [ours] | Evidence |
|---|---|---|
| 1 | The schema already allows the kinds. `kind` is a `VARCHAR(16)` with no CHECK. `primary`, `add` and `remove` are constants | `migrations/Version20261104090000.php`, `src/Entity/InboundMoveRun.php:34-37` |
| 2 | Every entry point refuses or hides other kinds, so a row of another kind is inert today | `InboundMoveRunner.php:126` and `:230`, `InboundMoveControls.php:268`, `InboundMoveScheduler.php:119`, `InboundMoveOverview.php:60`, `InboundMoveActions::planOfRun()` |
| 3 | One open run per plan, of any kind, is enforced by the database | `open_plan_id`, unique index and CHECK constraint in the P3 migration |
| 4 | `attachForMove()` already does what `add` needs. It reads the client, returns "already on target" if C is listed, fails with nothing sent if the client is not on the primary, attaches C only, and confirms by a read | `Sanaei3xuiModernDriver.php:325-375` |
| 5 | `detachInbounds()` already does what `remove` needs. It refuses the primary, refuses to leave no inbound, fails with nothing sent when the client is not on its primary, and confirms by a read | `Sanaei3xuiModernDriver.php:238-300` |
| 6 | The attach timer treats an `attached` row whose inbound is gone from the client as "removed by hand" and **attaches it again** | `InboundAttachScheduler.php:253-254` (`reconcile()`) |
| 7 | The attach timer leaves `detached` rows alone, and so does the attacher | `InboundAttachScheduler.php:253-260`, `ServiceInboundAttacher.php:187-202` |
| 8 | **A `detached` row still blocks deleting the inbound.** The foreign key is RESTRICT and `deletionProblem()` counts every row, `detached` included | `VpnServiceInbound.php:48`, `PlanInboundUsage.php:134-150` |
| 9 | `detached` is written only when a service is deleted | `ServiceAdministration.php:86` |
| 10 | Editing a plan changes new sales only. An order keeps a snapshot of the plan's inbounds, so an order taken before an edit and paid after it uses the old set | `Order.php:233-252`, `VpnProvisioningService::orderInbounds()` |
| 11 | A renewal creates no service and does not read or write inbound rows. It updates the client (expiry, limits) | `ServiceRenewalService.php` has no inbound code; U3 of the move design covers membership |
| 12 | The drift notice already counts both directions: services **missing** an additional inbound of the plan, and services holding an **extra** one the plan no longer has | `PlanInboundDrift::additionalDrift()` |
| 13 | The display guard reads `vpn_service.inbound_changed_at` against `last_access_info_synced_at`. The runner clears the second stamp whenever it writes the first | `FinalConfigLinkProvider::linksPending()`, `InboundMoveRunner::LINKS_PENDING_SQL`, `finishRetire()` |
| 14 | A trial's backing plan is single-inbound (M2d B5) and locked | `TrialBackingPlans`, `docs/design/trial-backing-plans.md` |

## 3. Reuse, and what changes

### 3.1 Reused as it is

| Piece | How |
|---|---|
| `inbound_move_run`, `service_inbound_move` | One run row per run and one move row per service, with the same columns |
| `InboundMoveRunner::process()` | Same pass, batches, pause between batches, stale claim, 5-failures pause, `stillRunning()` check |
| `InboundMoveLock` (`GET_LOCK`) | Same lock name. Only one pass of any kind runs at a time. Command and task refuse each other as today |
| `inbound-moves` task | Same job and timer. `openRunIds()` stops filtering on kind. No new timer, so no full deploy for that reason |
| Batch and pause | `plans.inbound_move_batch_size`, `plans.inbound_move_pause_seconds` |
| Retries and alerts | `plans.attach_*` back-off and give-up. `InboundAttachAlerts::moveRunEnded()` and `moveGaveUp()`, with the kind in the text |
| Pause, resume, cancel, retry failed | `InboundMoveControls`, with the changes of 3.3 |
| Lock order and transactions | `vpn_service` → its `vpn_service_inbound` rows → its move row, READ COMMITTED, panel calls never inside a transaction, `PanelCallBudget` 30 s, `recordHealth=false` |
| Flap guard | At most 3 changes per service in 24 h, counted across all kinds |
| One open run, one unfinished move per service | Unchanged: `open_plan_id`, `SKIP_IN_OTHER_RUN` |
| Drivers | No driver change: facts 4 and 5 |

### 3.2 Schema

**No migration is needed.** No new column, no new enum value, no new table.

| Column | `primary` (today) | `add` | `remove` |
|---|---|---|---|
| `run.kind` | `primary` | `add` | `remove` |
| `run.target_inbound_id` | B | C | D |
| `run.keep_old` | as chosen | always 0 | always 0 |
| `run.grace_hours` | as chosen | always 0 | as chosen (6.5) |
| `move.from_inbound_id`, `from_remote_inbound_id` | A | NULL | D, its remote id taken from the service's row |
| `move.to_inbound_id`, `to_remote_inbound_id` | B | C | NULL |
| `move.detach_after` | set at the switch | NULL | set when the run is created: now + grace |
| `move.skip_reason` on a `done` row | NULL | `already_on_panel` when nothing was attached (5.3) | NULL |

The states are the existing ones. Their meaning per kind:

| State | `add` | `remove` |
|---|---|---|
| `planned` | Waiting | Waiting. Due once `detach_after` has passed |
| `attaching` | Claimed, panel step running | Not used |
| `switched` | On the panel and row written, links not refreshed yet | Not used |
| `detaching` | Not used (undo only) | Claimed, panel step running |
| `done` | Row written and links refreshed | Row deleted, `detached_at` set |
| `failed` | Back-off, then given up | Back-off, then given up. `detach_after` is kept, as for a failed retire |
| `skipped`, `cancelled`, `undone` | As today | As today |

### 3.3 Code that must become kind-aware

These are small changes, listed so that P11a can be reviewed against them:

- **The kind filters** of fact 2.
- **Due rule.** `state = 'planned'` becomes `state = 'planned' AND (detach_after IS NULL OR detach_after <= now)`, in
  `dueMoveIds()` and `due()`. Primary rows in `planned` always have a NULL `detach_after`, so nothing changes for them.
- **`add`, links.** A `switched` row of an `add` run is due for its refresh. It must not use `inbound_changed_at` (6.4).
- **`refreshCounts()`.** "Done" today counts `switched`, `detaching` and `done`. For `remove` only `done` counts, and
  `detaching` counts as remaining. Likewise `InboundMoveOverview::planRun()`: "retired", "undoable" and `plan_changed`.
- **`InboundMoveControls::retireNow()`.** For a `remove` run it also makes `planned` rows due, so "do not wait" works.
- **`protocolMix()`.** Its primary is the target today. Split out the rule so that it takes a primary and a list of
  additional inbounds. `add` passes the service's primary, its live rows and C. `remove` needs no check: removing an
  inbound cannot break D1.
- **Run block and page texts** per kind (9.1). `titles()` in the runner, for the "A → B" line.

### 3.4 Where this overrides the move design 6.4

| 6.4 says | This design | Why |
|---|---|---|
| `add` creates `pending` rows and the attach timer attaches them | The run does its own attach step, and writes the row after the panel confirms | The timer handles active services only, needs `plans.attach_retry_enabled`, and cannot be paused, cancelled, counted or undone by the run. A `pending` row would also show to customers as "connecting" until the timer's next pass |
| `remove`: the row becomes `detached` | The row is **deleted** when the detach is confirmed | Fact 8: a `detached` row would keep the inbound from ever being deleted, and deleting it is usually why the owner removes it. The move row is the record |

## 4. Choosing the services

**Rule: the plan changes first, then the run follows it. A run never writes the plan.** Plan edits keep going through
`PlanAdminService::save()`, with its validator and notices. That is what keeps new sales and existing services from
drifting apart: new sales take the plan's set from the moment of the edit, and the run brings the old services to the
same set (Q2).

| Kind | Target must be | Run refused when |
|---|---|---|
| `add` | One of the plan's additional inbounds, active, on the panel of the plan's primary | Not in the plan: "add it to the plan first" |
| `remove` | Neither the plan's primary nor one of its additional inbounds | Still in the plan: "remove it from the plan first" |

Both are refused for a trial's backing plan (fact 14).

Candidates, all from the database, in order of id:

- `add`: services of the plan that have no `attached` row for C and whose primary is not C.
- `remove`: services of the plan with a row for D in `attached`, `pending` or `failed`.

Scope options are those of the move design 6.2: `--service`, `--include-suspended`, `--include-expired`, `--limit`,
`--after-id`. Defaults are the same: active only. `--from-inbound` keeps its meaning for `add` (services whose primary
is X). It is refused for `remove`, where it would mean nothing. `--include-trials` is refused for both (6.6).

Skip reasons, all under the service's row lock again at the claim:

| Reason | `add` | `remove` | Note |
|---|---|---|---|
| `not_modern`, `not_real_panel`, `no_panel`, `remote_id` | yes | yes | Legacy is report-only and other panels are skipped, as for a move. Nothing is sent |
| `suspended`, `expired`, `trial`, `deleted` | yes | yes | As 6.2 |
| `other_panel` | yes | yes | For `remove`: a row on another panel never reached the client. It stays, and the skip shows it |
| `in_other_run`, `moved_too_often` | yes | yes | As 6.2 |
| `protocol_mix` | yes | no | 3.3 |
| `already_on_target` | yes | no | Not a candidate at listing; can arise before the claim |
| `is_primary` (new) | yes | yes | The service's primary is the target, for example after a move |
| `not_attached` (new) | no | yes | The row went away after the run was created |

A fresh `pending` row means someone else's attach is in flight. That is not a skip: the move stays `planned`, is
reported as busy, and the next pass takes it.

## 5. The per-service steps

### 5.1 `add`

1. **Claim** (short transaction, lock order 3.1). Re-check the rules. A row for C that is `failed` or stale `pending`
   is claimed as the attacher claims it (`pending`, `updated_at` = claim), so the attacher sees it as busy. With no
   row, none is created. Move `attaching`, `claimed_at`, attempts + 1.
2. **Panel** (no transaction, no lock): `attachForMove($remoteId, $c, $panel, false)`.
3. **Write** (short transaction, same order, identity check of the move design 4.3 step 3). Insert the row, or update
   it, as `attached`, with `attached_at`, `checked_at` = now, attempts and alert state cleared. Move `switched`. The
   service row is not touched: no `inbound_id`, no remote id, no `inbound_changed_at`.
4. **Snapshot:** `PanelLinkSnapshot::refresh()`. Any outcome but `FAILED`: the move is `done`. On `FAILED` it stays
   `switched` and the next pass refreshes it.

A failure gives the move `failed` with back-off. A row claimed in step 1 goes back to `failed` as it was.

### 5.2 `remove`

The order matters because of fact 6. If the row stayed `attached` while the panel detaches D, the attach timer could
read the client in between, conclude "removed by hand" and **attach D again**. So the row leaves `attached` **before**
the panel call, under its lock.

1. **Claim**, once `detach_after` has passed:
   - lock, then re-check the rules, including that the service's primary is not D;
   - a fresh `pending` row: busy, try again next pass;
   - set the row `detached`, `last_error` = "being removed by run #N", `updated_at` = now. The attach timer and the
     attacher now ignore it (fact 7). A `reconcile()` that read the row before this finds it changed and writes
     nothing, since its update is conditional on the status it read;
   - move `detaching`, `claimed_at`, attempts + 1.
2. **Panel** (no transaction, no lock):
   `detachInbounds($remoteId, $service->getInbound(), [$d], $panel, false)`. D is gone on the panel: as the retire,
   done once `panelHasInbound()` confirms it, and NULL (unreadable) is a failure.
3. **Write** (short transaction, identity check): **delete the row**, move `done`, `detached_at` = now,
   `inbound_changed_at` = now and `last_access_info_synced_at` = NULL (as `finishRetire()`).
4. **Snapshot:** `PanelLinkSnapshot::refresh()`. The run stays open until the display guard's condition clears, as
   for a retire.

A failure leaves the move `failed` and the row `detached`, so nothing re-attaches D while the run still means to
remove it. The panel may or may not still list D. "Retry failed now", the back-off, Undo and "retire now" all start
with a read, so each settles it. Cancel does not cover such a move, as it does not cover a failed retire. A move that
was given up keeps its row held until one of those is used. See R5.

**Refused outcomes.** `REFUSED_PRIMARY` and `REFUSED_LAST_INBOUND` are `skipped`, with the row put back as `attached`.
`FAILED_NOT_ON_PRIMARY` (a hand edit) is `failed`, nothing sent.

### 5.3 Zero inbounds

Two independent guards:

- **Ours:** the primary is never a target. It is checked when the run is created, at the listing and at the claim
  (`is_primary`). A service keeps its primary, so it always keeps at least one inbound.
- **The driver's** (fact 5): `REFUSED_PRIMARY`, `REFUSED_LAST_INBOUND`, `FAILED_NOT_ON_PRIMARY`. 3x-ui has no guard of
  its own [3x-ui], so these are what protect the client. The local rows are never trusted for this: the driver acts
  on what the panel lists.

If `attachForMove()` reports "already on target" for an `add`, the move records `skip_reason = already_on_panel` on
its `done` row. Undo then leaves that inbound alone: this run did not put it there.

### 5.4 Idempotency, crashes, partial failures

The standard of the move design 5.4 applies: every step can be repeated, writes are conditional on what was read, a
claim older than `ServiceInboundAttacher::STALE_CLAIM` (5 minutes) is taken over, and a re-run of a done item is a
no-op.

| Killed during | Panel | Local | Next pass |
|---|---|---|---|
| `add`: attach | Maybe C | No row, `attaching` | Takes over; a read finds C or attaches it |
| `add`: write | C | Rolled back | A read finds C, then writes |
| `add`: snapshot | C | Row `attached`, move `switched` | Refreshes. C's links appear late, nothing is wrong meanwhile |
| `remove`: after the claim | D | Row `detached`, `detaching` | Takes over; detaches |
| `remove`: after the detach | No D | Row `detached`, `detaching` | Takes over; "already detached"; writes |
| `remove`: after the write | No D | Row gone, `done`, guard on | Refreshes; guard clears |

Between a confirmed detach and the write, D's stored link can still show. That lasts seconds, or after a crash until
the takeover by a later pass: the same window as a retire killed during its detach (the move design, 5.4).

**Partial failure** is per service. One failed service never stops the others, and a run ends `done` with failures
reported. Five failed panel reads in a row pause the run, with one alert.

An `add` that was cancelled or given up can leave C on the client with no row, when the attach applied but its read
failed. This heals itself: a later `add` lists the service, finds C on the panel and writes the row.

**Known limit, inherited from P6.** A Regenerate that read `subLinks` before the detach and stores after the runner's
refresh leaves D's link stored until the next refresh. The subscription URL is live and stays right.

## 6. Safety rules

### 6.1 Panels

Modern 3x-ui only. Legacy is report-only (`not_modern`), other panels are skipped, and neither gets a request. The
target must be on the service's own panel.

### 6.2 What must never change

UUID, email, subId, subscription URL, `/s/{token}`, expiry, limits, `enable`, traffic used. Both steps only change the
client's inbound membership, and neither sends the client record (`clients/update` is never called). The write's
identity check discards the step if a link reset changed the credentials meanwhile: the move goes back to `planned`
and is picked once more, as in the move design 4.3 step 3.

### 6.3 Multi-inbound plans and the attach timer

- **`add` and `remove` need `plans.multi_inbound_enabled` on** (Q5), when the run is created and at the start of each
  pass. With it off a pass does nothing and writes nothing: items stay due, as with the move switch off. Undo needs
  only `plans.inbound_move_enabled`, because it is an emergency action.
- **After an `add`** the row is an ordinary `attached` row. The timer owns it from then on, and a hand removal is
  re-attached, within `MAX_REATTACH_CYCLES`. That is what the plan promises.
- **A service's first row.** A single-inbound service has no rows, so the timer never reads it. After `add` it does:
  one GET per service every `CHECK_EVERY_HOURS` (6). See risk R3.
- **After a `remove`** there is no row, so the timer has nothing to re-attach, ever.
- **New sales:** nothing changes. A sale takes the plan's set. The run never touches a service created after it, even
  if that service has the same plan.
- **Unpaid orders (fact 10).** An order taken before a plan edit and paid afterwards uses its snapshot. It can create
  a service with D after the `remove` run, or without C after the `add` run. This is accepted: the drift notice counts
  it (fact 12), and another run fixes it.
- **Renewals:** nothing changes (fact 11). An expired service left out of a run keeps its old set when renewed. It
  stays in the drift count until a later run covers it. No automatic catch-up at renewal (Q8): a renewal is a payment
  path, and the move design 5.7 keeps moves off it.
- **The plan changes during a run.** The move design only shows a notice. Here a contradiction would create drift: an
  `add` run whose C left the plan, or a `remove` run whose D came back. So at the start of each pass the runner checks
  the plan again, and on a contradiction it **pauses** the run with a reason (Q7). An admin decides: resume or cancel.
- **Primary moves.** One open run per plan keeps them from overlapping. A primary run already handles rows: it deletes
  B's row and keeps a row for A when the plan still lists A.
- **keep-old inbounds.** A primary run with `--keep-old` leaves A on the client with no row, so `remove` does not list
  those services. `--retire-now` on that run handles them (Q9).

### 6.4 Display guard

| | `add` | `remove` |
|---|---|---|
| Stored links after the panel step | Still true, only incomplete | **Hold a dead link** |
| `inbound_changed_at` | **Not written** | **Written**, in the same transaction as the row delete, with `last_access_info_synced_at` = NULL |
| Refresh | Required, but late is harmless | Required. The guard hides the single links until it succeeds |
| The run stays open until | The move is `done` | The guard's condition clears |

Writing it for `add` would hide working links from every customer of the run for as long as a refresh takes, for no
gain. Undo reverses this: undoing an `add` is shaped like a `remove` (written), and undoing a `remove` like an `add`
(not written).

Links always come from the panel's `subLinks` through `PanelLinkSnapshot`, stored exactly as given. Nothing here
builds or merges a link, on view or anywhere else, and no panel call happens on view.

### 6.5 The grace period of `remove` (DECISION NEEDED Q1)

**Recommendation: reuse the mechanism (`grace_hours`, `detach_after`, the range 0–168), not the default. The default
for `remove` is 0, and `plans.inbound_move_detach_grace_hours` is not read for it.**

A move's grace period works because the replacement is already there. During it the customer's next subscription
update brings B while A still works, and undo is cheap until A is gone.

For `remove` neither holds:

- no replacement arrives during the wait, and nothing tells the customer (no notice before P10). The subscription
  lists D until the moment it is removed, so waiting 24 hours protects nobody;
- undo does not depend on it. A removal can be put back by attaching D again, which the retire of a move cannot do so
  simply. The risky part, losing the client, cannot happen here;
- a run waiting 24 hours blocks every other run of the plan (one open run).

What a wait does give is a window to cancel before any panel call, so the field stays on the form. The value is frozen
in the run. When P10 exists, a notice followed by a wait is useful, and P10 can raise the default.

### 6.6 Trials

A trial service's plan is its backing plan, and a backing plan refuses `add` and `remove`. So `--include-trials` is
refused with these kinds, and the skip reason `trial` stays only as a guard. Backing plans keep their rules: the
selectors do not show for them, and nothing about them changes.

## 7. Undo

Undo stays what it is today: a run-level button or `--undo=<runId>`, under the lock, in the foreground, retried by
pressing it again, with no back-off (P6). Items not started become `cancelled`.

| | Undo of `add` | Undo of `remove` |
|---|---|---|
| Applies to | `switched`, `done`, `failed` | `detaching`, `failed`, `done` |
| Skips | `skip_reason = already_on_panel` (the run did not attach it) | Nothing, but a service whose latest move is not this one is left alone |
| Steps | As 5.2: row to `detached` if there is one, detach C, delete the row, guard, refresh | Read; attach D if missing (`attachForMove()`); row back as `attached` (`checked_at` NULL), refresh |
| A `done` item | **Can be undone.** It is a plain detach | **Can be undone.** It is a plain attach. Unlike a retired move, no new run is needed |
| Cannot be | The primary changed to C meanwhile: `is_primary` | D was deleted locally or is inactive: `failed` / `target_inactive`, as P6 |
| The plan afterwards | **Not changed.** It still sells C. The page says so, and the drift count shows it | **Not changed.** It still does not sell D. The service holds an extra inbound, and the drift count shows it |

Undo is refused for a run that is not the plan's latest of any kind: a later run may have built on it.

## 8. Controls

**No new setting and no new switch.** The existing, default-off switches are enough (Q3):

| Switch | Default | Needed by |
|---|---|---|
| `plans.inbound_move_enabled` | off | Every kind: run creation, the runner, the task, undo |
| `plans.multi_inbound_enabled` | off | `add` and `remove`, except undo |

Why not a separate `plans.inbound_add_remove_enabled`: it would guard the same runner, lock and panel calls, and an
admin stopping things in a hurry should have one switch. `add` and `remove` start only from an explicit action with a
dry run, so turning on the existing switch for moves starts nothing by itself.

`plans.inbound_move_detach_grace_hours` stays a move setting (6.5). Batch size, pause and the `plans.attach_*` retries
are reused.

## 9. Interfaces

### 9.1 Web admin

The Apply page stays `GET /admin/plan/{id}/apply-inbounds`, with `POST .../create`. There are no new routes.

- **Kind selector** at the top: "Move to the plan's inbound" (default), "Add an inbound", "Remove an inbound". `add`
  and `remove` show only with both switches on and for a plan that is not a backing plan. Selecting one is a GET
  (`?kind=add`), and no GET writes.
- **Target selector.**
  - `add`: the plan's active additional inbounds that some service lacks.
  - `remove`: inbounds that services of the plan hold and the plan no longer has.
  - An empty list says why, with a link to edit the plan.
- **Options:** the existing scope options. `keep_old` is hidden. `grace_hours` shows for `remove` only, prefilled 0.
- **Dry run:** as today, local only, no panel request. Counts: would add / would remove, skipped by reason, and a list
  (id, title, current inbounds → after). The note "checked when the run is created" stays for protocol rules.
- **Confirmation:** names the inbound and says what the customer sees (section 10).
- **Run block:** "Add inbound C" or "Remove inbound D" as the title, done / failed / skipped / remaining, buttons as
  today, and "not refreshed yet" for `switched` rows of an `add`.
- **Service page:** the line reads "Inbound added" or "Inbound removed" instead of "A → B".

`InboundMoveActions` stays the single implementation. `form()` gains `kind` and `inbound`, and `create()` validates
them again. Permissions are unchanged (PLANS_MANAGE; "Retry now" SERVICES_MANAGE). CSRF is as P7.

### 9.2 Mini App admin

The same screens through the same `InboundMoveActions`, on the existing routes: the kind and inbound selectors on the
Apply screen, and the run block's new labels. Same rate limit, Idempotency-Key, Telegram confirmation for cancel and
undo, and Persian digits. Texts in the `admin` domain, new labels in `miniapp`, fa and en.

### 9.3 Command line

```
php bin/console app:plan:apply-inbounds <plan> --kind=add --inbound=<id>                     dry run
php bin/console app:plan:apply-inbounds <plan> --kind=add --inbound=<id> --service=<id> --apply
php bin/console app:plan:apply-inbounds <plan> --kind=remove --inbound=<id> --grace-hours=0 --apply
```

- `--kind=primary|add|remove`, default `primary`. Existing calls behave exactly as they do today.
- `--inbound=<id>`: required with `add` and `remove`, refused with `primary`.
- **Dry run unless `--apply`**. It writes nothing and works with the switches off.

| Option | `add` | `remove` |
|---|---|---|
| `--apply`, `--service`, `--include-suspended`, `--include-expired`, `--limit`, `--after-id`, `--batch-size`, `--pause`, `--json` | yes | yes |
| `--resume`, `--pause-run`, `--cancel`, `--undo` | yes (the run knows its kind) | yes |
| `--from-inbound` | yes | refused |
| `--grace-hours` | refused | yes |
| `--retire-now` | refused | yes: makes the removal due now |
| `--keep-old`, `--include-trials` | refused | refused |

Exit codes stay 0 / 1 / 2.

The dry run reads the panel with one budgeted GET per service, as today, and shows per service what would change:

```
Service  Status  Now on panel     After         Action   Note
#101     active  DE-1, NL-2       DE-1          remove   D is an additional inbound
#102     active  DE-1             DE-1          remove   not on the panel: only the row goes
#103     active  DE-1             —             skip     not modern: legacy panel
```

`--json` carries `action` (`add`, `remove`, `skip`), `inbounds_now`, `inbounds_after` and `note`. Ids and titles only.

### 9.4 Audit and logging

- **Audit:** the existing actions, `plan.inbounds_apply_started`, `_paused`, `_finished`, `_cancelled`, `_undone`,
  with `kind` and `target_inbound_id` in every entry. Counts only. No audit row per service: the move row is the
  record. The Mini App actor rule of P9 applies.
- **Logs:** new keys `inbound_move_added` and `inbound_move_removed`, with run, service and inbound ids only.
- **Errors:** everything shown, logged, stored or alerted goes through `SafeText` (`SecretRedactor`, the client email
  masked, the panel's token and password removed). `last_error` through `VpnServiceInbound::safeError()`, also the
  fixed text on a held row.
- **Never in any page, message, audit entry or log:** a link, UUID, subId, token, password, client email or owner.
  `ModernPanelHttpClient`'s existing request log is left as P4 described it.
- **Tests** repeat the redaction checks with `zend.exception_ignore_args` 0 and 1.
- **`app:panel:doctor`** and the panel page: failed moves count as today, all kinds together.
- **`app:service:verify-links`** needs no change. It expects attached rows on the client, and a removed row is gone.

## 10. What the customer sees

The subscription URL does not change, on any surface, in any case.

| | `add` | `remove` |
|---|---|---|
| Subscription in the app | The new server appears at the next update | The server disappears at the next update |
| Until then | Everything works as before | **The app still lists D, and D no longer connects.** The other inbounds work |
| Single links on the bot, `/s/{token}`, Mini App | C's link appears after the refresh | Hidden with "Your links are being updated" until the refresh, then without D |
| A single config the customer copied | Keeps working | D's stops working |
| "Servers: N" | N + 1 once the row is written | N − 1 from the claim |
| Service detail host, country, protocol | Unchanged: they read the primary | Unchanged |
| Usage, quota, expiry | Unchanged (**U1**, **U2**) | Unchanged |
| Resellers | Nothing changes, no message | Nothing changes, no message |

Customers get no message in P11. That is P10.

## 11. Tests and real-panel checks

### 11.1 Automated tests

Each on the fake 3x-ui with `detach` at v3.11.0 semantics, on MariaDB 10.11, with `COLUMNS=60`, one suite at a time.

- **`add`:** T4-style end to end; row written; UUID, subId, email, expiry, limits, usage unchanged; `inbound_changed_at`
  stays NULL; links stay visible during the run.
- **`remove`:** the row is deleted, D's link hidden until the refresh, and the inbound can then be deleted.
- **Guards:** primary, last inbound, not on primary, legacy, other panel, dummy: skipped or refused, with zero requests
  where the table says so.
- **Timer:** the reconcile never re-attaches during or after a `remove`; a run after an `add` keeps the row.
- **Real two-process tests** (`ParallelStart`, `tests/Support/bin/inbound-move.php`):
  - `remove` against the attach timer stopped between its read and its write;
  - `remove` against "Retry attach" on the same row;
  - `add` against "Retry attach";
  - `kill -9` at each row of the table in 5.4;
  - link reset and Regenerate during a step;
  - two runners on one run.
- **Plan rule:** refusal for a target in or out of the plan; a pause on contradiction.
- **Undo:** table of section 7, and `already_on_panel` left alone.
- **No effect on sales:** settlement unchanged with a run open, and the `real-time` lock-hold test.

### 11.2 What the existing tests must keep showing

`kind=primary` behaves byte for byte as before: the whole of `tests/Shop/InboundMove`, unchanged, passes after each PR.

### 11.3 Real-panel checklist and unverified assumptions

Do this after the move design's own checklist (8.3), which has never been run (Q10). Two test inbounds C and D on one
modern panel, a multi-inbound plan, 2 test services. For each kind, record UUID, subId, usage and expiry from the panel
before and after:

1. Dry run: no write in the panel's log.
2. One service with `--apply`. The panel shows the change. The phone's "update subscription" shows it. All other
   inbounds still connect.
3. Stop the panel during a run: it pauses after 5 failures, nothing changes for customers. Start it and resume.
4. Run undo on a `done` item. The service is back, with the same UUID and usage.
5. `app:service:verify-links`: PASS.
6. After a `remove` of every service: delete inbound D locally. It must be allowed (see R2 for the exception).
7. Wait for the next `attach-inbounds` run: D is not re-attached, and C stays.

[unverified]:

- **U1, U2, U3, U5** of the move design, now for an additional inbound;
- **U11:** after an attach, `clients/subLinks/{subId}` lists C alongside the others;
- **U12:** after a detach of an additional inbound, the primary keeps serving with no client restart or reconnect;
- **U13:** `attach` and `detach` of one inbound do not disturb live connections on the client's other inbounds.

## 12. Risks and open questions

### 12.1 Risks

| # | Risk | Recommendation |
|---|---|---|
| R1 | `remove` is customer-visible with no warning: D stops working for a customer until they switch server in the app | State it on the confirmation page. Test one service first. Consider waiting for P10 before a large `remove` |
| R2 | Rows of **deleted** services stay `detached` (fact 9) and are never in scope, so inbound D can still be refused for deletion after a full `remove` | A separate small fix, outside P11: delete those rows in `ServiceAdministration::delete()` and clean up the old ones |
| R3 | A plan's first `add` makes every one of its services readable by the attach timer: one GET per service per 6 hours | Bounded by the timer's existing batches and limits. Note it for a panel with thousands of services |
| R4 | `attachForMove()` and `detachInbounds()` were written for moves and tested on the fake only | P11a's tests pin them for this use. Real-panel checklist before any production run |
| R5 | A held `detached` row of a `failed` move shows "Servers: N − 1" while the client may still be on D | Accepted: the admin sees the failure, and a retry or Undo settles it |
| R6 | Undo of `add` leaves the plan selling C | The page says so. It is the safe direction in an emergency: customers first, plan edit next |

### 12.2 Open questions for the owner

Marked **[choice]** where your decision changes the build. The others have a recommendation that will be built unless
you say otherwise.

1. **Q1 [choice].** `remove` default grace: 0, with the field on the form (6.5)? *Recommended: 0.* The alternative is
   the 24-hour setting, which delays the change without helping any customer until P10.
2. **Q2 [choice].** The plan changes first, and a run never writes the plan (section 4)? *Recommended: yes.*
3. **Q3.** Reuse `plans.inbound_move_enabled`, no new switch (section 8)? *Recommended: yes.*
4. **Q4 [choice].** `remove` of a primary inbound is refused, and the admin uses a move instead? *Recommended: yes.*
5. **Q5 [choice].** Both kinds need `plans.multi_inbound_enabled`? *Recommended: yes.* Otherwise `remove` could tidy up
   after the switch was turned off, but with no plan form to match it to.
6. **Q6 [choice].** Depart from 6.4 of the move design (3.4): the run attaches itself, and the removed row is deleted
   rather than kept `detached`? *Recommended: yes.* Fact 8 makes keeping it a dead end.
7. **Q7.** A pass pauses when the plan contradicts the run (6.3)? *Recommended: yes.*
8. **Q8.** No automatic catch-up at renewal (6.3)? *Recommended: none; a later run covers those services.*
9. **Q9.** keep-old inbounds stay out of `remove`, and `--retire-now` covers them (6.3)? *Recommended: yes.*
10. **Q10 [choice].** Run the move design's real-panel checklist (8.3) before P11 reaches production? The move itself
    has only been tested against the fake 3x-ui. *Recommended: yes, it comes first.*
11. **Q11.** R2 as a separate small PR, before or with P11c? *Recommended: yes, with P11c.*

## 13. Proposed PRs

Each is small and revertible, with phpunit and phpstan green, an independent review, and the pushed head tested from a
clean checkout. Tests run on MariaDB 10.11 with `COLUMNS=60`, one suite at a time. None needs a migration or a new
timer, so each is an app-only deploy. Nothing changes for anyone until an admin starts a run.

| # | Scope | Tests | Rollout and real-panel test |
|---|---|---|---|
| **P11a** | Preparation with no behaviour change: the kind-aware changes of 3.3, except the filters that open the new kinds; `protocolMix()` split; the move design 6.4 and 9 point here; Persian summary of this document | The whole of `tests/Shop/InboundMove` unchanged; a pin that a run row of another kind stays inert | Deploy; run a one-service move if the 8.3 checklist is under way. No visible change |
| **P11b** | `kind=add`, command line and task: planner, runner step, `--kind`, `--inbound`, dry run, undo, plan rule, pause on contradiction, audit, admin guide en/fa | 11.1 for `add`, including the parallel tests with "Retry attach" and `kill -9` | Checklist 11.3 for `add` on one test service, then one test plan |
| **P11c** | `kind=remove`, command line and task: claim before detach, row delete, guard, grace, `--retire-now`, undo; R2 if Q11 is yes | 11.1 for `remove`, including the timer race, `kill -9` at each row of 5.4 and the inbound delete | Checklist 11.3 for `remove`, with step 7 after a real timer run |
| **P11d** | Web admin: kind and target selectors, dry run, confirmation, run block, service page; admin guide en/fa | As `InboundMoveWebTest`: CSRF, permissions, switch off, no panel request on GET, escaping, no link / UUID / token / owner in the HTML, parity with the command, fa/en keys | One service from the page, then wait for the task. Check the plan page after the run |
| **P11e** | Mini App admin: the same through `InboundMoveActions`; `miniapp` translations | As `MiniAppAdminInboundMoveTest`: permission matrix, double tap, Persian digits, redaction | One service from the real Telegram app |

Order: P11a → P11b → P11c → P11d → P11e. P11b and P11c can each ship and be used from the command line alone. P10 is
independent and can come at any point.
