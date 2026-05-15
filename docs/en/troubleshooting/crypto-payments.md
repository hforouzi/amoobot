# Crypto Payments Troubleshooting

## Common Issues

- Invalid API key: verify credentials and environment.
- NOWPayments uses `x-api-key`.
- Minimum amount: converted crypto amount may be below provider limits.
- Invoice vs direct payment: direct flow may require `pay_currency`.
- Underpaid or partial payment: keep payment pending/failed according to gateway verification result; do not provision directly.
- Expired payment: user may need a new payment attempt.
- Webhook signature: configure and verify secrets where supported.
- Exchange rate snapshot: keep conversion config current, especially `toman_per_usd`.

## Commands

```bash
php bin/console app:payment:test-nowpayments-auth <gatewayId>
php bin/console app:payment:debug-nowpayments-amount <gatewayId>
php bin/console app:payment:test-plugin-gateway <gatewayId>
```
