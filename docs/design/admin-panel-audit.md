# Admin panel audit

Status: **audit for review**. Nothing here is built. Base commit: `b485bfb` (tip of `develop/v2`, 2026-11-06).

Scope: the web panel at `/admin` (EasyAdmin 5.6.1). The Mini App admin section is out of scope. The admin area of the
Telegram bot (`src/Bot/Application/Admin`) is mentioned only where it shapes the web panel.

Phase names use the prefix **PM**, continuing `docs/design/product-modules.md`.

**The short version.** The panel is better built than it feels. The confusion is not mainly a technology problem:

- Translation is close to complete, roles are enforced server-side, and new pages are closed by default.
- The problems are **information architecture** (where things live), **workflow** (long jobs spread over pages, a timer
  and a CLI), and **language for a non-technical manager** ("inbound", "regenerate", "apply").
- Audit also found real defects, listed in 2.6. They should be fixed before any redesign, whatever is decided.

Counts come from `grep` and a small script, not a full parse. Nothing was clicked through: findings describe the code.
A finding that depends on runtime behaviour says so.

## 1. Inventory

### 1.1 Menu

Source: `src/Admin/UI/DashboardController.php:109-160`. 18 entries in 5 groups, plus dashboard and logout.

| Group | Entry | Page | What it is for |
|---|---|---|---|
| | Dashboard | `DashboardController`, `Dashboard/DashboardOverview.php` | Attention queue, today, services, panels, wallets, shortcuts; per role |
| Sales | Orders | `Crud/OrderCrudController` | Order list; no delete. Reaches order drafts |
| Sales | Payments | `Crud/PaymentCrudController`, `PaymentModerationController` | Tabs by status; confirm, retry, reject, reject as refunded |
| Sales | Discount codes | `Crud/DiscountCodeCrudController` | Codes. Reaches usages |
| Catalog | Plans | `Crud/PlanCrudController` | Plans. Reaches trial plans, trial claims, bulk price, inbound apply |
| Catalog | Apps | `Crud/AppDownloadCrudController`, `Crud/ClientAppCrudController` | Two tabs: installer files, client app deep links |
| Customers | Customers | `Crud/UserCrudController` | Search, profile. Reaches wallet |
| Customers | VPN services | `Crud/VpnServiceCrudController`, `ServiceInboundsController`, `SubscriptionPageLinkController` | Tabs by state; regenerate, rotate page link, retry attach, claims |
| Customers | Wallets | `WalletAdminController` | Balances, ledger, adjust, credit limit, freeze |
| Customers | Resellers | `ResellerAdminController` | Profiles, wholesale rules, sold orders, refund, report |
| Customers | Claims | `ClaimAdminController` | Claim links, revoke, return, ownership history |
| Messaging | Broadcasts | `Crud/BroadcastMessageCrudController`, `BroadcastActionsController` | Create, schedule, duplicate, retry failed |
| Messaging | Bot texts | `Crud/BotMessageTemplateCrudController`, `Crud/BotButtonLabelCrudController`, `GuideTextAdminController` | Two tabs, plus Mini App guide texts with live preview |
| System | Payment gateways | `Crud/PaymentGatewayCrudController` (639 lines) and three typed CRUDs | Install, configure, store payment methods |
| System | VPN panels | `Crud/VpnPanelCrudController`, `PanelSetupController`, `VpnPanelInboundActionsController` | Setup wizard, test, sync inbounds |
| System | Settings | `SettingsAdminController` | 8 tabs, see 1.4 |
| System | Plugins | `Crud/PluginCrudController` | Upload, enable, disable |
| System | Admin users | `Crud/AdminUserCrudController` | Admins, roles, Telegram link |
| System | Logs | `Crud/AdminAuditLogCrudController`, `ServiceNotificationLogCrudController`, `BotMessageLogCrudController` | Three tabs |

### 1.2 Pages not in the menu

Deliberate, per the comment at `DashboardController.php:102-107`.

| Page | Reached from |
|---|---|
| Order drafts | Orders, global action |
| Discount usages | Discount codes, global action |
| Trial plans, trial claims, bulk price | Plans, global actions |
| Apply inbounds, inbound-move run | Plan edit and plan detail |
| Broadcast deliveries | A broadcast |
| Telegram accounts | Customers |
| Store payment methods | Payment gateways |
| Inbounds | Their panel |
| Required channels, raw settings | Settings tabs |
| Gateway install, schema config | Payment gateways |
| Panel setup steps | VPN panels |

