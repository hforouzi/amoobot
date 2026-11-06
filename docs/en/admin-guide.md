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

### Client apps in the customer guide

The **active** client apps are also the apps of the Mini App guide's **Install and connect** topic
([Mini App guide](miniapp-guide.md#client-apps-in-install-and-connect)): for the customer's device, the first active app
of this list is shown as **Recommended** with an **Install** button, the others in a smaller "Other apps" line. The
Client apps page says so at the top and warns about every device (Android, iOS, Windows, macOS, Linux) without an active
app: there the guide only shows a general text without app names or buttons. The guide never shows the "Add
subscription" link; it sends customers to **My services** for that.

The shop recommends **Happ**; **V2Box** is the alternative. Add **one record per platform**, so each one has that
platform's official install page (Apps -> Client apps -> Add):

| Name | Platforms | Sort order | Install page (store URL) |
|---|---|---|---|
| Happ | Android | 1 | `https://play.google.com/store/apps/details?id=com.happproxy` |
| Happ | iOS | 1 | `https://apps.apple.com/us/app/happ-proxy-utility/id6504287215` |
| Happ | macOS | 1 | the App Store link above, or the macOS download on `https://www.happ.su/` |
| Happ | Windows | 1 | `https://www.happ.su/` (or add a Download: the installer from the official site or `https://github.com/Happ-proxy/happ-desktop/releases`, linked to this record) |
| Happ | Linux | 1 | as for Windows |
| V2Box | iOS | 2 | `https://apps.apple.com/us/app/v2box-v2ray-client/id6446814690` |
| V2Box | Android | 2 | the V2Box page on Google Play (`https://play.google.com/store/apps/details?id=dev.hexasoftware.v2box`) |

- Open each link before you save it and check the publisher: these were taken from the vendors' sites and the
  stores in November 2026; copy-cat apps with similar names exist. The V2Box Google Play package in particular could
  not be confirmed from the vendor's own site.
- Keep Happ's sort order lower than V2Box's, so Happ is the recommended app on every device. Any other active app with
  a lower sort order would be recommended instead: the seeded list has **Hiddify** (sort order 10), v2rayNG, NekoBox
  and v2rayN **active**, and Happ and V2Box inactive. Deactivate the seeded apps you do not want, or give them a higher
  sort order; activate or delete the seeded Happ/V2Box records (their link templates are not verified, see their
  notes) when you add your own.
- Link template: leave it empty unless you tested the app's scheme on a phone (customers then copy the link). With a
  template, the service pages show the one-tap button; the guide only points there.
- A **Download** linked to the record (same platform) takes precedence over the store URL, and is counted.

## Trial plans (test & gift)

Trial plans live only under **Catalog → Trial plans** (web) and **Trial plans** in the Mini App admin. They are never
sold, and nothing about them is managed under Plans.

- **The hidden plan.** The first time a trial plan is claimed, the bot creates a hidden plan for it, called
  "[Trial] <title>". Every trial order and service hangs off this plan. It always copies the trial plan (inbound,
  traffic, duration), stays **inactive at price 0**, and belongs to that trial plan only.
- **Where it does not show up.** It is left out of everything on the sales side:
  - the plan list, its counts and search (web, Mini App, bot);
  - the discount-code plan picker, wholesale (reseller) prices, the bulk price page;
  - panel plan counts and inbound "Used by plans";
  - the customer catalog.

  The hidden plan is recognised by its link to the trial plan, or by its trial orders, never by its title. A normal
  plan you name "[Trial] …" stays an ordinary sales plan.
- **It cannot be changed from the sales side.** Activating, pricing, editing, duplicating, deleting or giving it a
  wholesale price is refused. A direct link to it (web or Mini App) says "this plan belongs to the trial plan …" and
  leads to that trial plan. The checkout refuses it even if it was made active by hand in the database.
- **Deactivating** a trial plan stops offering it to customers at once (bot and Mini App). Existing trial services
  keep running.
- **Deleting** a trial plan (web or Mini App, which use the same code) also deletes its claims, and decides about the
  hidden plan. The message says which of these happened:
  - **Nothing refers to the hidden plan** (no orders, so no services or claims): it is deleted too. Its wholesale rules
    are removed. Discount codes limited to it are **deactivated**, not widened to every plan.
  - **Orders refer to it** (every claim made one): it is **kept**, inactive, at price 0 and still hidden, and the
    message gives the number of orders and services. The trial services stay valid, and their history and reports
    still point to a plan.

  A delete and a claim running at the same moment are serialized: either the claim comes first, so the hidden plan
  is kept, or the claim finds the trial plan gone, so nothing is created.
- **Checking existing data:** `php bin/console app:trial-plans:backing-audit` lists every hidden plan, including those
  of trial plans deleted earlier, with its trial plan, orders, services, discount codes and any problems. It is a
  **dry run**: it changes nothing. `php bin/console app:trial-plans:backing-audit --apply` fixes the problems:
  - an active hidden plan is deactivated;
  - a priced or customizable one is set to price 0 and not customizable;
  - its wholesale rules are removed.

  Each fix is audited. It deletes no plan, order or service, and running it again finds nothing to fix.
- **Existing "[Trial] …" rows:**
  - "[Trial] …" plans that are a trial's hidden plan disappear from Plans by themselves. Run the command once,
    first without `--apply`, to see whether any of them was made active or priced; then with `--apply`.
  - Plans whose title merely starts with "[Trial]" but are no trial's hidden plan (no trial plan and no trial order
    refers to them) are listed by the command as a note and left alone. Such a plan is an ordinary sales plan;
    rename, deactivate or delete it under Plans if it is a leftover.

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
    be sent (the process stopped, Telegram refused it or did not answer) is sent by the next approval or gateway retry of
    that payment, or by the `orders` job within about 10 minutes (a sender that stopped is taken over after 5 minutes),
    only the messages Telegram has not accepted yet. It is sent only to the customer who bought the service and still owns
    it, only while the service is active, and only within 60 minutes of the sale; otherwise nothing is sent to anyone
    (claimed or transferred, suspended or deleted, too late). This is checked again before each message, so a delivery of
    several messages stops at the next one; a claim or transfer at the very instant of a send can still let that one
    message reach the previous owner (only what he already had). At most 3 attempts: a message whose answer was lost
    (timeout) may reach the customer once per attempt, so up to 3 copies; after the third failed attempt you get one
    alert "📭 پیام تحویل سرویس به مشتری نرسید" (payment and order) and should give the customer the links yourself;
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
  every inbound and removes its rows, so they never stand in the way of deleting an inbound.
- **Other operations** (renew, add traffic, suspend, resume, reset usage, usage sync, claim links, "reset my links")
  work on the one client and so on every inbound, as for any service. Resellers pay the plan's price.
- **`app:service:verify-links <id>`** also checks that the client is on every inbound recorded as attached; the not
  attached ones are listed for information.
- **Plan list:** an "Inbounds" column (count, "not sold", inactive ones). **Panel inbounds page:** "Used by plans",
  primary or additional.
- **Deleting** an inbound that a plan uses as an additional inbound, or a panel with such an inbound, is refused and the
  plans are named. Deleting a plan's primary inbound works as before (the plan then has no inbound).
- **Trial plans keep one inbound:** a trial's hidden plan (see "Trial plans" above; it is not in the plan list and
  cannot be edited there) only ever has the trial plan's inbound, and a trial is not given out while its hidden plan has
  additional inbounds (from before this rule).

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
    inbound, and the service's additional inbound rows are gone. Then remove the deleted Trojan inbound from the test plan before syncing
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

