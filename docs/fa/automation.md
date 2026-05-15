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
