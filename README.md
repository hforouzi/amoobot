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
5. Bot shows payment method selection (`select_payment_method:{planId}:manual_card`).
6. User selects `💳 کارت به کارت`; bot creates Order + Payment and sends payment instructions.
7. User taps `✅ تایید و ارسال رسید` (`payment_submit_receipt:{paymentId}`) and then sends receipt photo or tracking text in chat.
8. Payment becomes `submitted`, admin sees it in `/admin`.
9. Admin confirms payment via `Confirm Payment` action.
10. System provisions via `DummyVpnPanelDriver` and sends subscription/config to user.
11. User can view non-deleted services from `📦 سرویسهای من`.

## Confirm/Reject Payment
In `/admin` -> Payments, use actions:
- `Confirm Payment` -> marks payment confirmed, order paid/provisioned, creates VPN service, notifies user.
- `Reject Payment` -> marks payment rejected and notifies user.

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
  - `username` / `password`: panel login credentials
  - `config` JSON for global panel settings (no `inbound_id`):
    ```json
    {
      "subscription_base_url": "https://sub.boodbash.ir:8443",
      "subscription_path_prefix": "/rain",
      "public_host": "sub.boodbash.ir"
    }
    ```

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
php bin/console app:panel:sync-inbounds {panelId}
php bin/console app:panel:test-create-client {inboundId}
```

### CLI equivalents
```bash
php bin/console app:panel:test-login 1
php bin/console app:panel:debug-transport 1
php bin/console app:panel:sync-inbounds 1
php bin/console app:panel:test-create-client 3
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
```

Cron example:
```bash
*/10 * * * * php /path/bin/console app:service:sync-usage
*/15 * * * * php /path/bin/console app:service:check-expiry
*/20 * * * * php /path/bin/console app:service:send-notifications
```

## Phase 1.4.3 Renewal Flow
- User can renew an existing service directly from service detail (`🔄 تمدید سرویس`).
- Renewal creates `Order(type=renewal)` + `Payment(manual_card)` and reuses receipt/admin approval flow.
- On admin confirmation:
  - existing service is updated (not duplicated),
  - panel renew API is called,
  - local `expiresAt`/`trafficLimitGb` is updated.
- Traffic rule in this phase: renewal traffic is **added** to current traffic limit.
- Expiry rule in this phase:
  - if current expiry is in future: extend from current expiry,
  - otherwise: extend from now,
  - unlimited renewal keeps expiry unlimited.

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
  - `🛒 خرید سرویس`
  - `📦 سرویسهای من`
  - `🎧 پشتیبانی`
  - `🛠 مدیریت` (admin only)
- **Inline Keyboard (contextual):** used for action-specific flows:
  - plan selection
  - admin menu actions
  - pending payment views
  - payment confirm/reject
  - back navigation buttons

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
- `app:panel:sync-inbounds {panelId}`
- `app:panel:test-create-client {inboundId}`
- `app:service:debug-links {serviceId}`
- `app:service:regenerate-config {serviceId}`
- `app:service:sync-usage [--service-id=ID] [--limit=100] [--dry-run]`
- `app:service:check-expiry [--service-id=ID] [--dry-run]`
- `app:service:send-notifications [--dry-run] [--type=expiry|traffic|expired|all] [--limit=100]`
- `app:service:test-renew {serviceId} [--days=30] [--traffic-gb=10]`

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
- Online payment gateways
- Additional real panel drivers beyond `sanaei_3xui`
