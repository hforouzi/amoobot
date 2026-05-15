# Testing

## Smoke Checklist

```bash
php bin/console lint:container
php bin/console lint:yaml config translations
php bin/console lint:twig templates
php bin/console doctrine:migrations:status
```

## Plugin Checks

```bash
php bin/console app:plugin:validate-package /path/to/plugin.zip
php bin/console app:plugin:doctor plugin_code
php bin/console app:payment:test-plugin-gateway <gatewayId>
```

## Payment Checks

```bash
php bin/console app:payment:list-modules
php bin/console app:payment:list-gateways
php bin/console app:payment:list-methods
php bin/console app:payment:debug-methods-for-order <orderId>
```

## Panel Checks

```bash
php bin/console app:panel:test-login
php bin/console app:panel:list-inbounds
php bin/console app:panel:test-client-links
```

## Manual Flow

Run a bot purchase flow in long polling or webhook mode, select a plan, choose a payment method, complete payment/receipt flow, and verify provisioning output.
