# خطای Plugin Driver Missing

اول doctor را اجرا کنید:

```bash
php bin/console app:plugin:doctor plugin_code
```

بررسی کنید:

- `mainClass` با namespace و نام کلاس PHP یکی است.
- namespace prefix از `mainClass` درست به دست آمده است.
- `srcDir` وجود دارد.
- class file candidate وجود دارد.
- `class_exists` برابر yes است.
- expected interface برابر `App\Payment\Plugin\PaymentGatewayPluginInterface` است.
- implements interface برابر yes است.

سپس درگاه واقعی را تست کنید:

```bash
php bin/console app:payment:test-plugin-gateway GATEWAY_ID
```
