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

## Logs and customer credentials (production)

Versions before `fix/log-redaction` wrote subscription URLs, config links (`vless://…`), client UUIDs and subIds to the logs
and to the `bot_message_log` table (messages the bot sent). Any one of them is enough to use a customer's service. The app now
logs only service/order/panel ids and the last 4 characters, but old files and rows stay until you remove them. Once after
deploying, as root on the server (layout of `deploy.sh`: app in `/var/www/amoobot`, app user `www-data`, PHP 8.4):

```bash
# 1) Permissions as deploy.sh sets them: only the app user reads the job logs
chown www-data:www-data /var/log/amoobot && chmod 750 /var/log/amoobot
find /var/log/amoobot -type f -exec chown www-data:www-data {} + -exec chmod 640 {} +
chmod 640 /var/log/nginx/amoobot_*.log*      # www-data:adm

# 2) Old files: delete rotated copies, empty the current ones
find /var/log/amoobot -type f -name '*.log.*' -delete
truncate -s 0 /var/log/amoobot/*.log
find /var/log/nginx -name 'amoobot_*.log.*' -delete
truncate -s 0 /var/log/nginx/amoobot_access.log /var/log/nginx/amoobot_error.log
find /var/log -maxdepth 1 -name 'php8.4-fpm.log.*' -delete && truncate -s 0 /var/log/php8.4-fpm.log
truncate -s 0 /var/www/amoobot/var/log/*.log 2>/dev/null || true   # worker.*.log, if the worker is installed

# 3) Old bot messages (use the date you deployed this version)
mariadb amoobot -e "DELETE FROM bot_message_log WHERE created_at < '2026-10-30 00:00:00'"

# 4) Check: both must find nothing / print 0
grep -rlE 'vless://|vmess://|trojan://|/sub/' /var/log/amoobot /var/log/nginx/amoobot_error.log /var/log/php8.4-fpm.log
journalctl -u 'amoobot-*' --since '-30d' | grep -cE 'vless://|vmess://|trojan://'
```

- If `journalctl` prints more than 0: `journalctl --rotate && journalctl --vacuum-time=1s` (this removes **all** of the server's
  journald logs, not only amoobot's).
- `amoobot_access.log` still records customer page URLs (`/s/<token>`); keep it at mode `640` and do not copy it anywhere.
- Database backups taken before that date contain the old `bot_message_log` rows too; keep or delete them accordingly.
- If a log left the server (chat, ticket), use "New subscription page link" on those customers' services and, if needed,
  regenerate their subscription link / UUID on the 3x-ui panel.
