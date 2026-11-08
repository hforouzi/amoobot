# Tetraminator Payment Gateway Plugin

Installable Tetraminator Toman payment gateway plugin for Amoobot. Plugin code: `tetraminator`.

It follows the strict verification contract in `docs/en/plugins/payment-gateway-plugin-sdk.md` and the design in
[`design.md`](design.md) (this is PR 3 of it). The plugin only creates the invoice and asks Tetraminator whether it was
paid. Amoobot does everything else: locking, amount check, provisioning and notifications.

> **Do not enable it yet.** The notify endpoint (PR 4) and the reconciliation timer (PR 5) are not part of this plugin.
> Without the timer, a payment whose webhook is lost is never seen. Build and validate the ZIP, but keep `enabled` off
> on production until PR 5 is deployed.

## Flow

1. **Create**: `POST https://api.tetraminator.com/v1/invoice/create` with header `X-API-KEY: <API_KEY>` and JSON
   `price` (Toman, as is), `callback_url` and, only when `send_chat_id` is on, `chat_id`.
   - Refused before any call: an amount outside 50,000 to 10,000,000 Toman, or a config that is not usable (below).
   - A row that already holds this gateway's unexpired invoice for the same amount gets its stored link back, with no
     API call (one payment row, one invoice).
   - `callback_url` is `{callback_base_url}/payment/notify/tetraminator/{paymentId}/{token}`. The token is 32 random
     bytes, base64url. Only its SHA-256 hex is stored, in the payment's `authority`.
   - On 201 (or 200) with `status === true`, a valid `pay_id` and a valid link: `pay_id` becomes the transaction id,
     the expected amount is frozen (`IRT`), the payment expires in 3600 s, and the customer gets `url` (else
     `payment_link`, else `gateway_link`). Only `status`, `message`, `pay_id` and `mini_app_link` are stored.
   - Nothing is retried: the API has no idempotency key.
2. **Webhook**: Tetraminator's GET to `callback_url` is unsigned and carries nothing. It is handled by the core's own
   endpoint (PR 4), which only triggers step 3. The plugin's `handleWebhook()` returns `null`, and the generic plugin
   webhook route does not authenticate it.
3. **Verify**: `GET https://api.tetraminator.com/v1/payment/inquiry/{pay_id}` with the **stored** `pay_id`. The request
   payload is never read.

## Verification results

| Inquiry answer | Plugin result | What Amoobot does |
|---|---|---|
| HTTP 200, `status === true`, `payment_status === "paid"`, `pay_id` equals the stored one, `amount` (int or digit string) equals the expected Toman exactly | paid, amount, currency `IRT` | Confirms |
| Says paid (`payment_status` is "paid" in any case) but any rule above fails | paid, **no amount**. Evidence in the payment's verify payload under `tetraminator_review`: reason code(s), reported amount, expected amount, `pay_id_match` yes/no | NEEDS_REVIEW, nothing provisioned |
| `status === true` with any other `payment_status` | `success: true, paid: false`, the value in the message | Stays pending |
| HTTP 200 with `status === false`, or HTTP 404 with a JSON body | `success: true, paid: false` | Stays pending |
| 401, whatever the body | `success: false`, `rawResponse.error` = `unauthorized` | Stays pending |
| 403, whatever the body | `success: false`, `rawResponse.error` = `store_disabled` | Stays pending |
| 400, 429, 5xx, other statuses, timeout, TLS error, HTML, empty, oversized, redirect | `success: false` | Stays pending, retried by the next check |

`failed` is never reported. An invoice stays payable after Amoobot gave up on it, and a rejected payment would ignore
that money.

**Why a mismatch reports no amount.** A higher amount would be confirmed as `overpaid` by the core. With no amount,
`AmountMatcher` answers `missing`, which is never confirmed. The review reason then reads "missing", so the real reason
is in the verify payload. Reason codes: `status_not_true`, `payment_status_not_exact`, `pay_id_missing`,
`pay_id_mismatch`, `amount_missing`, `amount_not_integer`, `amount_mismatch`.

**No rounding, no tolerance.** `50000.0` is a mismatch. `underpayment_tolerance_percent` is not in the config.

