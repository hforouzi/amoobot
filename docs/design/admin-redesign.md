# Admin panel redesign

Status: **design, owner decisions applied (2026-11-22, section 8)**. Nothing here is built. Base commit: `2ab34b3` (tip
of `develop/v3`, 2026-11-22).

Builds on `docs/design/admin-panel-audit.md` (the audit: inventory 1.1-1.5, IA in 6, dashboard, workflow pattern) and its
decision: **EasyAdmin + Twig + a small amount of Stimulus, no React** (audit 5, option (i)). This document turns the
owner-approved mockup into a page map, a rollout, the missing service actions, the dashboard data and a PR plan.

The redesign's PRs are numbered **AD1, AD2, …** (section 7). They replace the audit's unbuilt PM13-PM19, whose numbers
are not reused here; PM20 and PM21 are the Marzban and PasarGuard drivers (`product-modules.md`). See 7.0.

**The short version.**

- The new layout (7-item sidebar, dashboard, services page with a modal, settings hub) is built **beside** today's
  layout, behind one switch, **off by default**. The same routes, permissions, CSRF tokens, confirm pages and audit log
  serve both. The owner flips it once when every page is done; flipping back is one setting (or one env value).
- Nothing is removed. Every current page, controller and action has a place (section 2); the ones without a natural
  home stay where they are and are reached from the Settings hub.
- **One real defect blocks the mockup's service modal as written:** a web "extend" or "add traffic" can't truthfully say
  "applied on the 3x-ui panel too", because `ServiceAdministration::extend()` and `addTraffic()` write the database
  only, and the next usage sync puts the panel's values back (3.2). This also affects the bot and the Mini App today.
  The owner decided the panel is updated first (decision 1); that fix is a separate branch,
  `fix/admin-extend-traffic-on-panel`, outside this series. **Web extend/add traffic (AD5) requires
  fix/admin-extend-traffic-on-panel merged.**

## 0. What the audit says, checked against the code at `2ab34b3`

