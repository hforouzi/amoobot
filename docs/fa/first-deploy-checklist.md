# چک‌لیست اولین استقرار (سرور تازه‌ی Ubuntu 24.04)

این فهرست را **به ترتیب** و قدم‌به‌قدم انجام دهید و هر مورد را تیک بزنید. جزئیات و دلیل هر کار در [deployment.md](deployment.md) است.
در همه‌ی دستورها `SERVER` آدرس سرور و `bot.example.com` دامنه‌ی شماست.

> **قانون:** هیچ رمزی را روی خط فرمان، در چت یا در git ننویسید. رمزها فقط در `/etc/amoobot/.env.prod.local` (مالک root، `600`) هستند و
> همان‌جا روی خود سرور تولید می‌شوند. تا آخر این چک‌لیست با **بات تست** کار کنید، نه بات اصلی.

---

## ۱. پیش‌نیازها (قبل از ورود به سرور)

- [ ] سرور تازه‌ی Ubuntu 24.04 با ورود root **با کلید SSH**، و SSH روی پورت 22.
- [ ] رکورد DNS از نوع A برای دامنه به IP سرور (و AAAA اگر IPv6 دارید). بررسی از لپ‌تاپ:
  ```bash
  dig +short A bot.example.com      # باید IP سرور باشد
  ```
- [ ] یک **بات تست** جدا در @BotFather (توکن آن فقط موقت است و در آخر با توکن بات اصلی عوض می‌شود).
- [ ] شناسه‌ی عددی حساب تلگرام ادمین (`TELEGRAM_ADMIN_CHAT_ID`).
- [ ] یک خط کلید عمومی SSH خودتان برای کاربر `deploy` (`DEPLOY_SSH_PUBLIC_KEY`، محتوای فایل `.pub`).
- [ ] `REPO_URL` (پیش‌فرض `git@github.com:hforouzi/amoobot.git`) و دسترسی شما به **GitHub ← مخزن ← Settings ← Deploy keys**: در
  مرحله‌ی ۵ سرور یک کلید **root‌مالک** جدید (`/etc/amoobot/repo_deploy_ed25519.pub`) چاپ می‌کند که باید **فقط‌خواندنی** اضافه شود.
- [ ] کامیت یا tagی که deploy می‌شود روی GitHub است (`--ref` فقط branch/tag روی origin یا hash کامل کامیتی در آن‌ها را قبول می‌کند).

## ۲. فایل تنظیمات روی سرور

```bash
ssh root@SERVER
install -d -m 700 /etc/amoobot
curl -fsSL -o /etc/amoobot/.env.prod.local https://raw.githubusercontent.com/hforouzi/amoobot/main/.env.prod.example   # یا scp
chown root:root /etc/amoobot/.env.prod.local && chmod 600 /etc/amoobot/.env.prod.local
openssl rand -hex 32   # APP_SECRET (دوباره اجرا کنید برای TELEGRAM_WEBHOOK_SECRET و TELEGRAM_WEBHOOK_SECRET_TOKEN؛ هر سه متفاوت)
openssl rand -hex 24   # DB_PASSWORD
nano /etc/amoobot/.env.prod.local
```

- [ ] پر شده: `DOMAIN`، `DB_PASSWORD`، `APP_SECRET`، `TELEGRAM_BOT_TOKEN` (توکن **بات تست**)، `TELEGRAM_WEBHOOK_SECRET`،
  `TELEGRAM_WEBHOOK_SECRET_TOKEN`، `TELEGRAM_ADMIN_CHAT_ID`، `DEPLOY_SSH_PUBLIC_KEY`، و در صورت نیاز `REPO_URL`، `DEPLOY_REF`.
- [ ] `stat -c '%a %U' /etc/amoobot/.env.prod.local` ← `600 root`.

## ۳. اسکریپت را به‌صورت کپی root‌مالک بیاورید

از لپ‌تاپ، از همان کامیتی که deploy می‌کنید (نه از نسخه‌ی تغییرکرده‌ی محلی):

```bash
git show <کامیت-یا-tag>:deploy.sh > /tmp/deploy.sh && sha256sum /tmp/deploy.sh
scp /tmp/deploy.sh root@SERVER:/root/deploy.sh
ssh root@SERVER 'chown root:root /root/deploy.sh && chmod 700 /root/deploy.sh && sha256sum /root/deploy.sh'   # همان hash
```

## ۴. اول dry-run

```bash
ssh root@SERVER 'bash /root/deploy.sh --dry-run' 2>&1 | tee /tmp/amoobot-dry-run.txt
```

خروجی **باید** این‌ها را نشان دهد (و هیچ رمزی در آن نباشد):

