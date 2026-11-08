# Product modules, provider drivers and two brands on one server

Status: **design for review (PM0b)**. Nothing here is built. PM0 was written at `b485bfb` (tip of `develop/v2`,
2026-11-06). This revision, PM0b, is written at `82a109d` (tip of `develop/v3`, 2026-11-08).

**Line numbers.** Citations in sections 0, 2, 3, 4, 6, 7 (except 7.9), 8 and 9 were read at `82a109d`. The coupling
map (section 1), the two-brands analysis (section 5) and the Hetzner worked example (7.9) are kept from PM0 with their
`b485bfb` line numbers. They show scale and location; re-check them before editing code there. Example: the settlement
lock and `fulfil()` are now at `PaymentSettlementService.php:542,562`, not `:401,561`.

Phases in this document use the prefix **PM** (PM0, PM1, ...), so they are not confused with the multi-inbound phases
M1-M2e. `docs/design/admin-panel-audit.md` continues the numbering with PM12-PM19.

**How to read the labels.** "Fact" means read from this repository (file and line given) or from a provider's or panel's
published documentation or source (URL and version given in sections 6 and 7). "Estimate" and "Opinion" are the
author's judgement. "UNCONFIRMED" means the source could not be read or did not say. Counts come from `grep` and are
line counts, so they show scale and are not exact.

## 0. What PM0b changes

The owner corrected PM0 on six points. This revision follows them:

| Owner's correction | Where it lands |
|---|---|
| Hetzner was only an example; the design must be generic | Section 7 is a generic server-driver contract. Hetzner is one worked example (7.9). No first server driver is chosen |
| Two layers: a **product module** (what is sold: `vpn`, `server`; later `file`, `license`) and a **provider driver** (what builds it, per module) | Section 2 is rewritten around the two layers |
| VPN drivers: Sanaei 3x-ui now, Marzban and PasarGuard later. Server drivers: any provider | Section 6 (VPN contract mapped to all three panels), section 7 (server contract compared across six providers) |
| Everything else stays the same: users, wallet, ledger, payments, orders, resellers, bot, Mini App, admin, branding, jobs | Section 2.1 "What the core sees" |
| Modules and drivers are installable and can be enabled and disabled like payment plugins, independent of payment gateways. Gateways are chosen per install | Section 4. Section 5 intro |
| Two brands = **multi-install** on the same server | Section 5 marks (a) as chosen |
| `develop/v3` is deployed to production: every PR is behaviour-neutral, or behind a switch that is off by default | Section 9: every PR names its switch, or why it needs none |

**The findings that shape everything else** (PM0's two, still true, plus one new):

1. The driver boundary already exists, one level too low and VPN-shaped. `VpnPanelDriverInterface`
   (`src/Provisioning/Domain/VpnPanelDriverInterface.php:13-33`) abstracts *which panel*, and its optional abilities are
   already separate interfaces checked with `instanceof` (24 checks in 12 files, for example
   `InboundMoveRunner.php` 8, `ServiceOwnership.php` 3). That is a capability system without the name. Above the
   driver, `VpnService`, `Plan` traffic fields, order metadata and settlement all assume a VPN account. **The new
   module layer goes above it. The driver layer is renamed, not invented.**
2. Provisioning is a synchronous remote call **inside** the settlement transaction, with the order and payment rows
   locked (`PaymentSettlementService.php:40-61`, `lock()` at `:542`, `fulfil()` at `:562`). That is acceptable for a VPN
   panel. It does not fit a cloud server, whose creation is asynchronous at every provider read (section 7.1).
3. **The payment plugin system can load code but cannot host a module.** A plugin's class is built with `new` and gets
   no services (`PaymentGatewayRegistry.php:175`). Its `templates/` and `translations/` directories are accepted
   (`PluginManager.php:17-24`) but nothing loads them, and its declared `permissions` are stored and shown but not
   enforced (`PaymentGatewayModuleRegistry.php:412`). The one plugin that needed a timer (Tetraminator) had its
   reconciler and timer added to the core (`TetraminatorReconciler.php:44`, `deploy.sh:95-99,110`). Section 4 builds
   on that system rather than around it, and draws a line: **drivers can be packages; modules are built in for now.**

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
| `src/Shop/Application/TrialAccountService.php:40`, `src/Shop/Application/Catalog/TrialBackingPlans.php` | Trials provision through `VpnProvisioningService` directly. A trial is VPN only; a free server is an abuse risk (section 7.8). | medium |
| `src/Shop/Application/Catalog/PlanInbound*.php`, `InboundMoveSettings.php`, `MultiInboundSettings.php` | VPN code placed under `Shop`. It should move into the VPN module. | easy |

### 1.3 Payments

| Where | Dependency | Rating |
|---|---|---|
| `PaymentSettlementService.php:85-87,93-94` | Constructor depends on five VPN services. | hard |
| `PaymentSettlementService.php:524-575` | `fulfil()` returns `?VpnService` and branches on order type, inside the transaction (see 2.5). | hard |
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

## 2. Two-layer architecture

### 2.1 The layers and who calls whom

```text
 core (unchanged in role)                       product module (one per product type)        provider driver (per module)
 ─────────────────────────                      ──────────────────────────────────────        ────────────────────────────
 settlement, checkout, renewal checkout ──────▶ ProductModuleInterface                 ─────▶ VpnDriverInterface     (3x-ui, Marzban, PasarGuard)
 bot, Mini App, notifications, broadcasts       'vpn'    VpnProductModule (built in)          ServerDriverInterface  (any provider)
 admin service and plan pages                   'server' ServerProductModule (built in)       ... one contract per module
 automation jobs (lifecycle policy)             later: 'file', 'license'
                    │                                         │                                        │
                    └─── ProductModuleRegistry ───────────────┴── ProviderDriverRegistry ──────────────┘
                         (by product_type)                        (by module + driver code, per connection)
```

Rules (opinion, each one a review check for later PRs):

1. **The core calls only `ProductModuleInterface`,** found by `plan.product_type` or the service row's `product_type`
   through `ProductModuleRegistry`. The core never sees a driver, a connection's credentials or a remote id.
2. **A module calls only its own driver contract,** found through `ProviderDriverRegistry` from the plan's connection
   (section 3.3). A VPN module never calls a server driver.
3. **A driver is a remote-API adapter and nothing else.** It gets DTOs and a `DriverContext` (section 4.4), and returns
   DTOs. It never opens a transaction, locks or flushes a row, sends a Telegram message, writes the ledger or reads a
   `Setting`. That is how today's drivers already behave: `Sanaei3xuiModernDriver` takes a remote id and a `VpnPanel`
   and returns DTOs such as `CreatedVpnService` and `VpnUsage`
   (`src/Provisioning/Infrastructure/Sanaei3xui/Modern/Sanaei3xuiModernDriver.php:91,870`).
4. **Capabilities are declared by the driver, per connection, and read by the module.** The module decides what a
   capability means for the product (for example "no `multi_inbound`" hides additional-inbound fields on the plan
   form). The core asks the module, never the driver (section 6.3).
5. **Money stays in the core.** Prices, discounts, wallet, ledger, refunds, reseller pricing and the order state
   machine do not move. A module can say "this action is a paid add-on of order type X" (2.5 item 6), and the core
   prices and settles it.

**What the core sees.** One module per product type, one service row per sold thing (today `vpn_service`, section 3),
and these neutral results: `ProvisionResult` (`DELIVERED`, `PENDING`, or an exception), `LifecycleResult`, a
`CustomerServiceView` for bot, Mini App and notifications, an `AdminServiceView`, a list of `CustomerAction`s, a plan
schema, a lifecycle policy, and scheduled tasks. Everything else about the product lives in the module and its driver.

**What does not change** (owner's rule): users, wallet, ledger, payments and gateways, orders, discounts, resellers
(pricing and sales; claims are a module feature, see 6.4), bot routing, Mini App shell and sessions, admin shell and
permissions, branding, the job runner and its timers, deploy. These call the module interface where they call VPN code
today (the coupling map, section 1, lists the places).

### 2.2 `ProductModuleInterface`

Sketch, not final code. Names follow the existing tagged-service style (`app.vpn_driver`, `app.payment_gateway`,
`config/services.yaml:41-47`).

```php
interface ProductModuleInterface
{
    /** 'vpn', 'server', ...: stored in plan.product_type and on the service row. */
    public function type(): string;

    /** Contract version this module implements; the registry refuses a mismatch (section 4.3). */
    public function contractVersion(): int;

    /** The per-product plan fields: admin form, validation, what a customer chooses. Merges the driver's plan schema. */
    public function planSchema(Connection $connection): PlanSchema;

    /** Why this plan can't be sold right now (connection down, capability missing, switch off); null = sellable. */
    public function saleBlocker(Plan $plan): ?SaleBlocker;

    /**
     * Fulfil a paid order. Idempotent per order: a second call for the same order adopts what exists.
     * Runs in the settlement transaction, so a module with a slow backend returns PENDING without a remote call.
     */
    public function provision(Order $order, ProvisionContext $context): ProvisionResult;

    public function renew(ServiceRecord $service, Order $order): RenewResult;
    public function suspend(ServiceRecord $service, LifecycleReason $reason): LifecycleResult;
    public function resume(ServiceRecord $service): LifecycleResult;
    public function terminate(ServiceRecord $service, LifecycleReason $reason): LifecycleResult;

    /** Grace days, whether expiry suspends or terminates, whether the backend enforces expiry itself. */
    public function lifecyclePolicy(ServiceRecord $service): LifecyclePolicy;

    /** The remote truth, for sync jobs and admin pages. Batched by the module where the driver allows it. */
    public function status(ServiceRecord $service): ModuleStatus;

    public function customerView(ServiceRecord $service, ViewContext $context): CustomerServiceView;
    public function adminView(ServiceRecord $service): AdminServiceView;

    /** @return list<CustomerAction> buttons offered now; a paid one names the order type it creates */
    public function customerActions(ServiceRecord $service): array;
    public function runCustomerAction(ServiceRecord $service, string $action, ActionInput $input): ActionResult;

    /** @return list<ScheduledTask> long work run by the core job runner (section 4.5) */
    public function scheduledTasks(): array;
}
```

`ServiceRecord` stands for today's `VpnService` entity (section 3 explains why it isn't renamed first).
`ProvisionResult` has three outcomes: `DELIVERED` (today's VPN), `PENDING` (a job completes it) and a thrown exception
(`PROVISIONING_FAILED`, as today).

### 2.3 Provider drivers, per module

There is **no single driver interface across modules**: a VPN panel and a cloud API have nothing useful in common
beyond identity and health. Each module defines its driver contract, and all contracts share one small base:

```php
interface ProviderDriverInterface
{
    /** Stable code: 'sanaei_3xui', 'marzban', 'pasarguard', 'hetzner_cloud', ... (unique within its module). */
    public function code(): string;

    /** The module this driver serves: 'vpn' or 'server'. */
    public function module(): string;

    public function contractVersion(): int;

    /** Fields of a connection (URL, token, project, ...): the same schema format as payment plugins' configSchema. */
    public function connectionSchema(): ConfigSchema;

    /** Fields this driver adds to a plan (inbounds, server type, location, image, ...). */
    public function planSchema(Connection $connection): ConfigSchema;

    /** What this connection can do. May depend on the remote side's version (3x-ui modern vs legacy). */
    public function capabilities(Connection $connection): CapabilitySet;

    /** One cheap, read-only call. Classifies the failure: auth, network, version, rate_limited, other. */
    public function healthCheck(Connection $connection): HealthReport;
}
```

The VPN contract (section 6) and the server contract (section 7) extend it. A driver that lacks an optional capability
does not implement the method's interface, as today. The `CapabilitySet` is the declared, testable list. The contract
test kit (section 8) checks that the two agree.

**Today's code mapped onto the two layers (fact):**

| Today | Becomes |
|---|---|
| `VpnPanelDriverInterface` (`src/Provisioning/Domain/VpnPanelDriverInterface.php:13`) and its ten optional interfaces in `src/Provisioning/Domain/*DriverInterface.php` | `VpnDriverInterface` extends `ProviderDriverInterface`. The optional interfaces stay as they are and each one maps to a capability (6.3) |
| `VpnPanelDriverRegistry::resolve()`: first driver whose `supports($panel)` is true (`VpnPanelDriverRegistry.php:19-28`) | `ProviderDriverRegistry::driverFor(Connection)`, keyed by `(module, driver code)`, no ordering. The 3x-ui modern/legacy split, today two `supports()` answers on the same `type` (`Sanaei3xuiModernDriver.php:81-84`, `Sanaei3xuiDriver.php:39-42`), becomes two driver codes, or one code whose capabilities depend on the probe (section 6.3; open question 13) |
| `DummyVpnPanelDriver` (`supports(null)` or type `dummy`, `DummyVpnPanelDriver.php:16-18`) | Built-in VPN driver `dummy`, still for development. The test fake is separate (section 8) |
| `VpnProvisioningService`, `ServiceRenewalService`, `ServiceAdministration`, `ServiceAutoSuspendService`, `ServiceUsageSyncService`, link and view classes | The body of `VpnProductModule`, delegated to as they are (2.4) |
| `VpnPanel` (`src/Entity/VpnPanel.php`) | The VPN module's **connection** (section 3.3) |

### 2.4 How the current VPN code maps onto the module contract

| Contract | Today's code | Fit |
|---|---|---|
| `provision` | `VpnProvisioningService::provisionOrder()` (`:68`), idempotent through `orderClientUuid()` (`:54`) and `findAdoptableClient()` (`:346`) | Good. Already the pattern every module should follow. |
| `renew` | `ServiceRenewalService::renew()` (`:23`) | Good for VPN. See 2.5. |
| `suspend` | `ServiceAutoSuspendService`, `ServiceAdministration::suspend()` (`:34`) | Two code paths with different rules: the admin path only calls the panel for `sanaei_3xui` (`:137`), the job always resolves a driver. Wrap each as it stands. |
| `resume` | `ServiceAdministration::activate()` (`:49`), with `ServiceActivationDriverInterface` or a renew with 0 days as fallback | Fits. |
| `terminate` | `ServiceAdministration::delete()` (`:75`), admin only | Fits, but VPN never terminates automatically. |
| `status` | `ServiceUsageSyncService`, `BatchUsageDriverInterface`, `getUsage()` | Fits. Batching stays inside the module. |
| `customerView` | `SubscriptionPageViewFactory`, `FinalConfigLinkProvider`, `VpnAccessMessageFormatter`, `_service_card.html.twig` | Medium. `SubscriptionPageView` is a good start, but its shape is VPN. |
| `adminView` | `VpnServiceCrudController` (452 lines) | Medium. |
| `customerActions` | `ServiceActionInterface` plus `userServiceDetail()` (`TelegramKeyboardFactory.php:558`), `MiniAppServiceActionController.php:38` | Medium. Two lists to unify. |
| `planSchema` | `Plan` columns, `PlanInbounds` constraint, `PlanOptions` | Medium. VPN keeps columns; schema describes them. |
| `scheduledTasks` | `InboundAttachScheduler`, `InboundMoveScheduler`, `ServiceUsageSyncService` | Fits. |

### 2.5 What does not fit

These are the contract's real content. Each needs a decision, not just a wrapper.

1. **The transaction boundary (fact, hard).** `run()` holds `FOR UPDATE` on order and payment across `fulfil()`
   (`run()` at `PaymentSettlementService.php:354`, `lock()` at `:542`). The class comment says so, and `WalletService.php:134-138` documents a
   real gap-lock incident caused by holding a transaction across the panel call. VPN stays as it is. A new module
   returns `PENDING` from `provision()` with no remote call, and a job does the work outside the transaction.
2. **A paid order with a pending service.** Today `Order::PROVISIONED` and "the service is delivered" are the same
   moment (`PaymentSettlementService.php:450-453`). `PENDING` needs a service status `provisioning`, which is new to `VpnServiceStatus`. The order
   stays `PROVISIONED` so that the duplicate-payment guard at `:406-425` keeps working. The word becomes slightly
   misleading, so an open question stands (section 11).
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
   of type X". Server equivalents: resize, extra IP (later). The core prices and settles it; the module only names it (2.1 rule 5).
7. **Trial, claims, multi-inbound, inbound moves, credential rotation.** VPN only. They stay inside the VPN module, and
   the core must not ask about them. Calls such as `inboundAlerts->missingInbounds()` move behind a module
   `afterSettlement()` hook. That hook is missing from 2.2 on purpose, because it should only be added if PM1 proves it
   can't live inside `provision()`'s result.
8. **Expiry is not one thing.** VPN: the panel enforces expiry, the job only updates the row, nothing is ever deleted.
   Server: no provider enforces the sold term, Amoobot is the enforcer, and with the providers read the meter runs until delete
   (section 7.2). So `suspend` and `terminate` must be driven by a core lifecycle policy read from the module (grace
   days, whether to terminate), not by copying `ServiceAutoSuspendService`.
9. **Traffic fields on the service row.** Used by notifications, broadcasts and the dashboard. For a server they are
   null or module data. `suspendTrafficExhaustedServices()` is safe for null limits (`ServiceAutoSuspendService.php:85`), which also shows the
   core logic can ignore them.
10. **One-time secrets.** VPN links can be re-read at any time. A root password can't. Nothing in the delivery flow
    handles "show once" (section 7.6).

## 3. Data model and connections

### 3.1 Decision

**Keep `vpn_service` as the table and `VpnService` as the entity in the first PRs.** Add `product_type`. Rename later,
if ever, as a separate mechanical PR.

Why (opinion, with facts): eight entities reference it, 361 admin lines and 188 Mini App lines match VPN terms, and a
rename at the same time as the boundary move would hide behaviour changes inside a huge diff. The existing suite is the
safety net for "no behaviour change", so the diff should stay reviewable.

### 3.2 Changes

```sql
-- PM2a: additive, defaults reproduce today exactly
ALTER TABLE plan        ADD product_type VARCHAR(32) NOT NULL DEFAULT 'vpn', ADD module_config JSON DEFAULT NULL;
ALTER TABLE vpn_service ADD product_type VARCHAR(32) NOT NULL DEFAULT 'vpn';
CREATE INDEX idx_vpn_service_type_status ON vpn_service (product_type, status);

-- PM5b: connections for non-VPN modules (VPN keeps vpn_panel, 3.3)
CREATE TABLE provider_connection (
  id INT AUTO_INCREMENT PRIMARY KEY,
  module VARCHAR(32) NOT NULL, driver VARCHAR(64) NOT NULL, title VARCHAR(255) NOT NULL,
  config JSON NOT NULL,                     -- non-secret fields from the driver's connectionSchema
  secrets LONGTEXT DEFAULT NULL,            -- enc:v1 (SecretCipher) JSON of the schema's password fields
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  limits JSON DEFAULT NULL,                 -- per-connection caps (3.4)
  consecutive_failures INT NOT NULL DEFAULT 0, degraded_since DATETIME DEFAULT NULL,
  last_health_at DATETIME DEFAULT NULL, last_health_status VARCHAR(64) DEFAULT NULL,
  created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL
);
ALTER TABLE plan ADD connection_id INT DEFAULT NULL;   -- non-VPN plans; VPN plans keep inbound_id -> panel

-- PM6: the server module's own row per service (generic across server drivers)
CREATE TABLE server_instance (
  service_id INT NOT NULL UNIQUE, connection_id INT NOT NULL, driver VARCHAR(64) NOT NULL,
  remote_id VARCHAR(128) DEFAULT NULL,      -- null until created
  idempotency_label VARCHAR(128) NOT NULL,  -- derived from the order, 7.3
  state VARCHAR(32) NOT NULL, operation_ref VARCHAR(128) DEFAULT NULL,
  ipv4 VARCHAR(45) DEFAULT NULL, ipv6 VARCHAR(64) DEFAULT NULL,
  location VARCHAR(64) DEFAULT NULL, size VARCHAR(64) DEFAULT NULL, image VARCHAR(128) DEFAULT NULL,
  driver_data JSON DEFAULT NULL,            -- driver-specific, never secrets
  secret_enc LONGTEXT DEFAULT NULL, secret_expires_at DATETIME DEFAULT NULL,
  next_check_at DATETIME DEFAULT NULL, attempts INT NOT NULL DEFAULT 0, last_error VARCHAR(500) DEFAULT NULL,
  grace_delete_at DATETIME DEFAULT NULL, deleted_remote_at DATETIME DEFAULT NULL,
  INDEX (state, next_check_at), INDEX (connection_id, remote_id)
);
```

- **Common facts stay on the shared row:** owner, order, status, starts/expires, sold-by reseller, public token.
- **VPN-specific columns stay where they are.** They are nullable or harmless for other products. `client_uuid`,
  `sub_id`, `config_links` and traffic columns are simply null on a server row.
- **Module data lives in module tables, owned by the built-in module,** not in plugin packages (section 4.4) and not
  in a JSON column on the shared row: lifecycle jobs need indexed columns (`state`, `next_check_at`), and secrets need
  their own narrow handling. `server_instance` is generic: every server driver uses it, and anything specific goes into
  `driver_data`. A driver package therefore never needs a migration.
- **Plan options:** `plan.module_config` JSON, validated by the module's `planSchema()` (which merges the driver's).
  VPN leaves it null and keeps its columns. `plan.duration_days`, `price`, `is_active` are shared. `traffic_gb` and
  `inbound_id` are already nullable.
- **`order_draft`:** `traffic_gb`/`duration_days` remain. A new module stores its choices in the existing `data` JSON
  column (`OrderDraft.php:60` at `b485bfb`).
- **`Order.type`:** unchanged. `add_traffic` stays a string value only the VPN module uses.

### 3.3 Connections

A **connection** is one account at one provider: a 3x-ui panel, a Marzban panel, a Hetzner project, a DigitalOcean
team token, a Proxmox cluster. It generalises today's `VpnPanel`, which already has every ingredient (fact, at
`82a109d`):

