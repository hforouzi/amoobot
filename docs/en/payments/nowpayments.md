# NOWPayments Gateway

NOWPayments is a core crypto gateway.

## Config

- `api_key`: required.
- `ipn_secret`: **required** IPN signature secret. Without it every IPN is rejected (HTTP 403); payments are then only confirmed via the success page or `payment:check-nowpayments`.
- `payment_mode`: `invoice` or `payment`.
- `price_currency`: currency used for invoice pricing, commonly `usd`.
- `pay_currency`: direct payment currency when needed.
- `amount_unit`: store amount unit, such as `toman` or `rial`.
- `toman_per_usd`: required for IRR/Toman conversion.
- `callback_base_url`: required public base URL. The customer is sent back to
  `{callback_base_url}/payment/return/{return token}` (cancel: `.../cancel`); the page identifies the
  payment only by that token and refreshes itself while the payment is pending. Return links issued
  before this change (`/payment/success?NP_id=...`) no longer identify a payment.

## Notes

- NOWPayments auth uses the `x-api-key` header.
- Invoice mode sends users to a hosted invoice page.
- Direct payment mode can require a concrete `pay_currency`.
- Minimum amount errors usually mean the converted crypto amount is below provider limits.
- Underpaid, partial, expired, and pending statuses should remain payment states until verified/handled by the existing flow.

## Diagnostics

```bash
php bin/console app:payment:test-nowpayments <gatewayId>
php bin/console app:payment:test-nowpayments-auth <gatewayId>
php bin/console app:payment:debug-nowpayments-amount <gatewayId>
php bin/console app:payment:check-nowpayments <paymentId>
```
