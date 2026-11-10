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
     the expected amount is frozen (`IRT`), the payment expires in 3600 s, and the customer gets the link chosen by
     `prefer_website_link` (see [Which link the customer gets](#which-link-the-customer-gets)). Only `status`, `message`,
     `pay_id` and, when `use_mini_app_link` is on, `mini_app_link` are stored.
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
| HTTP 200 with `status === false` (`pending`, `expired`, `refused`), or HTTP 404 with a JSON body | `success: true, paid: false` | Stays pending |
| A `payment_status` outside `pending`, `paid`, `expired`, `refused` | as the row it falls in (not paid); a warning is logged with the payment id and the value (masked, at most 32 characters) | Stays pending |
| HTTP 404 with an empty or non-JSON body, after the payment's `expiresAt` | `success: true, paid: false`, `rawResponse.error` = `not_found_after_expiry`; a warning is logged (payment id only) | Stays pending; the timer expires it |
| HTTP 404 with an empty or non-JSON body, before `expiresAt` | `success: false`; a warning is logged (payment id only) | Stays pending, retried by the next check (a misrouted 404 never expires a live invoice) |
| 401, whatever the body | `success: false`, `rawResponse.error` = `unauthorized` | Stays pending |
| 403, whatever the body | `success: false`, `rawResponse.error` = `store_disabled` | Stays pending |
| 400, 429, 5xx, other statuses, timeout, TLS error, HTML, empty (other than a 404), oversized, redirect | `success: false` | Stays pending, retried by the next check |

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
Telegram, at most once an hour per gateway (`PaymentGatewayCredentialAlert`). The alert has a fixed text and never the key:
401 reads "API key missing or rejected", 403 reads "API key invalid or store disabled" (the provider uses 403 for both).

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
| `prefer_website_link` | boolean | no (default `true`) | On: the customer gets `gateway_link`, the direct Shaparak page (no card-to-card), when present and valid, else `url`, else `payment_link`. Off: `url` (the provider's web page that offers Shaparak and/or card-to-card as the store is set up), then `payment_link`, then `gateway_link`. New invoices only |
| `use_mini_app_link` | boolean | no (default `false`) | Only when on is `mini_app_link` stored, so our Mini App opens the provider's Telegram mini app with `openTelegramLink`. Off: not stored, and our Mini App opens the payment link with `openLink`, as for other gateways. New invoices only |

The method is offered (`isUsable()`) only when `enabled` is on, `api_key` is not empty and `callback_base_url` is valid.
Missing, empty or non-boolean values count as off. To stop inquiries too, remove the key.

### Which link the customer gets

| `prefer_website_link` | `use_mini_app_link` | Bot and our Mini App open | Our Mini App opens with |
|---|---|---|---|
| on (default) | off (default) | `gateway_link` (direct Shaparak), else `url`, else `payment_link` | `openLink` (the Shaparak page, inside Telegram's browser) |
| off | off | `url` (the provider's web chooser), else `payment_link`, else `gateway_link` | `openLink` |
| any | on | as above in the bot | `openTelegramLink` with `mini_app_link` when the provider sent a valid `t.me` link, else `openLink` with the payment link |

- What each link is (provider docs): `url` is a web page where the buyer chooses Shaparak or card-to-card when the store
  has both on; it equals `payment_link` when Shaparak is off, and `gateway_link` when only card-to-card is off.
  `payment_link` is the invoice in the provider's bot (card-to-card), `mini_app_link` the invoice in the provider's
  Telegram mini app (card-to-card), `gateway_link` the direct Shaparak page. All four are always returned.
- Every link is validated the same way (`https`, a host, no user info, no port). An invalid `gateway_link` is skipped and
  the next link is used. A create answer with no valid link at all is a failed create.
- A config saved before plugin 1.1.0 has neither key and gets the defaults: `prefer_website_link` is on unless it is
  exactly `false`, and `use_mini_app_link` is off unless it is exactly `true`.
- **A settings change applies to new invoices only.** A live invoice handed out again from the payment row keeps the link
  chosen when it was created, with no API call.

## Amounts

Amoobot and Tetraminator both use **Toman**. The payable amount is sent as is. The store hides the method for amounts
outside 50,000 to 10,000,000 Toman (`acceptsAmount()`), and `createPayment()` refuses them without an API call. That is the
provider's default store range; a store with its own lower cap gets a 400 at create for amounts above it (a failed create,
`http_400`). The inquiry `amount` is the invoice's original amount in Toman, before the gateway fee, so it must equal the
price exactly.

## HTTP behaviour

- TLS verification always on. Redirects are not followed.
- Timeouts: 5 s inactivity (it also bounds connecting) and 15 s in total.
- Response body cap 64 KiB. Only a JSON object is parsed. `Accept: application/json` is always sent.
- No retry, on create or on inquiry.

## Secrets

- The exact key, the callback token and the callback URL are masked in every message and stored response.
- The transport's own error text is not passed on (it names the URL, and so the `pay_id`).
- The plugin logs only two warnings, through `SafeErrorLog`: an inquiry 404 without a JSON body (payment id only) and an
  undocumented `payment_status` (payment id and the value, masked, at most 32 characters). Amoobot's logs carry the
  payment id only. `SecretRedactor::redact()` also masks
  `X-API-KEY` and `api_key` patterns, as a backstop.
- Docs use `<API_KEY>`. Tests use an obviously fake key.

## Provider answers

Tetraminator answered most open questions of `design.md` section 12 in its new docs. What the plugin does with each:

1. **`payment_status`** is one of `pending`, `paid`, `expired`, `refused`. The inquiry `status` is `true` only when paid,
   `false` otherwise. Paid needs `status === true`, `payment_status === "paid"` (exact case), the returned `pay_id` equal to
   the stored one, and the exact amount. A paid claim failing a rule (for example `status: false` with `payment_status:
   "paid"`) goes to review with the evidence in the verify payload. Any other value is not paid; an undocumented one is
   also logged as a warning.
2. **`amount`** is an integer in Toman, the invoice's original amount before the gateway fee: an exact match with the
   price is correct. No rounding, no tolerance.
3. **Webhook**: sent by the provider's server (not the buyer's browser), a plain GET with no added parameters, retried at
   least once if the first attempt fails. It only triggers an inquiry; the timer stays the safety net.
4. **Create errors**: 400 for a missing `price` or `callback_url`, an unsafe URL, a non-numeric `chat_id`, or an amount
   outside the store's range (default 50,000 to 10,000,000; a store may have its own cap). 401 when `X-API-KEY` is
   missing; 403 for an invalid key or a disabled store. 401 and 403 are recognised by status alone, whatever the body.
5. **Inquiry errors**: 403 for an invalid key, a disabled store or an invoice of another store; 404 when there is no
   invoice with that `pay_id`. A 404 with a JSON body is not paid. A 404 without one is not paid only after the payment's
   `expiresAt` (the provider may delete an expired invoice); before that it is an error, so a misrouted 404 never expires
   a live invoice.
6. **Links**: all four are always returned; see [Which link the customer gets](#which-link-the-customer-gets).
   Hosts are not pinned (`https`, no user info, no port).
7. **Unchanged assumptions**: `failed` is never reported; 400, 429, 5xx, timeout, TLS, HTML, empty, oversized and
   redirect answers decide nothing; `pay_id` is an int or a string matching `^[A-Za-z0-9_-]{1,128}$`; create success is
   HTTP 201, and 200 is accepted too; `chat_id` is sent as an integer when it is a positive Telegram user id.

**Still open:**

- Payments confirmed after the 60-minute window (for example a late card-to-card confirmation). The plugin never
  reports `failed`, and a late paid answer goes to review.
- The webhook's source IP addresses, for allowlisting. The per-payment token stays required either way.

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

### Upgrading an installed plugin (for example 1.1.0 to 1.1.1)

Upgrade in place with `app:plugin:upgrade`. The full procedure is in
[Upgrading a plugin](../../en/plugins/payment-gateway-plugin-sdk.md#upgrading-a-plugin) (Persian:
[ارتقای پلاگین](../../fa/plugins/payment-gateway-plugin-sdk.md)). On the server:

```bash
cd /var/www/amoobot
sudo -u www-data sh docs/plugins/tetraminator-gateway/build.sh /tmp/tetraminator-1.1.1.zip
sudo -u www-data php bin/console app:plugin:upgrade /tmp/tetraminator-1.1.1.zip --dry-run --env=prod
sudo -u www-data php bin/console app:plugin:upgrade /tmp/tetraminator-1.1.1.zip --env=prod
sudo systemctl reload php8.4-fpm
sudo -u www-data php bin/console app:plugin:doctor tetraminator --env=prod
```

1.0.0 to 1.1.0 adds no permission and no required field. The dry run lists the two new config fields,
`prefer_website_link` and `use_mini_app_link`. The plugin keeps its status, and the gateway config is not touched, so
the gateway runs on the defaults until you change them in Admin > Payment Gateways.

1.1.0 to 1.1.1 adds no permission and no config field (only the `prefer_website_link` label changes). It changes the
inquiry 404 handling and adds two log warnings; see [Provider answers](#provider-answers).

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
- خطای 401 (کلید API ارسال نشده یا رد شده) یا 403 (کلید API نامعتبر یا فروشگاه غیرفعال) هنگام ساخت فاکتور، حداکثر ساعتی یک بار برای هر درگاه به مدیران اطلاع داده می‌شود.
- ارسال `chat_id` به‌طور پیش‌فرض خاموش است.
- به‌طور پیش‌فرض مشتری صفحهٔ مستقیم شاپرک (`gateway_link`، بدون کارت‌به‌کارت) را باز می‌کند، در ربات و در مینی‌اپ آموبات (`prefer_website_link` روشن، `use_mini_app_link` خاموش). با خاموش کردن `prefer_website_link` مشتری صفحهٔ وب انتخاب روش (`url`) را طبق تنظیمات فروشگاه می‌بیند. تغییر این تنظیمات فقط روی فاکتورهای جدید اثر دارد.
- ارتقای افزونهٔ نصب‌شده (مثلاً از 1.1.0 به 1.1.1) با `app:plugin:upgrade` انجام می‌شود؛ دستورها در بخش «Upgrading an installed plugin» و در [ارتقای پلاگین](../../fa/plugins/payment-gateway-plugin-sdk.md) آمده‌اند. پس از ارتقا PHP-FPM را reload کنید.
- پاسخ‌های تترامیناتور در بخش «Provider answers» آمده‌اند. هنوز باز: پرداخت‌های تأییدشده پس از ۶۰ دقیقه و IPهای ارسال وب‌هوک.