- [ ] `Read <N> settings from /etc/amoobot/.env.prod.local (values not shown).` و خط `Domain: ...` با دامنه، `App dir`، `Ref` و `Deploy user` درست.
- [ ] `DRY RUN: nothing will be changed.` و در پایان `Amoobot deployment completed (dry run - nothing changed)` (کد خروج `0`).
- [ ] پیش‌نمایش sudoers فقط با چهار دستور `/usr/local/sbin/amoobot-{deploy,backup,restore,check-jobs}` و توضیح مدل اعتماد.
- [ ] `PermitRootLogin prohibit-password` (یا `no` اگر عمداً انتخاب کرده‌اید و ادمین دیگری با sudo کامل دارید).
- [ ] **هیچ** `WARNING: a real run would stop here` (اگر هست، دلیل‌های بالای آن را رفع کنید؛ معمولاً `DEPLOY_SSH_PUBLIC_KEY`).
- [ ] `fetch git@github.com:... AS ROOT into /var/lib/amoobot/mirror.git` و `resolve <ref> in the root-owned mirror ... before pausing anything`.
- [ ] جدول شش کار زمان‌بندی‌شده و خط cron بکاپ `30 3 * * * root /usr/local/sbin/amoobot-backup nightly`.
- [ ] هیچ رمزی در خروجی نیست. در نشست root روی سرور (مقادیر چاپ نمی‌شوند، فقط تعداد خط‌های منطبق):
  ```bash
  bash /root/deploy.sh --dry-run > /root/dry-run.txt 2>&1
  grep -E '^(APP_SECRET|DB_PASSWORD|TELEGRAM_BOT_TOKEN|TELEGRAM_WEBHOOK_SECRET|TELEGRAM_WEBHOOK_SECRET_TOKEN)=' /etc/amoobot/.env.prod.local \
    | cut -d= -f2- | sed -e "s/^[\"']//" -e "s/[\"']\$//" | grep . | grep -cFf - /root/dry-run.txt   # باید 0 باشد
  rm /root/dry-run.txt
  ```
  و نسخه‌ی محلی را هم پاک کنید: `rm /tmp/amoobot-dry-run.txt`.

## ۵. اجرای واقعی (با نشست دوم root باز)

- [ ] **یک نشست SSH دوم با root باز کنید و تا پایان مرحله‌ی ۷ نبندید.** اگر چیزی در SSH خراب شد، از همین نشست درستش می‌کنید.

```bash
ssh root@SERVER 'bash /root/deploy.sh'
```

- [ ] اجرا در مرحله‌ی 7 **عمداً متوقف می‌شود** و کلید عمومی `/etc/amoobot/repo_deploy_ed25519.pub` را چاپ می‌کند (قبل از متوقف کردن چیزی).
  GitHub ← مخزن ← Settings ← Deploy keys ← Add deploy key ← کلید را بچسبانید، **Allow write access را تیک نزنید** ← Add.
- [ ] دوباره اجرا کنید: `ssh root@SERVER 'bash /root/deploy.sh'` ← تا `Amoobot deployment completed` بدون خطا.

## ۶. ورود `deploy` را قبل از بستن نشست root امتحان کنید

در یک ترمینال **جدید**:

```bash
ssh deploy@SERVER                       # با کلید وارد می‌شود
sudo amoobot-deploy --dry-run           # بدون پسورد اجرا می‌شود
sudo -l                                 # فقط چهار دستور amoobot-*
exit
ssh -o PubkeyAuthentication=no deploy@SERVER   # باید رد شود (Permission denied (publickey))
```

- [ ] هر چهار مورد بالا درست است. حالا می‌توانید نشست دوم root را ببندید.

## ۷. بررسی بعد از deploy

```bash
ssh root@SERVER 'sudo /usr/local/lib/amoobot/post-deploy-check.sh'
```

- [ ] `RESULT: OK` (کد خروج `0`)، یعنی همه‌ی ۱۷ خط `PASS` هستند. اولین بار ممکن است `scheduled-jobs` به‌خاطر `NEVER` (کارها هنوز یک‌بار هم
  اجرا نشده‌اند) `FAIL` باشد: ۳۰ دقیقه صبر کنید و دوباره اجرا کنید. هر `FAIL` دیگر را قبل از ادامه رفع کنید.
- [ ] نسخه‌ی root‌مالک را اجرا کنید (`/usr/local/lib/amoobot/...`)، **نه** `bin/` داخل `/var/www/amoobot` با root.

## ۸. تست دود Webhook

```bash
ssh root@SERVER 'sudo /usr/local/lib/amoobot/smoke-webhook.sh 999000001'   # id جعلی؛ نه id ادمین
```

