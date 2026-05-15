# پلاگین Demo Payment Gateway

پلاگین demo برای تست نصب، autoload، schema و load شدن adapter پرداخت است. این پلاگین برای پرداخت واقعی نیست.

## ساخت ZIP

```bash
cd docs/plugins/demo-payment-gateway
zip -r /tmp/demo-payment-gateway.zip plugin.json README.md src
```

## نصب

```bash
php bin/console app:plugin:validate-package /tmp/demo-payment-gateway.zip
php bin/console app:plugin:install /tmp/demo-payment-gateway.zip
php bin/console app:plugin:enable demo_payment_gateway
php bin/console app:plugin:doctor demo_payment_gateway
```

## استفاده

بعد از نصب، PaymentGateway بسازید یا اگر در پروژه فعال است از repair command استفاده کنید، سپس StorePaymentMethod فعال بسازید.

```bash
php bin/console app:payment:list-modules
php bin/console app:payment:test-plugin-gateway GATEWAY_ID
```
