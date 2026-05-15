# NovinoPay Payment Gateway Plugin

Installable NovinoPay rial payment gateway plugin for Amoobot.

This plugin uses NovinoPay's documented IPG flow:

- create payment session: `POST /payment/ipg/v2/request`
- receive browser callback with `PaymentStatus`, `Authority`, and `InvoiceID`
- verify payment: `POST /payment/ipg/v2/verification`

The plugin never provisions services and never calls `PaymentApprovalService`. It only returns payment request and verification DTOs to the existing payment flow.

## Required Config

- `api_key`: kept for the installer schema. If `merchant_id` is empty, this value is used as the NovinoPay `merchant_id`.
- `merchant_id`: NovinoPay merchant code. Use `test` for NovinoPay test mode if your account/docs allow it.
- `api_base_url`: defaults to `https://api.novinopay.com`.
- `callback_base_url`: public base URL of the Amoobot installation.
- `description`: optional payment description.

NovinoPay amounts are sent in IRR. This follows the project's stored payment amount convention.

## Build ZIP

From a Unix-like shell:

```bash
cd docs/plugins/novinopay-gateway
zip -r /tmp/novinopay-gateway.zip plugin.json README.md src
```

From PowerShell:

```powershell
Compress-Archive -Path docs\plugins\novinopay-gateway\plugin.json,docs\plugins\novinopay-gateway\README.md,docs\plugins\novinopay-gateway\src -DestinationPath var\novinopay-gateway.zip -Force
```

The ZIP root must contain `plugin.json`, `README.md`, and `src/NovinoPayGatewayPlugin.php`.

## Install And Enable

```bash
php bin/console app:plugin:install /tmp/novinopay-gateway.zip
php bin/console app:plugin:enable novinopay
php bin/console app:plugin:doctor novinopay
php bin/console app:payment:list-modules
```

Validate the ZIP before installing it:

```bash
php bin/console app:plugin:validate-package /tmp/novinopay-gateway.zip
```

After enabling, open Admin -> Payment Gateways -> Add Payment Gateway and install the `NovinoPay` module.

Create a `StorePaymentMethod` that points to the installed NovinoPay gateway to expose it in the bot. Existing bot payment selection behavior is unchanged.

## Callback URL

The plugin builds callback URLs under:

```text
{callback_base_url}/payment/callback/plugin/novinopay
```

with `payment_id`, `order_id`, and `tracking_code` query parameters when available.

NovinoPay callback status is never trusted by itself. A payment is considered paid only when NovinoPay verification returns a successful status.

## Test Driver Loading

After creating a `PaymentGateway` instance from the admin installer:

```bash
php bin/console app:payment:test-plugin-gateway {gatewayId}
```

This validates plugin class loading and interface compatibility without creating a real payment.

## Security Notes

- Only install trusted plugin ZIPs.
- Keep the NovinoPay merchant/API values private.
- Raw responses returned by this plugin redact sensitive keys.
- The plugin does not implement webhook/IPN support; it uses browser callback plus verification.
