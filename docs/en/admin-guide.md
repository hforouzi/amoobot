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

A plan can name **additional inbounds** of the same panel: a buyer's one client is meant to be attached to all of them.
Design: [multi-inbound-plans.md](../design/multi-inbound-plans.md). This release (phase M1) only stores them. **They take
effect when provisioning support ships (M2): until then a plan with additional inbounds is not sold at all**, so no
customer buys servers that are not delivered.

- **Switch:** `Settings → Plans → Allow additional inbounds on plans` (`plans.multi_inbound_enabled`, SETTINGS_MANAGE),
  off by default. With it off the plan form, the plan list and the inbound page look exactly as before, and a request
  that sends additional inbounds anyway is refused. Turning it off while plans keep additional inbounds names those
  plans; they stay unsold until you remove the additional inbounds (switch on, edit the plan).
- **Plan form** (web panel, PLANS_MANAGE): "Additional inbounds" lists the active inbounds of the primary inbound's panel,
  without the primary. A save is refused when an inbound is on another panel, is the primary, is newly chosen but
  inactive, the panel is not in modern mode (forced, or auto with modern detected), the plan has custom traffic or
  duration (those stay single-inbound), there are more than 20, or the protocols break the rules below. The Mini App and
  the bot have no field yet (M3); a change they make (e.g. moving the primary to another panel) is checked the same way.
- **Protocol rules (D1):** VLESS, VMess and Trojan together; Shadowsocks only when every Shadowsocks inbound of the plan
  uses the same method; a VLESS primary with the vision flow only with flow-capable VLESS inbounds (TCP with
  TLS/REALITY). The method and flow capability are read from the panel when these rules apply: a panel that does not
  answer refuses the save.
- **Warnings** after saving: not sold yet; an inbound that became inactive since it was chosen; the panel not in modern
  mode; several protocols together (run the real-panel checklist of the design, 7.3, first).
- **Plan list:** an "Inbounds" column (count, "not sold yet", inactive ones). **Panel inbounds page:** "Used by plans",
  primary or additional.
- **Deleting** an inbound that a plan uses as an additional inbound, or a panel with such an inbound, is refused and the
  plans are named. Deleting a plan's primary inbound works as before (the plan then has no inbound).
- Trial plans keep one inbound.

**Upgrading.** Run the migrations: `Version20261101090000` adds the `plan_additional_inbound` and `vpn_service_inbound`
tables (the second stays empty until M2). Nothing existing is changed and nothing is backfilled. Its `down()` refuses
and changes nothing while either table has a row: remove the additional inbounds from every plan first.

## Sales Controls

Use `Settings -> Sales` to enable or disable new service sales, renewals, and extra traffic purchases independently. Closing sales does not block existing waiting-payment orders, service viewing, config delivery, or approval of already-submitted payments.

## Language

Use the admin language switcher for `fa` and `en`. Persian UI is RTL and English UI is LTR.
