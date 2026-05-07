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

Webhook endpoint:
- `POST /telegram/webhook/{secret}`

## Telegram Bot Flow (Phase 1)
1. User sends `/start`.
2. Bot auto-registers TelegramAccount/User and shows main menu.
3. User clicks `🛒 خرید سرویس` and sees active plans.
4. User selects plan (`select_plan:{id}`).
5. Bot creates Order + Payment and sends manual card-to-card instructions.
6. User sends receipt photo or tracking text.
7. Payment becomes `submitted`, admin sees it in `/admin`.
8. Admin confirms payment via `Confirm Payment` action.
9. System provisions via `DummyVpnPanelDriver` and sends subscription/config to user.
10. User can view active services from `📦 سرویسهای من`.

## Confirm/Reject Payment
In `/admin` -> Payments, use actions:
- `Confirm Payment` -> marks payment confirmed, order paid/provisioned, creates VPN service, notifies user.
- `Reject Payment` -> marks payment rejected and notifies user.

## Commands
- `app:telegram:set-webhook {baseUrl}`
- `app:telegram:delete-webhook`
- `app:telegram:poll [--limit=N] [--timeout=N] [--sleep=N] [--once] [--drop-pending] [--no-delete-webhook]` (defaults: limit=20, timeout=25, sleep=1)
- `app:create-default-settings`
- `app:create-sample-plans`

## Not implemented in Phase 1
- SaaS
- Multi-tenancy
- Flow Builder
- Reseller/Agent
- Referral
- Wallet
- Online payment gateways
- Real Xray/Marzban/MikroTik drivers
