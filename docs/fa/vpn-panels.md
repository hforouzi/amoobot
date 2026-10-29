# پنل‌های VPN

> راهنمای اتصال پنل جدید با API Token (پیشنهادی): [panel-connection.md](panel-connection.md). دستور `app:panel:doctor` همان گزارش «تست اتصال» را چاپ می‌کند و با `--self-test --yes` پنل را کامل آزمایش می‌کند.

`VpnPanel` تنظیمات اتصال و احراز هویت پنل ریموت را نگه می‌دارد. `VpnInbound` اینباند قابل فروش در آن پنل است. `VpnService` سرویس ساخته‌شده برای کاربر را به کلاینت ریموت وصل می‌کند.

## Sanaei / 3x-ui

حالت Legacy معمولاً این تنظیمات را دارد:

- `api_version=legacy`
- `auth_mode=cookie`
- نام کاربری و رمز عبور

حالت v3+ معمولاً این تنظیمات را دارد:

- `api_version=v3`
- `auth_mode=bearer`
- `api_token`
- endpoint مثل `/panel/api/inbounds/list`
- endpointهای لینک مثل `getClientLinks` و `getSubLinks`

## لینک اشتراک

- `subscription_base_url`: آدرس عمومی برای لینک‌های اشتراک.
- `subscription_path_prefix`: پیشوند مسیر لینک اشتراک.

## لینک‌های کانفیگ و Hostهای 3x-ui

روی پنلی که در حالت **modern** است، لینک‌های کانفیگ مشتری (دکمهٔ کانفیگ ربات، صفحهٔ `/s`، QR و کپی) دقیقاً همان چیزی است که اشتراک خود پنل می‌دهد: `GET /panel/api/clients/subLinks/{subId}`، همراه با **Host**های پنل (برای هر آدرسِ هر Host فعال یک لینک، به ترتیب پنل). ربات همین فهرست را به‌عنوان نسخهٔ ذخیره‌شدهٔ لینک‌های سرویس نگه می‌دارد و هیچ لینکی را که خودش از روی اینباند ساخته به آن اضافه نمی‌کند.

- اگر پنل در دسترس نباشد، درخواست را رد کند یا هیچ لینکی برنگرداند، لینک‌های ذخیره‌شده دست نمی‌خورند.
- فهرست کوتاه‌تر (مثلاً بعد از غیرفعال کردن یک Host) ذخیره و گزارش می‌شود.
- پنل‌های حالت legacy و پنل‌های دیگر مثل قبل کار می‌کنند.

طراحی: [بخش ۹ سند طراحی](../design/multi-inbound-plans.md).

دستور `app:panel:doctor <panelId>` نشان می‌دهد پنل API مربوط به Hostها را دارد یا نه:

- `hosts=yes`: دارد (همراه با تعداد گروه‌های Host)؛
- `hosts=no`: پنل قدیمی‌تر است؛ لینک‌ها باز هم از subLinks خوانده می‌شوند؛
- `hosts=forbidden`: توکن API اجازهٔ خواندن `/panel/api/hosts` را ندارد؛ دسترسی admin به آن بدهید.

### خواندن دوبارهٔ لینک‌ها: اول اجرای آزمایشی

```bash
# یک سرویس: تعداد لینک قبل و بعد، و هر لینکی که اضافه (+) یا حذف (-) می‌شود. چیزی نوشته نمی‌شود.
php bin/console app:service:regenerate-config <serviceId>
php bin/console app:service:regenerate-config <serviceId> --apply      # ذخیره

# همهٔ سرویس‌های یک اینباند، دسته‌دسته. بدون --apply فقط اجرای آزمایشی است.
php bin/console app:inbound:regenerate-service-configs <inboundId>
php bin/console app:inbound:regenerate-service-configs <inboundId> --apply --batch-size=50
php bin/console app:inbound:regenerate-service-configs <inboundId> --apply --after-id=<آخرین شناسهٔ پردازش‌شده>   # ادامه

# بعد از همگام‌سازی اینباندهای یک پنل: همهٔ سرویس‌های پنل، با همین قواعد (بدون --apply فقط آزمایشی).
php bin/console app:panel:sync-inbounds <panelId> --regenerate-configs
php bin/console app:panel:sync-inbounds <panelId> --regenerate-configs --apply
```