## Changing a plan's inbound

You can give a plan another inbound at any time (plan form, Mini App plan form, or the panel setup wizard's "Map
plans" step). Here is what that does and does not do.

**What changes**

- New sales of the plan get the new inbound. That includes an order the customer started before your change and paid
  after it.

**What does not change**

- Services already sold stay on the inbound they were created on. Nothing moves them: not saving the plan, not
  "Regenerate config", not the customer pressing "update" in their app.
- Their links are the old inbound's. That is correct: the panel really serves those services on the old inbound, and
  the bot only shows the links the panel gives.
- So keep the old inbound running on the panel while services use it. If you disable or delete it there, those
  customers lose their connection.

**Where the bot tells you**

- After saving the plan: a yellow message with how many existing services stay on another inbound, and on which.
- On the plan's edit page, under the inbound field. In the plan list: a "⚠ N on old inbound" badge.
- In the panel setup wizard: after "Map plans", the total for the plans you changed (shown on the next admin page).
- In the Mini App plan form: before you save, with the other warnings.
- On a service's page (web and Mini App), and after "Regenerate config", when the service is not on its plan's inbound.
- Trial plans: the same, for the trial's own services.

The counts include active, suspended and expired services (not deleted ones), and services sold by resellers or claimed
by customers. They never show a customer, a reseller or a link.

**Moving an existing service by hand (modern 3x-ui panels only)**

You can also move one service by hand, like this:

1. In 3x-ui, open the client. Its email is on the service page in the admin panel.
2. **Add** the new inbound to the client first, then **remove** the old inbound. In that order, the client is never
   left without an inbound.
3. In the admin panel, open the service and press **Regenerate config**. The links now come from the new inbound.
4. Ask the customer to update the subscription in their app. Single configs they copied before stop working.

Limits of the manual move:

- The service page and the bot still name the old inbound (its country and protocol), because the bot's own record of
  the service is not changed.
- For a plan with several inbounds: after you move a client by hand, the scheduled re-attach no longer checks that
  service.
- Not possible on a legacy panel, or to an inbound on another panel (that would be a new client: new subscription,
  usage counted from zero).

**Moving existing services (admin panel, Mini App or command line)**

`app:plan:apply-inbounds` moves a plan's existing services to the plan's current inbound, on modern 3x-ui panels. You
start a move from the plan's page in the admin panel ("Apply to existing services", below) or on the server with the
command. The scheduled task `inbound-moves` then continues it
(a timer every 30 minutes, installed by a **full** `deploy.sh`, not `--app-only`).

A move has two phases:

- **Phase 1, "switch"**: the service moves to the new inbound while the old one stays attached.
- **Phase 2, "retire"**: after a **grace period** (24 hours by default) the old inbound is removed from the client.

The grace period lets a customer whose app has not fetched the subscription yet keep working on the old inbound.

What phase 1 does, per service (one at a time, in batches):

1. It adds the plan's inbound to the service's client on the panel (the same client: same UUID, email, subId, expiry,
   traffic limit and usage), and reads the client back to confirm.
2. It switches the bot's record of the service to the new inbound (its page, the bot and the Mini App name the new
   one).
