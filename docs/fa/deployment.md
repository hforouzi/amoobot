# راهنمای استقرار Production (Runbook)

> اولین استقرار روی سرور تازه؟ قدم‌به‌قدم: [first-deploy-checklist.md](first-deploy-checklist.md).
> اتصال پنل 3x-ui (آدرس + API Token، گواهی self-signed، عیب‌یابی): [panel-connection.md](panel-connection.md).

این راهنما برای سرور **Ubuntu 24.04** و اسکریپت [`deploy.sh`](../../deploy.sh) است. همان اسکریپت هم برای اولین نصب و هم برای هر به‌روزرسانی اجرا می‌شود و اجرای دوباره‌ی آن بی‌خطر است (idempotent).

> **قانون اصلی:** هیچ رمز، توکن یا پسوردی وارد git نمی‌شود. همه‌ی رمزها فقط در فایل سرور
> `/etc/amoobot/.env.prod.local` (مالک root، دسترسی `600`) نگهداری می‌شوند.

> **هشدار: رمز را روی خط فرمان ندهید.** `sudo TELEGRAM_BOT_TOKEN=... ./deploy.sh` (یا `export ...` و بعد اجرا) **پشتیبانی نمی‌شود**:
> مقدار در history شل (`~/.bash_history`)، لاگ sudo (`/var/log/auth.log`) و محیط همه‌ی پروسه‌های فرزند (`/proc/<pid>/environ`،
> ابزارهای مانیتورینگ و crash report) می‌ماند. sudo هم به‌طور پیش‌فرض این متغیرها را حذف می‌کند (`env_reset`) و کاربر `deploy` اجازه‌ی
> تنظیم متغیر ندارد. **تنها راه پشتیبانی‌شده** فایل `/etc/amoobot/.env.prod.local` است: آن را با `sudo nano` ویرایش و سپس
> `sudo amoobot-deploy` را اجرا کنید. اگر رمزی را اشتباهی روی خط فرمان وارد کرده‌اید، آن را لو رفته فرض کنید و طبق بخش ۶ عوض کنید.

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
| 5 | کاربر غیر root به نام `deploy` (با sudo فقط برای `amoobot-*`) و سیاست restart خودکار برای nginx/php-fpm/mariadb/supervisor/cron. **اختیاری و پیش‌فرض خاموش:** SSH فقط با کلید (`SSH_HARDENING=yes`، فقط اگر ورود `deploy` با کلید تأیید شود) و فایروال فقط 22/80/443 (`FIREWALL=yes`). با مقدار پیش‌فرض `no` تنظیمات SSH و ufw سرور دست نمی‌خورد |
| 6 | تنظیم `sql_mode = STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION`، ساخت دیتابیس و کاربر (و همگام کردن پسورد) |
| 7 | root با **deploy key فقط‌خواندنیِ متعلق به root** (`/etc/amoobot/repo_deploy_ed25519`) مخزن را در **آینه‌ی root‌مالک** `/var/lib/amoobot/mirror.git` می‌گیرد، `--ref` را فقط با branchها و tagهای origin در همین آینه resolve می‌کند و اسکریپت‌هایی را که root اجرا می‌کند از همین آینه در `/usr/local/lib/amoobot` نصب می‌کند؛ checkout کاربر `deploy` از یک bundle همان کامیت پر می‌شود؛ ثبت در `/var/lib/amoobot/releases.log` (بخش ۳، «مدل اعتماد») |
| 8 | `composer install --no-dev --optimize-autoloader` |
| 9 | ساخت `.env.prod.local` (600) و `.env.local.php` (`composer dump-env prod`) داخل پوشه‌ی برنامه |
| 10 | دسترسی‌ها: کد متعلق به `deploy`، فقط‌خواندنی برای `www-data`؛ `var/` قابل نوشتن برای `www-data` |
| 11 | پاک کردن کش، warmup، **بکاپ قبل از migration** (اگر migration جدید باشد)، اجرای همه‌ی migrationها (خطا = توقف)، `lint:container` |
| 12–13 | پیکربندی Nginx (IPv4 و در صورت وجود IPv6)، گواهی Let's Encrypt با تمدید خودکار (`certbot.timer`) و ریدایرکت HTTP به HTTPS. تا وقتی گواهی نیست، پورت 80 فقط `/.well-known/acme-challenge/` را سرو می‌کند و بقیه 503 است. HSTS فقط بعد از موفقیت `https://DOMAIN/healthz` در مرحله‌ی 18 |
| 14 | worker پیام‌رسان فقط وقتی transport به نام `async` تعریف شده باشد (فعلاً تعریف نشده، پس نصب نمی‌شود) |
| 15 | cron بکاپ شبانه |
| 16 | نصب کارهای زمان‌بندی‌شده (systemd timer) و ادامه دادن آن‌ها (بخش ۸). از قبل از checkout در مرحله‌ی 7 تا این مرحله متوقف هستند |
| 17 | ثبت Webhook تلگرام با رمز در URL **و** `secret_token` (هدر `X-Telegram-Bot-Api-Secret-Token`) |
| 18 | بررسی نهایی: `APP_ENV=prod`، `APP_DEBUG=0` (یعنی میان‌برهای تست پلاگین‌ها مثل auto-confirm دموی پرداخت خاموش است)، فعال بودن همه‌ی timerها و `https://DOMAIN/healthz` = 200 |

گزینه‌ها:

```bash
sudo amoobot-deploy --dry-run          # فقط نمایش؛ هیچ تغییری نمی‌دهد و رمزها را چاپ نمی‌کند
sudo amoobot-deploy --app-only         # بدون مراحل 1 تا 6 (برای به‌روزرسانی سریع کد)
sudo amoobot-deploy --ref v1.4.0       # branch یا tag روی origin، یا hash کامل ۴۰ کاراکتری کامیتی که در یکی از آن‌هاست (برای Rollback هم)
sudo amoobot-deploy --env-file /etc/amoobot/other.env   # فایل تنظیمات دیگر (پیش‌فرض /etc/amoobot/.env.prod.local)
```

`--ref` (و `DEPLOY_REF`) فقط نام ref معتبر را می‌پذیرد: `^[A-Za-z0-9._/-]+$`، بدون `-` در ابتدا و بدون `..`. هر چهار دستور `amoobot-*`
(از جمله `amoobot-deploy`) اسکریپت را با محیط تمیز (`env -i`) اجرا می‌کنند.

`--env-file` (با root، حتی با `--dry-run`) فقط فایلی **مستقیماً داخل `/etc/amoobot/`** را قبول می‌کند: فایل معمولی (نه symlink)، مالک root با
دسترسی `600`، در پوشه‌ای که مالک آن root است و برای گروه/دیگران قابل نوشتن نیست. فایل یک‌بار باز می‌شود و همان فایل بازشده (از طریق
`/proc/self/fd`) بررسی و خوانده می‌شود، پس نمی‌توان آن را بین بررسی و خواندن عوض کرد.

---

## ۲. اولین استقرار

**پیش‌نیاز:** سرور تازه‌ی Ubuntu 24.04، دسترسی root، و رکورد DNS از نوع A برای دامنه که به IP سرور اشاره کند (بدون آن گواهی HTTPS صادر نمی‌شود). پورت‌های TCP 80 و 443 باید از اینترنت باز باشند. فقط با `SSH_HARDENING=yes` یا `FIREWALL=yes` لازم است SSH روی پورت 22 باشد.

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

مقادیر اجباری: `DOMAIN`، `DB_PASSWORD`، `APP_SECRET`، `TELEGRAM_BOT_TOKEN`، `TELEGRAM_WEBHOOK_SECRET`، `TELEGRAM_WEBHOOK_SECRET_TOKEN`، `TELEGRAM_ADMIN_CHAT_ID`. `DEPLOY_SSH_PUBLIC_KEY` (یک خط از فایل `.pub` خودتان) را هم پر کنید تا
بتوانید با کاربر `deploy` وارد شوید. `SSH_HARDENING`، `SSH_HARDENING_REVERT` و `FIREWALL` اختیاری‌اند و پیش‌فرض همه `no` است (بخش بعد).

