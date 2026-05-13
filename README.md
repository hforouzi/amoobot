# Amoobot - Personal Telegram VPN Shop Bot (Phase 1 MVP)

Symfony 7 MVP for a self-hosted Telegram VPN shop bot with manual payment verification and dummy VPN provisioning.

## Stack
- PHP 8.2+
- Symfony 7.4
- Doctrine ORM + Migrations
- MySQL
- EasyAdmin
- Symfony HttpClient
- Symfony Messenger
- Symfony Validator
- Symfony Serializer
- Twig

## Installation
```bash
composer install --ignore-platform-req=ext-redis
cp .env .env.local
# edit .env.local values
```

## Environment Variables
Required:
- `DATABASE_URL`
- `TELEGRAM_BOT_TOKEN`
- `TELEGRAM_WEBHOOK_SECRET`
- `PAYMENT_CARD_NUMBER`
- `PAYMENT_CARD_HOLDER`

Optional:
- `TELEGRAM_ADMIN_CHAT_ID`
- `PAYMENT_DESCRIPTION`
- `ADMIN_PASSWORD` (default: admin)
- `TELEGRAM_MODE` (default: `long_polling`) — allowed: `webhook`, `long_polling`
- `TELEGRAM_PROXY` — optional outgoing proxy for Bot → Telegram API, e.g. `socks5://host:1080`
- `PANEL_PROXY_ENABLED` (default: `false`)
- `PANEL_PROXY_TYPE` (default: `socks5`) — allowed: `socks5`, `http`
- `PANEL_PROXY_HOST`
- `PANEL_PROXY_PORT`
- `PANEL_PROXY_USERNAME`
- `PANEL_PROXY_PASSWORD`
- `PANEL_PROXY_TIMEOUT` (default: `30`)
- `SERVICE_NOTIFY_EXPIRY_DAYS` (default: `3,1`)
- `SERVICE_NOTIFY_TRAFFIC_THRESHOLDS` (default: `80,95,100`)

## Database Migration
```bash
php bin/console doctrine:database:create --if-not-exists
php bin/console doctrine:migrations:migrate -n
```

## Create Sample Plans
```bash
php bin/console app:create-sample-plans
```

## Create Default Settings
```bash
php bin/console app:create-default-settings
```

## Run Local Server
```bash
symfony serve
# or
php -S 127.0.0.1:8000 -t public
```

## Admin Panel
- URL: `/admin`
- Username: `admin`
- Password: from `ADMIN_PASSWORD`

Manage:
- Users
- Telegram Accounts
- Plans
- Orders
- Payments
- VPN Panels
- VPN Inbounds
- VPN Services
- Bot Message Logs
- Settings

## Set Telegram Webhook
```bash
php bin/console app:telegram:set-webhook https://mydomain.com
```

Delete webhook:
```bash
php bin/console app:telegram:delete-webhook
```

## Local development without domain using Long Polling
Webhook requires a public HTTPS URL. Long polling does not require a domain and can run locally or on a private server.

```bash
php bin/console app:telegram:delete-webhook
php bin/console app:telegram:poll
```

One-shot poll:
```bash
php bin/console app:telegram:poll --once
```

Drop pending updates before polling:
```bash
php bin/console app:telegram:poll --drop-pending
```

Notes:
- Do not run webhook and long polling at the same time.
- Keep the terminal open while polling is running.
- While polling, callback updates print `callback_data=...` in console for temporary debug.

Webhook endpoint:
- `POST /telegram/webhook/{secret}`

## Telegram Bot Flow (Phase 1)
1. User sends `/start`.
2. Bot auto-registers TelegramAccount/User and shows main menu.
3. User clicks `🛒 خرید سرویس` and sees active plans.
4. User selects plan (`select_plan:{id}`).
5. Bot shows payment method selection (`select_store_payment_method:{orderId}:{storePaymentMethodId}`) from active store payment methods.
6. User selects `manual_card` or `zibal`; bot creates/reuses Payment from selected store method gateway and starts selected flow.
7. For manual card, user taps `✅ تایید و ارسال رسید` (`payment_submit_receipt:{paymentId}`) and then sends receipt photo or tracking text in chat.
8. Payment becomes `submitted`, admin sees it in `/admin`.
9. Admin confirms payment via `Confirm Payment` action.
10. System provisions via `DummyVpnPanelDriver` and sends subscription/config to user.
11. User can view non-deleted services from `📦 سرویسهای من`.

## Confirm/Reject Payment
In `/admin` -> Payments, use actions:
- `Confirm Payment` -> marks payment confirmed, order paid/provisioned, creates VPN service, notifies user.
- `Reject Payment` -> marks payment rejected and notifies user.

## Phase 1.7.2 Payment Gateway Architecture

### PaymentGateway setup (module/account config)
- Entity: `PaymentGateway` with supported types only:
  - `manual_card`
  - `zibal`
  - `custom_api`
