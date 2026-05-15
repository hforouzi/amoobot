# نصب و راه‌اندازی

## پیش‌نیازها

- PHP نسخه `8.2` یا بالاتر، مطابق `composer.json`
- Composer
- پایگاه داده سازگار با Doctrine، معمولاً MySQL یا MariaDB
- Symfony CLI یا سرور داخلی PHP برای توسعه محلی
- توکن بات تلگرام از BotFather
- دامنه HTTPS عمومی برای حالت Webhook، یا Long Polling برای محیط محلی

## نصب وابستگی‌ها

```bash
composer install
cp .env .env.local
```

فایل `.env.local` را برای مقادیر واقعی پروژه و رمزها ویرایش کنید.

## تنظیمات محیط

مقادیر مهم:

- `DATABASE_URL`
- `TELEGRAM_BOT_TOKEN`
- `TELEGRAM_WEBHOOK_SECRET`
- `APP_DEFAULT_LOCALE`
- `ADMIN_PASSWORD`
- `TELEGRAM_MODE`
- `TELEGRAM_PROXY`
- `PANEL_PROXY_*`

آدرس عمومی برنامه برای Callback درگاه‌ها معمولاً داخل تنظیمات هر PaymentGateway، مثل `callback_base_url`، وارد می‌شود.

## پایگاه داده

```bash
php bin/console doctrine:database:create --if-not-exists
php bin/console doctrine:migrations:migrate
```

دستورهای کمکی موجود:

```bash
php bin/console app:create-default-settings
php bin/console app:create-sample-plans
php bin/console app:payment:create-default-gateways
```

اگر در آینده دستور جداگانه‌ای برای ساخت کاربر ادمین اضافه شود، از همان دستور استفاده کنید. در وضعیت فعلی، ورود ادمین طبق تنظیمات امنیتی پروژه انجام می‌شود.

## کش و اجرای محلی

```bash
php bin/console cache:clear
symfony serve
```

یا بدون Symfony CLI:

```bash
php -S 127.0.0.1:8000 -t public
```

سپس مسیر `/admin` را باز کنید.

## بررسی اولیه

```bash
php bin/console lint:container
php bin/console list app
```
