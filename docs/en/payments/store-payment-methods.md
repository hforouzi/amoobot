# Store Payment Methods

StorePaymentMethod records control the payment choices shown in the Telegram bot.

## Fields

- `gateway`: linked PaymentGateway.
- `title`: bot-visible label.
- `isActive`: controls bot visibility.
- `sortOrder`: lower values appear earlier.
- `minAmount` / `maxAmount`: optional payable amount limits.
- `currency`: must match order currency.

## Visibility Checklist

- StorePaymentMethod is active.
- Linked PaymentGateway is active.
- Gateway is configured.
- Gateway driver exists and can load.
- Currency matches the order.
- Amount is within min/max.
- Order is still `waiting_payment`.

Diagnostics:

```bash
php bin/console app:payment:list-methods
php bin/console app:payment:debug-methods-for-order <orderId>
```