The audit was written at `b485bfb` (`develop/v2`). Since then PM12 (`9c1a0cb`, PR #116), PM1c (`fdff2d1`) and PM1d
(`9a59ec9`, PR #142) were merged. Claims this design relies on, re-checked:

| Audit claim | Now | Where |
|---|---|---|
| Menu: 18 entries in 5 groups, `DashboardController.php:109-160` | **Still true.** `configureMenuItems()` starts at 109, ends at 161 | `src/Admin/UI/DashboardController.php:109-161` |
| Pages off the menu are deliberate | True; the comment moved to 101-107 | `DashboardController.php:101-107` |
| `'Amoobot Admin'` title | True | `DashboardController.php:86` |
| Gregorian `yyyy-MM-dd` dates | True | `DashboardController.php:79-80` |
| `DashboardOverview::attention()` = payment tabs | True: `ATTENTION = ['needs_review', 'provisioning_failed', 'pending']` | `src/Admin/UI/Dashboard/DashboardOverview.php:34,79-91` |
| 31 CRUD controllers, 16 non-CRUD | 31 CRUD files in `src/Admin/UI/Crud`; 18 PHP files in `src/Admin/UI` of which 2 are traits (`ConfirmPages`, `TranslatedFlashes`) and 16 controllers (`ServiceDeleteController` is new, added by PM12) | `ls src/Admin/UI` |
| Custom action line numbers in 1.3 | **Shifted.** `PaymentCrudController:138-165`, `VpnServiceCrudController:132-185` | as listed |
| State-changing GETs, no CSRF (2.6) | **Fixed by PM12**: GET shows a confirm page, POST with a token acts (`ConfirmPages` trait) | `src/Admin/UI/ConfirmPages.php:9-33`, `templates/admin/action_confirm.html.twig` |
| Web delete of a service bypasses the panel (2.6) | **Fixed by PM12**: EasyAdmin new/delete/batch delete are off; "Delete on panel" goes through the product module after the name is typed | `VpnServiceCrudController.php:170`, `ServiceDeleteController.php:33-62` |
| Raw edit of a service (2.6) | **Fixed by PM12**: every form field is disabled | `VpnServiceCrudController.php:219-222` |
| `regenerate config` needs `PANELS_MANAGE` (4.2) | **Fixed**: `SERVICES_ASSIST` on the web | `VpnServiceCrudController.php:183` |
| Broadcast "send now" / "preview" not wired (2.5) | **Still true**: `preview()`, `prepareRecipients()`, `sendNow()`, `cancelBroadcast()` exist, only duplicate and retry are actions | `BroadcastMessageCrudController.php:82-87,260-324` |
| No Stimulus, AssetMapper, `package.json`, `importmap.php`, `assets/` | **True.** `symfony/ux-twig-component` is installed through EasyAdmin; `admin.js` is 88 lines, `admin.css` 1212 | `composer.json`, `public/assets/admin/` |
| Font from `cdn.jsdelivr.net` | True; the Mini App ships `Vazirmatn-wght.woff2` locally | `public/assets/admin/admin.css:2`, `public/miniapp/fonts/`, `public/miniapp/app.css:21-25` |
| Web regenerate stops at 10 services | True | `VpnPanelInboundActionsController.php:47` |
| Extend, add traffic, reset usage, suspend, activate only in bot and Mini App | **True.** Suspend/activate/delete go through `ProductModuleResolver::forService()` (PM1c); extend/add traffic/reset usage still call `ServiceAdministration` directly (product-modules.md "Still on ServiceAdministration") | `ServiceManagementService.php:1230,1246,1265,1285,1318,1351`; `MiniAppAdminServiceController.php:161-230` |

New facts found while checking, used below:

- `custom_page.html.twig` loads EasyAdmin's CSS by its hashed file name (`bundles/easyadmin/app.459a1f73.css`,
  `templates/admin/custom_page.html.twig:12`). Any EasyAdmin upgrade breaks it silently. The new shell must not copy this.
- A customer's card receipt doesn't change the payment status: `ReceiptSubmission` sets `receiptFileId` or
  `trackingCode` on a `pending`/`submitted` payment (`src/Shop/Application/Checkout/ReceiptSubmission.php:102-117`).
  No code in `src/` was found setting the status `submitted` (grep; only reads and badges). So "receipts waiting" is a new query, not a tab count.
- "Paid but not delivered" already has a finder: `StuckPaymentFinder::find()` (confirmed, order not fulfilled, older than
  N minutes; `src/Payment/Application/Repair/StuckPaymentFinder.php:33-38`), used only by `app:payments:repair-stuck`.
- Job health is on disk: `bin/scheduled-job.sh:61-64` writes `/var/lib/amoobot/jobs/<job>.last-run` and touches
  `<job>.last-success`; `bin/check-scheduled-jobs.sh:25-28` reads them with the manifest
  `/var/lib/amoobot/scheduled-jobs.conf` and checks the newest nightly backup in `/var/backups/amoobot`.
- `/healthz` reports only database reachability (`src/Shared/UI/HealthController.php:25-36`). There is no app version in
  the code (no `VERSION` file), but `deploy.sh` already records the deployed commit as root in
  `/var/lib/amoobot/deployed-commit` and appends to `releases.log` (`deploy.sh:1895-1898`; corrects the first version
  of this document). That file is root's own record and, under `deploy.sh`'s `umask 027` (`deploy.sh:20`), is
  `root:root 0640`: PHP can't read it, and shouldn't (deploy trusts it, `deploy.sh:1879`).
- Scheduled jobs run as the web user: their systemd units have `User=${WEB_USER}` (`www-data`, `deploy.sh:40,403-404`),
  `/var/lib/amoobot/jobs` is `www-data`-owned `0755` (`deploy.sh:2335`) and the manifest is `root:root 0644`
  (`deploy.sh:2392`). PHP-FPM (also `www-data`) can therefore already read job state without any new right. The
  nightly backup runs as root through `amoobot-backup` (`deploy.sh:766-769`) with `umask 077` into a `0700` directory
  (`bin/backup-database.sh:18,39`): PHP can't see it, and shouldn't.
- Gateway credential failures are only sent as Telegram alerts, rate-limited, and not stored
  (`src/Payment/Application/PaymentGatewayCredentialAlert.php:12-20`).
- Broadcasts can already target chosen customers: `BroadcastMessage::TARGET_SELECTED_USERS` with `targetUserIds`
  (`src/Entity/BroadcastMessage.php:29,77`). The bulk "message owners" can prefill that.
- `endroid/qr-code` is installed (`composer.json:14`): a QR for "links & QR" needs no new dependency.
- Jalali formatting exists, outside the admin: `SubscriptionFormat` uses `IntlDateFormatter('fa_IR@calendar=persian')`
  (`src/Subscription/UI/SubscriptionFormat.php:28-36`).
- Messenger has no transport configured (`config/packages/messenger.yaml:6-14`, commented). Long work runs on systemd
  timers through `app:automation:run --only=<job>` (`deploy.sh:101-110`).

## 1. The approved mockup, as requirements

Summarised from the owner's approval. Labels are the owner's.

**Shell.** RTL Persian first, fa/en switch. Font Vazirmatn. Light theme with a dark toggle. One accent colour: the
brand colour from Branding (decision 15). White cards on `#F4F6F9`, radius 16px. Touch targets at least 44px. The brand
name replaces "Amoobot Admin" in the header and the browser tab (decision 9).

**Sidebar, exactly 7 items:**

| # | Label | Holds |
|---|---|---|
| 1 | داشبورد | Dashboard |
| 2 | فروش و پرداخت‌ها | Orders, payments, receipt review. **Red badge = card-to-card receipts waiting only** (decision 5) |
| 3 | مشتریان | Customers, wallets, resellers, claims |
| 4 | سرویس‌ها | Services |
| 5 | پلن‌ها و تخفیف | Plans, trials, discounts |
| 6 | پیام‌ها و متن ربات | Broadcasts, bot texts, guide, apps |
| 7 | تنظیمات | Settings hub |

Bottom of the sidebar: theme toggle, language toggle, admin name.

**Dashboard.** Header with global search and a chip "system healthy · version". Worklist "نیاز به اقدام شما" at the top:
receipts to review, paid-but-not-delivered orders, services expiring today, inbounds not attached; each row has one
button that does or opens the fix. Five KPI cards: sales today (% vs yesterday), confirmed payments + waiting, active
services + new today, expiring in 3 days, new customers. 7-day sales bar chart. Services-by-status bar with legend.
Latest payments table. System health: jobs, panels, gateways, last backup, bot webhook.

**Services.** Status tabs with counts: all, active, expiring, expired, suspended, needs attention. Search by id, name,
Telegram username. More filters: plan, panel, reseller. Table: checkbox, id, customer, plan, status chip, usage bar,
expiry, panel, "⋯". Selecting rows shows a dark bulk bar: extend days, add traffic, suspend, activate, message owners,
clear.

**Service modal** (over the list, not a new page). Header: id, customer, username, plan, status chip, close. Three
tiles: expiry, usage with bar, panel/servers. Action grid: extend days, add traffic, reset usage, suspend/activate,
resend link to customer, links & QR. Every action opens an inline confirm that says exactly what will happen, then
confirm/cancel. "More" (collapsed): regenerate config, rotate page link, retry inbound attach, move history. A separate
red zone: delete from panel. Success toast.

**Settings hub.** Cards in 3 groups, each with a one-line description and a status chip:

- Sales & money: payment gateways, sales & renewal, wallet & resellers.
- Servers & bot: panels & inbounds, bot & texts, brand & domain.
- System: scheduled jobs, admins & access, modules & plugins, logs.

Plus an "advanced" collapsible. Nothing is removed, only moved off the daily menu. Module-specific pages appear only when
the module is on (`product-modules.md` 4.6-4.7).

## 2. Mapping: every current page and action to the new IA

Notation: **§2** = sidebar item 2, "tab" = a tab strip on that page (the `AdminTabs` pattern,
`src/Admin/UI/Support/AdminTabs.php:22-37`), **hub** = a Settings card. "Kept" means the page, route and template stay
as they are, restyled by the shell only.

### 2.1 Menu entries (audit 1.1)

| Current entry | Controller / route | New place |
|---|---|---|
| Dashboard | `DashboardController::index()` (`:49`) | §1 داشبورد, new template (4) |
| Orders | `Crud/OrderCrudController` | §2, tab "سفارش‌ها". Kept |
| Payments | `Crud/PaymentCrudController`, `PaymentModerationController` | §2, default tab "پرداخت‌ها" with its status tabs (`PaymentTabs::TABS`), plus new tab "رسیدهای در انتظار" (4.2) |
| Discount codes | `Crud/DiscountCodeCrudController` | §5, tab "کدهای تخفیف". Kept |
| Plans | `Crud/PlanCrudController` | §5, default tab "پلن‌ها". Kept |
| Apps | `Crud/AppDownloadCrudController`, `Crud/ClientAppCrudController` (`AdminTabs` group `apps`) | §6, tab "اپ‌ها" (keeps its two sub-tabs). `[vpn]` |
| Customers | `Crud/UserCrudController` | §3, default tab "مشتریان". Kept |
| VPN services | `Crud/VpnServiceCrudController` | §4 سرویس‌ها: new list template + modal (5) on the same CRUD and routes |
| Wallets | `WalletAdminController` | §3, tab "کیف پول‌ها". Kept |
| Resellers | `ResellerAdminController` | §3, tab "نمایندگان" (shown when the resellers switch is on, as the setting already decides). Kept |
| Claims | `ClaimAdminController` | §3, tab "انتقال سرویس". Kept. `[vpn]` |
| Broadcasts | `Crud/BroadcastMessageCrudController`, `BroadcastActionsController` | §6, default tab "پیام همگانی". Kept |
| Bot texts | `Crud/BotMessageTemplateCrudController`, `Crud/BotButtonLabelCrudController`, `GuideTextAdminController` | §6, tabs "متن‌های ربات", "دکمه‌ها", "راهنما" (guide `[vpn]`). Kept |
| Payment gateways | `Crud/PaymentGatewayCrudController` + Zibal, NowPayments, CustomApi CRUDs | hub "درگاه‌های پرداخت". Kept |
| VPN panels | `Crud/VpnPanelCrudController`, `PanelSetupController`, `VpnPanelInboundActionsController` | hub "پنل‌ها و اینباندها". Kept. `[vpn]` |
| Settings (8 tabs) | `SettingsAdminController` | Split over hub cards, see 2.4. Same controller, same `?tab=` URLs |
| Plugins | `Crud/PluginCrudController` | hub "ماژول‌ها و افزونه‌ها". Kept |
| Admin users | `Crud/AdminUserCrudController` | hub "مدیران و دسترسی". Kept |
| Logs (3 tabs) | `AdminAuditLogCrudController`, `ServiceNotificationLogCrudController`, `BotMessageLogCrudController` | hub "گزارش‌ها". Kept |
| Logout | `admin_logout` | Sidebar bottom, next to the admin name |
| Locale switch | `AdminLocaleController` `admin_locale_switch` | Sidebar bottom, language toggle (same route) |

### 2.2 Pages not in the menu (audit 1.2)

| Page | Controller / route | New place |
|---|---|---|
| Order drafts | `Crud/OrderDraftCrudController` | §2, tab "سفارش‌های ناتمام" (today a global action of Orders) |
| Discount usages | `Crud/DiscountUsageCrudController` | §5, from a discount code (kept) and a tab "استفاده از تخفیف" |
| Trial plans | `Crud/TrialPlanCrudController` | §5, tab "پلن‌های تست". `[vpn]` |
| Trial claims | `Crud/TrialClaimCrudController` | §5, sub-tab of trial plans. `[vpn]` |
| Bulk price | `RenewalPricingAdminController` `admin_bulk_plan_price_adjustment` | §5, tab "تغییر گروهی قیمت". Kept (CSRF since PM12) |
| Apply inbounds, move run | `InboundMoveAdminController` `plan_apply_inbounds`, `plan_apply_inbounds_create`, `inbound_move_run_action` | Kept on the plan's pages (§5). Runs also listed in hub "کارهای زمان‌بندی‌شده". `[vpn]` |
| Broadcast deliveries | `Crud/BroadcastDeliveryCrudController` | Kept, from a broadcast (§6) |
| Telegram accounts | `Crud/TelegramAccountCrudController` | Kept, from a customer (§3) |
| Store payment methods | `Crud/StorePaymentMethodCrudController`, `admin_store_payment_method_move`, `_toggle` | hub "درگاه‌های پرداخت", second tab "روش‌های پرداخت مشتری" (today hidden behind gateways, audit 2.1) |
| Required channels | `Crud/RequiredChannelCrudController` | hub "ربات و متن‌ها" (settings tab `channels`). Kept |
| Raw settings | `Crud/SettingCrudController` | hub, "پیشرفته" collapsible. Kept |
| Gateway install, schema config, success, add store method | `admin_payment_gateways_install`, `_install_type`, `_install_success`, `_config`, `_add_store_method` | Kept, reached from the gateways card |
| Panel setup steps | `PanelSetupController` `admin_panel_setup_new/_edit/_show/_test/_inbounds/_plans` | Kept, from the panels card |
| Inbounds | `Crud/VpnInboundCrudController`, `admin_vpn_panel_view_inbounds` | Kept, from a panel |
| Service claims | `admin_claims_service`, `claims_create`, `claim_revoke`, `claims_return` | Kept; also linked from the service modal's "more" |
| Wallet pages | `wallet`, `wallet_customer`, `wallet_customer_open`, `wallet_adjust`, `wallet_credit_limit`, `wallet_freeze`, `wallet_unfreeze` | Kept under §3 |
| Reseller pages | `reseller`, `reseller_create`, `reseller_update`, `reseller_plan_price`, `reseller_plan_price_remove`, `reseller_enable`, `reseller_disable`, `reseller_order_refund`, `reseller_report` | Kept under §3 |
| Guide preview/reset | `bot_texts_guide_preview`, `bot_texts_guide_reset` | Kept under §6 |
| Branding logo removal | `settings_branding_remove_logo` | Kept, hub "برند و دامنه" |
| Legacy redirects | `LegacyAdminRedirectController`, `LegacyAdminRoutes` (10 entries) | Kept unchanged |
| Login | `AdminSecurityController` `admin_login` | Kept; restyled with the new tokens only when the switch is on |

### 2.3 Custom actions (audit 1.3)

| CRUD | Actions | New place |
|---|---|---|
| `PaymentCrudController:138-165` | confirm, retry provisioning, reject, reject as refunded | Kept on §2 rows and detail. Worklist rows (4.1) link to the same confirm pages |
| `VpnServiceCrudController:132-185` | regenerate config, rotate page link, claims history, retry attach, delete on panel | Service modal: regenerate, rotate, retry attach, claims → "more"; delete → red zone. **Same routes**: the modal opens the existing confirm page in the dialog (5.3). Detail page kept |
| `ServiceInboundsController:49,68` | retry move, retry attach | Modal "more" (move history shows the move field already on the detail page, `VpnServiceCrudController::inboundMoveField()`) |
| `VpnPanelCrudController:104-121` | test connection, sync inbounds, view inbounds | Kept, hub panels card |
| `VpnInboundCrudController:69-92` | test create client, resync, sync access metadata, regenerate service configs | Kept, from a panel |
| `PlanCrudController:139-148` | trial plans, trial claims, bulk prices, apply inbounds | First three become §5 tabs; the global actions stay too. Apply inbounds kept on the plan |
| `PaymentGatewayCrudController:114-136` | install, edit config, add store method | Kept, hub |
| `PluginCrudController:66-74` | upload, enable, disable | Kept, hub |
| `BroadcastMessageCrudController:82-87` | duplicate, retry failed | Kept, §6 |
| `DiscountCodeCrudController:69` | discount usages | Kept, §5 |
| `OrderCrudController:58` | order drafts | Also a §2 tab |
| `UserCrudController:72` | customer wallet | Kept, §3 |
| `BotMessageTemplateCrudController:81` | guide texts | Also a §6 tab |

### 2.4 Setting tabs (audit 1.4, `AdminTabs::SETTINGS`, `AdminTabs.php:40-48`)

| Tab | New place |
|---|---|
| `sales` (switches, closed message, renewal, global discount, traffic add-on) | hub "فروش و تمدید" → `admin_settings?tab=sales` |
| `automation` (sync, expiry, notify, auto suspend, batch, incomplete hours) | hub "کارهای زمان‌بندی‌شده", as its "settings" part |
| `wallet` | hub "کیف پول و نمایندگان", first part |
| `resellers` | hub "کیف پول و نمایندگان", second part |
| `plans` (multi-inbound, attach retries, inbound moves) | hub "پنل‌ها و اینباندها" (it is about inbounds, audit 2.2). `[vpn]` |
| `branding` | hub "برند و دامنه" |
| `channels` | hub "ربات و متن‌ها" |
| `advanced` (raw key/value) | hub "پیشرفته" collapsible |

The settings page itself is unchanged: one controller, same tabs, same token (`SettingsAdminController.php:46`). A card
links to `?tab=…`. Under the new layout the page's own tab strip shows only the tabs of the card you came from
(`?card=` query, display only); every tab stays reachable by URL.

Env-only settings (`BRAND_PUBLIC_BASE_URL`, `PAYMENT_CARD_*`, `SERVICE_NOTIFY_*`, `TELEGRAM_ADMIN_CHAT_ID`) stay env. The
"brand & domain" card shows the public base URL read-only with "set in `.env`".

### 2.5 Command-only features (audit 1.5)

| Command | New place |
|---|---|
| `app:payments:repair-stuck` | Worklist row "paid but not delivered" (`StuckPaymentFinder`), each item's button opens the payment's existing retry confirm. The bulk `--apply` stays CLI |
| `app:telegram:webhook-info` | hub "ربات و متن‌ها" status chip + dashboard health (4.4) |
| `amoobot-check-jobs` | hub "کارهای زمان‌بندی‌شده" + dashboard health (4.4) |
| `app:services:attach-inbounds` | Worklist row "inbounds not attached" → services tab "needs attention"; per service "retry attach" (exists) |
| `app:inbound:regenerate-service-configs` | Stays CLI above 10 services (`WEB_REGENERATE_MAX_SERVICES`) |
| `app:plan:apply-inbounds --retire-now`, `--keep-old`, `--grace-hours` | Stays CLI (not in the mockup) |
| `app:payments:winapay-reconcile`, `app:wallet:reconcile`, `app:service:verify-links`, `app:panel:doctor`, `detect-version`, `test-client-links`, `app:trial-plans:backing-audit`, `app:plugin:doctor`, `validate-package`, `app:payment:repair-plugin-gateway`, `app:telegram:set-webhook`, `set-menu-button`, `app:bot-content:missing`, `seed`, `app:sales:status`, `app:admin:create-user` | Stay CLI. Not in the hub for now (decision 12) |

### 2.6 Placed late, or not yet placeable

1. **Broadcast preview / send now / cancel** (`BroadcastMessageCrudController.php:260-324`). The methods exist but no
   action or route reaches them. The owner decided to wire them (decision 11): §6, in **AD7** (the PR that builds the
   section pages, of which §6 is one). They get `#[AdminRoute]`s, actions on the broadcast detail page, `BROADCAST_SEND`,
   the PM12 pattern (GET confirm page naming the recipient count, POST + token) and an audit row each; preview is a GET
   that changes nothing.
2. **Modules page** ("ماژول‌ها" in the hub card). There is no module enable/disable page yet: that is
   `product-modules.md` PM2b/PM3b. Until then the card shows Plugins only.
3. **Mockup items with no backend on the web:** "resend link to customer" and "links & QR" on a service, the
   "needs attention" service tab as one list, "message owners" in bulk, the "version" chip, gateway health, last
   backup and webhook status. Each is designed in 3-4 and built in section 7; none removes anything.

## 3. Service actions on the web

### 3.1 Rule

The web does exactly what the bot and the Mini App do, through the same code, never by editing fields:

| Action | Call (as the bot, `ServiceManagementService`) | Permission | Mini App route (for reference) |
|---|---|---|---|
| Suspend | `ProductModuleResolver::forService($s)->suspend($s)` (`:1230`) | `SERVICES_MANAGE` | `miniapp_admin_service_suspend` |
| Activate | `->resume($s)` (`:1246`) | `SERVICES_MANAGE` | `_activate` |
| Delete | `->terminate($s)` (`:1265`; web already: `ServiceDeleteController.php:61`) | `SERVICES_DELETE` | `_delete` |
| Reset usage | `ServiceAdministration::resetUsage()` (`:1285`) | `SERVICES_MANAGE` | `_reset_usage` |
| Extend N days | `ServiceAdministration::extend()` (`:1318`) | `SERVICES_MANAGE` | `_extend` |
| Add N GB | `ServiceAdministration::addTraffic()` (`:1351`) | `SERVICES_MANAGE` | `_add_traffic` |
| Resend link | `ServiceManagementService::resendConfig(…, adminMode: true)` (`:258`) | `SERVICES_ASSIST` | `_resend_config` |
| Regenerate config | existing web route `admin_vpn_service_regenerate_config` | `SERVICES_ASSIST` | `_regenerate_config` |

**Support in the modal (decision 8).** An admin with `SERVICES_ASSIST` but not `SERVICES_MANAGE` (today: `ROLE_SUPPORT`)
sees the header, the three tiles and **one action: "resend link"**. No links, no QR, no "more", no red zone. The modal
decides this by permission (`SERVICES_MANAGE` for links & QR and "more"), not by role name, so a later role split (AD11)
needs no template change. This is a modal rule only: the detail page and today's routes keep their current permissions
until AD11 adds masking (audit 4.2); nothing is taken away from support in this series.

Reset usage, extend and add traffic move to the module contract when it gets admin actions (product-modules.md PM3a,
"Still on ServiceAdministration"). The web controller calls them through one small application service
(`App\Admin\Application\ServiceActions`), so that move changes one class, not three surfaces. The bot and the Mini App
switch to it in the same PR or later; their behaviour doesn't change.

Limits come from the domain: `ServiceAdministration::MAX_DAYS = 3650`, `MAX_TRAFFIC_GB = 100000` (`:24-25`), checked
again inside `extend()`/`addTraffic()`. Presets as the Mini App: `EXTEND_PRESETS = [7, 30, 90]`,
`TRAFFIC_PRESETS = [10, 50, 100]` (`MiniAppAdminServiceController.php:56-57`), plus a custom value. Persian digits are
accepted (`AdminText::latinDigits()`, as `ServiceDeleteController.php:54`).

### 3.2 Why web extend / add traffic waits for a separate fix

**Dependency: web extend/add traffic (the single-service actions PR, AD5) requires fix/admin-extend-traffic-on-panel
merged.** That branch is not part of this series: it fixes the bot and the Mini App now (decision 1). The finding that
led to it, from reading:

- `ServiceAdministration` says so itself: "extend and add traffic (local only, as they always were)"
  (`ServiceAdministration.php:17-20`). `extend()` sets `expiresAt` and flushes (`:102-114`); `addTraffic()` sets the
  limit and flushes (`:116-127`). No driver call.
- The 3x-ui client has its own expiry and limit, set at creation (`Sanaei3xuiModernDriver.php:124`), and the panel
  enforces them.
- `ServiceUsageSyncService` (timer `sync`, every 15 minutes, `deploy.sh:103`) takes the panel's `expiresAt` and total
  and writes them over the row (`ServiceUsageSyncService.php:78-99`), unless a paid change is unfulfilled.

So an admin's "extend 30 days" shows in the bot until the next sync, the panel still disables the client on the old
date, and the sync then reverts the row. The mockup's confirm text "new expiry …, applied on the 3x-ui panel too" would
be false.

The drivers already have what is needed: `renewService()` with `expiresAt` (`Sanaei3xuiModernDriver.php:759-770`) and
`addTrafficLimit()` (`:776`), so the fix can call the panel first, like `suspend()` (nothing changes locally if the
panel fails: `onPanel()`, `:134-149`). Until it is merged there is no true confirm text to show (even "the panel is
updated at the next sync" would be false: the sync goes the other way), so **AD5 ships extend and add traffic only
once fix/admin-extend-traffic-on-panel is merged**; if AD5 is ready first, it ships the other actions and adds these two
in a follow-up commit. Suspend, activate, reset usage and delete do reach the panel today. AD5's tests include the
regression "sync after a web extend keeps the new date".

### 3.3 Single-service actions: request flow

One controller, `ServiceActionsController`, routes under `/admin/vpn-service/{id}/…` next to the existing ones:

- `GET …/action/{action}`: the confirm fragment (the `ConfirmPages` lines, rendered as a fragment when
  `?modal=1`, the full `action_confirm.html.twig` page otherwise, so it works without JS).
- `POST …/action/{action}` with `_token` (CSRF id `vpn_service_action_{action}_{id}`), amount fields where needed.
- Per action: permission check (`AdminCrudPermissions::ROUTES`, fail-closed as every `App\Admin\` controller), the call
  from 3.1, `AdminAuditLogger::record('service.'.$action, 'VpnService', $id, ['outcome' => …, 'days'|'gb' => …,
  'source' => 'web'])` exactly as the Mini App does (`MiniAppAdminServiceController::audit()`), then a translated flash.
- Rate limit per admin: reuse the limiter pattern of `AdminActions` (`src/MiniApp/Admin/AdminActions.php:23-54`) with a
  new limiter `admin_web_action`.
- Idempotency: the confirm form carries a one-time key; a double submit gets the first answer
  (`CheckoutIdempotency::claimOnce`, as `AdminActions` step 3).
- Answers by `ServiceAdminOutcome`: `DONE` → toast, `ALREADY_*` → info, `PANEL_FAILED` → error that names the panel,
  `INVALID_AMOUNT`/`LIMIT_OVERFLOW` → the field error, `NOT_ALLOWED` → error. No exception text reaches the page (audit
  2.3).

Confirm texts, each computed server-side from the service (no JS maths):

| Action | Text (fa first; en key mirrors it) |
|---|---|
| Extend | "انقضای جدید: {date}. روی پنل {panel} هم اعمال می‌شود. از کیف پول کسر نمی‌شود." (only with fix/admin-extend-traffic-on-panel merged) |
| Add traffic | "حجم جدید: {old} + {n} = {new} گیگ. روی پنل {panel} هم اعمال می‌شود. از کیف پول کسر نمی‌شود." (same) |
| Reset usage | "مصرف {used} صفر می‌شود، روی پنل هم. حجم کل تغییر نمی‌کند." |
| Suspend | "اتصال مشتری روی پنل {panel} قطع می‌شود تا وقتی دوباره فعال کنید." + the sentence about the customer message that matches today's behaviour |
| Activate | "اتصال روی پنل {panel} دوباره برقرار می‌شود. تاریخ انقضا تغییر نمی‌کند." + the same |
| Resend link | "لینک‌ها در چت تلگرام {customer} دوباره فرستاده می‌شود." |
| Delete (red zone) | Today's text and the typed name (`ServiceDeleteController.php:39-46`) |

Customer messages on suspend/activate keep today's behaviour (decision 4): the web calls the same module methods and
adds no message of its own. AD5 reads `ServiceManagementService::suspendService()`/`activateService()` and the module
path to write the matching sentence, and a test pins that the web sends exactly what the bot's admin path sends.

### 3.4 Bulk actions

Bulk changes the panel for many customers, so it follows the audit's workflow pattern (audit 6): dry run → explicit
confirmation → job → per-row result.

**Who: super admin only (decision 3).** A new permission `SERVICES_BULK` in `AdminPermission`, granted only to
`ROLE_SUPER_ADMIN` in `AdminRolePermissions::forRole()`. A permission, not a role check, so it fits the existing matrix
and the fail-closed listener. The checkboxes and the bulk bar render only with it; every bulk route requires it, and the
action's own permission (`SERVICES_MANAGE`) as well.

| Step | What happens |
|---|---|
| Select | Checkboxes on §4 (5.2). "Select all N matching" is offered only up to the limit |
| Choose | Bulk bar: extend days, add traffic, suspend, activate, message owners |
| Dry run (GET) | `BulkServiceRunPlanner` counts what would happen: will change / already in that state / not allowed (deleted, module off, driver off) / would exceed MAX. Nothing is written |
| Confirm (POST + token) | Page states the counts and the action in words; the admin types the number of services to confirm when it is above 20 (as the delete asks for the name) |
| Run | A `BulkServiceRun` row (`planned`) with one `BulkServiceRunItem` per service; the same runner processes it |
| Result | A run page: per row `done` / `already` / `panel_failed` / `skipped` with the reason; "retry failed" creates a new run of the failed rows |

Execution:

- **Small runs (≤ 10, as `WEB_REGENERATE_MAX_SERVICES`) run in the request**, through the same runner, and show the
  result page at once.
- **Larger runs go to a timer**, `bulk-actions`, in its own lock group, every 2 minutes (as `broadcasts`,
  `deploy.sh:101`), a batch per tick. No Messenger: it isn't configured (`messenger.yaml`) and the timers are the house
  pattern. The run page shows "next run in N minutes" from the job state (4.4).
- **Idempotent per item:** an item is claimed (`planned → running`) with a row lock, and done items are never redone.
  Extend/add traffic store the amount on the run; an interrupted item is re-checked before retry (a re-run of extend
  would extend twice, so a `running` item older than the timeout is marked `unknown` for the admin, not retried).
- **Limits (decision 6):** max **500** services per run; **one active run per admin**; typed count confirmation
  **above 20** services; the action's own MAX per service. The run's permissions (`SERVICES_BULK` + the action's) are
  checked at creation and again by the runner (the admin may lose the role meanwhile).
- **Audit:** one row for the run (`service.bulk.<action>`, counts) and one per item (`service.<action>`, `run` id), so
  the log of one service shows bulk changes too.
- **Pause / cancel:** as `InboundMoveRun` (`inbound_move_run_action`: pause, resume, cancel), reusing its page shape.
- **Message owners** is not a run: it opens a new broadcast with `TARGET_SELECTED_USERS` and the owners' user ids
  (deduplicated), and the normal broadcast flow takes over.

Delete is **not** a bulk action (not in the mockup either); one service at a time with the typed name.

## 4. Dashboard data

### 4.1 Worklist "نیاز به اقدام شما"

| Row | Source | Exists? | Query | Button |
|---|---|---|---|---|
| Receipts to review (card-to-card) | `pending`/`submitted` payments of the card-to-card method with `receiptFileId` or `trackingCode`. **This count, and only this, is the sidebar's red badge** (decision 5) | **New** (`PaymentTabs` gets a `receipts` tab with this filter) | 1 COUNT | §2 tab "receipts", oldest first |
| Needs review (gateway amount mismatch) | `PaymentTabs::countByTab()['needs_review']`. A separate row, never in the badge (decision 5) | Exists (`DashboardOverview::attention()`) | shared GROUP BY | §2 tab |
| Paid but not delivered | `provisioning_failed` count (exists) + `StuckPaymentFinder` count (confirmed, unfulfilled, older than 30 min) | Partly; the finder needs a `count()` | 1 GROUP BY + 1 COUNT | §2 tab / the payment's retry confirm |
| Services expiring today | `ServiceTabs::countByTab()` + one `SUM(CASE … expiresAt <= end of today)` column, "today" = the calendar day in **Asia/Tehran** (decision 10, 4.2) | **New column** in the existing query | shared | §4 tab "expiring", sorted by expiry |
| Inbounds not attached | `ServiceInboundCounts::notConnectedByService()` (`src/Provisioning/Application/ServiceInboundCounts.php:71`) | Exists | 1 GROUP BY | §4 tab "needs attention". Only when multi-inbound is on |
| Failed inbound moves | services with failed moves (`VpnServiceCrudController` `?moves=failed`) | Exists as a filter; count is new | 1 COUNT | §4 filtered |

"One button that does the fix" means: open the exact list or confirm page. The dashboard never acts in one click
(PM12's rule: a GET never changes anything).

Rows with a zero count are hidden; an empty list shows "همه چیز مرتب است".

### 4.2 KPI cards and charts

| Card | Source | Exists? |
|---|---|---|
| Sales today, % vs yesterday | `DashboardOverview::today()` revenue (`:100-136`) | Today exists. **New:** the same query for yesterday's range (refactor `today()` into `range(from, to)`); % computed in PHP; "—" when yesterday is 0 |
| Confirmed payments + waiting | `today()` count + `PaymentTabs` `pending` | Exists |
| Active services + new today | `ServiceTabs` `active` + COUNT `createdAt >= midnight` | Count new (folded into the `countByTab` query) |
| Expiring in 3 days | `ServiceTabs` `expiring` (`EXPIRING_DAYS = 3`, `ServiceTabs.php:23`) | Exists |
| New customers | `today()` customers | Exists |
| 7-day sales bars | **New:** one query, `SUM(CASE WHEN confirmedAt in day k …)` × 7 with the revenue rule of `today()` (wallet payments excluded, top-up credit counted). DQL has no `DATE()`, so 7 CASE columns, no native SQL | New |
| Services by status | `ServiceTabs::countByTab()` | Exists |
| Latest payments | **New:** 8 rows, newest first, joined with user and order; amount, method, status chip, link | New |

**Day boundaries: Asia/Tehran.** The app runs in UTC (`src/Kernel.php:17,25`), so today's `today()` counts from UTC
midnight (`$clock->now()->setTime(0, 0)`, 03:30 in Tehran). Decision 10 sets "expiring today" to the calendar day in
Asia/Tehran; so that one page never mixes two "todays", the modern dashboard uses that day for **every** boundary:
expiring today, sales/payments/customers today, yesterday, the 7 days. One constant (`AdminCalendar::TIMEZONE =
'Asia/Tehran'`), converted to UTC instants for the queries; stored data stays UTC. The classic dashboard keeps UTC
midnight unchanged, so the two can differ by up to 3.5 hours of activity (flip checklist 3 accounts for this). With
Jalali display (6.3) the chart labels are Jalali dates in Persian.

### 4.3 Header

- **Global search:** one box, routed by what is typed: a number → service id, payment id, order id (shows "found in …"
  choices); `@name` or text → customers (`CustomerSearch`, `src/Admin/Application/CustomerSearch.php`) and services by
  username. Results are links; it's a GET form to an `admin_search` page. **No typeahead** (decision 14).
- **"System healthy · version" chip:** green when every health line (4.4) is OK, amber with the count otherwise; click
  opens the health block. Version from the deploy record (4.4, decision 13).

### 4.4 System health

| Line | Source | Exists? | Cost |
|---|---|---|---|
| Panels | `PanelHealth::of()` from stored probe data (`DashboardOverview::panels()`, `:157-174`); nothing is contacted | Exists | 1 query |
| Jobs | Read `/var/lib/amoobot/scheduled-jobs.conf` (`root:root 0644`) and `jobs/<job>.last-success`, `.last-run`, `.installed` (written by the jobs themselves as `www-data`, `bin/scheduled-job.sh:61-64`) and apply the OK/STALE/NEVER/NEW rules of `bin/check-scheduled-jobs.sh` in PHP (`ScheduledJobHealth`). FAILED comes from `rc=` in `.last-run`. INACTIVE (timer stopped) needs systemd, which PHP never asks: it shows as STALE once the job is overdue | **New** reader. **No new right**: FPM and the jobs are both `www-data` (section 0) | ~12 `stat()` calls |
| Last backup | Root writes `/var/lib/amoobot/backup-last-success` (`root:root 0644`, one line: the UTC time of the last successful nightly dump; no file name, size or path) at the end of `bin/backup-database.sh` when the label is `nightly`. PHP reads that file only; `/var/backups/amoobot` stays `0700` | **New** (one line in a root script) | 1 file read |
| Gateways | **New**: `PaymentGatewayCredentialAlert` also stores the last failure per gateway (code and time) in the cache pool, 24 h TTL; the line shows active gateways and any recent credential failure | New | 1 cache read |
| Bot webhook | `TelegramApiClient::getWebhookInfo()` (`src/Bot/Infrastructure/TelegramApiClient.php:367`) is a **remote call**: never on page load. Cached 10 min, refreshed by a timer tick or the "check now" button (POST) on the bot card | New | 1 cache read |
| Version | Decision 13. `deploy.sh` already records the commit in `deployed-commit` (`root:root 0640`, root's trusted record, `deploy.sh:1897`). Rather than open that file to PHP, `deploy.sh` writes a **separate public copy** next to it, `/var/lib/amoobot/deployed-version` (`install -m 0644 -o root -g root`, one line: short commit, UTC deploy time, `DEPLOY_REF`), at the same step. PHP reads only that; it holds nothing secret and is written only by root. Missing file → "dev" | New (one line in `deploy.sh`) | 1 file read |

**Least privilege, summarised.** PHP-FPM gains no group, no sudo rule, no `systemctl` call and no access to the backup
directory, the deploy record, logs or locks. It reads three things: the job state it already owns, and two one-line
`0644` files that root writes for it on purpose (`deployed-version`, `backup-last-success`). All paths are constants in
one class with an env override for tests (`AMOOBOT_STATE_DIR`, as the scripts' `AMOOBOT_JOBS_STATE_DIR`); an unreadable
file shows "unknown", never an error.

### 4.5 Cost per page load and caching

Today's dashboard runs about 6 queries (`DashboardOverview::for()`: payment tabs, today's 3 counts, revenue, service
tabs, panels, wallet totals). The new one:

| Group | Queries | Cache |
|---|---|---|
| Worklist | ~5 (receipts, payment tabs, stuck count, service tabs + today column, not attached) | **None**: must be fresh, all are indexed COUNTs |
| KPI + charts | ~4 (today range, yesterday range, 7-day CASE, new services today in service tabs) | 60 s in `cache.app`, key = section + day + permission set (not per admin: the numbers don't depend on who asks, only whether they may see them) |
| Latest payments | 1 (LIMIT 8) | None |
| Health | 1 query + ~12 `stat()` + 2 file reads + 2 cache reads | 60 s |
| Sidebar badge (every admin page) | 1 COUNT (card-to-card receipts) | 30 s, shared |

So ~12 queries cold, ~7 warm, each an aggregate with no per-row query (the class's own rule, `DashboardOverview.php:24-27`).
Indexes to check in AD2: `payment(status)`, `payment(confirmed_at)`, `vpn_service(status, expires_at)`,
`vpn_service(created_at)`, `user(created_at)`. A migration only if `EXPLAIN` on a production-size copy shows a scan.

Sections stay per permission, as now: a support admin sees no money cards (`$can(...)` in `DashboardOverview::for()`).

## 5. Pages: what reuses EasyAdmin and what is custom Twig

### 5.1 The switch and the shell

**Switch.** A setting `admin.ui.layout` = `classic` (default) | `modern`, in the `setting` table, edited on a card in hub
"پیشرفته" (super admin, POST + token + confirm, audited as `settings.update`). Plus an env override
`ADMIN_UI_FORCE_CLASSIC=1` for the case where the modern layout breaks the page that would switch it back.

**Preview before the flip: super admin only (decision 2).** `?admin_ui=preview` sets a session flag,
`?admin_ui=classic` clears it. `AdminUiMode` honours the flag only for an admin whose assigned roles include
`ROLE_SUPER_ADMIN` (`AdminUser::getAssignedRoles()`); for anyone else the parameter is ignored. Other admins see the new
layout only after the flip.

**Brand name (decision 9).** In modern mode `configureDashboard()->setTitle()` and the `<title>` use the brand name from
Branding instead of `'Amoobot Admin'` (`DashboardController.php:86`); fallback "Amoobot" when none is set.

**One reader**, `AdminUiMode` (request-scoped, reads the setting once), used by:

- `DashboardController::configureMenuItems()`: classic yields today's groups unchanged; modern yields the 7 items. Each
  item keeps `setPermission(AdminCrudPermissions::view(…))` of the page it opens, so menu and server can't disagree
  (audit 4.1). Item 2 gets `setBadge()` with the receipts count.
- `configureAssets()`: modern adds `admin-modern.css` and the modern JS; classic is byte-identical to today.
- `templates/bundles/EasyAdminBundle/layout.html.twig`: extends `@!EasyAdmin/layout.html.twig` and, in modern mode,
  overrides only the sidebar, header and body-class blocks. CRUD pages are unchanged inside.
- `DashboardController::index()`: renders `admin/modern/dashboard.html.twig` in modern mode, today's template otherwise.

**Same routes.** Every existing route keeps its path, name, permission, token and confirm page. New pages get new routes
in the same `/admin` namespace, registered in `AdminCrudPermissions::ROUTES` (fail-closed). In classic mode the new
pages still work by URL (they're useful and harmless) but no menu links them; the bulk runner and the service actions
are not tied to the layout.

**`custom_page.html.twig`**: its 4 pages move to extend the EasyAdmin layout (dropping the hashed CSS file name,
section 0) in AD1, in both modes.

**Section pages = tab groups.** Items 2, 3, 5 and 6 are groups of existing pages; each gets an `AdminTabs` group (the
existing mechanism, `AdminTabs.php:22-37`) so the tab strip shows the section's pages and the sidebar item stays active.
EasyAdmin's active-menu detection works by the menu item's controller; for a tab on another controller the layout
override marks the item from the section map (`AdminSections::of(route)`). To verify in AD1 on EasyAdmin 5.6.1.

### 5.2 Reuse vs custom

| Part | Built with | Why |
|---|---|---|
| All CRUD lists, filters, forms, detail pages (orders, payments, customers, plans, discounts, broadcasts, texts, gateways, panels, plugins, admins, logs) | **EasyAdmin, unchanged**, restyled by CSS tokens | They work and are tested (28 files in `tests/Admin`) |
| Section tab strips (2, 3, 5, 6) | `AdminTabs` groups | Existing pattern |
| Services list (§4) | **EasyAdmin index** of `VpnServiceCrudController` with a custom index template (`configureCrud()->overrideTemplate('crud/index', …)` in modern mode only): tabs (existing `admin_tabs` response parameter, `configureResponseParameters()`, `:93-117`), search (`configureCrud()->setSearchFields`), filters (`EntityFilter` user exists; add plan, panel, reseller), usage bar and status chip as field templates | Pagination, sorting, filters and permissions come free. "Needs attention" tab = not attached + failed moves + `provisioning_failed` services (new filter key) |
| Row checkboxes + bulk bar | EasyAdmin **batch actions** (they render checkboxes and post the ids with EasyAdmin's own CSRF token); each batch action redirects to the bulk dry-run page (3.4). The dark bar is CSS on EasyAdmin's batch-action area | No custom selection code |
| Service modal | **Custom Twig fragment** (`admin/modern/service/_modal.html.twig`) from a new GET route `admin_vpn_service_panel` (read-only, `SERVICES_VIEW`), loaded into a `<dialog>`; each action's confirm is the 3.3 fragment loaded into the same dialog | Not a CRUD page. Without JS the "⋯" link opens the detail page as today |
| Dashboard | **Custom Twig** | 4 |
| Settings hub | **Custom Twig** page `admin_settings_hub` (`SETTINGS_MANAGE` for the page; each card shown by its own permission: gateways `GATEWAYS_MANAGE`, panels `PANELS_MANAGE`, admins `ADMINS_MANAGE`, logs `LOGS_VIEW`, …) | Cards with status chips |
| Jobs page | **Custom Twig** `admin_jobs` (4.4 data, inbound-move runs, bulk runs, automation settings link) | New |
| Global search | **Custom Twig** `admin_search` | New |
| Bulk dry-run, confirm, run pages | **Custom Twig** on the `action_confirm` shell | Workflow pattern |
| Charts | Server-rendered inline SVG / CSS bars in Twig, no chart library | No build, RTL-safe, prints |

### 5.3 JavaScript

Stimulus is the decision, but nothing in the repo loads it today. Options, no Node either way:

- **(a) Recommended: vendor one file.** `@hotwired/stimulus` ESM build (one file, MIT) committed under
  `public/assets/admin/vendor/`, loaded with `<script type="module">`. Controllers are small files next to `admin.js`.
  No deploy change, no Composer package.
- (b) `symfony/asset-mapper` + `symfony/stimulus-bundle` + `importmap.php`. More conventional, but adds
  `asset-map:compile` to `deploy.sh` (or serves assets through PHP), which is a production change for a few hundred lines
  of JS.

Controllers needed, each under ~80 lines: `dialog` (open a fragment URL in `<dialog>`, focus trap, Esc), `confirm`
(swap the action grid for the confirm fragment, submit as a normal form, show the toast from the flash), `bulk-bar`
(show when EasyAdmin's batch checkboxes are ticked), `theme` (toggle, persist), `collapse`. Every interaction has a
no-JS fallback (a link to a full page), so tests stay crawler tests.

## 6. i18n, RTL, dark mode, accessibility, assets

### 6.1 i18n

- Every new string is a key in `translations/admin.fa.yaml` and `admin.en.yaml`; `TranslationCompletenessTest` guards
  both (audit 3). Persian first: the fa text is written first and the en mirrors it.
- No exception text or English data in pages (audit 2.3): actions answer by outcome enum (3.3).
- The title `'Amoobot Admin'` (`DashboardController.php:86`) becomes the brand name in modern mode, header and browser
  tab (decision 9).
- Labels use the owner's words from the mockup; technical words (inbound, regenerate) appear only under "more".

### 6.2 RTL

- EasyAdmin sets `dir` from the locale (audit 3); modern CSS uses **logical properties only** (`margin-inline-start`,
  `inset-inline-end`, `text-align: start`), so one stylesheet serves fa and en. No `[dir=rtl]` overrides in the new CSS.
- Keep the LTR islands pattern (`dir="ltr"`, `<bdi>` on ids, URLs, usernames, amounts with Latin digits).
- Bars (usage, charts) grow from the inline start; the 7-day chart reads right-to-left in fa (today at the inline end).

### 6.3 Dates and digits

Decision 7: **Jalali dates with Persian digits in Persian, Gregorian in English.**

- Modern mode formats every date (lists, detail pages, dashboard, chart labels) through one Twig filter, `admin_date`,
  built on the existing `SubscriptionFormat` approach (`IntlDateFormatter('fa_IR@calendar=persian')`,
  `SubscriptionFormat.php:36`), which writes Persian digits; `en` gets Gregorian with Latin digits. Without intl it falls
  back to `Y-m-d`. EasyAdmin date fields use it through the existing `AdminValueConfigurator` hook. Classic mode keeps
  `yyyy-MM-dd` (`DashboardController.php:79-80`).
- Times shown with a date are in Asia/Tehran, the same zone as the dashboard's days (4.2); stored values stay UTC.
- Digits in fa: Persian in display text (dates, counts, amounts in cards and tables). Latin stays in ids, codes, URLs,
  usernames, inputs and anything copyable, inside the LTR islands (6.2). Inputs accept both (`AdminText::latinDigits()`).

### 6.4 Theme and dark mode

- Tokens as CSS custom properties in `admin-modern.css`: `--bg: #F4F6F9`, `--card: #fff`, `--radius: 16px`, one
  `--accent` = **the brand colour from Branding** (decision 15), written into the page as one inline custom property
  (validated as a hex colour; fallback a default when unset), status colours (ok, warn, danger, info, muted), text and
  border. Text on accent picks black or white by contrast, so any brand colour stays readable (6.5).
- Dark mode: the same tokens under `[data-theme="dark"]`. EasyAdmin has its own light/dark scheme handling and
  `configureDashboard()` doesn't disable it (`DashboardController.php:83-94`), but `admin.css` has no dark rules (0
  matches for "dark"), so its overrides are light-only. The modern toggle drives EasyAdmin's scheme and the tokens
  together; classic keeps whatever it does today. To verify on a running panel in AD1.
- Choice persists per browser (`localStorage`), default = system preference.

### 6.5 Accessibility rules

- Touch targets ≥ 44×44 px (buttons, tabs, checkboxes' hit area, sidebar items).
- Contrast ≥ 4.5:1 for text, 3:1 for chips and bars, in both themes; status never by colour alone (chip text + icon).
- `<dialog>` with `aria-labelledby`, focus moved in and returned on close, Esc closes, background inert.
- Toasts in an `aria-live="polite"` region; errors `role="alert"`.
- Charts have a text table equivalent (visually hidden) and a `<title>`.
- Keyboard: every action reachable by Tab; the bulk bar announces "N selected".
- Respect `prefers-reduced-motion`.

### 6.6 Assets: what the repo has, and what modern mode adds

Today: `public/assets/admin/admin.css` (1212 lines, 14 `!important`), `admin.js` (88 lines, no dependencies), font from
the jsDelivr CDN (`admin.css:2`), no build step, `deploy.sh` runs Composer only.

Modern mode adds, still with no build step:

- `public/assets/admin/admin-modern.css` (tokens, shell, components; target < 800 lines, no `!important` except where
  EasyAdmin's specificity forces it, each commented).
- `public/assets/admin/fonts/Vazirmatn-wght.woff2`, a copy of the Mini App's file (OFL, `public/miniapp/fonts/OFL.txt`),
  so the panel works without the CDN (audit question 11). Classic switches to it too (one line), since it only removes a
  network dependency.
- `public/assets/admin/vendor/stimulus.js` and `public/assets/admin/controllers/*.js` (5.3).
- Cache busting: `?v=` from `deployed-version` (4.4), as the Mini App does with its font.

## 7. PR plan

### 7.0 Numbering

The redesign's PRs are **AD1-AD9**, in the order below. The audit's PM13-PM19 (audit 7) were never built; their numbers
are not reused, and PM20/PM21 belong to the Marzban and PasarGuard drivers (`product-modules.md`). The audit items not
covered by this series keep running on their own:

- audit "PM13 language" (stored English messages, bulk-price page, README roles) → **AD10**;
- audit "PM18 roles" (sales manager, permission split, masking) → **AD11**.

Audit "PM14 menu", "PM15 jobs", "PM16 dashboard", "PM17 workflows" and "PM19 Jalali/fonts" are absorbed into AD1-AD8.

**Outside the series:** the panel-side extend / add traffic fix is its own branch, `fix/admin-extend-traffic-on-panel`,
for the bot and the Mini App (decision 1, reason in 3.2). **Web extend/add traffic (AD5) requires
fix/admin-extend-traffic-on-panel merged.**

### 7.1 PRs

| PR | Content | Switch | Risk | Tests |
|---|---|---|---|---|
| **AD1** Shell | `AdminUiMode` + setting + env override + super-admin-only preview (decision 2); layout override (sidebar 7 items, bottom toggles, header); brand name in header and tab (decision 9); `admin-modern.css` tokens with the Branding colour as accent (decision 15), local font; vendored Stimulus + `theme`, `collapse`; section tab groups (`AdminTabs`) and `AdminSections`; `custom_page.html.twig` on the EasyAdmin layout; card-to-card receipts badge (decision 5) | Off. Classic output unchanged | Low-medium (layout override touches every page) | Classic: snapshot of the menu HTML before/after is identical. Modern: crawl every menu target as each role (`RoleAccessWebTest` style) → 200 or 403 exactly as classic; badge counts card-to-card receipts only; `?admin_ui=preview` ignored for admin and support, honoured for super admin; accent value rejected unless a hex colour |
| **AD2** Dashboard | `DashboardOverview` gains `worklist()`, `range()`, `sevenDays()`, `latestPayments()`, the service-tab columns, `StuckPaymentFinder::count()`, `PaymentTabs` `receipts`; `AdminCalendar` (Asia/Tehran days, decision 10); caching (4.5); modern dashboard template; global search page, no typeahead (decision 14) | Modern only (data methods are mode-free) | Low | Each new query against fixtures (counts, revenue rule incl. wallet and top-up, yesterday = 0); day boundaries at Tehran midnight (a service expiring 23:59 Tehran counts, 00:01 next day doesn't; a payment at 21:00 UTC counts for the next Tehran day); permission sections per role; query count ≤ 12 cold |
| **AD3** Health | `ScheduledJobHealth` reader; `backup-last-success` line in `bin/backup-database.sh`; `deployed-version` line in `deploy.sh` (decision 13); gateway failure memory; cached webhook info + "check now"; jobs page; health block and version chip | Modern pages; the two ops lines are inert for classic | Medium (root scripts) | Reader on fixture dirs (OK/STALE/NEVER/NEW/FAILED, unreadable → unknown); `tests/ops`: both new files `root:root 0644` with one line each, `deployed-commit` mode unchanged, backup dir still `0700`; webhook never called on page load (spy) |
| **AD4** Services list + modal (read-only) | Modern index template, tabs incl. "needs attention", search, plan/panel/reseller filters, usage bar, status chip; `admin_vpn_service_panel` fragment (tiles, links & QR via `endroid/qr-code` for `SERVICES_MANAGE` only, existing actions in "more" opening existing confirm pages); support sees "resend link" only (decision 8); `dialog` controller | Modern only | Low | Filters and tabs return the right ids; fragment needs `SERVICES_VIEW`; as support: no links, no QR, no "more", no red zone in the HTML (not just hidden by CSS); no-JS fallback link |
| **AD5** Single-service actions | `ServiceActions` application service; `ServiceActionsController`: suspend, activate, reset usage, resend link; **extend and add traffic only with fix/admin-extend-traffic-on-panel merged**; confirm fragments; customer messages as today (decision 4); audit; limiter; idempotency key; toast; red zone wired to the existing delete | Routes mode-free; buttons in modern modal | Medium | Per action × role: 403 without permission, CSRF required, GET never changes, one audit row with outcome, `PANEL_FAILED` leaves the row, double POST does it once; module resolver spy proves suspend/resume/terminate go through `ProductModuleResolver`; web suspend/activate send the same customer message as the bot's admin path; sync after a web extend keeps the new date |
| **AD6** Bulk actions | `SERVICES_BULK` permission, super admin only (decision 3); `BulkServiceRun` + items (migration, additive), planner (dry run), confirm with typed count above 20, runner (in request ≤ 10, timer `bulk-actions` above), run page with pause/cancel/retry failed, message owners → broadcast prefill; EasyAdmin batch actions on the services list | Routes mode-free; bar in modern | **Medium-high** (many customers at once) | Dry run writes nothing; counts match the run; 403 for admin and support; limit 500, one active run per admin, typed count required at 21 and not at 20 (decision 6); item claimed once under concurrent runners; `running` timeout → `unknown`, not retried; audit per item; permissions re-checked by the runner; migration on a production-size copy |
| **AD7** Settings hub, section pages, broadcast send | Hub page and cards with status chips, no CLI-tools card (decision 12); `?card=` tab filtering on settings; advanced collapsible with the layout switch; module-gated cards (`[vpn]` until PM3b, then real gating); page-level help lines; §6: **broadcast preview / send now / cancel wired** (decision 11, 2.6) | Hub modern only; broadcast actions in both layouts | Low-medium (send now reaches every recipient) | Each card per role; every settings tab still reachable by URL; module-off fixture hides `[vpn]` cards once PM3b exists; send now: `BROADCAST_SEND`, GET confirm shows the recipient count and sends nothing, POST + token sends once (second POST is refused), audit row; preview changes nothing |
| **AD8** Polish and readiness | `admin_date` filter: Jalali + Persian digits in fa, Gregorian in en (decision 7); dark theme pass; accessibility pass (6.5); empty states; login page tokens; flip checklist (7.2) run on staging | Modern only | Low | a11y checks in crawler tests (labels, dialog attributes, 44px class on targets); date and digit rules unit-tested in both locales, with and without intl |
| **AD9** Remove classic | One month after the flip (decision 16): delete the classic menu branch, `admin.css` rules no longer used, `custom_page.html.twig` leftovers, the setting, the env override and the preview flag; modern becomes the only layout | n/a | Low-medium (large deletion) | Full `tests/Admin` suite green; the role crawl of AD1 now without the switch; no route removed (`debug:router` before/after equal) |

**Order.** AD1 → AD2 → AD3 → AD4 → AD5 → AD6 → AD7 → AD8 → flip → (one month) → AD9. AD7 may go any time after AD1;
its broadcast part needs only AD1's section pages. AD5's extend and add traffic wait for
fix/admin-extend-traffic-on-panel. AD10 and AD11 are independent of the switch and may run in parallel. PM12-style
defects found on the way are fixed in their own PRs, not folded in.

### AD2 implementation notes

- Modern dashboard only; classic dashboard template and AD1 snapshot fixtures/tests are unchanged.
- The current permission matrix grants `PAYMENTS_VIEW` to support as well as admin and super admin.
  AD2 preserves that matrix and the classic dashboard's visibility (including money), as required for this rollout.
  The sentence in 4.5 claiming support sees no money cards does not describe the current code; changing it belongs
  to the separate role/permission work, AD11.
- `StuckPaymentFinder::count()` counts database candidates only, including unfulfilled renewal/add-traffic orders.
  It never calls `classifyRenewal()` or a panel. The existing repair command still performs its panel comparison.
  A new read-only, paginated `/admin/payment-worklist` page combines provisioning failures and these candidates,
  linking to the existing payment detail/retry actions. This intermediary is needed because no existing tab lists
  that union; no dashboard GET performs a repair. Pages hold 100 rows and offer previous/next links.
- Expiring-today opens the existing services list with a `dashboard_day` filter for that exact Tehran day;
  the broader three-day `expiring` tab would not list the same counted items. Unattached inbounds use the existing
  `inbounds=not_connected` filter until AD4 adds the needs-attention tab. Failed moves use `moves=failed`.
- Payment receipts are an additional tab in modern mode only. `PaymentTabs::countByTab($em, true)` opts into its
  count, preserving existing callers' result shape and classic tab output.
- Shared service-tab aggregates remain fresh because the worklist needs them; the KPI/service chart reuses those
  values rather than caching a second copy. Revenue, payment KPI, new-customer KPI and seven-day revenue use
  `cache.app`, 60-second TTL, keyed by Tehran day and sorted effective permission set. Worklist and latest payments
  are uncached; the existing shell badge remains independently cached for 30 seconds. The query budget tests cover both a cold dashboard overview and the full dashboard GET for all three roles,
  including shell/authentication and multi-inbound enabled. Search/list navigation requests are separate.
  To keep the full GET within 12 queries, the cached seven-day CASE query also supplies today/yesterday revenue,
  today's confirmed count and an authorized scalar new-customer subquery; failed moves share the service aggregate.
- Dashboard-only date/number filters implement decision 7 now; rollout of `admin_date` to other pages remains AD8.
  Health/version are omitted unless both real values are supplied (AD3). No health probe runs on dashboard load.
- No indexes or schema migrations added: production-size EXPLAIN is unavailable locally; the existing index review
  in 4.5 remains a staging task. No production database was queried or changed.

### 7.2 The flip (after AD8, not a PR)

The owner sets `admin.ui.layout = modern` in hub → Advanced. Checklist first, on staging with a production copy:

1. Every row of section 2 opens from the modern layout, as each of the three roles, with the same 200/403 as classic.
2. Every write in section 2 still asks its confirm page and writes one audit row (sample: payment confirm, wallet
   adjust, plugin enable, settings save, service delete, broadcast send now from AD7).
3. Dashboard numbers equal the classic dashboard's for the same moment, compared with the same day boundary (classic
   counts from UTC midnight, modern from Tehran midnight, 4.2): service tabs equal at any time; today's orders and revenue
   equal when checked with both set to the same range in a test.
4. Worklist rows each lead to a page that lists exactly the counted items; the badge equals the card-to-card receipts
   row.
5. Service actions (AD5) on a test service on a real panel: suspend, activate, reset, extend, add traffic; the panel
   shows the change and the next sync keeps it (requires fix/admin-extend-traffic-on-panel).
6. A bulk run (AD6) of 3 services and one of 25 (timer path, typed count) finish with per-row results, as super admin;
   an admin gets 403.
7. fa and en, light and dark, phone width (360 px) and desktop: no clipped text, no horizontal scroll; Jalali dates with
   Persian digits in fa, Gregorian in en (AD8).
8. The panel loads with `cdn.jsdelivr.net` blocked.
9. Health lines (AD3) show real values on the production box: jobs, `deployed-version`, `backup-last-success`.
10. Support sees only "resend link" in the modal (AD4).
11. Switching back to classic, and `ADMIN_UI_FORCE_CLASSIC=1`, both restore today's panel at once.

Classic stays available for **one month** after the flip (decision 16); then AD9 removes it.

## 8. Decisions (owner, 2026-11-22)

| # | Decision | Applied in |
|---|---|---|
| 1 | Extend/add traffic must update the panel first: yes, via the separate fix `fix/admin-extend-traffic-on-panel` (bot and Mini App) | Short version, 3.2, 3.3 texts, 7.0, AD5, flip 5 |
| 2 | Preview of the new layout before the flip: super admin only | 5.1, AD1 |
| 3 | Bulk actions: super admin only | 3.4 (`SERVICES_BULK`), AD6, flip 6 |
| 4 | Suspend/activate customer message: keep today's behaviour | 3.3, AD5 |
| 5 | Red badge: card-to-card receipts only; "needs review" is a separate worklist row | 1, 4.1, 4.5, AD1, flip 4 |
| 6 | Bulk limit: 500 services per run, one active run per admin, typed count confirmation above 20 | 3.4, AD6 |
| 7 | Dates: Jalali with Persian digits in Persian, Gregorian in English | 6.3, AD8, flip 7 |
| 8 | Support role: only "resend link" in the modal, no links/QR | 3.1, AD4, flip 10 |
| 9 | Brand name replaces "Amoobot Admin" in header and tab | 1, 5.1, 6.1, AD1 |
| 10 | "Expiring today": calendar day in Asia/Tehran (extended to every dashboard day boundary so one page has one "today") | 4.1, 4.2, 6.3, AD2, flip 3 |
| 11 | Broadcast preview / send now: wire them | 2.6, AD7 |
| 12 | CLI tools in the settings hub: not now | 2.5, AD7 |
| 13 | `deploy.sh` records the deployed commit for the version chip; the FPM user may read job status, the least-privilege way | 0, 4.4 (`deployed-version`, `backup-last-success`, no new rights), AD3 |
| 14 | Global search: a search page first, no typeahead | 4.3, AD2 |
| 15 | Accent colour: the brand colour from Branding | 1, 6.4, AD1 |
| 16 | Classic layout stays one month after the flip, then a removal PR | 7.2, AD9 |
