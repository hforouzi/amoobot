# راهنمای استقرار Production (Runbook)

این راهنما برای سرور **Ubuntu 24.04** و اسکریپت [`deploy.sh`](../../deploy.sh) است. همان اسکریپت هم برای اولین نصب و هم برای هر به‌روزرسانی اجرا می‌شود و اجرای دوباره‌ی آن بی‌خطر است (idempotent).

> **قانون اصلی:** هیچ رمز، توکن یا پسوردی وارد git نمی‌شود. همه‌ی رمزها فقط در فایل سرور
> `/etc/amoobot/.env.prod.local` (مالک root، دسترسی `600`) نگهداری می‌شوند.

---

## ۰. فوری: رمزهای لو رفته

نسخه‌ی قدیمی `deploy.sh` (کامیت `4206da6` و همه‌ی شاخه‌های بعدی، از جمله `main` روی GitHub) این مقادیر را **به‌صورت متن ساده** داشت:
`DB_PASS`، `BOT_TOKEN`، `APP_SECRET`، `WEBHOOK_SECRET`.

پاک کردن آن‌ها از فایل، تاریخچه‌ی git را پاک نمی‌کند. پس **همه را لو رفته فرض کنید** و قبل از Go-live عوض کنید (بخش ۶):

1. توکن بات: در @BotFather ← `/revoke` ← توکن جدید.
2. `APP_SECRET`، `TELEGRAM_WEBHOOK_SECRET`، `TELEGRAM_WEBHOOK_SECRET_TOKEN` و `DB_PASSWORD` جدید بسازید (`openssl rand -hex 32` / `openssl rand -hex 24`).
3. اگر مخزن عمومی است یا بوده، پاک‌سازی تاریخچه (`git filter-repo`) را جداگانه تصمیم بگیرید. این کار force-push لازم دارد و جایگزین عوض کردن رمزها **نیست**.

---

## ۱. اسکریپت چه می‌کند

| مرحله | کار |
|---|---|
| 0 | خواندن و اعتبارسنجی تنظیمات. اگر مقدار اجباری نباشد یا `APP_ENV`≠prod یا `APP_DEBUG`≠0 باشد، با پیام واضح متوقف می‌شود |
| 1–4 | `apt upgrade`، نصب Nginx، MariaDB، Supervisor، ufw، certbot، PHP 8.4 (PPA ondrej) و Composer (با بررسی امضای نصب‌کننده) |
| 5 | کاربر غیر root به نام `deploy`، SSH فقط با کلید، فایروال فقط 22/80/443، سیاست restart خودکار برای nginx/php-fpm/mariadb/supervisor/cron |
| 6 | تنظیم `sql_mode = STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION`، ساخت دیتابیس و کاربر (و همگام کردن پسورد) |
| 7 | گرفتن کد با **deploy key فقط‌خواندنی** و checkout روی ref خواسته‌شده؛ ثبت در `/var/lib/amoobot/releases.log`؛ نصب اسکریپت‌هایی که root اجرا می‌کند از **آبجکت‌های git همان کامیت** در `/usr/local/lib/amoobot` (بخش ۳) |
| 8 | `composer install --no-dev --optimize-autoloader` |
| 9 | ساخت `.env.prod.local` (600) و `.env.local.php` (`composer dump-env prod`) داخل پوشه‌ی برنامه |
| 10 | دسترسی‌ها: کد متعلق به `deploy`، فقط‌خواندنی برای `www-data`؛ `var/` قابل نوشتن برای `www-data` |
| 11 | پاک کردن کش، warmup، **بکاپ قبل از migration** (اگر migration جدید باشد)، اجرای همه‌ی migrationها (خطا = توقف)، `lint:container` |
| 12–13 | پیکربندی Nginx، گواهی Let's Encrypt با تمدید خودکار (`certbot.timer`) و ریدایرکت HTTP به HTTPS |
| 14 | worker پیام‌رسان فقط وقتی transport به نام `async` تعریف شده باشد (فعلاً تعریف نشده، پس نصب نمی‌شود) |
| 15 | cron بکاپ شبانه |
| 16 | نصب کارهای زمان‌بندی‌شده (systemd timer) و ادامه دادن آن‌ها (بخش ۸). از قبل از checkout در مرحله‌ی 7 تا این مرحله متوقف هستند |
| 17 | ثبت Webhook تلگرام با رمز در URL **و** `secret_token` (هدر `X-Telegram-Bot-Api-Secret-Token`) |
| 18 | بررسی نهایی: `APP_ENV=prod`، `APP_DEBUG=0` (یعنی میان‌برهای تست پلاگین‌ها مثل auto-confirm دموی پرداخت خاموش است)، فعال بودن همه‌ی timerها و `https://DOMAIN/healthz` = 200 |

