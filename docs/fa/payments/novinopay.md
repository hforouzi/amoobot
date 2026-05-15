# پلاگین NovinoPay

NovinoPay به صورت پلاگین درگاه پرداخت نصب می‌شود.

## نصب

```bash
php bin/console app:plugin:validate-package /tmp/novinopay-gateway.zip
php bin/console app:plugin:install /tmp/novinopay-gateway.zip
php bin/console app:plugin:enable novinopay
php bin/console app:plugin:doctor novinopay
```

## تنظیمات required

- `api_key`
- `api_base_url`
- `callback_base_url`

کلیدهای اختیاری طبق schema پلاگین می‌توانند شامل `merchant_id`، `sandbox` و `description` باشند.

Callback طبق پیاده‌سازی فعلی زیر ساخته می‌شود:

```text
{callback_base_url}/payment/callback/plugin/novinopay
```

## راه‌اندازی

1. پلاگین را نصب و enable کنید.
2. PaymentGateway را بسازید یا repair کنید.
3. credentialهای required را وارد کنید.
4. StorePaymentMethod فعال بسازید.
5. driver را تست کنید.

```bash
php bin/console app:payment:repair-plugin-gateway novinopay
php bin/console app:payment:test-plugin-gateway GATEWAY_ID
```
