# Payment Gateway Plugin SDK

Payment gateway plugins implement the core interface:

```php
App\Payment\Plugin\PaymentGatewayPluginInterface
```

Do not define a duplicate interface inside the plugin.

## Required Methods

- `getType()`
- `createPayment(Payment $payment, Order $order, array $config): PaymentRequestResult`
- `verifyPayment(Payment $payment, array $payload, array $config): PaymentVerificationResult`
- `supportsWebhook(): bool`
- `handleWebhook(array $payload, Request $request, array $config): ?PaymentWebhookResult`

## DTOs

- `PaymentRequestResult`: create-payment result and redirect/payment metadata.
- `PaymentVerificationResult`: verification result and provider reference metadata.
- `PaymentWebhookResult`: webhook result when a plugin supports webhooks.

## Config Schema

Fields use `key` or `name`, `type`, `required`, optional `default`, `label`, and optional `choices`.

Choice fields must normalize to:

```json
{
  "Human Label": "scalar_value"
}
```

Required secret fields should not use fake defaults.

## Autoload Rule

The runtime reads `plugin.json mainClass` and derives the namespace prefix by removing the short class name.

Example:

```text
mainClass: Amoobot\Plugin\SwapWallet\SwapWalletGatewayPlugin
prefix:    Amoobot\Plugin\SwapWallet\
srcDir:    var/plugins/swapwallet/src/
```

## Commands

```bash
php bin/console app:plugin:validate-package /path/to/plugin.zip
php bin/console app:plugin:doctor plugin_code
php bin/console app:payment:test-plugin-gateway <gatewayId>
```

Plugins must never call `PaymentApprovalService`, provision services directly, or log secrets.
