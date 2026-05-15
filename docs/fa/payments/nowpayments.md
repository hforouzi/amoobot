# درگاه NOWPayments

NOWPayments یک درگاه Core برای پرداخت رمزارزی است.

## تنظیمات

- `api_key`: required.
- `ipn_secret`: برای امضای IPN/Webhook، اختیاری.
- `payment_mode`: مقدار `invoice` یا `payment`.
- `price_currency`: ارز قیمت‌گذاری، معمولاً `usd`.
- `pay_currency`: ارز پرداخت، مخصوصاً برای direct payment.
- `amount_unit`: واحد مبلغ فروشگاه، مثل `toman` یا `rial`.
- `toman_per_usd`: نرخ تبدیل تومان به دلار، برای مبالغ ریالی required.
- `callback_base_url`: آدرس عمومی برنامه، required.

## نکته‌ها

- احراز هویت NOWPayments با هدر `x-api-key` انجام می‌شود.
- حالت invoice کاربر را به صفحه پرداخت می‌فرستد.
- حالت direct ممکن است به `pay_currency` مشخص نیاز داشته باشد.
- خطای minimum amount معمولاً یعنی مبلغ تبدیل‌شده کمتر از حداقل provider است.
- وضعیت‌های underpaid، partial، expired و pending باید طبق verify و flow فعلی مدیریت شوند؛ درگاه نباید مستقیم سرویس بسازد.

## عیب‌یابی

```bash
php bin/console app:payment:test-nowpayments GATEWAY_ID
php bin/console app:payment:test-nowpayments-auth GATEWAY_ID
php bin/console app:payment:debug-nowpayments-amount GATEWAY_ID
php bin/console app:payment:check-nowpayments PAYMENT_ID
```