- Manage gateways in `/admin` -> `درگاههای پرداخت`.
- Type-specific configuration pages:
  - `کانفیگ کارت به کارت`
  - `کانفیگ زیبال`
  - `کانفیگ Custom API`
- Gateway config fields:
  - `manual_card`: `card_number`, `card_holder`, `bank_name`, `instructions`
  - `zibal`: `merchant`, `sandbox`, `callback_base_url`, `description`, and optional advanced fields (`mobile`, `allowedCards`, `percentMode`, `feeMode`, `multiplexingAccountNumber`)
  - `custom_api`: `config_json` using `create`, `verify`, optional `webhook`, and `variables`

### Store Payment Methods setup (bot-visible methods)
- Entity: `StorePaymentMethod` controls user-visible payment choices in bot.
- Manage methods in `/admin` -> `روشهای پرداخت فروشگاه`.
- Each method links to one configured `PaymentGateway`.
- Only active store methods are shown to users.
- You can set: `title`, `isActive`, `sortOrder`, `minAmount`, `maxAmount`, `currency`.

### Admin setup flow
1. Go to `درگاههای پرداخت` and configure gateway credentials/accounts.
2. Go to `روشهای پرداخت فروشگاه`.
3. Create method rows pointing to configured gateways.
4. Activate desired methods.
5. Bot shows only active store payment methods.

### Zibal setup
- Create/configure a `zibal` gateway:
  ```json
  {
    "merchant": "zibal",
    "sandbox": true,
    "callback_base_url": "https://example.com"
  }
  ```
- Callback URL:
  - `GET/POST /payment/callback/zibal`
- Production example:
  ```json
  {
    "merchant": "YOUR_MERCHANT",
    "sandbox": false,
    "callback_base_url": "https://your-domain.com",
    "description": "Amoobot order payment"
  }
  ```

### Telegram online payment flow
- For `zibal` and `custom_api` payments bot sends:
  - `پرداخت آنلاین` (URL button)
  - `بررسی پرداخت` (`payment_check:{paymentId}`)
  - `انصراف` (`payment_cancel:{paymentId}`)

### Custom API gateway
- Use `custom_api` for simple HTTP-based gateways with create/verify endpoints.
- Use native drivers for complex gateways or advanced cryptographic/signature schemes.
- Internal config shape:
  ```json
  {
    "create": {
      "method": "POST",
      "url": "https://gateway.example.com/api/payment/create",
      "headers": {
        "Authorization": "Bearer {{api_key}}",
        "Content-Type": "application/json"
      },
      "body": {
        "amount": "{{amount}}",
        "order_id": "{{order_id}}",
        "payment_id": "{{payment_id}}",
        "callback_url": "{{callback_url}}",
        "description": "{{description}}",
        "currency": "{{currency}}"
      },
      "response_mapping": {
        "success": "success",
        "payment_url": "data.payment_url",
        "transaction_id": "data.transaction_id",
        "authority": "data.authority",
        "message": "message"
      }
    },
    "verify": {
      "method": "POST",
      "url": "https://gateway.example.com/api/payment/verify",
      "headers": {
        "Authorization": "Bearer {{api_key}}",
        "Content-Type": "application/json"
      },
      "body": {
        "transaction_id": "{{transaction_id}}",
        "authority": "{{authority}}",
        "amount": "{{amount}}",
        "payment_id": "{{payment_id}}"
      },
      "response_mapping": {
        "success": "success",
        "paid": "data.paid",
        "ref_id": "data.ref_id",
        "transaction_id": "data.transaction_id",
        "message": "message"
      }
    },
    "webhook": {
      "enabled": true,
      "secret_header": "X-Gateway-Signature",
      "secret": "CHANGE_ME",
      "payment_lookup": "transaction_id",
      "status_path": "status",
      "paid_values": ["paid", "success", "confirmed"]
    },
    "variables": {
      "api_key": "SECRET_VALUE"
    }
  }
  ```
- Supported placeholders:
  - `{{amount}}`, `{{payable_amount}}`, `{{order_id}}`, `{{payment_id}}`
  - `{{callback_url}}`, `{{webhook_url}}`, `{{description}}`
  - `{{transaction_id}}`, `{{authority}}`, `{{currency}}`
  - any key from `variables` (for example `{{api_key}}`)
- Dot-path response mapping is supported (example: `data.payment_url`).
- Callback URL (per gateway):
  - `GET/POST /payment/callback/custom-api/{gatewayId}`
- Webhook URL (per gateway):
  - `POST /payment/webhook/custom-api/{gatewayId}`
- Webhook secret limitation:
  - only static header secret comparison is supported (`hash_equals`)
  - complex signature verification is intentionally not part of `custom_api`

### Commands
- Create defaults:
  ```bash
  php bin/console app:payment:create-default-gateways
  ```
- List configured gateways:
  ```bash
  php bin/console app:payment:list-gateways
  ```