### SSH، فایروال و جلوگیری از قفل شدن (مرحله‌ی 5)

**سخت‌سازی SSH و فایروال اختیاری و به‌طور پیش‌فرض خاموش است.** با پیش‌فرض (`SSH_HARDENING=no`، `FIREWALL=no`) اسکریپت هیچ فایلی در
`/etc/ssh/sshd_config.d/` نمی‌نویسد، ورود با پسورد و `PermitRootLogin` را تغییر نمی‌دهد، ssh را reload نمی‌کند، ufw را فعال یا reset نمی‌کند و
قانون‌های موجود آن را تغییر نمی‌دهد. برای هر کدام از این کارها در خروجی (و در `--dry-run`) یک خط `skipped (SSH_HARDENING=no)` یا
`skipped (FIREWALL=no)` چاپ می‌شود و پورت‌هایی که برنامه لازم دارد (TCP 80 و 443، به‌علاوه‌ی پورت SSH خودتان) یادآوری می‌شوند؛ اگر ufw فعال
باشد و 80/443 را باز نگذاشته باشد هشدار داده می‌شود (فقط خواندن، بدون تغییر). کاربر `deploy`، کلید آن و قانون sudoers همیشه ساخته
می‌شوند؛ این‌ها روی نحوه‌ی ورود root اثری ندارند.

- **برگرداندن سخت‌سازی قبلی:** اگر یک deploy قبلی (پیش از این تغییر یا با `SSH_HARDENING=yes`) فایل
  `/etc/ssh/sshd_config.d/00-amoobot-hardening.conf` را نصب کرده باشد، اجرا با `SSH_HARDENING=no` آن را **گزارش** می‌کند ولی حذف نمی‌کند.
  با `SSH_HARDENING_REVERT=yes` فایل حذف می‌شود: ابتدا `sshd -t` بدون آن اجرا می‌شود و فقط اگر قبول شد ssh reload می‌شود؛ در غیر این صورت
  فایل دقیقاً برگردانده و deploy متوقف می‌شود. بعد از آن ورود با پسورد و `PermitRootLogin` از بقیه‌ی تنظیمات sshd سرور می‌آیند. فقط فایلی
  حذف می‌شود که خط `# Managed by amoobot deploy.sh` را داشته باشد.
- **`FIREWALL=yes`:** `ufw default deny incoming`، فقط 22/80/443 و `ufw --force enable`. اگر SSH روی پورت دیگری گوش دهد، قبل از هر دستور
  ufw متوقف می‌شود (وگرنه دسترسی SSH قطع می‌شد).

موارد زیر **فقط با `SSH_HARDENING=yes`** انجام می‌شوند (همه‌ی محافظت‌های ضدقفل‌شدن بدون تغییر):

- `DEPLOY_SSH_PUBLIC_KEY` با `ssh-keygen -l` بررسی می‌شود؛ مقدار نامعتبر (یا چند خط، یا کلید خصوصی) در مرحله‌ی 0 متوقف می‌کند.
- کلیدهای `/root/.ssh/authorized_keys` **همیشه** به `~deploy/.ssh/authorized_keys` اضافه می‌شوند (فقط کلیدهایی که هنوز نیستند)،
  حتی اگر `deploy` از قبل کلید داشته باشد. کلیدی که `command="..."` دارد (مثلاً «Please login as ubuntu» در ایمیج‌های ابری) ورود حساب نمی‌شود.
- `/etc/sudoers.d/amoobot-deploy` (مالک root، `440`، قبل از نصب با `visudo -cf` بررسی می‌شود) به `deploy` فقط اجازه‌ی اجرای بدون پسورد
  `amoobot-deploy`، `amoobot-backup`، `amoobot-restore` و `amoobot-check-jobs` را می‌دهد، **نه** `ALL`.
- `PasswordAuthentication no` (و `SSH_PERMIT_ROOT_LOGIN=no` اگر انتخاب شده) فقط وقتی نوشته می‌شود که: کاربر `deploy` shell دارد، حداقل یک
  کلید معتبر بدون `command=` دارد، مالکیت و دسترسی home، `.ssh` و `authorized_keys` با StrictModes سازگار است، و `sudo -l -U deploy`
  اجازه‌ی `amoobot-deploy` را نشان می‌دهد، **و** پیکربندی مؤثر sshd برای همین کاربر (`sshd -T -C user=deploy,host=localhost,addr=127.0.0.1`)
  او را راه می‌دهد: در `AllowUsers`/`AllowGroups` هست (اگر تعریف شده باشند)، در `DenyUsers`/`DenyGroups` نیست، بلوک `Match` آن را نمی‌بندد و
  `AuthorizedKeysFile` شامل `.ssh/authorized_keys` است. پورتی که SSH **واقعاً** روی آن گوش می‌دهد هم باید 22 باشد: وقتی `ssh.socket` فعال است
  (Ubuntu 24.04) مقدار `systemctl show ssh.socket -p Listen` ملاک است، نه فقط `Port` در sshd_config. وگرنه deploy با فهرست دلیل‌ها **متوقف
  می‌شود** (dry-run همین را به‌صورت WARNING نشان می‌دهد). پیش‌فرض `PermitRootLogin prohibit-password` است (root فقط با کلید).
- **`SSH_PERMIT_ROOT_LOGIN=no`** فقط وقتی پذیرفته می‌شود که حساب دیگری (غیر از `deploy`) با کلید وارد شود و sudo کامل داشته باشد
  (`sudo -l -U <user>` باید `(ALL) ALL` یا `(ALL : ALL) ALL` نشان دهد؛ اگر `NOPASSWD` نیست، پسورد قابل استفاده هم لازم است)، یا
  `I_HAVE_CONSOLE_ACCESS=yes` تنظیم شده باشد (کنسول سرویس‌دهنده با پسورد root). `deploy` فقط چهار دستور `amoobot-*` را دارد و با آن نمی‌توان
  مثلاً فایل تنظیمات را ویرایش کرد.
- محدودیت: بلوک‌های `Match Address`/`Match Host` برای آدرس واقعی شما قابل ارزیابی نیستند (بررسی با `addr=127.0.0.1` است).
- فایل `/etc/ssh/sshd_config.d/00-amoobot-hardening.conf` نصب و سپس با `sshd -t` و دوباره با `sshd -T -C user=deploy` (اینکه `pubkeyauthentication`
  برای `deploy` واقعاً `yes` است و چیزی او را نمی‌بندد) بررسی می‌شود؛ اگر رد شود، **قبل از توقف** نسخه‌ی قبلی
  برگردانده (یا فایل جدید حذف) می‌شود و ssh دوباره بارگذاری نمی‌شود. در Ubuntu 24.04 اگر `ssh.service` در حال اجرا باشد reload می‌شود؛
  اگر فقط `ssh.socket` فعال باشد sshd هنوز اجرا نشده و اتصال بعدی تنظیمات جدید را می‌خواند.

> **توجه:** این اسکریپت نمی‌تواند یک ورود واقعی SSH را امتحان کند (کلید خصوصی شما را ندارد). قبل از بستن نشست فعلی حتماً در ترمینال دیگر
> `ssh deploy@SERVER` و `sudo amoobot-deploy --dry-run` را امتحان کنید. کاربر `deploy` با `amoobot-deploy` می‌تواند هر branch، tag یا
> commitی را که **روی origin (GitHub) وجود دارد** deploy کند و اسکریپت‌های عملیاتی آن commit با root اجرا می‌شوند. پس `deploy` دقیقاً تا جایی
> معادل root است که **دسترسی نوشتن روی مخزن GitHub** هست: دسترسی نوشتن GitHub (و کلیدهای امضای `REQUIRE_SIGNED_TAG`) را مثل root محافظت
> کنید. هیچ چیزی که `deploy` روی خود سرور تغییر دهد (checkout یا `.git` آن) با root نصب یا اجرا نمی‌شود (بخش ۳، «مدل اعتماد»).

