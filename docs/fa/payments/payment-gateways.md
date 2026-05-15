# درگاه‌های پرداخت

PaymentGateway رکوردی برای نگهداری تنظیمات و اطلاعات اتصال یک درگاه است.

## فیلدهای رایج

- `title`: عنوان داخلی یا نمایشی در پنل.
- `type`: نوع درگاه، مثل `manual_card`، `zibal`، `nowpayments` یا کد پلاگین.
- `pluginCode`: برای درگاه‌های پلاگینی مقدار دارد.
- `config`: تنظیمات JSON درگاه.
- `isActive`: فعال یا غیرفعال بودن درگاه.
- `currency`: ارز درگاه.

## درگاه Core و پلاگین

درگاه‌های Core داخل برنامه پیاده‌سازی شده‌اند. درگاه‌های پلاگینی از پلاگین‌های معتبر و فعال نوع `payment_gateway` می‌آیند.

## راه‌اندازی در ادمین

1. وارد بخش Payment Gateways شوید.
2. درگاه مورد نظر را نصب یا تنظیم کنید.
3. کلیدهای required را کامل کنید.
4. برای آن یک StorePaymentMethod بسازید.
5. هم PaymentGateway و هم StorePaymentMethod را فعال کنید.

## عیب‌یابی

```bash
php bin/console app:payment:list-modules
php bin/console app:payment:list-gateways
php bin/console app:payment:debug-gateway-config GATEWAY_ID
php bin/console app:payment:test-zibal GATEWAY_ID
php bin/console app:payment:test-nowpayments GATEWAY_ID
php bin/console app:payment:test-plugin-gateway GATEWAY_ID
```

هر دستور تست را فقط برای نوع درگاه مرتبط اجرا کنید.