گزینه‌ها:

```bash
sudo ./deploy.sh --dry-run          # فقط نمایش؛ هیچ تغییری نمی‌دهد و رمزها را چاپ نمی‌کند
sudo ./deploy.sh --app-only         # بدون مراحل 1 تا 6 (برای به‌روزرسانی سریع کد)
sudo ./deploy.sh --ref v1.4.0       # branch، tag یا commit مشخص (برای Rollback هم)
sudo ./deploy.sh --env-file /path   # فایل تنظیمات دیگر (پیش‌فرض /etc/amoobot/.env.prod.local)
```

---

## ۲. اولین استقرار

**پیش‌نیاز:** سرور تازه‌ی Ubuntu 24.04، دسترسی root با **کلید SSH**، و رکورد DNS از نوع A برای دامنه که به IP سرور اشاره کند (بدون آن گواهی HTTPS صادر نمی‌شود). SSH باید روی پورت 22 باشد.

```bash
# 1) روی سرور: فایل تنظیمات را بسازید (رمزها را همین‌جا تولید کنید، نه روی لپ‌تاپ یا چت)
sudo install -d -m 700 /etc/amoobot
sudo curl -fsSL -o /etc/amoobot/.env.prod.local \
  https://raw.githubusercontent.com/hforouzi/amoobot/main/.env.prod.example   # یا با scp کپی کنید
sudo chown root:root /etc/amoobot/.env.prod.local && sudo chmod 600 /etc/amoobot/.env.prod.local
openssl rand -hex 32   # برای APP_SECRET، TELEGRAM_WEBHOOK_SECRET، TELEGRAM_WEBHOOK_SECRET_TOKEN (هر کدام جدا)
openssl rand -hex 24   # برای DB_PASSWORD
sudo nano /etc/amoobot/.env.prod.local
```

مقادیر اجباری: `DOMAIN`، `DB_PASSWORD`، `APP_SECRET`، `TELEGRAM_BOT_TOKEN`، `TELEGRAM_WEBHOOK_SECRET`، `TELEGRAM_WEBHOOK_SECRET_TOKEN`، `TELEGRAM_ADMIN_CHAT_ID`. پیشنهاد می‌شود `DEPLOY_SSH_PUBLIC_KEY` (کلید عمومی خودتان) را هم پر کنید.

```bash
# 2) اسکریپت را به سرور بیاورید و ابتدا dry-run بگیرید
scp deploy.sh root@SERVER:/root/deploy.sh
ssh root@SERVER 'bash /root/deploy.sh --dry-run'

# 3) اجرای واقعی
ssh root@SERVER 'bash /root/deploy.sh'
```

**اولین اجرا در مرحله‌ی 7 عمداً متوقف می‌شود** و کلید عمومی deploy key را چاپ می‌کند:
GitHub ← مخزن ← Settings ← Deploy keys ← Add deploy key. کلید را بچسبانید و **Allow write access را تیک نزنید**. سپس اسکریپت را دوباره اجرا کنید.

```bash
# 4) ساخت ادمین پنل و داده‌های اولیه
cd /var/www/amoobot
sudo -u www-data php bin/console app:admin:create-user --env=prod
sudo -u www-data php bin/console app:settings:seed --env=prod
sudo -u www-data php bin/console app:bot-content:seed --env=prod
sudo -u www-data php bin/console app:payment:create-default-gateways --env=prod
```

> قبل از بستن نشست SSH فعلی، در یک ترمینال دیگر بررسی کنید که با `ssh deploy@SERVER` (با کلید) وارد می‌شوید.
> ورود با پسورد از این به بعد غیرفعال است.

---

## ۳. به‌روزرسانی

