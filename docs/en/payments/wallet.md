# Customer Wallet

Every customer can have a wallet: a balance in Toman that is topped up with the shop's payment methods and spent on new services, renewals and extra traffic. The wallet is **off by default**.

## Settings (Settings → Wallet, super admin)

- **Enable the wallet**: shows "Wallet" in the bot menu and on the Mini App "My services" page.
- **Allow paying orders from the wallet** (on by default once the wallet is enabled).
- **Minimum / maximum top-up** and **quick amounts** (comma separated, each within the range).

## How it works

- **Ledger.** Every movement is an append-only row (top-up, purchase, refund, adjustment, reward, reversal) with the balance after it. A mistake is corrected with a new row, never by editing. The cached balance must equal the ledger sum: `php bin/console app:wallet:reconcile` reports any mismatch (exit code 1) and never fixes anything. It also checks payments against the ledger and lists, with their ids: a confirmed top-up without its credit, a top-up credit whose payment is not confirmed, a wallet payment without its debit, a rejected payment whose debit was never given back, a debit given back while its payment is still live, and a purchase debit without a payment.
- **Top-up.** A top-up is an order of type "wallet top-up" paid like any order (card to card with a receipt, Zibal, custom API, NOWPayments, plugins). The wallet is credited once, when the payment is confirmed. A rejected, failed, expired or cancelled payment never credits; money paid after a cancel or an expiry waits in "Needs review" and is credited only when an admin approves it.
- **What a top-up credits.** What was actually received, never more than the ordered amount. If a gateway reports a little less than expected (within its underpayment tolerance, e.g. 1% for crypto) or an admin approves an underpaid top-up, the wallet gets `floor(ordered × received ÷ expected)` Toman; to give the full amount anyway, add the difference as a manual adjustment. An overpayment credits the ordered amount and alerts the admins. A card-to-card top-up approved from its receipt credits the ordered amount. The ledger row's note shows received vs credited.
- **Paying from the wallet.** Offered only when the balance (plus the credit limit) covers the whole amount; partial wallet payment is not supported. The order gets a payment of method "wallet" and is confirmed like any other payment. If creating the service fails, the usual "Retry" works and never takes the money twice. If an admin rejects the payment (with or without "refunded"), or an unsettled wallet payment is cancelled or expires, the money goes back to the wallet once.
- **A wallet payment that did not finish.** If the wallet was debited but confirming the order did not finish (a timeout or an error while the service was being created), the order keeps that one wallet payment. Until it is settled, another payment method cannot be started for the order: the customer is asked to tap "Pay from wallet" again (it reuses the same debit, nothing is taken twice) or to cancel the order (the amount goes back to the wallet). This is deliberate: starting a gateway payment beside a debited wallet payment could charge the customer twice.
- **No "paid late" for wallet payments.** A wallet payment that was cancelled or expired before it was settled already gave its money back; it is never parked in "Needs review" as a late payment. A wallet payment is confirmed (by itself, by an admin's approval or by a retry) only while its debit is still taken; otherwise the admin sees "Not confirmed: this wallet payment has no wallet debit any more" and should reject it.
- **Credit limit.** How far below zero a balance may go (0 by default). It cannot be set lower than the credit already in use.
- **Frozen wallet.** Cannot be used to pay or be topped up by the customer; payments already made are still credited and admins can still adjust.

## Admin

- **Customers → Wallets** (web): every wallet with balance, credit limit and last activity, the totals, and each wallet's full ledger. With *manage* permission: manual credit/debit (a reason is required and the change is confirmed on a second screen), credit limit, freeze/unfreeze. Every change is in the audit log.
- **Mini App admin**: the wallet on a customer's page, its ledger, and an adjustment confirmed in Telegram's dialog.
- **Dashboard**: total customer balance and credit in use / granted.
- **Permissions**: *Wallets view* (super admin, admin, support) and *Wallets manage* (super admin, admin).
