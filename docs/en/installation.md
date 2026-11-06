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

- `BRAND_PUBLIC_BASE_URL`: optional customer-facing domain, see [Brand domain](#brand-domain-optional)

## Brand domain (optional)

A second, customer-facing domain can serve the Telegram Mini App and the payment return page, while everything else
stays on `DEFAULT_URI`. One installation serves both hosts. The main domain is never removed: it keeps every route,
including the Mini App and return pages, so links already sent keep working.

| Stays on `DEFAULT_URI` | Moves to `BRAND_PUBLIC_BASE_URL` |
|---|---|
| Telegram webhook, EasyAdmin, subscription pages `/s/`, downloads `/d/` | Mini App pages, API and files (`/app`, `/miniapp/`) |
| Payment gateway callbacks and webhooks, delivery messages | Payment return page (`/payment/return/`) and the logo it shows |
| The Mini App's admin section (`/app/admin`, `/app/api/admin`) | |

On the brand host everything else answers 404, whatever the method or user. The application enforces this
(`BrandHostGuard`) and the nginx example below repeats it.

`BRAND_PUBLIC_BASE_URL` is `https://` and a host with an optional port. It takes no path, not even a trailing slash, no
query or fragment, and its host must be public and differ from the `DEFAULT_URI` host. Empty means one domain, exactly as
before. An invalid value is ignored as a whole and logged once as a warning (again after a day or a cache clear); the
shop then behaves as if it were empty, so check the log after setting it.

With the value active, `trusted_hosts` holds exactly the two hosts, so a request for any other host gets 400. Monitors
and scripts must call the app by one of these names, not by IP address. The hosts share no cookies, and a customer who
changes host signs in again through Telegram, which happens by itself.

### What changes for admins

Every Mini App link in Telegram opens the brand host, which has no admin section. Telegram sign-in only works when
Telegram opens the page, and no bot button opens the main domain, so **the Mini App admin is not reachable with the brand
active**. Use EasyAdmin on the main domain. Direct links such as `https://<main domain>/app/admin` opened in a browser do
not sign in either.

### Set it up

Run the steps in this order: links are published last, when the new host already works.

1. **DNS**: point an `A` record (and `AAAA` only if the server is reachable over IPv6) for the brand domain to this
   server.

   ```bash
   dig +short stacktunnel.net
   ```

2. **Payment gateways**: nothing to change in their panels for callbacks. `callback_base_url` stays on the main domain;
   do not change it. Winapay's only return URL is its `CallbackURL`, so the Winapay panel keeps the main domain, and after
   processing the callback the app redirects (303) the customer to the brand return page with only the opaque return
   token. Zibal, NovinoPay and custom API callbacks work the same way. NOWPayments and SwapWallet receive the brand return
   URL with each new payment; their IPN and webhook URLs stay on the main domain. SwapWallet needs plugin version 1.0.1 or
   later, older versions keep returning to the main domain. Check your merchant account if a provider restricts return
   domains. Invoices created earlier keep the return URL they were created with.

3. **TLS**: get a separate certificate. Renewal runs on the same `certbot.timer`.

   ```bash
   sudo certbot certonly --nginx -d stacktunnel.net --deploy-hook "systemctl reload nginx"
   ```

4. **nginx**: install [`nginx-brand-domain.conf.example`](nginx-brand-domain.conf.example) as a site of its own. `deploy.sh`
   rewrites only its own site, so this one survives a deploy.

   ```bash
   sudo cp docs/en/nginx-brand-domain.conf.example /etc/nginx/sites-available/amoobot-brand
   sudo editor /etc/nginx/sites-available/amoobot-brand   # your domain, app directory and PHP version
   sudo ln -s /etc/nginx/sites-available/amoobot-brand /etc/nginx/sites-enabled/amoobot-brand
   sudo nginx -t && sudo systemctl reload nginx
   ```

5. **Environment**: add the value to the deploy env file, `/etc/amoobot/.env.prod.local`, then run your normal deploy.

   ```bash
   BRAND_PUBLIC_BASE_URL=https://stacktunnel.net
   ```

   Without a full deploy, add the same line to `.env.prod.local` in the app directory too, then:

   ```bash
   composer dump-env prod
   php bin/console cache:clear
   sudo systemctl reload php8.4-fpm
   ```

   Keep `DEFAULT_URI` as it is. Do not run `app:telegram:set-webhook` with the brand domain.

6. **Telegram**: a setting cannot change what Telegram has stored, so update both places.

   ```bash
   php bin/console app:telegram:set-menu-button --dry-run   # must show the brand URL
   php bin/console app:telegram:set-menu-button
   ```

   If you configured them in BotFather, send `https://stacktunnel.net/app` there too: `/mybots` → your bot → *Bot Settings*
   → *Configure Mini App*, and → *Menu Button*, and the URL of any app made with `/newapp`. The bot's webhook does not
   move. See [Mini App](miniapp.md).

### Verify

```bash
# Served on the brand host: 200
curl -s -o /dev/null -w '%{http_code}\n' https://stacktunnel.net/app
curl -s -o /dev/null -w '%{http_code}\n' https://stacktunnel.net/miniapp/app.js

# Not served there: 404 each
for path in /admin/login /telegram/webhook/x /s/x /d/1 /healthz /app/admin /app/api/admin/x /payment/callback/zibal; do
  curl -s -o /dev/null -w "%{http_code} $path\n" "https://stacktunnel.net$path"
done

# The main domain is unchanged: 200 each
curl -s -o /dev/null -w '%{http_code}\n' https://<main domain>/healthz
curl -s -o /dev/null -w '%{http_code}\n' https://<main domain>/admin/login

# Another host: 400
curl -s -o /dev/null -w '%{http_code}\n' -H 'Host: other.example.com' https://<main domain>/healthz
```

Then, in Telegram: open the menu button and sign in, open a service and its subscription page (it opens on the main
domain), and make a small real payment. After paying, the address bar should pass through the main domain and end on
`https://stacktunnel.net/payment/return/...`.

### Roll back

1. Remove `BRAND_PUBLIC_BASE_URL` from the env file(s), then deploy, or run `composer dump-env prod`, `cache:clear` and
   reload PHP-FPM as above. New links are built on the main domain at once, and `trusted_hosts` and the application's
   allowlist switch off.
2. Run `php bin/console app:telegram:set-menu-button` and restore the BotFather URLs.
3. Keep the nginx site and DNS for a while: payments and buttons created earlier still point to the brand domain, and the
   nginx allowlist keeps everything else off it. A Mini App page opened on it in this state shows subscription and
   download links on the brand host, which 404; removing the site once old links have expired ends that.

Nothing is stored in the database, so a rollback needs no data change, and gateways need none either.

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
