# Zibal Gateway

Zibal is a core online rial payment gateway.

## Config

- `merchant`: required merchant code. Use Zibal-provided merchant in production.
- `sandbox`: optional boolean for test mode.
- `callback_base_url`: required public application base URL.

## Flow

The driver creates a payment URL, receives callback data, and verifies payment status before final approval.

Callback route:

```text
/payment/callback/zibal?rt={return token}
```

The callback is matched to the payment by the stored `trackId` and verified with Zibal; `rt` only
sends the customer on to the return page `/payment/return/{token}` afterwards.

## Test

```bash
php bin/console app:payment:test-zibal <gatewayId>
```
