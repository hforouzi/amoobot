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
| `underpaid` | less than expected | **needs review** |
| `currency_mismatch` | different currency after normalization | **needs review** |
| `missing` | amount or currency not reported / not a number | **needs review** |

The expectation is `payableAmount` in Toman, unless the gateway stored its own quote when creating the
payment (`Payment::setExpectation()`), as NOWPayments does with `price_amount` / `price_currency`.

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