| Ingredient | Today in `VpnPanel` (`src/Entity/VpnPanel.php`) | Generic form |
|---|---|---|
| Driver | `type` (`:19-20`), e.g. `sanaei_3xui`, `dummy` | `provider_connection.driver` |
| Encrypted credentials | `password`, `apiToken` stored with `SecretCipher` (`:31-37`), typed into `plainPassword`/`plainApiToken` and encrypted on flush by `VpnPanelSecretsListener`. Read only through `PanelCredentials` (`src/Provisioning/Infrastructure/PanelCredentials.php:20-34`) | `secrets`, encrypted the same way, filled from the schema's `password` fields. Never shown after saving |
| Non-secret config | `baseUrl`, `username`, `config` JSON, `subscriptionBaseUrl`, `publicHost` (`:25-50`) | `config` JSON from `connectionSchema()` |
| Remote version / mode | `sanaeiIntegrationMode`, `sanaeiDetectedMode`, `sanaeiProbedAt`, `sanaeiProbeStatus` (`:55-68`) | Driver-owned, in `config` (3x-ui keeps its columns) |
| Health | `consecutiveFailures`, `degradedSince`, `DEGRADED_AFTER_FAILURES = 3` (`:70-78`), `isDegraded()` (`:541`), `isAvailableForSale()` (`:578-581`) | Same columns and rule, applied by the core to every connection |
| Active switch | `isActive` (`:52-53`) | `is_active` |

**Decision (opinion): VPN keeps `vpn_panel`; new modules use `provider_connection`.** Moving `vpn_panel` into a
generic table would touch inbounds, plans, services, moves and the 3x-ui probe, with no customer benefit. The VPN
module adapts a `VpnPanel` to the `Connection` interface (a read-only view: id, driver code, config, a credential
accessor). A Marzban or PasarGuard connection is a `vpn_panel` row of another `type`, so plans and inbounds keep
working (section 6.2 explains "inbound" for those panels).

**Health check.** `healthCheck()` is one cheap read-only call (3x-ui: today's probe, `SanaeiCapabilityProbe`; a cloud:
list one page of servers or `GET` the account). It is run by the admin's "test connection" button, by
`app:connection:doctor <id>` (generalising `app:panel:doctor`, `src/Command/PanelDoctorCommand.php:37`) and by the
sync job. The core keeps today's degraded rule: three consecutive failures stop new sales on that connection, any
success resets it. Bulk and background calls do not count, exactly as today (`InboundAttachScheduler.php:43`,
`InboundMoveRunner.php:66`).

