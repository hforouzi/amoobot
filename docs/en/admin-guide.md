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

## Sales Controls

Use `Settings -> Sales` to enable or disable new service sales, renewals, and extra traffic purchases independently. Closing sales does not block existing waiting-payment orders, service viewing, config delivery, or approval of already-submitted payments.

## Language

Use the admin language switcher for `fa` and `en`. Persian UI is RTL and English UI is LTR.