```bash
# 2) اسکریپت را به سرور بیاورید و ابتدا dry-run بگیرید
scp deploy.sh root@SERVER:/root/deploy.sh
ssh root@SERVER 'bash /root/deploy.sh --dry-run'

# 3) اجرای واقعی
ssh root@SERVER 'bash /root/deploy.sh'
```

**اولین اجرا در مرحله‌ی 7 عمداً متوقف می‌شود** (قبل از متوقف کردن هر چیزی) و کلید عمومی deploy key متعلق به root
(`/etc/amoobot/repo_deploy_ed25519.pub`) را چاپ می‌کند: GitHub ← مخزن ← Settings ← Deploy keys ← Add deploy key. کلید را بچسبانید و
**Allow write access را تیک نزنید**. سپس اسکریپت را دوباره اجرا کنید. کاربر `deploy` دیگر هیچ کلیدی برای GitHub لازم ندارد.

**کلید میزبان GitHub:** اسکریپت به اولین کلیدی که سرور SSH نشان می‌دهد اعتماد نمی‌کند (`accept-new` حذف شده). کلیدهای منتشرشده‌ی GitHub
در هر deploy از `https://api.github.com/meta` (فقط HTTPS با گواهی معتبر) گرفته، با `ssh-keygen` بررسی و در
`/etc/ssh/amoobot_github_known_hosts` (مالک root، `644`) نوشته می‌شوند؛ git با `StrictHostKeyChecking=yes` و همین فایل کار می‌کند.
اگر GitHub در دسترس نباشد فایل قبلی نگه داشته می‌شود؛ اگر فایلی نباشد deploy متوقف می‌شود. برای مخزنی غیر از github.com کلید میزبان
خودکار گرفته نمی‌شود: اثر انگشت را از مستندات سرویس‌دهنده بگیرید و کلید تأییدشده را در `/etc/amoobot/repo_known_hosts` (مالک root) بگذارید.

**نسخه‌ی Composer:** نصب‌کننده با SHA-384 منتشرشده بررسی می‌شود، اما آخرین نسخه‌ی پایدار Composer را نصب می‌کند. برای ثابت نگه داشتن
نسخه، در مرحله‌ی 4 به فراخوانی `composer-setup.php` گزینه‌ی `--version=<2.x.y>` را اضافه کنید و آگاهانه به‌روز کنید؛ Composer موجود دست
نمی‌خورد (`sudo composer self-update <2.x.y>`). وابستگی‌های PHP با `composer.lock` ثابت هستند.

```bash
# 4) ساخت ادمین پنل و داده‌های اولیه
cd /var/www/amoobot
sudo -u www-data php bin/console app:admin:create-user --env=prod
sudo -u www-data php bin/console app:settings:seed --env=prod
sudo -u www-data php bin/console app:bot-content:seed --env=prod
sudo -u www-data php bin/console app:payment:create-default-gateways --env=prod
```

**بررسی بعد از deploy (فقط‌خواندنی):** با root `sudo /usr/local/lib/amoobot/post-deploy-check.sh` (نسخه‌ی root‌مالک از آینه؛ نسخه‌ی
`bin/` در checkout را با root اجرا نکنید). برای هر بررسی یک خط `PASS`/`FAIL`/`SKIP` چاپ می‌کند، هیچ رمزی چاپ نمی‌کند و فقط اگر همه `PASS`
باشند کد خروج `0` می‌دهد: `APP_ENV`/`APP_DEBUG` واقعی برنامه، `healthz` روی HTTPS، ریدایرکت HTTP، روزهای باقی‌مانده‌ی گواهی، `403` برای
Webhook بدون هدر و با رمز URL اشتباه، `getWebhookInfo` (URL ماسک‌شده، بدون `last_error_message`، صف کوچک)، کارهای زمان‌بندی‌شده، بکاپ شبانه
(تازه و `600`)، ufw، `sshd -T` (با `FIREWALL=no` / `SSH_HARDENING=no` فقط یک خط `INFO` با وضعیت فعلی، که در نتیجه شمرده نمی‌شود؛
و `FAIL` فقط وقتی ufw فعال است و 80/443 را بسته)، `sql_mode`، دسترسی‌های `/etc/amoobot`، منطقه‌ی زمانی PHP (`UTC` برای CLI و FPM)، `nginx -t`، نبود رشته‌ی شبیه رمز در لاگ‌های nginx، خاموش بودن
حالت نگهداری و نبود فایل `deploy-in-progress`. یک خط `INFO` هم برای دفتر کیف پول چاپ می‌کند (`app:wallet:reconcile` با کاربر وب؛ اگر مغایرتی پیدا شود `WARNING` می‌گوید، اما در نتیجه شمرده نمی‌شود و چیزی را تغییر نمی‌دهد). با کاربر `deploy` (`bin/post-deploy-check.sh`) بررسی‌هایی که root لازم دارند `SKIP`
می‌شوند و نتیجه `INCOMPLETE` (کد `1`) است. ترتیب کامل اولین استقرار: [first-deploy-checklist.md](first-deploy-checklist.md).

**تست دود Webhook:** `sudo /usr/local/lib/amoobot/smoke-webhook.sh 999000001` (یک id **جعلی** که مال هیچ کس نیست؛ id ادمین پذیرفته نمی‌شود)
یک به‌روزرسانی ساختگی `/start` را، دقیقاً مثل تلگرام و با هدر `X-Telegram-Bot-Api-Secret-Token`، به nginx محلی می‌فرستد و کد HTTP و ثبت آن در
`bot_message_log` را گزارش می‌کند. رمز URL و توکن هدر از فایل root‌مالک خوانده می‌شوند و چاپ نمی‌شوند و روی خط فرمان `curl` نمی‌آیند
(`--dry-run` فقط payload و URL ماسک‌شده را نشان می‌دهد). این فقط مسیر nginx ← PHP-FPM ← Symfony ← کنترلر Webhook را ثابت می‌کند: برنامه برای
id جعلی یک حساب بات می‌سازد و پاسخ بات به آن چت در تلگرام تحویل نمی‌شود (طبیعی است). **تست نهایی** ارسال واقعی `/start` از حساب تلگرام ادمین است.

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
- **مدل اعتماد: root هیچ چیزی را از مخزنی که کاربر `deploy` کنترل می‌کند نصب یا اجرا نمی‌کند.** کاربر `deploy` مالک
  `/var/www/amoobot` **و `.git` آن** است، پس می‌تواند هم فایل‌ها و هم آبجکت‌ها و refهای git آن را بسازد یا عوض کند. به همین دلیل:
  - root مخزن را خودش، با کلید فقط‌خواندنی `/etc/amoobot/repo_deploy_ed25519` (مالک root، `600`) و کلید میزبان pin‌شده، در آینه‌ی bare
    `/var/lib/amoobot/mirror.git` (مالک root، `700`) می‌گیرد؛ فقط branchهای origin (به‌صورت `refs/remotes/origin/*`) و tagها.
  - `--ref` فقط این‌ها را قبول می‌کند: branch روی origin، tag روی origin، یا hash کامل ۴۰ کاراکتری که یکی از branchها یا tagهای origin آن را
    شامل شود. hash کوتاه، کامیتی که فقط در checkout کاربر `deploy` ساخته شده و آبجکتی که هیچ ref از origin به آن نمی‌رسد (مثلاً بعد از
    force-push) **رد می‌شوند**.
  - `deploy.sh`، اسکریپت‌های بکاپ/بازگردانی، اسکریپت‌های کارهای زمان‌بندی‌شده و `bin/lib/` با `git --git-dir=<آینه> cat-file blob <کامیت>:<مسیر>`
    در `/usr/local/lib/amoobot` (مالک root، `755`/`644`) نصب می‌شوند؛ فهرست migrationهای کامیت مقصد هم از آینه خوانده می‌شود (بخش ۴).
  - checkout برنامه از یک bundle همان کامیت (در `/run/amoobot-deploy`، فقط برای گروه `deploy` خواندنی) با کاربر `deploy` پر می‌شود؛ root
    هیچ‌وقت داخل مخزن کاربر `deploy` دستور git اجرا نمی‌کند و `deploy` دیگر کلید GitHub ندارد. کلید قدیمی `~deploy/.ssh/amoobot_deploy_ed25519`
    را از Deploy keys در GitHub پاک کنید.
  - **نتیجه:** کاربر `deploy` می‌تواند هر ref موجود روی origin را deploy کند؛ این دقیقاً به اندازه‌ی دسترسی نوشتن روی مخزن GitHub معادل root
    است. دسترسی نوشتن GitHub را مثل root محافظت کنید، یا `REQUIRE_SIGNED_TAG=yes` را فعال کنید (پایین‌تر).
  - **ارتقای یک سرور قدیمی به این نسخه:** `amoobot-deploy` نصب‌شده هنوز نسخه‌ی قبلی است و این نسخه را یک‌بار به روش قدیم (از checkout) نصب
    می‌کند. اگر احتمال می‌دهید checkout دستکاری شده باشد، ارتقا را مثل اولین نصب از یک کپی root‌مالک و بررسی‌شده انجام دهید:
    `scp deploy.sh root@SERVER:/root/deploy.sh` و `sudo bash /root/deploy.sh --app-only`. بعد از آن rollback به کامیت‌های قبل از آینه رد می‌شود.

  ورودی‌ها:

  | دستور | کار |
  |---|---|
  | `sudo amoobot-deploy [گزینه‌ها]` | همان `deploy.sh` (نسخه‌ی نصب‌شده از آخرین deploy) |
  | `sudo amoobot-backup [برچسب]` | بکاپ دستی (پیش‌فرض برچسب `manual`) |
  | `sudo amoobot-restore <فایل> --yes` | بازگردانی (بخش ۵) |
  | `sudo amoobot-check-jobs` | بررسی تازگی کارها (بخش ۸) |

  هر چهار فایل در `/usr/local/sbin` و مالک root هستند و اسکریپت را با محیط تمیز (`env -i`) اجرا می‌کنند. cron بکاپ، بکاپ قبل از
  migration و unitهای systemd هم فقط همین مسیرها را اجرا می‌کنند. اسکریپت‌های بکاپ/بازگردانی `DATABASE_URL` را فقط از
  `/etc/amoobot/database.env` (مالک root، `600`، ساخته‌شده در مرحله‌ی 9) می‌خوانند و هیچ‌وقت `.env.local.php` برنامه را به‌عنوان root بارگذاری نمی‌کنند.