**Credential rules.** Read through a per-connection accessor only (as `PanelCredentials` does today). Never logged,
never in an exception message, never in `last_error`. Keys added to `SecretRedactor`
(`src/Provisioning/Infrastructure/Sanaei3xui/Modern/Http/SecretRedactor.php`, to be moved to Shared in PM1a). Rotating
`APP_SECRET` makes every connection secret unreadable, as with panel tokens today; the admin re-enters them.

### 3.4 Per-connection limits

Stored in `limits` (VPN: in `vpn_panel.config` to avoid a schema change), enforced by the module before a sale, and
again in the job before a remote create:

| Limit | Why |
|---|---|
| `max_active_services` | A panel's capacity, or a cloud account's server quota (Hetzner's is per account and not published; 7.9.7) |
| `max_creates_per_hour` | Provider create limits (Linode: 20 per 15 s for creates; 7.1) and abuse damping |
| `max_deletes_per_run` | The delete circuit breaker (7.5) |
| `request_budget_floor` | Stop a job pass when the provider's remaining requests fall below it (7.9.5) |
| `max_monthly_cost` (server) | Stop new sales when the estimated running cost passes it (estimate from catalogue prices) |

A plan whose connection is over a limit is not sellable (`saleBlocker()`); the reason is shown to the admin, never
to the customer.

### 3.5 Plans bind to module, driver and connection

```text
plan.product_type = 'vpn'    -> plan.inbound_id -> vpn_inbound.panel_id -> vpn_panel (type = driver)    (today, unchanged)
plan.product_type = 'server' -> plan.connection_id -> provider_connection (module = 'server', driver = ...)
plan.module_config           -> fields from module.planSchema(connection) = module fields + driver.planSchema(connection)
```

The plan form is built from the schema: the admin picks the module (fixed after the first sale), then the connection
(only connections of that module whose driver is enabled), then fills in the fields. Server example: size, location,
image and allowed SSH-key policy come from the driver's catalogue calls, so the form offers only what that account can
create. A plan's connection can change only through a move (VPN: today's inbound moves; server: no move in v1).

### 3.6 Backward compatibility

- `NOT NULL DEFAULT 'vpn'` gives every existing row the right value without touching it. The default stays in the
  schema: there is no window in which an old code path inserts a row without a type. On MariaDB 10.11 adding a column
  with a default can be done in place; the implementation PR should confirm that on a copy of the production database
  and record the time.
- Every migration is additive, so older code would run against the extra columns. A rollback is still not free:
  `check_schema_matches()` (`deploy.sh:688`) stops a deploy when the database has executed migrations the target commit
  does not contain. Going back means restoring the pre-deploy dump, or a forward fix.
- A code path that reads a service of an unknown type, or of a disabled module, must fail closed: the registry throws a
  typed exception, the job counts a failure, and nothing is suspended or deleted (section 4.6).

## 4. Plugins: packaging, installing and enabling modules and drivers

### 4.1 The payment plugin system as it is (facts, at `82a109d`)

| Piece | Where | What it does |
|---|---|---|
| Entity `plugin` | `src/Entity/Plugin.php:18-34` | Columns `code` (unique), `type`, `name`/`description` (per locale), `version`, `status` (`installed`, `enabled`, `disabled`, `error`), `path`, `mainClass`, `manifest` JSON, `permissions`, timestamps. Only one type exists: `payment_gateway` (`:23,32-34`) |
| Package | `PluginManager::installFromZip()` (`src/Plugin/PluginManager.php:36`) | A ZIP of at most 10 MiB (`:16`, `config/services.yaml:26`). Allowed roots: `plugin.json`, `src`, `templates`, `translations`, `README.md`, `assets` (`:17-24`); `.git`, `vendor`, `public`, `var`, `cache` refused; path traversal refused (`isSafeArchivePath()`). Extracted to `var/plugin_uploads/tmp`, moved to `var/plugins/<code>` (`:396`) |
| Manifest | `PluginManager::validateManifest()` (`:129-166`) | `manifestVersion` must be `1`; `code` `[a-z0-9_-]+`, not reserved (`PaymentGatewayRegistry::RESERVED_CODES`, `:27`); `type` in `Plugin::TYPES`; `version`, `name`, `mainClass` required. Example: `var/plugins/tetrapay/plugin.json` also has `category`, `permissions`, `supportsWebhook`, `configSchema` |
| Config schema | `PluginConfigSchemaValidator` (`src/Payment/Plugin/PluginConfigSchemaValidator.php:12`) | Field types `text`, `password`, `textarea`, `boolean`, `number`, `integer`, `url`, `choice`; `required`; per-locale labels. The gateway admin form is built from it |
| Doctor | `PaymentPluginDoctor::inspect()` (`src/Plugin/PaymentPluginDoctor.php:19-98`), `app:plugin:doctor` (`src/Command/PluginDoctorCommand.php:16`) | Checks `plugin.json`, type, code, `mainClass` matches the manifest, `src/` exists, the class loads and implements `PaymentGatewayPluginInterface`, the schema is valid. Run at install and at enable |
| Loading | `PluginAutoloader::register()` (`src/Plugin/PluginAutoloader.php:63-79`) | A PSR-4-like `spl_autoload_register` per plugin, from `mainClass`'s namespace to `<path>/src`. Registered lazily when a gateway is resolved. `registerEnabledPlugins()` exists but is marked "Do not call from the kernel in this phase" (`:18`) |
| Instantiation | `PaymentGatewayRegistry::createPluginAdapter()` (`src/Payment/Infrastructure/PaymentGatewayRegistry.php:129-200`) | Refuses wrong type, `error`, not `enabled`, doctor failures; then `new $mainClass()` with **no constructor arguments** (`:175`), wrapped in `PluginPaymentGatewayDriverAdapter`. Config comes per call as an array from the `payment_gateway` row |
| Enable / disable | `PluginManager::enable()` / `disable()` (`:168-200`); routes `admin_plugins_enable` / `admin_plugins_disable` (`src/Admin/UI/Crud/PluginCrudController.php:189-223`) | Enable re-runs the doctor and sets `error` on failure. Both are CSRF-confirmed and audit-logged (`:201,219`). Permission `PLUGINS_MANAGE` (`src/Admin/Security/AdminCrudPermissions.php:117`) |
| Uninstall | `PluginManager::uninstall()` (`:202-205`) | Throws "not available in this phase". Files and row stay |
| Listing | `app:payment:list-modules` (`src/Command/PaymentListModulesCommand.php:14,28-41`), `PaymentGatewayModuleRegistry::all()` (`src/Payment/Application/PaymentGatewayModuleRegistry.php:156`) | Merges built-in gateway types and enabled plugins (`:385-412`), with `source` = `core` or plugin |
| Routes | `/payment/webhook/plugin/{code}`, `/payment/callback/plugin/{code}` (`src/Payment/UI/PaymentWebhookController.php:80,98`) | Core routes that dispatch to the plugin by code. Plugins register no routes of their own |
| Templates, translations | Accepted in the ZIP (`PluginManager.php:17-24`) | **Not loaded anywhere**: no Twig path or translator resource is added for a plugin (grep for `addPath`/`addResource` finds none). Labels come from the manifest's per-locale maps |
| Permissions | `manifest.permissions` | Stored and listed, **not enforced** (`PaymentGatewayModuleRegistry.php:412`) |
| Scheduled work | none | The Tetraminator plugin's reconcile timer lives in the core: `TetraminatorReconciler` ("The plugin ships outside the core, so the core cannot refer to the class", `src/Payment/Application/Reconcile/TetraminatorReconciler.php:44`), `--only=tetraminator` (`src/Command/AutomationRunCommand.php:255-272`), a unit in `deploy.sh:110` that exits 0 when the plugin is absent (`:95-99`) |
| Development copies | `docs/plugins/*-gateway/` (source, `build.sh`, tests), installed copies in `var/plugins/<code>` (`deploy.sh:1965` creates the directory) | Test fixtures: `tests/Support/plugins/{constrained-gateway,tetraminator-scripted,winapay-scripted}` |

**What this tells us.** The system is a good *driver* host: small adapter classes, configuration through a schema,
dispatch through core routes, safe ZIP handling, a doctor, audit-logged enable/disable. It is not a *module* host:
no services, no Doctrine mappings, no templates, no translations, no timers, no admin pages. Turning it into one
would mean loading plugin code into the compiled container (bundles from `var/`). That is a large, risky change to
how the application boots, done to support packages nobody needs yet (opinion).

### 4.2 Proposal: one registry, two kinds of package, built-ins registered the same way

1. **New plugin types:** `vpn_driver` and `server_driver` (one per module contract; `<module>_driver` in general).
   `product_module` is reserved in `Plugin::TYPES` but **not installable in v1**: modules are built in.
2. **Built-ins go through the same registry.** `VpnProductModule`, `ServerProductModule`, the 3x-ui drivers and
   `dummy` stay in `src/`, tagged (`app.product_module`, `app.provider_driver`). `ProductModuleRegistry` and
   `ProviderDriverRegistry` merge tagged built-ins with enabled packages, exactly as `PaymentGatewayModuleRegistry`
   merges built-in gateways and plugins today. Listing shows `source` = `core` or `package`.
3. **Enabled state for built-ins is a `plugin` row too,** with a new column `source` (`core`/`package`), `path` and
   `mainClass` null for core rows. A migration inserts `vpn` and `sanaei_3xui` (and `dummy`) as `enabled`, so an
   existing install is identical after the migration. `server` is inserted as `disabled`. The same admin page
   (`PluginCrudController`) and the same audit records enable and disable them. Core rows can't be uninstalled.
4. **Payment gateways are untouched and independent.** No module or driver may depend on a gateway, or the reverse.
   A gateway sees `Payment` and `Order` only (`PaymentGatewayPluginInterface.php:20-34`). Each install (section 5)
   has its own `plugin` rows, so the server install enables its own gateways.

**Commands** (follow the existing names):

| Command | Does |
|---|---|
| `app:product:list-modules` | Like `app:payment:list-modules`: modules and drivers, source, version, contract, status, capabilities per connection |
| `app:plugin:doctor <code>` | Extended to driver types: the class implements the module's driver interface, contract version matches, schemas valid, integrity matches (4.5) |
| `app:connection:doctor <id>` | `healthCheck()` plus capability list; the 3x-ui `--self-test` stays a driver command |

### 4.3 Manifest and versioning

```json
{
  "manifestVersion": 2,
  "type": "vpn_driver",
  "code": "marzban",
  "version": "1.2.0",
  "name": {"fa": "مرزبان", "en": "Marzban"},
  "mainClass": "Amoobot\\Plugin\\Marzban\\MarzbanDriver",
  "requires": {"core": ">=3.4 <4.0", "contract": {"vpn_driver": 1}},
  "remote": {"tested": ["0.8.4"]},
  "connectionSchema": [ ... same format as configSchema ... ],
  "planSchema": [ ... ],
  "capabilities": ["multi_inbound", "per_user_inbound_selection", "panel_sub_links", "traffic_reset", "..."],
  "files": {"src/MarzbanDriver.php": "sha256:..."},
  "permissions": ["http.outbound"]
}
```

- `manifestVersion: 1` stays valid for payment gateways. Version 2 adds `requires`, `connectionSchema`, `planSchema`,
  `capabilities`, `files`.
- **Core compatibility:** `requires.core` is checked against the application version (new constant, bumped by
  release). **Contract compatibility:** each driver interface has an integer version (`VpnDriverInterface::VERSION`).
  A breaking change bumps it; the registry refuses a package built for another version and marks it `error`, with the
  services on its connections failing closed (4.6). A new optional capability is not a breaking change.
- The manifest's `capabilities` list is a ceiling: `capabilities(Connection)` may return fewer (for example a remote
  version too old), never more. The doctor checks it.

### 4.4 What a package may register