3. It refreshes the service's links from the panel.

What phase 2 does, per service, when its grace period is over:

1. It reads the client on the panel. If the new inbound is missing there, it attaches it again **first**: the old
   inbound is never removed from a client that is not on the new one.
2. It removes the old inbound, then reads the client back to confirm.
3. It refreshes the service's links from the panel: they now hold the new inbound only.

It refuses, and the service is reported as failed, when the old inbound is the only inbound the client is on, when the
old inbound is still the service's main inbound in the bot, or when the client is on neither inbound (nothing is sent to
the panel then). If you already removed the old inbound by hand, or deleted it on the panel, the step counts as done
(a deleted inbound only after the panel's inbound list confirms it). UUID, email, subId, expiry, limits and usage do not
change. A customer's old single configs stop working now; the subscription URL keeps working.

What the move does **not** do:

- **While a service is in its grace period, do not disable or delete the old inbound on the panel.** Customers work on
  both until the retire.
- No message to customers. A customer sees the new inbound's configs after updating the subscription in their app
  (the subscription URL does not change). Single configs copied before keep working, on the old inbound, until phase 2.
- A move is started and controlled from the admin panel, the Mini App admin or the command line.
- Services on a legacy panel, on another panel or on a test (dummy) panel are listed as skipped, with the reason.
  Deleted services are never moved. Adding or removing a plan's additional inbounds on existing services is not part
  of it (a moved service keeps its additional inbounds, and the old inbound becomes one of them when the plan still
  lists it).

**What customers see during a move.** Normally nothing changes: the move refreshes a service's links right after
switching it and again after removing the old inbound, and the customer's subscription URL stays the same. If that refresh fails (the panel did not answer or
answered with no links), the bot still holds the old inbound's single configs. Until the links are refreshed:

- the bot (service details, "Subscription link", "Resend config", "Subscription QR", renewal and add-traffic confirmations), the subscription
  page, the Mini App and the payment return page show the subscription URL with "Your links are being updated; use
  the subscription link or try again in a few minutes." The single configs are not shown or sent;
- a service with no subscription URL shows that text only;
- nothing asks the panel when a customer opens a page or presses a button.

The links come back by themselves with the next successful refresh: run the command again with `--resume=<runId>`
(the summary says how many moved services still wait for their links), or use "Regenerate config" on the service.
Services that were not moved, and services on legacy or other panels, are never affected. The admin service page and
`app:service:debug-links` still show what is stored.