```bash
ssh deploy@SERVER
sudo amoobot-deploy --app-only            # آخرین DEPLOY_REF (پیش‌فرض main)
sudo amoobot-deploy --app-only --ref v1.5.0
```

- اسکریپت قبل از اجرا از روی خودش یک کپی می‌گیرد، پس checkout کد جدید وسط کار مشکلی ایجاد نمی‌کند. توجه: در این حالت **نسخه‌ی فعلیِ** `deploy.sh` اجرا می‌شود و نسخه‌ی جدید از اجرای بعدی.
- **root هیچ فایلی از پوشه‌ی برنامه را اجرا نمی‌کند.** کاربر `deploy` مالک `/var/www/amoobot` است و می‌تواند آن را تغییر دهد، پس
  `deploy.sh`، اسکریپت‌های بکاپ/بازگردانی، اسکریپت‌های کارهای زمان‌بندی‌شده و `bin/lib/` با `git cat-file blob <کامیت>:<مسیر>`
  (محتوای commit‌شده، نه فایل روی دیسک) در `/usr/local/lib/amoobot` (مالک root، `755`/`644`) نصب می‌شوند. ورودی‌ها:

  | دستور | کار |
  |---|---|
  | `sudo amoobot-deploy [گزینه‌ها]` | همان `deploy.sh` (نسخه‌ی نصب‌شده از آخرین deploy) |
  | `sudo amoobot-backup [برچسب]` | بکاپ دستی (پیش‌فرض برچسب `manual`) |
  | `sudo amoobot-restore <فایل> --yes` | بازگردانی (بخش ۵) |
  | `sudo amoobot-check-jobs` | بررسی تازگی کارها (بخش ۸) |

  هر چهار فایل در `/usr/local/sbin` و مالک root هستند و اسکریپت را با محیط تمیز (`env -i`) اجرا می‌کنند. cron بکاپ، بکاپ قبل از
  migration و unitهای systemd هم فقط همین مسیرها را اجرا می‌کنند. اسکریپت‌های بکاپ/بازگردانی `DATABASE_URL` را فقط از
  `/etc/amoobot/database.env` (مالک root، `600`، ساخته‌شده در مرحله‌ی 9) می‌خوانند و هیچ‌وقت `.env.local.php` برنامه را به‌عنوان root بارگذاری نمی‌کنند.
- اگر migration جدید وجود داشته باشد، قبل از آن خودکار یک بکاپ با برچسب `pre-deploy` در `/var/backups/amoobot` گرفته می‌شود.
- اگر فایل‌های tracked روی سرور دستی تغییر کرده باشند، اسکریپت متوقف می‌شود (هیچ تغییری روی سرور دور ریخته نمی‌شود).
- اجرای کامل (بدون `--app-only`) بسته‌های سیستم را هم به‌روز می‌کند؛ هر چند وقت یک‌بار در زمان کم‌ترافیک انجام دهید.

---

## ۴. Rollback

```bash
tail -n 5 /var/lib/amoobot/releases.log        # <زمان> <کامیت قبلی> -> <کامیت جدید> (ref)
sudo amoobot-deploy --app-only --ref <کامیت قبلی>
```

**دیتابیس:** migrationها فقط رو به جلو اجرا می‌شوند. اگر نسخه‌ی خراب migration داشته که با کد قدیمی سازگار نیست:

```bash
sudo systemctl stop php8.4-fpm 'amoobot-job-*.timer' 'amoobot-job-*.service'
ls -lt /var/backups/amoobot/*_pre-deploy_*.sql.gz | head -3
sudo amoobot-restore /var/backups/amoobot/amoobot_pre-deploy_<زمان>.sql.gz --yes
sudo amoobot-deploy --app-only --ref <کامیت قبلی>   # PHP-FPM و timerها را هم دوباره راه می‌اندازد
```

> داده‌هایی که بین بکاپ و بازگردانی ثبت شده‌اند (سفارش‌ها و پرداخت‌ها) از بین می‌روند. قبل از restore، بکاپ `pre-restore` خودکار گرفته می‌شود تا در صورت نیاز دستی بازیابی شوند.

---

## ۵. بکاپ و بازگردانی

