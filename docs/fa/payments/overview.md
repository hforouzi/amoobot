# نمای کلی پرداخت‌ها

پرداخت در Amoobot به چند مفهوم جدا تقسیم شده است تا هم تنظیمات درگاه، هم چیزی که کاربر در بات می‌بیند، و هم تراکنش واقعی قابل کنترل باشد.

## مفاهیم

- PaymentGateway: تنظیمات و اطلاعات اتصال درگاه، مثل کلید API، آدرس Callback و تنظیمات تبدیل مبلغ.
- StorePaymentMethod: روش پرداختی که در بات به کاربر نمایش داده می‌شود. این رکورد به یک PaymentGateway وصل است.
- Payment: تراکنش واقعی مربوط به یک سفارش.
- Order: سفارش خرید، تمدید یا افزایش حجم.
- PaymentApprovalService: مرحله نهایی ساخت، تمدید یا افزایش حجم سرویس بعد از تایید پرداخت.

درگاه‌ها و پلاگین‌ها نباید مستقیم سرویس بسازند یا `PaymentApprovalService` را صدا بزنند. وظیفه آن‌ها فقط ساخت درخواست پرداخت، بررسی پرداخت یا دریافت Webhook است.

## جریان پرداخت

```text
Order
  -> StorePaymentMethod
  -> PaymentGateway driver
  -> لینک پرداخت یا رسید دستی
  -> callback / verify / تایید ادمین
  -> PaymentApprovalService
  -> ساخت یا تمدید سرویس VPN
```

فعال بودن PaymentGateway کافی نیست. StorePaymentMethod هم باید فعال باشد، currency و min/max با سفارش سازگار باشد و driver درگاه بدون خطا load شود.