- List store methods:
  ```bash
  php bin/console app:payment:list-methods
  ```
- Test zibal request:
  ```bash
  php bin/console app:payment:test-zibal {gatewayId} --amount=10000
  ```
- Test custom_api request:
  ```bash
  php bin/console app:payment:test-custom-api {gatewayId} --amount=10000
  ```
- Debug gateway config safely:
  ```bash
  php bin/console app:payment:debug-gateway-config {gatewayId}
  ```

### Duplicate callback safety
- Zibal callback verification is always re-verified with gateway API.
- Payment confirmation is idempotent through existing approval checks, so repeated callbacks do not double-provision/renew/add-traffic.

## Phase 1.7.3 Incomplete Order Management and Navigation

- Custom new-service draft steps now keep navigation history and support back/cancel callbacks:
  - `draft_back:{draftId}`
  - `draft_cancel:{draftId}`
- After order creation, payment navigation uses order callbacks (not draft id):
  - `order_back_to_payment_methods:{orderId}`
  - `order_cancel:{orderId}`
  - `order_resume:{orderId}`
  - `discount_back:{orderId}`
  - `payment_methods_back:{orderId}`
- `/start` and main menu show an incomplete-order prompt when applicable:
  - `▶️ ادامه سفارش`
  - `🗑 حذف سفارش ناتمام`
  - `➕ سفارش جدید`
- Canceling incomplete flows marks records as cancelled/rejected; rows are not deleted.
- Incomplete expiration is configurable by admin setting:
  - `orders.incomplete_expire_hours` (default `24`)
- New command:
  ```bash
  php bin/console app:orders:expire-incomplete --dry-run
  php bin/console app:orders:expire-incomplete --hours=24 --limit=100
  ```
- Automation includes incomplete-order expiration (configurable):
  - `automation.expire_incomplete_orders_enabled` (default `true`)
  - `php bin/console app:automation:run --only=orders --dry-run`

## Admin approval from Telegram
- Set `TELEGRAM_ADMIN_CHAT_ID` in `.env.local` to the admin Telegram chat ID.
- After a user submits receipt photo/tracking text, bot sends admin a Telegram message with:
  - Payment ID
  - Order ID
  - User username/telegram ID
  - Plan title
  - Amount
  - Receipt/tracking info
- Admin receives inline buttons in Telegram:
  - `✅ تایید پرداخت`
  - `❌ رد پرداخت`
- Callback security:
  - Only `TELEGRAM_ADMIN_CHAT_ID` can execute admin confirm/reject callbacks.
  - Other users receive `Unauthorized`.
- Telegram admin approval reuses the same payment confirmation/rejection business logic as EasyAdmin.

## Telegram Admin Menu
- Set `TELEGRAM_ADMIN_CHAT_ID` in `.env.local`.
- Admin Telegram user sees an extra `🛠 مدیریت` button in main menu.
- Admin can open management menu and view:
  - `💳 پرداختهای در انتظار`
  - `👥 لیست کاربران`
  - `📦 لیست سرویسها`
  - `🧾 آخرین سفارشها`
- Admin can open pending payments, view payment details, and view receipt photos in Telegram.
- Admin can confirm/reject payments from Telegram using inline buttons.

## Service Management (Phase 1.2)
- Phase 1.2 introduces Telegram service management for users and admins.
- Service actions are handled with callback-driven action classes (`ServiceActionResolver` + action handlers) to keep update handling modular.
- Later phases added panel-synced actions for `sanaei_3xui` (usage/expiry/suspend/activate/delete/reset).

## User Service Actions
- `my_services`: shows latest non-deleted services as inline buttons.
- `service_view:{id}`: shows service detail page with status, expiry, traffic, subscription, and config preview.
- `service_subscription:{id}`: sends subscription link in a separate Telegram message.
- `service_resend_config:{id}`: re-sends config and service summary.
- `service_sync_usage:{id}`: syncs real traffic/expiry from panel and re-renders detail page.
- Users can access only their own services.

## Admin Service Actions
- `admin_services`: lists latest non-deleted services with admin detail entry buttons.
- `admin_service_view:{id}`: shows full service details and management actions.
- `service_suspend:{id}` / `service_activate:{id}`
- `service_delete:{id}` now opens a confirmation step and deletion is finalized by `service_delete_confirm:{id}`.
- `service_reset_usage:{id}`
- `service_sync_usage:{id}` (admin can sync any service usage)
- `service_extend_menu:{id}` -> `service_extend:{id}:{days}`
- `service_add_traffic_menu:{id}` -> `service_add_traffic:{id}:{gb}`
- `admin_user_view:{id}`: shows user detail summary for service context.
- Admin actions require configured `TELEGRAM_ADMIN_CHAT_ID`.

## Service Statuses
- `active` (🟢)
- `suspended` (⏸)
- `expired` (🔴)
- `deleted` (🗑)
- Status operations in Phase 1.2 update local `VpnService` fields only.

