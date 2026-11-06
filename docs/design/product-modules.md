# Product modules and two brands on one server

Status: **audit and design for review**. Nothing here is built. Base commit: `b485bfb` (tip of `develop/v2`, 2026-11-06).

Phases in this document use the prefix **PM** (PM0, PM1, ...), so they are not confused with the multi-inbound phases
M1-M2e.

**How to read the labels.** "Fact" means read from this repository at the base commit (file and line given) or from
Hetzner's published API spec. "Estimate" and "Opinion" are the author's judgement. Counts come from `grep` and are
line counts, so they show scale and are not exact.

**The two findings that shape everything else:**

1. The module boundary already half exists, one level too low. `VpnPanelDriverInterface` abstracts *which panel*, not
   *which product*. Above it, `VpnService`, `Plan` traffic fields, order metadata and settlement all assume a VPN
   account.
2. Today's provisioning is a synchronous remote call **inside** the settlement transaction, with the order and payment
   rows locked (`src/Payment/Application/Settlement/PaymentSettlementService.php:44,401,561`). That is acceptable for a
   3x-ui panel. It does not fit a cloud server, where creation is an asynchronous Action. The contract in section 2 has
   to change this for new modules, and leave VPN exactly as it is.

## 1. Coupling map

Rating: how hard it is to move behind a module boundary.

- **easy**: a seam exists, or the code only needs to call one interface.
- **medium**: several files or a type change, mechanical, covered by existing tests.
- **hard**: money, concurrency or a data model depend on it; it needs a design of its own.

Scale (fact, lines matching VPN terms / files of the area that match them): Admin 361 / 24 of 76, Command 268 / 25 of
75, MiniApp 188 / 25 of 89, Reseller 163 / 14 of 35, Bot 154 / 15 of 61, Shop 137 / 21 of 53, Payment 63 / 8 of 49,
Subscription 23 / 3 of 16, Broadcast 9 / 1 of 9, Wallet 3 / 1 of 23, Shared 3 / 3 of 13, Branding 0, Plugin 0,
Security 0.

### 1.1 Users, wallet, ledger

| Where | Dependency | Rating |
|---|---|---|
| `src/Entity/User.php`, `src/Entity/TelegramAccount.php` | None on VPN. Coupled to Telegram instead: `telegram_id` is globally unique (`TelegramAccount.php:21`). Matters for section 5(b), not for modules. | easy |
| `src/Wallet/Application/WalletService.php` | None. Only knows users, amounts and keys. | easy |
| `src/Wallet/Application/WalletReconciler.php:9,160-161` | One import: `VpnServiceStatus::SUSPENDED/DELETED` in a reconcile query. | easy |
| `src/Wallet/Domain/WalletKeys.php` | Keys are built from payment and order ids (`purchase:payment:<id>`), product neutral. | easy |

The wallet is the cleanest part of the core. No module work is needed there.

### 1.2 Orders and checkout

| Where | Dependency | Rating |
|---|---|---|
| `src/Entity/Order.php:42` | `targetService` is a FK to `VpnService`. | medium |
| `src/Entity/Order.php:22,24,260` | `planInbounds` / `planInboundsMissing` metadata keys and `planInboundSnapshot()` on the core order. | medium |
| `src/Shop/Domain/OrderType.php` | `ADD_TRAFFIC` is a VPN concept in a core enum; `TRIAL` too in practice. | medium |
| `src/Entity/OrderDraft.php:36,39` | `trafficGb` and `durationDays` columns, and a `step` state machine that starts at `waiting_custom_username`. | hard |
| `src/Shop/Application/Checkout/PlanSaleRule.php`, `src/Shop/Domain/MultiInboundSale.php:53` | The "is this plan sold" rule at every decision point is written in inbounds, and names `sanaei_3xui`. | medium |
| `src/Shop/Application/Checkout/PlanOptions.php`, `NewServiceCheckout.php`, `PlanCatalog.php` | Custom plans mean traffic and days. | medium |
| `src/Shop/Application/Checkout/ServiceOrderCheckout.php:47,126,138,205` | Renewal and add-traffic drafts typed to `VpnService`. | medium |
| `src/Shop/Application/PlanPricingService.php:50`, `TrafficAddonPricingService.php` | Price by GB and day, renewal typed to `VpnService`. | medium |
| `src/Shop/Application/TrialAccountService.php:40`, `src/Shop/Application/Catalog/TrialBackingPlans.php` | Trials provision through `VpnProvisioningService` directly. A trial is VPN only; a free server is an abuse risk (section 6). | medium |
| `src/Shop/Application/Catalog/PlanInbound*.php`, `InboundMoveSettings.php`, `MultiInboundSettings.php` | VPN code placed under `Shop`. It should move into the VPN module. | easy |

### 1.3 Payments

| Where | Dependency | Rating |
|---|---|---|
| `PaymentSettlementService.php:85-87,93-94` | Constructor depends on five VPN services. | hard |
| `PaymentSettlementService.php:524-575` | `fulfil()` returns `?VpnService` and branches on order type, inside the transaction (see 2.3). | hard |
| `PaymentSettlementService.php:450-458` | Post-commit steps are inbound attach and inbound alerts. | medium |
| `src/Payment/Application/Settlement/SettlementResult.php:8` | Result carries a `VpnService`. | medium |
| `src/Payment/Application/PaymentCustomerNotifier.php:68,113,130,397-499` | Delivery messages are config links, subscription line and traffic text. | hard |
| `src/Payment/Application/PaymentDeliveryLog.php:62,180` | Tracked delivery is a row in `service_notification_log`. Reusable, the row has a generic shape. | medium |
| `src/Payment/Application/MissingDeliveryResender.php:8` | Resends config delivery. | medium |
| `src/Payment/Application/Repair/StuckPaymentFinder.php:9`, `RenewalPanelComparator.php:7-9` | Repair logic compares renewal against the panel. | medium |
| `src/Payment/UI/PaymentReturnController.php:14-15` | The return page shows links. | medium |
| `src/Payment/Application/Reconcile/WinapayReconciler.php:16` | Imports only `SecretRedactor`. | easy |

### 1.4 Resellers and claims

| Where | Dependency | Rating |
|---|---|---|
| `src/Reseller/Application/ResellerPricing.php:98,113,130` | Wholesale quotes for new, renewal, add-traffic. | medium |
| `src/Reseller/Application/ResellerCheckout.php:11-16`, `src/MiniApp/Reseller/ResellerPurchases.php` | Same three flows typed to `VpnService`. | medium |
| `src/Reseller/Application/ServiceOwnership.php:13-14,39-63,232-237` | Transfer of ownership rotates panel credentials (`CredentialRotationDriverInterface`). | hard |
| `src/Reseller/Application/ClaimPanelSync.php:9-11` | The panel client's comment follows the owner. | medium |
| `src/Reseller/Application/ClaimLinks.php`, `ClaimService.php`, `SoldServices.php`, `ResellerCustomers.php`, `ResellerRefunds.php` | Written against `VpnService` and its status. | medium |

Claims should not be offered for servers in the first version. Whoever had root keeps root until a rebuild, so a
"claim" can't rotate access the way a VPN UUID can.

### 1.5 Bot

