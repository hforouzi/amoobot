# Demo Payment Gateway Plugin

Demo plugin for testing Amoobot payment gateway plugin installation and runtime loading.

This plugin never processes real money, never calls external HTTP services, and never calls `PaymentApprovalService`.

## Build ZIP

From this directory:

```bash
zip -r /tmp/demo-payment-gateway.zip plugin.json README.md src
```

On Windows PowerShell from the repository root:

```powershell
Compress-Archive -Path docs\plugins\demo-payment-gateway\plugin.json,docs\plugins\demo-payment-gateway\README.md,docs\plugins\demo-payment-gateway\src -DestinationPath var\demo-payment-gateway.zip -Force
```

The ZIP root must contain `plugin.json`, `README.md`, and `src/DemoPaymentGatewayPlugin.php`.

## Test

```bash
php bin/console app:plugin:install /tmp/demo-payment-gateway.zip
php bin/console app:plugin:enable demo_payment_gateway
php bin/console app:plugin:doctor demo_payment_gateway
php bin/console app:payment:list-modules
```

Validate the ZIP before installing it:

```bash
php bin/console app:plugin:validate-package /tmp/demo-payment-gateway.zip
```

Then install `demo_payment_gateway` from Admin -> Payment Gateways -> Add Payment Gateway.

If the plugin is already installed in a development database, the installer will correctly reject the ZIP with `Plugin already installed.` For a clean reinstall test, disable the plugin, delete its `plugin` table row, and remove `var/plugins/demo_payment_gateway`, or temporarily test with a different plugin code.