- **حالت نگهداری برای Webhook و پرداخت:** از لحظه‌ی توقف کارها (قبل از checkout) تا پایان migrationها و `lint:container`، nginx برای
  `/telegram/webhook/` و `/payment/` پاسخ `503` با `Retry-After: 120` می‌دهد (فایل `/etc/nginx/snippets/amoobot-maintenance.conf` که در
  همان دو location با `include` آمده است). تلگرام و درگاه‌ها درخواست را دوباره می‌فرستند، پس آپدیت‌ها به کد نیمه‌کاره نمی‌رسند. بقیه‌ی سایت
  عوض نمی‌شود. با موفقیت مرحله‌ی 11 همیشه برداشته می‌شود؛ اگر deploy وسط کار شکست بخورد **باقی می‌ماند** و اسکریپت دستور برداشتن آن را چاپ
  می‌کند. توجه: کاربری که دقیقاً در همین چند دقیقه از صفحه‌ی پرداخت برمی‌گردد، صفحه‌ی خطای 503 می‌بیند و باید صفحه را دوباره باز کند.
  در اولین deploy بعد از این تغییر (و اولین نصب) پیکربندی قبلی nginx هنوز این include را ندارد، پس این حالت از deploy بعدی فعال است.
- اگر migration اجرانشده وجود داشته باشد **و** دیتابیس از قبل جدول داشته باشد، قبل از migration خودکار یک بکاپ با برچسب `pre-deploy` در
  `/var/backups/amoobot` گرفته می‌شود (مستقل از اینکه قبلاً چه کامیتی deploy شده؛ مثلاً سروری که از dump بازگردانی شده هم بکاپ می‌گیرد).
  روی دیتابیس خالی (اولین نصب) بکاپی لازم نیست.
- **schema جلوتر از کد:** قبل از متوقف کردن کارها و قبل از checkout، نسخه‌های اجراشده در جدول `doctrine_migration_versions` (با root و
  `/etc/amoobot/database.env`) با فهرست `migrations/Version*.php` کامیت مقصد (از آینه‌ی root‌مالک) مقایسه می‌شوند. اگر دیتابیس migrationی
  داشته باشد که کامیت مقصد ندارد (rollback به نسخه‌ی قدیمی‌تر از schema)، deploy **رد می‌شود** و نسخه‌ی در حال اجرا دست نمی‌خورد؛ نسخه‌های
  ناشناخته و راه بازگردانی یک بکاپ سازگار چاپ می‌شوند. اگر دیتابیس خوانده نشود هم deploy قبل از هر توقفی متوقف می‌شود.
- خط دفاع دوم: بعد از migration، `doctrine:migrations:up-to-date --fail-on-unregistered` اجرا می‌شود. کدهای خروج (بررسی‌شده در
  `doctrine/migrations` 3.9.7، `UpToDateCommand`): `0` به‌روز، `1` migration اجرانشده، `2` migrationهای اجراشده‌ای که کد نمی‌شناسد (فقط با
  `--fail-on-unregistered`؛ اگر هر دو حالت باشد `2`؛ بدون این گزینه همین حالت `0` برمی‌گرداند). با `1` یا `2` deploy **قبل از فعال کردن دوباره‌ی
  timerها** متوقف می‌شود و دستور دقیق بازگردانی بکاپ `pre-deploy` را چاپ می‌کند.
- **root از symlinkهای کاربر `deploy` یا `www-data` پیروی نمی‌کند.** `~deploy/.ssh/authorized_keys`، `.env.prod.local` و `.env.local` را خود
  کاربر `deploy` می‌نویسد (فایل موقت و `mv`، پس symlink جایگزین می‌شود نه دنبال)، پوشه‌های `var/` و `public/bundles` را مالک خودشان می‌سازد و
  `chmod` می‌کند و root فقط `chown -h` و `chown -R -h -P` اجرا می‌کند. اگر `~deploy/.ssh`، `authorized_keys`، `var/`، `public/`،
  `public/bundles` یا `.env.local.php` symlink باشد deploy **متوقف می‌شود**.
- **منطقه‌ی زمانی PHP همیشه `UTC` است.** مرحله‌ی 18 فایل `/etc/php/<نسخه>/{fpm,cli}/conf.d/99-amoobot-timezone.ini` با `date.timezone = UTC`
  را می‌نویسد و بعد از restart مقدار مؤثر را برای CLI (`php -r`) و FPM (`php-fpm<نسخه> -i` و overrideهای `pool.d`) بررسی می‌کند؛ خود برنامه هم در
  boot کرنل `UTC` را تنظیم می‌کند. رفتار عوض نمی‌شود: قبلاً هم چون هیچ php.ini مقداری نداشت، پیش‌فرض PHP یعنی `UTC` بود. زمان‌هایی که به کاربر
  نشان داده می‌شوند (پیام‌های بات، ستون‌های تاریخ پنل ادمین، کد پیگیری سفارش) هم مثل قبل `UTC` هستند، نه ساعت ایران.