| Item | Driver package (v1) | Built-in module (v1) |
|---|---|---|
| PHP classes | Yes, under its own namespace, autoloaded as today | `src/` |
| Services from the container | **No.** It receives a `DriverContext`: an HTTP client bound to the connection's base URL with timeouts, budget and redaction (built on `PanelHttpClientFactory`/`ModernPanelHttpClient`), a redacting logger, a clock, and the connection's config and secrets. The constructor stays argument-free, as for gateways | Normal autowiring |
| Entities / migrations | **No.** Driver state goes into the module's tables (`server_instance.driver_data`, VPN columns). Keeps `doctrine:migrations` under the core's control | Yes, in `migrations/` |
| Connection and plan schema | Yes (manifest), rendered by the core form | Yes |
| Translations | Labels in the manifest (per-locale maps, as gateways do). Error and status texts are **core** keys the driver returns (`driver.error.auth`, ...). No translator resources | `translations/*` |
| Templates | No | Yes |
| Routes | No. If a provider calls back (webhooks: Marzban and PasarGuard support them, 6.1), a core route `/provider/webhook/{connectionId}/{token}` dispatches to the driver, like `/payment/webhook/plugin/{code}` | Yes |
| Bot / Mini App views | No. The module renders; the driver returns data | Yes, through `MiniAppSectionProvider` and `ServiceActionInterface` (existing tags) |
| Scheduled tasks | No units. A driver may declare `pollIntervalSeconds` and implement `poll()`; the module's task calls it from a core timer (4.6) | `scheduledTasks()` run by `app:automation:run --only=<task>` |

**Later, module packages** (`file`, `license`): only when one is actually needed, as its own design. The likely shape
is a Composer package added to the repository and deployed like core code, not a ZIP upload, because it needs
entities, migrations, templates and container services (opinion).

### 4.5 Security: plugins are full PHP code

A plugin runs with the PHP process's rights: it can read `.env.local.php`, the database credentials, every connection
secret, and send anything anywhere. The `permissions` list is a declaration, not a sandbox. Rules (opinion):

1. **Trust.** Install only packages built from source the owner or a trusted reviewer has read. Third-party ZIPs
   are refused by policy, even though the uploader would accept them.
2. **Review.** Driver packages live like the gateways in `docs/plugins/<code>/` with tests and `build.sh`. A package
   version is installed only from a reviewed commit, and the ZIP is built by `build.sh` from that commit.
3. **Integrity.** The manifest lists `sha256` per file (`files`). The installer records the ZIP's hash and the doctor
   recomputes every file's hash at install, at enable and from the sync job (cheap). Any mismatch, or a file not in
   the list, sets `error` and the driver fails closed. Optional, later: a signature over the manifest by a key whose
   public half is in the core (opinion: worth it once a second person builds packages).
4. **Upload switch.** An env `PLUGIN_UPLOAD_ENABLED`, default on for compatibility, so an install can turn web uploads
   off and install only from the CLI (`app:plugin:install <zip>`, new) during a deploy.
5. **Permission.** `PLUGINS_MANAGE` stays super-admin only. Install, enable, disable and every doctor failure are
   audit-logged (they already are for gateways) and alerted to the admin chat.
6. **No eval of remote data.** Drivers parse JSON; no `unserialize`, no `include` of anything outside their `src/`.
   The contract test kit (section 8) greps a package for these.
7. **Secrets in, never out.** A driver gets its own connection's secrets, nothing else. Its exceptions and logs go
   through `SecretRedactor`; the kit asserts that a secret never appears in an exception message.

### 4.6 Disabling a module or a driver: fail closed, keep data, never delete remotely

| Event | New sales | Existing services | Jobs | Customer sees | Admin sees |
|---|---|---|---|---|---|
| Driver disabled (or `error`, or contract mismatch) | Plans on its connections not sellable (`saleBlocker`) | Rows unchanged. No remote call of any kind | Tasks skip its services and count them as `driver_unavailable`, exit 0 | The service with stored data and "temporarily unavailable" on actions; renewals refused before payment | Dashboard warning with the number of active services no longer enforced; one admin alert per state change |
| Module disabled | Its plans hidden; its menu entries, bot buttons, Mini App sections and public routes (`/s/{token}`, `/d/{id}` for VPN) off | Rows unchanged. No suspend, resume, terminate, sync | Its tasks exit 0 with "module off" (timers stay installed, 4.7) | Its services listed read-only, or hidden if the owner chooses (open question) | Same warning |
| Connection deactivated | Its plans not sellable (as `isAvailableForSale()` today) | As above for that connection | As above | As above | As above |
| Re-enabled | Back | First sync pass reconciles stored state with the remote before any lifecycle action | Normal | Normal | Warning clears |

Guarantees:

- **No data loss.** Disabling never deletes a row, a file or a remote resource. "Uninstall" of a package (later) is
  refused while any service references one of its connections.
- **No remote deletes as a side effect.** Only an explicit lifecycle decision, with the guards of 7.5, deletes
  remotely. A disabled driver can't delete, and re-enabling doesn't replay missed deletes without the guards.
- **Payments in flight.** An order whose module or driver was disabled after payment is settled as
  `PROVISIONING_FAILED` with reason `module_unavailable`; the admin retries after re-enabling, or refunds (2.5 item 3).
  The money path is unchanged.
- **Expiry while disabled.** Services keep their dates. VPN panels enforce expiry themselves (6.1). Servers keep
  running and costing money: the dashboard warning shows the estimated running cost of servers past expiry.

### 4.7 What disappears when the `vpn` module is off

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
`app:automation:run` already handles "skipped by setting" and "nothing to do" (the Tetraminator task exits 0 with no
plugin, `deploy.sh:95-99`). A disabled task should succeed as a no-op. Making `SCHEDULED_JOBS` conditional adds risk
to `deploy.sh` for no gain (opinion). The server module gets one new timer, `modules`, in its own lock group (7.9.5
explains why not `lifecycle`), which exits 0 while the module is disabled.

**Env or row?** PM0 proposed `APP_MODULES` in env. With the `plugin` rows above, the enabled state is in the database,
like gateways. The env stays as a **ceiling**: `APP_MODULES_ALLOWED=vpn` (default) means an admin can't enable `server`
on the VPN install by mistake; the server install sets `APP_MODULES_ALLOWED=server`. Migrations of built-in modules
always run, so enabling never needs a deploy (opinion).

## 5. Two brands on one server

**Decision (owner, PM0b): (a) MULTI-INSTALL is chosen.** (b) is kept below for the record, so the reasons stay
visible; nothing in it is planned. Each install has its own database, its own `plugin` rows and therefore its **own
payment gateways and its own product modules and drivers**: the server install may use a different gateway from the VPN
install, and no gateway knows or cares which product type it is paying for (`PaymentGatewayPluginInterface` receives a
`Payment` and an `Order`, never a service: `src/Payment/Plugin/PaymentGatewayPluginInterface.php:20-34`).

### 5.0 What is shared either way (facts)

- Same box, same public IP, one PHP-FPM service, one MariaDB server, one nginx. A failure of the box or MariaDB takes
  down both brands. A determined observer can link two domains on one IP.
- `SecretCipher` keys derive from `APP_SECRET`, so one install has one key.
- Provisioning runs inside PHP-FPM requests (Telegram webhook, payment webhook): slow panel or gateway calls occupy
  workers. Under (a) with one pool, or under (b), the brands compete for workers.

### 5.1 (a) MULTI-INSTALL (chosen)

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

### 5.2 (b) MULTI-STORE (not chosen; kept for the record)

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
  currency is a separate piece of work (section 11).

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

### 5.4 Recommendation (opinion) and decision

**Choose (a) MULTI-INSTALL.** The owner has since decided for (a); the reasons below are the recorded rationale.

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

## 6. VPN driver contract

### 6.1 The three panels (facts from their sources, read 2026-11-08)

| | Sanaei 3x-ui | Marzban | PasarGuard |
|---|---|---|---|
| Source read | `github.com/MHSanaei/3x-ui`, `main` (latest release v3.11.0, commit `c621ba9`, 160 commits behind `main`); `internal/web/controller/{api,client,inbound}.go`, `internal/database/model/model.go`, `internal/sub/controller.go` | `github.com/Gozargah/Marzban`, `master` (latest release v0.8.4, commit `7f396db`); `app/routers/{user,admin,core,system,node,subscription}.py`, `app/models/user.py`, `config.py` | `github.com/PasarGuard/panel`, `main` (latest release v5.6.0, commit `2b75db1`); `app/routers/{user,group,subscription,system,authentication}.py`, `app/models/{user,group,settings}.py`, `app/db/models.py` |
| Auth | Bearer API token (stored hashed, optional expiry, scopes `admin`/`monitor`/`node-sync`/`node-admin`) or session cookie. The repository's modern driver uses the token; legacy panels use the cookie login | `POST /api/admin/token` (OAuth2 password form) returns a JWT, `Authorization: Bearer`, default lifetime 1440 min (`JWT_ACCESS_TOKEN_EXPIRE_MINUTES`). No static API key | JWT as Marzban, **or** API key `pg_key_<uuid4>` in `X-Api-Key` or `Authorization: ApiKey`, with per-key `max_requests`, `delete_on_limit` and RBAC permissions |
| User identity | Client `email` (unique, the path key), plus `uuid` and `subId`. Global client linked to inbounds through a `client_inbounds` join table | `username`, 3-32 chars, immutable, global | `username` and numeric `id` (`/by-username/`, `/by-id/` routes), global |
| Bound to | Inbounds, by id: `POST /panel/api/clients/{email}/attach` / `detach` with `{inboundIds}`, bulk forms | `inbounds`: per protocol, a list of inbound tags (omitted = all of that protocol); `proxies`: per-protocol credentials | `group_ids`; a user's inbounds are the union of `inbound_tags` of its enabled groups. Hosts (one per inbound tag) shape how links render |
| Create / get / modify / delete | `POST /panel/api/clients/add` (with `inboundIds`), `GET /get/{email}`, `POST /update/{email}`, `POST /del/{email}` | `POST /api/user`, `GET`/`PUT`/`DELETE /api/user/{username}` | `POST /api/user`, `GET`/`PUT`/`DELETE /api/user/{username}` |
| List | `GET /list`, `/list/paged` | `GET /api/users?offset&limit&username[]&search&admin[]&status&sort` | `GET /api/users` with offset, limit, ids, username, search, status, group, admin, sort, expire/online ranges |
| Limits | `totalGB`, `expiryTime`, `limitIp` (enforced through Fail2ban), `limitHwid` | `data_limit` (bytes, 0 = unlimited), `expire` (unix time). No IP or device limit | `data_limit`, `expire`, `hwid_limit` (devices). No IP limit |
| Status | `enable` boolean only | `active`, `disabled`, `limited`, `expired`, `on_hold` | Same five |
| Suspend / resume | Update `enable`; `bulkEnable`/`bulkDisable` | `PUT` with `status: disabled/active`; bulk per admin | `PUT /api/user/{username}/disabled {disabled: bool}`; bulk |
| Traffic reset | `POST /resetTraffic/{email}`; inbound-level schedule `never/hourly/daily/weekly/monthly`; client `reset` in days (from a summary, not verbatim) | `POST /api/user/{username}/reset`; `data_limit_reset_strategy` `no_reset/day/week/month/year` | Same as Marzban |
| Usage | `GET /traffic/{email}`; bulk: partial | `GET /api/user/{username}/usage`, `GET /api/users/usage` | Same, plus `node_id` and `period` filters |
| Revoke / rotate | No endpoint seen; rotation by `update` (UNCONFIRMED). The repository rotates UUID and subId (`CredentialRotationDriverInterface`) | `POST /api/user/{username}/revoke_sub` | `POST /api/user/{username}/revoke_sub` |
| Subscription URL | `GET {subPath}{subId}` (default `/sub/`), JSON and Clash variants, `Subscription-Userinfo` header | `GET /{XRAY_SUBSCRIPTION_PATH}/{token}` (default `sub`), variants per client type; user response has `subscription_url` | `GET /{sub_path}/{token}` with `/info`, `/raw`, per client type; user response has `subscription_url`; default path UNCONFIRMED |
| Per-user links from the panel | `GET /links/{email}`, `/subLinks/{subId}` | `links` field in the user response | No `links` field; `GET /api/user/{id}/subscription/links` (client type `links`) returns them |
| Owner note | `comment`, `tgId` | `note` (500) | `note` (500) |
| Expiry on first use | No | `on_hold_expire_duration`, `on_hold_timeout` | Same, plus `on_hold_timeout_action` |
| Next plan | UNCONFIRMED (README mentions renewal cycles) | `next_plan`, `POST /active-next` | `next_plan` with `user_template_id`, `POST /active_next` |
| Webhooks | Telegram/Discord/email alerts; a generic webhook UNCONFIRMED | `WEBHOOK_ADDRESS`, `WEBHOOK_SECRET` | `webhooks[{url, secret}]`, `days_left`, `usage_percent` |
| API rate limit | None seen; `login_limiter.go` not read (UNCONFIRMED) | None seen | Per-key `max_requests` only |
| Version endpoint | `/panel/api/server/status`, `/server/getXrayVersion` | `GET /api/system` (has `version`) | `GET /api/system` |