### 1.3 Controllers and custom actions

31 CRUD controllers in `src/Admin/UI/Crud`, 16 non-CRUD controllers in `src/Admin/UI`, about 73 custom route attributes, 32 custom
actions, 10 legacy redirects (`src/Admin/Routing/LegacyAdminRoutes.php`).

Custom actions by CRUD:

| CRUD | Actions |
|---|---|
| `PaymentCrudController:142-161` | confirm, retry provisioning, reject, reject as refunded |
| `VpnServiceCrudController:131-151` | regenerate config, rotate page link, claims history, retry attach |
| `VpnPanelCrudController:104-115` | test connection, sync inbounds, view inbounds |
| `VpnInboundCrudController:72-88` | test create client, resync, sync access metadata, regenerate service configs |
| `PlanCrudController:139-148` | trial plans, trial claims, bulk prices, apply inbounds |
| `PaymentGatewayCrudController:114-124` | install, edit config, add store method |
| `PluginCrudController:62-70` | upload, enable, disable |
| `BroadcastMessageCrudController:82-87` | duplicate, retry failed |
| Others | discount usages, order drafts, customer wallet, guide texts |

Custom Twig pages, 51 templates under `templates/admin`: 19 extend `@EasyAdmin/page/content.html.twig`, 4 extend
`admin/custom_page.html.twig`, the others are partials, CRUD overrides or `admin/base.html.twig`.

### 1.4 Setting groups

`SettingsAdminController`, tabs in `AdminTabs::SETTINGS`, forms in `src/Admin/Application/SettingsAdministration.php`.

| Tab | Holds |
|---|---|
| Sales | Sales switches, closed message, renewal rules, global discount, traffic add-on |
| Automation | Sync, expiry, notify, auto suspend, batch limit, incomplete-order hours |
| Wallet | Switches, top-up range, quick amounts |
| Resellers | Switch, floor, rounding, claims, claim TTL |
| Plans | Multi-inbound switch, attach retries, inbound-move switch, grace, batch, pause |
| Branding | Name, logo, accent colour, support link |
| Channels | Required channels list |
| Advanced | Raw key/value list behind a warning |

Settings not on the web: env only (`.env.prod.example`), including `BRAND_PUBLIC_BASE_URL`, `PAYMENT_CARD_*`,
`SERVICE_NOTIFY_*` and `TELEGRAM_ADMIN_CHAT_ID`.

### 1.5 Command-only features

75 files in `src/Command`. Most are debugging tools, which is fine. These matter to an operator and have no complete
web page:

| Command | What is missing on the web |
|---|---|
| `app:admin:create-user` | First admin only; acceptable |
| `app:payments:repair-stuck` | Finding and repairing confirmed payments that were never fulfilled |
| `app:payments:winapay-reconcile` | Gateway reconciliation |
| `app:wallet:reconcile` | Ledger against balances; only `post-deploy-check` surfaces it |
| `app:plan:apply-inbounds --retire-now`, `--keep-old`, `--grace-hours` | "remove now"; the page says so (`admin.en.yaml:1554`) |
| `app:inbound:regenerate-service-configs` | Web stops at 10 services (`WEB_REGENERATE_MAX_SERVICES`) and prints a command to run |
| `app:services:attach-inbounds` | Bulk attach |
| `app:service:verify-links` | Link check |
| `app:panel:doctor`, `detect-version`, `test-client-links` | Deep panel diagnosis beyond "test connection" |
| `app:trial-plans:backing-audit` | Trial backing audit |
| `app:plugin:doctor`, `validate-package` | Plugin diagnosis |
| `app:payment:repair-plugin-gateway` | Gateway repair |
| `app:telegram:set-webhook`, `webhook-info`, `set-menu-button` | Bot connection status has no web page |
| `app:bot-content:missing`, `seed` | Texts missing or out of date |
| `app:sales:status` | Why the shop is closed; partly on the settings page |
| `amoobot-check-jobs` (root) | **Timer health has no web page.** An admin who starts an inbound move can't see whether the job runs |

## 2. Problems

### 2.1 Navigation and grouping

