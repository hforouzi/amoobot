# Manual Card Gateway

Manual card payment shows bank/card instructions to the user and waits for receipt submission.

## Config

- `card_number`: required card number.
- `card_holder`: required card holder name.
- `bank_name`: optional bank name.
- `instructions`: optional extra text shown to the user.

## Flow

1. User selects the manual card StorePaymentMethod.
2. Bot shows card instructions.
3. User uploads a receipt photo or tracking text.
4. Payment becomes submitted.
5. Admin confirms or rejects payment.
6. Confirmed payment is finalized through `PaymentApprovalService`.
