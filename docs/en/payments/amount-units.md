# Amount units (Toman / Rial) and amount verification

## Unit policy

All shop amounts are **integers in Toman**:

- `Plan::price`, `Order::amount`, `Payment::amount`, `Payment::payableAmount`
- every amount shown by the Telegram bot ("… تومان")

Inside the code Toman is written as the pseudo-ISO code `IRT`. The only place that converts units is
`App\Payment\Domain\Money\AmountNormalizer`:

| Direction | Rule |
|---|---|
| Shop → rial gateway (Zibal, NovinoPay, …) | `AmountNormalizer::toRial($toman)` = Toman × 10 |
| Gateway report → core | `AmountNormalizer::normalize($amount, $currency)`: `IRR`/`rial` ÷ 10 → `IRT`; `IRT`/`toman` unchanged; other currencies (USD, …) unchanged |

`Payment::currency` / `PaymentGateway::currency` are legacy labels and are **not** used for amount checks.

**Admin warning.** The plan list has a *Unit check* column, and saving a plan shows a warning, when a
price looks implausible for Toman (`App\Shop\Application\PlanPriceSanityChecker`): price outside
5,000–20,000,000, price per GB outside 500–200,000, price per day outside 100–1,000,000 Toman. Too high
usually means the value was typed in Rial, too low means thousands of Toman or USD. Saving is never
blocked. `tests/Payment/UnitConversionPinningTest.php` pins what every gateway charges for a
150,000 Toman plan.

> Changed in hardening/payment-settlement: Zibal used to be sent the Toman number as if it were Rial,
> so customers paid one tenth of the price. Zibal now receives Toman × 10.

## Strict amount check

Every gateway's `verifyPayment()` must return the amount the provider itself reports as paid, plus its
currency (`PaymentVerificationResult::$amount`, `::$currency`, decimal strings). The core compares that
with the expectation using `AmountMatcher`:

| Result | Meaning | Core action |
|---|---|---|
| `match` | normalized amount and currency equal the expectation | confirm |
| `overpaid` | more than expected | confirm and flag for the admin |
| `underpaid_within_tolerance` | less than expected, shortfall ≤ the gateway's tolerance (see below) | confirm and flag `underpaid_tolerated` |
| `underpaid` | less than expected, beyond the tolerance | **needs review** (shortfall in the review reason) |
| `currency_mismatch` | different currency after normalization | **needs review** |
| `missing` | amount or currency not reported / not a number | **needs review** |

The expectation is `payableAmount` in Toman, unless the gateway stored its own quote when creating the
payment (`Payment::setExpectation()`), as NOWPayments does with `price_amount` / `price_currency`.

## Underpayment tolerance

Each gateway may accept a small shortfall. Config key (per payment gateway, in the admin config form
or the config JSON):

```json
{ "underpayment_tolerance_percent": 1 }
```

- Default when the key is empty or absent: **0** for fiat gateways (Zibal, NovinoPay, Custom API,
  manual card) and **1** for crypto gateways (module category `crypto`: NOWPayments, SwapWallet and
  other plugins whose `plugin.json` has `"category": "crypto"`).
- The value is a percentage of the expected amount (decimals allowed, e.g. `0.5`). Invalid values
  (negative, not a number) mean 0; values above 10 are capped at 10.
- A shortfall within the tolerance is confirmed and flagged `underpaid_tolerated` on the payment
  (`amount_flag`; expected and verified amounts stay visible in the admin).
- A shortfall beyond the tolerance goes to **needs review**. The review reason records the shortfall,
  e.g. `underpaid: expected 10 USD, gateway reported 9.5 USD; shortfall 0.5 USD (5%), tolerance 1%`.
- The comparison is exact decimal arithmetic (`shortfall × 100 ≤ expected × tolerance`), never floats.
- The tolerance never excuses a currency mismatch or a missing amount.

## Per gateway

| Gateway | Charged in | Reported amount |
|---|---|---|
| Manual card | – | none; the admin's approval is the verification |
| Zibal | Rial (Toman × 10) | `amount` from `/v1/verify`, `IRR` |
| NOWPayments | `price_currency` quote (e.g. USD) | `price_amount`/`price_currency` from the API, pro-rated by `actually_paid / pay_amount` when less arrived (`partially_paid` → underpaid) |
| Custom API | as configured | `verify.response_mapping.amount` + `verify.response_mapping.currency` (or fixed `verify.amount_currency`) |
| Plugins | as implemented | whatever the plugin returns in `amount`/`currency` |

Custom API templates can use `{{ amount }}` / `{{ payable_amount }}` (Toman, unchanged) or
`{{ amount_rial }}` / `{{ payable_amount_rial }}`.
