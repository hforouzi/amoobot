# Plugin Driver Missing

Run doctor first:

```bash
php bin/console app:plugin:doctor plugin_code
```

Check:

- `mainClass` matches the PHP namespace and class.
- Namespace prefix is derived from `mainClass`.
- `srcDir` exists.
- Class file candidate exists.
- `class_exists` is yes.
- Expected interface is `App\Payment\Plugin\PaymentGatewayPluginInterface`.
- Implements interface is yes.

Then test an actual gateway:

```bash
php bin/console app:payment:test-plugin-gateway <gatewayId>
```
