# خطای Gateway Not Configured

`gateway_not_configured` یعنی یک یا چند کلید required خالی یا missing است.

## بررسی کلیدهای required

```bash
php bin/console app:payment:list-modules
php bin/console app:payment:debug-gateway-config GATEWAY_ID
```

برای درگاه پلاگینی:

```bash
php bin/console app:plugin:doctor plugin_code
```

secretهای required بدون default باید دستی وارد شوند. repair command فقط defaultهای واقعی schema را اعمال می‌کند و secret جعلی نمی‌سازد.

```bash
php bin/console app:payment:repair-plugin-gateway plugin_code
```
