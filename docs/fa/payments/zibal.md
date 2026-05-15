# درگاه زیبال

Zibal یک درگاه Core برای پرداخت ریالی آنلاین است.

## تنظیمات

- `merchant`: کد پذیرنده، required.
- `sandbox`: حالت تست، اختیاری.
- `callback_base_url`: آدرس عمومی برنامه، required.

## جریان

درایور لینک پرداخت می‌سازد، Callback را دریافت می‌کند و قبل از تایید نهایی وضعیت پرداخت را verify می‌کند.

مسیر Callback:

```text
/payment/callback/zibal
```

## تست

```bash
php bin/console app:payment:test-zibal GATEWAY_ID
```
