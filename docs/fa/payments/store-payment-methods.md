# روش‌های پرداخت فروشگاه

StorePaymentMethod تعیین می‌کند کاربر در بات چه روش پرداختی را ببیند.

## فیلدها

- `gateway`: PaymentGateway متصل.
- `title`: عنوانی که در بات نمایش داده می‌شود.
- `isActive`: کنترل نمایش در بات.
- `sortOrder`: ترتیب نمایش.
- `minAmount` و `maxAmount`: محدودیت مبلغ.
- `currency`: باید با ارز سفارش هماهنگ باشد.

## چک‌لیست نمایش در بات

- StorePaymentMethod فعال است.
- PaymentGateway متصل فعال است.
- درگاه configured است.
- driver درگاه موجود و قابل load است.
- currency با سفارش یکی است.
- مبلغ سفارش داخل min/max است.
- سفارش هنوز در وضعیت `waiting_payment` است.

```bash
php bin/console app:payment:list-methods
php bin/console app:payment:debug-methods-for-order ORDER_ID
```
