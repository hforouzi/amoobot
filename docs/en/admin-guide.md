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
failed on a schedule, alerts the admins and shows the state (below); phase **M2c** adds the sale rule everywhere a plan
is sold or paid and the fixes of the independent review. The customer gets the subscription link, which
carries every attached inbound.

- **Switch:** `Settings → Plans → Allow additional inbounds on plans` (`plans.multi_inbound_enabled`, SETTINGS_MANAGE),
  off by default. With it off the plan form, the plan list, the inbound page and the service page look exactly as
  before, a request that sends additional inbounds anyway is refused, and **a plan with additional inbounds is not
  sold**. Turning it off while plans keep additional inbounds names those plans. **Run the checklist below on your
  panel before turning it on.**
- **When such a plan is sold:** all of: the switch on; the plan active; the primary and every additional inbound
  active and on the same panel; that panel usable and a 3x-ui panel in modern mode (forced, or auto with modern
  detected: Panels → test connection). Otherwise it is hidden from the bot (plan list, plan choice, card-payment step),
  the Mini App and resellers, an order is not created from a draft, and an order already waiting is refused when the
  payment starts (gateway or card) and at wallet payment (resellers too), checked against the inbounds the order was
  taken with. The plan list shows "not sold" (the reason on hover) and the save warning says why. An inactive additional
  inbound stops the plan selling (D3). **Not refused:** renewals and add-traffic of an existing service (they change the
  one client, whatever the switch), and a card receipt already submitted (the money may be on its way; the order is
  provisioned as below).
- **Plan form** (web panel, PLANS_MANAGE): "Additional inbounds" lists the active inbounds of the primary inbound's panel,
  without the primary. A save is refused when an inbound is on another panel, is the primary, is newly chosen but
  inactive, the panel is not in modern mode, the plan has custom traffic or duration (those stay single-inbound), there
  are more than 20, or the protocols break the rules below. The Mini App and the bot have no field yet (M3); a change
  they make (e.g. moving the primary to another panel) is checked the same way.
- **Protocol rules (D1):** VLESS, VMess and Trojan together; Shadowsocks only when every Shadowsocks inbound of the plan
  uses the same method; a VLESS primary with the vision flow only with flow-capable VLESS inbounds (TCP with
  TLS/REALITY). The method and flow capability are read from the panel when these rules apply: a panel that does not
  answer refuses the save.
