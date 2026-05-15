# Telegram Bot

The Telegram bot is the customer-facing shop. It creates orders, collects payment, and shows provisioned services.

## Purchase Flow

1. User starts the bot.
2. User selects a plan.
3. If enabled by plan/settings, user can choose custom username, volume, or duration.
4. User can apply a discount code.
5. Bot creates or resumes an incomplete order.
6. User selects a StorePaymentMethod.
7. Bot starts the selected gateway flow.
8. Payment is verified by callback/check or admin approval.
9. Confirmed payment is finalized through `PaymentApprovalService`.
10. Bot sends service details, subscription link, QR code, or configs.

## Payment Options

- Manual card: user receives card instructions and uploads a receipt or tracking text.
- Online payment: user receives a payment URL and can check payment status.
- Crypto payment: user receives a gateway payment URL, usually invoice or direct-payment style depending on gateway config.

## Order Recovery

The bot supports continuing incomplete orders, deleting incomplete orders, expiring stale incomplete orders, and tracking orders by tracking code.

## Service Actions

Users can view “My services”, get subscription URLs, QR codes, and configs, renew service, or add traffic when those actions are available for the service.

## Admin Telegram Actions

Admins can receive payment notifications. Manual payments can be confirmed or rejected from admin actions, which notify the user.