- مقادیری که در فایل‌های root (cron، unitها، nginx، فایل option دیتابیس) نوشته می‌شوند قبل از هر کاری بررسی می‌شوند: `BACKUP_CRON` فقط پنج
  فیلد از `0-9 * / , -` یا `@daily`/`@hourly`/`@weekly`؛ `PHP_VERSION` مثل `8.4`؛ `REPO_URL` فقط `git@...` یا `ssh://...` بدون فاصله، کوتیشن یا
  کاراکتر کنترلی؛ نام کاربر و میزبان دیتابیس بدون کاراکتر کنترلی، `"` یا `\`، و پسورد بدون کاراکتر کنترلی.
- مسیرها قبل از هر کاری بررسی می‌شوند: `APP_DIR` باید `/var/www/<نام>` یا `/srv/<نام>`، `BACKUP_DIR` باید `/var/backups/<نام>` یا
  `/srv/<نام>` و home کاربر deploy باید `/home/<نام>` باشد (این مسیرها با `chown -R`/`chmod -R`/`rm` استفاده می‌شوند).
- `apt-get upgrade` غیرتعاملی است و فایل‌های پیکربندی تغییرکرده‌ی محلی (مثلاً sshd) را نگه می‌دارد (`--force-confdef --force-confold`).
- اگر فایل‌های tracked روی سرور دستی تغییر کرده باشند، اسکریپت متوقف می‌شود (هیچ تغییری روی سرور دور ریخته نمی‌شود).
- اجرای کامل (بدون `--app-only`) بسته‌های سیستم را هم به‌روز می‌کند؛ هر چند وقت یک‌بار در زمان کم‌ترافیک انجام دهید.

### فقط tagهای امضاشده (`REQUIRE_SIGNED_TAG=yes`، اختیاری)

با این تنظیم `amoobot-deploy` فقط **tag annotated و امضاشده** را قبول می‌کند که امضای آن با `git verify-tag` در برابر
`/etc/amoobot/allowed_signers` (مالک root، غیرقابل نوشتن برای گروه/دیگران) معتبر باشد؛ branch، hash، tag سبک (lightweight) و tag بدون امضا یا با
کلید دیگر رد می‌شوند. آن‌وقت حتی کسی که دسترسی نوشتن GitHub دارد بدون کلید امضا نمی‌تواند چیزی را با root اجرا کند.

```bash
# روی لپ‌تاپ امضاکننده (کلید SSH جداگانه، ترجیحاً روی کلید سخت‌افزاری):
git config gpg.format ssh && git config user.signingkey ~/.ssh/amoobot_release_ed25519.pub
git tag -s v1.6.0 -m "release v1.6.0" && git push origin v1.6.0
# روی سرور (یک‌بار؛ فرمت allowed signers گیت):
echo 'release@example namespaces="git" ssh-ed25519 AAAA...' | sudo tee /etc/amoobot/allowed_signers > /dev/null
sudo chown root:root /etc/amoobot/allowed_signers && sudo chmod 644 /etc/amoobot/allowed_signers
# در /etc/amoobot/.env.prod.local:  REQUIRE_SIGNED_TAG=yes   و   DEPLOY_REF=v1.6.0
sudo amoobot-deploy --app-only --ref v1.6.0
```

### Nginx، HTTPS و محافظت از پنل ادمین

- **قبل از گواهی:** پیکربندی موقت پورت 80 فقط چالش ACME (`/.well-known/acme-challenge/`، پوشه‌ی `/var/www/letsencrypt`) را جواب می‌دهد و هر
  مسیر دیگر `503` است؛ برنامه هیچ‌وقت روی HTTP ساده سرو نمی‌شود.
- **IPv6:** اگر کرنل IPv6 دارد (`/proc/net/if_inet6`)، `listen [::]:80` و `listen [::]:443 ssl` هم اضافه می‌شوند. برای رکورد AAAA لازم است.
- **HSTS:** `Strict-Transport-Security: max-age=31536000` (بدون `includeSubDomains`) فقط وقتی فرستاده می‌شود که مرحله‌ی 18 یک‌بار
  `https://DOMAIN/healthz` را با گواهی معتبر با موفقیت گرفته باشد (فایل وضعیت `/var/lib/amoobot/hsts-enabled`). بعد از آن مرورگرها یک سال
  فقط با HTTPS وصل می‌شوند؛ اگر دامنه را عوض می‌کنید یا HTTPS را برمی‌دارید این را در نظر بگیرید.
- **Webhook در access log نیست:** location `^~ /telegram/webhook/` با `access_log off` و مستقیم به PHP-FPM (بدون try_files، تا
  redirect داخلی به `index.php` دوباره لاگ نشود). رمز URL پس در `/var/log/nginx/amoobot_access.log` نمی‌آید. خطاهای upstream (مثلاً PHP-FPM
  خاموش یا timeout) در سطح `error` با خط درخواست، یعنی **با رمز URL**، لاگ می‌شوند؛ برای همین این location فایل خطای جداگانه
  `/var/log/nginx/amoobot_webhook_error.log` فقط با سطح `crit` دارد. فایل‌های لاگ سایت با دسترسی `640` (`www-data:adm`) ساخته یا اصلاح می‌شوند.
- **HSTS در حالت نگهداری:** `add_header` داخل یک location همه‌ی `add_header`های سطح server را حذف می‌کند، پس فایل حالت نگهداری (وقتی HSTS
  فعال شده است) هدر `Strict-Transport-Security` را هم تکرار می‌کند.
- **ورود ادمین:** سیمفونی `login_throttling` (۵ تلاش ناموفق در ۱۵ دقیقه برای هر ایمیل+IP، و ۲۵ برای هر IP) و nginx
  `limit_req` برای `= /admin/login` (۱۰ درخواست در دقیقه برای هر IP، burst ۱۰، پاسخ `429`).
- **`ADMIN_ALLOW_IPS` (اختیاری):** فهرست IP یا CIDR (IPv4/IPv6، جدا با کاما). اگر پر باشد فقط همین‌ها به `/admin`، `/admin/login` و `/admin/apps/downloads` دسترسی دارند
  (بقیه `403`). خالی (پیش‌فرض) یعنی بدون محدودیت IP. پشت CDN یا reverse proxy، nginx آدرس proxy را می‌بیند؛ در آن حالت این
  تنظیم و `limit_req` هر دو روی IP proxy کار می‌کنند، پس یا خالی بگذارید یا real_ip را جداگانه تنظیم کنید.

### دانلود برنامه‌ها (App downloads) و آپلودهای بزرگ

ادمین‌ها در «کاتالوگ ← برنامه‌ها ← دانلودها» فایل نصب برنامه‌ها (apk، exe، msi، zip، dmg، pkg، AppImage، deb) را آپلود می‌کنند. برای
فایل‌های بزرگ (مثلاً APKهای ۱۰۰ مگابایتی) سه محدودیت باید بالا برود؛ `deploy.sh` هر سه را با `APP_DOWNLOADS_MAX_MB` (پیش‌فرض ۲۰۰، حداکثر
۲۰۴۸) تنظیم می‌کند و هر بار اجرا همان نتیجه را می‌دهد (idempotent):

| محدودیت | مقدار | کجا |
|---|---|---|
| nginx `client_max_body_size` | `APP_DOWNLOADS_MAX_MB + 16`M، `client_body_timeout 300s`، `fastcgi_read_timeout 300s` | فقط `location ^~ /admin/apps/downloads` (بقیه‌ی سایت همان `50M`) |
| PHP `upload_max_filesize` | `APP_DOWNLOADS_MAX_MB`M | `/etc/php/<نسخه>/fpm/conf.d/98-amoobot-uploads.ini` |
| PHP `post_max_size` | `APP_DOWNLOADS_MAX_MB + 16`M (کل فرم؛ ۱۶ مگابایت برای بقیه‌ی فیلدها و قالب multipart) | همان فایل، به‌همراه `max_input_time = 300` |
| خود برنامه | `APP_DOWNLOADS_MAX_MB` | `AppDownloadStorage` (پیام خطای فرم) |

- این ini فقط برای FPM است (CLI آپلود نمی‌گیرد). مرحله‌ی ۱۸ پس از restart با `php-fpm<نسخه> -i` مقدار مؤثر را می‌خواند و اگر کمتر باشد یا یک pool در
  `pool.d` آن را با `php_value`/`php_admin_value` بازنویسی کرده باشد، استقرار را متوقف می‌کند.
