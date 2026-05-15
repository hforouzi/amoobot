# عیب‌یابی پرداخت رمزارزی

## مشکل‌های رایج

- API key نامعتبر: credential را بررسی کنید.
- NOWPayments از هدر `x-api-key` استفاده می‌کند.
- minimum amount: مبلغ تبدیل‌شده ممکن است کمتر از حداقل provider باشد.
- invoice و direct payment رفتار متفاوت دارند؛ direct ممکن است `pay_currency` بخواهد.
- underpaid یا partial: وضعیت پرداخت را طبق verify مدیریت کنید و مستقیم سرویس نسازید.
- expired: معمولاً باید پرداخت جدید ساخته شود.
- Webhook signature: اگر secret تنظیم شده، امضا باید معتبر باشد.
- نرخ تبدیل: مقدارهایی مثل `toman_per_usd` باید به‌روز باشند.

## دستورها

```bash
php bin/console app:payment:test-nowpayments-auth GATEWAY_ID
php bin/console app:payment:debug-nowpayments-amount GATEWAY_ID
php bin/console app:payment:test-plugin-gateway GATEWAY_ID
```
