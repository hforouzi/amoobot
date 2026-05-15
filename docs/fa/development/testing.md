# تست و بررسی

## Smoke Test

```bash
php bin/console lint:container
php bin/console lint:yaml config translations
php bin/console lint:twig templates
php bin/console doctrine:migrations:status
```

اگر lint YAML به tagهای Symfony مثل `!tagged_iterator` گیر داد، دستور را با `--parse-tags` اجرا کنید.

## پلاگین

```bash
php bin/console app:plugin:validate-package /path/to/plugin.zip
php bin/console app:plugin:doctor plugin_code
php bin/console app:payment:test-plugin-gateway GATEWAY_ID
```

## پرداخت

```bash
php bin/console app:payment:list-modules
php bin/console app:payment:list-gateways
php bin/console app:payment:list-methods
php bin/console app:payment:debug-methods-for-order ORDER_ID
```

## پنل VPN

```bash
php bin/console app:panel:test-login
php bin/console app:panel:list-inbounds
php bin/console app:panel:test-client-links
```

## تست دستی بات

یک خرید کامل را با Long Polling یا Webhook انجام دهید: انتخاب پلن، انتخاب روش پرداخت، تکمیل پرداخت یا ارسال رسید، و بررسی خروجی provisioning.
