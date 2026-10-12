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
- `PaymentVerificationResult`: verification result, provider reference metadata and the verified `amount` + `currency` (required for automatic confirmation).
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

## Verification contract (strict)

A plugin only answers: was this payment really made, how much, in which currency. Amoobot's
`PaymentSettlementService` does everything else (locking, idempotency, amount check, provisioning,
notifications).

1. **Amounts are Toman.** `Payment::getPayableAmount()` is Toman. A Rial provider must be charged
   `App\Payment\Domain\Money\AmountNormalizer::toRial($amount)` (Toman × 10).
2. **Store the provider reference.** Return the provider's id from `createPayment()` as
   `transactionId` / `authority`. Callbacks and webhooks are matched to a payment **only** by these
   stored references, never by Amoobot's own payment or order id.
3. **`verifyPayment()` must report `amount` and `currency`** as the provider confirms them (decimal
   strings, e.g. `amount: "1500000", currency: "IRR"`), and must look the payment up with the stored
   reference, not a value from `$payload`.
4. **No amount or currency ⇒ NEEDS_REVIEW.** A paid result without both, a different currency, or a
   lower amount is never confirmed automatically; an admin must review it. More than expected is
   confirmed and flagged.
5. **Non-Toman quotes:** if you charge in another currency (USD, USDT, …) call
   `$payment->setExpectation($quotedAmount, $quotedCurrency)` in `createPayment()`; the reported
   amount is then compared with that quote.
6. **Webhooks fail closed.** `handleWebhook()` must authenticate the request and return
   `handled: false, message: "unauthorized"` if the signature is wrong or no secret is configured.
   The webhook body never decides that a payment is paid; `verifyPayment()` does.

Endpoints: webhooks `/payment/webhook/plugin/{code}` (the unified `/payment/webhook/{type}/{code}`
with type `plugin`), browser returns `/payment/callback/plugin/{code}`.

See also [Amount units](../payments/amount-units.md).

Plugins must never call `PaymentSettlementService`, provision services directly, or log secrets.
