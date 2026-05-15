# عیب‌یابی پلاگین‌ها

## پلاگین نصب شده ولی در نصب درگاه دیده نمی‌شود

```bash
php bin/console app:plugin:doctor plugin_code
php bin/console app:payment:list-modules
```

پلاگین باید `enabled` باشد، type آن `payment_gateway` باشد، doctor را پاس کند و interface اصلی پرداخت را پیاده‌سازی کرده باشد.

## پلاگین فعال است ولی در بات دیده نمی‌شود

بررسی کنید:

- PaymentGateway ساخته شده و active است.
- config درگاه تمام required keyها را دارد.
- StorePaymentMethod ساخته شده و active است.
- currency و min/max با سفارش سازگار است.

## خطاهای رایج

- `class_not_found`: مقدار `mainClass`، namespace یا مسیر فایل داخل `src/` اشتباه است.
- `interface_not_implemented`: کلاس اصلی interface `App\Payment\Plugin\PaymentGatewayPluginInterface` را پیاده‌سازی نکرده است.
- `gateway_driver_missing`: runtime نتوانسته driver قابل استفاده پیدا کند.
- `gateway_not_configured`: تنظیمات required خالی است.
- invalid choices: فیلد choice در `configSchema` به label => scalar value تبدیل نمی‌شود.
- مشکل ZIP مک: package را validate کنید؛ metadata مک در حد امکان نادیده گرفته می‌شود.
- duplicate plugin code: پلاگین با همان code قبلاً نصب شده است.
