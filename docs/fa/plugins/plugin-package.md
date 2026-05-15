# بسته پلاگین

## ساختار ZIP

```text
plugin.json
README.md
src/
translations/
templates/
assets/
```

برای یک پلاگین پرداخت ساده، `plugin.json`، `README.md` و `src/` کافی است.

## فیلدهای Manifest

- `manifestVersion`: در حال حاضر `1`.
- `type`: در حال حاضر `payment_gateway`.
- `code`: کد یکتا با حروف کوچک، عدد، `_` یا `-`.
- `name`: نام‌های محلی‌سازی‌شده.
- `version`: نسخه پلاگین.
- `mainClass`: نام کامل کلاس PHP.
- `permissions`: دسترسی‌های اعلام‌شده.
- `configSchema`: فیلدهای تنظیمات درگاه.

## قوانین ZIP

- `plugin.json` می‌تواند در ریشه ZIP یا داخل یک پوشه ریشه باشد.
- metadata مک مثل `__MACOSX`، `.DS_Store` و `._*` در validator نادیده گرفته می‌شود.
- Path traversal رد می‌شود.
- ریشه‌های غیرمجاز مثل `vendor`، `public`، `var` و `.git` رد می‌شوند.
- code تکراری هنگام نصب رد می‌شود.

قبل از نصب validate کنید:

```bash
php bin/console app:plugin:validate-package /path/to/plugin.zip
```