- **Grouped by data model, not by task.** A manager thinks "something is wrong with a sale". Payment is in Sales, the
  gateway that took it in System, its order's service in Customers, the panel behind it in System, the plan in Catalog.
- **Money settings are in three places.** Gateways in System, top-up limits in Settings → Wallet, wallets in Customers.
- **VPN services sit under Customers** although they are the product sold.
- **Plans hold four unrelated things.** Trial plans, trial claims, bulk price and inbound moves are global actions or
  buttons that only appear after you open a list.
- **Hidden pages are by design but not discoverable.** Store payment methods decide what customers can pay with, and
  they're behind Payment gateways. Required channels affect every customer and are a Settings tab, whose row edits leave
  Settings for CRUD pages.
- **No home for "operations".** Panels, plugins, logs and admin users are one list under System. There is no health view.

### 2.2 Naming

- **Technical words shown as if they were business words:** inbound, panel, claim, attach, regenerate, resync, sync access
  metadata, "test create client".
- **Near-duplicates.** "Payment gateway" and "store payment method" need the docs to tell apart. "Plans" settings tab means
  inbounds, not plans. "Sales" settings tab holds traffic pricing. "Apps" means VPN client apps, and could mean anything
  for a server store.
- **Product-specific names in core places:** "VPN services" in a menu that must serve server stores.

### 2.3 Mixed languages and hardcoded texts

See section 3 for counts. Examples of what leaks:

- `templates/admin/bulk_plan_price_adjustment.html.twig`: Persian hardcoded in the template (14 lines), and options shown
  as raw field names: `price`, `pricePerGb`, `pricePerDay`, `all`. The English UI shows Persian; the Persian UI shows
  English identifiers. Both are wrong.
- **English sentences reach the Persian UI through data.** `VpnProvisioningService::SWITCHED_OFF` and `NOT_MODERN` (`:31-33`)
  are stored as attachment errors, `Payment::NOTE_ORDER_EXPIRY` and `NOTE_ORDER_FAILED` as admin notes,
  and `SettlementResult` messages ("Order #%d is already fulfilled...") are English.
- **Exception text in flashes:** `flash.error` with `%error%` shows `$e->getMessage()` (for example
  `VpnPanelInboundActionsController.php:66`), English or a raw panel error.
- `'Amoobot Admin'` (`DashboardController.php:86`, `templates/admin/base.html.twig`, `custom_page.html.twig`), the product
  name, is not the brand name and is not translated.
- The broadcast preview in `BroadcastMessageCrudController.php:268` is an inline HTML string with `lang="fa"`.
- Settings → Advanced shows raw keys. Reasonable for an expert tab, but it's the only way to see some values.

### 2.4 Missing help

- There is **field-level** help: 63 `setHelp()` calls and 53 `help.*` keys.
- There is no **page-level** help: nothing says what a page is for, when to use it, or what happens next.
- There is no link from a page to the guide. `docs/fa/admin-guide.md` exists but the panel doesn't point to it.
- There are no empty states that say what to do first. A new install has no "connect a panel → create a plan → add a
  payment method → test a purchase" checklist, though `PanelSetupController` shows how well a guided flow works here.

### 2.5 Multi-step workflows that are hidden or split

**Changing a plan's inbound (the worst case).** Fact, from `PlanCrudController.php:148`, `InboundMoveAdminController.php`,
`deploy.sh:92-94,104`:

1. Settings → Plans: the switch `plans.inbound_move_enabled` must be on. Nothing on the plan form tells you first.
2. Plans → edit a plan → change the inbound → save. New sales change at once; **existing services don't**.
3. The "Apply to existing services" button appears only on the edit page.
4. Dry run, then confirmation (`?step=confirm`), then "create run". The run is only `planned`.
5. Wait for the `inbound-moves` timer (`*:21/30`, up to 30 minutes). It's in the `lifecycle` group, so it may be skipped
   once when busy. The page can't tell you the timer is healthy.
6. Progress is on the plan's detail page, not the edit page you came from, nor in any jobs list.
7. Failures show up in Services filters and alerts. "Remove now" exists only on the CLI.

**Adding a payment method.** Gateways → install (a type page) → schema form → success page → "add store method" → a
different page to order and toggle. Plugin gateways add a Plugins step first.