- **What a purchase does:** the order keeps the plan's inbounds as they were when it was taken; provisioning uses
  that list, not the plan as edited later (orders taken before M2c use the live plan). One client is added on the
  primary and every additional inbound in one request; inbounds the panel did not attach are attached right after the
  payment is recorded (below).
  **The service is delivered as soon as the primary works** (D2): an additional inbound that still fails is recorded as
  "not attached" with the reason, and never fails the payment, the order or the wallet. Further rules:
  - while the payment is being recorded (the order is locked then) only the add and its read back are sent to the
    panel; no inbound is attached then. Right after it the customer gets the delivery message (the primary's links and
    the subscription link), then one attach pass runs with nothing locked: at most **15 seconds in all** (every call of the pass included: a call is cut short at the
    limit and none is sent after it) and at most 3 one-by-one attaches after a refused batch. What is left stays
    "attaching…" for the scheduled run; when the panel cannot be read back after the attach the inbounds are "attaching…"
    too (the scheduled run checks the panel and finishes them). The payment and the order are never changed by it. When the pass attached servers the customer gets one short
    follow-up ("2 more servers were added to your service … update the subscription in your app"); the subscription link
    already carries them, and the Mini App and the service page show the live state. A delivery message that could not
    be sent (the process stopped, Telegram refused it) is sent once by the next approval or gateway retry of that
    payment, or by the `orders` job within about 10 minutes; it is never sent twice and is given up after 3 failed tries;
  - an inbound inactive or on another panel by then is recorded "not attached" with that reason (alerted at the next
    run); an inbound **deleted** by then cannot be attached and the admins get an alert naming it at once;
  - with the switch **off** when the paid order is provisioned: the customer gets the primary on any panel, nothing is
    sent to the panel for the additional inbounds, and they are recorded "not attached" ("switched off when the service
    was created"); after the switch is on again the scheduled run attaches them (or "Retry attach" at once), except on a
    panel that is not in modern mode;
  - with the switch **on** and the panel **not in modern mode** (legacy, or another panel type) the plan is not sold; an
    order paid anyway (e.g. the mode changed in between) still gets its primary, exactly as a one-inbound plan: the
    additional inbounds are recorded "not attached" with "the panel is not in modern mode (Panels → test connection);
    attached once it is", you get one "not attached" alert at the next run, and the customer is told to contact
    support. Set the panel to modern and the next run (:25/:55) attaches them.
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
- **Trial plans keep one inbound:** a trial's hidden plan ("[Trial] …" in the plan list) refuses additional inbounds on
  save, and a trial is not given out while its hidden plan has any (from before this rule).

### Scheduled retry and alerts

- **What the job does** (`app:automation:run --only=attach-inbounds`, a systemd timer every 30 minutes at :25/:55, in
  the `lifecycle` lock group; by hand: `php bin/console app:services:attach-inbounds [--dry-run]`). Only for **active**
  services (suspended, expired and deleted ones are never touched):
  - it retries each "not attached" inbound with back-off: the first retry at the next run after the sale, then after a
    delay that doubles after each failure (5 minutes × 2ⁿ by default, at most 6 hours), until the attempts are used up (12 in all, the one at the sale
    included). It never creates a second client;
  - every 6 hours it compares the attached inbounds with the panel (one read per service): an inbound the client was
    **removed from by hand in 3x-ui** becomes "not attached", the admins are alerted and it is attached again; an inbound
    the panel already has (e.g. attached by hand) becomes "attached" without an attempt. A panel answer that does not
    list the primary inbound is not trusted: nothing changes for that service in that run. **Loops:** an inbound
    removed and attached again 3 times within 24 hours is not attached again at the next removal: it is "not attached"
    with "automatic re-attach stopped", the admins get one "attempts used up" alert, and "Retry attach" still works;
  - a panel that cannot be read for 5 services in a row is left alone for that run (the others go on); a run never
    marks a panel "degraded" and uses up no attempt while the panel is down. Two runs at once are safe.
- **Settings** (`Settings → Plans → Retrying inbounds that were not attached`, SETTINGS_MANAGE):
  `plans.attach_retry_enabled` (on), `plans.attach_max_attempts` (12, 1–100), `plans.attach_retry_delay_minutes` (5,
  1–1440). Raising the attempts lets a given-up inbound be retried again.
- **Alerts** (Telegram, to the admins who may retry: `TELEGRAM_ADMIN_CHAT_ID` and linked admins with SERVICES_MANAGE):
  one message per service when an inbound fails for the first time, one when its attempts are used up, and one when it
  is attached again; plus one, at once, when an inbound of the order was deleted before provisioning. They name the
  service, user, plan and inbound by id and title and quote the error, never a link, UUID, client email, token or panel
  address. An inbound found removed on the panel and attached again in the same run sends nothing. No stored error and no
  log line of the app (the jobs', the web's and the payment plugins') shows a customer's Telegram id: a client email is
  written `tg_[redacted]_order_<n>` and a Telegram id field `[redacted]`.
- **Where to see failures:** the services list has a badge "N inbounds not attached" and a tab **"Inbounds not
  attached"**; the service page lists each inbound with its state, attempts, last error and when the next automatic try
  is (or that the attempts are used up); the panel page and `app:panel:doctor <panel id>` count the not attached ones.
- **How to retry by hand:** "Retry attach" on the service page (SERVICES_MANAGE), at any time; it ignores the back-off
  and the attempt limit.
- **Customers** (bot service detail, Mini App service page): "Servers: N" and, for the ones not attached, either "1
  server is still being connected and will be added automatically" (the scheduled retry will still try it) or "1 server
  is not connected yet. Please contact support." (its attempts are used up, a re-attach loop was stopped, the scheduled
  retry is off, or the service's panel is not in modern mode so no automatic retry runs). Never which one, where or
  why. Services without additional inbounds look as before.
- **How to disable:** untick `plans.attach_retry_enabled` (no retry, no check; the alerts still go out), or turn the
  switch `plans.multi_inbound_enabled` off (the job then reads and changes nothing, and nothing is shown).

### Before turning the switch on (real-panel checklist, 3x-ui v3.11.0)

Run this once on your own panel with test inbounds, not customers' inbounds, after deploying M2d (a **full**
`deploy.sh`, so the `attach-inbounds` timer exists). Turn the switch on, but keep **every multi-inbound plan inactive
except one test plan** that only you buy (from your own Telegram account, paying from your wallet or with a card payment
you approve yourself). Steps marked **required** must pass before you sell; if one fails, turn the switch off and report
what you saw (the service id, the row's state and last error, and `journalctl -u amoobot-job-attach-inbounds -n 50`).
The optional ones are needed only if you will sell that combination.

1. **Required: panel mode.** Panels → your panel → "Test connection" reports **modern**. Prefer setting the mode to
   "modern" rather than "auto", so a panel change cannot silently switch it (with legacy the plan is not sold, and an
   order paid meanwhile gets its primary only: step 12).
2. **Required: when the plan is sold (F1).** In 3x-ui create three test inbounds: VLESS-REALITY (TCP), VMess-WS,
   Trojan (TLS). In the admin panel: Panels → "Sync inbounds". Create the test plan: primary VLESS-REALITY, additional
   VMess-WS and Trojan; save (warnings about several protocols are expected) and activate it. Then check, from your own
   Telegram account and the Mini App:
   - switch **off**: the plan is in neither the bot nor the Mini App (the admin plan list looks as before, without
     the Inbounds column);
   - switch **on**: the plan is listed in both, and the plan list's Inbounds column shows 3 without "not sold";
   - Panel inbounds → make the Trojan inbound **inactive**: the plan disappears from the bot and the Mini App, the badge
     says why; make it active again: it is back.
3. **Required: one client, three inbounds.** Buy the test plan. In 3x-ui → Clients: exactly **one** client with the
   service's email, on the three inbounds, one UUID, one subscription ID. Admin service page: service active;
   "Additional inbounds" both "attached", 1 attempt, no error. Nothing in the last error shows a Telegram id (an email
   is written `tg_[redacted]_order_<n>`).
4. **Required: links.** You get **one** delivery message and no follow-up (the panel put the client on the three
   inbounds at once, so nothing was left for the pass). Import its subscription link in a client app (v2rayNG, Hiddify
   or Streisand): **three** configs. On the server run `php bin/console app:service:verify-links <service id>`: no DIFF
   (INFO lines are fine). Admin service page → "Regenerate config": still three links stored.
5. **Required: connect.** Connect through each of the three configs; each works, and in 3x-ui the one client's usage
   grows whichever config you use.
6. **Required: limits on the one client.** Give the test service little traffic and use it up through the VMess config:
   all three configs stop. Add traffic or renew from the bot: all three work again. Suspend from the admin service page:
   all three stop; resume: all three work. Reset usage: 3x-ui shows 0 for the client.
7. **Required: the plan changes between order and payment (F4).** Start a purchase of the test plan in the bot and stop
   at the payment step (an order waiting for payment). In the admin panel remove VMess-WS from the plan's additional
   inbounds and save. Pay the waiting order. Expected: the client is on **all three** inbounds (the order keeps what it
   was sold with) and both rows are "attached". Put VMess-WS back on the plan.
8. **Required: failure path (D2, F4).** In 3x-ui only (do not sync), delete the Trojan inbound, then buy the test plan
   once more. Expected: the service is delivered (VLESS and VMess work), the payment is approved, the Trojan row is "not
   attached" with an error, and 3x-ui has one client for it. Within 30 minutes (the next run at :25/:55) you get one
   "not attached" alert for the service. Click "Retry attach": the row stays "not attached", attempts 2, and there is
   still one client (it never creates a second one). For a successful retry, use a service whose inbound you only
   **disabled** in 3x-ui (if 3x-ui v3.11.0 refuses to attach to a disabled inbound) and enable it again before
   clicking: the row becomes "attached".
9. **Required: switch off with a paid order (F3).** Start a purchase and stop at the payment step, turn the switch
   **off**, then pay. Expected: the payment is approved and the service delivered on the primary only; both rows are
   "not attached" with "plans with several inbounds were switched off when the service was created"; nothing was sent
   to the panel for them (3x-ui: the client is on the primary only). Turn the switch back on and click "Retry attach":
   both rows become "attached".
10. **Required: delete.** Delete the test services from the admin panel: in 3x-ui the client is gone from every
    inbound, and the rows show "detached". Then remove the deleted Trojan inbound from the test plan before syncing
    inbounds.
11. **Required: reset links and claim.** On a new test service use "Reset links" in the bot, then a claim link to move
    the service to a second Telegram account: the client keeps its three inbounds, and the subscription imports three
    configs with the new UUID.
12. **Required: panel not in modern mode after payment (B2).** Start a purchase of the test plan and stop at the payment
    step. Set the panel's mode to **legacy** (Panels → your panel), then pay. Expected: the payment is approved and the
    service delivered on the primary only (it connects); both rows "not attached" with "the panel is not in modern
    mode"; at the next run (:25/:55) one "not attached" alert for the service, and no second one at the run after;
    the customer's service page says "not connected yet. Please contact support.", never "added automatically". Set the
    mode back to **modern**: the next run attaches both rows and the subscription imports three configs.
13. **Required: nothing waits behind the attach (B1).** In 3x-ui create one more test inbound (VMess), "Sync inbounds",
    add it to the test plan, then delete it **in 3x-ui only**. Buy the test plan paying by card; approve the payment
    and at once approve it again from a second browser tab. Expected: the second answers "already processed" without
    waiting for the first's attach work; the first returns at most about 15 seconds after the service exists; your
    Telegram gets **exactly one** delivery message (sent before the attach pass, with the primary's links) and **one**
    follow-up "2 more servers were added" (the panel refused the add that named the deleted inbound, so the sale
    delivered the primary alone and the pass attached VMess-WS and Trojan), although you approved twice; the deleted
    one's row is "not attached". If you take VMess-WS and Trojan off the plan first, so that only the deleted inbound is
    left to attach, there is no follow-up at all.
    Remove that inbound from the plan afterwards.
14. Optional: **VLESS vision + VLESS-WS** on one client (primary VLESS-REALITY with `xtls-rprx-vision`, additional
    VLESS-WS). Both connect, the flow applied only where valid; or note that it fails and do not sell that combination.
15. Optional: **two Shadowsocks inbounds** with the same method; record what works (two different 2022 methods are
    refused by the plan form).
16. Optional: **removed by hand.** Remove the client from one inbound by hand in 3x-ui. `verify-links` reports a DIFF
    for that inbound. Run `php bin/console app:services:attach-inbounds` (the comparison runs every 6 hours; set the
    row's `checked_at` to NULL to force it): the command reports it found removed and the client is attached again in
    the same run. **No alert is sent** for that (one is sent only if attaching it fails, or once if it keeps being
    removed: the third re-attach within 24 hours is the last, the next removal stops the automatic re-attach with one
    "attempts used up" alert).

When 1 to 13 pass, activate the plans you want to sell.

**Upgrading.** Run the migrations: `Version20261101090000` (M1) adds the `plan_additional_inbound` and
`vpn_service_inbound` tables; M2a needs no new migration; `Version20261102090000` (M2b) adds the retry columns
(`next_attempt_at`, `checked_at`, `alert_state`, `alerted_at`) and an index to `vpn_service_inbound`;
`Version20261103090000` (M2c) adds `reattach_count` and `reattach_since`; M2d and M2e need no migration and no new job (an
`--app-only` deploy is enough once M2b's timer exists; M2e's resend of a lost delivery message runs in the existing
`orders` job, and orders paid before M2e are never resent). Nothing existing is changed and nothing is backfilled. M1's `down()` refuses and changes nothing while either table has a row (remove the additional inbounds from
every plan first) or while M2b's migration is applied, and M2b's `down()` refuses while M2c's is applied (roll back the
newest first). The M2b timer (`amoobot-job-attach-inbounds`) is installed by a **full** `deploy.sh` run, not
`--app-only`; until then run the command by hand. Right after the deploy that installs it, `amoobot-check-jobs` and
`post-deploy-check` show it as **NEW** (a warning: "first run pending") until its first run, not as a failure; if it has
still not succeeded after its max age (95 minutes) it becomes NEVER.

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
