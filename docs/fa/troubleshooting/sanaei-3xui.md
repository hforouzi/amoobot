# عیب‌یابی Sanaei / 3x-ui

## Legacy و v3

Legacy:

- `api_version=legacy`
- `auth_mode=cookie`
- نام کاربری و رمز عبور

v3+:

- `api_version=v3`
- `auth_mode=bearer`
- `api_token`
- endpoint مثل `/panel/api/inbounds/list`
- endpointهای رسمی لینک مثل `getClientLinks`

## دستورها

```bash
php bin/console app:panel:detect-version
php bin/console app:panel:test-login
php bin/console app:panel:list-inbounds
php bin/console app:panel:test-client-links
php bin/console app:panel:debug-transport
```

## مشکل‌های رایج

- توکن Bearer اشتباه برای v3.
- استفاده از Cookie auth روی endpointهای v3.
- timeout یا مشکل شبکه؛ تنظیمات proxy را بررسی کنید.
- تفاوت نوع inbound id بین پاسخ پنل و دیتابیس.
- لینک‌های `externalProxy` به metadata همگام‌سازی‌شده وابسته‌اند.