- cron: `/etc/cron.d/amoobot-backup` (پیش‌فرض هر شب 03:30) ← `/usr/local/sbin/amoobot-backup nightly` (نسخه‌ی root‌مالک `bin/backup-database.sh`)
- `DATABASE_URL` برای بکاپ و بازگردانی: `/etc/amoobot/database.env` (مالک root، `600`)
- مقصد: `/var/backups/amoobot` (مالک root، `700`)؛ نگهداری `BACKUP_RETENTION_DAYS` روز (پیش‌فرض 14)
- لاگ: `/var/log/amoobot-backup.log`
- هر فایل `<db>_<برچسب>_<UTC>.sql.gz` است. برچسب‌ها: `nightly`، `pre-deploy`، `pre-restore`. بکاپ ناقص هیچ‌وقت جایگزین فایل سالم نمی‌شود (هم `gzip -t` و هم بررسی خط `Dump completed`).
- پسورد دیتابیس از طریق فایل option موقت با دسترسی `600` به mysqldump داده می‌شود و در لیست پروسه‌ها دیده نمی‌شود.

```bash
# بکاپ دستی
sudo amoobot-backup manual

# بازگردانی (کارهای زمان‌بندی‌شده هم متوقف شوند تا وسط restore چیزی ننویسند)
sudo systemctl stop php8.4-fpm 'amoobot-job-*.timer' 'amoobot-job-*.service'
sudo amoobot-restore /var/backups/amoobot/<فایل>.sql.gz --yes
sudo -u www-data php /var/www/amoobot/bin/console doctrine:migrations:status --env=prod
sudo systemctl start php8.4-fpm 'amoobot-job-*.timer'
curl -fsS https://DOMAIN/healthz
```

`restore-database.sh` این کارها را انجام می‌دهد: بررسی سلامت آرشیو، بکاپ ایمنی `pre-restore`، حذف **همه‌ی** جدول‌ها و viewها، import و گزارش تعداد جدول‌ها و migrationها. بدون `--yes` اجرا نمی‌شود.

**بکاپ خارج از سرور:** بکاپی که فقط روی همان سرور باشد در برابر از دست رفتن سرور محافظتی ندارد. پوشه‌ی `/var/backups/amoobot` را به‌طور منظم به جای دیگری (مثلاً با `rsync` یا `rclone`) و به‌صورت رمزنگاری‌شده منتقل کنید.

**تمرین بازگردانی (هر ماه):** آخرین بکاپ را روی یک دیتابیس یا سرور آزمایشی restore کنید و `doctrine:migrations:up-to-date` را اجرا کنید. بکاپی که هیچ‌وقت restore نشده، بکاپ مطمئنی نیست.

---

## ۶. چرخش رمزها (Rotation)

همه‌ی موارد زیر با ویرایش `/etc/amoobot/.env.prod.local` و سپس `sudo amoobot-deploy --app-only` انجام می‌شوند:

| رمز | روش | اثر جانبی |
|---|---|---|
| `TELEGRAM_BOT_TOKEN` | در @BotFather دستور `/revoke`، مقدار جدید را وارد کنید | Webhook با توکن جدید دوباره ثبت می‌شود |
| `TELEGRAM_WEBHOOK_SECRET` / `TELEGRAM_WEBHOOK_SECRET_TOKEN` | `openssl rand -hex 32` | Webhook دوباره ثبت می‌شود. چند ثانیه بین deploy و ثبت، آپدیت‌ها با 403 رد می‌شوند و تلگرام بعداً دوباره می‌فرستد |
| `APP_SECRET` | `openssl rand -hex 32` | نشست‌ها و remember-me ادمین‌ها باطل می‌شود |
| `DB_PASSWORD` | `openssl rand -hex 24` (فقط `A-Z a-z 0-9 . _ ~ -`) | اسکریپت در مرحله‌ی 6 پسورد کاربر MariaDB را هم عوض می‌کند. **بدون `--app-only` اجرا کنید** |
| کلیدهای درگاه پرداخت | در پنل ادمین ← درگاه‌ها | مستقل از deploy |
| deploy key گیت | `rm ~deploy/.ssh/amoobot_deploy_ed25519*`، اجرای deploy و ثبت کلید جدید در GitHub (کلید قبلی را حذف کنید) | — |

بعد از هر چرخش: `curl -fsS https://DOMAIN/healthz` و یک پیام آزمایشی به بات.

---

