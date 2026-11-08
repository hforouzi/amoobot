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

**Account errors at create.** When the provider refuses an invoice because of the shop's own account, return a failed
`PaymentRequestResult` with `rawResponse['error']` set to `unauthorized` (invalid key) or `store_disabled` (the store is
disabled at the provider). `PaymentStarter` then alerts the admins in Telegram, at most once an hour per gateway
(`PaymentGatewayCredentialAlert`). The alert text is fixed, so nothing the provider sent, such as a quoted key, reaches it.

**A Telegram link for the Mini App.** A successful `rawResponse` is stored as the payment's request payload. When it has
`mini_app_link` set to a `https://t.me/...` link, the Mini App payment page opens that link with Telegram's
`openTelegramLink`, so the customer stays inside Telegram; the bot keeps `paymentUrl`. The page checks the link again
(`App\MiniApp\Checkout\PaymentTelegramLink`): host exactly `t.me`, no user info, port or fragment, a plain path, at most
512 characters. Anything else is ignored and the page opens `paymentUrl` with `openLink`, as for any other gateway.

## Optional: Constraints

A plugin may also implement `App\Payment\Plugin\PaymentGatewayConstraintsInterface`, when the provider has limits the
store should respect before the customer picks the method. Nothing else changes: a plugin without it is usable, accepts
any amount and has no invoice lifetime, and core gateways are not affected.

| Method | Meaning | Used by |
|---|---|---|
| `isUsable(array $config): bool` | May this gateway be offered with this config? Return `false` for a switch that is off or a value that is missing or invalid | Method lists and the customer guide (`getOfferableMethods()`) |
| `acceptsAmount(int $toman): bool` | Does the provider take this payable amount, in Toman? | Method lists for an order |
| `invoiceLifetimeSeconds(): ?int` | How long a provider invoice stays payable, or `null` for no limit | `PaymentStarter`: see [Invoices with a lifetime](#invoices-with-a-lifetime) |

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

### Invoices with a lifetime

A non-null `invoiceLifetimeSeconds()` makes one `Payment` row one provider invoice, for life. `PaymentStarter` decides under
the order lock, and `createPayment()` then runs outside any lock:

| The order's open payment | What the core does |
|---|---|
| Live invoice of this gateway, for this amount | Keeps the row and calls `createPayment()`. The plugin returns the stored link and calls no API |
| Live invoice of another gateway, an expired invoice, or a changed amount | Closes the row as rejected with source `gateway_invoice_superseded`, and creates a new one |
| Another start is creating the invoice | Refuses: the customer is asked to try again in a few seconds |
| Nothing, or a row with no provider reference | Uses a new or the same row, with a reservation |

- **Reservation.** Before `createPayment()` runs, `expiresAt` is set to 90 seconds from now. A row with an `expiresAt` and no
  `gatewayTransactionId` is a reservation, so `createPayment()` must treat only a row with both as a live invoice.
- **Expiry.** Set `expiresAt` in `createPayment()`. If the plugin leaves it, the core sets now plus the lifetime. An answer
  without a `transactionId`, or an exception, clears the reservation, and the row is reused by the next start.
- **Late money.** A closed invoice keeps its references, so a later paid verification reaches `settle()` and waits in admin
  review. The same goes for `PaymentSettlementService::expireUnpaid()`, which a plugin's reconciler calls when the provider
  says an invoice ran out unpaid. It changes only a pending payment and leaves the order open.
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

## Upgrading a plugin

An installed plugin is upgraded in place, from the CLI only (no web upload):
`app:plugin:upgrade <zip> [--dry-run] [--accept-new-permissions]`. `app:plugin:install` still refuses a code that is
already installed, and there is no uninstall.

On the server (deploy defaults: `/var/www/amoobot`, PHP 8.4; use your `PHP_VERSION` in the FPM unit name). Run the
console as `www-data`, like install, so the files belong to the same user:

```bash
cd /var/www/amoobot
# 1. Build the new ZIP (example: Tetraminator; every plugin in docs/plugins has a build.sh)
sudo -u www-data sh docs/plugins/tetraminator-gateway/build.sh /tmp/tetraminator.zip
# 2. Check everything and print the plan; nothing is changed
sudo -u www-data php bin/console app:plugin:upgrade /tmp/tetraminator.zip --dry-run --env=prod
# 3. Upgrade (add --accept-new-permissions only if the plan lists added permissions you accept)
sudo -u www-data php bin/console app:plugin:upgrade /tmp/tetraminator.zip --env=prod
# 4. Reload PHP-FPM: it may keep the old plugin classes in OPcache until then
sudo systemctl reload php8.4-fpm
# 5. Check the plugin
sudo -u www-data php bin/console app:plugin:doctor tetraminator --env=prod
```

What the command checks and does:

- The ZIP goes through install's checks (safe paths, allowed folders, manifest, `configSchema`).
- Its `code` must be installed, with the same `type`, and its `version` must be a strictly greater
  [semantic version](https://semver.org) (`1.1.0` after `1.0.0`; an equal or lower one is refused). `mainClass` may
  change if the new class loads.
- **Permissions:** added ones are refused unless you pass `--accept-new-permissions`. They are printed. Removed ones are
  fine.
- **Config:** each payment gateway of this plugin is checked against the new schema. Stored keys the schema no longer
  has are kept and listed. A field that becomes required with no default, and that a gateway lacks, refuses the
  upgrade: fill it in first. Only gateway ids and field names are printed, never a value.
- **Doctor first:** the new files are checked in a workspace (main class loads and implements the contract) before
  anything live is touched. `--dry-run` stops here and prints the plan: old and new version, added and removed
  permissions, added and removed config fields, and the gateways that use it.
- **Switch:** it runs under the lock that install also takes, so two upgrades, or an upgrade and an install, never
  overlap. `var/plugins/<code>` is moved to `var/plugins/.upgrade-backup/<code>-<old version>-<timestamp>`, the new
  files take its place, and the plugin row gets the new version, name, description, main class, manifest and
  permissions. Then the doctor runs again. If anything fails after the first move, the old files and the row are put
  back. After a success only the latest backup of that plugin is kept.
- **Unchanged:** the plugin's status (enabled stays enabled, disabled stays disabled), its install and enable dates,
  and every gateway row and its config.
- An admin audit entry `plugin.upgrade` records the code, the old and new version and the operating system user who
  ran it (and the `sudo` user). It holds no config.
- The command refuses symbolic links: in `var/plugins`, in the plugin's directory, and inside the old or new files.

**OPcache.** PHP-FPM may keep running the old plugin classes until it reloads. The command reminds you at the end.
`sudo amoobot-deploy --app-only` restarts PHP-FPM too, but an upgrade between deploys needs the reload above.

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
