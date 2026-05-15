# روش پرداخت در بات نمایش داده نمی‌شود

زنجیره تشخیص:

1. پلاگین فعال است؟
2. PaymentGateway ساخته شده؟
3. PaymentGateway فعال و configured است؟
4. StorePaymentMethod فعال است؟
5. سفارش در وضعیت `waiting_payment` است؟
6. `currency` و `min/max` درست است؟
7. driver موجود است؟

## دستورها

```bash
php bin/console app:plugin:list
php bin/console app:payment:list-modules
php bin/console app:payment:list-gateways
php bin/console app:payment:list-methods
php bin/console app:payment:debug-methods-for-order ORDER_ID
```

## معنی reasonها

- `order_not_waiting_payment`: سفارش دیگر قابل پرداخت نیست.
- `gateway_driver_missing`: driver قابل استفاده پیدا نشده است.
- `gateway_not_configured`: تنظیمات required درگاه ناقص است.
- `plugin_disabled`: پلاگین فعال نیست.
- `currency_mismatch`: ارز روش پرداخت با سفارش یکی نیست.
- `method_inactive`: StorePaymentMethod غیرفعال است.
- `gateway_inactive`: PaymentGateway متصل غیرفعال است.