## ۷. چک‌لیست Go-live

**امنیت**
- [ ] همه‌ی رمزهای لو رفته‌ی بخش ۰ عوض شده‌اند (توکن بات revoke شده است)
- [ ] `/etc/amoobot/.env.prod.local` با مالک root و دسترسی `600` (`stat -c '%a %U' /etc/amoobot/.env.prod.local` ← `600 root`)
- [ ] `git grep` در مخزن هیچ رمز واقعی‌ای پیدا نمی‌کند
- [ ] ورود `ssh deploy@SERVER` با کلید کار می‌کند و ورود با پسورد رد می‌شود
- [ ] `sudo ufw status` فقط 22، 80 و 443 را نشان می‌دهد
- [ ] deploy key در GitHub **بدون** دسترسی نوشتن است

**برنامه**
- [ ] خروجی deploy بدون خطا تا «Amoobot deployment completed»
- [ ] `sudo -u www-data php /var/www/amoobot/bin/console about --env=prod` ← Environment `prod` و Debug `false`
- [ ] `curl -fsS https://DOMAIN/healthz` ← `{"status":"ok"}`
- [ ] `curl -I http://DOMAIN` ← ریدایرکت 301 به https
- [ ] `doctrine:migrations:up-to-date` موفق است
- [ ] MariaDB: `sudo mysql -N -e 'SELECT @@GLOBAL.sql_mode'` ← `STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION`
- [ ] ادمین ساخته شده و ورود به `/admin` کار می‌کند

**تلگرام**
- [ ] `/start` در بات جواب می‌دهد و پیام ادمین به `TELEGRAM_ADMIN_CHAT_ID` می‌رسد
- [ ] `app:telegram:webhook-info` بدون `last_error_message` است (این خروجی رمز URL را دارد؛ جایی کپی نکنید)

**پرداخت**
- [ ] هر درگاه در پنل: `sandbox=false`، کلیدهای production، `callback_base_url=https://DOMAIN`
- [ ] پلاگین دمو غیرفعال است (`app:plugin:list`). در prod هم هیچ پرداختی را تأیید نمی‌کند، اما نباید فعال بماند
- [ ] یک پرداخت واقعی با کمترین مبلغ: callback و فعال شدن سرویس درست انجام می‌شود
- [ ] اطلاعات کارت‌به‌کارت (`PAYMENT_CARD_*`) درست است

**عملیات**
- [ ] `ls /var/backups/amoobot` بعد از اولین شب یک فایل `nightly` دارد و یک‌بار restore آزمایشی انجام شده است
- [ ] بکاپ‌ها به خارج از سرور منتقل می‌شوند
- [ ] `systemctl list-timers certbot.timer` فعال است (`sudo certbot renew --dry-run` موفق است)
- [ ] مانیتورینگ uptime روی `https://DOMAIN/healthz`
- [ ] `systemctl list-timers 'amoobot-job-*'` شش timer نشان می‌دهد و ستون LAST یک ساعت بعد از deploy برای همه پر است
- [ ] `sudo amoobot-check-jobs` دو ساعت بعد از deploy (و بعد از اولین بکاپ شبانه) کد خروج `0` می‌دهد و به مانیتورینگ وصل شده است
- [ ] Toggleهای اتوماسیون در پنل ادمین بررسی شده‌اند (به‌خصوص «تعلیق خودکار»؛ پیش‌فرض خاموش)
- [ ] `automation.batch_limit` بیشتر از تعداد کل سرویس‌هاست ([automation.md](automation.md))
- [ ] هشدار آزمایشی رسیده است: `sudo -u www-data env APP_DIR=/var/www/amoobot /usr/local/lib/amoobot/scheduled-job-alert.sh failed test` (بعد `... recovered test`)

---

## ۸. کارهای زمان‌بندی‌شده (systemd timers)

`deploy.sh` در مرحله‌ی 16 برای هر کار یک service از نوع `oneshot` و یک timer نصب می‌کند. فهرست کارها، فاصله‌ها و دلیل هر کدام در
[automation.md](automation.md) آمده است. منبع اصلی آرایه‌ی `SCHEDULED_JOBS` در `deploy.sh` است. برای تغییر زمان‌بندی همان را
ویرایش کنید و دوباره deploy کنید: unitها به‌روز می‌شوند و کارهای حذف‌شده غیرفعال و پاک می‌شوند.
`sudo amoobot-deploy --dry-run` جدول کارها و متن کامل (یا diff) هر unit را قبل از نصب نشان می‌دهد.

