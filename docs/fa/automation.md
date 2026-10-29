# اتوماسیون

اتوماسیون کارهای تکرارشونده چرخه عمر سرویس‌ها و سفارش‌ها را انجام می‌دهد.

## کارها

- همگام‌سازی مصرف سرویس‌ها از پنل
- بررسی تاریخ انقضا
- تشخیص اتمام ترافیک
- ارسال اعلان‌ها
- تعلیق سرویس‌های منقضی‌شده در صورت فعال بودن
- تعلیق سرویس‌های با ترافیک تمام‌شده در صورت فعال بودن
- منقضی کردن سفارش‌های ناقص

## تنظیمات مهم

- `orders.incomplete_expire_hours`
- روزهای اعلان انقضا از `SERVICE_NOTIFY_EXPIRY_DAYS`
- آستانه‌های ترافیک از `SERVICE_NOTIFY_TRAFFIC_THRESHOLDS`
- Toggleهای اتوماسیون در تنظیمات پروژه، اگر فعال شده باشند

## دستورها

```bash
php bin/console app:automation:run --dry-run
php bin/console app:automation:run
php bin/console app:orders:expire-incomplete --dry-run
php bin/console app:orders:expire-incomplete
php bin/console app:service:sync-usage
php bin/console app:service:check-expiry
php bin/console app:service:send-notifications
```

روی داده واقعی ابتدا `--dry-run` اجرا کنید.

## زمان‌بندی (Production)

همه‌ی کارهای دوره‌ای از طریق `app:automation:run --only=<task>` اجرا می‌شوند، نه دستورهای مستقل. دلیل: فقط این دستور به
Toggleهای پنل ادمین (`automation.*`) احترام می‌گذارد. اگر کاری در پنل خاموش باشد، اجرای زمان‌بندی‌شده هم کاری انجام نمی‌دهد.
`deploy.sh` این کارها را به‌صورت systemd timer نصب می‌کند (راهنما: [deployment.md](deployment.md)).

| کار | دستور | فاصله‌ی پیشنهادی | زمان اجرا | پیش‌فرض Toggle |
|---|---|---|---|---|
| `broadcasts` | `app:automation:run --only=broadcasts` | هر ۲ دقیقه | `*:0/2` | روشن |
| `orders` (منقضی کردن سفارش‌های ناقص) | `app:automation:run --only=orders` | هر ۱۰ دقیقه | `*:0/10` | روشن |
| `sync` (مصرف سرویس‌ها از پنل) | `app:automation:run --only=sync` | هر ۱۵ دقیقه | `:00 :15 :30 :45` | روشن |
| `expiry` (بررسی انقضا) | `app:automation:run --only=expiry` | هر ۱۵ دقیقه، بعد از sync | `:05 :20 :35 :50` | روشن |
| `suspend` (تعلیق خودکار) | `app:automation:run --only=suspend` | هر ۱۵ دقیقه، بعد از expiry | `:08 :23 :38 :53` | **خاموش** (اختیاری) |
| `notifications` (اعلان انقضا و ترافیک) | `app:automation:run --only=notifications` | هر ۳۰ دقیقه | `:10 :40` | روشن |
| `claims` (منقضی کردن لینک‌های واگذاری، تلاش دوباره برای به‌روزرسانی پنل) | `app:automation:run --only=claims` | هر ۱۵ دقیقه | `:13 :28 :43 :58` | بدون Toggle (فقط مرتب‌سازی؛ وقتی لینکی نیست کاری نمی‌کند) |

- ترتیب sync ← expiry ← suspend عمدی است: انقضا و تعلیق روی داده‌ی تازه‌ی مصرف تصمیم می‌گیرند.
- `claims` هم در گروه قفل `lifecycle` است: به‌روزرسانی مالک کلاینت در پنل، مثل sync/expiry/suspend، کل ردیف کلاینت را بازنویسی می‌کند و دو نوشتن هم‌زمان می‌توانند تغییر یکدیگر را برگردانند. اگر کار دیگری از این گروه هنوز در حال اجرا باشد، `claims` تا ۱۲۰ ثانیه صبر می‌کند و در غیر این صورت این نوبت را رد می‌کند (نوبت بعدی ۱۵ دقیقه بعد).
- اعلان‌ها تکراری ارسال نمی‌شوند (کلید یکتای `service_id + type + key` در `service_notification_log`)، پس اجرای مکرر بی‌خطر است.
- `suspend` فقط وقتی کاری انجام می‌دهد که ادمین «تعلیق خودکار» را در تنظیمات روشن کرده باشد. پیش‌فرض خاموش است و زمان‌بندی آن را روشن نمی‌کند.

### عمداً زمان‌بندی نمی‌شوند

| دستور | دلیل |
|---|---|
| `app:payments:repair-stuck` | تعمیر پرداخت‌ها تصمیم انسانی می‌خواهد (`--apply` وضعیت پرداخت را تغییر می‌دهد) |
| `app:claims:expire` | همان کار `--only=claims`؛ فقط برای اجرای دستی |
| `app:service:sync-usage`، `app:service:check-expiry`، `app:service:send-notifications`، `app:orders:expire-incomplete`، `app:broadcast:run` | همان کارهای `automation:run` هستند ولی Toggleهای پنل را نادیده می‌گیرند. فقط برای اجرای دستی |
| `app:broadcast:send` / `prepare` / `retry-failed` | برای یک broadcast مشخص (آرگومان لازم دارند). ارسال زمان‌بندی‌شده با `--only=broadcasts` انجام می‌شود |
| `app:payment:check-nowpayments` | برای یک پرداخت مشخص. IPN درگاه وضعیت را خودکار به‌روز می‌کند |
| `app:telegram:poll` | فقط برای حالت long polling (توسعه). Production از webhook استفاده می‌کند |
| `app:panel:sync-inbounds`، `app:inbound:regenerate-service-configs` | تغییر دسته‌جمعی پیکربندی‌ها، فقط دستی |
| سلامت پنل‌ها | دستور جداگانه‌ای ندارد. خطای اتصال به پنل باعث شکست کار `sync` و ارسال هشدار می‌شود |

### محدودیت مهم: `automation.batch_limit`

`sync`، `expiry` و `suspend` در هر اجرا فقط **جدیدترین** `automation.batch_limit` سرویس را بررسی می‌کنند (پیش‌فرض ۱۰۰، حداکثر ۱۰۰۰، مرتب‌سازی `id DESC`).
بنابراین اگر تعداد سرویس‌ها از این عدد بیشتر شود، سرویس‌های قدیمی‌تر **هیچ‌وقت** همگام یا منقضی نمی‌شوند و کوتاه‌تر کردن فاصله‌ی اجرا هم کمکی نمی‌کند.
`automation.batch_limit` را در تنظیمات همیشه بیشتر از تعداد کل سرویس‌ها نگه دارید. (اصلاح خود این رفتار خارج از این تغییر است.)
