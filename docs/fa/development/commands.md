# دستورها

فقط دستورهایی که در پروژه وجود دارند اینجا آمده‌اند.

## پلاگین

```bash
php bin/console app:plugin:list
php bin/console app:plugin:install ZIP_PATH
php bin/console app:plugin:enable CODE
php bin/console app:plugin:disable CODE
php bin/console app:plugin:doctor CODE
php bin/console app:plugin:validate-package ZIP_PATH
```

## پرداخت

```bash
php bin/console app:payment:list-modules
php bin/console app:payment:list-gateways
php bin/console app:payment:list-methods
php bin/console app:payment:debug-methods-for-order ORDER_ID
php bin/console app:payment:debug-gateway-config GATEWAY_ID
php bin/console app:payment:test-zibal GATEWAY_ID
php bin/console app:payment:test-nowpayments GATEWAY_ID
php bin/console app:payment:test-nowpayments-auth GATEWAY_ID
php bin/console app:payment:test-plugin-gateway GATEWAY_ID
php bin/console app:payment:repair-plugin-gateway PLUGIN_CODE
```

## پنل

```bash
php bin/console app:panel:detect-version
php bin/console app:panel:test-login
php bin/console app:panel:list-inbounds
php bin/console app:panel:test-client-links
php bin/console app:panel:sync-inbounds <panelId> [--regenerate-configs [--apply]]   # --regenerate-configs بدون --apply فقط اجرای آزمایشی است (دسته‌ای، --after-id، --pause؛ بعد از ۵ خطای پشت‌سرهم متوقف می‌شود)
php bin/console app:panel:sync-inbound-metadata
php bin/console app:panel:debug-transport
```

## سفارش و اتوماسیون

```bash
php bin/console app:orders:backfill-tracking-codes
php bin/console app:orders:expire-incomplete
php bin/console app:automation:run
```

## سرویس

```bash
php bin/console app:service:sync-usage
php bin/console app:service:check-expiry
php bin/console app:service:send-notifications
# بدون --apply فقط اجرای آزمایشی‌اند: لینک‌های قبلی و جدید، بدون ذخیره. راهنما: vpn-panels.md، «لینک‌های کانفیگ و Hostهای 3x-ui».
php bin/console app:service:regenerate-config <serviceId> [--apply]
php bin/console app:inbound:regenerate-service-configs <inboundId> [--apply] [--batch-size=50] [--pause=0.2] [--after-id=N]
php bin/console app:service:verify-links <serviceId> [--json]   # فقط خواندنی؛ خروجی ۰ = PASS، ۱ = DIFF، ۲ = بررسی ممکن نشد
php bin/console app:service:debug-links
```

## تلگرام

```bash
php bin/console app:telegram:set-webhook URL
php bin/console app:telegram:delete-webhook
php bin/console app:telegram:webhook-info
php bin/console app:telegram:mode-info
php bin/console app:telegram:poll
```