### فهرست کارها و شواهد انتخاب

فاصله‌ها پیشنهاد عملیاتی هستند؛ کد فاصله‌ی اجباری تعیین نمی‌کند. همه با `app:automation:run --only=<task>` اجرا می‌شوند تا
تنظیمات `automation.*` رعایت شود. بسته‌های `util-linux` (برای `flock`) و `logrotate` در مرحله‌ی نصب بسته‌ها نصب می‌شوند.

| کار | فاصله و زمان | دستور مستقل متناظر / شاهد در کد | دلیل |
|---|---|---|---|
| `broadcasts` | هر ۲ دقیقه، `*:0/2` | `app:broadcast:run`؛ `src/Command/BroadcastRunCommand.php` و `src/Broadcast/BroadcastSender.php::runDue` | ارسال پیام‌های موعدرسیده با تأخیر کم؛ retry شکست‌ها خودکار نیست |
| `orders` | هر ۱۰ دقیقه، `*:0/10` | `app:orders:expire-incomplete`؛ `src/Shop/Application/IncompleteOrderExpiryService.php::expire` | انقضای سفارش و پیش‌نویس قدیمی؛ پرداخت تأییدشده، نیازمند بررسی یا دارای رسید محافظت می‌شود |
| `sync` | هر ۱۵ دقیقه، `*:0/15` | `app:service:sync-usage`؛ `src/Provisioning/Application/ServiceUsageSyncService.php::syncActiveServices` | دریافت مصرف و تاریخ انقضا از پنل |
| `expiry` | هر ۱۵ دقیقه، `*:5/15` | `app:service:check-expiry`؛ `src/Provisioning/Application/ServiceExpiryChecker.php::checkAll` | تغییر وضعیت سرویس منقضی‌شده پس از sync، بدون حذف سرویس |
| `suspend` | هر ۱۵ دقیقه، `*:8/15` | `src/Command/AutomationRunCommand.php` بخش `suspend`؛ `src/Provisioning/Application/ServiceAutoSuspendService.php` | تعلیق اختیاری انقضا/اتمام ترافیک؛ هر دو toggle پیش‌فرض خاموش هستند |
| `notifications` | هر ۳۰ دقیقه، `*:10/30` | `app:service:send-notifications`؛ `src/Command/ServiceSendNotificationsCommand.php` | اعلان نزدیک انقضا، مصرف و انقضای نهایی |

شاهد مشترک اجرای این شش کار: `src/Command/AutomationRunCommand.php` و [automation.md](automation.md).
پیش‌فرض toggleها در `src/Provisioning/Application/AutomationSettingsProvider.php` است.
تعلیق Sanaei در `src/Provisioning/Infrastructure/Sanaei3xui/Sanaei3xuiDriver.php::suspendService` فقط `enable=false` می‌کند؛
client حذف نمی‌شود و تمدید دوباره آن را فعال می‌کند. زمان‌بندی هیچ toggleای را روشن نمی‌کند.

سلامت پنل دستور دوره‌ای جداگانه ندارد: شکست `getUsage` در `ServiceUsageSyncService::syncOne` به شکست `sync` و هشدار تبدیل می‌شود
(هر ۱۵ دقیقه، فقط پنل‌های سرویس‌های بررسی‌شده؛ پنل بدون سرویس بررسی نمی‌شود). `app:panel:test-login` یک ابزار دستی برای پنل مشخص است.
دستورهای مستقل جدول جداگانه زمان‌بندی نمی‌شوند تا کارها دو بار اجرا نشوند. `app:broadcast:prepare/send/retry-failed` برای یک broadcast مشخص هستند.
`app:payments:repair-stuck`، ابزارهای پرداخت/تعمیر/seed، تغییر قیمت، ایجاد client آزمایشی، حذف webhook و بازنویسی گروهی تنظیمات
در زمان‌بندی نیستند؛ preflight فقط شش دستور جدول را می‌پذیرد.

