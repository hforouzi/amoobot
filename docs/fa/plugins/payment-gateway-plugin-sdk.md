# SDK پلاگین درگاه پرداخت

پلاگین پرداخت باید interface اصلی پروژه را پیاده‌سازی کند:

```php
App\Payment\Plugin\PaymentGatewayPluginInterface
```

داخل پلاگین نباید interface جداگانه یا کپی‌شده تعریف شود.

## متدهای required

- `getType()`
- `createPayment(Payment $payment, Order $order, array $config): PaymentRequestResult`
- `verifyPayment(Payment $payment, array $payload, array $config): PaymentVerificationResult`
- `supportsWebhook(): bool`
- `handleWebhook(array $payload, Request $request, array $config): ?PaymentWebhookResult`

## DTOها

- `PaymentRequestResult`: نتیجه ساخت پرداخت و اطلاعات لینک یا provider.
- `PaymentVerificationResult`: نتیجه verify، اطلاعات مرجع provider و `amount` + `currency` تأییدشده (برای تأیید خودکار الزامی).
- `PaymentWebhookResult`: نتیجه Webhook وقتی پلاگین Webhook پشتیبانی کند.

## configSchema

هر فیلد می‌تواند `key` یا `name`، `type`، `required`، `default`، `label` و `choices` داشته باشد.

فرمت choices باید به شکل label به مقدار scalar باشد:

```json
{
  "Live": "live",
  "Sandbox": "sandbox"
}
```

برای secretهای required مقدار fake default نگذارید.

## Autoload

Runtime مقدار `mainClass` را از `plugin.json` می‌خواند و namespace prefix را با حذف نام کوتاه کلاس به دست می‌آورد.

```text
mainClass: Amoobot\Plugin\SwapWallet\SwapWalletGatewayPlugin
prefix:    Amoobot\Plugin\SwapWallet\
srcDir:    var/plugins/swapwallet/src/
```

## دستورها

```bash
php bin/console app:plugin:validate-package /path/to/plugin.zip
php bin/console app:plugin:doctor plugin_code
php bin/console app:payment:test-plugin-gateway GATEWAY_ID
```

## قرارداد verify (سخت‌گیرانه)

پلاگین فقط پاسخ می‌دهد: آیا این پرداخت واقعاً انجام شده، چه مبلغی و با چه ارزی. بقیهٔ کارها (قفل،
جلوگیری از تکرار، بررسی مبلغ، ساخت سرویس، اعلان‌ها) را `PaymentSettlementService` انجام می‌دهد.

1. **مبالغ به تومان هستند.** برای درگاه ریالی مبلغ را با
   `App\Payment\Domain\Money\AmountNormalizer::toRial($amount)` (تومان × ۱۰) ارسال کنید.
2. **مرجع درگاه را ذخیره کنید.** شناسهٔ درگاه را از `createPayment()` به‌صورت `transactionId` /
   `authority` برگردانید. callback و webhook **فقط** با همین مرجع ذخیره‌شده به پرداخت وصل می‌شوند،
   نه با شناسهٔ پرداخت یا سفارش آموبات.
3. **`verifyPayment()` باید `amount` و `currency` را** همان‌طور که درگاه تأیید می‌کند برگرداند
   (رشتهٔ عددی، مثل `amount: "1500000", currency: "IRR"`) و برای بررسی از مرجع ذخیره‌شده استفاده کند.
4. **بدون مبلغ یا ارز ⇒ NEEDS_REVIEW.** نتیجهٔ پرداخت‌شده بدون این دو، ارز متفاوت یا مبلغ کمتر هرگز
   خودکار تأیید نمی‌شود و ادمین باید بررسی کند. مبلغ بیشتر تأیید و علامت‌گذاری می‌شود.
5. **قیمت به ارز دیگر:** اگر به USD/USDT و … دریافت می‌کنید، در `createPayment()`
   `$payment->setExpectation($quotedAmount, $quotedCurrency)` را صدا بزنید.
6. **Webhook باید fail-closed باشد.** در `handleWebhook()` امضا را بررسی کنید و اگر نادرست بود یا
   secret تنظیم نشده بود `handled: false, message: "unauthorized"` برگردانید. بدنهٔ webhook هرگز
   پرداخت‌شدن را تعیین نمی‌کند؛ فقط `verifyPayment()`.

مسیرها: webhook در `/payment/webhook/plugin/{code}` و بازگشت مرورگر در `/payment/callback/plugin/{code}`.

همچنین ببینید: [واحد مبالغ](../payments/amount-units.md).

پلاگین نباید مستقیم `PaymentSettlementService` را صدا بزند، سرویس بسازد یا secretها را log کند.
