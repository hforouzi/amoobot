# Ubuntu 24.04 Deployment Script for Amoobot

این اسکریپت برای Ubuntu 24.04 طراحی شده و این موارد را انجام می‌دهد:

- نصب Nginx
- نصب PHP 8.3 + Extensions
- نصب Composer
- نصب MariaDB
- ساخت دیتابیس و یوزر
- Clone پروژه
- اجرای Composer
- تنظیم permissionها
- cache warmup
- migration
- ساخت nginx config
- SSL با Let's Encrypt
- تنظیم Supervisor
- webhook setup

---

# قبل از اجرا

این مقادیر را تغییر بده:

```bash
DOMAIN="bot.example.com"
REPO_URL="https://github.com/YOUR_USERNAME/amoobot.git"
APP_DIR="/var/www/amoobot"
DB_NAME="amoobot"
DB_USER="amoobot"
DB_PASS="CHANGE_THIS_STRONG_PASSWORD"
BOT_TOKEN="YOUR_BOT_TOKEN"
APP_SECRET="CHANGE_THIS_SECRET"
WEBHOOK_SECRET="CHANGE_THIS_WEBHOOK_SECRET"
```

---

# deploy.sh

```bash
#!/usr/bin/env bash

set -e

########################################
# CONFIG
########################################

DOMAIN="bot.example.com"
REPO_URL="https://github.com/YOUR_USERNAME/amoobot.git"
APP_DIR="/var/www/amoobot"
DB_NAME="amoobot"
DB_USER="amoobot"
DB_PASS="CHANGE_THIS_STRONG_PASSWORD"
BOT_TOKEN="YOUR_BOT_TOKEN"
APP_SECRET="CHANGE_THIS_SECRET"
WEBHOOK_SECRET="CHANGE_THIS_WEBHOOK_SECRET"
PHP_VERSION="8.3"

########################################
# ROOT CHECK
########################################

if [ "$EUID" -ne 0 ]; then
  echo "Please run as root"
  exit 1
fi

########################################
# UPDATE SYSTEM
########################################

echo "[1/15] Updating system..."
apt update
apt upgrade -y

########################################
# INSTALL PACKAGES
########################################

echo "[2/15] Installing packages..."
apt install -y \
  nginx \
  git \
  unzip \
  curl \
  supervisor \
  mariadb-server \
  software-properties-common

########################################
# INSTALL PHP 8.3
########################################

echo "[3/15] Installing PHP ${PHP_VERSION}..."
add-apt-repository ppa:ondrej/php -y
apt update

apt install -y \
  php${PHP_VERSION}-fpm \
  php${PHP_VERSION}-cli \
  php${PHP_VERSION}-mbstring \
  php${PHP_VERSION}-xml \
  php${PHP_VERSION}-curl \
  php${PHP_VERSION}-zip \
  php${PHP_VERSION}-intl \
  php${PHP_VERSION}-mysql \
  php${PHP_VERSION}-bcmath \
  php${PHP_VERSION}-gd \
  php${PHP_VERSION}-sqlite3

########################################
# INSTALL COMPOSER
########################################

echo "[4/15] Installing Composer..."
cd /tmp
curl -sS https://getcomposer.org/installer | php
mv composer.phar /usr/local/bin/composer
chmod +x /usr/local/bin/composer

########################################
# CREATE DATABASE
########################################

echo "[5/15] Creating database..."
mysql <<EOF
CREATE DATABASE IF NOT EXISTS ${DB_NAME} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
EOF

########################################
# CLONE PROJECT
########################################

echo "[6/15] Cloning project..."
mkdir -p ${APP_DIR}
chown -R www-data:www-data ${APP_DIR}

if [ ! -d "${APP_DIR}/.git" ]; then
  git clone ${REPO_URL} ${APP_DIR}
fi

cd ${APP_DIR}

########################################
# INSTALL DEPENDENCIES
########################################

echo "[7/15] Installing Composer dependencies..."
composer install --no-dev --optimize-autoloader

########################################
# ENV FILE
########################################

echo "[8/15] Creating .env.local..."

cat > ${APP_DIR}/.env.local <<EOF
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=${APP_SECRET}

DATABASE_URL="mysql://${DB_USER}:${DB_PASS}@127.0.0.1:3306/${DB_NAME}?serverVersion=mariadb-10.11.0&charset=utf8mb4"

APP_URL=https://${DOMAIN}

TELEGRAM_BOT_TOKEN=${BOT_TOKEN}
TELEGRAM_WEBHOOK_SECRET=${WEBHOOK_SECRET}
EOF

########################################
# CACHE & MIGRATIONS
########################################

echo "[9/15] Running migrations and cache..."

php bin/console doctrine:migrations:migrate --no-interaction || true

rm -rf var/cache/*

php bin/console cache:clear --env=prod
php bin/console cache:warmup --env=prod
php bin/console lint:container

########################################
# PERMISSIONS
########################################

echo "[10/15] Setting permissions..."

mkdir -p var/plugins
mkdir -p var/plugin_uploads

chown -R www-data:www-data var
chown -R www-data:www-data public
chown -R www-data:www-data var/plugins
chown -R www-data:www-data var/plugin_uploads

chmod -R 775 var

########################################
# NGINX CONFIG
########################################

echo "[11/15] Configuring Nginx..."

cat > /etc/nginx/sites-available/amoobot <<EOF
server {
    listen 80;
    server_name ${DOMAIN};

    root ${APP_DIR}/public;
    index index.php;

    client_max_body_size 50M;

    location / {
        try_files \$uri /index.php\$is_args\$args;
    }

    location ~ ^/index\\.php(/|\$) {
        fastcgi_pass unix:/run/php/php${PHP_VERSION}-fpm.sock;
        fastcgi_split_path_info ^(.+\\.php)(/.*)\$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT \$realpath_root;
        internal;
    }

    location ~ \\.php\$ {
        return 404;
    }

    access_log /var/log/nginx/amoobot_access.log;
    error_log /var/log/nginx/amoobot_error.log;
}
EOF

ln -sf /etc/nginx/sites-available/amoobot /etc/nginx/sites-enabled/amoobot
rm -f /etc/nginx/sites-enabled/default

nginx -t
systemctl restart nginx

########################################
# SSL
########################################

echo "[12/15] Installing SSL..."

apt install -y certbot python3-certbot-nginx

certbot --nginx -d ${DOMAIN} --non-interactive --agree-tos -m admin@${DOMAIN}

########################################
# SUPERVISOR
########################################

echo "[13/15] Configuring Supervisor..."

cat > /etc/supervisor/conf.d/amoobot-worker.conf <<EOF
[program:amoobot-worker]
command=php ${APP_DIR}/bin/console messenger:consume async --time-limit=3600 --env=prod
directory=${APP_DIR}
user=www-data
numprocs=1
autostart=true
autorestart=true
stderr_logfile=${APP_DIR}/var/log/worker.err.log
stdout_logfile=${APP_DIR}/var/log/worker.out.log
EOF

supervisorctl reread || true
supervisorctl update || true
supervisorctl restart amoobot-worker || true

########################################
# WEBHOOK
########################################

echo "[14/15] Setting Telegram webhook..."

php bin/console app:telegram:set-webhook || true

########################################
# FINAL CHECKS
########################################

echo "[15/15] Running final checks..."

php bin/console app:plugin:list || true
php bin/console app:payment:list-modules || true
php bin/console app:payment:list-gateways || true
php bin/console app:payment:list-methods || true

systemctl restart php${PHP_VERSION}-fpm
systemctl restart nginx

########################################
# DONE
########################################

echo ""
echo "========================================="
echo " Amoobot deployment completed"
echo "========================================="
echo "URL: https://${DOMAIN}"
echo "App Dir: ${APP_DIR}"
echo "Database: ${DB_NAME}"
echo "========================================="
```

---

# اجرا

```bash
chmod +x deploy.sh
sudo ./deploy.sh
```

---

# بعد از دیپلوی حتما تست کن

```bash
php bin/console lint:container
php bin/console app:plugin:list
php bin/console app:payment:list-modules
php bin/console app:payment:list-gateways
php bin/console app:payment:list-methods
```

---

# نکات مهم

## 1. اول DNS را ست کن

قبل از SSL:

```text
bot.example.com -> VPS IP
```

## 2. اگر Messenger نداری

این بخش را حذف کن:

```ini
messenger:consume async
```

## 3. اگر PostgreSQL می‌خواهی

DATABASE_URL را تغییر بده.

## 4. اگر پنل‌های ایران داری

برای VpnPanelها:

- proxy per panel
- tunnel/reverse tunnel
- whitelist

را بعداً تنظیم کن.

## 5. Production Deploy بعدی

برای آپدیت:

```bash
cd /var/www/amoobot

git pull
composer install --no-dev --optimize-autoloader
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console cache:clear --env=prod
php bin/console cache:warmup --env=prod
sudo systemctl restart php8.3-fpm
sudo systemctl restart nginx
```