**Not confirmed** (read or listed but not verified, to be settled in the driver's first PR against a real panel):
3x-ui's client field list came partly from a summary of a truncated file; 3x-ui's credential regeneration and
next-plan; PasarGuard's `host.py`, `core.py`, `node.py`, `user_template.py` were not read, nor its default sub path or
JWT lifetime; Marzban's and PasarGuard's README and docs sites were not read; no panel was exercised. Whether
Marzban's `revoke_sub` also rotates the proxy UUIDs, or only the subscription token, is **UNCONFIRMED** and decides
whether claims can work there (6.4).

**What the repository uses from 3x-ui today** is wider than the public route list: the modern driver
(`Sanaei3xuiModernDriver.php`, 1098 lines) speaks the clients API through `ModernClientsApi.php` (548 lines), and the
legacy driver (`Sanaei3xuiDriver.php`, 1473 lines) the old cookie API. Both stay as they are.

### 6.2 What "inbound" means per panel

The VPN module's sale unit is today a plan bound to a primary inbound plus additional inbounds
(`Plan::$inbound`, `PlanAdditionalInbound`; `src/Entity/Plan.php:89-101`), each a `vpn_inbound` row of a panel. The
contract keeps that word and defines it as **the smallest thing a driver can put a user on**:

| Panel | `vpn_inbound` row is | Per-user subset | Move |
|---|---|---|---|
| 3x-ui | An inbound (`remoteInboundId`) | Yes, attach/detach by id | Yes (P1-P11, today) |
| Marzban | An inbound **tag** (protocol in `config`) | Yes, `inbounds` per protocol in `PUT` | Yes, by `PUT` of a new `inbounds` map (to be proven in its PR) |
| PasarGuard | A **group** (its `inbound_tags` in `config`) | Yes, at group granularity | Yes, by `PUT` of new `group_ids` |

So the inbound sync (`VpnInboundSyncService`) becomes a driver call `listInbounds(Connection)` that returns these
units, and the admin's inbound pages work for all three. Mapping PasarGuard groups to "inbounds" is a choice
(opinion): the alternative, a separate "group" concept in plans, would fork the plan form for one panel.

### 6.3 The contract and its capabilities

```php
interface VpnDriverInterface extends ProviderDriverInterface
{
    public const VERSION = 1;

    /** Required of every VPN driver. */
    public function createUser(CreateVpnServiceRequest $request, Connection $c): CreatedVpnService;   // idempotent: see adopt
    public function renewUser(string $remoteId, RenewVpnServiceRequest $request, Connection $c): void; // sets limit and expiry
    public function suspendUser(string $remoteId, Connection $c): void;
    public function deleteUser(string $remoteId, Connection $c): void;      // a missing user is success
    public function usage(string $remoteId, Connection $c): VpnUsage;
    /** @return list<RemoteInbound> the units of 6.2 */
    public function listInbounds(Connection $c): array;
}
```

Every optional capability is an existing interface (same names and signatures, `src/Provisioning/Domain/`), so the
3x-ui drivers change only their `implements` line. The `CapabilitySet` names them so the module, the admin and the
test kit can read them:

| Capability | Interface (existing unless marked) | Meaning | 3x-ui modern | 3x-ui legacy | Marzban | PasarGuard |
|---|---|---|---|---|---|---|
| `adopt_existing` | `ExistingClientAwareDriverInterface` | Find the order's user after a lost response | yes | yes | yes (deterministic username) | yes |
| `resume` | `ServiceActivationDriverInterface` | Re-enable without a renewal | yes | no (renew with 0 days, `ServiceAdministration.php:58`) | yes | yes |
| `traffic_add` | `TrafficLimitDriverInterface` | Raise the data limit in place | yes | yes | yes (`PUT data_limit`) | yes |
| `traffic_reset` | in base today (`resetUsage`) | Zero the used counter | yes | yes | yes | yes |
| `batch_usage` | `BatchUsageDriverInterface` | Usage of many users in one call | yes | no | yes | yes |
| `multi_inbound` | `InboundAttachDriverInterface` | One user on several units | yes | no | yes | yes |
| `per_user_inbound_selection` | `InboundAttachDriverInterface` (`clientInboundIds`) | Read and set the units of one user | yes | no | yes | yes (groups) |
| `inbound_move` | `InboundMoveDriverInterface` | Move a user to another unit keeping identity | yes | no | likely (UNCONFIRMED until tested) | likely (UNCONFIRMED) |
| `inbound_detach` | `InboundDetachDriverInterface` | Take a unit off a user | yes | no | yes | yes |
| `panel_sub_links` | `PanelLinkSourceDriverInterface` | The panel renders the user's links (H0 rule) | yes | no | yes (`links`) | yes (subscription `links`) |
| `subscription_url` | new, small: `subscriptionUrl(remoteId)` | The panel serves a subscription URL | yes (built from subId) | yes (built) | yes | yes |
| `credential_rotation` | `CredentialRotationDriverInterface` | New credentials, old links dead | yes | no | partial: `revoke_sub` (UUID rotation UNCONFIRMED) | partial: same |
| `client_owner_note` | `ClientOwnerDriverInterface` | Owner id in the remote note | yes | no | yes (`note`) | yes (`note`) |
| `client_import` | new: `listUsers(Connection, cursor)` | Read all users for import | yes (today inside `PanelClientImporter`) | no | yes | yes |
| `ip_limit` | new field in create/renew | Enforce simultaneous IPs | yes (`limitIp`) | yes | no | no |
| `device_limit` | new field | Enforce devices | yes (`limitHwid`, not used yet) | no | no | yes (`hwid_limit`) |
| `on_hold` | new, later | Expiry starts on first use | no | no | yes | yes |
| `webhooks` | new, later | Panel calls back (usage, expiry) | UNCONFIRMED | no | yes | yes |

Rules:

- **H0 rule, generalised.** A driver with `panel_sub_links` is the only source of a service's links: the stored
  snapshot is exactly the panel's answer, never merged with links built by Amoobot
  (`src/Provisioning/Application/PanelLinkSnapshot.php:34-44` and its class comment; `docs/design/multi-inbound-plans.md`
  phase H0). Today the generator `VpnAccessLinkGenerator` still runs for any panel type other than `sanaei_3xui`
  (`VpnProvisioningService.php:161`). In the contract the generator is allowed only for the legacy 3x-ui driver,
  which is frozen. **A new VPN driver must have `panel_sub_links` or `subscription_url`; a driver with neither is
  refused by the registry.**
- **Capabilities are per connection.** 3x-ui modern vs legacy is decided by the probe on the panel row
  (`VpnPanel::isModern()`, `src/Entity/VpnPanel.php:371-374`), so `capabilities($connection)` reads it. A Marzban
  driver may drop `inbound_move` for a panel version that fails the test.
- **The module asks, the core doesn't.** Today `MultiInboundSale::blocker()` names the driver
  (`'sanaei_3xui' === $panel->getType() && $panel->isModern()`, `src/Shop/Domain/MultiInboundSale.php:53`). In the
  contract it asks `capabilities()->has('multi_inbound')`, and `PlanSaleRule`
  (`src/Shop/Application/Checkout/PlanSaleRule.php:42-81`) asks the module's `saleBlocker()`. Same answer for every
  existing panel, so the change is behaviour-neutral (PM3a).
- **Undeclared means absent.** Calling a capability's method on a driver that doesn't declare it throws
  `CapabilityNotSupported`, and the kit tests that.

### 6.4 Today's 3x-ui-specific features and how each is gated

| Feature | Where (at `82a109d`) | Gated by | When absent |
|---|---|---|---|
| Multi-inbound plans (additional inbounds) | `Plan::$additionalInbounds` (`src/Entity/Plan.php:99-101`), `MultiInboundSale::blocker()` (`src/Shop/Domain/MultiInboundSale.php:53`), switch `plans.multi_inbound_enabled` (`src/Shop/Application/MultiInboundSettings.php:29`), `PlanInboundsValidator.php:91` | `multi_inbound` **and** the switch | Additional-inbound fields hidden on the plan form; a plan with them on such a connection is not sellable (today's `NOT_MODERN` reason) |
| Attach-inbounds pass and timer | `ServiceInboundAttacher`, `InboundAttachScheduler` (`:153`), `app:services:attach-inbounds` (`src/Command/ServicesAttachInboundsCommand.php:22`), timer `deploy.sh:108`; post-settlement step `PaymentSettlementService.php:488-496` | `multi_inbound` | Services skipped, as today for a driver without `InboundAttachDriverInterface` |
| Inbound moves P1-P11 (move, add, remove) | `InboundMovePlanner` (`:789,993,1085`), `InboundMoveRunner`, `InboundMoveScheduler`, `app:plan:apply-inbounds` (`src/Command/PlanApplyInboundsCommand.php:42`), switch `plans.inbound_move_enabled` (`src/Shop/Application/InboundMoveSettings.php:26`), timer `deploy.sh:109` | `inbound_move` (move, add), `inbound_detach` (remove), and the switch | Planner reports the services as not movable, as today |
| `PanelLinkSnapshot` (links only from the panel) | `src/Provisioning/Application/PanelLinkSnapshot.php:44` (`driverFor()` returns null for other drivers) | `panel_sub_links` | Links from `subscription_url` only; generator only for legacy 3x-ui |
| `PlanSaleRule` | `src/Shop/Application/Checkout/PlanSaleRule.php:42-81` | Module `saleBlocker()` reading capabilities and connection health | — |
| Panel doctor and probe | `app:panel:doctor` (`src/Command/PanelDoctorCommand.php:37`), `app:panel:detect-version` (`src/Command/PanelDetectVersionCommand.php:18`), `SanaeiCapabilityProbe`, `ModernModeGuard` (`src/Provisioning/Application/ModernModeGuard.php:32`) | Driver-specific commands stay; the generic part becomes `healthCheck()` and `app:connection:doctor` | — |
| Client import | `app:panel:import-clients` (`src/Command/PanelImportClientsCommand.php:24`), `PanelClientImporter::import()` refuses non-modern 3x-ui (`src/Provisioning/Application/PanelClientImporter.php:59`) | `client_import` | Command refuses with "driver can't list users" |
| Legacy 3x-ui panels | `Sanaei3xuiDriver` (`src/Provisioning/Infrastructure/Sanaei3xui/Sanaei3xuiDriver.php:28,39-42`): base, `adopt_existing`, `traffic_add` only | Its own small capability set | Frozen: no new capabilities. Open question whether to retire it |
| Claims and ownership transfer | `ServiceOwnership.php:232,380,453`, `ClaimPanelSync.php:60` | `credential_rotation` (transfer), `client_owner_note` (comment sync) | Claims not offered for services on that connection |
| Traffic add-on | `ServiceTrafficAddonService.php:45` | `traffic_add` | Add-traffic button hidden (today it falls back) |
| Batch usage sync | `ServiceUsageSyncService.php:192` | `batch_usage` | One call per service, as today |
| Resume without renewal | `ServiceAdministration.php:58` | `resume` | Renew with 0 days, as today |
| Admin-path suspend only for 3x-ui | `ServiceAdministration.php:137` | Becomes "driver resolved", like the job path (2.4) | — |
| Panel-type branches in admin and commands | `sanaei_3xui` in 25 files outside `src/Provisioning/Infrastructure`: `VpnPanelCrudController.php:107,112,173`, `VpnInboundCrudController.php:72-88`, `PlanCrudController.php:386`, `PanelSetupController.php:58,69`, `FinalConfigLinkProvider.php:86,172`, `VpnInboundSyncService.php:827`, the `app:panel:*` commands, and others | Each becomes a capability check or moves into the 3x-ui driver's admin form | — |
| Trials | `TrialAccountService`, `TrialBackingPlans` | Module feature (VPN only), not a driver capability | — |

### 6.5 Identity, units and idempotency per panel

- **Remote id.** Today the remote id is the 3x-ui client email, derived from the order so a retry adopts
  (`VpnProvisioningService::orderClientUuid()`, `:54`; `findAdoptableClient()`, `:346`). Marzban and PasarGuard use
  `username` (3-32 chars): a deterministic form such as `<prefix>o<orderId>` fits. The prefix comes from the connection,
  so two installs on one panel don't collide (section 5).
- **Units.** Drivers convert at the edge: 3x-ui `totalGB` and Marzban `data_limit` are bytes; expiry is milliseconds
  in 3x-ui and seconds (or a datetime in PasarGuard) elsewhere. DTOs stay in GB and `DateTimeImmutable`.
- **Delete is idempotent.** A user already gone is success, never an error, so a retried terminate is safe.

## 7. Server module and server driver contract

### 7.1 Providers compared (facts from public docs, read 2026-11-08)

Sources:

- **Hetzner Cloud:** `https://docs.hetzner.cloud/`, `https://docs.hetzner.cloud/cloud.spec.json` (OpenAPI 3.1.2, `info.version`
  1.0.0; 3.5 MB, read in parts). PM0's reading of the same spec, changelog and FAQs (2026-11-06) is kept in 7.9.
- **DigitalOcean:** `https://docs.digitalocean.com/reference/api/reference/`, the Droplets and Droplet Actions pages
  ("Generated on 6 Nov 2026").
- **Vultr:** `https://www.vultr.com/api/` and its `openapi.json` returned **403**. Facts below come from the official Go
  client `https://github.com/vultr/govultr` (`govultr.go` `version = "3.34.0"`, `instance.go`, README) and the
  `vultr-cli` README. That is a client, not the API reference.
- **Linode/Akamai:** `https://techdocs.akamai.com/linode-api/reference/api` (updated 2026-06-16), `rate-limits` (2026-10-13),
  `filtering-and-sorting` (2026-03-01), `post-linode-instance` and `get-linode-instance` (spec 4.229.1).
- **Proxmox VE (self-hosted):** `https://pve.proxmox.com/wiki/Proxmox_VE_API` (edited 2026-02-04),
  `https://pve.proxmox.com/pve-docs/qm.1.html` (9.2.13). The API viewer renders with JavaScript; only the CLI
  reference was readable, and REST paths are inferred from it.
- **Virtualizor (self-hosted panel):** `https://www.virtualizor.com/docs/developers/admin-api` and `.../enduser-api`
  rendered only a navigation shell. **Nothing about Virtualizor is confirmed.**

"(s)" marks a fact taken from a fetch tool's summary of a part too large to read verbatim. "U" = UNCONFIRMED.

| Item | Hetzner Cloud | DigitalOcean | Vultr (via govultr) | Linode/Akamai | Proxmox VE | Virtualizor |
|---|---|---|---|---|---|---|
| Auth | Bearer, one token per project | Bearer, scopes `droplet:create` etc.; `droplet:admin` for rebuild/password | Bearer API key | PAT or OAuth, `linodes:read_write` | `PVEAPIToken=USER@REALM!ID=UUID` header (no CSRF), or ticket cookie + CSRF (2 h) | U |
| Create | `POST /servers`, async: returns `action` + `next_actions` | `POST /v2/droplets`, 202, poll `links.actions` | `POST /v2/instances`; separate `status`/`power_status`/`server_status` suggest async (inference) | `POST /v4/linode/instances`, async: `status` `provisioning` → `running` | Create or clone returns a task `UPID` (async) | U |
| Progress | `GET /actions/{id}`: `running`/`success`/`error`, `progress` % | Action `in-progress`/`completed`/`errored` | Poll the instance (inference) | Poll `status`; events exist (paths U) | Task status (path U) | U |
| Root password | Returned once, only without SSH keys (s) | Not returned; reset sends it by e-mail | `default_password` field (`omitempty`; when set U) | Never returned: the caller sets `root_pass` (write-only) | Caller sets it through cloud-init `cipassword` (SSH keys recommended) | U |
| Status values | 9: running, initializing, starting, stopping, off, deleting, migrating, rebuilding, unknown (s) | 4: new, active, off, archive | U | 14, incl. provisioning, running, offline, busy, billing_suspension (s) | U | U |
| Power off | `shutdown` (ACPI) / `poweroff` (hard) | `shutdown` / `power_off` | `halt` | Shut down (hard not listed) | shutdown / stop | U |
| Reboot | `reboot` / `reset` (hard) | `reboot` / `power_cycle` | `reboot` | Reboot | reboot / reset (s) | U |
| Rebuild | `rebuild` (password again if no keys) | `rebuild` (`image`) | `reinstall` (body: hostname) | Rebuild (deletes all disks) | No single call; destroy + clone (inference) | U |
| Reset password | `reset_password`, needs qemu guest agent (s) | `password_reset`, by e-mail | U | Reset root password, server must be off | cloud-init re-run (inference) | U |
| Delete | `DELETE /servers/{id}`, returns an Action | `DELETE /v2/droplets/{id}` 204; also **delete by tag** | `DELETE /v2/instances/{id}` | Delete a Linode | `qm destroy` (+ `--purge`) | U |
| Resize | `change_type`, off, disk upgrade is one-way (s) | `resize` (+ `disk:true`) | `PATCH` plan | Resize | Config change | U |
| Catalogue | `/server_types` (prices and `available` per location), `/locations`, `/images` | `/v2/sizes` (regions, available, prices), `/v2/regions`, `/v2/images` | Plans, regions, OS services (paths U) | Types (prices), regions, images; per-region availability U | Nodes, templates, storage (owner-defined) | Plans, templates (U) |
| SSH keys | `/ssh_keys` (name unique per project); `ssh_keys` at create | Account keys; `ssh_keys` at create | `sshkey_id[]` at create | `authorized_keys` or `authorized_users` at create | cloud-init `sshkeys` | U |
| Labels / tags | Key=value labels, `label_selector` filter | Plain tags, `tag_name` filter | `tags[]`, `label`; filter U | Plain tags and `label`, `X-Filter` header | `tags` (meta only), `description`, `pool` | U |
| Unique name | Yes per project (409 `uniqueness_error`) | U | U | U | `vmid` unique, caller-chosen | U |
| Idempotency key on create | Not seen | Not seen | Not seen | Not seen | Not seen (caller-chosen `vmid` gives one) | U |
| Rate limit | 3600/h per project, 1/s refill; `RateLimit-*` headers; 429 | 5000/h and 250/min; `ratelimit-*`; 429 | Not published; client default 500 ms spacing, 3 retries | 1600/min, list GETs 200/min, **creates 20 per 15 s**; `X-RateLimit-*`, `Retry-After` | None stated | U |
| Billing | Lower of hourly and monthly; off servers billed until deleted (PM0 FAQ reading, 7.9.4) | Hourly and monthly prices; off billing U | U | Hourly U; `billing_suspension` status | Self-hosted | Self-hosted |
| Console | `request_console` (wss URL valid 1 min + VNC password) | U | U | U (Lish) | vncproxy / terminal (s) | U |
| Snapshots / backups | `create_image`, `enable_backup` | `snapshot`, `enable_backups` | `snapshot_id`, `backups` at create | Snapshot, backups | `qm snapshot`/`rollback` (s) | U |
| Firewall | `/firewalls`, `firewalls` at create | `/v2/firewalls` | `firewall_group_id` at create | `firewall_id` at create | Per-NIC `firewall=1`, PVE firewall | U |
| IPv6-only | `public_net.enable_ipv4: false` | U | `enable_ipv6` (IPv6-only U) | Public IPv4 `addresses: []` (new interfaces) | `ipconfig ip6=` | U |
| IP assignment | Automatic; Primary IPs billed separately | Automatic; reserved IPs | Automatic; `reserved_ipv4` | Automatic | **Caller or DHCP** (no IPAM seen) | IP pools (U) |

**What the comparison says (opinion).**

1. **Every provider read is asynchronous on create.** PENDING provisioning (2.5 item 1, PM5a) is required for any
   server driver, not a Hetzner quirk.
2. **None offers an idempotency key.** Idempotency must be built from a label or tag the driver can search, plus a
   deterministic name where names are unique (7.3).
3. **Password handling splits three ways:** returned once (Hetzner, maybe Vultr), set by the caller (Linode,
   Proxmox), or e-mailed by the provider (DigitalOcean reset). The contract has to cover all three (7.6).
4. **Self-hosted drivers (Proxmox, Virtualizor) have no catalogue, no billing and no IP management:** the owner defines
   plans and IP pools. They fit the same contract, with capabilities and a larger connection schema.
5. **Deletes are dangerous in different ways:** DigitalOcean can delete by tag. The contract forbids any bulk or
   selector delete (7.5).

### 7.2 The contract

```php
interface ServerDriverInterface extends ProviderDriverInterface
{
    public const VERSION = 1;

    /** Catalogue for the plan form and the pre-sale check: sizes (with price if known), locations, images, availability. */
    public function catalogue(Connection $c): ServerCatalogue;

    /** Find a server created for this label (lost response, retry). Null when none. Never matches by name alone. */
    public function findByLabel(Connection $c, IdempotencyLabel $label): ?RemoteServer;

    /**
     * Start creating. Must not block on completion. Returns the remote id if known, an operation reference to poll,
     * and a one-time secret if the provider returned one (Hetzner) or the driver generated it (Linode, Proxmox).
     */
    public function create(Connection $c, CreateServerRequest $request): CreateServerResult;

    /** Progress of an operation started by create/power/rebuild/delete: RUNNING, SUCCEEDED, FAILED(reason). */
    public function operation(Connection $c, OperationRef $ref): OperationState;

    /** The normalised state of one server: PENDING, RUNNING, OFF, TRANSITION, ERROR, GONE, plus IPs and labels. */
    public function get(Connection $c, string $remoteId): RemoteServer;

    /** @return iterable<RemoteServer> every server this install manages on the connection (paged inside) */
    public function listManaged(Connection $c, string $instanceLabel): iterable;

    public function powerOn(Connection $c, string $remoteId): OperationRef;
    public function powerOff(Connection $c, string $remoteId, bool $hard): OperationRef;
    public function reboot(Connection $c, string $remoteId, bool $hard): OperationRef;

    /** Deletes exactly one server. The driver re-reads it first and refuses unless its labels match $expected. */
    public function delete(Connection $c, string $remoteId, IdempotencyLabel $expected): OperationRef;

    /** Last seen request budget (from rate-limit headers), so jobs stop early. Null when the provider has none. */
    public function requestBudget(Connection $c): ?RequestBudget;
}
```

Optional capabilities, each its own interface as in the VPN contract:

| Capability | Interface method | Hetzner | DO | Vultr | Linode | Proxmox | Notes |
|---|---|---|---|---|---|---|---|
| `rebuild` | `rebuild(remoteId, image)` | yes | yes | yes | yes | no (inference) | Destroys data: a confirmed customer action |
| `reset_password` | `resetPassword(remoteId)` | yes (agent) | e-mail only | U | yes (off) | via cloud-init | `password_via_email` is a separate flag; no use for a customer of ours |
| `console` | `consoleUrl(remoteId)` | yes | U | U | U | yes | Short-lived URL, shown once |
| `snapshots` | `snapshot`, `listSnapshots` | yes | yes | yes | yes | yes | Paid extra at clouds: a paid add-on (2.5 item 6) |
| `backups` | `enableBackups` | yes | yes | yes | yes | no | Same |
| `firewall` | `firewall` at create | yes | yes | yes | yes | yes | Default policy for abuse control (7.8) |
| `ipv6_only` | flag at create | yes | U | U | yes | yes | Cheaper at Hetzner (no Primary IPv4) |
| `resize` | `resize(remoteId, size)` | yes | yes | yes | yes | yes | Paid add-on; one-way disk caveat |
| `ssh_keys` | keys at create | yes | yes | yes | yes | yes | Default (7.6) |
| `returns_root_password` | — (flag) | yes | no | U | no | no | Decides the secret path |
| `caller_sets_password` | — (flag) | no | no | U | yes | yes | Driver generates with `random_bytes` |
| `hard_power` | `$hard = true` honoured | yes | yes | U | no | yes | Else hard = graceful + timeout |
| `catalogue_prices` | prices in `catalogue()` | yes | yes | U | yes | no | Pre-sale cost check (7.4) |
| `label_kv` / `tags` | how `IdempotencyLabel` is stored | kv | tags | tags + label | tags + label | tags/description | Driver encodes the label |

### 7.3 Idempotency

The same steps for every driver, because no provider offers a key:

1. In the settlement transaction, `ServerProductModule::provision()` inserts the service (`provisioning`) and its
   `server_instance` row (`state = queued`, `idempotency_label` from the order and instance, for example
   `amoobot-<instance>-o<orderId>`), and returns `PENDING`. **No remote call.** A retried settlement finds the row
   (unique `service_id`) and does nothing.
2. The job, outside any transaction: `findByLabel()` first; adopt if found.
3. Otherwise `create()` with the label as a tag or label, plus `managed-by=amoobot` and the instance. Where names are
   unique (Hetzner), the name is the label too, and a name conflict leads to `findByLabel()`, never to a second
   create. A name match with wrong labels stops for an admin, the same rule as `findAdoptableClient()`
   (`VpnProvisioningService.php:346`).
4. Save `remote_id` and `operation_ref` in their own commit before anything else.
5. Poll `operation()` by `next_check_at` (no loops, no sleeps). On success: `get()`, store IPs, set the service
   `active`, deliver.

### 7.4 Lifecycle policy (generic)

| Stage | Trigger | Action | Customer |
|---|---|---|---|
| Reminders | Days before expiry | Existing expiry notification | As today |
| Expired | `expires_at` passed | Service `expired`, `powerOff(hard: false)`, then `hard` after a timeout | Told, with the delete date |
| Grace | Before `grace_delete_at` | Stays off; renewal allowed, `powerOn` on payment | Can renew |
| Delete | Grace over | `delete()` with all guards (7.5), service `deleted` | Told |
| Failure after payment | Create failed (capacity, limit, bad request) | Service `failed`, order `PROVISIONING_FAILED`, refund path: to wallet automatically or by admin (open question) | Told, with the refund |

The policy values (grace days, timeout, whether a powered-off server counts as suspended) come from
`lifecyclePolicy()` and settings of the server install. **The job re-checks power state every pass,** because a
provider can power a server back on (Hetzner's FAQ: after a live migration, 7.9.4). A pre-sale check compares the
plan's size and location with `catalogue()` (availability and, with `catalogue_prices`, the price) before payment is
accepted, and again before `create()`.

### 7.5 Delete guards (every driver, enforced by the module, tested by the kit)

- Status and dates re-read under a row lock.
- No open renewal order or pending payment for the service (`ServiceOrdersInFlight` is the VPN equivalent).
- `delete()` re-reads the remote server and refuses unless the id, the instance label and the order label match.
- One id at a time. **Never delete by list, tag or selector** (DigitalOcean allows `DELETE /v2/droplets?tag_name=`; the
  driver must not use it).
- Never touch a server without `managed-by=amoobot`.
- A per-run cap (`max_deletes_per_run`, 3.4). Above it, stop and alert: the circuit breaker for a clock or data bug.
- A `--dry-run`, and a switch `server.auto_delete_enabled`, **off by default**: until it is on, a due delete becomes an
  admin approval.

### 7.6 Secrets

- **Connection secret:** `provider_connection.secrets`, `SecretCipher`, never shown after saving (3.3).
- **Root password: prefer SSH keys.** Offer the customer an SSH key first; all six providers accept keys at create.
- When a password is used, whichever way it arrives (returned by the provider, generated by the driver): it is a
  one-time secret. Stored encrypted in `server_instance.secret_enc` for at most 24 hours (proposal), cleared on reveal
  or expiry in the same transaction that records the reveal. A lost provider response means a lost password;
  `reset_password` (where available) is the recovery.
- **The current logging would capture it.** `TelegramApiClient` logs every outgoing message to `bot_message_log`, and
  `BotMessageLogger::redact()` masks links and tokens, not passwords. A dedicated send path that logs a placeholder is
  required, plus a test. Better (opinion, default): the message links to a Mini App one-time reveal, and tells the
  customer to change the password.
- Add `root_password`, `password`, `default_password`, `root_pass`, `cipassword` to `SecretRedactor` keys. Never put
  an HTTP body in an exception, a `last_error` or `Payment.provisioningError`.

### 7.7 Rate limits and failures (generic)

- The driver reads the provider's budget headers (`RateLimit-*`, `ratelimit-*`, `X-RateLimit-*`, `Retry-After`) and
  exposes `requestBudget()`. A job pass stops below `request_budget_floor` and leaves room for creates and power
  actions.
- Sync uses `listManaged()` (paged), not one call per server.
- Errors are classified by the driver into: `retry_later` (429, 5xx, timeouts, conflicts, locked), `capacity`
  (resource unavailable, placement), `account_limit`, `auth` (stop all work on the connection, alert once),
  `bad_request` (no retry, alert), `gone` (reconcile, never recreate automatically). The module acts on the class,
  not on provider codes. Hetzner's mapping is 7.9.6.
- **Reconcile job:** compare `server_instance` rows with `listManaged()`. A server with no service and a service with
  no server both alert. No automatic delete here.

### 7.8 Abuse and account separation (generic)

- **A provider account separate from the one hosting Amoobot. Hard requirement,** for every cloud driver. Limits,
  suspensions and abuse complaints are per account; the "same server" decision (section 5) makes the Amoobot box a
  single point of failure for both brands, so its account must not carry customer servers.
- Controls: no trials, ordering only above a trust level (policy), a per-customer server cap, a firewall at creation
  where `firewall` exists, an admin kill switch (`suspend` with reason `abuse`, no self-resume), and a record of who
  held which IP when (`server_instance` keeps it after delete).
- `sales.new_orders_enabled` (`SalesSettingsProvider`) already stops all new orders.
- Each provider's reselling terms and customer-location rules must be read before its driver is sold (section 11).

### 7.9 Worked example: a Hetzner Cloud driver (from PM0)

This is PM0's Hetzner section, kept as **one example of a driver**, not as the design. In the generic design the
`hetzner_account` and `hetzner_server` tables below become `provider_connection` (driver `hetzner_cloud`) and
`server_instance` with `driver_data` (3.2); the names show what this driver keeps.

Source: Hetzner's published OpenAPI spec (`docs.hetzner.cloud/cloud.spec.json`, fetched 2026-11-06), its changelog and
FAQs. Values can change: if Hetzner is chosen as the first driver, PM7a confirms them against a real test project.

#### 7.9.1 API calls

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

#### 7.9.2 Data and idempotency

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

#### 7.9.3 Secrets

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

#### 7.9.4 Lifecycle

| Stage | Trigger | Action |
|---|---|---|
| Reminders | Days before expiry | Existing expiry notification |
| Expired | `expires_at` passed | Service `expired`. `shutdown`, then `poweroff` after a timeout. Customer told, with delete date |
| Grace | `grace_delete_at` not passed | Server off. Renewal allowed, `poweron` on payment |
| Delete | Grace over | `DELETE /servers/{id}`, service `deleted` |

**Billing fact:** a powered-off server is billed until deleted ("regardless of whether it is turned on or not"), so
every day of grace costs the owner. Usage is rounded up to whole hours; Primary IPs are billed separately until deleted.
Grace length and whether customers pay for it are business decisions (section 11).

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

#### 7.9.5 Rate limits

Default **3600 requests per hour per project**, recovering one per second. Bursts are allowed. Headers `RateLimit-Limit`,
`RateLimit-Remaining`, `RateLimit-Reset`; exceeded means `429 rate_limit_exceeded`.

- Read `RateLimit-Remaining` on each response. Stop the pass below a floor (say 200) and leave time for creates and
  power actions.
- Sync through one `GET /servers?label_selector=managed-by=amoobot` with paging, not one call per server.
- Poll actions by `next_check_at`, at 10 seconds or more, as Hetzner advises against frequent polling.
- One job at a time per account, in a new lock group (`modules`), not `lifecycle`. A Hetzner stall must not delay VPN
  suspend.

#### 7.9.6 Failure handling

| Case | Codes | Handling |
|---|---|---|
| Retry later | `429`, `500 server_error`, `502 bad_gateway`, `503 unavailable`, `504 timeout`, `409 conflict`, `423 locked`, network errors | Backoff through `next_check_at`, capped attempts, then alert |
| Capacity | `412 resource_unavailable`, `422 placement_error` | Try the plan's fallback location if one is set. Else alert and go to the refund path (2.5, 7.4). Hetzner's FAQ says some location blocks lift by the next day |
| Account limit | `403 resource_limit_exceeded` | Stop new creates for the account. Alert. Limit increases need a request, and are possible only after a month and a paid first invoice |
| Maintenance | `403 maintenance` | Retry later |
| Auth | `401 unauthorized`, `403 forbidden` | Stop all work on the account. Alert once |
| Bad request | `422 invalid_input`, `400 json_error` | No retry. Alert. A code or catalogue bug |
| Server gone | `404` on a known id | Reconcile, never recreate automatically. Alert |
| Unknown result | Timeout during `POST` | Next pass adopts by label (7.9.2) |
| Stuck | `running` for longer than a limit | Alert |

**Reconcile job:** compare services to `GET /servers`. Paid-for-nothing (a server with no service) and
charged-with-no-service (a service with no server) both alert. Automatic delete is not allowed here.

Secrets and customer data stay out of `last_error`, following `ServiceErrorText::redact()`.

#### 7.9.7 Abuse and account separation

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
- Reselling terms and customer location rules: check Hetzner's terms before launch (section 11). This document makes no
  claim about them.

## 8. Contract test kit

Every driver, built in or packaged, must pass the same shared tests. Every core test that needs a product uses a fake
module or a fake driver, never 3x-ui fixtures by accident.

### 8.1 What exists (facts)

- `tests/Support/Fake3xui/Fake3xuiPanel.php`, `Fake3xuiWiring.php`: an in-process fake of the 3x-ui modern API, used by
  the provisioning, multi-inbound and move tests.
- `tests/Support/ProgrammableVpnPanelDriver.php` (357 lines): a scriptable `VpnPanelDriverInterface` for core tests.
- `tests/Support/MockHttpResponder.php`: recorded HTTP answers.
- `tests/Support/plugins/*`: installable plugin fixtures (gateways).

### 8.2 Shared contract tests

Abstract PHPUnit cases in `tests/Contract/`. A driver's test extends one and provides a backend: a fake server
(`Fake3xuiPanel`, a new `FakeMarzban`, recorded responses) or a sandbox account in an opt-in group that CI does not run.

`VpnDriverContractTestCase`:

- Create, then create again for the same order: one remote user (`adopt_existing`, or a typed conflict).
- Lost response: the create succeeded remotely but the call threw; the retry adopts.
- Renew sets limit and expiry exactly; units round-trip (GB, timestamps, time zone).
- Suspend then resume (or renew with 0 days): the user works again with the same links.
- Delete twice: both succeed.
- Usage of an unknown user: a typed `RemoteNotFound`, not a generic exception.
- **Capability honesty:** each declared capability's test runs and passes; each undeclared one throws
  `CapabilityNotSupported`. The manifest list is a ceiling for `capabilities()`.
- `panel_sub_links`: the stored snapshot equals the panel's answer; an empty answer never replaces stored links (the
  H0 tests, generalised).
- Health check classifies: wrong token = `auth`, closed port = `network`, a 5xx = `other`, a too-old version = `version`.
- **No secret leaks:** a token and a password in the connection never appear in an exception message, a log record
  (`RecordingLogger`) or a returned DTO.

`ServerDriverContractTestCase`:

- `create` returns without waiting; `operation` goes RUNNING → SUCCEEDED; `get` maps every provider status to one of
  the normalised states (a table test over all documented values; unknown maps to `TRANSITION`, never `GONE`).
- `findByLabel` after a lost create response finds the server; a name match with a foreign label is refused.
- `delete` refuses a label mismatch, a server without `managed-by`, and never calls a list or selector delete
  (asserted on the recorded requests).
- Rate-limit headers update `requestBudget`; a 429 is classified `retry_later` with the reset time.
- Error classification table for the provider's documented codes.
- One-time secret: returned once by `create` (or generated), and never present in logs or exceptions.

**Static checks on packages** (in `app:plugin:doctor` and the kit): no `eval`, `unserialize`, `include` outside the
package's `src/`, no `exec`/`shell_exec`/`proc_open`, no reads of `$_ENV`/`getenv`, file hashes as in the manifest.

### 8.3 Fakes for core tests

- `FakeVpnDriver`: in memory, configurable capability set, scriptable failures. Grows out of
  `ProgrammableVpnPanelDriver`; it must pass the VPN contract case itself, so core tests run against honest behaviour.
- `FakeServerDriver`: in memory, asynchronous (operations finish after N polls), scriptable capacity, limit, auth and
  lost-response failures, rate-limit headers. Passes the server contract case.
- `FakeProductModule` (`type = 'fake'`): `DELIVERED` or `PENDING` by configuration, used to test settlement, the job
  runner, gating and the disabled-module rules (4.6) without VPN or server code.
- A **disabled-module suite**: the whole bot, Mini App and admin route set crawled with `vpn` off; no 500, no VPN text
  (PM3b).

## 9. PR plan

**Rule (owner):** `develop/v3` is deployed to production. Every PR is either behaviour-neutral, proven by the
unchanged suite, or its new behaviour sits behind a switch that is **off by default**. The "Switch" column names it, or
says why none is needed. Every PR needs `php bin/phpunit` against MariaDB 10.11, PHPStan level 5 and
`lint:container` (`.github/workflows/ci.yml`). Sizes and risks are estimates.

**Renumbering against PM0.** PM1a-PM1d and PM4a-PM4d keep their content. Old PM2 (`product_type`) is now **PM2a**.
Old PM3 (module gating by `APP_MODULES`) is now **PM3b**, driven by plugin rows (4.7). Old PM5 (PENDING) is **PM5a**.
Old PM6-PM11 (Hetzner) are now the generic server module (PM6) and a **first server driver whose provider is not yet
chosen** (PM7-PM11). New: PM2b (plugin loading), PM3a (capability gating), PM5b (connections). PM12-PM19 stay with
`admin-panel-audit.md`. The new Marzban and PasarGuard phases are **PM20** and **PM21**, after it, so no existing number
moves.

| Phase | Content | Switch | Risk | Test plan |
|---|---|---|---|---|
| **PM0 / PM0b** | These documents | None | None | Review |
| **PM1a** | Move `SecretRedactor`, `PublicUrlPolicy` to Shared. Add `ProductModuleInterface`, `ProviderDriverInterface`, `VpnDriverInterface` (extending the existing interface; 3x-ui drivers and `dummy` get the new `implements` line and `code()`/`capabilities()`), `CapabilitySet`, both registries, `VpnProductModule` delegating to existing classes. **Nothing calls the new code.** 3x-ui is the first VPN driver | None needed: unused code | Low | Suite unchanged. New: VPN contract case run on `Sanaei3xuiModernDriver` with `Fake3xuiPanel` and on `dummy`; capability set of each 3x-ui driver equals its `instanceof` set |
| **PM1b** | `PaymentSettlementService::fulfil()` goes through `ProductModuleRegistry`. VPN still `DELIVERED`, in the transaction | None, on purpose: a second code path in the money path is riskier than the refactor | **High** (money path, small diff) | No test file changes. New test pins the call order. `tests/Payment` is the proof |
| **PM1c** | Suspend, resume, terminate, expiry jobs through the module | None: neutral | Medium | Suite unchanged |
| **PM1d** | `customerView` / `customerActions` for bot and Mini App, VPN output byte-identical | None: neutral | Medium | Snapshot tests of bot messages and Mini App JSON first, in a separate preceding PR; then suite unchanged |
| **PM2a** | `plan.product_type`, `plan.module_config`, `vpn_service.product_type` with default `vpn`; unknown type fails closed | None: additive, defaults reproduce today | Low | Migration on a copy of production, timed; old rows read as `vpn`; a row with type `x` fails closed in every job |
| **PM2b** | Plugin loading for drivers: `Plugin::TYPES` + `vpn_driver`, `server_driver`; `plugin.source`; core rows inserted as `enabled` (vpn, sanaei_3xui, dummy) and `server` as `disabled`; manifest v2, `requires` and contract checks, file hashes, doctor for driver types; `app:product:list-modules`; `PLUGIN_UPLOAD_ENABLED` | Installing a driver package is the switch; no package exists. `server` row disabled; `APP_MODULES_ALLOWED=vpn` ceiling | Medium (plugin table migration) | Gateway plugin tests unchanged; new fixture `tests/Support/plugins/fake-vpn-driver` installs, passes the contract kit, enables, disables; a wrong hash, wrong contract or wrong core version ends in `error` |
| **PM3a** | Capability gating: `MultiInboundSale.php:53`, `PlanSaleRule`, `PanelLinkSnapshot`, attach, moves, import, claims, traffic add-on and the `sanaei_3xui` branches of 6.4 ask capabilities, not the type. Same answers for every existing panel | None: neutral (3x-ui modern declares everything it implements, legacy the three it has) | Medium (many files) | Suite unchanged. New: each gate tested with `FakeVpnDriver` lacking that capability |
| **PM3b** | Module enable/disable gating of menu, pages, plans, settings, dashboard, bot, Mini App, public routes, texts, timers (4.7); disabled-driver and disabled-module rules (4.6) | `vpn` is enabled on every existing install; turning it off is the switch | Medium | Suite unchanged with `vpn` on; disabled-module crawl suite (8.3); disabled driver: nothing remote is called, nothing deleted |
| **PM4a** | `INSTANCE` in `deploy.sh` and scripts; empty changes nothing | `INSTANCE` empty by default | **High** (root, production) | `tests/ops` golden comparison of rendered units and files; `--dry-run` |
| **PM4b** | Per-instance FPM pool, Unix user, nginx names | Only with a non-empty `INSTANCE` | High | Scratch VM rehearsal |
| **PM4c** | Backups, restore rule, check-jobs, post-deploy-check per instance | Same | Medium | Restore rehearsal for each brand |
| **PM4d** | Runbook; install the second, empty brand (no module yet, its own gateways) | An operator action | Medium | Real install on the box; first brand's jobs, backup and webhook confirmed untouched |
| **PM5a** | Core support for `PENDING`: service status `provisioning`, the `modules` job task and timer, one-time secret delivery path and Mini App reveal, refund path. VPN never uses it | Unused until a module returns `PENDING`; the task exits 0 with nothing to do | **High** | `FakeProductModule` end to end; retries; crash between each step; the secret never in `bot_message_log` or logs |
| **PM5b** | `provider_connection` with encrypted secrets, health, limits; `Connection` interface; `VpnPanel` adapter; `app:connection:doctor`; `plan.connection_id` | Only used by modules that are disabled; VPN reads `vpn_panel` as before | Medium | Secrets encrypted at rest and redacted; degraded rule as for panels; `FakeServerDriver` connection round trip |
| **PM6** | `ServerProductModule` with `server_instance`, lifecycle policy, delete guards, pre-sale catalogue check, admin pages, plan schema; works with `FakeServerDriver` only | Module `server` disabled, and not allowed on the VPN install (`APP_MODULES_ALLOWED`) | High | Server contract case on `FakeServerDriver`; every delete guard has a test that proves it blocks; disabled-module rules |
| **PM7a** | First server driver (provider chosen by the owner, section 11): client, catalogue, read-only `app:connection:doctor`; a spike against a test account confirms the facts of 7.1 for it | Driver row disabled | Low | `MockHttpResponder` with recorded answers; contract kit (read-only parts) |
| **PM7b** | That driver's create, `findByLabel`, polling, delivery | Driver and module disabled | High | Full server contract case; lost-response adoption; every error class |
| **PM8** | Lifecycle, reconcile, deletes | `server.auto_delete_enabled` off: due deletes become admin approvals | **High** (irreversible) | Dry run on staging; per-run cap; power-state recheck |
| **PM9** | Customer actions by capability: rebuild, password reset, power, console | Module disabled | Medium | Ownership checks and rate limits on actions; capability absent = no button |
| **PM10** | Abuse controls, connection limits, cost estimate on the dashboard | Module disabled | Medium | `tests/Admin` patterns |
| **PM11** | Soak on the server install with internal orders, then a limited sale; `server.auto_delete_enabled` last | Operator switches, one at a time | Medium | Checklist; real money, small amounts |
| **PM12-PM19** | `admin-panel-audit.md` section 7 | Unchanged | | |
| **PM20** | Marzban VPN driver as a package (`docs/plugins/marzban-driver/`), `FakeMarzban`; maps inbound tags (6.2); capabilities as 6.3, `inbound_move` only if its test passes on a real panel | Package not installed; then installed but no connection; then one connection with a test plan | Medium | VPN contract kit on `FakeMarzban`; a recorded session against a real panel of the tested version |
| **PM21** | PasarGuard VPN driver as a package; groups as units (6.2); API key auth preferred over JWT | Same | Medium | Same, with `FakePasarGuard` |

**Order and dependencies.** PM1a → PM1b → PM1c → PM1d → PM2a → PM2b → PM3a → PM3b. PM4 (multi-install) depends on
nothing above and can run in parallel. PM5a/PM5b need PM1-PM2. PM6 needs PM5. PM7 needs PM6. PM20/PM21 need only PM1a,
PM2b and PM3a: the Marzban driver can ship before any server work (opinion: a good way to prove the contract on a
second, simpler product of the same kind).

PM1b is the smallest diff with the largest blast radius, so it goes alone.

## 10. What this design did not check

- No production data: row counts, table sizes, real RAM headroom on the box, and PHP-FPM worker counts are unknown.
- No code was run. Counts are `grep` results, not a parse. The Doctrine filter limits in 5.2 and the nginx zone
  collision in 5.1 are from knowledge of those tools, not tested here.
- No panel and no provider was exercised. Section 6.1 and 7.1 come from reading source and docs; "(s)" and
  UNCONFIRMED mark what was summarised or not read. Vultr was read only through its Go client; Virtualizor not at all;
  Proxmox only through its CLI reference.
- Lines in sections 1, 5 and 7.9 are from `b485bfb` (see the note at the top).
- Estimates assume the working style of this repository: small reviewed PRs with tests.

## 11. Open questions

Answered since PM0 and removed: which option for two brands (multi-install), whether Hetzner is the design (no:
generic), whether Marzban/PasarGuard are drivers or modules (drivers), whether `APP_MODULES` is env (rows, with an env
ceiling), currency and payment gateway coupling to product (none: gateways per install).

**Business**

1. Which server provider first? The comparison (7.1) favours a provider with key/value labels, a published rate
   limit and a returned or caller-set password, but the choice is commercial.
2. For each provider considered: are its **reselling terms** compatible with this business, and are there
   **customer-location restrictions** that affect this customer base? Needs reading by the owner, ideally with advice.
   This document makes no claim about them.
3. **Currency:** the wallet is fixed to `IRT` (`Wallet::CURRENCY`), while provider costs are in another currency. Who
   absorbs exchange-rate changes, and how often do server prices change?
4. **Billing period:** monthly only, or also shorter terms? (Most clouds bill hourly; Amoobot sells periods.)
5. **Grace** after expiry: how many days, and who pays for them, given that providers that bill off servers keep
   charging?
6. **Refunds** when a paid server can't be created: to the wallet automatically, or by the admin?
7. **Abuse:** trust level before ordering, per-customer limit, identity checks, what happens on a complaint?
8. Which account or company holds each provider account, and how is it paid?
9. Resellers, discounts and claims on the server install in the first version? (Assumed: no resellers, no claims, no
   trials.)
10. Support model for a customer locked out of a server: console, rescue, reinstall only?
11. How many brands in the next two years? (A third brand is a third install under section 5.)
12. Is "same server" final? Both brands fail together and share an IP. Must they be unlinkable to an observer?

**Technical**

13. 3x-ui modern and legacy: two driver codes, or one with probe-dependent capabilities (2.3)? And should the legacy
    driver be retired, given that it can't follow the H0 rule?
14. Customers' view of a disabled module's services: read-only list, or hidden (4.6)?
15. SSH key only, password only, or both? Opinion: both, default key, reveal in the Mini App only.
16. Images: official only, or prepared snapshots?
17. Should the `Order.status` value `provisioned` be split, or is "paid and queued" acceptable under that name (2.5)?
18. Rename `VpnService` to a neutral name after PM3, or keep it for good?
19. Package signing (4.5 item 3): now, or when a second person builds packages?
20. Should Marzban (PM20) go before the server module to prove the contract with a second VPN panel?
21. Can production table sizes and RAM headroom be provided, to time the migrations and size the second FPM pool?
22. Is a daily report or merged view across the two installs needed?
