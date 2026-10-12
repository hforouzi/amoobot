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

### Database SQL mode

The database **must run in strict mode** (`STRICT_TRANS_TABLES`), in production too. Without it
MariaDB/MySQL silently truncates values that do not fit a column (payment references, amounts,
statuses) and stores invalid values as zero or empty, which can make a paid payment impossible to
match or settle.

- **dev and test** set it per connection in `config/packages/doctrine.yaml` (`when@dev`, `when@test`):
  `SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'`.
- **production** must set it on the server. In `my.cnf` / `my.ini` under `[mysqld]`:

  ```ini
  sql_mode = STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION
  ```

  then restart the database. (MySQL 8 and MariaDB ≥ 10.2.4 are strict by default; panels and some
  installers such as Laragon or older control panels turn it off.)
- Check it with a read-only query:

  ```bash
  php bin/console dbal:run-sql "SELECT @@GLOBAL.sql_mode, @@SESSION.sql_mode"
  ```

  Both values must contain `STRICT_TRANS_TABLES`.

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
