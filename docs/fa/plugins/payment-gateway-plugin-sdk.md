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
- `PaymentVerificationResult`: نتیجه verify و اطلاعات مرجع provider.
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

پلاگین نباید مستقیم `PaymentApprovalService` را صدا بزند، سرویس بسازد یا secretها را log کند.
