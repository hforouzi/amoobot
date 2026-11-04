# Trial backing plans (fix/trial-backing-plan-hidden)

A trial plan (`TrialPlan`, admin: Plans → Trial Plans, Mini App: Plans → Trial plans) is provisioned through an
ordinary `Plan` row, its **backing plan** (`trial_plan.backing_plan_id`). `TrialAccountService::syncBackingPlan()`
creates it on the first claim and rewrites it on every claim: title `[Trial] <trial title>`, price 0, inactive, not
customizable, the trial's inbound, traffic and days. Every trial claim creates an order of type `trial` on it.

The backing plan belongs to the trial area only. It must never be listed, priced, activated, edited or deleted from
the sales area, and never be sold.

## Step 0: findings on the base commit (b6b548e)

Reproduced with a throwaway test on the base commit. It created a trial plan "Gift", claimed it once (backing plan
`[Trial] Gift`, price 0, inactive), then opened the pages as a super admin:

| Place | Base behaviour |
|---|---|
| Web Plans list `/admin/plan` (EasyAdmin index, its search `?query=` and filters) | listed |
| Web plan detail `/admin/plan/{id}` and edit `/admin/plan/{id}/edit` (also the index's active toggle, a PATCH to edit) | open, editable |
| Web plan delete (row action and batch delete) | refused with "orders refer to it" (`PlanInUse`), so the row cannot be removed |
| Web discount code form, plan picker (`DiscountCodeCrudController`, `AssociationField plan`) | offered |
| Web reseller page, per-plan wholesale price picker (`ResellerAdminController::show` / `planPrice`) | offered; a rule could be saved |
| Web panel setup "plans" step (`PanelSetupController::plans`) | listed; its inbound could be changed |
| Web bulk price adjustment (`PlanPriceAdjustmentService`, also `app:plans:adjust-prices`) | included. A percent change of 0 stays 0, but a fixed-amount increase gives the backing plan a price |
| Web inbound page "used by plans" (`PlanInboundUsage::plansUsing`) | listed |
| Bot admin plans list / detail / activate / price (`AdminPlansHandler`), bot bulk price change | listed, could be activated and priced |
| Mini App admin plans list, tabs counts, search (`MiniAppAdminPlanController::list`) | listed and counted |
| Mini App admin plan page, update, check, duplicate, activate, deactivate, delete | all open |
| Mini App admin discount form plan picker, bulk price page, panel / inbound plan counts | offered / counted |
| Customer shop: bot and Mini App catalog (`PlanCatalog::forSale` / `findForSale`) | hidden only because the row is inactive |
| Reseller catalog and Mini App reseller section (`ResellerCheckout` via `PlanCatalog`) | hidden only because the row is inactive (and the price is 0) |
| Mini App guide payment topics (`GuideStateProvider`) | not affected: it reads trial plans and payment methods, never plans |
| Dashboard | no plan counts (only a link to the plan list and "add plan") |
| Exports | none list plans (the reseller CSV lists reseller orders, never trial orders) |
| Commands | `app:plans:adjust-prices` (as the bulk adjustment); no other command lists plans |
| Order / order draft admin (`OrderCrudController`, `OrderDraftCrudController`) | show the plan of a trial order. That is history, not a sales list, and it is kept |
| Renewal / add-traffic of a trial service | priced from the service's plan, the backing plan. Renewal is refused while its price is 0 (`ServiceOrderCheckout::renewalQuote`). Add-traffic is priced by the add-on settings |

Why deleting or deactivating it is hard today:

- `order.plan_id` is `ON DELETE RESTRICT` and every claim creates a `trial` order, so `PlanAdminService::delete()` refuses
  (`PlanInUse`) as soon as one trial was claimed. `reseller_plan_price.plan_id` is RESTRICT too.
- Deactivating looks like it works, but the row already is inactive. Activating is not undone until the next claim
  (`syncBackingPlan()` sets it inactive again).
- Deleting the trial plan deletes its `trial_plan` row. `trial_claim.trial_plan_id` is `ON DELETE CASCADE`, so the
  claims go with it. The backing plan stays as an orphan "[Trial] …" row in the sales area. The only link left is its
  `trial` orders (`trial_plan.backing_plan_id` is gone).

What happens when someone ACTIVATES it (confirmed):

- `PlanAdminService::setActive(true)` succeeds. `PlanCatalog::forSale()` then returns `[Trial] Gift @0`: the bot and
  Mini App shop list a free plan with the trial's inbound.
- Checking out at 0 stops at the draft confirmation (`NewServiceCheckout::confirmationBlocker`: amount ≤ 0) and
  resellers are not offered it (no wholesale price). Once the plan is given a price, which the sales area allows, it
  is an ordinary plan on sale (`findForSale()` returns it) that sells the trial's inbound and limits. A trial
  service's renewal also becomes payable at that price.

## The fix

- **Identification** (`App\Shop\Application\Catalog\TrialBackingPlans`): a plan is a trial backing plan when a
  `trial_plan` row points at it (`trial_plan.backing_plan_id`) or an order of type `trial` was made on it (the plan of
  a trial plan deleted earlier). Both are relations; the "[Trial]" title is never used, so a normal plan named
  "[Trial] …" is a normal plan. No migration: both columns and their indexes exist.
- **Sales side**: every list, picker and count above excludes backing plans through that one class (a DQL
  `NOT EXISTS` for query builders, the same condition in SQL for the raw queries). Every sales-side write refuses
  them (`TrialBackingPlanLocked`): `PlanAdminService` (web, bot, Mini App), reseller wholesale rules, bulk price
  changes, panel setup. Direct URLs show "this plan belongs to the trial plan …" (web: a flash and a redirect to the
  trial plan; Mini App: a page / a 409 answer; bot: a notice), never an error page.
- **Sale rule**: `PlanSaleRule` (`isSellable`, `sellable`, `acceptsOrders`, `acceptsPayment` for new services) refuses a
  backing plan even if its row was activated and priced by SQL, and `PlanCatalog::forSale()` excludes them in its query.
- **Lifecycle** (`TrialPlanAdminService`, shared by the web and the Mini App): deactivating a trial plan hides it
  from customers (as before). Deleting it locks the `trial_plan` row, then its backing plan row, and deletes the
  backing plan too when nothing refers to it (orders, which also covers services and claims). Otherwise it keeps the
  plan inactive and hidden, and the admin message says why. A claim locks the same `trial_plan` row first
  (`TrialAccountService`), so a delete and a claim are serialized: the claim either sees no trial plan or its order
  exists before the delete counts references. Lock order: `trial_plan`, then `plan`, then `order`.
- **Existing data**: `app:trial-plans:backing-audit` (dry run by default, `--apply` to write) lists backing plans and
  fixes an active or priced one (inactive, price 0) and wholesale rules on one (removed).
  It also turns off customization and per-GB / per-day prices, and works plan by plan under the plan row's lock. Each
  fix is audited (`trial_plan.backing_plan_fixed`). It is idempotent and deletes no plan, order or service. Plans only
  titled "[Trial] …" are listed as a note and left alone.
- **Deleting a backing plan with nothing referring to it**: discount codes limited to it are deactivated (the foreign
  key's `SET NULL` would otherwise turn them into codes for every plan). Its wholesale rules are deleted. Order drafts
  and additional-inbound rows go with it (`ON DELETE CASCADE`).
- **Left as before, on purpose**: a trial plan's claims are still deleted with it (`trial_claim.trial_plan_id`
  `ON DELETE CASCADE`). The trial orders and services keep the history. Renewals and extra traffic of a trial
  service still run on its backing plan (they already did, and need no sales-side access). The panel's
  "plans using it" guard in modern mode still counts active plans only, which a backing plan never is.
