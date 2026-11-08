# SDK پلاگین درگاه پرداخت

پلاگین پرداخت باید interface اصلی پروژه را پیاده‌سازی کند:

```php
App\Payment\Plugin\PaymentGatewayPluginInterface
```

داخل پلاگین نباید interface جداگانه یا کپی‌شده تعریف شود.

## متدهای required

- `getType()`
- `createPayment(Payment $payment, Order $order, array $config): PaymentRequestResult`
- `verifyPayment(Payment $payment, array $payload, array $config): PaymentVerificationResult`
- `supportsWebhook(): bool`
- `handleWebhook(array $payload, Request $request, array $config): ?PaymentWebhookResult`

## DTOها

- `PaymentRequestResult`: نتیجه ساخت پرداخت و اطلاعات لینک یا provider.
- `PaymentVerificationResult`: نتیجه verify، اطلاعات مرجع provider و `amount` + `currency` تأییدشده (برای تأیید خودکار الزامی).
- `PaymentWebhookResult`: نتیجه Webhook وقتی پلاگین Webhook پشتیبانی کند.

**خطای حساب هنگام ساخت پرداخت.** اگر درگاه به‌خاطر حساب خود فروشگاه فاکتور نسازد، یک `PaymentRequestResult` ناموفق با `rawResponse['error']` برابر `unauthorized` (کلید نامعتبر) یا `store_disabled` (فروشگاه نزد درگاه غیرفعال است) برگردانید. `PaymentStarter` حداکثر ساعتی یک بار برای هر درگاه در تلگرام به مدیران خبر می‌دهد (`PaymentGatewayCredentialAlert`). متن این هشدار ثابت است و چیزی از پاسخ درگاه، مثلاً کلید، در آن نمی‌آید.

**لینک تلگرام برای مینی‌اپ.** `rawResponse` موفق به‌عنوان request payload پرداخت ذخیره می‌شود. اگر در آن `mini_app_link` یک لینک `https://t.me/...` باشد، صفحه پرداخت مینی‌اپ آن را با `openTelegramLink` تلگرام باز می‌کند تا مشتری در تلگرام بماند؛ ربات همچنان `paymentUrl` را نشان می‌دهد. صفحه لینک را دوباره بررسی می‌کند (`App\MiniApp\Checkout\PaymentTelegramLink`): میزبان دقیقاً `t.me`، بدون user info، پورت یا fragment، مسیر ساده و حداکثر ۵۱۲ نویسه. در غیر این صورت نادیده گرفته می‌شود و صفحه مثل هر درگاه دیگری `paymentUrl` را با `openLink` باز می‌کند.

## configSchema

هر فیلد می‌تواند `key` یا `name`، `type`، `required`، `default`، `label` و `choices` داشته باشد.

فرمت choices باید به شکل label به مقدار scalar باشد:

```json
{
  "Live": "live",
  "Sandbox": "sandbox"
}
```

برای secretهای required مقدار fake default نگذارید.

## Autoload

Runtime مقدار `mainClass` را از `plugin.json` می‌خواند و namespace prefix را با حذف نام کوتاه کلاس به دست می‌آورد.

```text
mainClass: Amoobot\Plugin\SwapWallet\SwapWalletGatewayPlugin
prefix:    Amoobot\Plugin\SwapWallet\
srcDir:    var/plugins/swapwallet/src/
```

## دستورها

```bash
php bin/console app:plugin:validate-package /path/to/plugin.zip
php bin/console app:plugin:doctor plugin_code
php bin/console app:payment:test-plugin-gateway GATEWAY_ID
```

## ارتقای پلاگین (Upgrading a plugin)

پلاگینِ نصب‌شده درجا و فقط از CLI ارتقا داده می‌شود (آپلود وب ندارد):
`app:plugin:upgrade <zip> [--dry-run] [--accept-new-permissions]`. `app:plugin:install` همچنان کدِ نصب‌شده را رد
می‌کند و حذف (uninstall) وجود ندارد.

روی سرور (پیش‌فرض‌های deploy: `/var/www/amoobot` و PHP 8.4؛ در نام سرویس FPM مقدار `PHP_VERSION` خودتان را بگذارید).
کنسول را مثل نصب با کاربر `www-data` اجرا کنید تا فایل‌ها مال همان کاربر باشند:

```bash
cd /var/www/amoobot
# ۱. ساخت ZIP جدید (مثال: تترامیناتور؛ هر پلاگین در docs/plugins یک build.sh دارد)
sudo -u www-data sh docs/plugins/tetraminator-gateway/build.sh /tmp/tetraminator.zip
# ۲. بررسی کامل و چاپ برنامه؛ هیچ چیز تغییر نمی‌کند
sudo -u www-data php bin/console app:plugin:upgrade /tmp/tetraminator.zip --dry-run --env=prod
# ۳. ارتقا (--accept-new-permissions فقط وقتی برنامه مجوز تازه‌ای نشان داد و آن را می‌پذیرید)
sudo -u www-data php bin/console app:plugin:upgrade /tmp/tetraminator.zip --env=prod
# ۴. بارگذاری دوبارهٔ PHP-FPM: تا آن موقع ممکن است کلاس‌های قدیمی پلاگین در OPcache بمانند
sudo systemctl reload php8.4-fpm
# ۵. بررسی پلاگین
sudo -u www-data php bin/console app:plugin:doctor tetraminator --env=prod
```

دستور چه چیزهایی را بررسی می‌کند و چه می‌کند:

- ZIP همان بررسی‌های نصب را می‌گذراند (مسیر امن، پوشه‌های مجاز، manifest و `configSchema`).
- `code` آن باید نصب شده باشد، `type` همان باشد و `version` به‌طور اکید بزرگ‌تر باشد
  ([نسخه‌بندی معنایی](https://semver.org)؛ مثلاً `1.1.0` بعد از `1.0.0`؛ نسخهٔ برابر یا کمتر رد می‌شود). `mainClass`
  فقط وقتی می‌تواند عوض شود که کلاس جدید بارگذاری شود.
- **مجوزها:** مجوز تازه بدون `--accept-new-permissions` رد می‌شود و فهرستش چاپ می‌شود. حذف مجوز اشکالی ندارد.
- **تنظیمات:** تنظیمات هر درگاه پرداختِ این پلاگین با schema جدید سنجیده می‌شود. کلیدهایی که schema دیگر ندارد نگه
  داشته و گزارش می‌شوند. فیلدی که تازه اجباری شده، پیش‌فرض ندارد و در تنظیمات یک درگاه نیست، ارتقا را رد می‌کند: اول آن
  را پر کنید. فقط شناسهٔ درگاه و نام فیلد چاپ می‌شود، هرگز مقدار.
- **اول doctor:** فایل‌های جدید پیش از دست زدن به نسخهٔ فعال در یک پوشهٔ موقت بررسی می‌شوند (کلاس اصلی بارگذاری شود
  و قرارداد را پیاده کند). `--dry-run` همین‌جا می‌ایستد و برنامه را چاپ می‌کند: نسخهٔ قدیم و جدید، مجوزها و فیلدهای
  اضافه و حذف‌شده و درگاه‌هایی که از پلاگین استفاده می‌کنند.
- **جابه‌جایی:** زیر همان قفلی انجام می‌شود که نصب هم می‌گیرد، پس دو ارتقا یا ارتقا و نصب هم‌زمان نمی‌شوند.
  `var/plugins/<code>` به `var/plugins/.upgrade-backup/<code>-<نسخهٔ قدیم>-<زمان>` منتقل می‌شود، فایل‌های جدید جای آن
  را می‌گیرند و ردیف پلاگین نسخه، نام، توضیح، کلاس اصلی، manifest و مجوزهای جدید را می‌گیرد. بعد doctor دوباره اجرا
  می‌شود. اگر بعد از اولین انتقال هر خطایی رخ دهد، فایل‌ها و ردیف قدیمی برگردانده می‌شوند. پس از موفقیت فقط آخرین
  پشتیبانِ همان پلاگین نگه داشته می‌شود.
- **بدون تغییر:** وضعیت پلاگین (فعال فعال می‌ماند و غیرفعال غیرفعال)، تاریخ نصب و فعال‌سازی، و همهٔ ردیف‌های درگاه و
  تنظیماتشان.
- یک رکورد لاگ ممیزی مدیر `plugin.upgrade` با کد، نسخهٔ قدیم و جدید و کاربر سیستم‌عاملی که دستور را اجرا کرده (و کاربر
  `sudo`) ثبت می‌شود؛ بدون هیچ تنظیماتی.
- لینک نمادین (symlink) پذیرفته نمی‌شود: در `var/plugins`، در پوشهٔ پلاگین و داخل فایل‌های قدیم یا جدید.

**OPcache.** ممکن است PHP-FPM تا بارگذاری دوباره کلاس‌های قدیمی پلاگین را اجرا کند؛ دستور در پایان این را یادآوری
می‌کند. `sudo amoobot-deploy --app-only` هم PHP-FPM را راه‌اندازی دوباره می‌کند، اما ارتقای بین دو deploy به همان
بارگذاری دوبارهٔ بالا نیاز دارد.

## قرارداد verify (سخت‌گیرانه)

پلاگین فقط پاسخ می‌دهد: آیا این پرداخت واقعاً انجام شده، چه مبلغی و با چه ارزی. بقیهٔ کارها (قفل،
جلوگیری از تکرار، بررسی مبلغ، ساخت سرویس، اعلان‌ها) را `PaymentSettlementService` انجام می‌دهد.

1. **مبالغ به تومان هستند.** برای درگاه ریالی مبلغ را با
   `App\Payment\Domain\Money\AmountNormalizer::toRial($amount)` (تومان × ۱۰) ارسال کنید.
2. **مرجع درگاه را ذخیره کنید.** شناسهٔ درگاه را از `createPayment()` به‌صورت `transactionId` /
   `authority` برگردانید. callback و webhook **فقط** با همین مرجع ذخیره‌شده به پرداخت وصل می‌شوند،
   نه با شناسهٔ پرداخت یا سفارش آموبات.
3. **`verifyPayment()` باید `amount` و `currency` را** همان‌طور که درگاه تأیید می‌کند برگرداند
   (رشتهٔ عددی، مثل `amount: "1500000", currency: "IRR"`) و برای بررسی از مرجع ذخیره‌شده استفاده کند.
4. **بدون مبلغ یا ارز ⇒ NEEDS_REVIEW.** نتیجهٔ پرداخت‌شده بدون این دو، ارز متفاوت یا مبلغ کمتر هرگز
   خودکار تأیید نمی‌شود و ادمین باید بررسی کند. مبلغ بیشتر تأیید و علامت‌گذاری می‌شود.
   **تلورانس کم‌پرداخت:** کسری حداکثر به اندازهٔ `underpayment_tolerance_percent` درصد از مبلغ مورد انتظار
   (تنظیم درگاه؛ پیش‌فرض ۰، یا ۱ اگر `plugin.json` مقدار `"category": "crypto"` داشته باشد) تأیید و با
   `underpaid_tolerated` علامت‌گذاری می‌شود؛ کسری بیشتر با ثبت مقدار کسری به NEEDS_REVIEW می‌رود. این کلید
   را با نوع `number` به `configSchema` اضافه کنید. تلورانس را هسته اعمال می‌کند؛ پلاگین باید همچنان مبلغ
   دقیق پرداخت‌شده را گزارش کند.
5. **قیمت به ارز دیگر:** اگر به USD/USDT و … دریافت می‌کنید، در `createPayment()`
   `$payment->setExpectation($quotedAmount, $quotedCurrency)` را صدا بزنید.
6. **Webhook باید fail-closed باشد.** در `handleWebhook()` امضا را بررسی کنید و اگر نادرست بود یا
   secret تنظیم نشده بود `handled: false, message: "unauthorized"` برگردانید. بدنهٔ webhook هرگز
   پرداخت‌شدن را تعیین نمی‌کند؛ فقط `verifyPayment()`.
7. **آدرس بازگشت باید توکن بازگشت داشته باشد.** هر پرداخت یک `returnToken` غیرقابل‌حدس دارد. مشتری را با
   `App\Payment\Domain\PaymentReturnUrl` برگردانید:
   - آدرس بازگشت/موفقیت درگاه: `PaymentReturnUrl::returnUrl($callbackBaseUrl, $payment)`
     (`/payment/return/{token}`) و آدرس لغو: `PaymentReturnUrl::cancelUrl(...)`؛
   - callback مرورگری که ابتدا آموبات پردازش می‌کند: `PaymentReturnUrl::withToken($callbackUrl, $payment)`
     که `?rt={token}` اضافه می‌کند؛ پس از پردازش، مشتری به صفحهٔ بازگشت هدایت می‌شود.

   صفحهٔ بازگشت پرداخت را **فقط** با این توکن پیدا می‌کند و تا وقتی پرداخت در انتظار است، حالت «در انتظار
   تایید» با به‌روزرسانی خودکار نشان می‌دهد. توکن هرگز برای پیدا کردن پرداخت در callback/webhook استفاده
   نمی‌شود (قانون ۲ پابرجاست).

8. **میان‌بر تست فقط خارج از production.** هر آرایهٔ config که آموبات به پلاگین می‌دهد کلید
   `_amoobot_testing_shortcuts_allowed` دارد (فقط در `dev`/`test` یا با debug برابر true)؛ در production هسته
   `auto_confirm_for_testing` را هم حذف می‌کند. هر چیزی که به‌جای درگاه به درخواست اعتماد می‌کند (مثل `demo_paid`
   در پلاگین دمو) باید به این کلید وابسته باشد.

مسیرها: webhook در `/payment/webhook/plugin/{code}` و بازگشت مرورگر در `/payment/callback/plugin/{code}`.

همچنین ببینید: [واحد مبالغ](../payments/amount-units.md).

پلاگین نباید مستقیم `PaymentSettlementService` را صدا بزند، سرویس بسازد یا secretها را log کند.
