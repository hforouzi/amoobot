# Payment Methods Not Showing

Use this chain:

1. Plugin is enabled, if the gateway is plugin-based.
2. PaymentGateway is active.
3. PaymentGateway is configured.
4. StorePaymentMethod is active.
5. Order is still `waiting_payment`.
6. Currency matches.
7. Amount is inside min/max limits.
8. Gateway driver exists.

## Commands

```bash
php bin/console app:payment:list-modules
php bin/console app:payment:list-gateways
php bin/console app:payment:list-methods
php bin/console app:payment:debug-methods-for-order <orderId>
```

## Reasons

- `order_not_waiting_payment`: order is no longer payable.
- `gateway_driver_missing`: no usable driver was resolved.
- `gateway_not_configured`: required gateway config is missing.
- `plugin_disabled`: plugin is not enabled.
- `currency_mismatch`: method currency does not match order currency.
- `method_inactive`: StorePaymentMethod is inactive.
- `gateway_inactive`: linked gateway is inactive.
