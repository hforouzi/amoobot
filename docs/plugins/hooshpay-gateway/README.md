# HooshPay Payment Gateway Plugin

Installable HooshPay card-to-card Toman payment gateway plugin for Amoobot. Plugin code: `hooshpay`. API docs:
<https://hooshpay.xyz/developers>.

It follows the strict verification contract in `docs/en/plugins/payment-gateway-plugin-sdk.md`. The plugin creates the
invoice, authenticates HooshPay's webhook and asks HooshPay whether the invoice was paid. Amoobot does everything else:
locking, idempotency, amount check, provisioning, wallet credit and notifications.

**Installed disabled.** The `enabled` switch defaults to off, and a gateway with the switch off, a missing key or Secret,
or an invalid setting is never offered to customers (fail closed). With the plugin disabled nothing in Amoobot changes.

## Flow

1. **Create**: `POST {base_url}/api/v1/invoices` with the header `X-API-KEY` and the JSON body:

   | Field | Value |
   |---|---|
   | `amount` | The payment's payable amount in **Toman**, unchanged (Amoobot counts in Toman too; never ×10) |
   | `fee_mode` | The `fee_mode` setting |
   | `description` | The `description_template` setting, filled in (UTF-8, Persian is fine) |
   | `order_id` | Our unique reference of this payment row: `{order tracking code}-P{payment id}` |
   | `callback_url` | `{callback_base_url}/payment/webhook/hooshpay` (the webhook) |
   | `return_url` | The core return page `/payment/return/{return token}`, on the brand domain when there is one |

   The answer's `uid` is stored as the payment's transaction id, `expires_at` as its expiry (fallback: the
   `invoice_lifetime_seconds` setting), and the customer is sent to `payment_url`, which must be an https URL on the
   `base_url` host. The bot and the Mini App also show HooshPay's `payable_amount`: that is what the customer transfers.
   The answer's **card object (card number, holder name, bank) is never stored or logged**: only an allowlist of fields
   is kept.
2. **One invoice per payment row.** Choosing HooshPay again while the invoice is live hands out the same link without an
   API call. When the core replaces the row (the amount changed, the invoice expired, the customer switched gateways), it
   closes the old row and the plugin calls `POST {base_url}/api/v1/invoices/{uid}/cancel` on the old invoice. That is best
   effort: HooshPay only cancels a pending invoice, and a failure is logged (`Superseded invoice not cancelled at the
   gateway.`), never fatal.
3. **Webhook**: HooshPay POSTs `payment.success` to `callback_url` with the header `X-HooshPay-Signature`. Amoobot:
   - rejects it (`401`) unless the signature equals the lowercase hex HMAC-SHA256 of the body, decoded, `ksort`ed and
     re-encoded with `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`, keyed with the **Secret**; rejects it (`400`) when
     the gateway's switch is off. Nothing is looked up or changed; the log line names the reason only;
   - finds the payment **only** by the invoice uid it stored, for that gateway. Unknown invoice: `200` (so HooshPay stops
     retrying) and a log line;
   - never trusts the body: it calls verify (below) and settles only on a matching answer;
   - answers `200` to a repeated webhook without crediting anything twice.