- **هر دو دستور بدون `--apply` فقط اجرای آزمایشی‌اند.** `--apply` همراه با `--dry-run` پذیرفته نمی‌شود.
- لینک‌ها به شکل `scheme address:port #remark` چاپ می‌شوند، هرگز همراه با UUID یا رمز.
- دستور دسته‌ای بعد از هر دسته `last processed id N` را چاپ می‌کند. اگر متوقف شد، آن را با `--after-id=N` دوباره اجرا کنید.
- سرویسی که درخواستش به پنل خطا بدهد با پیام `FAILED, stored links kept` رد می‌شود، اجرا ادامه پیدا می‌کند و کد خروج ۱ است.
- **بعد از ۵ سرویس ناموفق پشت‌سرهم، اجرا متوقف می‌شود** (احتمالاً پنل در دسترس نیست) و مقدار `--after-id` برای ادامه را چاپ می‌کند. بین دسته‌ها مکث می‌شود: `--pause` به ثانیه (پیش‌فرض ۰٫۲).
- اجرای دسته‌ای **هرگز پنل را «degraded» نمی‌کند** (که فروش روی آن را متوقف می‌کرد): خطاهای آن شمرده نمی‌شوند.
- هر سرویس جداگانه و با قفل روی ردیف خودش نوشته می‌شود. اگر «ریست لینک‌ها» در همان فاصله‌ای که لینک‌ها خوانده می‌شد مشخصات سرویس را عوض کرده باشد، لینک‌های خوانده‌شده دور ریخته می‌شوند (`credentials changed meanwhile`) و لینک‌های مشخصات جدید دوباره از پنل خوانده می‌شوند. لینک قدیمی هرگز روی مشخصات جدید نوشته نمی‌شود.
- دکمهٔ «بازسازی کانفیگ» در پنل وب و مینی‌اپ برای یک سرویس همان کار `--apply` را می‌کند و پیامش تعداد جدید و قبلی را نشان می‌دهد.
- اکشن وب «بازسازی کانفیگ سرویس‌ها» برای یک **اینباند** حداکثر **۱۰ سرویس** را انجام می‌دهد (برای هر سرویس یک درخواست به پنل، داخل یک درخواست وب). برای اینباند بزرگ‌تر، دستوری را که باید اجرا شود نشان می‌دهد: `php bin/console app:inbound:regenerate-service-configs <inboundId> --apply` (اول بدون `--apply`).

### بررسی لینک‌ها (فقط‌خواندنی)

```bash
php bin/console app:service:verify-links <serviceId>          # با --json گزارش ماشینی
```

این دستور فقط پایگاه داده و پنل را می‌خواند (فقط درخواست GET) و چیزی را تغییر نمی‌دهد. معنای DIFF در هر بررسی:

| بررسی | معنای DIFF |
|---|---|
| stored subId = panel subId | subId که ربات با آن لینک‌ها را می‌خواند با subId پنل یکی نیست، یا در پایگاه داده ذخیره نشده (در این حالت `regenerate-config <id> --apply` آن را ذخیره می‌کند). هیچ‌کدام از دو مقدار چاپ نمی‌شود. |
| stored snapshot = panel subLinks | سرویس هنوز لینک‌های قدیمی دارد. `app:service:regenerate-config <id> --apply` را اجرا کنید. |
| panel subLinks = Hosts prediction | Hostی که باید لینک بدهد لینکی نداده، یا security / SNI / path آن فرق دارد. اگر پنل API مربوط به Hostها را نداشته باشد یا کلاینت به Hostهای مشخصی وصل شده باشد، این بررسی انجام نمی‌شود. |
| every address is public | لینکی به `127.0.0.1` یا آدرس خصوصی اشاره می‌کند (Sub/Web domain پنل را تنظیم کنید یا Host اضافه کنید). |
| every link carries this client's credential | لینکی مال کلاینت دیگری است. تغییر دو بایت UUID توسط «VLESS route» مجاز است. |
| U2 | در subLinks موردی بود که ربات کنار می‌گذارد (مثلاً خط اطلاعات `socks://127.0.0.1`). |
| U6 | دو endpoint لینک پنل نتیجهٔ یکسان نمی‌دهند. ربات فقط subLinks را ذخیره می‌کند. |

کد خروج: `0` یعنی PASS، `1` یعنی تفاوت (فهرست می‌شود)، `2` یعنی بررسی ممکن نبود (پنل legacy، پنل در دسترس نبود یا رد کرد، کلاینت وجود نداشت، یا هر خطای پیش‌بینی‌نشده).

**بررسی‌های دستی** (چیزهایی که دستور نمی‌بیند)، روی یک سرویس آزمایشی:

