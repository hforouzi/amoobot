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
- All current service operations are **local database operations only**.
- Real panel synchronization/drivers (x-ui/3x-ui/MikroTik/WG APIs) are intentionally postponed to next phase.

## User Service Actions
- `my_services`: shows latest non-deleted services as inline buttons.
- `service_view:{id}`: shows service detail page with status, expiry, traffic, subscription, and config preview.
- `service_subscription:{id}`: sends subscription link in a separate Telegram message.
- `service_resend_config:{id}`: re-sends config and service summary.
- `service_refresh:{id}`: reloads local DB values and re-renders detail page.
- Users can access only their own services.

## Admin Service Actions
- `admin_services`: lists latest non-deleted services with admin detail entry buttons.
- `admin_service_view:{id}`: shows full service details and management actions.
- `service_suspend:{id}` / `service_activate:{id}`
- `service_delete:{id}` now opens a confirmation step and deletion is finalized by `service_delete_confirm:{id}`.
- `service_reset_usage:{id}`
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
  - `config` JSON example:
    ```json
    {
      "inbound_id": 1,
      "protocol": "vless",
      "default_flow": "",
      "default_security": "reality",
      "default_network": "tcp",
      "subscription_base_url": "https://example.com",
      "remark_prefix": "amoobot"
    }
    ```

### Assign panel to plan
- In `/admin` -> Plans select a `panel` per plan.
- If plan panel is empty, provisioning falls back to Dummy driver.

### Test panel commands
```bash
php bin/console app:panel:test-login {panelId}
php bin/console app:panel:list-inbounds {panelId}
php bin/console app:panel:test-create-client {panelId} {inboundId}
```

### Known issue
- Some 3x-ui versions may return empty responses for `addClient`/`updateClient`.
- The driver logs this safely and handles it as a warning path where applicable.

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
- `app:telegram:poll [--limit=N] [--timeout=N] [--sleep=N] [--once] [--drop-pending] [--no-delete-webhook]` (defaults: limit=20, timeout=25, sleep=1)
- `app:create-default-settings`
- `app:create-sample-plans`
- `app:panel:test-login {panelId}`
- `app:panel:list-inbounds {panelId}`
- `app:panel:test-create-client {panelId} {inboundId}`

## Not implemented in Phase 1
- SaaS
- Multi-tenancy
- Flow Builder
- Reseller/Agent
- Referral
- Wallet
- Online payment gateways
- Additional real panel drivers beyond `sanaei_3xui`