| چیز | محل |
|---|---|
| unitها | `/etc/systemd/system/amoobot-job-<job>.service` و `.timer`، هشدار: `amoobot-job-alert@.service` |
| اجرای واقعی | `/usr/local/lib/amoobot/scheduled-job.sh` (نسخه‌ی root‌مالک `bin/scheduled-job.sh`) با کاربر `www-data` (کاربر برنامه) |
| خروجی کامل | `/var/log/amoobot/<job>.log` (چرخش روزانه، ۱۴ روز: `/etc/logrotate.d/amoobot-jobs`) |
| خلاصه‌ی هر اجرا | `journalctl -u amoobot-job-<job>` |
| آخرین اجرا / آخرین موفقیت | `/var/lib/amoobot/jobs/<job>.last-run` و `<job>.last-success` |
| قفل‌ها | `/run/amoobot/<گروه>.lock` (`sync`، `expiry`، `suspend` و `notifications` در گروه `lifecycle` هستند و هم‌زمان اجرا نمی‌شوند) |

### دیدن وضعیت آخرین اجرا

```bash
systemctl list-timers 'amoobot-job-*'                  # LAST = آخرین اجرا، NEXT = اجرای بعدی
systemctl list-units --failed 'amoobot-job-*'          # کارهایی که آخرین اجرایشان شکست خورده
systemctl status amoobot-job-sync.service              # Active/Result و کد خروج آخرین اجرا
journalctl -u amoobot-job-sync -n 20                   # خلاصه‌ی اجراهای اخیر (rc و مدت)
cat /var/lib/amoobot/jobs/sync.last-run                # <زمان> rc=<کد> duration=<ثانیه>
tail -n 50 /var/log/amoobot/sync.log                   # خروجی کامل دستور
```

### بررسی تازگی همه‌ی کارها

```bash
sudo amoobot-check-jobs
```

```text
STATUS    JOB            LAST-SUCCESS  MAX-AGE   NOTES
OK        broadcasts     3m ago        16m       schedule *:0/2; last run rc=0
FAILED    sync           2h0m ago      70m       schedule *:0/15; last run rc=1; unit failed: journalctl -u amoobot-job-sync
...
OK        backup         5h12m ago     26h0m     newest amoobot_nightly_20261014T033001Z.sql.gz

CRITICAL: 1 of 7 jobs need attention.
```

| وضعیت | معنی |
|---|---|
| `OK` | آخرین اجرای موفق در محدوده‌ی مجاز است (۳ برابر فاصله‌ی اجرا + timeout) |
| `STALE` | آخرین موفقیت خیلی قدیمی است (شکست یا رد شدن‌های پشت سر هم) |
| `NEVER` | هنوز هیچ اجرای موفقی ثبت نشده (عادی در چند دقیقه‌ی اول بعد از اولین deploy) |
| `INACTIVE` | timer فعال نیست (مثلاً deploy شکست‌خورده کارها را متوقف گذاشته) |
| `FAILED` | آخرین اجرا شکست خورده، حتی اگر موفقیت قبلی هنوز تازه باشد |

بکاپ شبانه هم بررسی می‌شود (جدیدترین `*_nightly_*.sql.gz` کمتر از ۲۶ ساعت). این بخش فقط با `sudo` اجرا می‌شود، چون پوشه‌ی بکاپ فقط برای root قابل خواندن است.
کد خروج برای مانیتورینگ (Nagios/Icinga/cron): `0` همه سالم، `2` مشکل، `3` امکان بررسی نیست (هنوز deploy نشده).

### کار با کارها

```bash
sudo systemctl start amoobot-job-sync.service          # اجرای فوری یک کار (همان کاربر، قفل و لاگ)
sudo systemctl stop 'amoobot-job-*.timer'              # توقف موقت همه‌ی زمان‌بندی‌ها
sudo systemctl start 'amoobot-job-*.timer'             # ادامه
sudo systemctl reset-failed 'amoobot-job-*'            # پاک کردن وضعیت failed بعد از رفع مشکل
```

برای غیرفعال کردن یک کار (مثلاً `notifications`):

```bash
sudo systemctl disable --now amoobot-job-notifications.timer
# اگر همان کار اکنون در حال اجراست، برای توقف آن:
sudo systemctl stop amoobot-job-notifications.service
# فعال‌سازی مجدد:
sudo systemctl enable --now amoobot-job-notifications.timer
```