- location آپلود مستقیم به PHP-FPM می‌رود (بدون try_files)؛ چون بعد از redirect داخلی به `index.php`، nginx حجم بدنه را دوباره با محدودیت
  `50M` همان location می‌سنجد و `413` می‌دهد.
- فایل‌ها خارج از `public/` در `var/downloads` (یا `APP_DOWNLOADS_DIR`) با نام تصادفی ذخیره و فقط از `/d/{id}` سرو می‌شوند (پشتیبانی Range برای
  ادامه‌ی دانلود، شمارنده‌ی دانلود، محدودیت ۶۰ درخواست در دقیقه برای هر IP). checkout در همان پوشه به‌روز می‌شود، پس این فایل‌ها بین استقرارها
  می‌مانند؛ ولی در بکاپ دیتابیس نیستند: اگر فایل آپلود می‌کنید (نه لینک خارجی)، این پوشه را جداگانه بکاپ بگیرید.
- اگر سایت پشت CDN است (مثلاً Cloudflare)، محدودیت حجم بدنه‌ی خود CDN هم باید بزرگ‌تر باشد (پلن رایگان Cloudflare: ۱۰۰ مگابایت).
- صفحه‌ی عمومی اشتراک `/s/{token}` و دانلود `/d/{id}` محدودیت نرخ برای هر IP دارند (`config/packages/rate_limiter.yaml`). پشت CDN یا reverse
  proxy بدون تنظیم real_ip، همه‌ی کاربران یک IP دیده می‌شوند و زودتر به محدودیت می‌خورند.

---

## ۴. Rollback

```bash
tail -n 5 /var/lib/amoobot/releases.log        # <زمان> <کامیت قبلی> -> <کامیت جدید> (ref)
sudo amoobot-deploy --app-only --ref <کامیت قبلی>
```

`<کامیت قبلی>` باید روی origin باشد (در یک branch یا tag؛ hash کامل ۴۰ کاراکتری). قبل از متوقف کردن کارها و قبل از checkout، اسکریپت
در آینه‌ی root‌مالک با `git cat-file -e <کامیت>:<مسیر>` بررسی می‌کند که کامیت مقصد همه‌ی فایل‌های عملیاتی (`deploy.sh`، اسکریپت‌های
بکاپ/بازگردانی/کارهای زمان‌بندی‌شده، `bin/lib/`، `bin/console`) را دارد و `deploy.sh` آن از `/usr/local/lib/amoobot` **و** آینه‌ی
`/var/lib/amoobot/mirror.git` استفاده می‌کند. وگرنه **رد می‌کند** و نسخه‌ی در حال اجرا دست نمی‌خورد. بنابراین rollback به کامیت‌های قبل از
آینه ممکن نیست (آن نسخه‌ها فایل‌های root را از checkout کاربر `deploy` نصب می‌کردند).

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
- مقصد: `/var/backups/amoobot` (مالک root، `700`)؛ بکاپ‌های **`nightly`** بعد از `BACKUP_RETENTION_DAYS` روز (پیش‌فرض 14) پاک می‌شوند.
  بکاپ‌های `pre-deploy`، `pre-restore` و `manual` راه برگشت از یک نسخه یا restore خراب هستند و **هیچ‌وقت خودکار پاک نمی‌شوند**؛
  هر چند وقت یک‌بار قدیمی‌ها را دستی پاک کنید (`ls -lt /var/backups/amoobot`).
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
قبل از هر کاری **رد می‌کند** اگر: فایل dump مستقیماً داخل `BACKUP_DIR` (`/var/backups/amoobot`، مسیر resolve‌شده) نباشد یا symlink باشد
(dump دیگری را اول با root به همان پوشه کپی کنید)؛ یا PHP-FPM یا هر `amoobot-job-*.timer`/`amoobot-job-*.service` هنوز فعال باشد (پس دستور
`systemctl stop` بالا الزامی است).

**بکاپ خارج از سرور:** بکاپی که فقط روی همان سرور باشد در برابر از دست رفتن سرور محافظتی ندارد. پوشه‌ی `/var/backups/amoobot` را به‌طور منظم به جای دیگری (مثلاً با `rsync` یا `rclone`) و به‌صورت رمزنگاری‌شده منتقل کنید.

**تمرین بازگردانی (هر ماه):** آخرین بکاپ را روی یک دیتابیس یا سرور آزمایشی restore کنید و `doctrine:migrations:up-to-date` را اجرا کنید. بکاپی که هیچ‌وقت restore نشده، بکاپ مطمئنی نیست.

---

## ۶. چرخش رمزها (Rotation)

همه‌ی موارد زیر با ویرایش `/etc/amoobot/.env.prod.local` و سپس `sudo amoobot-deploy --app-only` انجام می‌شوند (مقدار جدید را هیچ‌وقت
به‌صورت `sudo VAR=...` روی خط فرمان ندهید؛ هشدار بالای همین راهنما):

| رمز | روش | اثر جانبی |
|---|---|---|
| `TELEGRAM_BOT_TOKEN` | در @BotFather دستور `/revoke`، مقدار جدید را وارد کنید | Webhook با توکن جدید دوباره ثبت می‌شود |
| `TELEGRAM_WEBHOOK_SECRET` / `TELEGRAM_WEBHOOK_SECRET_TOKEN` | `openssl rand -hex 32` | Webhook دوباره ثبت می‌شود. چند ثانیه بین deploy و ثبت، آپدیت‌ها با 403 رد می‌شوند و تلگرام بعداً دوباره می‌فرستد |
| `APP_SECRET` | `openssl rand -hex 32` | نشست‌ها و remember-me ادمین‌ها باطل می‌شود |
| `DB_PASSWORD` | `openssl rand -hex 24` (فقط `A-Z a-z 0-9 . _ ~ -`) | اسکریپت در مرحله‌ی 6 پسورد کاربر MariaDB را هم عوض می‌کند. **بدون `--app-only` اجرا کنید** |
| کلیدهای درگاه پرداخت | در پنل ادمین ← درگاه‌ها | مستقل از deploy |
| API Token پنل 3x-ui | در 3x-ui: Settings → Security → API Token؛ در بات: پنل ← «ویرایش تنظیمات» ← «تست اتصال»؛ سپس توکن قبلی را در پنل باطل کنید ([panel-connection.md](panel-connection.md) بخش ۶) | مستقل از deploy. بعد از چرخش `APP_SECRET` هم باید توکن‌ها دوباره وارد شوند (رمزنگاری‌شده با کلید مشتق از آن) |
| deploy key گیت (root) | `sudo rm /etc/amoobot/repo_deploy_ed25519*`، اجرای deploy و ثبت کلید جدید در GitHub (کلید قبلی را حذف کنید) | deploy تا ثبت کلید، قبل از متوقف کردن چیزی، متوقف می‌شود |

بعد از هر چرخش: `curl -fsS https://DOMAIN/healthz` و یک پیام آزمایشی به بات.

### لاگ‌های قدیمی که ممکن است دسترسی مشتری را داشته باشند

نسخه‌های قبل از `fix/log-redaction` لینک اشتراک، لینک‌های کانفیگ (`vless://…`)، UUID کلاینت و subId را در لاگ‌ها و در جدول
`bot_message_log` (پیام‌های ارسالی بات) می‌نوشتند. هر کدام از این‌ها برای استفاده از سرویس مشتری کافی است. نسخه‌ی جدید فقط شناسه‌ی
سرویس/سفارش/پنل و ۴ کاراکتر آخر را لاگ می‌کند، اما فایل‌ها و ردیف‌های قدیمی خودبه‌خود پاک نمی‌شوند. **یک‌بار بعد از deploy** با root:

```bash
# ۱) دسترسی‌ها (همان چیزی که deploy.sh می‌سازد): فقط کاربر برنامه (www-data) لاگ کارها را می‌خواند
chown www-data:www-data /var/log/amoobot && chmod 750 /var/log/amoobot
find /var/log/amoobot -type f -exec chown www-data:www-data {} + -exec chmod 640 {} +
chmod 640 /var/log/nginx/amoobot_*.log*      # www-data:adm

# ۲) فایل‌های قدیمی: نسخه‌های چرخیده حذف، فایل‌های فعلی خالی
find /var/log/amoobot -type f -name '*.log.*' -delete
truncate -s 0 /var/log/amoobot/*.log
find /var/log/nginx -name 'amoobot_*.log.*' -delete
truncate -s 0 /var/log/nginx/amoobot_access.log /var/log/nginx/amoobot_error.log
find /var/log -maxdepth 1 -name 'php8.4-fpm.log.*' -delete && truncate -s 0 /var/log/php8.4-fpm.log
truncate -s 0 /var/www/amoobot/var/log/*.log 2>/dev/null || true   # worker.*.log اگر worker نصب است

# ۳) پیام‌های قدیمی بات (تاریخ deploy همین نسخه را بگذارید)
mariadb amoobot -e "DELETE FROM bot_message_log WHERE created_at < '2026-10-30 00:00:00'"

# ۴) بررسی: باید چیزی پیدا نشود
grep -rlE 'vless://|vmess://|trojan://|/sub/' /var/log/amoobot /var/log/nginx/amoobot_error.log /var/log/php8.4-fpm.log
journalctl -u 'amoobot-*' --since '-30d' | grep -cE 'vless://|vmess://|trojan://'
```

- اگر `journalctl` عددی بیشتر از ۰ نشان داد: `journalctl --rotate && journalctl --vacuum-time=1s` (این **همه‌ی** لاگ‌های journald
  سرور را پاک می‌کند، نه فقط amoobot).
- `amoobot_access.log` هنوز هم آدرس صفحه‌ی اشتراک (`/s/<token>`) را ثبت می‌کند؛ همان دسترسی `640` کافی است و این فایل را جایی کپی نکنید.
- بکاپ‌های دیتابیس قبل از این تاریخ هم `bot_message_log` قدیمی را دارند؛ با همان دقت فایل‌های بکاپ نگهداری یا حذف شوند.
- اگر لاگی بیرون از سرور رفته است (چت، تیکت)، برای سرویس‌های آن مشتری‌ها لینک صفحه‌ی اشتراک را در پنل ادمین عوض کنید
  («New subscription page link» در صفحه‌ی سرویس) و در صورت نیاز لینک اشتراک/UUID را در پنل 3x-ui بازسازی کنید.

---

## ۷. چک‌لیست Go-live

**امنیت**
- [ ] همه‌ی رمزهای لو رفته‌ی بخش ۰ عوض شده‌اند (توکن بات revoke شده است)
- [ ] `/etc/amoobot/.env.prod.local` با مالک root و دسترسی `600` (`stat -c '%a %U' /etc/amoobot/.env.prod.local` ← `600 root`)
- [ ] `git grep` در مخزن هیچ رمز واقعی‌ای پیدا نمی‌کند
- [ ] ورود `ssh deploy@SERVER` با کلید کار می‌کند (و فقط با `SSH_HARDENING=yes`: ورود با پسورد رد می‌شود)
- [ ] `sudo -l -U deploy` فقط چهار دستور `/usr/local/sbin/amoobot-*` را نشان می‌دهد
- [ ] فقط با `FIREWALL=yes`: `sudo ufw status` فقط 22، 80 و 443 را نشان می‌دهد؛ با `FIREWALL=no`: پورت‌های 80 و 443 در فایروال خودتان باز هستند
- [ ] deploy key در GitHub **بدون** دسترسی نوشتن است و فقط کلید root (`/etc/amoobot/repo_deploy_ed25519.pub`) ثبت است
- [ ] دسترسی نوشتن مخزن GitHub (و branch protection برای `main`) مثل دسترسی root محدود است؛ یا `REQUIRE_SIGNED_TAG=yes` فعال است

**برنامه**
- [ ] خروجی deploy بدون خطا تا «Amoobot deployment completed»
- [ ] `sudo -u www-data php /var/www/amoobot/bin/console about --env=prod` ← Environment `prod` و Debug `false`
- [ ] `curl -fsS https://DOMAIN/healthz` ← `{"status":"ok"}`
- [ ] `curl -I http://DOMAIN` ← ریدایرکت 301 به https
- [ ] `curl -sI https://DOMAIN/healthz | grep -i strict-transport` بعد از اولین deploy موفق، هدر HSTS را نشان می‌دهد
- [ ] `grep -c telegram/webhook /var/log/nginx/amoobot_access.log` ← `0`
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
- [ ] `sudo amoobot-check-jobs` دو ساعت بعد از deploy کد خروج `0` می‌دهد و `systemctl list-timers amoobot-check-jobs.timer` فعال است
- [ ] `MONITOR_PING_URL` تنظیم شده و در سرویس مانیتورینگ برای هر کار (و `backup`) ping تازه دیده می‌شود (بخش ۸، «هشدار بدون تلگرام»)
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
| قفل‌ها | `/run/amoobot/<گروه>.lock` (`sync`، `expiry`، `suspend`، `notifications` و `claims` در گروه `lifecycle` هستند و هم‌زمان اجرا نمی‌شوند) |

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
| `PAUSED` | deployی کمتر از ۳۰ دقیقه پیش شروع شده و کارها عمداً متوقف هستند (مشکل حساب نمی‌شود) |
| `DEPLOY` | فایل `/var/lib/amoobot/deploy-in-progress` بیش از ۳۰ دقیقه است که مانده: deploy شکست خورده یا قطع شده (یا سرور وسط deploy ریبوت شده) و کارها متوقف مانده‌اند |

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
- هنگام deploy، قبل از checkout فایل `/var/lib/amoobot/deploy-in-progress` (مالک root) نوشته می‌شود، timerهای `amoobot-job-*` متوقف می‌شوند و
  deploy صبر می‌کند تا کار در حال اجرا تمام شود. تا وقتی این فایل هست `scheduled-job.sh` **هیچ کاری را اجرا نمی‌کند** (`SKIPPED`، unit موفق،
  بدون ping). `amoobot-check-jobs.timer` **متوقف نمی‌شود**: در ۳۰ دقیقه‌ی اول کارها را `PAUSED` نشان می‌دهد و بعد از آن `DEPLOY` (کد خروج
  `2`، unit failed، هشدار تلگرام و ping به آدرس `/fail` با `{job}`=`deploy`). علاوه بر آن یک timer موقت
  `amoobot-deploy-watchdog` (`systemd-run --on-active=45min`) همان بررسی را ۴۵ دقیقه بعد از شروع اجرا می‌کند، پس حتی اگر نشست SSH قطع شود
  و کسی ترمینال را نبیند هشدار می‌رسد. watchdog خودش هیچ چیزی را ادامه نمی‌دهد. با پایان موفق، فایل حذف و watchdog لغو می‌شود.
  اگر deploy شکست بخورد، کارها **متوقف می‌مانند** و یک هشدار برجسته (`!!! SCHEDULED JOBS ARE PAUSED`) با دستور یک‌خطی ادامه چاپ می‌شود:
  ترجیحاً مشکل را رفع و `sudo amoobot-deploy --app-only` را اجرا کنید؛ یا اگر کد و schema با هم می‌خوانند، با root (اجرای دوباره بی‌خطر است):
  `rm -f /var/lib/amoobot/deploy-in-progress && systemctl stop amoobot-deploy-watchdog.timer; systemctl start 'amoobot-job-*.timer'`
- **ریبوت وسط deploy:** timerها enabled هستند و بعد از بوت شروع می‌شوند، اما چون فایل `deploy-in-progress` (در `/var/lib`، نه `/run`) باقی
  است هیچ کاری روی schema یا کد نیمه‌کاره اجرا نمی‌شود؛ قفل‌ها و watchdog موقت با ریبوت از بین می‌روند ولی `amoobot-check-jobs.timer` یک ساعت
  بعد از بوت `DEPLOY` گزارش می‌کند. فایل حالت نگهداری nginx هم می‌ماند، پس Webhook و پرداخت تا اجرای دوباره‌ی
  `sudo amoobot-deploy --app-only` پاسخ `503` می‌دهند (تلگرام و درگاه‌ها دوباره تلاش می‌کنند).

