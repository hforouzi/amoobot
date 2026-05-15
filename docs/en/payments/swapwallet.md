# SwapWallet Plugin Gateway

SwapWallet is provided as a payment gateway plugin package for crypto payments.

## Install

```bash
php bin/console app:plugin:validate-package /tmp/swapwallet-gateway.zip
php bin/console app:plugin:install /tmp/swapwallet-gateway.zip
php bin/console app:plugin:enable swapwallet
php bin/console app:plugin:doctor swapwallet
```

## Config

Required keys:

- `api_key`
- `api_base_url`
- `callback_base_url`
- `payment_mode`
- `price_currency`
- `amount_unit`
- `toman_per_usd`

Optional keys include `api_secret`, `webhook_secret`, `pay_currency`, `network`, `rate_margin_percent`, `success_url`, `cancel_url`, and `description` when present in the schema.

## Payment Modes

- `invoice`: hosted invoice style flow.
- `direct`: direct crypto payment style flow.

Amount conversion uses the configured store amount unit and rate snapshot. Keep rate settings current.

Webhook signature validation is used when `webhook_secret` is configured. Pending, partial, underpaid, and expired states should be handled as payment status signals, not direct provisioning.

## Setup

```bash
php bin/console app:payment:repair-plugin-gateway swapwallet
php bin/console app:payment:test-plugin-gateway <gatewayId>
```

Create and activate a StorePaymentMethod after the gateway is configured.