| Where | Dependency | Rating |
|---|---|---|
| `src/Bot/Application/TelegramUpdateHandler.php` (2897 lines) `:207,391,409,673-735,812-898,1634,2756,2821-2833` | Trial menu, custom-traffic steps and "GB" text inside the update router. | hard |
| `src/Bot/Application/ServiceManagementService.php` (1779 lines) | Bot and Mini App share it. 60 lines mention `VpnService`. | hard |
| `src/Bot/Application/TelegramKeyboardFactory.php:118-127,177,558-591,662,671` | Main menu and service detail buttons are VPN actions. | medium |
| `src/Bot/Application/ServiceAction/*` | `ServiceActionInterface` is tagged (`config/services.yaml:42`): a good seam. Actions such as `AddTrafficAction`, `ResetUsageAction`, `ResendConfigAction` are VPN. | easy |
| `src/Bot/Application/VpnAccessMessageFormatter.php`, `QrCodeSender.php`, `SubscriptionPageButton.php` | VPN delivery. | easy |
| `src/Bot/Application/Admin/AdminPanelsHandler.php`, `AdminPlansHandler.php` | Admin bot screens for panels and plans. | easy |
| Bot texts | 654 non-comment PHP lines in `src/Bot` hold Persian text, on top of `translations/bot.*.yaml`. `bot.fa.yaml:257` says "this shop sells VPN". Texts are per template key, so a server bot can override them, but the literals in PHP can't be overridden. | medium |

### 1.6 Mini App

| Where | Dependency | Rating |
|---|---|---|
| `src/MiniApp/Navigation/MiniAppSectionProvider.php`, `MiniAppSections.php`, `CoreSections.php` | Tagged section providers (`app.miniapp_section_provider`): **the seam exists**. | easy |
| `src/MiniApp/UI/MiniAppCustomerController.php:83-133`, `MiniAppServiceOrderController.php:41,62` | Service list and detail, subscription, renew and add-traffic pages. | medium |
| `src/MiniApp/UI/MiniAppCheckoutController.php:76-102`, `src/MiniApp/Checkout/MiniAppCheckout.php` | Renew and add-traffic APIs. | medium |
| `src/MiniApp/UI/MiniAppServiceActionController.php:38` | Actions are a fixed list in the route requirement. | medium |
| `templates/miniapp/view/*`, `templates/miniapp/reseller/*` | 18 templates mention traffic, subscription or inbound. `_service_card.html.twig:1` is built on `SubscriptionPageView`. | medium |
| `src/MiniApp/Guide/*` | The connection guide is VPN only. It lives behind a section, so it can be hidden. | easy |
| `src/MiniApp/Auth/InitDataValidator.php`, sessions | Product neutral. Already takes a **list** of bot tokens (`services.yaml:112`), but does not report which one matched (section 5b). | easy |

### 1.7 Admin panel

| Where | Dependency | Rating |
|---|---|---|
| `src/Admin/UI/DashboardController.php:126,138` | Fixed menu entries for VPN services and panels. | easy |
| `src/Admin/UI/Dashboard/DashboardOverview.php:139-172` | Services and panel health cards. | easy |
| `src/Admin/UI/Crud/PlanCrudController.php` (480 lines) | Plan form is traffic, inbound, custom-traffic pricing. | medium |
| `src/Admin/UI/Crud/VpnServiceCrudController.php` (452 lines) | Service list and actions are VPN. | medium |
| `VpnPanelCrudController`, `VpnInboundCrudController`, `PanelSetupController`, `VpnPanelInboundActionsController`, `ServiceInboundsController`, `InboundMoveAdminController`, `SubscriptionPageLinkController`, `AppDownloadCrudController`, `ClientAppCrudController`, `TrialPlanCrudController`, `TrialClaimCrudController` | Wholly VPN. They move with the module. | easy |
| `src/Admin/Application/SettingsAdministration.php:37-92` | Sales form mixes traffic add-on with general switches; "plans" form is inbounds. | medium |
| `src/Security/AdminPermission.php` | `PANELS_MANAGE`, `APPS_MANAGE` and traffic-flavoured permission text. | easy |

### 1.8 Branding, notifications, jobs, deploy

| Where | Dependency | Rating |
|---|---|---|
| `src/Branding/*`, `src/Shared/Domain/BrandDomainConfig.php:7` | No product dependency. One import of `PublicUrlPolicy` from `App\Provisioning`, which belongs in Shared. | easy |
| `src/Shared/Infrastructure/RedactingLogger.php:7`, `SafeErrorLog.php:7` | `SecretRedactor` lives in `Provisioning/Infrastructure/Sanaei3xui/Modern/Http`, yet the whole app uses it. Move to Shared. | easy |
| `src/Provisioning/Application/ServiceNotificationService.php:19-20,122-173,256` | Expiry warnings are generic. Traffic warnings are VPN. The class mixes both. | medium |
| `src/Broadcast/BroadcastRecipientResolver.php:73-107` | Targets "users with active / expiring service". Works for any product once the service row is generic. | easy |
| `src/Command/AutomationRunCommand.php` | One command runs `orders` (core), `broadcasts` (core), `sync`, `expiry`, `suspend`, `notifications`, `claims`, `attach-inbounds`, `inbound-moves`. | medium |
| `src/Provisioning/Application/ServiceExpiryChecker.php:57-66` | `checkAll()` reads newest-first up to the batch limit (default 100, `AutomationSettingsProvider.php:48`). Services past the limit are not checked, because every status is included. `ServiceAutoSuspendService` is not affected, because it filters on expired rows. A server lifecycle job must not copy this shape. | medium |
| `src/Provisioning/Application/ServiceAutoSuspendService.php:43-49,101-107` | Reads VPN status and calls the driver directly. | medium |
| `deploy.sh:95-105` | `SCHEDULED_JOBS` is a static list. `lifecycle` lock group is VPN flavoured. | easy |
| `docs/`, `README.md`, `bin/smoke-webhook.sh`, `bin/post-deploy-check.sh` | Product neutral apart from wording. | easy |

### 1.9 The VPN data model itself

`VpnService` is referenced by eight other entities: `Order`, `ServiceClaim`, `ResellerCustomerService`,
`ServiceNotificationLog`, `ServiceOwnershipEvent`, `ServiceInboundMove`, `TrialClaim`, `VpnServiceInbound`. The columns
`panel_id` and `inbound_id` are already nullable. `'sanaei_3xui'` appears in 14 places outside `src/Provisioning` and
`src/Command`.

## 2. ProductModule contract

### 2.1 Interface

Sketch, not final code. Names follow the existing tagged-driver style (`app.vpn_driver`, `app.payment_gateway`).

```php
interface ProductModuleInterface
{
    /** 'vpn', 'hetzner_server', ... stored in plan.product_type and the service row. */
    public function type(): string;

    /** The per-product fields of a plan: admin form, validation, how a customer chooses options. */
    public function planSchema(): PlanSchema;

    /**
     * Fulfil a paid order. Idempotent per order: a second call for the same order adopts what exists.
     * Runs in the settlement transaction, so a module with a slow backend returns PENDING without a remote call.
     */
    public function provision(Order $order, ProvisionContext $context): ProvisionResult;

    public function renew(ServiceRecord $service, Order $order): RenewResult;
    public function suspend(ServiceRecord $service, LifecycleReason $reason): LifecycleResult;
    public function resume(ServiceRecord $service): LifecycleResult;
    public function terminate(ServiceRecord $service, LifecycleReason $reason): LifecycleResult;

    /** The remote truth, for sync jobs and admin pages. Batched by the module where the backend allows it. */
    public function status(ServiceRecord $service): ModuleStatus;

    /** What the customer sees: a neutral view for the bot, the Mini App and notifications. */
    public function customerView(ServiceRecord $service, ViewContext $context): CustomerServiceView;

    /** Module-specific blocks of the admin service page. */
    public function adminView(ServiceRecord $service): AdminServiceView;

    /** @return list<CustomerAction> buttons offered now, e.g. reinstall, resend links */
    public function customerActions(ServiceRecord $service): array;
    public function runCustomerAction(ServiceRecord $service, string $action, ActionInput $input): ActionResult;

    /** Long work that belongs to a job: inbound attach, server creation polling. */
    public function scheduledTasks(): array;
}
```