## Create failures

The bot and the Mini App show a failed create's message to the customer, and `PaymentStarter` keeps it as the payment's
admin note. So the message is always a short generic Persian text, with no config name, HTTP code or provider wording:

- amount out of range: «پرداخت با این درگاه فقط برای مبلغ ۵۰٬۰۰۰ تا ۱۰٬۰۰۰٬۰۰۰ تومان ممکن است. لطفاً روش دیگری را انتخاب کنید.»
- anything else: «درگاه پرداخت در حال حاضر در دسترس نیست. لطفاً روش دیگری را انتخاب کنید.»

The admin's detail is in `rawResponse`, stored as the payment's request payload:

| Case | `rawResponse` |
|---|---|
| Amount out of range | `error` `amount_out_of_range`, `amount` |
| Switched off or not configured | `error` `not_usable`, `detail` |
| Payment not saved yet | `error` `payment_not_saved` |
| 401, whatever the body | `error` `unauthorized`, `http_status`, `detail` |
| 403, whatever the body | `error` `store_disabled`, `http_status`, `detail` |
| 400, 404, 429, 5xx | `error` `http_n`, `http_status`, masked provider `message` |
| `status` not strictly `true` | `error` `status_not_true`, `http_status`, masked provider `message` |
| No valid `pay_id` or link | `error` `invalid_pay_id` or `invalid_link`, `http_status` |
| Timeout, TLS, HTML, empty, oversized, non-JSON | `error` `transport`, `detail` (fixed text, no URL) |

401 and 403 are recognised by the status code alone, on create and on inquiry: the body is not read, so an empty, HTML or
oversized body still gives `unauthorized` or `store_disabled`. On those two codes at create, the core alerts the admins in
Telegram, at most once an hour per gateway (`PaymentGatewayCredentialAlert`). The alert has a fixed text and never the key.

`verifyPayment()` messages are for admins and logs (English, with the detail). No customer path shows them for this gateway:
the bot's check button answers with its own fixed texts, and the core return page `/payment/return/{token}`, which would show
one, is never linked for Tetraminator (it has no return URL).

## Config fields

| Key | Type | Required | Notes |
|---|---|---|---|
| `enabled` | boolean | no (default `false`) | Gates new invoices only. Verification keeps working while it is off, for invoices already issued |
| `api_key` | password | yes | Sent as `X-API-KEY`. Never shown in messages, logs or stored responses |
| `callback_base_url` | url | yes | Public `https` base URL of Amoobot, e.g. `https://shop.example.com`. A host, no user info, no query |
| `send_chat_id` | boolean | no (default `false`) | Sends the customer's Telegram id to Tetraminator. The shop owner's decision: it hands a third party the customer's id, and our own bot already reports the result |

The method is offered (`isUsable()`) only when `enabled` is on, `api_key` is not empty and `callback_base_url` is valid.
Missing, empty or non-boolean values count as off. To stop inquiries too, remove the key.

## Amounts

Amoobot and Tetraminator both use **Toman**. The payable amount is sent as is. The store hides the method for amounts
outside 50,000 to 10,000,000 Toman (`acceptsAmount()`), and `createPayment()` refuses them without an API call.

## HTTP behaviour

- TLS verification always on. Redirects are not followed.
- Timeouts: 5 s inactivity (it also bounds connecting) and 15 s in total.
- Response body cap 64 KiB. Only a JSON object is parsed. `Accept: application/json` is always sent.
- No retry, on create or on inquiry.

## Secrets

- The exact key, the callback token and the callback URL are masked in every message and stored response.
- The transport's own error text is not passed on (it names the URL, and so the `pay_id`).
- The plugin logs nothing. Amoobot's logs carry the payment id only. `SecretRedactor::redact()` also masks
  `X-API-KEY` and `api_key` patterns, as a backstop.
- Docs use `<API_KEY>`. Tests use an obviously fake key.

## Pending provider answers

Tetraminator has not answered the open questions in `design.md` section 12. Until it does, the plugin implements these
assumptions. Each one is safe: a wrong guess leaves a payment pending or sends it to review, never confirms it wrongly.

