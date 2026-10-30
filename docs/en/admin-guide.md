# Admin Guide

The admin panel is built with EasyAdmin and supports Persian and English. Persian renders RTL; English renders LTR.

## Main Areas

The menu has one entry per page; related lists are tabs or sections of that page.

- Sales: orders (order drafts inside), payments (status tabs: all, pending, needs review, provisioning failed, confirmed, rejected), discount codes (usages inside)
- Catalog: plans (trial plans, trial claims and bulk price adjustment inside), apps (tabs: downloads, client apps)
- Customers: customers (Telegram username and id, language, services and orders; Telegram account pages linked from each customer), VPN services
- Messaging: broadcasts (deliveries inside), bot texts (tabs: message templates, button labels)
- System (super admin): payment gateways (store payment methods: order, enable/disable, below the gateways), panels (each panel lists its inbounds), settings (tabs: sales & renewal pricing, automation, required channels, advanced), plugins, admin users, logs (tabs: audit, notifications, bot messages)

Old addresses of pages that moved (for example `/admin/payment-needs-review` or `/admin/settings/automation`) redirect to their new place.

## Core Concepts

- Plan: a sellable VPN package, including price, duration, and volume.
- Order: a purchase, renewal, or add-traffic intent.
- Payment: a transaction attached to an order.
- VPN Service: the provisioned service after payment approval.
- PaymentGateway: gateway credentials and configuration.
- StorePaymentMethod: a bot-visible payment option linked to one gateway.
- Plugin: an installed extension package. Current runtime support is focused on `payment_gateway`.

## Common Workflows

1. Create a plan with price, duration, volume, and active status.
2. Configure a VPN panel and sync or create inbounds.
3. Configure a payment gateway.
4. Create and activate a StorePaymentMethod for that gateway.
5. For manual payments, review submitted receipts and confirm or reject them.
6. Track orders by status and tracking code.
7. Check bot message logs, payment diagnostics, and panel test commands when a flow fails.

## Customer subscription page and apps

