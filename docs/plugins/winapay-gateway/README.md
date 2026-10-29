# Winapay Payment Gateway Plugin

Installable Winapay (Zarinpal-style) Toman payment gateway plugin for Amoobot. Plugin code: `winapay`.

It follows the strict verification contract in `docs/en/plugins/payment-gateway-plugin-sdk.md`. The plugin
only creates the payment and asks Winapay whether it was paid. Amoobot does everything else: locking,
amount check, provisioning and notifications.

## Flow

1. **Create**: `POST https://winapay.io/webservice/rest/PaymentRequest` with `MerchantID`, `Amount` (Toman),
   `CallbackURL`, `Description`, `InvoiceID` (the payment tracking code) and `Mobile` (only when the
   customer's number is a valid Iranian mobile; sent as `09xxxxxxxxx`). `Email` and `AllowCard` are never sent
   because Amoobot has no reliable source for them.
   The returned `Authority` is stored as the payment's `authority` and `transactionId`. The customer goes to
   `PaymentUrl` when it is an `https://winapay.io` URL, otherwise to `https://winapay.io/startPay/{Authority}`.
2. **Callback**: Winapay POSTs `Authority` and `PaymentStatus` (`OK`/`NOK`) to
   `{callback_base_url}/payment/callback/plugin/winapay?rt={return token}`. The callback is **unsigned**:
   Amoobot only uses `Authority` to find the payment by its stored reference. `PaymentStatus=OK` never marks
   anything paid. With `NOK` the plugin does not call Winapay; the payment stays pending (never failed, since the
   callback is unauthenticated) and the customer is sent to the return page. Like for every gateway, that core
   page asks `verifyPayment()` again while the payment is pending (on load and every refresh), which Winapay
   answers with `-15` for an unpaid payment.
3. **Verify**: `POST https://winapay.io/webservice/rest/PaymentVerification` with `MerchantID`, the **stored**
   `Authority` (never one from the request) and the **expected** amount in Toman.

## Verification results

| Winapay answer | Plugin result | What Amoobot does |
|---|---|---|
| `100` with `RefID` and a numeric `Amount` | paid, amount = Winapay's `Amount`, currency `IRT` | Confirms when the amount matches. Lower → review (or tolerance), higher → confirmed and flagged |
| `100` without `RefID` or without a valid `Amount` | paid, **no amount** | NEEDS_REVIEW (the amount is never taken from our own record) |
| `101` already verified | paid, **no amount** | NEEDS_REVIEW if not settled yet (see below) |
| `-12 -14 -15 -16 -17 -20 -21` | not paid (`success: true, paid: false`) | Payment stays pending until it expires |
| `102`, other negatives, unknown code, no status | error (`success: false`) | Payment stays pending; verification is retried by the next callback, return page refresh or "check payment" |
| timeout, TLS/network error, HTML/non-JSON/empty/oversized body, redirect | error (`success: false`) | Same as above |

**Why `101` goes to review.** `101` means "verified earlier". A payment Amoobot already settled is never
verified again, so a `101` usually means an earlier verify succeeded at Winapay but Amoobot could not record it
(crash, timeout after Winapay answered, two checks racing). The plugin does not invent an amount for it. It
reports "paid without amount" and the core parks the payment in NEEDS_REVIEW, where an admin compares it with
the Winapay panel (RefID is kept when Winapay sends one).

`-18` (paid but Winapay could not update the merchant wallet) is treated as a retryable error: the money was
taken, so check the Winapay panel if a payment stays pending with this message.

## Setup in the Winapay panel

1. Get your **MerchantID** from the Winapay panel.
2. **Register the callback domain**: it must be the host of `callback_base_url` (e.g. `shop.example.com`).
   Otherwise Winapay answers `-10`.
3. **Whitelist the server IP**: add the public outgoing IP of the server that runs Amoobot. Otherwise Winapay
   answers `-9`. Behind NAT or a proxy, use the address Winapay sees.
4. Make sure the account is an active business account (`-7`, `-8`).

## Config fields

| Key | Type | Required | Notes |
|---|---|---|---|
| `merchant_id` | password | yes | Winapay MerchantID. Never shown in messages, logs or stored responses. |
| `callback_base_url` | url | yes | Public base URL of Amoobot, e.g. `https://shop.example.com`. Its domain must be registered in the Winapay panel. |
| `request_format` | choice | no (default `form`) | `form` sends `application/x-www-form-urlencoded` (every value URL-encoded). `json` sends `application/json`. Switch when Winapay rejects one format. |
| `description_prefix` | text | no | Payment description prefix; the tracking code is appended (default prefix `Order`). |
| `underpayment_tolerance_percent` | number | no (default `0`) | Accepted shortfall, applied by the core. Keep `0` for Toman payments. |

Amounts: Amoobot and Winapay both use **Toman**. The payable amount is sent as is (no ×10) and the minimum is
100 Toman (smaller payments are refused before calling Winapay).

## HTTP behaviour

- TLS verification is always on. The provider sample's `CURLOPT_SSL_VERIFYPEER=false` is deliberately not used.
- Timeouts: 5 s inactivity (Symfony HttpClient has no separate connect timeout, so this one also bounds
  connecting) and 30 s in total.
- Redirects are not followed; a 3xx response counts as an error.
- Response body cap: 64 KiB. Non-JSON bodies are reported as errors and never parsed as success.
- `Accept: application/json` is always sent.

## Error codes / کدهای خطا

| Code | English | فارسی |
|---|---|---|
| 100 | Request accepted / payment verified | عملیات موفق |
| 101 | Payment was already verified earlier (sent to review) | پرداخت قبلاً تأیید شده است (ارجاع به بررسی دستی) |
| 102 | Winapay could not connect to its web service; try again later | اتصال به وب‌سرویس برقرار نشد؛ بعداً تلاش کنید |
| -1 | The request must be sent through the web service | درخواست باید از طریق وب‌سرویس ارسال شود |
| -2 | MerchantID is missing | MerchantID ارسال نشده است |
| -3 | Amount is missing | مبلغ ارسال نشده است |
| -4 | CallbackURL is missing | آدرس بازگشت ارسال نشده است |
| -5 | Amount is below the 100 Toman minimum | مبلغ کمتر از حداقل ۱۰۰ تومان است |
| -6 | MerchantID was not found | MerchantID یافت نشد |
| -7 | The merchant is inactive | پذیرنده غیرفعال است |
| -8 | The merchant account is not a business account | حساب پذیرنده از نوع تجاری نیست |
| -9 | This server's IP address is not allowed | آی‌پی سرور مجاز نیست (در پنل وینا پی ثبت کنید) |
| -10 | The callback address does not match the registered domain | آدرس بازگشت با دامنه ثبت‌شده در درگاه مطابقت ندارد |
| -11 | Transaction creation failed | ایجاد تراکنش ناموفق بود |
| -12 | Invalid Authority | Authority نامعتبر است |
| -13 | Authority is missing | Authority ارسال نشده است |
| -14 | Transaction not found | تراکنش یافت نشد |
| -15 | The payment was not completed | پرداخت انجام نشده است |
| -16 | The amount does not match the transaction amount | مبلغ با مبلغ تراکنش مطابقت ندارد |
| -17 | Authority is not registered for this merchant | Authority متعلق به این پذیرنده نیست |
| -18 | Paid, but the merchant wallet update failed | پرداخت انجام شد اما به‌روزرسانی کیف پول پذیرنده ناموفق بود |
| -19 | Transaction status is unknown | وضعیت تراکنش نامشخص است |
| -20 | Invalid card number | شماره کارت نامعتبر است |
| -21 | The payer card differs from the allowed card | کارت پرداخت‌کننده با کارت مجاز متفاوت است |

## Build, validate, install

```bash
docs/plugins/winapay-gateway/build.sh /tmp/winapay.zip
php bin/console app:plugin:validate-package /tmp/winapay.zip
php bin/console app:plugin:install /tmp/winapay.zip
php bin/console app:plugin:enable winapay
php bin/console app:plugin:doctor winapay
php bin/console app:payment:list-modules
```

Then go to Admin → Payment Gateways, add the `Winapay` module, fill in the config and create a store payment
method that points to it. `php bin/console app:payment:test-plugin-gateway {gatewayId}` checks class loading
without creating a payment.

Unit tests (mocked HTTP, no network): `php bin/phpunit docs/plugins/winapay-gateway/tests`.

## Test checklist

- [ ] `validate-package` and `doctor` report no errors.
- [ ] Wrong MerchantID → the admin sees `-6`/`-2`, and the MerchantID appears nowhere (message, payment record, logs).
- [ ] Callback domain registered, server IP whitelisted (no `-9`/`-10`).
- [ ] 1,000 Toman payment: Winapay page shows 1,000 Toman (not 10,000).
- [ ] Successful payment → callback → order confirmed and provisioned once; RefID recorded.
- [ ] Cancel on the Winapay page (`NOK`) → return page, payment stays pending, nothing provisioned.
- [ ] Reload the return page / press "check payment" after success → no second provisioning.
- [ ] If `form` is rejected, switch `request_format` to `json` and retry.

---

## راهنمای فارسی

افزونهٔ درگاه پرداخت **وینا پی** (کد `winapay`) برای آموبات. مبالغ در آموبات و وینا پی هر دو **تومان** است و مبلغ بدون ضرب در ۱۰ ارسال می‌شود. حداقل مبلغ ۱۰۰ تومان است.

### مراحل راه‌اندازی در پنل وینا پی

۱. **MerchantID** را از پنل وینا پی بردارید.
۲. **دامنهٔ Callback** را ثبت کنید. این دامنه باید همان دامنهٔ `callback_base_url` باشد، وگرنه خطای `-10` می‌گیرید.
۳. **آی‌پی خروجی سرور** آموبات را در پنل مجاز کنید، وگرنه خطای `-9` می‌گیرید.
۴. حساب باید تجاری و فعال باشد (خطاهای `-7` و `-8`).

### تنظیمات

- `merchant_id` (الزامی، محرمانه): شناسهٔ پذیرنده. هرگز در پیام‌ها، لاگ‌ها یا پاسخ ذخیره‌شده نمایش داده نمی‌شود.
- `callback_base_url` (الزامی): آدرس عمومی آموبات، مثلاً `https://shop.example.com`. دامنهٔ آن باید در پنل وینا پی ثبت شده باشد. آدرس بازگشت به این شکل ساخته می‌شود: `{callback_base_url}/payment/callback/plugin/winapay?rt=...`
- `request_format`: مقدار `form` (پیش‌فرض) یا `json`. اگر وینا پی یکی را نپذیرفت، دیگری را امتحان کنید.
- `description_prefix`: پیشوند توضیحات پرداخت. کد پیگیری به آن اضافه می‌شود.
- `underpayment_tolerance_percent`: درصد مجاز کم‌پرداخت (پیش‌فرض ۰).

### قواعد تأیید پرداخت

- پرداخت فقط وقتی «پرداخت‌شده» است که پاسخ PaymentVerification وضعیت `100` و `RefID` و `Amount` عددی داشته باشد. مبلغ گزارش‌شده همان مبلغ وینا پی است، نه مبلغ سفارش.
- Callback امضا ندارد. `PaymentStatus=OK` به‌تنهایی چیزی را تأیید نمی‌کند. در حالت `NOK` تأییدیه درخواست نمی‌شود و پرداخت در انتظار می‌ماند.
- تأیید همیشه با Authority ذخیره‌شده انجام می‌شود، نه Authority موجود در درخواست.
- کد `101` (قبلاً تأیید شده) و پاسخ `100` بدون مبلغ یا RefID معتبر **به بررسی دستی** ارجاع می‌شود.
- کدهای `-15` و مشابه یعنی پرداخت انجام نشده است. خطاهای شبکه، تنظیمات و سرویس قابل تکرارند و پرداخت در انتظار می‌ماند.