4. **Verify**: `POST {base_url}/api/v1/invoices/{uid}/verify` with the **stored** uid. A payment is paid only when the
   answer has `success: true`, `paid: true`, `status: "paid"`, `data.uid` equal to the stored uid, `data.order_id` equal to
   the `order_id` Amoobot sent and `data.amount` equal to **our** expected amount. When verify's `data` has no `order_id`
   or `amount` (HooshPay's docs elide them), they are read from `GET {base_url}/api/v1/invoices/{uid}` for the same uid.
5. **Return page**: the customer comes back to `/payment/return/{token}`. Like for every gateway, that page runs verify
   and settles through the same idempotent path; otherwise it shows "waiting for confirmation" and refreshes.

**The credit is always our amount.** `payable_amount` differs from `amount` (a few Toman make every invoice unique, and
with `buyer` or `split` the customer also pays the fee). It is shown to the customer and never credited: the order, or
the wallet top-up, is credited with the amount Amoobot asked for.

## Verification results

| HooshPay verify answer | Plugin result | What Amoobot does |
|---|---|---|
| paid, `status: "paid"`, our uid, our `order_id`, our `amount` | paid, amount = our amount, currency `IRT` | Confirms and provisions (or credits the wallet) once |
| paid, but the uid, `order_id`, `amount` or `status` does not match (or `order_id` / `amount` is missing) | not settled (`success: false`) | Nothing changes. The webhook logs `Security: HooshPay webhook for a paid invoice that does not match the payment; not settled.` with the reasons; the evidence is kept in the payment's verify payload (`hooshpay_mismatch`) |
| `paid: false` with `pending`, `expired`, `cancelled` or `failed` | not paid | Payment stays pending |
| `401`, `403`, `404`, `503`, other HTTP errors, timeout, TLS/network error, HTML/non-JSON/empty/oversized body | error (`success: false`) | Nothing decided. The webhook answers `503` so HooshPay retries later |

`failed` is never reported to the core: an invoice can stay payable after Amoobot gave up on it, and rejecting the payment
would lose that money.

## Setup in the HooshPay panel

1. Sign in to HooshPay and open the **Development** section («توسعه») of the app (`/app`). Create or copy the **API key**
   (`hp_live_…`).
2. Copy the **Secret** used to sign webhooks. HooshPay's docs do not name the page that shows it; look in the same
   Development section, or ask HooshPay support. It is not the API key.
3. Nothing needs registering for the webhook: Amoobot sends `callback_url` with every invoice. It is
   `{callback_base_url}/payment/webhook/hooshpay`, e.g. `https://shop.example.com/payment/webhook/hooshpay`. The URL must
   be reachable from the internet over https; a firewall or Cloudflare rule must let HooshPay's POST through.
4. Optionally set your default fee mode in HooshPay; Amoobot sends `fee_mode` with every invoice, so its setting wins.

## Config fields

| Key | Type | Required | Notes |
|---|---|---|---|
| `enabled` | boolean | no (default **off**) | Off: not offered, webhook refused. Invoices issued before switching off can still be verified on the return page. |
| `api_key` | password | yes | `X-API-KEY`. Never shown in the admin (shown as `configured`), in messages, logs or stored responses. |
| `api_secret` | password | yes | Webhook signing Secret. Same handling as the key. Without it every webhook is refused. |
| `base_url` | url | no (default `https://hooshpay.xyz`) | Must be https. `payment_url` must be on this host. |
| `callback_base_url` | url | yes | Public base URL of Amoobot, https only, no user info, query or fragment (the same rule as Tetraminator's). |
| `fee_mode` | choice | no (default `seller`) | See below. |
| `min_amount` | integer | no (default `1000`) | Toman. Below `1000` (HooshPay's minimum) makes the gateway unusable. |
| `max_amount` | integer | no (empty = no limit) | Toman. Below `min_amount` makes the gateway unusable. |
| `description_template` | text | no (default `Order {order}`) | `{order}` = order tracking code, `{payment}` = payment id, `{amount}` = amount in Toman. Cut to 255 characters. |
| `invoice_lifetime_seconds` | integer | no (default `1800`) | Used only when HooshPay sends no usable `expires_at`. 60 to 86400. |

An invalid value does not stop the admin form from saving; it makes the gateway unusable, so it is hidden from customers.
`php bin/console app:payment:debug-methods-for-order {orderId}` shows `gateway_not_usable` for it.

**Secrets at rest.** Like every payment plugin's secrets, `api_key` and `api_secret` are stored in the gateway's config
column and are not encrypted at rest. The admin never renders them back (empty password field, `configured` in the detail
page), and they are masked in logs and stored provider answers.

### Fee modes

| `fee_mode` | Who pays HooshPay's fee | What the customer transfers (`payable_amount`) | What Amoobot credits |
|---|---|---|---|
| `seller` | The shop: it comes out of `merchant_credit` | amount + a few Toman | amount |
| `buyer` | The customer: it is added to the payment | amount + fee + a few Toman | amount |
| `split` | Half each | amount + half the fee + a few Toman | amount |

## Customer-facing messages

- HooshPay disabled or no card available (`503`), timeouts and network errors: «درگاه پرداخت موقتاً در دسترس نیست. لطفاً
  چند دقیقه دیگر دوباره تلاش کنید یا روش دیگری را انتخاب کنید.» in the bot and the Mini App. Nothing is retried.
- Any other create failure: «ایجاد لینک پرداخت انجام نشد. لطفاً روش دیگری را انتخاب کنید.» The detail is in the payment's
  request payload for the admin. `401` and `403` also alert the admins in Telegram (at most hourly per gateway).
- Amount outside the limits: the allowed range, in Toman.

## HTTP behaviour

- TLS verification is always on; redirects are not followed.
- Timeouts: 5 s inactivity (it also bounds connecting) and 15 s in total.
- Response body cap: 64 KiB. Non-JSON bodies are errors and never parsed as success. `401`, `403` and `503` are decided by
  their status alone.
- No retries: create has no idempotency key, so a retry could leave a second invoice.

## Build, validate, install

```bash
docs/plugins/hooshpay-gateway/build.sh /tmp/hooshpay.zip
php bin/console app:plugin:validate-package /tmp/hooshpay.zip
php bin/console app:plugin:install /tmp/hooshpay.zip
php bin/console app:plugin:enable hooshpay
php bin/console app:plugin:doctor hooshpay
```

Then go to Admin → Payment Gateways, add the `HooshPay` module, fill in the config, switch `enabled` on and create a store
payment method that points to it.

Unit tests (mocked HTTP, no network): `php bin/phpunit docs/plugins/hooshpay-gateway/tests`. Integration tests:
`tests/Payment/Notify/HooshPayWebhookTest.php`, `tests/Payment/Plugin/HooshPayCheckoutTest.php`,
`tests/Payment/Plugin/HooshPayPackageTest.php`.

## Test checklist

- [ ] `validate-package` and `doctor` report no errors.
- [ ] Switch off → HooshPay is not offered; a signed webhook is refused.
- [ ] 1,000 Toman payment: HooshPay shows about 1,000 Toman (not 10,000), and the bot shows the exact `payable_amount`.
- [ ] Pay → webhook → order confirmed and provisioned once (or the wallet credited with the amount, not `payable_amount`).
- [ ] Resend the webhook from HooshPay → `200`, no second credit.
- [ ] Return page after paying → paid; before paying → waiting, refreshing.
- [ ] Change the order's amount and pay again → the old invoice is cancelled at HooshPay.
- [ ] The API key, the Secret and the card number appear nowhere in the admin detail page, the payment record or the logs.

## Not in this version

- No reconcile/polling job: a payment whose webhook never arrives and whose customer never returns stays pending until an
  admin checks it. The return page and "check payment" still verify it.

---

## راهنمای فارسی

افزونهٔ درگاه کارت‌به‌کارت **هوش‌پی** (کد `hooshpay`) برای آموبات. مبالغ در آموبات و هوش‌پی هر دو **تومان** است و مبلغ
بدون ضرب در ۱۰ ارسال می‌شود. حداقل مبلغ ۱۰۰۰ تومان است. افزونه **غیرفعال** نصب می‌شود.

### مراحل راه‌اندازی در پنل هوش‌پی

۱. وارد هوش‌پی شوید و از بخش «توسعه» در `/app` **کلید API** را بردارید.
۲. **Secret** امضای وب‌هوک را بردارید (مستندات هوش‌پی محل آن را مشخص نکرده؛ در همان بخش توسعه را ببینید یا از پشتیبانی بپرسید).
۳. ثبت آدرس وب‌هوک لازم نیست: آموبات برای هر فاکتور `callback_url` را می‌فرستد:
   `{callback_base_url}/payment/webhook/hooshpay`. این آدرس باید از اینترنت با https در دسترس باشد.

### تنظیمات

- `enabled`: پیش‌فرض خاموش. خاموش یعنی درگاه نمایش داده نمی‌شود و وب‌هوک رد می‌شود.
- `api_key` و `api_secret` (الزامی، محرمانه): هرگز در پنل مدیریت، پیام‌ها، لاگ‌ها یا پاسخ‌های ذخیره‌شده نمایش داده نمی‌شوند.
- `base_url`: پیش‌فرض `https://hooshpay.xyz`، فقط https.
- `callback_base_url` (الزامی): آدرس عمومی آموبات، فقط https.
- `fee_mode`: `seller` (کارمزد از سهم فروشگاه)، `buyer` (کارمزد به مبلغ مشتری اضافه می‌شود) یا `split` (نصف‌نصف).
- `min_amount` / `max_amount`: بازهٔ مبلغ به تومان؛ حداقل نمی‌تواند کمتر از ۱۰۰۰ باشد.
- `description_template`: قالب توضیح فاکتور با `{order}`، `{payment}` و `{amount}`.
- `invoice_lifetime_seconds`: مهلت پیش‌فرض فاکتور، فقط وقتی هوش‌پی `expires_at` نفرستد.

### قواعد تأیید پرداخت

- وب‌هوک فقط با امضای درست (`X-HooshPay-Signature`) پذیرفته می‌شود و بدنهٔ آن به‌تنهایی چیزی را تأیید نمی‌کند.
- پرداخت فقط وقتی تأیید می‌شود که پاسخ verify هوش‌پی `paid: true`، وضعیت `paid`، همان uid، همان `order_id` و **مبلغ خود ما** را داشته باشد. هر مغایرتی به‌عنوان هشدار امنیتی ثبت می‌شود و پرداخت تأیید نمی‌شود.
- مبلغی که به کیف پول یا سفارش اعمال می‌شود همیشه مبلغ خود ماست، نه `payable_amount` (که رقم یکتا و کارمزد خریدار را دارد). `payable_amount` فقط به مشتری نشان داده می‌شود.
- وب‌هوک تکراری پاسخ ۲۰۰ می‌گیرد و دوباره اعتبار نمی‌دهد. فاکتور ناشناخته پاسخ ۲۰۰ می‌گیرد و کاری انجام نمی‌شود.
- اطلاعات کارت (شماره کارت، نام دارنده) هرگز ذخیره یا لاگ نمی‌شود.
