# NovinoPay Plugin Gateway

NovinoPay is provided as a payment gateway plugin package.

## Install

```bash
php bin/console app:plugin:validate-package /tmp/novinopay-gateway.zip
php bin/console app:plugin:install /tmp/novinopay-gateway.zip
php bin/console app:plugin:enable novinopay
php bin/console app:plugin:doctor novinopay
```

## Config

Required keys:

- `api_key`
- `api_base_url`
- `callback_base_url`

Optional keys include `merchant_id`, `sandbox`, and `description` if present in the plugin schema.

Callback URL is built under:

```text
{callback_base_url}/payment/callback/plugin/novinopay
```

## Setup

1. Install and enable the plugin.
2. Create or repair the PaymentGateway.
3. Fill required credentials.
4. Create and activate a StorePaymentMethod.
5. Test driver loading.

```bash
php bin/console app:payment:repair-plugin-gateway novinopay
php bin/console app:payment:test-plugin-gateway <gatewayId>
```
