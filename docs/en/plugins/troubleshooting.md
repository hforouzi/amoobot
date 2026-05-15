# Plugin Troubleshooting

## Installed But Not In Gateway Installer

Run:

```bash
php bin/console app:plugin:doctor plugin_code
php bin/console app:payment:list-modules
```

The plugin must be `enabled`, type `payment_gateway`, pass doctor validation, and implement the core interface.

## Enabled But Not In Bot

Check:

- PaymentGateway exists and is active.
- Gateway config has all required keys.
- StorePaymentMethod exists and is active.
- Currency and amount limits match the order.

## Common Errors

- `class_not_found`: `mainClass`, namespace prefix, or `src/` file path is wrong.
- `interface_not_implemented`: class does not implement `App\Payment\Plugin\PaymentGatewayPluginInterface`.
- `gateway_driver_missing`: runtime could not resolve a usable gateway driver.
- `gateway_not_configured`: required config keys are missing or empty.
- invalid choices: `configSchema` choice field cannot normalize to label => scalar value.
- macOS ZIP issue: rebuild or validate package; metadata is ignored when possible.
- duplicate code: uninstall old package manually only through supported operational process, or use a new code.
