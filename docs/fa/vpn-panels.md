# پنل‌های VPN

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