Every service has a public page, `https://DOMAIN/s/<token>`, that customers open from the bot ("Open subscription page"
under a service and under the purchase message) or from a Telegram `web_app` button. It shows the service's status, expiry, traffic, the
subscription link (copy button and QR code), one-tap "Add subscription" buttons for client apps and the app downloads,
in Persian (RTL) or English (`?lang=en`). The token is random (not the panel's subId) and the page needs no login, so:

- The link is on the service's detail page (VPN services -> a service -> "Customer subscription page").
- "New subscription page link" on that page replaces the token: the old link stops working at once (for example when a
  customer shared it). The subscription URL itself does not change. Needs "manage services"; recorded in the audit log.
- Wrong, old or deleted-service links all show the same "not found" page. Each IP may open 30 pages a minute.

The menu entry Catalog -> Apps (permission "manage apps": super admin and admin) has two tabs:

**Client apps** (the "Add subscription" buttons). To add one: Apps -> Client apps -> Add, then set the name, the
platforms and the link template, using these placeholders: `{url}` (the subscription URL as is), `{url_encoded}` (encoded
for a query parameter), `{url_base64}` and `{name}` (the profile name, encoded). Example: `v2rayng://install-sub?url={url_encoded}#{name}`.
Leave the template empty for an app without a URL scheme: customers then get "Install" plus "Copy link". The
detail page previews the link. Test a new scheme on a real phone before you activate it. The seeded apps whose scheme
could not be verified from the vendor are inactive, and their notes say why.

**Downloads**. To add one: Apps -> Downloads -> Add, then set the title, platform and version, and choose either "Uploaded file"
(apk, zip, exe, msi, dmg, pkg, AppImage, deb, up to `APP_DOWNLOADS_MAX_MB`, 200 MB by default) or "External link" (http/https). Optionally
link the download to a client app so that app's "Install app" button leads to it. The file is stored outside the
web root under a random name; its SHA-256 is shown on the detail page and on the customer page. Customers download through
`/d/<id>`, which counts downloads and supports resuming. The bot shows a "Download apps" button whenever at least one download is active.

## Plans with several inbounds (preview)

A plan can name **additional inbounds** of the same panel: a buyer's one client (one email, one UUID, one subscription)
is put on the primary inbound and on all of them. Design: [multi-inbound-plans.md](../design/multi-inbound-plans.md).
Phase M1 stores them; phase **M2a** provisions them (modern 3x-ui panels only); phase **M2b** retries the ones that
failed on a schedule, alerts the admins and shows the state (below). The customer gets the subscription link, which
carries every attached inbound.

- **Switch:** `Settings → Plans → Allow additional inbounds on plans` (`plans.multi_inbound_enabled`, SETTINGS_MANAGE),
  off by default. With it off the plan form, the plan list, the inbound page and the service page look exactly as
  before, a request that sends additional inbounds anyway is refused, and **a plan with additional inbounds is not
  sold**. Turning it off while plans keep additional inbounds names those plans. **Run the checklist below on your
  panel before turning it on.**
- **When such a plan is sold:** all of: the switch on; the plan active; the primary and every additional inbound
  active and on the same panel; that panel usable and a 3x-ui panel in modern mode (forced, or auto with modern
  detected: Panels → test connection). Otherwise it is hidden from the bot, the Mini App and resellers, and an order
  already in a cart is refused at confirmation, payment start and wallet payment. The plan list shows "not sold" and the
  save warning says why. An inactive additional inbound stops the plan selling (D3).
- **Plan form** (web panel, PLANS_MANAGE): "Additional inbounds" lists the active inbounds of the primary inbound's panel,
  without the primary. A save is refused when an inbound is on another panel, is the primary, is newly chosen but
  inactive, the panel is not in modern mode, the plan has custom traffic or duration (those stay single-inbound), there
  are more than 20, or the protocols break the rules below. The Mini App and the bot have no field yet (M3); a change
  they make (e.g. moving the primary to another panel) is checked the same way.
- **Protocol rules (D1):** VLESS, VMess and Trojan together; Shadowsocks only when every Shadowsocks inbound of the plan
  uses the same method; a VLESS primary with the vision flow only with flow-capable VLESS inbounds (TCP with
  TLS/REALITY). The method and flow capability are read from the panel when these rules apply: a panel that does not
  answer refuses the save.
- **What a purchase does:** one client is added on the primary and every additional inbound in one request; inbounds
  the panel did not attach are attached once more. **The service is delivered as soon as the primary works** (D2): an
  additional inbound that still fails is recorded as "not attached" with the reason, and never fails the payment, the
  order or the wallet. A plan with additional inbounds on a panel in **legacy** mode is never provisioned on the primary
  alone: the order becomes "provisioning failed" with "Several inbounds need the panel in modern mode (Panels → test
  connection)" and you get the usual admin alert (fix the panel mode, then retry the order as for any provisioning
  failure). An inbound made inactive, or the switch turned off, after the order was taken: the customer gets the
  primary and those inbounds are recorded as not attached.
- **Service page** (switch on): "Additional inbounds" lists each one with its state (attached, attaching…, not
  attached, detached), the number of attempts and the last error. **"Retry attach"** (SERVICES_MANAGE) appears while one
  is not attached: it attaches the missing inbounds to the existing client (it never creates a second client), refreshes
  the stored links, and is limited to 5 per service per 10 minutes. A second click while one runs says "in progress".
  It is refused while the switch is off or the panel is in legacy mode. Deleting the service removes the client from
  every inbound and marks the rows detached.
- **Other operations** (renew, add traffic, suspend, resume, reset usage, usage sync, claim links, "reset my links")
  work on the one client and so on every inbound, as for any service. Resellers pay the plan's price.
- **`app:service:verify-links <id>`** also checks that the client is on every inbound recorded as attached; the not
  attached ones are listed for information.
- **Plan list:** an "Inbounds" column (count, "not sold", inactive ones). **Panel inbounds page:** "Used by plans",
  primary or additional.
- **Deleting** an inbound that a plan uses as an additional inbound, or a panel with such an inbound, is refused and the
  plans are named. Deleting a plan's primary inbound works as before (the plan then has no inbound).
- Trial plans keep one inbound.

### Scheduled retry and alerts

