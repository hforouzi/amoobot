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

## Optional: Constraints

A plugin may also implement `App\Payment\Plugin\PaymentGatewayConstraintsInterface`, when the provider has limits the
store should respect before the customer picks the method. Nothing else changes: a plugin without it is usable, accepts
any amount and has no invoice lifetime, and core gateways are not affected.

| Method | Meaning | Used by |
|---|---|---|
| `isUsable(array $config): bool` | May this gateway be offered with this config? Return `false` for a switch that is off or a value that is missing or invalid | Method lists and the customer guide (`getOfferableMethods()`) |
| `acceptsAmount(int $toman): bool` | Does the provider take this payable amount, in Toman? | Method lists for an order |
| `invoiceLifetimeSeconds(): ?int` | How long a provider invoice stays payable, or `null` for no limit | Declared only: nothing reads it yet |

`StorePaymentMethodResolver` is the one place that lists methods for the bot and the Mini App, so a plugin needs no UI code.

- A method that fails `isUsable()` is skipped with the reason `gateway_not_usable`.
- An amount that fails `acceptsAmount()` is skipped with the reason `amount_out_of_range`, the same reason as the store
  method's own range. `StorePaymentMethod` `minAmount` and `maxAmount` still apply on top, so admins can only narrow the
  range. See the reasons with `php bin/console app:payment:debug-methods-for-order`.
- **Fail closed.** If either method throws, or the plugin cannot be loaded, the method is skipped. The log has the gateway
  id, the plugin code and the exception's class, never its message, because a message may quote the config. A throwing
  check is reported as `gateway_not_usable`.
- Both methods run on every listing, so they must not call the network. `isUsable()` receives the same config as
  `createPayment()`.
- Hiding a method is not a guarantee: `createPayment()` must still refuse an amount or config it cannot accept, because a
  customer can act on an old list.

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
   **Underpayment tolerance:** a shortfall of at most `underpayment_tolerance_percent` of the expected
   amount (gateway config; default 0, or 1 when your `plugin.json` declares `"category": "crypto"`)
   is confirmed and flagged `underpaid_tolerated`; a larger shortfall goes to NEEDS_REVIEW with the
   shortfall in the review reason. Add the key to your `configSchema` (type `number`) so admins can
   set it. The core applies the tolerance; plugins must still report the exact amount paid.
5. **Non-Toman quotes:** if you charge in another currency (USD, USDT, …) call
   `$payment->setExpectation($quotedAmount, $quotedCurrency)` in `createPayment()`; the reported
   amount is then compared with that quote.
6. **Webhooks fail closed.** `handleWebhook()` must authenticate the request and return
   `handled: false, message: "unauthorized"` if the signature is wrong or no secret is configured.
   The webhook body never decides that a payment is paid; `verifyPayment()` does.
7. **Return URLs carry the return token.** Each payment has an unguessable `returnToken`. Send the
   customer back with `App\Payment\Domain\PaymentReturnUrl`:
   - provider return / success URL: `PaymentReturnUrl::returnUrl($callbackBaseUrl, $payment)`
     (`/payment/return/{token}`), cancel URL: `PaymentReturnUrl::cancelUrl(...)`;
   - a browser callback that Amoobot processes first: `PaymentReturnUrl::withToken($callbackUrl, $payment)`
     adds `?rt={token}`; after processing, the customer is redirected to the return page.

   The return page identifies the payment **only** by this token and shows a self-refreshing
   "waiting for confirmation" state while the payment is pending. The token is never a lookup key
   for callbacks/webhooks (rule 2 still applies). Never put payment or order ids in a return URL as
   the way to find the payment.

8. **Testing shortcuts only outside production.** Every config array Amoobot passes contains
   `_amoobot_return_base_url` (the shop's brand domain, or null: a direct return URL for the provider goes on this base
   when it is set, while callback and webhook URLs always stay on `callback_base_url`) and
   `_amoobot_testing_shortcuts_allowed` (true only in `dev`/`test` or with debug on); in production the
   core also removes `auto_confirm_for_testing`. Anything that trusts the request instead of the
   provider (like the demo plugin's `demo_paid`) must be gated on that flag.

Endpoints: webhooks `/payment/webhook/plugin/{code}` (the unified `/payment/webhook/{type}/{code}`
with type `plugin`), browser returns `/payment/callback/plugin/{code}`.

See also [Amount units](../payments/amount-units.md).

Plugins must never call `PaymentSettlementService`, provision services directly, or log secrets.
