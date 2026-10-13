# پلاگین Demo Payment Gateway

پلاگین demo برای تست نصب، autoload، schema و load شدن adapter پرداخت است. این پلاگین برای پرداخت واقعی نیست.

> این پلاگین درگاه واقعی ندارد و `?demo_paid=true` پرداخت را پرداخت‌شده نشان می‌دهد؛ بنابراین مبلغ و ارز
> گزارش نمی‌کند مگر `auto_confirm_for_testing` فعال باشد، و به‌طور پیش‌فرض پرداخت‌ها در وضعیت
> **NEEDS_REVIEW** می‌مانند. این گزینه (و خود پلاگین دمو) را هرگز روی فروشگاه واقعی فعال نکنید.
>
> **محافظ production (هسته).** با `APP_ENV=prod` و `APP_DEBUG=0`، آموبات `auto_confirm_for_testing` را از
> تنظیمات همهٔ پلاگین‌ها حذف می‌کند و `_amoobot_testing_shortcuts_allowed: false` می‌فرستد. در نتیجه دمو هرگز
> پرداختی را پرداخت‌شده گزارش نمی‌کند و callback عمومی آن نمی‌تواند پرداخت NEEDS_REVIEW یا هشدار ادمین بسازد.
> میان‌برهای تست فقط در محیط `dev`/`test` یا با debug فعال کار می‌کنند. پلاگین غیرفعال یا درگاه غیرفعال در
> مسیرهای callback/webhook نادیده گرفته می‌شود.

## ساخت ZIP

```bash
cd docs/plugins/demo-payment-gateway
zip -r /tmp/demo-payment-gateway.zip plugin.json README.md src
```

## نصب

```bash
php bin/console app:plugin:validate-package /tmp/demo-payment-gateway.zip
php bin/console app:plugin:install /tmp/demo-payment-gateway.zip
php bin/console app:plugin:enable demo_payment_gateway
php bin/console app:plugin:doctor demo_payment_gateway
```

## استفاده

بعد از نصب، PaymentGateway بسازید یا اگر در پروژه فعال است از repair command استفاده کنید، سپس StorePaymentMethod فعال بسازید.

```bash
php bin/console app:payment:list-modules
php bin/console app:payment:test-plugin-gateway GATEWAY_ID
```
