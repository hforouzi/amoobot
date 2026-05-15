# SwapWallet Payment Gateway Plugin

SwapWallet is an installable Amoobot payment gateway plugin for crypto payments through SwapWallet. It uses the existing Plugin Core and Payment Gateway Plugin Bridge and does not provision services directly.

Official docs: https://docs.swapwallet.app/

## Configuration

Required fields:

- `api_key`
- `api_base_url`
- `callback_base_url`
- `payment_mode`
- `price_currency`
- `amount_unit`
- `toman_per_usd`

Optional fields:

- `api_secret`
- `webhook_secret`
- `pay_currency`
- `network`
- `rate_margin_percent`
- `success_url`
- `cancel_url`
- `description`

SwapWallet API authentication is sent as:

```http
Authorization: Bearer apikey-xxx
```

Do not log or expose `api_key`, `api_secret`, or `webhook_secret`.

## Payment Modes

`invoice` is the default and recommended mode. It creates a hosted SwapWallet invoice and returns a payment URL from `paymentLinks`.

`direct` creates a temporary wallet style payment. It may return a wallet address, crypto amount, token, network, and deep links instead of a hosted payment URL. Use direct mode only if the bot/payment UI already supports showing direct crypto payment details.

## Amount Conversion

Amoobot order amounts are treated as IRR/Toman depending on `amount_unit`.

- `toman`: `priceAmount = payableAmount / finalRate`
- `rial`: `priceAmount = (payableAmount / 10) / finalRate`
- `finalRate = toman_per_usd * (1 + rate_margin_percent / 100)`

The conversion snapshot is returned in `rawResponse.conversion`. Live exchange rates are not fetched by this plugin.

If `toman_per_usd` is missing or zero, payment creation fails with:

```text
SwapWallet requires toman_per_usd for IRR orders.
```

## Webhook URL

Configure `callback_base_url` to your public Amoobot base URL. The plugin sends:

```text
{callback_base_url}/payment/webhook/plugin/swapwallet
```

The plugin validates HMAC-SHA256 webhook signatures when `webhook_secret` is configured. Unsigned webhooks must not be trusted for provisioning; the payment should still be verified through SwapWallet before final approval.

## Build ZIP

```bash
cd docs/plugins/swapwallet-gateway
zip -r /tmp/swapwallet-gateway.zip .
cd -
```

## Install And Enable

```bash
php bin/console app:plugin:install /tmp/swapwallet-gateway.zip
php bin/console app:plugin:enable swapwallet
php bin/console app:plugin:doctor swapwallet
php bin/console app:plugin:list
php bin/console app:payment:list-modules
```

Validate the ZIP before installing it:

```bash
php bin/console app:plugin:validate-package /tmp/swapwallet-gateway.zip
```

## Admin Setup

1. Open Admin -> Payment Gateways -> Install Gateway.
2. Select SwapWallet.
3. Configure the required fields.
4. Create a `StorePaymentMethod` with an active SwapWallet gateway and `IRR` currency.

Suggested method title:

```text
پرداخت ارز دیجیتال سواپ‌ولت
```

## Test

```bash
php bin/console app:payment:test-plugin-gateway {gatewayId} -vvv
```

If the command supports amount in your checkout:

```bash
php bin/console app:payment:test-plugin-gateway {gatewayId} --amount=100000 -vvv
```

The test command loads the plugin driver and checks configuration. It must not provision service.

## Status Mapping

Final paid statuses:

- `paid`
- `completed`
- `complete`
- `confirmed`
- `success`
- `succeed`
- `succeeded`
- `finished`

Pending statuses:

- `active`
- `pending`
- `waiting`
- `confirming`
- `processing`
- `created`

Failed or expired statuses:

- `failed`
- `expired`
- `cancelled`
- `canceled`
- `rejected`

Partial or underpaid statuses:

- `partial`
- `partially_paid`
- `underpaid`
- `wrong_amount`

Partial, underpaid, pending, and confirming statuses never return `paid=true`.

## Security

- Only install trusted plugins.
- Never trust unsigned webhooks.
- Do not provision until SwapWallet verification returns a final paid status.
- The plugin only returns payment DTOs.
- `PaymentApprovalService` remains the provisioning trigger.
- Existing core gateways and other payment plugins are not changed by this package.