- **What the job does** (`app:automation:run --only=attach-inbounds`, a systemd timer every 30 minutes at :25/:55, in
  the `lifecycle` lock group; by hand: `php bin/console app:services:attach-inbounds [--dry-run]`). Only for **active**
  services (suspended, expired and deleted ones are never touched):
  - it retries each "not attached" inbound with back-off: the first retry at the next run after the sale, then after a
    delay that doubles after each failure (5 minutes × 2ⁿ by default, at most 6 hours), until the attempts are used up (12 in all, the one at the sale
    included). It never creates a second client;
  - every 6 hours it compares the attached inbounds with the panel (one read per service): an inbound the client was
    **removed from by hand in 3x-ui** becomes "not attached", the admins are alerted and it is attached again; an inbound
    the panel already has (e.g. attached by hand) becomes "attached" without an attempt;
  - a panel that cannot be read for 5 services in a row is left alone for that run (the others go on); a run never
    marks a panel "degraded" and uses up no attempt while the panel is down. Two runs at once are safe.
- **Settings** (`Settings → Plans → Retrying inbounds that were not attached`, SETTINGS_MANAGE):
  `plans.attach_retry_enabled` (on), `plans.attach_max_attempts` (12, 1–100), `plans.attach_retry_delay_minutes` (5,
  1–1440). Raising the attempts lets a given-up inbound be retried again.
- **Alerts** (Telegram, to the admins who may retry: `TELEGRAM_ADMIN_CHAT_ID` and linked admins with SERVICES_MANAGE):
  one message per service when an inbound fails for the first time, one when its attempts are used up, and one when it
  is attached again. They name the service, user, plan and inbound by id and title and quote the error, never a link,
  UUID, client email, token or panel address.
- **Where to see failures:** the services list has a badge "N inbounds not attached" and a tab **"Inbounds not
  attached"**; the service page lists each inbound with its state, attempts, last error and when the next automatic try
  is (or that the attempts are used up); the panel page and `app:panel:doctor <panel id>` count the not attached ones.
- **How to retry by hand:** "Retry attach" on the service page (SERVICES_MANAGE), at any time; it ignores the back-off
  and the attempt limit.
- **Customers** (bot service detail, Mini App service page): "Servers: N" and, while some are not attached, "1 server is
  still being connected and will be added automatically", never which one or where. Services without additional inbounds
  look as before.
- **How to disable:** untick `plans.attach_retry_enabled` (no retry, no check; the alerts still go out), or turn the
  switch `plans.multi_inbound_enabled` off (the job then reads and changes nothing, and nothing is shown).

### Before turning the switch on (real-panel checklist, 3x-ui v3.11.0)

Run this once on your own panel with test inbounds, not customers' inbounds. Turn the switch on, but keep **every
multi-inbound plan inactive except one test plan** that only you buy (from your own Telegram account, paying from your
wallet or with a card payment you approve yourself). Steps marked **required** must pass before you sell; if one fails,
turn the switch off and report what you saw. The optional ones are needed only if you will sell that combination.

1. **Required: panel mode.** Panels → your panel → "Test connection" reports **modern**. Prefer setting the mode to
   "modern" rather than "auto", so a panel change cannot silently switch it (with legacy every sale of such a plan
   fails closed).
2. **Required: one client, three inbounds.** In 3x-ui create three test inbounds: VLESS-REALITY (TCP), VMess-WS,
   Trojan (TLS). In the admin panel: Panels → "Sync inbounds". Create the test plan: primary VLESS-REALITY, additional
   VMess-WS and Trojan; save (warnings about several protocols are expected). Activate it and buy it. In 3x-ui →
   Clients: exactly **one** client with the service's email, on the three inbounds, one UUID, one subscription ID.
   Admin service page: service active; "Additional inbounds" both "attached", 1 attempt, no error.
3. **Required: links.** Import the subscription link from the delivery message in a client app (v2rayNG, Hiddify or
   Streisand): **three** configs. On the server run `php bin/console app:service:verify-links <service id>`: no DIFF
   (INFO lines are fine). Admin service page → "Regenerate config": still three links stored.
4. **Required: connect.** Connect through each of the three configs; each works, and in 3x-ui the one client's usage
   grows whichever config you use.
5. **Required: limits on the one client.** Give the test service little traffic and use it up through the VMess config:
   all three configs stop. Add traffic or renew from the bot: all three work again. Suspend from the admin service page:
   all three stop; resume: all three work. Reset usage: 3x-ui shows 0 for the client.
