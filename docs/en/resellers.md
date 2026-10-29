# Resellers

A reseller is a customer account that buys services at a **wholesale price from its own wallet** and hands them to its own customers. Phases S1 and S2 of [the design](../design/reseller-and-claim.md): reseller profiles, wholesale pricing, purchases from the wallet in the bot, the admin pages and the report (S1), and **claim links** that hand one service to the end customer's Telegram account (S2, [below](#claim-links)). The reseller **Mini App** (S3) is [below](#mini-app).

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
- **Disabled reseller:** no purchase from that moment (a purchase in flight either finished before or is refused), his open claim links are revoked, his Mini App sessions end, the menu disappears. Services already sold keep running.

## Refunds

No automatic refund. On the reseller page, an admin with RESELLERS_MANAGE **and** WALLETS_MANAGE can refund a provisioned order **after its service is suspended or deleted**, at most the wholesale amount the wallet paid, once per order, with a reason, after a confirmation step. Before the service exists the usual wallet rules apply (a rejected, cancelled or expired wallet payment gives its money back once; a failed provisioning keeps it for the retry).

## Report

Customers → Resellers → **Reseller report**: per reseller, the orders whose wallet payment is confirmed in the period, the wholesale paid, the retail value, the discount given (retail − wholesale; not the reseller's profit, which the shop does not know) and refunds; CSV download.

## Permissions and audit

- **RESELLERS_VIEW** (super admin, admin, support): the list, profiles, the report.
- **RESELLERS_MANAGE** (super admin, admin): create, edit, plan rules, enable / disable. Refunds also need WALLETS_MANAGE.
- Every change is in the audit log: `reseller.create`, `reseller.update` (before / after), `reseller.plan_price`, `reseller.enable`, `reseller.disable` (with the Mini App sessions ended and the claim links revoked), `reseller.order_refund`, and `settings.update` for the settings tab.
- Claim links: SERVICES_VIEW to look, SERVICES_MANAGE to make, revoke or return (see [Claim links](#claim-links)).

## Monitoring

`php bin/console app:wallet:reconcile` also checks reseller orders: one paid any other way than from the wallet (`reseller_order_not_wallet_paid`), a held debit / order amount / wholesale snapshot that disagree (`reseller_debit_snapshot_mismatch`), a wholesale snapshot below min(floor, retail) (`reseller_order_below_floor`), a service whose "sold by" differs from its order (`reseller_service_attribution_mismatch`), a reseller order that is not on the reseller's own account (`reseller_order_wrong_customer`), a reseller order without its confirmation nonce (`reseller_order_without_nonce`), and refunds without their reseller order (`refund_without_order`), above what was paid (`refund_exceeds_paid`) or for a service that runs again (`refund_on_live_service`).

## Claim links

A claim link hands **one** service to whoever opens it in Telegram and taps **Add to my account**. It is **single use**, **expires** (7 days by default) and can be **revoked**. The end customer then sees the service in "My services" (bot and Mini App) like one he bought. No money moves.

Claim links are **off by default** and need resellers on too: Settings → Resellers → **Enable claim links**, and **A new link works for (hours)** (1 to 720, default 168; changing it does not change links already made). With them off, no link can be made or used, open links stop working, `/start` and the Mini App behave exactly as before, and nothing changes for any customer.

### For the reseller (bot)

- **Services I sold → a service → Make claim link.** Only for a service he sold and still owns (not a trial, not deleted, not while one of its orders is being paid or provisioned). The bot shows the link **once**: `https://t.me/<bot>?start=claim_…` (and `?startapp=claim_…` when the Mini App is on; that needs the bot's Main Mini App set in BotFather), plus its QR. Making a new one revokes the open one of that service. At most 30 links per hour.
- **Claim links:** his links, open first (service, until / claimed on / revoked / expired), with **Revoke** for each open one. The list never shows the link again, nor who claimed it.
- After the claim he is told "used on <date>" and still sees the service in "Services I sold" with plan, status, expiry, traffic and **claimed on**. He can still **renew** and **add traffic** at wholesale (decision D5): he is told it is done, **without** the subscription link or configs, which go to the customer. He never sees the customer's Telegram data, other services, orders or wallet, cannot make a new link for it, and has no subscription page or config access to it any more.
- Disabling a reseller **revokes his open links** at once (re-enabling does not bring them back). Services already claimed are not affected.

### For the customer

- Opening the link (bot `/start claim_…` or the Mini App) shows a preview: service, plan, traffic left, expiry and the reseller's display name (if set). The required channels are checked first, as for a purchase. Nothing changes until he taps **Add to my account**; link-preview bots and prefetches never claim.
- Every unusable link (wrong, truncated, expired, revoked, used, owner changed, service deleted) gets the **same** answer, so the link cannot be probed. The person who claimed it gets "already in your account" if he opens it again.
- On the claim the service's **subscription page address changes** (the reseller's `/s/…` link stops working) but the **panel credentials stay** (UUID, subId, config links), so the apps the reseller installed keep working (decision D4). The customer can then use **♻️ Reset my connection links** in the service view: new UUID and subId on the panel, new links through the regeneration path; every installed app must import them again (the reseller's copies stop working). Modern 3x-ui panels with VLESS / VMess only. A reset holds the service's row lock while the panel changes (a claim, a return or another reset of it waits), a double-tapped confirm button resets once, and a service can be reset at most 3 times a day. If the panel call fails, the panel is read back: new credentials there are saved as a success, the old ones mean nothing changed (the customer is told so), anything else is reported to admins with LOGS_VIEW and the customer is told it could not be confirmed. After the reset the panel is checked once more and the new credentials re-applied if a concurrent panel write (renewal, suspend) put the old ones back.
- After a claim the panel client is updated (best effort) to `tgId` = the customer's Telegram id and the comment marker `amoobot order #N, claimed` (modern 3x-ui only; legacy panels are skipped). A comment an admin wrote on the panel is kept: the marker goes after it (`your note | amoobot order #N, claimed`; left out when that would exceed 255 characters). A client that authenticates by password (Trojan, Shadowsocks) or whose panel id is not a UUID is marked **not applicable** after the first attempt and never retried; nothing is sent to the panel for it. A failure never undoes the claim: `app:claims:expire` retries it with a growing delay (5 minutes up to 6 hours) and gives up after 12 attempts (shown on the admin page).

### Security

- The token is 32 random bytes (256 bit, 43 characters). Only its SHA-256 is stored; the token is never logged, audited or stored, and the bot message log masks it (`claim_[redacted]`). After the preview, buttons and the Mini App carry a short-lived **ticket** bound to the claim and the Telegram user, never the token.
- Payloads are parsed exactly as received (no trimming; anything with extra characters, spaces or a newline is not a link).
- Rate limits: 10 previews / claims per 10 minutes per Telegram user (invalid ones included), 30 per minute per address on the Mini App claim routes, 30 new links per hour per reseller. The fifth invalid link of one Telegram user within an hour is reported to the admins with LOGS_VIEW (no automatic ban).
- The claim is one transaction locking the claim row, then the service row; it never touches a wallet, order or payment row, so it cannot deadlock with settlement. It is refused while an order of the service is being paid or provisioned ("try again in a minute").

### Admin

Customers → **Claim links** (SERVICES_VIEW): every link by status; a service's links and **ownership history** (also from the service's detail page, "Claim links and owners"). With SERVICES_MANAGE:

- **Make claim link** for any live service (support case: e.g. moving a customer's service to his new Telegram account). Shown once on the answer page. Audited `claim.create_admin` (never the token).
- **Revoke** an open link. Audited `claim.revoke_admin`.
- **Return to reseller** (reason required): the wrong person claimed it. The service goes back to the reseller who sold it with a new subscription page address; the panel credentials stay (reset them afterwards if needed); both sides get a bot message. Audited `claim.revert`, plus an ownership event.

### Monitoring

- `app:claims:expire` (scheduled as `app:automation:run --only=claims`, every 15 minutes, in the `lifecycle` lock group) marks links past their expiry as expired and retries pending panel updates. Safe to run by hand; `--dry-run` only counts.
- `app:wallet:reconcile` also checks the claim invariants (no money involved, listed with the payments): `claim_open_owner_changed`, `claim_open_service_mismatch`, `claim_open_reseller_disabled`, `claim_without_ownership_event`, `claim_owner_mismatch`, `ownership_event_owner_mismatch`.

## Mini App

With resellers on, an active reseller gets a **Reseller** entry in the Mini App. Nobody else sees it; every reseller
page answers "not found" to anybody else and while resellers are off.

- **Dashboard:** wallet balance and credit, what is left under today's cap, sales this month and in total (paid at
  wholesale, the retail value and the margin between them; confirmed orders only; refunds on their own line, taken
  out of both what was paid and the retail value in the month they were made, so a fully refunded order adds no
  margin), sold services (still his, claimed,
  expiring soon), open claim links and the latest orders. The wallet button opens the usual wallet screen.
- **New service:** fixed and customizable plans (traffic / days inside the plan's range) at the wholesale price, quoted
  by the server. **Buy from wallet** first shows the price and the balance after for confirmation; nothing is paid
  before OK. A changed price asks again with the new price. A double tap is one purchase.
- **Services sold:** search (username, `#id`, customer label), filters (still mine, claimed, expiring, status), renew /
  add traffic at wholesale. While a service is still the reseller's own, its subscription page, the "send to chat"
  actions and a claim link are there too; once a customer claimed it, only plan, status, expiry, traffic and the date
  it was claimed are shown, never its links or who has it.
- **My customers:** the reseller's own labels (name, optional phone and note) and which of the sold services each one
  has. Only what was typed; nothing is linked to a Telegram account.
- **Claim links** (with claim links on): the links with status and date, revoke an open one; a new link is shown once
  with copy, share and a QR.

## Upgrading

Run the migrations (`Version20261027090000`: new tables and nullable columns only; `Version20261029090000`: `order.reseller_nonce`, a nullable column and a unique key per reseller; `Version20261030090000`: the `service_claim` and `service_ownership_event` tables; `Version20261031090000`: the `reseller_customer` and `reseller_customer_service` tables for the Mini App customer labels, removed together with their reseller or service). Nothing existing is changed. Roll back newest first, and only while no data of that migration exists (no customer label or label assignment for the fourth, no claim link or ownership event for the third, no order carrying a confirmation nonce for the second, no reseller data for the first); otherwise `down()` refuses and changes nothing.

After deploying S2, `deploy.sh` installs the new `claims` timer; `bin/post-deploy-check.sh` checks it with the other jobs and runs `app:wallet:reconcile`, which now includes the claim checks.