## Local-only vs Panel-synced Operations
- For services on `dummy` panel (or no panel), operations remain local DB updates.
- For services on `sanaei_3xui`, suspend/activate/delete/reset usage/refresh usage are synced with panel API first, then local DB is updated.
- If panel API action fails, local state is not silently changed and admin sees an alert.

## Phase 1.3 Sanaei / 3x-ui Driver
- Dummy driver remains available for local/testing fallback.
- Real provisioning is available for panel type `sanaei_3xui` (MHSanaei/3x-ui compatible).

### Create a VpnPanel for Sanaei
- In `/admin` -> VPN Panels create/edit:
  - `type`: `sanaei_3xui`
  - `baseUrl`: panel base URL (example: `https://panel.example.com`)
  - `apiVersion`: `legacy` or `v3`
  - `authMode`: `cookie` or `bearer`
  - `apiToken`: for v3 bearer auth (from panel settings)
  - `basePath`: optional when panel is installed under a sub-path (example: `/xui`)
  - `username` / `password`: panel login credentials for cookie mode
  - `config` JSON for global panel settings (no `inbound_id`):
    ```json
    {
      "subscription_base_url": "https://sub.boodbash.ir:8443",
      "subscription_path_prefix": "/rain",
      "public_host": "sub.boodbash.ir"
    }
    ```

### Sanaei/3x-ui API versions
Legacy panel:
```json
{
  "api_version": "legacy",
  "auth_mode": "cookie"
}
```

3x-ui v3+ Bearer token:
```json
{
  "api_version": "v3",
  "auth_mode": "bearer",
  "api_token": "YOUR_TOKEN",
  "subscription_base_url": "https://sub.example.com:8443",
  "subscription_path_prefix": "/rain"
}
```

- API token is from `Settings → Security → API Token` on 3x-ui.
- Bearer token mode skips CSRF and login cookie flow.
- v3 uses official `getClientLinks` endpoint when available.
- legacy panels continue manual link generation from `externalProxy`.

### Subscription URL format
- Subscription URL is built as:
  - `subscription_base_url + subscription_path_prefix + "/" + subId`
- Example:
  - `https://sub.boodbash.ir:8443/rain/95b2bf45859e2ee1`
- Required config keys:
  - `subscription_base_url`
  - `subscription_path_prefix`
- If `subscription_base_url` is missing, subscription URL is not generated and a warning is logged.

### Correct Phase 1.3 setup flow (Admin UX)
1. Create `VpnPanel`.
2. In VPN Panels click `تست اتصال`.
3. In VPN Panels click `همگامسازی اینباندها`.
4. In VPN Inbounds edit metadata (`title`, `country`, `location`) if needed.
5. In Plans assign `VpnInbound` (`اینباند / سرور`) to each plan.
6. User buys plan from bot.
7. Admin approves payment.
8. Client is created in selected inbound.

### Test panel commands
```bash
php bin/console app:panel:test-login {panelId}
php bin/console app:panel:debug-transport {panelId}
php bin/console app:panel:list-inbounds {panelId}
php bin/console app:panel:detect-version {panelId} [--apply]
php bin/console app:panel:sync-inbounds {panelId}
php bin/console app:panel:test-create-client {inboundId}
php bin/console app:panel:test-client-links {serviceId}
```

### CLI equivalents
```bash
php bin/console app:panel:test-login 1
php bin/console app:panel:debug-transport 1
php bin/console app:panel:detect-version 1
php bin/console app:panel:sync-inbounds 1
php bin/console app:panel:test-create-client 3
php bin/console app:panel:test-client-links 10
php bin/console app:service:debug-links 10
php bin/console app:service:regenerate-config 10
```

### Regenerate existing service links
Use this command to regenerate config links and subscription URL for an existing service:
```bash
php bin/console app:service:regenerate-config {serviceId}
```

## Phase 1.4.1 Usage Sync
- Sync real usage from panel and store latest lifecycle fields in `VpnService`.
- Expiry checker marks `active` services as `expired` when `expiresAt` has passed.
- `expiresAt = null` means unlimited service and is never auto-expired.
- Telegram user/admin service detail now shows updated usage/expiry and last usage sync time.

Commands:
```bash
php bin/console app:service:sync-usage
php bin/console app:service:check-expiry
php bin/console app:service:send-notifications
php bin/console app:automation:run --limit=100
```

Old split cron example:
```bash
*/10 * * * * php /path/bin/console app:service:sync-usage
*/15 * * * * php /path/bin/console app:service:check-expiry
*/20 * * * * php /path/bin/console app:service:send-notifications
```

Safe single-command cron example:
```bash
*/10 * * * * cd /path/to/project && php bin/console app:automation:run --limit=100 > var/log/automation.log 2>&1
```

Automation execution order:
1. expire incomplete orders
2. sync usage
3. check expiry
4. auto suspend
5. notifications

