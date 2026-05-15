# Payment Gateway Plugin Template

This is the reference structure for Amoobot payment gateway plugins.

## Contract

- ZIP root contains `plugin.json`, `README.md`, and `src/`.
- `plugin.json` uses `manifestVersion: 1`.
- `type` is `payment_gateway`.
- `mainClass` is the real PHP class FQCN.
- The namespace prefix is derived from `mainClass` by removing the short class name.
- The class is loaded from `src/` and must implement `App\Payment\Plugin\PaymentGatewayPluginInterface`.
- Do not define another `PaymentGatewayPluginInterface` inside the plugin.
- `configSchema` keys must be unique.
- Choice fields must normalize to `label => scalar value`.
- Required secret fields should not define fake defaults.

## Build

```bash
cd docs/plugin-sdk/payment-gateway-template
zip -r /tmp/your-gateway.zip plugin.json README.md src
```

Use `plugin.json.example` and `src/GatewayPlugin.php.example` as starting points, then rename them before building.

## Validate

```bash
php bin/console app:plugin:validate-package /tmp/your-gateway.zip
php bin/console app:plugin:install /tmp/your-gateway.zip
php bin/console app:plugin:enable your_gateway_code
php bin/console app:plugin:doctor your_gateway_code
php bin/console app:payment:list-modules
```

## Runtime

The plugin class only creates and verifies payment DTOs. It must not provision services, change Telegram payment flow, or call `PaymentApprovalService`.