- [ ] `HTTP status: 200`، `logged by the app (bot_message_log, incoming): yes` و `RESULT: OK`.
- [ ] یادتان باشد: این فقط مسیر nginx ← PHP-FPM ← Symfony ← کنترلر Webhook را ثابت می‌کند (برای id جعلی یک حساب بات ساخته می‌شود و پاسخ بات
  به آن تحویل نمی‌شود). **تست نهایی:** از حساب تلگرام ادمین به **بات تست** `/start` بفرستید.
- [ ] `/start` واقعی از حساب ادمین: بات جواب می‌دهد و منوی ادمین دیده می‌شود.

## ۹. ساخت اولین ادمین و داده‌های اولیه

پسورد را **فقط وقتی پرسیده شد** وارد کنید (پنهان است). هیچ‌وقت `--password` روی خط فرمان ندهید: در history شل و لیست پروسه‌ها می‌ماند.

```bash
ssh root@SERVER
cd /var/www/amoobot
sudo -u www-data php bin/console app:admin:create-user --env=prod --email admin@example.com --name "Admin"   # Password: (پرسیده می‌شود)
sudo -u www-data php bin/console app:settings:seed --env=prod
sudo -u www-data php bin/console app:bot-content:seed --env=prod
sudo -u www-data php bin/console app:payment:create-default-gateways --env=prod
```

- [ ] ورود به `https://bot.example.com/admin/login` با همان ایمیل و پسورد کار می‌کند.

## ۱۰. پیکربندی در پنل ادمین

- [ ] **پنل VPN:** پنل را اضافه کنید ← «test connection» موفق ← «sync inbounds» ← inboundهای درست فعال.
- [ ] **پلن‌ها:** قیمت، حجم و مدت هر پلن (قیمت‌ها را برای مقایسه در مرحله‌ی ۱۱ یادداشت کنید).
- [ ] **درگاه‌ها:** برای هر درگاه فعال: `sandbox=false`، کلیدهای production، `callback_base_url=https://bot.example.com`. درگاه‌هایی که
  استفاده نمی‌کنید غیرفعال باشند. پلاگین دمو غیرفعال باشد (`sudo -u www-data php bin/console app:plugin:list --env=prod`).
- [ ] **کارت‌به‌کارت:** شماره و نام صاحب کارت (`PAYMENT_CARD_*`) درست است.
- [ ] **اتوماسیون:** toggleها (به‌خصوص «تعلیق خودکار»، پیش‌فرض خاموش) و `automation.batch_limit` بررسی شده‌اند.

## ۱۱. ماتریس خرید آزمایشی

با یک حساب تلگرام **غیر ادمین** در بات تست انجام دهید. برای هر ردیف نتیجه را ثبت کنید. مبلغ‌ها کمترین ممکن.

| # | سناریو | انجام | نتیجه‌ی مورد انتظار | ✓ |
|---|---|---|---|---|
| 1 | کارت‌به‌کارت، سرویس جدید | خرید پلن ← ارسال رسید ← تأیید ادمین در پنل | سفارش تکمیل، سرویس روی پنل VPN ساخته و کانفیگ در بات تحویل می‌شود | [ ] |
| 2 | کارت‌به‌کارت، تمدید | تمدید همان سرویس ← رسید ← تأیید | تاریخ انقضا و حجم طبق قوانین تمدید به‌روز می‌شود | [ ] |
| 3 | کارت‌به‌کارت، حجم اضافه | خرید حجم اضافه ← رسید ← تأیید | حجم سرویس روی پنل به همان اندازه زیاد می‌شود | [ ] |
| 4 | هر درگاه فعال (یک تراکنش واقعی کوچک برای **هر** درگاه) | خرید ← رفتن به صفحه‌ی درگاه ← **مبلغ صفحه‌ی درگاه را با قیمت پلن مقایسه کنید** (واحد: تومان/ریال، ارز) ← پرداخت | مبلغ دقیقاً برابر قیمت پلن (با تبدیل واحد درست)؛ بعد از پرداخت سفارش تکمیل و سرویس تحویل می‌شود؛ بازگشت از درگاه صفحه‌ی موفقیت را نشان می‌دهد | [ ] |
| 5 | پنل VPN خاموش | دسترسی پنل را موقتاً قطع کنید ← پرداخت | پرداخت `PROVISIONING_FAILED` می‌شود (پول ثبت شده، سرویس ساخته نشده) و هشدار ادمین می‌رسد ← پنل را وصل کنید ← در پنل ادمین «retry» ← سرویس ساخته می‌شود، **بدون** پرداخت دوم | [ ] |
| 6 | Webhook جعلی | به آدرس webhook درگاه یک درخواست ساختگی بفرستید (نمونه‌ی پایین) | هیچ پرداختی تأیید نمی‌شود (پاسخ خطا یا رد امضا/تأیید سمت سرور)؛ وضعیت پرداخت عوض نمی‌شود | [ ] |
| 7 | پرداخت کمتر از مبلغ | در درگاهی که مبلغ را پرداخت‌کننده تعیین می‌کند (مثلاً کریپتو) کمتر بپردازید | پرداخت `NEEDS_REVIEW` با کسری ثبت‌شده، سرویس خودکار ساخته **نمی‌شود**؛ تصمیم با ادمین | [ ] |
| 8 | پرداخت بعد از لغو | سفارش بسازید ← صفحه‌ی درگاه را باز نگه دارید ← در بات لغو کنید ← پرداخت را کامل کنید | پرداخت `NEEDS_REVIEW` («paid after cancel»)، سرویس خودکار ساخته نمی‌شود؛ ادمین بازپرداخت یا تأیید می‌کند | [ ] |