6. **Required: failure path (D2).** In 3x-ui only (do not sync), delete the Trojan inbound, then buy the test plan
   once more. Expected: the service is delivered (VLESS and VMess work), the payment is approved, the Trojan row is "not
   attached" with an error, and 3x-ui has one client for it. Click "Retry attach": the row stays "not attached",
   attempts 2, and there is still one client (it never creates a second one). For a successful retry, use a service
   whose inbound you only **disabled** in 3x-ui (if 3x-ui v3.11.0 refuses to attach to a disabled inbound) and enable it
   again before clicking: the row becomes "attached".
7. **Required: delete.** Delete the test services from the admin panel: in 3x-ui the client is gone from every
   inbound, and the rows show "detached". Then remove the deleted Trojan inbound from the test plan before syncing
   inbounds.
8. **Required: reset links and claim.** On a new test service use "Reset links" in the bot, then a claim link to move
   the service to a second Telegram account: the client keeps its three inbounds, and the subscription imports three
   configs with the new UUID.
9. Optional: **VLESS vision + VLESS-WS** on one client (primary VLESS-REALITY with `xtls-rprx-vision`, additional
   VLESS-WS). Both connect, the flow applied only where valid; or note that it fails and do not sell that combination.
10. Optional: **two Shadowsocks inbounds** with the same method; record what works (two different 2022 methods are
    refused by the plan form).
11. Optional: remove the client from one inbound by hand in 3x-ui. `verify-links` reports a DIFF for that inbound. Run
    `php bin/console app:services:attach-inbounds` (the comparison runs every 6 hours; set the row's `checked_at` to
    NULL to force it): the row is reported "found removed", you get an alert, and the client is attached again (M2b).

When 1 to 8 pass, activate the plans you want to sell.

**Upgrading.** Run the migrations: `Version20261101090000` (M1) adds the `plan_additional_inbound` and
`vpn_service_inbound` tables; M2a needs no new migration; `Version20261102090000` (M2b) adds the retry columns
(`next_attempt_at`, `checked_at`, `alert_state`, `alerted_at`) and an index to `vpn_service_inbound`. Nothing existing is
changed and nothing is backfilled. M1's `down()` refuses and changes nothing while either table has a row (remove the
additional inbounds from every plan first) or while M2b's migration is applied (roll that back first). The M2b timer
(`amoobot-job-attach-inbounds`) is installed by a **full** `deploy.sh` run, not `--app-only`; until then run the command
by hand.

## Branding (customer pages)

`Settings -> Branding` (needs the *Manage settings* permission) sets what customers see on the payment result pages
(`/payment/return/...` and the cancel page). Every change is in the audit log (`settings.update`, page `branding`).

- **Brand name**: shown in the page header when there is no logo, and in the browser tab. At most 60 characters,
  saved exactly as typed: no space at either end and no invisible or control characters (the Persian zero-width
  non-joiner is fine). Leave it empty for none.
- **Accent colour**: the top bar and the main button, as `#rrggbb`. Empty means the default green `#166534`. On a
  light colour the button text turns dark so it stays readable.
- **Logo**: PNG, JPEG or WebP (SVG is not accepted), at most 512 KB, 16 to 1024 pixels per side. A **square PNG of
  about 256×256 with a transparent background** works best: it is shown 56 px high, on light and dark backgrounds.
  The file is checked and saved again without its metadata (EXIF, comments). Choosing a new file replaces the old one
  (the old file is deleted); **Remove logo** deletes it. A JPEG photo is saved as it is stored, without its rotation
  flag, so export it upright.

Nothing is required: without branding the pages have no header and use the default green. The logo is kept outside
`public/` in `var/branding` (or `APP_BRANDING_DIR`) and served at `/brand/logo/...` with long caching; it is not in
the database backup, so back that directory up with `var/downloads` or upload the logo again after a restore.

The pages follow the phone's light or dark mode. Screenshots of every state (390 px and desktop, light and dark):
[`docs/screenshots/branding/`](../screenshots/branding/), for example
[paid](../screenshots/branding/paid-light.png), [pending](../screenshots/branding/pending-dark.png).

The subscription page (`/s/...`) and the Mini App do not use the brand yet; they will adopt it later.

## Sales Controls

Use `Settings -> Sales` to enable or disable new service sales, renewals, and extra traffic purchases independently. Closing sales does not block existing waiting-payment orders, service viewing, config delivery, or approval of already-submitted payments.

## Language

Use the admin language switcher for `fa` and `en`. Persian UI is RTL and English UI is LTR.