### هشدار بدون تلگرام

هشدار تلگرام به خود بات و سرور وابسته است؛ اگر سرور، شبکه یا توکن مشکل داشته باشد هیچ پیامی نمی‌رسد. دو لایه‌ی مستقل:

1. **`amoobot-check-jobs.timer` (root):** هر ساعت (اولین بار یک ساعت بعد از راه‌اندازی یا پایان deploy) `amoobot-check-jobs` را اجرا می‌کند؛
   هنگام deploy هم متوقف نمی‌شود. هر مشکلی (`STALE`، `NEVER`، `FAILED`، `INACTIVE`، `DEPLOY`، بکاپ قدیمی) کد خروج `2` است، پس unit در وضعیت **failed** می‌ماند
   (`systemctl --failed` و هر مانیتورینگی که unitهای failed را می‌بیند) و `amoobot-job-alert@` هم هشدار تلگرام می‌فرستد.
   اولین deploy اگر هنوز بکاپ `nightly` نباشد، همان لحظه یکی می‌گیرد تا این بررسی تا شب اول هشدار «backup NEVER» ندهد.
2. **Dead-man's switch با `MONITOR_PING_URL` (اختیاری، در `/etc/amoobot/.env.prod.local`):** هر اجرای **موفق** هر کار و بکاپ شبانه یک
   درخواست به این آدرس می‌فرستد (`curl` با timeout ۱۰ ثانیه؛ شکست ping نادیده گرفته می‌شود و کار را خراب نمی‌کند). سرویس بیرونی
   (healthchecks.io، Uptime Kuma «push»، Cronitor و…) وقتی pingها **قطع شوند** هشدار می‌دهد؛ یعنی وقتی سرور خاموش است، کارها شکست
   می‌خورند یا رد می‌شوند، یا timerها متوقف مانده‌اند. `{job}` در URL با نام کار جایگزین می‌شود (`broadcasts`، `orders`، `sync`،
   `expiry`، `suspend`، `notifications`، `backup`) تا هر کار check جدا داشته باشد، مثلاً `https://hc-ping.com/<ping-key>/amoobot-{job}`.
   بدون `{job}` همه یک check مشترک دارند و فقط توقف کامل دیده می‌شود. در سرویس مانیتورینگ برای هر check «period» را برابر
   فاصله‌ی اجرای کار (جدول بالا؛ بکاپ ۱ روز) و «grace» را حداقل timeout آن (و برای بکاپ چند ساعت) بگذارید.
   URL مثل رمز است (هر کس داشته باشد می‌تواند ping جعلی بفرستد): فقط در `/etc/amoobot/monitor.env` (مالک root، `600`) نوشته می‌شود،
   systemd آن را برای unitها می‌خواند، هیچ‌وقت چاپ نمی‌شود و روی خط فرمان `curl` نمی‌آید.

---

## ۹. عیب‌یابی

| نشانه | بررسی |
|---|---|
| توقف در مرحله‌ی 0 | پیام دقیقاً نام متغیر ناقص یا نامعتبر را می‌گوید (مقدارها چاپ نمی‌شوند) |
| توقف در مرحله‌ی 7 | deploy key در GitHub ثبت نشده است. کلید چاپ‌شده را اضافه کنید |
| خطای certbot | DNS هنوز به سرور اشاره نمی‌کند یا پورت 80 بسته است |
| `healthz` ← 503 | اتصال دیتابیس: `systemctl status mariadb`، و `/var/log/php8.4-fpm.log` و `/var/log/nginx/amoobot_error.log` (این شاخه monolog ندارد، پس خطاهای برنامه در لاگ PHP-FPM/nginx ثبت می‌شوند) |
| بات جواب نمی‌دهد | `app:telegram:webhook-info` (خصوصی؛ `last_error_message` مثلاً `403` یا `503` را نشان می‌دهد) و `/var/log/nginx/amoobot_error.log`. درخواست‌های `/telegram/webhook/` عمداً در access log **ثبت نمی‌شوند** (URL رمز را دارد)؛ error log ممکن است در خطاهای upstream آدرس را داشته باشد، پس آن را هم جایی کپی نکنید |
| `/admin/login` پاسخ 429 یا 403 می‌دهد | 429: محدودیت nginx (۱۰ درخواست در دقیقه برای هر IP، با burst ۱۰). پیام «Too many failed login attempts»: `login_throttling` سیمفونی (۵ تلاش ناموفق در ۱۵ دقیقه برای هر ایمیل+IP)؛ ۱۵ دقیقه صبر کنید. 403: IP شما در `ADMIN_ALLOW_IPS` نیست |
| خطای migration | deploy متوقف می‌شود (کارهای زمان‌بندی‌شده متوقف می‌مانند) و دستور بازگردانی بکاپ `pre-deploy` همین deploy را چاپ می‌کند. کد جدید روی سرور است اما دیتابیس ناقص. یا مشکل را رفع و دوباره deploy کنید، یا طبق بخش ۴ Rollback کنید |
| Webhook یا `/payment/` پاسخ 503 می‌دهد | deploy قبلی وسط کار شکست خورده و حالت نگهداری مانده است (پیام `still answer 503` در خروجی). مشکل را رفع و `sudo amoobot-deploy --app-only` را اجرا کنید، یا اگر نسخه‌ی فعلی سالم است با root: `printf '' > /etc/nginx/snippets/amoobot-maintenance.conf && nginx -t && systemctl reload nginx` |
| `up-to-date exit 2` | دیتابیس migrationهایی دارد که کد deploy‌شده ندارد (rollback به نسخه‌ی قدیمی‌تر از schema). نسخه‌ی جدیدتر را دوباره deploy کنید یا بکاپ `pre-deploy` مناسب را بازگردانی کنید (دستورها در خروجی deploy) |
| `Refusing to deploy <کامیت>` | کامیت مقصد یکی از فایل‌های عملیاتی را ندارد یا قدیمی‌تر از ساختار `/usr/local/lib/amoobot` است. چیزی تغییر نکرده؛ کامیت جدیدتری انتخاب کنید |
| هشدار «scheduled job FAILED» | `journalctl -u amoobot-job-<job> -n 50` و `/var/log/amoobot/<job>.log`. نتیجه‌ی `timeout` یعنی کار از `TimeoutStartSec` بیشتر طول کشیده (معمولاً پنل کند یا در دسترس نیست) |
| کاری هیچ‌وقت اجرا نمی‌شود | `systemctl list-timers 'amoobot-*'`. اگر deploy قبلی شکست خورده باشد، timerها متوقف مانده‌اند (هشدار `SCHEDULED JOBS ARE PAUSED`). یک deploy موفق اجرا کنید |
| `amoobot-check-jobs.service` failed | `journalctl -u amoobot-check-jobs -n 30` جدول وضعیت را نشان می‌دهد؛ همان مشکل را با `sudo amoobot-check-jobs` بررسی کنید. بعد از رفع: `sudo systemctl reset-failed amoobot-check-jobs` |
| پنل VPN خطا می‌دهد یا پلن‌هایش در بات دیده نمی‌شوند | پنل ادمین ← پنل ← «تست اتصال»، یا `sudo -u www-data php bin/console app:panel:doctor <id> --env=prod`؛ هر خطا با توضیح و راه حل ([panel-connection.md](panel-connection.md) بخش ۸). پنلی که ۳ بار پشت‌سرهم خطا بدهد «ناپایدار» می‌شود و پلن‌هایش تا اولین تماس موفق پنهان می‌مانند |
| هشدار نمی‌رسد | `systemctl status 'amoobot-job-alert@*'`: معمولاً `TELEGRAM_ADMIN_CHAT_ID` اشتباه است، یا بات در آن چت پیام نفرستاده/عضو نیست |