**From the admin panel: "Apply to existing services"**

On the plan's page (Plans → the plan → **Show**), the block **Moving existing services** has the link **Apply to existing
services**. The plan's edit page has the same button. It needs the permission to manage plans.

1. **The dry run.** The page counts, from the database only (it never asks the panel), how many of the plan's services
   would move to the plan's inbound and how many are skipped, by reason: on another panel, panel not in modern mode, not
   a real panel, in another run, moved too often, already on the target, suspended / expired / trial services (not
   included unless you tick them). It lists them by service id and title only. Services with several inbounds have the
   note "protocol rules checked when the run is created": that check needs the panel and is done when you create the
   run, like the command.
2. **The options**: only one service id (recommended for the first test), only services now on one inbound, include
   suspended / expired / trial services, keep the old inbound (never removed automatically), and the hours before the
   old inbound is removed (0–168, default `plans.inbound_move_detach_grace_hours`). Press **Show the dry run** to
   recount.
3. **Continue** shows the confirmation: the target inbound, the counts, and what phase 1 (now) and phase 2 (after the
   grace period) do. **Create the run** creates it as *planned*; nothing is sent to the panel by the page. The scheduled
   task `inbound-moves` starts it at its next pass (every 30 minutes) and moves the services in batches.

With `Settings → Plans → Moving existing services` off, the page says so and has no create button (the counts still
show). A plan has one open run at a time: the page names the open run and has no create button, and a second create is
refused. Pressing the button twice creates one run. A run created here is the same as one created by
`app:plan:apply-inbounds <planId> --apply` with the same options.

**Test ONE service first**: put its id in "Only this service id", create the run, wait for the scheduled task (or run
`--resume=<runId>` on the server), check the client on the panel and connect with a test phone. Then apply to the rest.

**The run block on the plan's page** shows the open run, or the last one: its status, the target, started by (an admin
or "command line"), the times, moved / failed / skipped (by reason) / not moved yet, how many old inbounds were removed
and when the next removal is due. If the plan's inbound changed since the run was created, it says so: the run still
moves services to its own target; cancel it (and undo it if needed), then apply again. The statuses:

- **Planned**: created; the scheduled task starts it at its next pass.
- **Running**: the scheduled task moves services, retries failures and removes old inbounds when their grace period
  ends.
- **Paused**: nothing is done until someone presses Resume (also when the run paused itself because the panel did not
  answer 5 times in a row; the reason is shown).
- **Done**: nothing is left: every service was moved, skipped, or failed with no automatic retry left.
- **Cancelled**: services not moved yet will not be moved. While moved services wait for their old inbound to be removed,
  the run stays open until that is done.

Only the buttons valid for the status are shown:

- **Pause** (planned or running) and **Resume** (paused): like `--pause-run` and `--resume`. Resume does not call the
  panel; the scheduled task continues the run at its next pass (or `--resume` on the server, at once).
- **Cancel**: like `--cancel` (asks for confirmation).
- **Undo**: like `--undo`; it calls the panel now and can take a while for a big run (asks for confirmation). Services
  whose old inbound was already removed cannot be put back; the message says how many and what to do (a new run back
  to the old inbound). Press Undo again if it stopped half way.
- **Retry failed now**: gives the failed services a new set of attempts; the scheduled task retries them at its next
  pass.

Resume, Retry and Undo are not shown while the switch is off. The same counts of failed moves (and those with no
automatic retry left) are shown on the panel's page and by `app:panel:doctor`. Audit entries are the command's
(`plan.inbounds_apply_started`, `_paused`, `_finished`, `_undone`, counts only).

**On a service's page** the line "Existing services" shows its latest move: the run, old → new inbound, the state,
attempts, next try, the last error (masked) and when the old inbound was or will be removed. A failed move has
**Retry now** (permission to manage services; at most 5 times in 10 minutes per service): the scheduled task retries it
at its next pass. The services list has the tabs **Being moved** and **Move failed**.

**In the Mini App admin.** The same, in Telegram (needs the same permissions: managing plans for the plan's screens and
buttons, managing services for "Retry now"):

- **Plans → a plan**: the block **Moving existing services** (the open or last run, its counts, "started by", the
  target, "the plan's inbound changed since this run was created") with the buttons valid in its state, and the link
  **Apply to existing services**. A run started by the bot's main admin (`TELEGRAM_ADMIN_CHAT_ID`, without an admin
  account) or from the command line shows "not recorded" as its starter; the audit log names the admin.