`ServiceRecord` stands for today's `VpnService` entity (section 3 explains why it isn't renamed first).

`ProvisionResult` has three outcomes: `DELIVERED` (today's VPN), `PENDING` (the job completes it) and a thrown exception
(`PROVISIONING_FAILED`, as today).

### 2.2 How the current VPN code maps onto it

| Contract | Today's code | Fit |
|---|---|---|
| `provision` | `VpnProvisioningService::provisionOrder()` (`:68`), idempotent through `orderClientUuid()` (`:54`) and `findAdoptableClient()` (`:346`) | Good. Already the pattern every module should follow. |
| `renew` | `ServiceRenewalService::renew()` (`:23`) | Good for VPN. See 2.3. |
| `suspend` | `ServiceAutoSuspendService`, `ServiceAdministration::suspend()` (`:34`) | Two code paths with different rules: the admin path only calls the panel for `sanaei_3xui` (`:137`), the job always resolves a driver. Wrap each as it stands. |
| `resume` | `ServiceAdministration::activate()` (`:49`), with `ServiceActivationDriverInterface` or a renew with 0 days as fallback | Fits. |
| `terminate` | `ServiceAdministration::delete()` (`:75`), admin only | Fits, but VPN never terminates automatically. |
| `status` | `ServiceUsageSyncService`, `BatchUsageDriverInterface`, `getUsage()` | Fits. Batching stays inside the module. |
| `customerView` | `SubscriptionPageViewFactory`, `FinalConfigLinkProvider`, `VpnAccessMessageFormatter`, `_service_card.html.twig` | Medium. `SubscriptionPageView` is a good start, but its shape is VPN. |
| `adminView` | `VpnServiceCrudController` (452 lines) | Medium. |
| `customerActions` | `ServiceActionInterface` plus `userServiceDetail()` (`TelegramKeyboardFactory.php:558`), `MiniAppServiceActionController.php:38` | Medium. Two lists to unify. |
| `planSchema` | `Plan` columns, `PlanInbounds` constraint, `PlanOptions` | Medium. VPN keeps columns; schema describes them. |
| `scheduledTasks` | `InboundAttachScheduler`, `InboundMoveScheduler`, `ServiceUsageSyncService` | Fits. |

### 2.3 What does not fit

These are the contract's real content. Each needs a decision, not just a wrapper.

1. **The transaction boundary (fact, hard).** `run()` holds `FOR UPDATE` on order and payment across `fulfil()`
   (`PaymentSettlementService.php:325,401`). The class comment says so, and `WalletService.php:135-139` documents a
   real gap-lock incident caused by holding a transaction across the panel call. VPN stays as it is. A new module
   returns `PENDING` from `provision()` with no remote call, and a job does the work outside the transaction.
2. **A paid order with a pending service.** Today `Order::PROVISIONED` and "the service is delivered" are the same
   moment (`:412-415`). `PENDING` needs a service status `provisioning`, which is new to `VpnServiceStatus`. The order
   stays `PROVISIONED` so that the duplicate-payment guard at `:368-387` keeps working. The word becomes slightly
   misleading, so an open question stands (section 9).
3. **Failure after the payment is settled.** VPN retries in `PROVISIONING_FAILED` with the admin. A server that can't be
   created (no capacity) has money taken and nothing to give. It needs a refund path: to the wallet, automatic or
   admin-approved (`WalletKeys::orderRefund()` exists but serves reseller orders only).
4. **`fulfil()` return type and `SettlementResult`.** Both are `?VpnService`. With `VpnService` kept as the record this
   is fine; the interface must not widen it yet.
5. **Renewal requires traffic.** `ServiceRenewalService.php:38` throws when `trafficGb <= 0`. A server plan has none.
   This code stays VPN-only behind `renew()`, and `ServiceOrderCheckout`, which builds the order metadata, needs a
   module hook.
6. **`ADD_TRAFFIC`.** A VPN action that creates an order. "Customer action" doesn't cover it, because it goes through
   payment. The contract needs **paid add-ons** as a concept: `customerActions()` can say "this action is a paid order
   of type X". Server equivalents: resize, extra IP (later).
7. **Trial, claims, multi-inbound, inbound moves, credential rotation.** VPN only. They stay inside the VPN module, and
   the core must not ask about them. Calls such as `inboundAlerts->missingInbounds()` move behind a module
   `afterSettlement()` hook. That hook is missing from 2.1 on purpose, because it should only be added if PM1 proves it
   can't live inside `provision()`'s result.
8. **Expiry is not one thing.** VPN: the panel enforces expiry, the job only updates the row, nothing is ever deleted.
   Server: no enforcement exists at Hetzner, Amoobot is the enforcer, and the billing meter keeps running until delete
   (section 6). So `suspend` and `terminate` must be driven by a core lifecycle policy read from the module (grace
   days, whether to terminate), not by copying `ServiceAutoSuspendService`.
9. **Traffic fields on the service row.** Used by notifications, broadcasts and the dashboard. For a server they are
   null or module data. `suspendTrafficExhaustedServices()` is safe for null limits (`:85-88`), which also shows the
   core logic can ignore them.
10. **One-time secrets.** VPN links can be re-read at any time. A root password can't. Nothing in the delivery flow
    handles "show once" (section 6.3).

## 3. Data model

### 3.1 Decision

**Keep `vpn_service` as the table and `VpnService` as the entity in the first PRs.** Add `product_type`. Rename later,
if ever, as a separate mechanical PR.

Why (opinion, with facts): eight entities reference it, 361 admin lines and 188 Mini App lines match VPN terms, and a
rename at the same time as the boundary move would hide behaviour changes inside a huge diff. The existing suite is the
safety net for "no behaviour change", so the diff should stay reviewable.

### 3.2 Changes

```sql
ALTER TABLE plan        ADD product_type VARCHAR(32) NOT NULL DEFAULT 'vpn', ADD module_config JSON DEFAULT NULL;
ALTER TABLE vpn_service ADD product_type VARCHAR(32) NOT NULL DEFAULT 'vpn';
CREATE INDEX idx_vpn_service_type_status ON vpn_service (product_type, status);
```

- **Common facts stay on the shared row:** owner, order, status, starts/expires, sold-by reseller, public token.
- **VPN-specific columns stay where they are.** They are nullable or harmless for other products. `client_uuid`,
  `sub_id`, `config_links` and traffic columns are simply null on a server row.
- **Module data lives in module tables,** one row per service, for example `hetzner_server` (section 6.2). Not in a JSON
  column on the shared row: lifecycle jobs need indexed columns (`hetzner_id`, `state`, `next_check_at`) and secrets need
  their own narrow handling.
- **Plan options:** `plan.module_config` JSON validated by `planSchema()`. VPN leaves it null and keeps its columns.
  `plan.duration_days`, `price`, `is_active` are shared. `traffic_gb` and `inbound_id` are already nullable.
- **`order_draft`:** `traffic_gb`/`duration_days` remain; a new module stores its choices in the existing `data` JSON
  column (`OrderDraft.php:60`).
- **`Order.type`:** unchanged. `add_traffic` stays a string value only the VPN module uses.

### 3.3 Backward compatibility

- `NOT NULL DEFAULT 'vpn'` gives every existing row the right value without touching it. The default stays in the
  schema: there is no window in which an old code path inserts a row without a type. On MariaDB 10.11 adding a column
  with a default can be done in place; the implementation PR should confirm that on a copy of the production database
  and record the time.
- The migration is additive, so older code would run against the extra columns. A rollback is still not free:
  `check_schema_matches()` (`deploy.sh:682`) stops a deploy when the database has executed migrations the target commit
  does not contain. Going back means restoring the pre-deploy dump (`restore_instructions`), or a forward fix.
- A code path that reads a service of an unknown type must fail closed: the registry throws, the job counts a failure,
  and nothing is suspended or deleted.

## 4. Module registry and enabling

```yaml
# services.yaml
_instanceof:
    App\Module\ProductModuleInterface:
        tags: ['app.product_module']
```

`ProductModuleRegistry` follows `VpnPanelDriverRegistry` and `PaymentGatewayRegistry`. Enabled modules: env
`APP_MODULES=vpn` (default `vpn`), read once.

**Env rather than a Setting (opinion).** A module brings timers, routes and migrations, so turning it on is a deployment
decision. If section 5(b) were chosen this moves to the store row.

A disabled module's **data stays**. Disabling hides and stops; it never deletes. A disabled module with active services
shows one warning on the dashboard, because those services are no longer enforced.

What disappears when `vpn` is off:

| Surface | Off means | Mechanism |
|---|---|---|
| Web menu | VPN services, VPN panels, Apps | `DashboardController::configureMenuItems()` asks the module for entries |
| Web pages | 404 for `/admin/vpn-panel/...`, `/admin/panel-setup/...`, `/admin/apps/...` | Check in `AdminPermissionListener`, which already fails closed for `App\Admin\` |
| Plans | Inbound fields, trial plans, bulk per-GB fields | Form from `planSchema()` |
| Settings | Traffic add-on, "plans" tab | Tabs per module (`AdminTabs::SETTINGS`) |
| Dashboard | Panel health, traffic cards | Sections per module |
| Bot | Trial button, add-traffic, subscription, QR, configs | `TelegramKeyboardFactory`, `ServiceActionResolver` |
| Mini App | Guide, Apps, subscription page, add-traffic | `MiniAppSectionProvider`, route guard |
| Public | `/s/{token}`, `/d/{id}` return 404 | Route condition or controller check |
| Texts | VPN-only templates hidden from Bot Texts | Category per module in `BotContentRegistry` |
| Timers | `sync`, `attach-inbounds`, `inbound-moves` exit 0 quickly with "module off" | See below |

**Timers stay installed.** `bin/check-scheduled-jobs.sh` raises `NEVER` or `STALE` for a job that doesn't succeed, and
`app:automation:run` already handles "skipped by setting" (`AutomationRunCommand.php`). A disabled task should succeed
as a no-op. Making `SCHEDULED_JOBS` conditional adds risk to `deploy.sh` for no gain (opinion).

## 5. Two brands on one server

### 5.0 What is shared either way (facts)

- Same box, same public IP, one PHP-FPM service, one MariaDB server, one nginx. A failure of the box or MariaDB takes
  down both brands. A determined observer can link two domains on one IP.
- `SecretCipher` keys derive from `APP_SECRET`, so one install has one key.
- Provisioning runs inside PHP-FPM requests (Telegram webhook, payment webhook): slow panel or gateway calls occupy
  workers. Under (a) with one pool, or under (b), the brands compete for workers.

### 5.1 (a) MULTI-INSTALL

Same code deployed twice: `/var/www/amoobot` and, for example, `/var/www/amoobot-servers`.

**What already works (facts).** `APP_DIR`, `DOMAIN`, `DB_NAME`, `DB_USER`, `BACKUP_DIR` are configurable, and
`APP_DIR` accepts `/var/www/<name>` (`deploy.sh:1497`). `--env-file` accepts any file directly in `/etc/amoobot`
(`:27`). `bin/check-scheduled-jobs.sh`, `bin/scheduled-job.sh` and `bin/post-deploy-check.sh` already take env overrides
for their paths. `InitDataValidator` has one token per install, which is correct as is. Branding, settings, gateways,
plugins and admin users live in the install's database and `var/`, so they need no change.

**What collides today (facts).** All of these are global names in `deploy.sh`:

| Line | Name | Effect of a second install |
|---|---|---|
| 26-28, 50-54, 69, 74 | `/etc/amoobot/...`, `database.env`, `monitor.env` | Second deploy overwrites the first's `database.env`: **the nightly backup would dump the wrong database** |
| 41, 49, 81 | `/var/lib/amoobot`, mirror, `hsts-enabled` | Shared state |
| 46, 59-63 | `/usr/local/lib/amoobot` | Root-run scripts of one install replaced by the other's deploy |
| 58, 72 | `/usr/local/sbin/amoobot-*` wrappers | `amoobot-backup` etc. point at one `APP_DIR` |
| 71, 115 | `amoobot-check-jobs`, `amoobot-deploy-watchdog` | One unit overwrites the other |
| 76-77 | `/etc/nginx/sites-available/amoobot`, maintenance snippet | Second site replaces the first; maintenance mode hits both |
| 95-105, 331 | `amoobot-job-*` | Same unit names. A second deploy **stops and repoints the first's timers** |
| 106-109 | Log, lock and manifest paths | Shared lock groups: one brand's job skips the other's |
| 112 | `deploy-in-progress` | One deploy pauses both brands' jobs |
| 1396 | `/run/amoobot-deploy.lock` | **Keep global.** Two deploys must not run `apt` at once |
| 1658 | `/etc/sudoers.d/amoobot-deploy` | Rewritten per install |
| nginx render | `limit_req_zone ... zone=amoobot_admin_login`, `/var/log/nginx/amoobot_*` | nginx rejects a second zone of that name, so `nginx -t` fails and the deploy stops (known nginx behaviour, confirm in the PR) |
| backup step | `/etc/cron.d/amoobot-backup`, `/var/log/amoobot-backup.log` | Overwritten |
| tmpfiles, logrotate, supervisor | `amoobot.conf`, `amoobot-jobs`, `amoobot-worker` | Overwritten |
| 40 | `WEB_USER="www-data"` | Both apps run as the same user, so either can read the other's `.env.local.php`, including tokens and `APP_SECRET` |

Other scripts:

- `bin/check-scheduled-jobs.sh:94,97` hardcode the unit prefix.
- `bin/check-scheduled-jobs.sh:118` checks the newest `*_nightly_*` in `BACKUP_DIR`, so a shared directory would let one
  brand's fresh dump hide the other's failure. Each install needs its **own** `BACKUP_DIR`.
- `bin/restore-database.sh:54` refuses while any `php*-fpm` is active, and a restore of one brand then requires stopping
  the other. This one needs a real decision (see Risks).
- The removal loop for old jobs (`deploy.sh`, "Jobs removed from SCHEDULED_JOBS") globs `amoobot-job-*.timer`. With the
  second install's units named `amoobot-<instance>-job-*`, the glob does not match them. That prefix order is therefore
  required, not cosmetic.

**Required changes:**

| Area | Change |
|---|---|
| `deploy.sh` | New `INSTANCE` value. Empty keeps every current name (no migration for the VPN install). Otherwise all names above get `-<instance>`. Machine-wide steps (apt, PHP, composer, MariaDB `sql_mode`, SSH, firewall) stay global and idempotent. |
| Tables | None. A new database with all 52 migrations from empty. |
| Timers | `amoobot-<instance>-job-*`. Offset `OnCalendar` from the other install so `lifecycle` passes don't start together. |
| nginx | Second site file, own server name, certificate, zone names and logs. `fastcgi_pass` to the instance's own pool socket. |
| PHP-FPM | One pool per instance with its own Unix user. Needs `WEB_USER` to become per instance and a pool file written by deploy. |
| Webhooks | Already per install: `TELEGRAM_WEBHOOK_SECRET` in the install's env, `app:telegram:set-webhook` runs in step 17. |
| Mini App initData | No change: one token per install. Menu button set by `app:telegram:set-menu-button` per bot. |
| Settings, branding, payment gateways | No code change. New gateway rows with `callback_base_url` of the new domain. |
| Backups | Per-instance cron, `BACKUP_DIR`, log and `database.env`. Upload directories (`var/downloads`, `var/branding`) are outside the database dump, as today. |
| post-deploy-check, amoobot-check-jobs | Per-instance wrapper with env overrides. New: unit prefix env in `check-scheduled-jobs.sh`. |
| Admin logins | Separate users, separate URL. No shared login. |
| Monitoring | `MONITOR_PING_URL` per install, with its own job names. |

**Estimate:** 4-5 PRs, small to medium, one larger: instance names in `deploy.sh` (150-300 changed lines), scripts,
FPM pool and user, restore rule, runbook. No business-code change.

**Risks:**

- `deploy.sh` is 2493 lines, runs as root, and is the production path. The empty-`INSTANCE` case must render byte-identical
  units and files. `tests/ops/*.test.sh` exist and should prove it.
- Version skew between installs: a migration or fix must be deployed twice. It is also a benefit, because the server
  install can soak a release first.
- Two admin panels, so two places to check. Cross-brand reporting means two logins.
- Restore of one brand can't stop a shared `php-fpm`. Options: per-site maintenance plus a per-pool check, or a separate
  `php-fpm` systemd instance per brand.
- Resource contention is now invisible: two sets of timers, two pools, one RAM.
- Going back later is costly: merging two databases means colliding ids in every table and ledger.

**Test strategy:**

- `tests/ops`: golden render with empty `INSTANCE` equals today's output; a named instance has no global-name leak
  (grep the rendered output for unsuffixed names).
- `deploy.sh --dry-run` for both.
- A rehearsal on a scratch Ubuntu 24 VM: install A, install B, deploy A again, then confirm that B's timers, database
  backup and nginx site were not touched. Run backup and restore on each.
- The app suite needs nothing new.

### 5.2 (b) MULTI-STORE

One install, one admin panel, a `Store` entity. The same Telegram user in two bots is two customers.

#### Required changes

| Area | Change | Facts behind it |
|---|---|---|
| Entity | `store`: slug, status, bot token (encrypted), webhook secrets, admin chat, brand, domain, enabled modules | All in env or `setting` today |
| Tables | `store_id` on nearly all of the 42 tables, new composite uniques | See "Existing data becomes store #1" |
| Telegram | `TelegramApiClient` per store | One token bound at container build (`services.yaml:53`); **42 classes** inject the client |
| Webhooks | `/telegram/webhook/{secret}` resolves the store by secret, then sets context | One secret today (`TelegramWebhookController.php:41`) |
| Mini App initData | Host selects the store; only **that** store's token is tried; session bound to store | `InitDataValidator` accepts any configured token and does not say which (`:56`); `mini_app_session` has no store (`MiniAppSession.php:28`) |
| Domains | Per-store brand and operational hosts; `trusted_hosts` from `store` | One brand pair from env (`BrandDomainConfig`, `BrandTrustedHostsEnvProcessor`); `BrandHostGuard`, `SiteUrls` |
| Settings | `SettingValueProvider` scoped; `key_name` unique per store | A good single point (15 classes inject it), but 9 files use `Setting::class` directly |
| Branding | Logo directory per store; memo must reset | `BrandingSettings.php:28` caches `$current` |
| Bot texts | Unique `(store, key, locale)` | `uniq_bot_message_template_key_locale`, `uniq_bot_button_label_key_locale` |
| Payment gateways | Store on `payment_gateway`; URLs carry the gateway id | See risk 4 |
| Card details | `PAYMENT_CARD_*` env and `payment.card_number` per store | `PaymentStarter.php:40`, `StorePaymentMethodResolver.php:28` |
| Jobs | Loop over stores, each in its own context, batch limit per store | `AutomationRunCommand` has no notion of store |
| Admin | Store switcher, per-store grants, audit log with store | `AdminUser` has none; the voter ignores the subject (`AdminPermissionVoter.php:28`) |
| Alerts | Sent through the right store's bot to that store's admins | `TELEGRAM_ADMIN_CHAT_ID`, `AdminAccessPolicy::telegramRecipients()` |
| deploy.sh | One nginx server per domain or multi-domain certificate; `certbot -d` for all; `app:telegram:set-webhook` for every store | Single `DOMAIN`, `server_name` |
| Timers | Unchanged, but a slow store can use up another's time budget | `lifecycle` group |
| Backups | Unchanged. Whole-DB only: **one brand can't be restored alone** | `bin/restore-database.sh` |
| post-deploy-check, check-jobs | Health for every domain and bot. Job success no longer means "every store ran" | Per-job, not per-store |

**Estimate:** 14-20 PRs, several large. Around 40% of `src/` needs review at least. Order: entity and context, then
schema, then Telegram client, then settings and branding, then Mini App, then gateways, then jobs, then admin, then
deploy. The first brand must work after each step.

#### Fail-closed scoping

No single mechanism is enough. Layers:

1. **`StoreContext`** with no default. Reading it unset throws. Set only by the webhook, host, admin session or job loop.
2. **Doctrine `SQLFilter`** on a `StoreScoped` interface. An unset parameter throws. Limits, which is why the filter alone
   is not acceptable:
   - It does not touch DBAL. Fact: **123** lines call `getConnection()` and **366** lines use raw `fetchOne`,
     `executeStatement` and similar, across 55 files, including the wallet, the reseller code and `InboundMoveRunner`.
   - `find()` returns a managed entity from the identity map with no filter. There are 246 `->find(` lines.
   - `TransactionRollback::resetEntityManager()` creates a new manager after a failure: the settlement and job paths
     use it, and the filter parameter must be restored on it.
   - A filtered to-one proxy throws `EntityNotFoundException`, which turns a leak into a 500. That is the right
     direction but it needs care in admin lists.
3. **Write check.** A `prePersist`/`onFlush` listener sets `store_id` from the context, and refuses a changed entity of
   another store.
4. **Database composite foreign keys** on money tables, for example `order (store_id, user_id)` to `user (store_id, id)`.
   Then a cross-store order is impossible even from raw SQL. This is the strongest guard and should cover `order`,
   `payment`, `wallet`, `wallet_transaction`, `vpn_service`.
5. **No schema default in the end.** After the migration `store_id` has no default, so an `INSERT` that forgets it fails.
6. **Explicit asserts** at the money edges: `PaymentSettlementService::lock()`, `WalletService::write()`.
7. **Architecture tests.** Every entity is `StoreScoped` or on a short `GLOBAL` list; every `getConnection()` caller is on a
   reviewed allowlist. A new entity or raw query fails CI until someone decides.

Non-routed lookups have no context and must derive it from the row, then compare to the host: `/s/{token}`, `/d/{id}`,
`/brand/logo/{name}`, `/payment/return/{token}`, claim links.

#### Wallet lock order and ledger

- **Lock order does not change:** order, then payment, then the user row, then the wallet row (`WalletService.php:36`,
  `:356-378`). Customers are per store, so a user row already means one store. No store-level lock is added. **The
  `store` row is never locked `FOR UPDATE`.**
- A subtle cost: inserting a child row with a FK takes a shared lock on the parent `store` row. Since settlement holds
  its transaction across a panel call, an admin updating the `store` row would wait for that call and then block new
  inserts behind it. So: no frequently updated columns on `store`.
- **Idempotency keys stay global.** They are built from auto-increment payment and order ids, which remain unique across
  stores (`WalletKeys`), so `uniq_wallet_transaction_key` needs no change.
- `wallet_transaction.store_id` is added with `DEFAULT 1` during migration, so **no ledger row is updated**.
  `insertRow()` writes columns by hand (`WalletService.php:276`) and must add it, as the comment there warns. Then the
  default is dropped. `WalletLedgerGuard` applies to Doctrine only, so the migration doesn't conflict with it.
- `WalletReconciler` runs per store, and adds one check: no ledger row's store differs from its wallet's store.
- The wallet stays unique per user (`uniq_wallet_user`). `Wallet::CURRENCY = 'IRT'` is a constant: a store in another
  currency is a separate piece of work (section 9).

#### Existing data becomes store #1

1. Create `store`, insert id 1 from the env and settings of today's install.
2. Add `store_id NOT NULL DEFAULT 1` everywhere, in one migration per group, with the FK. `deploy.sh` takes a pre-deploy
   dump when migrations are pending (`:1985-1992`).
3. Replace unique keys: `telegram_account (store_id, telegram_id)`, `setting (store_id, key_name)`,
   `discount_code (store_id, code)`, `bot_message_template`, `bot_button_label`.
4. Move env values into store 1, encrypted. Keep env as fallback for one release.
5. Keep old webhook and gateway URLs working for store 1, because **pending payments hold old callback URLs**.
6. Add composite FKs and drop defaults last, after a release has run clean.

Tables staying global: `admin_user`, `plugin`, `doctrine_migration_versions`. `admin_audit_log` gets a nullable
`store_id`.

Unknown (needs production numbers): row counts for `bot_message_log` and `wallet_transaction`, which decide how long these
`ALTER`s lock.

#### Cross-store leak risks

1. **Raw SQL** without a store condition (above). Largest risk by count.
2. **Job loops:** a service reused across stores in one process. Memos: `BrandingSettings::$current`,
   `MiniAppSections::$sections`. Each needs `ResetInterface` and a reset between stores.
3. **Wrong bot:** a message sent with store A's token to store B's user fails or, worse, shows the other brand's name.
   `TelegramApiClient` must be obtained from the row's store, never the context, in jobs.
4. **Gateway webhooks.** `activeGateways()` with code `default` returns **every** active gateway of a type, and
   `pluginCode` lookups return every gateway of a plugin (`PaymentWebhookController.php`). `GatewayNotificationProcessor`
   tries each in turn. That is safe today, because lookups are scoped by gateway reference. But the webhook has no store
   context before the lookup, so it runs across stores, by design, and must then assert that the payment's store matches
   the gateway's. New URLs carry the gateway id.
5. **Callback data:** `service_view:123`, `select_plan:12` trust an id. Covered by the filter, unless the entity is
   already managed.
6. **Sessions:** a Mini App token valid in the wrong store. Bind the session row and check host and store on each
   request.
7. **Admin:** autocomplete, batch actions, filters, `CustomerSearch`, CSV export, dashboards, all of which need the
   store. `admin_audit_log` and `bot_message_log` hold PII and must be filtered.
8. **Alerts** to `TELEGRAM_ADMIN_CHAT_ID` naming a customer of the other brand.
9. **Rate limiters and caches** keyed by Telegram id only. Same person, two stores.
10. **A human leak:** one operator, one panel, one mistake on the wrong store. Mitigation: a coloured store banner.

#### Tests that would prove isolation

- `IsolationTestCase`: two stores with the same Telegram id, same plan names, same discount code. Every customer route
  (bot callbacks, Mini App views and APIs) runs as store A against store B's ids and must return 404 or nothing.
- Crawl test: for every admin route, as a store-A admin, no store-B marker string appears in the response.
- Raw SQL: run each allowlisted query class with two stores; assert the row sets.
- Forged writes: direct DBAL inserts of cross-store rows must fail on the composite FK.
- Job tests: each job with two stores; messages go to the right bot client (`MockHttpResponder` records the token).
- Webhook: a store-A gateway signature against a store-B payment reference is refused.
- Migration test on a copy of production: row counts per table unchanged, ledger sum unchanged,
  `app:wallet:reconcile` clean before and after.
- A reset test: after `resetEntityManager()` a query still has the filter.
- The full existing suite must run with one store and pass unchanged. The 2,000-odd tests assume singletons, so a test
  bootstrap with a default store is itself a PR.

### 5.3 Comparison

| Criterion | (a) Multi-install | (b) Multi-store |
|---|---|---|
| Isolation of data (fact) | Separate databases; a leak needs a misconfiguration | Same tables; depends on code being right everywhere |
| Isolation of secrets | Separate with per-instance Unix user; **shared without one** | One `APP_SECRET`, all tokens in one database |
| Isolation of failure in code | A bad deploy can be limited to one brand | Every deploy reaches both brands |
| Shared-box failure | Both down | Both down |
| Business code changed | None | About 40% of `src/` reviewed (estimate); money paths touched |
| Estimated PRs (estimate) | 4-5 | 14-20 |
| Main technical risk | `deploy.sh` regressions | Cross-store leak; money-path regressions |
| Admin experience | Two URLs, two logins | One panel, switcher, per-store roles |
| Cross-brand reporting | Needs two looks, or an export | Built in, possible |
| Operational cost per deploy | Deploy twice | Deploy once |
| Adding a third brand | One env file and a deploy, more RAM | One row plus domain and certificate work |
| Restore one brand only | Possible, with the FPM rule fixed | Not possible without custom tooling |
| Server memory | Two sets of processes | One |
| Version independence | Yes | No |
| Reversibility | Hard to merge later | Hard to split later |
| Fit for "completely separate" business | Matches the shape directly | Separates logically, shares everything underneath |
| Fit for "one admin panel" | No | Yes |

The table is neutral: facts, with estimates marked. Everything below it in this section is opinion.

### 5.4 Recommendation (opinion)

**Choose (a) MULTI-INSTALL.**

- The owner describes two businesses that share nothing but the owner and the machine. (a) gives that by construction,
  while (b) has to rebuild the same separation through hundreds of query sites.
- The risk in (b) lands on the most careful code in the repository: settlement, wallet and reseller paths with raw SQL
  and hand-reasoned locks. A leak of one brand's customers to the other, or a wallet mix-up, is much worse than a
  clumsy second login.
- (a) ships the server business months earlier (by the estimates) and doesn't touch the VPN money paths.
- There are two brands in sight. (b) pays off with many stores, or when a person must operate several at once daily.

**What would change this:** a plan for many brands (say five or more), resellers running their own stores, or the owner
ruling that one login is a hard requirement. Then (b) is the right investment, done as its own project.

**Required with (a):** the per-instance Unix user. Without it the two installs share file access, and the isolation
argument is weaker than it looks.

## 6. Hetzner Cloud module sketch

Source: Hetzner's published OpenAPI spec (`docs.hetzner.cloud/cloud.spec.json`, fetched 2026-11-06), its changelog and
FAQs. Values can change: PM6 confirms them against a real test project.

### 6.1 API calls

| Purpose | Call | Notes (from the spec) |
|---|---|---|
| Catalogue | `GET /server_types`, `/locations`, `/images` | Availability is "only an indicator", "no guarantee" (changelog 2026-04-01) |
| Create | `POST /servers` | Required: `name`, `server_type`, `image`. Accepts `location`, `ssh_keys`, `user_data` (32 KiB), `labels`, `firewalls`, `public_net`, `start_after_create`. Returns `server`, `action`, `next_actions`, `root_password` |
| Find | `GET /servers?label_selector=...` | Also `name`, `status`, `page`, `per_page` |
| Progress | `GET /actions/{id}` | `running` until `success` or `error`. Resource-scoped forms are deprecated (changelog 2026-04-30) |
| Off | `POST /servers/{id}/actions/shutdown`, then `.../poweroff` | Shutdown's action only reflects that the request was sent. Poweroff is a hard cut |
| On | `POST /servers/{id}/actions/poweron` | |
| Reinstall | `POST /servers/{id}/actions/rebuild` | Destroys all data |
| New password | `POST /servers/{id}/actions/reset_password` | Needs a running server with the qemu guest agent |
| Delete | `DELETE /servers/{id}` | Immediate. Attached resources are detached |

Follow changes early: `Link` pagination headers go away on 2027-04-01 (use `meta.pagination`), and `datacenter` was
removed from server requests on 2026-07-01 (use `location`).

### 6.2 Data and idempotency

```text
hetzner_account: id, title, api_token (enc:v1 via SecretCipher), is_active, label_namespace
hetzner_server:  service_id (unique FK), account_id, hetzner_id (nullable until created), name, state,
                 action_id, ipv4, ipv6, location, server_type, next_check_at, attempts, last_error,
                 password_enc (nullable, short-lived), password_expires_at, grace_delete_at
```

**There is no idempotency key in the API** (the term does not appear in the spec). The guard is built from two facts:
the server name is unique per project, and labels are searchable.

1. In the settlement transaction: `provision()` inserts the service (`provisioning`) and `hetzner_server` (`state=queued`),
   with the name derived from the order, such as `amoo-<instance>-o<orderId>`. A retried settlement finds the row, which
   has a unique key on `service_id`, and does nothing.
2. In the job: look up by label `amoobot/order=<id>` first. Adopt if found.
3. Otherwise `POST /servers` with labels `amoobot/order`, `amoobot/service`, `amoobot/instance`, `managed-by=amoobot`.
4. On `409 uniqueness_error`: list by name, verify the labels, adopt. A name match with wrong labels stops for an admin,
   the same rule as `findAdoptableClient()` (`VpnProvisioningService.php:346`).
5. Save `hetzner_id` and `action_id` in their own commit before anything else.
6. Poll with `next_check_at`, not in a loop. On `success`, fetch IPs, set the service `active`, deliver.

**Price check:** the customer paid before the job ran. Before `POST`, compare the plan's server type price and
availability against the order. Payment should also be blocked up front when the type looks unavailable.

### 6.3 Secrets

**API key.** Stored with `SecretCipher`, as panel tokens are (`src/Provisioning/Infrastructure/PanelCredentials.php`).
Tokens are per project, scoped Read or Read & Write. Never show it after saving. Rotating `APP_SECRET` makes it
unreadable, as with panel tokens.

**Root password. Prefer SSH keys.** The password is returned only "when no SSH keys have been specified". Offer the
customer an SSH key first.

When a password is used:

- It appears once, in the `POST /servers` response. A lost response means a lost password, and `reset_password` is the
  recovery (it needs the guest agent, so a custom image may not support it).
- **The current logging would capture it.** `TelegramApiClient` logs every outgoing message to `bot_message_log`
  (`TelegramApiClient.php:39,59,82,...`), and `BotMessageLogger::redact()` masks links and tokens, not passwords. Those
  rows are visible in the admin logs tab. A dedicated send path that logs a placeholder is required, plus a test.
- Add `root_password` to `SecretRedactor` keys, and never put the HTTP body in an exception, a `last_error` or
  `Payment.provisioningError`.
- If delivery can't happen in the same step: `password_enc` encrypted, at most 24 hours (proposal), cleared on delivery
  or expiry. Clear it on read, in the same transaction as recording the delivery.
- Telegram keeps chat history. Better: the message links to a Mini App one-time reveal, and tells the customer to change
  the password. Opinion: default to the reveal.
- The database dump contains `password_enc` while it exists; another reason for the short window.

### 6.4 Lifecycle

| Stage | Trigger | Action |
|---|---|---|
| Reminders | Days before expiry | Existing expiry notification |
| Expired | `expires_at` passed | Service `expired`. `shutdown`, then `poweroff` after a timeout. Customer told, with delete date |
| Grace | `grace_delete_at` not passed | Server off. Renewal allowed, `poweron` on payment |
| Delete | Grace over | `DELETE /servers/{id}`, service `deleted` |

**Billing fact:** a powered-off server is billed until deleted ("regardless of whether it is turned on or not"), so
every day of grace costs the owner. Usage is rounded up to whole hours; Primary IPs are billed separately until deleted.
Grace length and whether customers pay for it are business decisions (section 9).

**The job must re-verify power state.** Hetzner's FAQ notes that a powered-off server can in rare cases be powered on
again after a live migration. Otherwise expired servers run for free.

**Guards before any `DELETE`,** because it is irreversible and customer data is gone:

- Status and dates re-read under a row lock.
- No open renewal order or pending payment for the service (`ServiceOrdersInFlight` is the VPN equivalent).
- The remote server's labels match this service and instance; id and name both match.
- A per-run cap, such as 5 deletes. Above it, stop and alert. This is the circuit breaker for a clock or data bug.
- Never delete from a list or by selector. One id at a time.
- The job never touches a server without `managed-by=amoobot`.
- A `--dry-run`, and a first release with delete off (`manual approve`).

### 6.5 Rate limits

Default **3600 requests per hour per project**, recovering one per second. Bursts are allowed. Headers `RateLimit-Limit`,
`RateLimit-Remaining`, `RateLimit-Reset`; exceeded means `429 rate_limit_exceeded`.

- Read `RateLimit-Remaining` on each response. Stop the pass below a floor (say 200) and leave time for creates and
  power actions.
- Sync through one `GET /servers?label_selector=managed-by=amoobot` with paging, not one call per server.
- Poll actions by `next_check_at`, at 10 seconds or more, as Hetzner advises against frequent polling.
- One job at a time per account, in a new lock group (`modules`), not `lifecycle`. A Hetzner stall must not delay VPN
  suspend.

### 6.6 Failure handling

| Case | Codes | Handling |
|---|---|---|
| Retry later | `429`, `500 server_error`, `502 bad_gateway`, `503 unavailable`, `504 timeout`, `409 conflict`, `423 locked`, network errors | Backoff through `next_check_at`, capped attempts, then alert |
| Capacity | `412 resource_unavailable`, `422 placement_error` | Try the plan's fallback location if one is set. Else alert and go to the refund path (2.3). Hetzner's FAQ says some location blocks lift by the next day |
| Account limit | `403 resource_limit_exceeded` | Stop new creates for the account. Alert. Limit increases need a request, and are possible only after a month and a paid first invoice |
| Maintenance | `403 maintenance` | Retry later |
| Auth | `401 unauthorized`, `403 forbidden` | Stop all work on the account. Alert once |
| Bad request | `422 invalid_input`, `400 json_error` | No retry. Alert. A code or catalogue bug |
| Server gone | `404` on a known id | Reconcile, never recreate automatically. Alert |
| Unknown result | Timeout during `POST` | Next pass adopts by label (6.2) |
| Stuck | `running` for longer than a limit | Alert |

**Reconcile job:** compare services to `GET /servers`. Paid-for-nothing (a server with no service) and
charged-with-no-service (a service with no server) both alert. Automatic delete is not allowed here.

Secrets and customer data stay out of `last_error`, following `ServiceErrorText::redact()`.

### 6.7 Abuse and account separation

- **A separate Hetzner account from production. Hard requirement.** Resource limits are per account, tokens per project.
  An abuse case or a suspension must not reach the box hosting Amoobot. Note that **the "same server" decision in this
  document makes this box a single point of failure for both brands**, and this account must not be the production one.
- Limits: account-level default server limits (visible at console.hetzner.com/limits, no number published), a default of
  20 projects per account.
- Typical abuse from rented servers: spam, port scans, crypto-mining, attacks. Complaints go to the account holder.
- Controls: no trials, wallet or card only after a minimum trust level (policy decision), a per-customer server cap, a
  Hetzner Firewall applied at creation (`firewalls`), an admin kill switch (`suspend` with reason `abuse`, no customer
  self-resume), and a log of who owned which IP and when (`hetzner_server` keeps it after delete).
- A "stop all new orders" switch: `sales.new_orders_enabled` already exists (`SalesSettingsProvider`).
- Reselling terms and customer location rules: check Hetzner's terms before launch (section 9). This document makes no
  claim about them.

## 7. PR plan

Sizes and risks are estimates. Every PR needs `php bin/phpunit` against MariaDB 10.11, PHPStan level 5 and
`lint:container` (`.github/workflows/ci.yml`).

| Phase | Content | Risk | Test plan |
|---|---|---|---|
| **PM0** | These two documents | None | Review |
| **PM1a** | Move `SecretRedactor`, `PublicUrlPolicy` to Shared; add `ProductModuleInterface`, registry, `VpnProductModule` delegating to existing classes, unused | Low | Existing suite unchanged; contract test on `VpnProductModule` |
| **PM1b** | `PaymentSettlementService::fulfil()` goes through the registry. VPN still `DELIVERED`, in the transaction | **High** (money path, small diff) | No test file changes. A new test pins call order; existing `tests/Payment` is the proof |
| **PM1c** | Suspend, resume, terminate, expiry jobs through the contract | Medium | Suite unchanged |
| **PM1d** | `customerView` / `customerActions` for bot and Mini App, VPN output byte-identical | Medium | Suite unchanged; snapshot tests of bot messages first, in a separate preceding PR |
| **PM2** | `product_type`, `module_config`; unknown type fails closed | Low | Migration test on a copy of production; old rows read as `vpn` |
| **PM3** | `APP_MODULES`, gating of menu, bot, Mini App, routes, timers no-op | Medium | Suite with `vpn` unchanged; new suite with VPN off, no 500 and no VPN text in any customer route |
| **PM4a** | `INSTANCE` in `deploy.sh` and scripts; empty changes nothing | **High** (root, production) | `tests/ops` golden comparison; `--dry-run` |
| **PM4b** | Per-instance FPM pool, Unix user, nginx names | High | Scratch VM rehearsal |
| **PM4c** | Backups, restore rule, check-jobs, post-deploy-check per instance | Medium | Restore rehearsal for each brand |
| **PM4d** | Runbook; install the second, empty brand (no module yet) | Medium | Real install on the box; first brand's jobs, backup and webhook confirmed untouched |
| **PM5** | Core support for `PENDING`: status `provisioning`, job task, one-time secret delivery path, refund path. VPN never uses it | **High** | Fake module end to end; retries, crash between steps; password never in `bot_message_log` or logs |
| **PM6** | Hetzner client, redaction, read-only commands; spike against a test project confirms section 6 | Low | `MockHttpResponder`; recorded responses |
| **PM7** | `provision`, adoption, polling, delivery | High | Every 6.6 row; lost-response adoption |
| **PM8** | Lifecycle, reconcile, delete guards; delete behind a switch, off | **High** (irreversible) | Each guard has a test that proves it blocks; dry-run on staging |
| **PM9** | Customer actions: reinstall, password reset, power | Medium | Ownership and rate limits on actions |
| **PM10** | Admin pages, plan schema, abuse switch | Medium | `tests/Admin` patterns |
| **PM11** | Soak with internal orders, then limited sale; delete switch on last | Medium | Checklist; real money, small amounts |

If section 5 chooses (b), PM4 is replaced by its own series (14-20 PRs) and PM5 onward are unchanged.

PM1 order matters. PM1b is the smallest diff with the largest blast radius, so it goes alone.

## 8. What this audit did not check

- No production data: row counts, table sizes, real RAM headroom on the box, and PHP-FPM worker counts are unknown.
- No code was run. Counts are `grep` results, not a parse. The Doctrine filter limits in 5.2 and the nginx zone
  collision in 5.1 are from knowledge of those tools, not tested here.
- Hetzner's facts were read from the spec and docs, not exercised. Section 6 is a sketch.
- Estimates assume the working style of this repository: small reviewed PRs with tests.

## 9. Open questions

**Business**

1. Which option in section 5? (The recommendation needs the owner's decision. The main trade-off is one login against
   isolation and earlier launch.)
2. How many brands in the next two years? Will resellers ever run their own?
3. Is "same server" final? Both brands fail together, and share an IP. A small second VM would remove most of section
   5.1's work. Is that excluded by cost or by choice?
4. Must the two brands be unlinkable to an outside observer (separate IP, separate operator identity)?
5. Currency of the server store: Toman (`Wallet::CURRENCY` is fixed to `IRT`) while costs are in EUR? Who absorbs exchange
   rate changes, and how often do prices change?
6. Do the server brand's customers pay monthly only, or also hourly?
7. Grace period after expiry, and who pays for it, given that off servers are billed?
8. Refund rule when a paid server can't be created: wallet automatically, or admin decides?
9. Hetzner's terms: is reselling allowed as planned, and are there customer-location restrictions that affect this
   customer base? Needs reading by the owner, ideally with advice.
10. Which account or company holds the Hetzner account, and how is it paid?
11. Abuse policy: trust level before ordering, per-customer limit, identity checks?
12. Should the server brand have resellers and discounts in the first version? (Assumed: no resellers, no claims, no
    trials.)
13. Which support model for customers locked out of a server? Rescue mode, console access, or reinstall only?

**Technical**

14. SSH key only, password only, or both? Opinion: both, default key.
15. Images offered: official only, or snapshots with hardening?
16. Add the qemu guest agent requirement for `reset_password`? Which official images have it needs a test (PM6).
17. Should the Mini App show the one-time password reveal, or only the bot?
18. Should the `Order.status` value `provisioned` be split, or is "paid and queued" acceptable under that name?
19. Rename `VpnService` to a neutral name after PM3, or keep it for good?
20. Later modules: files and licenses have no lifecycle backend. Marzban and PasarGuard are closer to a new
    `VpnPanelDriverInterface` driver than a new module, but `Sanaei3xui` specifics in `PlanSaleRule` and multi-inbound
    need checking. Confirm before designing them.
21. Is a daily report or merged view across brands needed under (a)?
22. Can production table sizes be provided, to time the migrations?
23. Is there RAM for a second FPM pool, and two sets of jobs, on the current box?