- **U1.** در پنل، روی Hostی که چند آدرس دارد «Shuffle host» را روشن و خاموش کنید، بعد «Hide» را، بعد Nodeهایش را تغییر دهید. بعد از هر تغییر، `regenerate-config <id> --apply` را دو بار و سپس `verify-links <id>` را اجرا کنید. انتظار: لینک‌ها، تعداد و ترتیب یکسان بمانند.
- **U3.** روی یک اینباند REALITY، Hostی با Security = TLS بسازید. `regenerate-config <id>` (آزمایشی) را اجرا کنید و لینک را با لینکی که صفحهٔ کلاینت در پنل نشان می‌دهد مقایسه کنید.
- **U4.** در تنظیمات پنل، Subscription domain یا Web domain تنظیم شده باشد. وگرنه اینباندهای بی‌Host آدرسی را می‌گیرند که ربات با آن به پنل وصل می‌شود، و ربات آن لینک‌ها را نشان نمی‌دهد.
- **U5.** خروجی `app:panel:doctor <panelId>` نباید `hosts=forbidden` باشد.

### اولین بررسی روی production

1. یک سرویس آزمایشی روی اینباندی انتخاب کنید که دست‌کم دو Host فعال و یک Host غیرفعال دارد (بهتر است یک Host دو آدرس داشته باشد).
2. `php bin/console app:panel:doctor <panelId>`. انتظار: `PASS connection` و `hosts=yes`.
3. `php bin/console app:service:verify-links <serviceId>`. انتظار: PASS، یا DIFF فقط در «stored snapshot» (لینک‌های قدیمیِ قبل از این اصلاح).
4. `php bin/console app:service:regenerate-config <serviceId>`. خطوط + و - را بخوانید، سپس همین را با `--apply` اجرا کنید و دوباره `verify-links` بزنید. انتظار: PASS.
5. یک Host فعال را در پنل غیرفعال کنید.
   - `verify-links` باید DIFF «only in the stored snapshot» بدهد.
   - `regenerate-config <id> --apply` باید `! fewer links than before` چاپ کند.
   - `verify-links` دوباره باید PASS باشد.
   - Host را دوباره فعال کنید و همین مراحل را تکرار کنید.
6. به یک Host آدرس دوم اضافه کنید، سپس یک Host را «Exclude from formats: raw» کنید. بعد از هر تغییر `regenerate-config --apply` و `verify-links` را اجرا کنید. انتظار: یک لینک بیشتر، سپس یک لینک کمتر.
7. مقایسه با چیزی که گوشی می‌گیرد: خروجی `curl -s '<لینک اشتراک>' | base64 -d` باید همان لینک‌ها باشد. اگر info node پنل روشن باشد، یک خط `socks://127.0.0.1` هم دارد.
8. سپس برای هر اینباند: اول `app:inbound:regenerate-service-configs <inboundId>` (آزمایشی) را اجرا کنید و خروجی را بخوانید، بعد با `--apply`.

سرویس‌هایی روی پنل modern که **هیچ** لینک ذخیره‌شده‌ای ندارند، هنگام نمایش هنوز لینک ساخته‌شده از روی اینباند می‌گیرند (این اصلاح هنوز این مورد را پوشش نمی‌دهد). شمارش، فقط‌خواندنی:

```sql
SELECT COUNT(*) AS modern_services_without_link_snapshot
FROM vpn_service s JOIN vpn_panel p ON p.id = s.panel_id
WHERE p.type = 'sanaei_3xui'
  AND (p.sanaei_integration_mode = 'modern'
       OR (p.sanaei_integration_mode NOT IN ('legacy', 'modern') AND p.sanaei_detected_mode = 'modern'))
  AND s.status <> 'deleted'
  AND (s.config_links IS NULL OR TRIM(s.config_links) IN ('', '[]', 'null'));
```

## دستورهای مفید

```bash
php bin/console app:panel:detect-version
php bin/console app:panel:test-login
php bin/console app:panel:list-inbounds
php bin/console app:panel:test-client-links
php bin/console app:panel:sync-inbounds
php bin/console app:panel:sync-inbound-metadata
php bin/console app:panel:debug-transport
```

برای آرگومان‌ها از `php bin/console help COMMAND` استفاده کنید.

## عیب‌یابی

- برای Proxy و Timeout از `app:panel:debug-transport` استفاده کنید.
- در v3 توکن Bearer باید معتبر باشد.
- نوع شناسه اینباند ممکن است در پاسخ پنل عددی یا رشته‌ای باشد.
- لینک‌های `externalProxy` به پاسخ پنل و metadata اینباند وابسته‌اند.
