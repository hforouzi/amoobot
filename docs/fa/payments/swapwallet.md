# پلاگین SwapWallet

SwapWallet به صورت پلاگین درگاه پرداخت رمزارزی نصب می‌شود.

## نصب

```bash
php bin/console app:plugin:validate-package /tmp/swapwallet-gateway.zip
php bin/console app:plugin:install /tmp/swapwallet-gateway.zip
php bin/console app:plugin:enable swapwallet
php bin/console app:plugin:doctor swapwallet
```

## تنظیمات required

- `api_key`
- `api_base_url`
- `callback_base_url`
- `payment_mode`
- `price_currency`
- `amount_unit`
- `toman_per_usd`

کلیدهای اختیاری طبق schema پلاگین می‌توانند شامل `api_secret`، `webhook_secret`، `pay_currency`، `network`، `rate_margin_percent`، `success_url`، `cancel_url` و `description` باشند.

## حالت پرداخت

- `invoice`: جریان صفحه پرداخت.
- `direct`: جریان پرداخت مستقیم رمزارزی.

تبدیل مبلغ بر اساس `amount_unit` و نرخ تنظیم‌شده انجام می‌شود. اگر `webhook_secret` تنظیم شود، امضای Webhook بررسی می‌شود.

وضعیت‌های pending، partial، underpaid و expired فقط سیگنال وضعیت پرداخت هستند و نباید باعث ساخت مستقیم سرویس در پلاگین شوند.

## راه‌اندازی

```bash
php bin/console app:payment:repair-plugin-gateway swapwallet
php bin/console app:payment:test-plugin-gateway GATEWAY_ID
```

بعد از تنظیم درگاه، یک StorePaymentMethod فعال برای آن بسازید.