نمونه‌ی ردیف ۶ (فقط روی سرور خودتان، با داده‌ی ساختگی):

```bash
curl -s -o /dev/null -w '%{http_code}\n' -X POST https://bot.example.com/payment/webhook/nowpayments \
  -H 'Content-Type: application/json' -H 'x-nowpayments-sig: forged' -d '{"payment_status":"finished","order_id":"1"}'
# انتظار: 4xx، و در پنل ادمین هیچ پرداختی تأیید نشده است
```

- [ ] بعد از ماتریس: `sudo /usr/local/lib/amoobot/post-deploy-check.sh` هنوز `RESULT: OK` است.

## ۱۲. تمرین بازگردانی بکاپ روی دیتابیس دورریختنی

`amoobot-restore` همیشه دیتابیس **اصلی** (از `/etc/amoobot/database.env`) را جایگزین می‌کند؛ برای تمرین از آن استفاده نکنید. یک دیتابیس
موقت بسازید و dump را آنجا import کنید:

```bash
ssh root@SERVER
sudo amoobot-backup manual                                    # "Backup written to /var/backups/amoobot/amoobot_manual_<UTC>.sql.gz"
f="$(ls -t /var/backups/amoobot/amoobot_manual_*.sql.gz | head -1)"; gzip -t "$f" && zcat "$f" | tail -1   # "-- Dump completed ..."
mariadb -e 'CREATE DATABASE amoobot_drill CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
zcat "$f" | mariadb --binary-mode amoobot_drill
mariadb -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='amoobot_drill'; SELECT COUNT(*) FROM amoobot_drill.doctrine_migration_versions; SELECT COUNT(*) FROM amoobot_drill.payment"
mariadb -N -e "SELECT COUNT(*) FROM amoobot.payment"          # باید با عدد بالا برابر باشد
mariadb -e 'DROP DATABASE amoobot_drill'
```

- [ ] تعداد جدول‌ها، migrationها و پرداخت‌ها در `amoobot_drill` با دیتابیس اصلی برابر است و `amoobot_drill` پاک شد.
- [ ] روش بازگردانی واقعی را خوانده‌اید (deployment.md بخش ۵: اول `systemctl stop` برای PHP-FPM و timerها؛ `amoobot-restore` وگرنه رد می‌کند).

## ۱۳. دروازه‌ی Go-live

**فقط وقتی همه‌ی موارد زیر تیک خورد** توکن بات تست را با توکن بات اصلی عوض کنید.

- [ ] بخش‌های ۱ تا ۱۲ همه تیک خورده‌اند؛ همه‌ی ردیف‌های ماتریس خرید نتیجه‌ی مورد انتظار را داده‌اند.
- [ ] رمزهای لو رفته‌ی قدیمی (deployment.md بخش ۰) عوض شده‌اند و توکن بات اصلی در @BotFather revoke و دوباره صادر شده است.
- [ ] deploy key در GitHub فقط‌خواندنی است و فقط کلید root سرور ثبت است؛ دسترسی نوشتن مخزن مثل root محدود است (یا `REQUIRE_SIGNED_TAG=yes`).
- [ ] بکاپ‌ها به خارج از سرور منتقل می‌شوند؛ `MONITOR_PING_URL` تنظیم شده و pingها در سرویس مانیتورینگ دیده می‌شوند؛ مانیتورینگ uptime روی `/healthz`.
- [ ] هشدار آزمایشی تلگرام به ادمین رسیده است (deployment.md بخش ۷).

جابه‌جایی توکن:

```bash
ssh root@SERVER
nano /etc/amoobot/.env.prod.local                   # TELEGRAM_BOT_TOKEN = توکن بات اصلی (اگر لازم است TELEGRAM_ADMIN_CHAT_ID هم)
sudo amoobot-deploy --app-only                      # webhook با توکن جدید ثبت می‌شود
sudo /usr/local/lib/amoobot/post-deploy-check.sh    # RESULT: OK
```

- [ ] `/start` از حساب ادمین به **بات اصلی** جواب می‌دهد.
- [ ] توکن بات تست در @BotFather revoke شده است.