## Phase 1.4.3 Renewal Flow
- User can renew an existing service directly from service detail (`🔄 تمدید سرویس`).
- Renewal creates `Order(type=renewal)` + `Payment(manual_card)` and reuses receipt/admin approval flow.
- On admin confirmation:
  - existing service is updated (not duplicated),
  - panel renew API is called,
  - local `expiresAt`/`trafficLimitGb` is updated.
- Renewal policies are configurable via `Setting`:
  - `renewal.carry_remaining_traffic` (default `true`)
  - `renewal.carry_remaining_days` (default `true`)
  - `renewal.expired_start_from_now` (default `true`)
  - env fallback: `RENEWAL_CARRY_REMAINING_TRAFFIC`, `RENEWAL_CARRY_REMAINING_DAYS`, `RENEWAL_EXPIRED_START_FROM_NOW`
- EasyAdmin page `/admin/settings/renewal-pricing` (menu: `تنظیمات تمدید و قیمتگذاری`) lets admin edit:
  - حفظ حجم باقیمانده هنگام تمدید
  - حفظ روزهای باقیمانده هنگام تمدید
  - تمدید سرویس منقضیشده از امروز شروع شود
  - درصد تخفیف سراسری (0..100)
- Traffic rule:
  - if carry remaining traffic = `true`, renewal traffic is added to existing limit
  - if carry remaining traffic = `false`, limit is replaced by renewal package traffic and usage is reset
- Expiry rule:
  - if current expiry is in future and carry remaining days = `true`, extend from current expiry
  - if current expiry is in future and carry remaining days = `false`, start from now
  - if expired, renewal starts from now
  - unlimited renewal keeps expiry unlimited
- Renewal pricing uses **current plan pricing**, not old order amount.
- Global discount setting:
  - `pricing.global_discount_percent` (default `0`)
  - env fallback: `PRICING_GLOBAL_DISCOUNT_PERCENT`
- Bulk plan pricing:
  - CLI: `app:plans:adjust-prices`
  - EasyAdmin page `/admin/plans/bulk-adjust-prices` (menu: `تغییر گروهی قیمت پلنها`) with preview (dry-run) and confirm+apply
- Renewal order metadata includes:
  - `priceSnapshot` (`baseAmount`, `discountPercent`, `discountAmount`, `finalAmount`, `planPriceSource`)
  - `renewalPolicy` (`carryRemainingTraffic`, `carryRemainingDays`)

## Phase 1.5 Add Traffic
- User can buy extra traffic directly from service detail via `➕ خرید حجم اضافه`.
- Flow:
  - `service_add_traffic_order:{serviceId}` opens add-traffic request.
  - User enters traffic amount between configured minimum/maximum.
  - Bot shows summary and creates `Order(type=add_traffic)` + `Payment(method=manual_card)` only after user confirms.
  - User submits receipt, admin approves/rejects as usual.
- On approval:
  - existing service is updated (no new service is created),
  - expiry time does **not** change,
  - traffic limit increases and panel `updateClient` is called (Sanaei/3x-ui supported),
  - duplicate confirm does not add traffic twice.
- Traffic add-on settings in `/admin/settings/renewal-pricing`:
  - `traffic_addon.enabled`
  - `traffic_addon.min_gb`
  - `traffic_addon.max_gb`
  - `traffic_addon.price_per_gb`
- If add-traffic price is zero (or disabled), user sees: `خرید حجم اضافه فعال نیست.`

Test command:
```bash
php bin/console app:service:test-add-traffic {serviceId} --traffic-gb=1
```

## Phase 1.6 Discount Codes and Campaigns
- Discount code support is available for:
  - خرید جدید (`new_service`)
  - تمدید (`renewal`)
  - خرید حجم اضافه (`add_traffic`)
- Discount calculation order:
  1. مبلغ پایه
  2. تخفیف سراسری
  3. کد تخفیف
  4. مبلغ نهایی (هرگز کمتر از صفر نمی‌شود)
- Price snapshot is stored in order metadata as:
  - `baseAmount`
  - `globalDiscountPercent`
  - `globalDiscountAmount`
  - `afterGlobalDiscountAmount`
  - `discountCode`
  - `discountCodeAmount`
  - `finalAmount`

### Discount restrictions
- Active/inactive state
- Start/end datetime window
- Max total uses
- Max uses per user
- First-purchase-only mode
- Applies-to scope (`all|new_service|renewal|add_traffic`)
- Plan restriction (optional)
- Minimum amount restriction (optional)

### Duplicate usage prevention
- Discount usage is recorded only after successful payment confirmation/provisioning.
- On repeated confirmation of the same payment, usage is not duplicated.
- `DiscountUsage` has unique protection for same `order + discount_code + user`.

### Commands
Create discount code:
```bash
php bin/console app:discount:create --code=TEST10 --type=percent --value=10 --max-uses=10 --applies-to=all --days-valid=7
```