1. **Paid answer** (questions 1, 4): only `status === true` (boolean), `payment_status === "paid"` (exact case), the
   returned `pay_id` equal to the stored one, and `amount` an int or a digit string equal to the expected Toman exactly.
2. **Amount unit and type** (question 4): the inquiry `amount` is Toman, an int or a digit string. If it is Rial or a
   decimal, every payment goes to review.
3. **Paid claim failing a rule** (questions 1, 4): paid with no amount, evidence in the verify payload.
4. **Other `payment_status` values** (question 1): any value but "paid" is not paid. Its value goes into the message,
   only when it is a short plain word.
5. **Unpaid or unknown invoice** (question 2): HTTP 200 with `status: false`, and HTTP 404 with a JSON body, mean not
   paid. A 404 without JSON is an error.
6. **401 and 403** (question 2): the key is invalid and the store is disabled, at create and at inquiry, whatever the body.
7. **Everything else** (questions 2, 10): 400, 429, 5xx, timeout, TLS, HTML, empty, oversized and redirect answers
   decide nothing.
8. **`failed` is never true** (question 9): an invoice may be payable after 60 minutes.
9. **`pay_id`** (question 7): an int or a string, matching `^[A-Za-z0-9_-]{1,128}$`, stored as a string.
10. **Links** (question 6): `https`, no user info, no port. Hosts are not pinned.
11. **Create success**: HTTP 201, and 200 is accepted too.
12. **`chat_id`**: sent as an integer when it is a positive Telegram user id.

## Build, validate, install

```bash
docs/plugins/tetraminator-gateway/build.sh /tmp/tetraminator.zip
php bin/console app:plugin:validate-package /tmp/tetraminator.zip
php bin/console app:plugin:install /tmp/tetraminator.zip
php bin/console app:plugin:enable tetraminator
php bin/console app:plugin:doctor tetraminator
```

Then add the module in Admin > Payment Gateways, fill in the config, and create a store payment method that points to
it. Keep `enabled` off until PR 5 is deployed (see the note at the top).

Unit tests (mocked HTTP, no network): `php bin/phpunit docs/plugins/tetraminator-gateway/tests`.

## Setup in the Tetraminator panel

1. Create the API key and enter it as `api_key`. It appears only in Admin > Payment Gateways.
2. Set the store's gateway priority. It decides what `url` opens.
3. Make sure the store is enabled (otherwise `403`).

The real-payment checklist is in `design.md` section 15.

---

## راهنمای فارسی

افزونهٔ درگاه پرداخت **تترامیناتور** (کد `tetraminator`) برای آموبات. مبلغ در هر دو سامانه **تومان** است و بدون تبدیل ارسال می‌شود. فقط مبلغ‌های ۵۰٬۰۰۰ تا ۱۰٬۰۰۰٬۰۰۰ تومان پذیرفته می‌شوند.

- تا وقتی endpoint اعلان (PR 4) و تایمر استعلام (PR 5) مستقر نشده‌اند، گزینهٔ `enabled` را روشن نکنید.
- پرداخت فقط وقتی تأیید می‌شود که استعلام `status === true` و `payment_status === "paid"` بدهد، `pay_id` با مقدار ذخیره‌شده برابر باشد و مبلغ دقیقاً برابر مبلغ مورد انتظار باشد. هر مغایرتی به بررسی دستی می‌رود و دلیل آن در verify payload ذخیره می‌شود.
- کلید API (`api_key`) و توکن آدرس بازگشت هرگز در پیام‌ها، لاگ‌ها یا پاسخ ذخیره‌شده نمی‌آید. در مستندات به‌جای کلید `<API_KEY>` بنویسید.
- خطای 401 (کلید نامعتبر) یا 403 (فروشگاه غیرفعال) هنگام ساخت فاکتور، حداکثر ساعتی یک بار برای هر درگاه به مدیران اطلاع داده می‌شود.
- ارسال `chat_id` به‌طور پیش‌فرض خاموش است.
- فرض‌های بخش «Pending provider answers» تا پاسخ تترامیناتور برقرارند.