- **Apply to existing services**: the same dry run (counted from the database, no panel request), the same options
  (Persian digits are accepted), **Continue** for the confirmation, then **Create the run**. Tapping it twice creates
  one run; with an open run, or with the switch off, there is no create button.
- **Cancel** and **Undo** ask for Telegram's confirmation first. **Resume** and **Retry failed now** only make the moves
  due: the scheduled task works on them at its next pass (the screen says so).
- **Services → a service**: the block "Inbound move #run" with the same details and **Retry now** (the same limit as
  the web: 5 per 10 minutes per service, both together). **Services** has the chips **Being moved** and **Move failed**
  while the switch is on.

A run created in the Mini App is the same as one created on the web or with the command (same options, same checks,
same audit entries).

How to use it from the command line:

1. **Dry run first** (it changes nothing, and works with the switch off):
   `php bin/console app:plan:apply-inbounds <planId>`. It lists each service with its current inbound, the target, and
   "move" or "skip" with the reason. It only reads the panel. `--json` gives the same as JSON.
2. Turn on `Settings → Plans → Moving existing services` (`plans.inbound_move_enabled`); `--apply` refuses without it.
3. **Test ONE service first**: `php bin/console app:plan:apply-inbounds <planId> --service=<serviceId> --apply`. Check
   it on the panel (on both inbounds, same UUID and usage), update the subscription in a test phone, connect.
4. Then the rest: `php bin/console app:plan:apply-inbounds <planId> --apply`. By default only active services;
   `--include-suspended`, `--include-expired`, `--include-trials` add those. `--from-inbound=<id>`, `--limit=<n>` and
   `--after-id=<serviceId>` narrow it; `--batch-size` and `--pause` override the settings below for this run.
5. Read the summary. If some failed (exit code 1), they keep their old inbound and links; they are retried with the
   attach retry settings (`plans.attach_max_attempts`, `plans.attach_retry_delay_minutes`) when you run
   `php bin/console app:plan:apply-inbounds <planId> --resume=<runId>` later, or when the scheduled task reaches them.
   An admin alert is sent when a service's
   attempts are used up, and once when the run finishes with failures.
6. If the panel does not answer 5 times in a row, the run **pauses itself** (one admin alert). Nothing changed for the
   services not moved yet. When the panel is back: `--resume=<runId>`.

Each plan has at most one open run; a second `--apply` is refused and names the open run (continue it with
`--resume`). The run's target and scope are fixed when it is created. A service sold while a run is open gets the
plan's current inbound and is not part of the run. Exit codes: 0 done, 1 some failed or the run paused, 2 could not
start (a clear message says why); an undo that left services behind also exits with 1. The output and the logs show ids and titles only: never a customer, a link, a UUID
or a token.

**Removing the old inbound (phase 2).** The scheduled task does this for you, or you start it yourself:

- Choose the grace period when you create the run: `--grace-hours=N` (0–168), otherwise the setting below. With 0 the
  old inbound is removed in the same pass. With `--keep-old` it is never removed automatically, only with
  `--retire-now`.
- **After the grace period**, the scheduled task's next pass removes it (`amoobot-job-inbound-moves`, at :21 and :51). To
  do it at once: `php bin/console app:plan:apply-inbounds <planId> --resume=<runId>`. Either way one pass handles what
  is left of phase 1 **and** every removal that is due, and running it twice does nothing twice. A run that is open
  refuses a second `--apply`: use `--resume`.