**A multi-inbound plan.** Settings → Plans switch, then panel in modern mode (a separate wizard's test), then plan form.
The rule is enforced (`PlanSaleRule`), but it reads to the admin as a plan that quietly isn't sold.

**Changing what a customer has.** Web: extend, add traffic, suspend and activate are done in the bot and Mini App
(`ServiceAdministration`). On the web a service is edited as raw fields (`SERVICES_MANAGE`). See 2.6.

**Fixing a stuck payment.** Payments tab shows `provisioning_failed` and offers retry. The cause is often a panel. The admin
has to walk to System → VPN panels and back. Repair of older stuck states is CLI only.

**Sending a broadcast.** `preview()`, `prepareRecipients()`, `sendNow()` and `cancelBroadcast()` exist in
`BroadcastMessageCrudController.php:261-325`, and the README describes them, but `configureActions()` registers only
duplicate and retry. No template links them, and they have no `#[AdminAction]` route. From reading, the web panel can
save and schedule, and has no "send now" or "preview". **Needs a click-through to confirm.**

### 2.6 Dangerous actions, and defects found

**State-changing GET requests with no CSRF token (fact).** The session cookie is `SameSite=Lax`, which allows cross-site
top-level GET navigation, so a link in an admin's chat can trigger these. All need admin permissions.

| Route | Effect | Location |
|---|---|---|
| `admin_vpn_inbound_test_create_client` | **Creates a real client on the production panel**, 1 day, 1 GB. Name `test_amoobot_<time>`. Nothing removes it | `VpnPanelInboundActionsController.php:86` |
| `admin_vpn_panel_sync_inbounds` | Sync | `:55` |
| `admin_vpn_inbound_resync`, `sync_access_metadata` | Sync | `:118,131` |
| `admin_vpn_service_regenerate_config` | Rewrites a service's links | `:265` |
| `admin_plugins_enable`, `admin_plugins_disable` | Enables or disables code | `PluginCrudController.php:185,195` |

`InboundMoveAdminController` states the correct rule in its own header: "No GET changes anything".

**POST with no CSRF token (fact).** `admin_bulk_plan_price_adjustment` changes all prices, and neither the template nor the
controller has a token. The page's `preview` mode helps a human, but it doesn't help against CSRF.

**Web delete of a service bypasses the panel (from reading, confirm by test).** `VpnServiceCrudController` has no
`deleteEntity()` and doesn't disable `Action::DELETE`; `RoleAccessWebTest.php:133` shows the POST is reachable with
`SERVICES_DELETE`. So EasyAdmin removes the database row with no panel call. The bot and Mini App go through
`ServiceAdministration::delete()` (`:75`), which deletes the client on the panel first. The likely result: a client that
keeps working on the panel, with no record. Foreign keys may refuse some deletes with a raw error.

**Raw edit of a service (fact).** The same CRUD's form exposes `user` (the owner), `order`, `panel`, `inbound`, `status`,
`expiresAt`, `remoteId`, `clientUuid`, `subId`, the subscription URL, the config text and the traffic counters
(`VpnServiceCrudController.php:190-233`). Saving writes the row only. `ServiceAdministration` does suspend and delete on
the panel first, and `ServiceOwnership` records every change of owner; this form does neither. A status of `suspended`
set here leaves the client working, and a changed owner leaves no ownership event.

**One-click actions with serious effects and no confirmation.** Only one EasyAdmin action in `src/Admin` uses
`askConfirmation` (`VpnServiceCrudController.php:142`). No confirmation on:

- **Confirm payment** (provisions and may deliver)
- **Reject** (may fail the order and return wallet debits)
- **Regenerate service configs** on an inbound (several customers' links)
- **Retry provisioning**
- **Retry failed broadcast**
- **Enable plugin**

Good examples to copy: `templates/admin/wallet/confirm.html.twig` and `reseller/refund_confirm.html.twig` are explicit
confirm pages with the amount shown. The Mini App requires typing the service name to delete
(`MiniAppAdminServiceController.php:47`).

**Plans stay saved while customers are not moved.** Not a bug by design, but an easy mistake. A plan page should say
"N services are still on the old inbound" prominently. `PlanInboundDrift` already computes this.

**`admin_vpn_service_regenerate_config` needs `PANELS_MANAGE`.** See 4.2.

### 2.7 Pages that work only through the CLI

See 1.5. The worst three: job health, stuck-payment repair and "remove old inbound now".

### 2.8 Inconsistent styles

- **Three page shells:** EasyAdmin content pages, `admin/custom_page.html.twig` (a hand-built copy of the layout that
  must repeat the locale switcher and title), and `admin/base.html.twig`.
- **Two list idioms.** EasyAdmin indexes against hand-built tables (wallets, resellers, claims, bot texts).
- **Actions with different shapes:** buttons, links, forms, global actions, dropdown items.
- `public/assets/admin/admin.css` is 1212 lines of overrides (14 `!important`). `public/admin.css` is a stub.
- The font loads from `cdn.jsdelivr.net` (`admin.css:2`). If that host is blocked or slow, the panel falls back or waits.
  The Mini App ships its fonts locally (`public/miniapp/fonts`).

## 3. Current i18n state

**Facts:**

| Measure | Value |
|---|---|
| `admin` domain keys | **1617 in fa, 1617 in en**, none missing either way |
| `\|trans` calls in admin templates | 601 across 51 templates |
| Text outside `trans` in admin templates (heuristic) | 36 lines, of which 23 in `bulk_plan_price_adjustment.html.twig`; most others are `فارسی` / `English` language names |
| Persian literals in `src/Admin` PHP, non-comment | 4, all separators or the language name |
| CRUD labels as literal English | 0 found; they're translation keys (`AdminTranslationIdGenerator`) |
| Flashes with literal English | 0 found |
| fa values with no Persian letters | 27 (URLs, tokens and acronyms mixed with real misses; not reviewed one by one) |
| fa = en identical | 25 |
| Other domains | miniapp 947, bot 269, validators 61, subscription 59, messages 9; complete in both locales |

`tests/Admin/TranslationCompletenessTest.php` guards this.

So **translatable: about 98-99% of admin strings by this measure.** The remaining problem is English from data and
exceptions (2.3), which a key count doesn't show. A similar audit of those stored English sentences is needed.

Outside the panel, 654 lines in `src/Bot` hold Persian literals. They aren't admin strings, but `TelegramUpdateHandler`
sends some directly (for example `:675`), so "the bot is translated" isn't true, and the English bot is incomplete.

**RTL:**

- EasyAdmin sets `rtl` automatically for `ar`, `fa`, `he` (`AdminContextFactory.php:186` in the bundle) and writes
  `dir` on `<html>`. `Dashboard::setTextDirection()` isn't needed here.
- Default locale comes from `APP_DEFAULT_LOCALE`; `AdminLocaleSubscriber` keeps it per session.
- Custom shells compute `isRtl` by hand (`templates/admin/base.html.twig:6`, `custom_page.html.twig:6`). 67 RTL or `[dir=`
  rules in `admin.css`.
- LTR islands are handled with care: `dir="ltr"` on URLs, keys, ports, `<bdi>`. A good pattern worth keeping.
- **Calendar:** dates are Gregorian `yyyy-MM-dd` (`DashboardController.php:79`) in both locales. A Persian-first panel
  probably needs Jalali display. No Jalali code was found in the admin.
- Digits: `AdminValueConfigurator` handles date formatting. The rule for Persian digits isn't written down.

## 4. Current access control

### 4.1 What exists

Better than the owner may expect:

- **Roles** (`src/Entity/AdminUser.php:25-32`): `ROLE_SUPER_ADMIN`, `ROLE_ADMIN`, `ROLE_SUPPORT`.
- **25 permissions** (`src/Security/AdminPermission.php`) and one matrix (`AdminRolePermissions::forRole()`), used by web, bot
  and Mini App through `AdminAccessPolicy`.
- **Voter:** `AdminPermissionVoter`, `is_granted('PERM_...')`. The subject is ignored.
- **Page gate:** `AdminPermissionListener` checks `AdminCrudPermissions` for every `App\Admin\` controller. A controller
  missing from the map is **closed**: a fail-closed design.
- **Action gate:** `AdminCrudActionPermissionSubscriber` for new, edit, delete.
- **Menu:** entries need the same permission as their page, so menu and server can't disagree.
- **Audit:** `AdminAuditLogger`, `AdminAuditEntitySubscriber`.
- `getRoles()` always adds `ROLE_ADMIN` for the `/admin` rule, and permissions come from `getAssignedRoles()`. So a
  support-only admin does get in, with support rights. **`README.md`'s roles section is out of date** on this point.
- `tests/Admin/RoleAccessWebTest.php` tests the matrix.

Current matrix: `ROLE_SUPER_ADMIN` has everything. `ROLE_ADMIN` has everything except gateways, panels, plugins, settings,
admins and logs. `ROLE_SUPPORT` has read-only orders, payments, customers, services, wallets and resellers, plus
`SERVICES_ASSIST`.

### 4.2 Gaps

- **`SERVICES_ASSIST` isn't used on the web.** It works in the bot and Mini App only. The web's "regenerate config" needs
  `PANELS_MANAGE` (`VpnServiceCrudController.php:169`), because of which controller holds the route. So web support staff
  can't do the one thing the permission is for.
- **Permissions are per area, not per object.** No "only this store", no "only these fields".
- **`PLANS_MANAGE` is too wide.** It covers plan text, bulk price changes and moving every customer of a plan.
- **`ROLE_ADMIN` includes `WALLETS_MANAGE`.** Manual ledger adjustments should be a deliberate grant.
- **No masking.** Roles that can read a service can read access links on detail pages. Support may need to see a link
  exists without seeing it. `BotMessageLog` holds customer messages.
- **No limits.** A manual wallet adjustment or discount has no amount ceiling per role, nor four-eyes approval.
- **Roles are hard-coded**, so a new role is a code change. That's fine for a few roles; a role editor isn't needed yet.

### 4.3 What new roles need

| Capability | Super admin | Sales manager | Support |
|---|---|---|---|
| Dashboard, orders, customers | all | all | read |
| Approve or reject payments | yes | yes | no |
| Retry provisioning | yes | yes | no |
| Plans: text, prices | yes | yes | no |
| Bulk price, plan infrastructure | yes | no | no |
| Discounts, broadcasts | yes | yes | no |
| Resellers | yes | manage | read |
| Wallet adjustment | yes | **open question** | no |
| Service: resend, refresh | yes | yes | yes |
| Service: extend, suspend | yes | yes | **open question** |
| Service: delete | yes | no | no |
| Bot texts | yes | yes | no |
| Gateways, modules, panels, plugins, settings, admins, logs | yes | no | no |

Work needed: add `ROLE_SALES_MANAGER`; make permission text product neutral; split `PLANS_MANAGE` into `PLANS_MANAGE`
and `PLANS_INFRA_MANAGE`; route regenerate through `SERVICES_ASSIST`; add `CUSTOMER_SECRETS_VIEW`. Later, under the
multi-store option only: add a subject to the voter and an `admin_user_store` table.

Estimate: 2-3 small PRs. The foundations are sound.

## 5. UI technology options

### Facts about this codebase

- EasyAdmin 5.6.1, Symfony 7.4, PHP 8.2+.
- `symfony/ux-twig-component` is installed (required by EasyAdmin). **Stimulus, Turbo and AssetMapper are not.** No
  `package.json`, no `importmap.php`, no `assets/`. `public/assets/admin/admin.js` is 88 lines with no dependencies.
- `deploy.sh` has no Node step. Deployment is `composer install` only.
- EasyAdmin's layout sets `data-turbo="false"`.
- Session and CSRF are server-side forms. `AdminCrudPermissions` guards by controller class.
- 24 test files in `tests/Admin`, all server-rendered crawler tests.
- 16 custom controllers and 51 templates already follow a working pattern.
- 20 Mini App admin controllers exist (`src/MiniApp/UI/Admin`), with `AdminRoutePermissions`. They render HTML fragments for
  the Mini App; they are not a general JSON API. Their domain services are shared and reusable.

### (i) EasyAdmin restructured, custom Twig pages, small Stimulus use

Keep EasyAdmin for lists and simple forms. Build task pages in Twig for workflows.

| | |
|---|---|
| **Effort (estimate)** | 8-12 PRs, mostly small |
| **Rebuild** | Menu, dashboard, page shell, workflow pages, confirmation component. Auth, CSRF and permissions stay |
| **API layer** | None |
| **Tests** | Existing tests stay valid, updated where URLs move. New ones follow `tests/Admin` |
| **Maintenance** | Low; one stack, one language. EasyAdmin major upgrades are the known cost |
| **Risks** | EasyAdmin's defaults limit layout; hard pages need custom templates. Keep custom pages on one shared shell. Too many overrides of EasyAdmin internals would make upgrades painful |

Interactivity (live run progress, plan-schema forms, confirm dialogs) needs little JS. Stimulus via AssetMapper is enough
and adds no Node. Turbo is optional and limited to custom pages, since EasyAdmin itself opts out.

### (ii) React admin on a new admin API

| | |
|---|---|
| **Effort (estimate)** | 25-40 PRs. Practically a second product |
| **Rebuild** | About 47 controllers' worth of screens; forms, validation display, tables, filters, tabs, CSV export, file upload, RTL, locale switch, translations pipeline, plan-schema rendering |
| **Auth** | New: session cookie plus CSRF for the API, or tokens. Login throttling, `remember_me` and `AdminUserChecker` have to be re-wired |
| **CSRF** | Every write needs a new pattern; today's per-action tokens don't carry over |
| **API layer** | New, 100+ endpoints with DTOs, serialization, permission on each. Risk of leaking fields the Twig pages carefully avoid (links, tokens) |
| **Tests** | New API tests plus frontend tests. The 24 admin files lose their value. Frontend tooling new to CI |
| **Deploy** | Node build step, or built assets committed. `deploy.sh` changes; supply-chain surface grows |
| **Maintenance** | High: two stacks, JS dependency updates, and every feature built twice |
| **Risks** | A long parallel period with two admins. The audit-logging and permission guarantees must be re-proved. Stalls feature work during the server launch |

Gains: smoother interactions, free layout. Those matter most for a product with many daily users. This panel has a handful.

### Recommendation (opinion)

**Option (i).** The owner's complaints are about structure and words, and a rewrite would carry the same structure across
unless the structure is fixed first. Option (i) fixes those directly, keeps the fail-closed permission model and the test
suite, and doesn't delay the server launch.

Reconsider (ii) only if a public or partner-facing admin appears with many users, or if a real prototype of the worst
workflows shows that (i) can't deliver them.

## 6. Proposed information architecture

Principles:

- By task, not by table.
- The product's own words: "Services" for any product, with a module-specific label if needed ("سرورها", "اکانت‌ها").
- Module pages appear only when the module is on.
- Technical tools go under one "Operations" area, not on the manager's path.
- Every long job appears in one "Jobs" page.

Works for both options of `product-modules.md` section 5: under multi-install each install has this menu. Under
multi-store a store switcher sits above it, and "Stores" is added under Operations.

```text
خانه / Home
    داشبورد

فروش / Sales
    سفارش‌ها                     Orders (+ incomplete orders tab)
    پرداخت‌ها                    Payments (tabs by status)
    کدهای تخفیف                  Discounts (+ usage on each code)

مشتریان / Customers
    مشتریان                      Customers (profile: services, orders, payments, wallet, Telegram)
    کیف پول‌ها                   Wallets
    نمایندگان                    Resellers (+ report)                  [module: reseller]

سرویس‌ها / Services
    سرویس‌ها                     Services (any product; columns from the module)
    انتقال سرویس                 Claims                                [vpn]
    درخواست‌های مشکل‌دار          Needs attention (failed provision, inbound, server errors)

محصولات / Products
    پلن‌ها                       Plans (form from planSchema)
    پلن‌های تست                  Trial plans (+ claims)                [vpn]
    تغییر گروهی قیمت             Bulk prices

ارتباط با مشتری / Communication
    پیام همگانی                  Broadcasts
    متن‌های ربات                 Bot texts (+ buttons)
    راهنما                       Guide texts                           [vpn]
    اپ‌ها و دانلودها             Apps                                  [vpn]
    کانال‌های اجباری             Required channels

مالی / Payments setup
    روش‌های پرداخت               Payment methods (what customers see)
    درگاه‌ها                     Gateways (+ install)
    تنظیمات کیف پول              Wallet settings
    تنظیمات فروش                 Sales settings

زیرساخت / Infrastructure                                              [per module]
    پنل‌های VPN                  Panels, inbounds                      [vpn]
    انتقال سرویس‌ها به اینباند    Move runs                             [vpn]
    حساب‌های ابری                Cloud accounts                        [hetzner]
    مدیریت سو استفاده            Abuse                                 [hetzner]

سیستم / Operations
    کارهای زمان‌بندی‌شده          Jobs (health and runs)
    برند و دامنه                 Branding
    اتصال ربات                   Bot connection (webhook status)
    ماژول‌ها                     Modules (read only)
    افزونه‌ها                    Plugins
    مدیران                       Admins and roles
    گزارش‌ها                     Logs
    تنظیمات پیشرفته              Advanced settings
```

### Dashboard

For a store manager. Sections are shown by permission and module, as today (`DashboardOverview`).

| Block | VPN store | Server store |
|---|---|---|
| **Needs action now** (top, one list) | Receipts, review, failed provisioning, inbounds not connected, failed moves | Receipts, review, failed provisioning, creation failed, deletes awaiting approval, abuse flags |
| **Today** | Orders, confirmed payments, revenue, top-ups, new customers | Same |
| **Services** | Active, expiring, expired, suspended, low traffic | Active, expiring, in grace, powered off, deletions due |
| **Money** | Wallet outstanding, credit used | Same, plus cost estimate of running servers, cost of servers in grace |
| **Infrastructure** | Panel health, last check | Cloud account status, API limit remaining, resource limit used |
| **System** | Job health, last backup, deploy version | Same |
| **Setup checklist** (new install) | Connect a panel, create a plan, add a payment method, set the bot texts, test a purchase | Connect a cloud account, create a plan, add a payment method, set the bot texts, test a purchase |

"Needs action now" is the most valuable block. It exists in part (`DashboardOverview::attention()`), and it should become
the single worklist. System health belongs here too: today it needs SSH.

### The workflow pattern

Each long job follows one pattern:

1. A **wizard page** with what will happen, in business words.
2. A **dry run** with counts.
3. An **explicit confirmation** showing the amount or the number of customers affected.
4. A **job page** with progress, pause, undo, and a health line ("next run in 12 minutes").
5. A **plain-language result**, linked from the dashboard.

Inbound moves already have 2, 3 and most of 4. Bulk prices have 2. The wallet has 3. The pattern exists in pieces.

## 7. Suggested order

| Phase | Content | Risk |
|---|---|---|
| **PM12** | Defects: CSRF and POST on the routes in 2.6, web service delete, confirmations on payment actions, regenerate permission | Low; do it now, independent of everything else |
| **PM13** | Language: translate stored English messages, bulk-price page, `README` roles section | Low |
| **PM14** | Menu restructure with redirects (`LegacyAdminRoutes` already provides the pattern), renamed labels, page-level help | Low |
| **PM15** | Jobs page and bot connection page; surface `check-jobs` data to the web through a state file or `/healthz` extension | Medium |
| **PM16** | Dashboard worklist and setup checklist | Low |
| **PM17** | Workflow pages, one at a time: inbound moves, payment methods, stuck payments | Medium |
| **PM18** | Roles: sales manager, permission split, masking | Medium |
| **PM19** | Jalali dates and local fonts | Low |

PM12 shouldn't wait for any decision. PM14 onward should wait for the module decision (`product-modules.md` PM3) only where
they rely on module gating.

## 8. Open questions

1. Which pages confuse the owner most, in order? This audit inferred that from code, so a 30-minute walkthrough would
   correct the priorities.
2. Who will actually use the panel in the next year? How many people, on phone or desktop?
3. Are Persian labels in section 6 the owner's words? They are proposals.
4. Jalali dates, Gregorian dates, or both? Persian digits in tables?
5. Should English be full parity, or a developer fallback?
6. Sales manager: may they adjust wallets? With a ceiling?
7. Support: may they extend or suspend a service, or only assist?
8. Should support see access links, or only resend them?
9. Should approvals need a second person above some amount?
10. Should the brand name replace "Amoobot Admin" in the panel?
11. Must the admin work from Iranian networks without the CDN? This decides local fonts.
12. Is `ADMIN_ALLOW_IPS` in use? If so, CSRF exposure is lower, though PM12 is still needed.
13. Is the web "send now" for broadcasts really missing (2.5), or is it reached another way? Needs a click-through.
14. Should a web service delete exist at all, or should deletion go only through `ServiceAdministration`?
15. Should the raw edit form for services stay? Which fields?
16. Which CLI tools should become pages, and which should stay expert tools?
17. Should job and backup health be visible to all admins, or only the super admin?
18. Is the admin bot's area to be kept in step with the web panel, or left as a small on-the-go tool? There are three admin
    surfaces today (web, bot, Mini App).