deploy بعدی همه‌ی timerهای موجود در `SCHEDULED_JOBS` را دوباره فعال می‌کند. برای حذف پایدار، سطر همان کار را از آرایه حذف کنید؛
deploy بعدی unitهای آن را غیرفعال و حذف می‌کند. برای توقف عملکرد بدون تغییر unit، toggle متناظر `automation.*` را در پنل خاموش کنید.
checker یک timer غیرفعال را `INACTIVE` گزارش می‌کند و `reset-failed` به‌تنهایی شکست ثبت‌شده در فایل `last-run` را پاک نمی‌کند.

### شکست و هشدار

- کد خروج کار همان کد خروج دستور است. پس شکست (یا گذشتن از `TimeoutStartSec`) یعنی unit در وضعیت **failed**.
  `app:automation:run` اگر حتی یک مورد شکست بخورد (مثلاً یک پنل در دسترس نباشد) کد خطا برمی‌گرداند.
- با شکست، `amoobot-job-alert@` یک پیام کوتاه به `TELEGRAM_ADMIN_CHAT_ID` می‌فرستد: نام کار، سرور، نتیجه‌ی systemd و آخرین
  موفقیت. **خروجی کار در پیام نیست** (ممکن است داده‌ی مشتری داشته باشد). برای هر کار حداکثر یک هشدار در ساعت
  (`ALERT_REPEAT_MINUTES`) فرستاده می‌شود و بعد از اولین اجرای موفق، پیام «recovered» می‌آید.
- اگر قفل گروه بیش از ۲ دقیقه آزاد نشود (کار دیگری یا deploy در حال اجراست)، اجرا رد می‌شود (`SKIPPED` در لاگ، unit موفق).
  اگر این وضعیت تکرار شود، بررسی تازگی (بخش بعد) آن را نشان می‌دهد.
- هنگام deploy، timerها از قبل از checkout متوقف می‌شوند و deploy صبر می‌کند تا کار در حال اجرا تمام شود. اگر deploy شکست
  بخورد، کارها **متوقف می‌مانند** (پیام `scheduled jobs are PAUSED`) تا یک deploy موفق انجام شود.

---

## ۹. عیب‌یابی

| نشانه | بررسی |
|---|---|
| توقف در مرحله‌ی 0 | پیام دقیقاً نام متغیر ناقص یا نامعتبر را می‌گوید (مقدارها چاپ نمی‌شوند) |
| توقف در مرحله‌ی 7 | deploy key در GitHub ثبت نشده است. کلید چاپ‌شده را اضافه کنید |
| خطای certbot | DNS هنوز به سرور اشاره نمی‌کند یا پورت 80 بسته است |
| `healthz` ← 503 | اتصال دیتابیس: `systemctl status mariadb`، و `/var/log/php8.4-fpm.log` و `/var/log/nginx/amoobot_error.log` (این شاخه monolog ندارد، پس خطاهای برنامه در لاگ PHP-FPM/nginx ثبت می‌شوند) |
| بات جواب نمی‌دهد | `app:telegram:webhook-info` (خصوصی)، `/var/log/nginx/amoobot_error.log`، و اینکه پاسخ‌های 403 در access log نیامده باشند |
| خطای migration | deploy متوقف می‌شود و کد جدید روی سرور است اما دیتابیس ناقص. یا مشکل را رفع و دوباره deploy کنید، یا طبق بخش ۴ Rollback کنید |
| هشدار «scheduled job FAILED» | `journalctl -u amoobot-job-<job> -n 50` و `/var/log/amoobot/<job>.log`. نتیجه‌ی `timeout` یعنی کار از `TimeoutStartSec` بیشتر طول کشیده (معمولاً پنل کند یا در دسترس نیست) |
| کاری هیچ‌وقت اجرا نمی‌شود | `systemctl list-timers 'amoobot-job-*'`. اگر deploy قبلی شکست خورده باشد، timerها متوقف مانده‌اند. یک deploy موفق اجرا کنید |
| هشدار نمی‌رسد | `systemctl status 'amoobot-job-alert@*'`: معمولاً `TELEGRAM_ADMIN_CHAT_ID` اشتباه است، یا بات در آن چت پیام نفرستاده/عضو نیست |
