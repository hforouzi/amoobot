# Importing panel clients as services (app:panel:import-clients)

When the bot's database is lost, the 3x-ui panels still hold every customer's client, and most clients carry the
customer's Telegram id (`tgId`). `app:panel:import-clients` turns those clients back into services, owned by the right
Telegram account, so a returning customer sees them in the bot ("My services") and the Mini App, and a broadcast to
everyone reaches them.

```
php bin/console app:panel:sync-inbounds <panelId>          # first: the panel's inbounds must be known
php bin/console app:panel:import-clients --panel=<panelId> --dry-run
php bin/console app:panel:import-clients --panel=<panelId> [--limit=N] [--batch-size=100]
```

## What it does

- **Modern mode only.** The panel must be 3x-ui in modern mode (API token, clients API). A legacy panel is refused
  before any request: its API cannot list clients with their `tgId`. If no client row the panel returns has a `tgId` at
  all, the run says so and exits non-zero (nothing can be imported).
- **Read-only towards the panel.** GET requests only: `clients/list` once (traffic), `clients/list/paged` page by page,
  then for each new client its full row (`clients/get/{email}`) and its links (`clients/subLinks/{subId}`, or
  `clients/links/{email}` without a subId). Nothing is written to the panel: no tgId, comment, enable, expiry or
  traffic change.
- **Per client**, in this order:
  - already known: a service has its UUID (any panel), its email on the same panel, or its subId → skipped untouched;
  - no owner: `tgId` missing, 0, negative (a channel) or not a number → skipped;
  - invalid: no email, no UUID, no inbound, or unreadable expiry/traffic → skipped;
  - unknown inbound: one of its inbounds has no local VpnInbound → skipped (run `app:panel:sync-inbounds`, then the
    import again);
  - otherwise a service is created for the TelegramAccount with that id, or for a new User + TelegramAccount created
    exactly as a first message to the bot creates one (`TelegramUserResolver::createAccount()`; `lastActivityAt` stays
    null until the customer writes; that first message then finds this account).
- **The service** is what provisioning would have stored: panel, primary inbound (the first one the panel lists), one
  `vpn_service_inbound` row per additional inbound (attached), remote id, client UUID, email, subId, IP limit, expiry
  (0 = unlimited; a not-started "start on first use" duration = no date), traffic limit and usage in bytes (GB as the
  usage sync stores them), the subscription URL, the panel's own links (the snapshot the modern driver keeps, never
  links built from the primary alone), a new public page token, `importedAt` = now. `order` and `soldByReseller` are
  null.
- **Status** by the bot's own rules: past its expiry = expired (as the expiry check), out of traffic = suspended (as
  auto-suspend), disabled on the panel = suspended, otherwise active.
- **Batches.** Each page (`--batch-size`, at most 200) is read completely first, then written in one transaction. A
  failed panel read stops the run before anything of that page is written; earlier pages stay. A rerun continues: what
  was imported is "already known" (a second run imports 0). `--limit=N` stops after N services; run again to continue.
- **Output**: counts only (clients read, imported, new users created, existing users matched, already known, no owner,
  unknown inbound, invalid, and the most services one owner got in this run). Above 20 for one owner the run warns
  to check that the panel's tgId values are the customers' and not an admin's (a count only, never whose). Never a Telegram id, UUID, email, subId or link, also in errors and logs: the full-row and
  links reads log their path as `clients/get/{email}` / `clients/links/{email}`, and an unexpected error is reported by
  its class only.
- **Nothing is sold**: no order, payment, wallet entry, invoice or discount usage is created, so imported services are
  absent from revenue, the dashboard's payments and every reseller figure (those read orders and payments; resellers
  also `soldByReseller`, which is null).
- **No notification burst.** The import sends nothing. For each imported service, the notices its state would trigger
  now (expired, an expiry warning already due, a traffic threshold already passed) are recorded as sent
  (`ServiceNotificationService::markCurrentStateNotified()`, payload `{"source": "panel_import", "sent": false}`), so
  the next `send-notifications` run does not message every imported customer at once. Thresholds reached later notify as
  usual.

The marker is one nullable column, `vpn_service.imported_at` (migration `Version20261108090000`), so a deploy of this
change needs the full deploy (migrations), not `--app-only`.

## What is blocked for imported services, and why

An imported service has no order, so no plan: there is no price to renew it at, and no plan to sell extra traffic
under. Every flow that needs one already refused order-less services (`ServiceOrderCheckout::renewalQuote()` and
`addTrafficPlan()` return null); the answer now points at buying a new plan instead of "contact support":

| Flow | Answer |
| --- | --- |
| Bot: Renew (summary and confirm) | "این سرویس پلن ندارد. برای تمدید، یک پلن جدید بخرید." with the buy button and back |
| Bot: Add traffic (start and confirm) | "این سرویس پلن ندارد. برای حجم بیشتر، یک پلن جدید بخرید." with the buy button and back |
| Mini App: service page | no renew / add traffic buttons; a card with the same text and the shop link |
| Mini App: renew / add traffic pages | the same text and the shop link |
| Mini App API: renew, add traffic (and their quotes) | 409 `renewal_new_plan` / `add_traffic_new_plan` |

The same applies to any service whose order was deleted (also order-less). Reseller renewals and add-ons need
`soldByReseller`, which an imported service does not have. Everything else works unchanged with no order: My services,
service detail, links / QR, the subscription page (plan name falls back to "VPN <id>"), usage sync, check-expiry,
notifications, auto-suspend, claim links, inbound attach, admin pages (plan shown as "none"), admin extend / add traffic
/ suspend (they act on the panel directly), broadcasts (all, active service, expiring service).

## Follow-ups (not built)

- Map imported services to a plan (by traffic and duration, or by hand in the admin) so they can be renewed and topped
  up in place instead of buying a new plan.
- Show "imported" in the admin service list and detail (the column exists; nothing displays it yet) and offer a filter.
- Auto-suspend (when enabled) treats imported expired services like sold ones: it disables the client on the panel and
  marks the service suspended on its next run. 3x-ui usually has those clients disabled already; if that is not wanted,
  run the import with auto-suspend off and decide afterwards.
- "New customers today" on the admin dashboard counts the users the import created on the day it runs.
- A customer who writes to the bot for the first time while their page is being imported can make that page's
  transaction fail on the unique Telegram id; the run stops with an error and a rerun completes it.
