# Resellers

A reseller is a customer account that buys services at a **wholesale price from its own wallet** and hands them to its own customers. Phase S1 of [the design](../design/reseller-and-claim.md): reseller profiles, wholesale pricing, purchases from the wallet in the bot, the admin pages and the report. Claim links (handing a service to the end customer's Telegram account) and the reseller Mini App come later.

Resellers are **off by default**. While they are off nothing changes for anybody: no reseller menu, no wholesale purchase.

## Turning it on

1. The wallet must be on (Settings → Wallet), with "Allow paying orders from the wallet": reseller purchases are wallet payments.
2. Settings → **Resellers** (super admin):
   - **Enable resellers.**
   - **Floor** (percent of the base price, default 50): no reseller ever pays less than this share of a plan's base price, whatever discount he has.
   - **Rounding** (Toman, default 1000): wholesale prices are rounded **up** to a multiple of this, before the floor is checked.
3. Customers → **Resellers** (RESELLERS_MANAGE): create a profile for an existing customer (user id, Telegram id or @username). Only admins create resellers; there is no request button.

## Wholesale price

Computed on the server only, in whole Toman:

- **base** = the plan's list price (a customizable plan: traffic × price per GB + days × price per day; extra traffic: GB × the add-on price per GB).
- **rule** = the reseller's per-plan rule for that plan, else his default discount. A per-plan rule is a discount percent, or a fixed price for a fixed (non-customizable) plan. Extra traffic uses the plan's percent rule or the default discount, never a fixed price.
- **candidate** = base minus the discount (or the fixed price), rounded up to the rounding.
- **floor** = the larger of the floor percent of the base and the plan's own **reseller floor price** (Plans → edit, fixed plans).
- **wholesale = min(max(candidate, floor), retail)**, where retail is what a customer pays now (base after the shop-wide discount). So a reseller never pays more than a customer during a promotion, and never less than the floor otherwise.

**Discount codes and the shop-wide discount never stack** on the wholesale price. A rule the floor overrides is shown with "Floor applies" on the reseller page and as a warning on the plan.

## Buying (bot)

An active reseller gets a **🧑‍💼 Reseller** button in the main menu (only while the wallet can pay: Settings → Wallet, wallet and wallet payments on): balance, **New service** (every plan for sale, with its wholesale price; a customizable plan shows its lowest "from" price), **Services I sold** (renew and add traffic at wholesale). Every purchase shows "wholesale / retail / balance before → after" and is paid only after **Pay from wallet**.

- **Customizable plans.** After the plan, the bot asks the traffic (GB) and then the days, each within the plan's range: tap a suggested value or type a whole number (digits only, Persian digits accepted; anything else, including spaces, is refused and asked again; the question expires after 5 minutes). Then it shows the exact price and the usual confirmation. The pay button carries the chosen GB and days, covered by the confirmation's signature.
- **Nothing to show.** "New service" says why: new sales are closed; no plan is set up; plans exist but none is for sale (an inbound or panel unavailable); or plans are for sale but none can be priced for resellers (a zero price).

- **One tap, one purchase.** The **Pay from wallet** button carries a one-time confirmation of exactly what was shown (that plan or service, GB, and amount). Tapping it twice, or Telegram delivering the same tap again, buys once; the second answer says where the first one stands. A confirmation is valid for 10 minutes; an older one shows a fresh confirmation instead of buying.
- **Only the confirmed price.** If the price changed after the confirmation was shown (an admin changed the discount, a plan price, the floor, a promotion), nothing is bought: the bot says so and shows a fresh confirmation with the price now.
- **Not enough money:** the bot asks the reseller to top up his wallet or contact support about his credit limit (there is no other payment method for reseller purchases).
- **An unfinished purchase** opened from "Continue my order" or order tracking shows the same state message (money held while it is processed, done, ...) instead of a payment screen; while the money is held, **Cancel** closes the order and gives the money back once.
- **After a failure** the bot says what really happened, read from the database: nothing was taken; or the money is held while the purchase is processed (do not pay again; if it does not finish it goes back to the wallet automatically within the incomplete-order expiry, 24 hours by default; the tracking code is shown for support); or the service could not be created yet (paid, held for the admins' retry); or the amount is back in the wallet.

- The order is created and paid from the wallet in one step. If the wallet (balance + credit limit) does not cover it, nothing is created.
- The price paid is the confirmed one, checked again when the order is created and stored on it as its snapshot (wholesale, retail value, floor).
- A reseller order is never offered a gateway, a card-to-card receipt or the customer's "pay from wallet": it is paid by the reseller flow only.
- New services are created under the reseller's own account and remember who sold them. Services he did not sell (e.g. his own retail purchases) cannot be renewed at wholesale.
- The required-channels rule and the sales switches (new orders, renewals, extra traffic) apply as for everybody.

## Limits

- **Credit:** the wallet's credit limit (Customers → Wallets), 0 by default; set it per reseller if he may buy on credit.
- **Daily spend cap** (optional, per reseller): today's open and paid reseller orders may not exceed it. "Today" is the day in the application timezone, which is **UTC** in production (`App\Kernel::TIMEZONE`, the zone every stored date uses): the cap starts again at 00:00 UTC (03:30 in Tehran).
- **Frozen wallet:** no purchase.
- **Disabled reseller:** no purchase from that moment (a purchase in flight either finished before or is refused), his Mini App sessions end, the menu disappears. Services already sold keep running.

## Refunds

No automatic refund. On the reseller page, an admin with RESELLERS_MANAGE **and** WALLETS_MANAGE can refund a provisioned order **after its service is suspended or deleted**, at most the wholesale amount the wallet paid, once per order, with a reason, after a confirmation step. Before the service exists the usual wallet rules apply (a rejected, cancelled or expired wallet payment gives its money back once; a failed provisioning keeps it for the retry).

## Report

Customers → Resellers → **Reseller report**: per reseller, the orders whose wallet payment is confirmed in the period, the wholesale paid, the retail value, the discount given (retail − wholesale; not the reseller's profit, which the shop does not know) and refunds; CSV download.

## Permissions and audit

- **RESELLERS_VIEW** (super admin, admin, support): the list, profiles, the report.
- **RESELLERS_MANAGE** (super admin, admin): create, edit, plan rules, enable / disable. Refunds also need WALLETS_MANAGE.
- Every change is in the audit log: `reseller.create`, `reseller.update` (before / after), `reseller.plan_price`, `reseller.enable`, `reseller.disable` (with the Mini App sessions ended), `reseller.order_refund`, and `settings.update` for the settings tab.

## Monitoring

`php bin/console app:wallet:reconcile` also checks reseller orders: one paid any other way than from the wallet (`reseller_order_not_wallet_paid`), a held debit / order amount / wholesale snapshot that disagree (`reseller_debit_snapshot_mismatch`), a wholesale snapshot below min(floor, retail) (`reseller_order_below_floor`), a service whose "sold by" differs from its order (`reseller_service_attribution_mismatch`), a reseller order that is not on the reseller's own account (`reseller_order_wrong_customer`), a reseller order without its confirmation nonce (`reseller_order_without_nonce`), and refunds without their reseller order (`refund_without_order`), above what was paid (`refund_exceeds_paid`) or for a service that runs again (`refund_on_live_service`).

## Upgrading

Run the migrations (`Version20261027090000`: new tables and nullable columns only; `Version20261029090000`: `order.reseller_nonce`, a nullable column and a unique key per reseller). Nothing existing is changed. Roll back newest first, and only while no reseller data exists (no order carries a confirmation nonce for the second, no reseller data for the first); otherwise `down()` refuses and changes nothing.