Validate discount code:
```bash
php bin/console app:discount:validate TEST10 --user-id=1 --amount=1000000 --type=new_service
```

### Known issue
- Some 3x-ui versions may return empty responses for `addClient`/`updateClient`.
- The driver logs this safely and handles it as a warning path where applicable.

### Troubleshooting addClient failures
If `test login` works but provisioning still fails on `addClient`:
- verify `remoteInboundId` is correct and mapped to the intended inbound
- verify inbound is enabled on panel
- verify payload compatibility with protocol (`vless`/`vmess`)
- `trojan` client creation is not implemented yet in Phase 1.3
- check for empty response behavior from your 3x-ui build
- inspect safe diagnostics in logs: `var/log/dev.log`

## Reply Keyboard vs Inline Keyboard
- **Reply Keyboard (persistent):** used for primary navigation at the bottom of Telegram chat.
  - When no incomplete order:
    - Row 1: `🛒 خرید سرویس` | `📦 سرویسهای من`
    - Row 2: `🎧 پشتیبانی` (+ `🛠 مدیریت` for admins)
  - When user has an active incomplete order or draft:
    - Row 1: `▶️ ادامه سفارش قبلی` | `🗑 حذف سفارش ناتمام`
    - Row 2: `🔎 پیگیری سفارش` (if user has recent trackable orders)
    - Row 3: `🛒 خرید سرویس` | `📦 سرویسهای من`
    - Row 4: `🎧 پشتیبانی` (+ `🛠 مدیریت` for admins)
  - Pressing `▶️ ادامه سفارش قبلی` resumes the correct draft step or order payment page.
  - Pressing `🗑 حذف سفارش ناتمام` asks confirmation with inline buttons before cancelling.
  - Pressing `🔎 پیگیری سفارش` shows recent orders with their `trackingCode` and status.
  - Reply keyboard updates (incomplete buttons appear/disappear) at `/start`, `main_menu`, and after order cancel flows.
- **Inline Keyboard (contextual):** used for action-specific flows. Most multi-option rows use a **two-column layout** to reduce vertical space:
  - plan selection
  - payment method selection (2 columns where applicable)
  - discount choice (enter code / skip on same row)
  - service action buttons (link/QR and resend/sync on same rows)
  - renew/add traffic action buttons (2 columns)
  - back+cancel buttons share the same row where possible
  - incomplete order resume/cancel on same row in the inline prompt
  - admin menu actions

## Popup Alerts
- Bot uses Telegram popup alerts (`answerCallbackQuery` with `show_alert=true`) for inline callback warnings, including:
  - no active plans
  - no services
  - empty admin lists
  - invalid/inactive plan
  - already processed payment
- Note: popup alerts are callback-only; text/reply-keyboard actions return short normal messages.

## TELEGRAM_ADMIN_CHAT_ID
- Configure `TELEGRAM_ADMIN_CHAT_ID` to the Telegram numeric user/chat id of the admin.
- Only this id can execute `admin_*` callbacks.
- Non-admin users receive `Unauthorized`.

## Long polling local development
- Recommended local flow:
  ```bash
  php bin/console app:telegram:delete-webhook
  php bin/console app:telegram:poll
  ```
- One-shot poll:
  ```bash
  php bin/console app:telegram:poll --once
  ```
- Debug: polling logs incoming `callback_data=...` for callback troubleshooting.

## Commands
- `app:telegram:set-webhook {baseUrl}`
- `app:telegram:delete-webhook`
- `app:telegram:webhook-info`
- `app:telegram:mode-info`
- `app:telegram:poll [--limit=N] [--timeout=N] [--sleep=N] [--once] [--drop-pending] [--no-delete-webhook] [--force]` (defaults: limit=20, timeout=25, sleep=1)
- `app:create-default-settings`
- `app:create-sample-plans`
- `app:panel:test-login {panelId}`
- `app:panel:debug-transport {panelId}`
- `app:panel:list-inbounds {panelId}`
- `app:panel:detect-version {panelId} [--apply]`
- `app:panel:sync-inbounds {panelId}`
- `app:panel:test-create-client {inboundId}`
- `app:panel:test-client-links {serviceId}`
- `app:service:debug-links {serviceId}`
- `app:service:regenerate-config {serviceId}`
- `app:service:sync-usage [--service-id=ID] [--limit=100] [--dry-run]`
- `app:service:check-expiry [--service-id=ID] [--dry-run]`
- `app:service:send-notifications [--dry-run] [--type=expiry|traffic|expired|all] [--limit=100]`
- `app:automation:run [--dry-run] [--limit=100] [--only=orders|sync|expiry|notifications|suspend|all]`
- `app:orders:backfill-tracking-codes`
- `app:orders:expire-incomplete [--dry-run] [--hours=24] [--limit=100]`
- `app:service:test-renew {serviceId} [--days=30] [--traffic-gb=10]`
- `app:service:test-add-traffic {serviceId} [--traffic-gb=1]`
- `app:plans:adjust-prices [--percent=10|--amount=50000] [--direction=increase|decrease] [--field=price|pricePerGb|pricePerDay|all] [--dry-run]`

