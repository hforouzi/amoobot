# Payments Overview

Payments are split into configuration, bot visibility, transaction state, and final provisioning.

## Concepts

- PaymentGateway: credentials/configuration for one gateway account or module.
- StorePaymentMethod: what users see in Telegram. It links to one PaymentGateway and controls active status, order, amount limits, and currency.
- Payment: the actual transaction for an order.
- Order: purchase, renewal, or add-traffic intent.
- PaymentApprovalService: final provisioning path after confirmed payment.

Gateways and plugins must not provision services directly. They only create payment requests, verify payments, or handle webhook signals. Confirmed payments then go through `PaymentApprovalService`.

## Flow

```text
Order
  -> StorePaymentMethod
  -> PaymentGateway driver
  -> payment URL or manual receipt
  -> callback / verify / admin approval
  -> PaymentApprovalService
  -> VPN service provisioning
```

Gateway active status is not enough. A StorePaymentMethod must also be active, match order currency/amount constraints, and have a usable driver.
