# Demo Payment Gateway Plugin

The demo payment gateway plugin is for validating installation, autoloading, schema handling, and runtime adapter loading. It is not for real payments.

> The demo has no real provider: `?demo_paid=true` marks a payment as paid. It therefore reports no
> amount/currency unless `auto_confirm_for_testing` is enabled, so by default demo payments land in
> **NEEDS_REVIEW**. Never enable `auto_confirm_for_testing` (or the demo plugin at all) on a live shop.

## Build

```bash
cd docs/plugins/demo-payment-gateway
zip -r /tmp/demo-payment-gateway.zip plugin.json README.md src
```

## Install

```bash
php bin/console app:plugin:validate-package /tmp/demo-payment-gateway.zip
php bin/console app:plugin:install /tmp/demo-payment-gateway.zip
php bin/console app:plugin:enable demo_payment_gateway
php bin/console app:plugin:doctor demo_payment_gateway
```

## Use

Create a PaymentGateway from the installer or repair command if available for the plugin, then create an active StorePaymentMethod.

```bash
php bin/console app:payment:list-modules
php bin/console app:payment:test-plugin-gateway <gatewayId>
```