## Deployment Guide

### Proxy considerations
- `TELEGRAM_PROXY` applies only to **Bot → Telegram API** (outgoing: getUpdates, sendMessage, setWebhook, etc.).
- Webhook inbound requests (Telegram → Bot) cannot be proxied; they arrive at your server's public URL.
- The VPN panel proxy (if any) is configured separately and does not affect Telegram API calls.

### Global Panel Proxy from .env
Use this when a panel has no `config.proxy` enabled and you want one shared transport policy:

```env
PANEL_PROXY_ENABLED=true
PANEL_PROXY_TYPE=socks5
PANEL_PROXY_HOST=127.0.0.1
PANEL_PROXY_PORT=1080
PANEL_PROXY_USERNAME=
PANEL_PROXY_PASSWORD=
PANEL_PROXY_TIMEOUT=30
```

Resolution order:
1. If `VpnPanel.config.proxy.enabled=true`, use panel-specific proxy.
2. Else if `PANEL_PROXY_ENABLED=true`, use global env proxy.
3. Else use direct connection.

Timeout order:
1. `VpnPanel.config.timeout`
2. `PANEL_PROXY_TIMEOUT`
3. `15` seconds fallback

Important:
- `TELEGRAM_PROXY` is only for Bot → Telegram API.
- Panel proxy vars are only for Bot → VPN Panel API.
- `app:panel:debug-transport {panelId}` prints safe transport diagnostics (no passwords/tokens/cookies).

---

### Scenario A: Server outside Iran with domain and SSL
```env
TELEGRAM_MODE=webhook
TELEGRAM_PROXY=
```
Setup:
```bash
php bin/console app:telegram:set-webhook https://mydomain.com
```
Telegram delivers updates directly to your HTTPS endpoint. No proxy needed.

---

### Scenario B: Server inside Iran (outgoing Telegram blocked)
```env
TELEGRAM_MODE=long_polling
TELEGRAM_PROXY=socks5://host:1080
# or: TELEGRAM_PROXY=socks5://user:pass@host:1080
```
Setup:
```bash
php bin/console app:telegram:poll
```
Bot polls Telegram through the proxy. No public domain required.

---

### Scenario C: Bot and VPN panel both inside Iran
```env
TELEGRAM_MODE=long_polling
TELEGRAM_PROXY=socks5://host:1080    # for Telegram API only
# VPN panel proxy (if needed) is separate panel-level config
```
Run polling command as above. Panel proxy is not controlled by `TELEGRAM_PROXY`.

---

### Scenario D: Bot outside Iran, VPN panel inside Iran
```env
TELEGRAM_MODE=webhook                 # or long_polling depending on preference
TELEGRAM_PROXY=                       # usually not needed for Telegram
# VPN panel reverse proxy / tunnel handled separately
```
Webhook mode is possible since the server is outside Iran. Panel connectivity uses its own proxy.

---

### Check current mode:
```bash
php bin/console app:telegram:mode-info
```

### Check webhook status:
```bash
php bin/console app:telegram:webhook-info
```

### Running poll when mode is webhook:
```bash
php bin/console app:telegram:poll --force
```


## Not implemented in Phase 1
- SaaS
- Multi-tenancy
- Flow Builder
- Reseller/Agent
- Referral
- Wallet
- Additional real panel drivers beyond `sanaei_3xui`

---

## Phase 1.8 — NOWPayments Crypto Payment Gateway

