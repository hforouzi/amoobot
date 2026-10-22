# Customer Wallet

Every customer can have a wallet: a balance in Toman that is topped up with the shop's payment methods and spent on new services, renewals and extra traffic. The wallet is **off by default**.

## Settings (Settings → Wallet, super admin)

- **Enable the wallet**: shows "Wallet" in the bot menu and on the Mini App "My services" page.
- **Allow paying orders from the wallet** (on by default once the wallet is enabled).
- **Minimum / maximum top-up** and **quick amounts** (comma separated, each within the range).

## How it works

- **Ledger.** Every movement is an append-only row (top-up, purchase, refund, adjustment, reward, reversal) with the balance after it. A mistake is corrected with a new row, never by editing. The cached balance must equal the ledger sum: `php bin/console app:wallet:reconcile` reports any mismatch (exit code 1) and never fixes anything.
- **Top-up.** A top-up is an order of type "wallet top-up" paid like any order (card to card with a receipt, Zibal, custom API, NOWPayments, plugins). The wallet is credited once, when the payment is confirmed. A rejected, failed, expired or cancelled payment never credits; money paid after a cancel or an expiry waits in "Needs review" and is credited only when an admin approves it.
- **Paying from the wallet.** Offered only when the balance (plus the credit limit) covers the whole amount; partial wallet payment is not supported. The order gets a payment of method "wallet" and is confirmed like any other payment. If creating the service fails, the usual "Retry" works and never takes the money twice. If an admin rejects the payment (with or without "refunded"), or an unsettled wallet payment is cancelled or expires, the money goes back to the wallet once.
- **Credit limit.** How far below zero a balance may go (0 by default). It cannot be set lower than the credit already in use.
- **Frozen wallet.** Cannot be used to pay or be topped up by the customer; payments already made are still credited and admins can still adjust.

## Admin

- **Customers → Wallets** (web): every wallet with balance, credit limit and last activity, the totals, and each wallet's full ledger. With *manage* permission: manual credit/debit (a reason is required and the change is confirmed on a second screen), credit limit, freeze/unfreeze. Every change is in the audit log.
- **Mini App admin**: the wallet on a customer's page, its ledger, and an adjustment confirmed in Telegram's dialog.
- **Dashboard**: total customer balance and credit in use / granted.
- **Permissions**: *Wallets view* (super admin, admin, support) and *Wallets manage* (super admin, admin).