- **Without waiting**: `--retire-now=<runId> --resume=<runId>` for the whole run, adding `--service=<id>` for one
  service. `--retire-now` alone (without a run id it takes the plan's latest run) only marks the services as due; the
  next `--resume` removes the old inbound. It also gives failed removals a new set of attempts.

Failed removals are retried with the same attach settings, an alert is sent when a service's attempts are used up, and
the run pauses itself after 5 failed panel reads in a row, as in phase 1.

**What the scheduled task does.** Every pass goes through the planned and running runs in order of id and handles what is
due: services not moved yet, retries whose back-off is over, old inbounds whose grace period is over, and links still to
refresh. It uses the same code, limits and alerts as `--resume`. It does nothing while `plans.inbound_move_enabled` is off
(no panel request). It never touches a **paused** run: after the panel is back, or after your own pause, only `--resume`
continues it. A cancelled run with moved services stays open until their old inbound is removed, and the task finishes
that part. It starts no new service after 7 minutes; the next pass picks up the rest. A skip because a command is working
is not an error (one line in the log). Output: `/var/log/amoobot/inbound-moves.log`; by hand:
`php bin/console app:automation:run --only=inbound-moves [--dry-run]`.

**Pause, resume, cancel, undo.** Each takes the run id from the output:

- `--pause-run=<runId>`: a pass working on the run finishes the service in hand and takes no other.
- `--resume=<runId>`: continues a run, also one that paused itself.
- `--cancel=<runId>`: services not moved yet will not be moved, including failed ones waiting for a retry. A service in
  the middle of a step finishes that step. Services already moved are still waiting for phase 2, so the run stays open
  until they are done.
- `--undo=<runId>`: moved services whose old inbound is **still attached** go back to it: the new inbound is removed
  (unless it is one of the plan's additional inbounds), the bot's record returns to the old inbound, and the links are
  refreshed. Services not moved yet are cancelled.

**What undo cannot do.** Once the old inbound was removed from a service, `--undo` does not put it back. It prints how
many services that concerns ("the old inbound was already removed from N services") and exits with code 1. Those need a
new run from the new inbound back to the old one: set the plan's inbound back to the old one and run the command again,
dry run first. The old inbound must still exist and be active. If you may want to go back, use a long grace period or
`--keep-old`. A failed undo is not retried by itself: run `--undo` again.

Only one pass runs at a time. `--apply`, `--resume`, `--retire-now` and `--undo` take the same lock as the scheduled task
and refuse at once with "a scheduled run is in progress, try again in a minute" (exit code 2, nothing changed). A pass of
the task can take up to about 9 minutes. `--pause-run` and `--cancel` do not need the lock, so you can stop a pass while it
works; the dry run does not need it either.

Skip reasons you may see: other panel, panel not in modern mode, not a real panel, suspended / expired / trial (not
included), in another run, moved too often (3 moves within 24 hours), protocol mix (e.g. Shadowsocks inbounds with
different methods: move it by hand), and an inactive target inbound refuses the whole run.

The settings, under `Settings → Plans → Moving existing services to a new inbound` (SETTINGS_MANAGE):

- `plans.inbound_move_enabled` (off): needed for `--apply`, `--resume` and `--undo`;
- `plans.inbound_move_batch_size` (20, 1–100): services per batch;
- `plans.inbound_move_pause_seconds` (2, 0–60): the pause between batches;
- `plans.inbound_move_detach_grace_hours` (24, 0–168): the grace period of a new run; a run keeps the value it was
  created with.

**Adding an inbound to existing services (`--kind=add`, or on the web and in the Mini App: see "On the web panel" below).** A plan's additional
inbound reaches new sales at once. `--kind=add` brings the plan's existing services to it: each client gets the one
inbound C on top of what it has. The primary, UUID, subscription id and URL, expiry, limits and usage do not change, and
neither does any other inbound.

The plan changes first: **add C to the plan** (Plans → the plan → additional inbounds), then run the command. A run never
writes the plan. It needs both `plans.inbound_move_enabled` and `plans.multi_inbound_enabled`, an active C on the panel of
the plan's primary, and a plan that is not a trial's backing plan. One run is one inbound: two inbounds are two runs, one
after the other.

1. **Dry run first** (it writes nothing, and works with the switches off):
   `php bin/console app:plan:apply-inbounds <planId> --kind=add --inbound=<inboundId>`. One read per service, and for each
   one the inbounds on the panel now, the inbounds after, and "add" or "skip" with the reason. `--json` carries `action`,
   `inbounds_now`, `inbounds_after` and `note`.
2. **One service**: add `--service=<serviceId> --apply`. Check it on the panel, update the subscription on a test phone,
   and connect to the old inbound and to C.
3. **The rest**: the same with `--apply`. `--from-inbound`, `--include-suspended`, `--include-expired`, `--limit`,
   `--after-id`, `--batch-size` and `--pause` work as for a move. `--keep-old`, `--grace-hours`, `--retire-now` and
   `--include-trials` are refused: an add has no old inbound to keep or remove, and trials are never part of one.

What the customer sees: the new server at the next subscription update, and C's link on the bot, `/s/{token}` and in the
Mini App after the refresh. Everything keeps working meanwhile, and the links are never hidden for an add. Customers get
no message.

The scheduled task, `--resume`, `--pause-run`, `--cancel`, retries, the alerts and the pause after 5 failed panel reads
work as for a move; the run knows its kind, so these need no `--kind`. A service that is already on C on the panel gets its
row with nothing sent. After the add, the row is an ordinary attached row: `attach-inbounds` keeps it, and attaches C again
if it is removed by hand.

**If the plan changes during a run.** At the start of each pass the run checks that C is still one of the plan's additional
inbounds. If not, it **pauses** with a reason and one alert, and does not touch a service. Put C back in the plan and
`--resume`, or `--cancel`. `--resume` with C still out of the plan pauses it again. Only services that have not started
wait for this: links still to refresh are finished.

**Undo** (`--undo=<runId>`, with `plans.inbound_move_enabled` alone, in case you have to turn the other switch off): C comes
off each client this run put it on, done services included, and the row goes. The links are hidden until they are refreshed.
Services already on C before the run are left alone, and the output counts them. Services not started are cancelled.
A failure keeps the row held so the timer does not attach C again: run `--undo` again. Undo is refused for a run that is
not the plan's latest, of any kind, since a later run may have built on it. The plan is not changed: it **still sells C**,
so take C out of the plan as well if that is what you want, or the drift count will show those services as missing it.

Skip reasons of an add, besides those above: `is_primary` (C is the service's primary), `no_primary` (the service's primary
inbound was deleted in the bot, so the plan rules cannot be checked), `already_on_target` (it has the row already). Undo
adds `last_inbound`: C is the only inbound the client has on the panel, so it stays, and so does the row.

**Removing an inbound from existing services (`--kind=remove`, or on the web and in the Mini App: see "On the web panel" below).** Taking an additional
inbound off a plan changes new sales only: existing services keep it. `--kind=remove` takes the one inbound D off the plan's
existing services. The primary, every other inbound, UUID, subscription id and URL, expiry, limits and usage do not change.
A client is never left with zero inbounds, and a plan's primary is never removed: changing
that is `--kind=primary`.

The plan changes first: **take D out of the plan** (Plans → the plan → additional inbounds), then run the command. A run
never writes the plan. It needs both `plans.inbound_move_enabled` and `plans.multi_inbound_enabled`, and a plan that is not a
trial's backing plan. D may be inactive, and that is the usual case when you are retiring it. One run is one inbound.

1. **Dry run first** (it writes nothing, and works with the switches off):
   `php bin/console app:plan:apply-inbounds <planId> --kind=remove --inbound=<inboundId>`. One read per service, and for each
   one the inbounds on the panel now, the inbounds after, and "remove" or "skip" with the reason. Only services with a row
   for D are listed.
2. **One service**: add `--service=<serviceId> --apply`. Check it on the panel, update the subscription on a test phone, and
   connect through an inbound that stays.
3. **The rest**: the same with `--apply`. `--include-suspended`, `--include-expired`, `--limit`, `--after-id`, `--batch-size`
   and `--pause` work as for a move. `--from-inbound`, `--keep-old` and `--include-trials` are refused.

What the customer sees: D's link disappears from the bot, `/s/{token}` and the Mini App when the panel's links are read again.
If that read fails, the stored links are hidden until a retry succeeds. The phone keeps the server until the next subscription
update, then loses it. D stops working for that client at the detach, so warn customers yourself if they need warning: no
message is sent.

**Grace period.** `--grace-hours=<0–168>` waits that long before the panel is touched, for each service. The default for a
remove is **0**, whatever `plans.inbound_move_detach_grace_hours` says. `--retire-now=<runId>` ends the wait for a run
(`--service=<id>` for one service), and the next pass removes the inbound. It takes no `--kind`.

The scheduled task, `--resume`, `--pause-run`, `--cancel`, retries, the alerts and the pause after 5 failed panel reads work as
for a move, and the run knows its kind. While a run works on a service, its row is held: it shows as `detached` with "being
removed by run #N", and `attach-inbounds` and "Retry attach" leave it alone, so D is not put back. A remove that keeps failing
stays held and raises one alert: settle it with `--retire-now=<runId>` and `--resume`, or undo it. `--cancel` stops what has
not started and leaves those services alone; a remove that has started is not cancelled, because only a panel read can tell
whether D left. Finish it, or use `--undo`.

**If the plan changes during a run.** At the start of each pass the run checks that the plan no longer sells D. If the plan
sells it again, or D became its primary, the run **pauses** with a reason and one alert, before any panel call. Take D out
again and `--resume`, or `--cancel`.

**Undo** (`--undo=<runId>`, with `plans.inbound_move_enabled` alone): D goes back on each client this run took it off, with the
same UUID and usage, and the row comes back as attached. Services not started are cancelled. A failure leaves that service as
it was: run `--undo` again. Undo needs D to still exist and be active: otherwise the service stays as it is and the output says
so. It is refused for a run that is not the plan's latest. The plan is not changed: it **still does not sell D**, so the drift
count shows those services as having an extra inbound until you add D to the plan again or remove it once more.

Skip reasons of a remove, besides those above: `is_primary` (D is the service's primary), `no_primary` (the primary was deleted
in the bot), `last_inbound` (D is the only inbound the client has on the panel, so it stays, and so does the row),
`not_attached` (no row for D any more). Undo adds `later_move` (a later run changed that service, so it is left alone). If the
client is not on its primary on the panel, the step fails with nothing sent: look at the client on the panel. A client already
off D loses only the row.

After a remove of every service, D can be deleted in the bot. Rows of **deleted** services no longer hold it.

**On the web panel (add and remove).** Plans → the plan → **Apply to existing services** (needs managing plans). With both
switches on, the page has three tabs: move, add an inbound, remove an inbound. A trial's backing plan shows no tabs.

1. Change the plan first, as above. The inbound chooser offers only what the plan allows: for an add, an active additional
   inbound of the primary's panel that some service still lacks; for a remove, an inbound the plan no longer sells that some
   service still has. The primary is never offered. If there is nothing to offer, the page says why and links to the plan.
2. Choose the inbound and the scope. The page counts from the bot's own data, asks no panel and writes nothing, and works with
   the switches off. For each service it shows the inbounds now and after. A case only the panel can answer, such as a protocol
   mix, is counted apart and checked when the run is created.
3. **Continue** shows the confirmation: the inbound, how many services, what the customer sees. For a remove it also shows the
   grace period, whose field starts at **0** and ignores `plans.inbound_move_detach_grace_hours`.
4. The confirm button only records the run as planned, as `--apply` does. The inbound-moves task does the work; the page never
   calls the panel.

The options are those of the command, and what it refuses is refused here. The plan page shows the run's block with the
kind's own wording and counts, and the buttons to pause, resume, cancel, retry failed services and undo. With
`plans.multi_inbound_enabled` off, resume and retry are hidden and refused, as creating a run is: pause, cancel and undo stay,
so you can always stop. Undo works with `plans.inbound_move_enabled` alone, and it calls the panel from the request. The
service page names the inbound ("Inbound added: C", "Inbound removed: D"), with **Retry now** on a failed move. Retry is not
offered for an add whose undo stopped, because it would put the inbound back: run undo again. `--retire-now` is on the command
line only.

**In the Mini App admin.** The same on the plan's "Apply to existing services" screen, with the same permissions, rules,
switches and refusals: the kind is a chip, numbers may be typed with Persian digits, cancel and undo are confirmed first, and
a double tap is one request. A plan's additional inbounds are edited on the web panel only, so change the plan there first.

The command, the pages and the Mini App have been tested against a simulated panel only, not against a real 3x-ui panel: try one service
first. Design: [plan-inbound-change.md](../design/plan-inbound-change.md).

## Branding (customer pages)

`Settings -> Branding` (needs the *Manage settings* permission) sets what customers see on the payment result pages
(`/payment/return/...` and the cancel page). Every change is in the audit log (`settings.update`, page `branding`).

- **Brand name**: shown in the page header when there is no logo, and in the browser tab. At most 60 characters,
  saved exactly as typed: no space at either end and no invisible or control characters (the Persian zero-width
  non-joiner is fine). Leave it empty for none.
- **Accent colour**: the top bar and the main button, as `#rrggbb`. Empty means the default green `#166534`. On a
  light colour the button text turns dark so it stays readable.
- **Support chat link** (`support.url`): `https://t.me/<username>` or `https://telegram.me/<username>` (username 5 to
  32 letters, digits or `_`, nothing after it), saved as the `t.me` link. When set, the Mini App guide's "Contact
  support" topic and the bot's support answer show a **Message support** button that opens this chat. Empty means no
  button (both look as before). Details: [Mini App guide](miniapp-guide.md#message-support-button-supporturl).
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