This phase adds cryptocurrency payment support via [NOWPayments](https://nowpayments.io).

### 1. Create NOWPayments Gateway

In Admin → درگاههای پرداخت → کانفیگ NOWPayments, create a new gateway with:

| Field | Description |
|---|---|
| `title` | Display name |
| `api_key` | API key from NOWPayments dashboard |
| `api_base_url` | NOWPayments API base URL. Default: `https://api.nowpayments.io/v1` |
| `ipn_secret` | IPN Secret for webhook signature validation |
| `sandbox` | Informational flag for diagnostics; base URL is controlled by `api_base_url` |
| `callback_base_url` | Your site base URL, e.g. `https://your-domain.com` |
| `price_currency` | Currency of the price sent to NOWPayments (usually `usd`) |
| `pay_currency` | Default crypto currency for payment, e.g. `usdttrc20`, `btc`, `eth` |
| `irr_to_usd_rate` | IRR per 1 USD conversion rate (e.g. `600000`). Required when gateway currency is IRR and price_currency is `usd`. |
| `success_url` | Optional redirect URL after successful payment |
| `cancel_url` | Optional redirect URL after cancelled payment |
| `order_description` | Description sent to NOWPayments |

**Example config (stored internally in PaymentGateway.config):**
```json
{
  "api_key": "YOUR_API_KEY",
  "api_base_url": "https://api.nowpayments.io/v1",
  "ipn_secret": "YOUR_IPN_SECRET",
  "sandbox": false,
  "callback_base_url": "https://your-domain.com",
  "price_currency": "usd",
  "pay_currency": "usdttrc20",
  "irr_to_usd_rate": 600000,
  "order_description": "Amoobot VPN order",
  "success_url": "https://your-domain.com/payment/success",
  "cancel_url": "https://your-domain.com/payment/cancel"
}
```

NOWPayments payment endpoints in this phase use:
- `x-api-key: YOUR_API_KEY`
- `Content-Type: application/json` for POST requests
- `Accept: application/json`

`Authorization: Bearer ...` is **not** used for NOWPayments payment endpoints.

### 2. Webhook / IPN URL

Configure in your NOWPayments dashboard:

```
POST /payment/webhook/nowpayments
```

IPN Signature validation uses `x-nowpayments-sig` header with HMAC-SHA512 over sorted JSON body using your `ipn_secret`. If `ipn_secret` is configured and the signature is invalid, the webhook is rejected (fail-closed). The handler is **idempotent** — duplicate IPN calls do not double-provision.

### 3. Create StorePaymentMethod

In Admin → روشهای پرداخت فروشگاه, create a StorePaymentMethod linked to the NOWPayments gateway.

The method is shown in bot only if:
- `isActive = true`
- gateway type is `nowpayments`
- `api_key` is set
- `api_base_url` exists (or falls back to the production default)
- `pay_currency` is set
- `price_currency` is set
- if gateway currency is IRR and `price_currency` is `usd`, then `irr_to_usd_rate` must be set

### 4. Currency Conversion

Orders are stored in IRR. NOWPayments typically expects USD prices.

Conversion formula:
```
priceAmount (USD) = order.payableAmount (IRR) / irr_to_usd_rate
```

The conversion snapshot is stored in `Payment.requestPayload._conversion`:
```json
{
  "originalAmount": 6000000,
  "originalCurrency": "IRR",
  "priceAmount": 10.00,
  "priceCurrency": "usd",
  "rate": 600000
}
```

Live rate fetching is **not implemented** in this phase. Update `irr_to_usd_rate` manually.

### 5. Payment Status Mapping

| NOWPayments status | App behavior |
|---|---|
| `finished`, `confirmed` | ✅ Mark as PAID, trigger PaymentApprovalService |
| `waiting`, `confirming` | ⏳ Pending — do not provision |
| `partially_paid` | ⚠️ Underpaid — do not provision, show user a warning |
| `failed`, `expired`, `refunded` | ❌ Mark as REJECTED |

### 6. Telegram UX

When user selects NOWPayments, bot sends:
```
💎 پرداخت ارز دیجیتال

مبلغ پرداختی: {pay_amount} {PAY_CURRENCY}

آدرس کیف پول:
{pay_address}

وضعیت: در انتظار پرداخت
کد پیگیری سفارش: {tracking_code}
```

Buttons:
- 🔄 بررسی پرداخت → checks payment status
- ❌ انصراف → cancels the payment
- پرداخت در صفحه پرداخت (if `invoice_url` returned) → URL button

### 7. CLI Commands

#### Test gateway (no provisioning):
```bash
php bin/console app:payment:test-nowpayments {gatewayId} --amount=1000000
```

Prints: price amount/currency, pay amount/currency, address, payment_id, status, payment URL.

#### Test auth/config:
```bash
php bin/console app:payment:test-nowpayments-auth {gatewayId}
```

Calls `GET /currencies` with `x-api-key` and prints only safe diagnostics (`api_key_configured`, `api_key_length`, `api_key_prefix`, endpoint, HTTP status, sanitized response).

#### Check payment status:
```bash
php bin/console app:payment:check-nowpayments {paymentId}
```

Add `--approve` flag to trigger provisioning if payment is confirmed:
```bash
php bin/console app:payment:check-nowpayments {paymentId} --approve
```

### 8. Underpaid/Partially Paid

- `partially_paid` status does **not** trigger automatic provisioning.
- Bot shows: "مبلغ پرداختی کافی نیست. لطفاً وضعیت پرداخت را بررسی کنید."
- Admin can manually confirm via Admin → Payments → Confirm Payment.

### 9. Database Migration

Run after deployment:
```bash
php bin/console doctrine:migrations:migrate
```

New columns added to `payment` table:
`crypto_price_currency`, `crypto_pay_currency`, `crypto_pay_amount`, `crypto_actually_paid`, `crypto_outcome_amount`, `crypto_payment_status`, `crypto_payment_id`, `crypto_purchase_id`, `crypto_address`, `crypto_network`, `crypto_expires_at`, `ipn_payload`
