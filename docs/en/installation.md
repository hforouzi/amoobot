# Installation

## Requirements

- PHP `>=8.2` as required by `composer.json`
- Composer
- A database supported by Doctrine, normally MySQL/MariaDB for this project
- Symfony CLI or PHP built-in server for local development
- Telegram bot token from BotFather
- A public HTTPS URL for webhook mode, or long polling for local/private servers

## Install

```bash
composer install
cp .env .env.local
```

Edit `.env.local` for local secrets and deployment-specific values.

## Environment

Common values:

- `DATABASE_URL`
- `TELEGRAM_BOT_TOKEN`
- `TELEGRAM_WEBHOOK_SECRET`
- `APP_DEFAULT_LOCALE`
- `ADMIN_PASSWORD`
- `TELEGRAM_MODE`
- `TELEGRAM_PROXY`
- `PANEL_PROXY_*`

Set the public application URL wherever your deployment uses it for callbacks and panel links. Payment gateway `callback_base_url` fields are configured per gateway.

## Database

```bash
php bin/console doctrine:database:create --if-not-exists
php bin/console doctrine:migrations:migrate
```

Default data helpers available in this project:

```bash
php bin/console app:create-default-settings
php bin/console app:create-sample-plans
php bin/console app:payment:create-default-gateways
```

Admin user setup is handled by the project security setup. If a dedicated admin creation command is added later, use it here.

## Cache And Server

```bash
php bin/console cache:clear
symfony serve
```

Without Symfony CLI:

```bash
php -S 127.0.0.1:8000 -t public
```

Open `/admin` and log in with the configured admin credentials.

## Basic Verification

```bash
php bin/console lint:container
php bin/console list app
```
