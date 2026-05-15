# Payment Gateways

PaymentGateway records store gateway type, credentials, and runtime config.

## Fields

- `title`: admin and operational label.
- `type`: gateway module type, such as `manual_card`, `zibal`, `nowpayments`, or a plugin code.
- `pluginCode`: set for plugin gateways.
- `config`: JSON config for schema-based gateways.
- `isActive`: gateway enabled flag.
- `currency`: gateway currency.

## Core vs Plugin Gateways

Core gateways are built into the application. Plugin gateways come from enabled valid `payment_gateway` plugins.

## Admin Setup

1. Open Payment Gateways.
2. Install/configure a supported gateway module.
3. Fill required config keys.
4. Create a StorePaymentMethod linked to the gateway.
5. Activate both gateway and method.

## Diagnostics

```bash
php bin/console app:payment:list-modules
php bin/console app:payment:list-gateways
php bin/console app:payment:debug-gateway-config <gatewayId>
php bin/console app:payment:test-zibal <gatewayId>
php bin/console app:payment:test-nowpayments <gatewayId>
php bin/console app:payment:test-plugin-gateway <gatewayId>
```

Use only commands that match the gateway type.
