# Demo Payment Gateway Plugin

This is a placeholder plugin package for Phase 1.10.1 plugin infrastructure testing.

The plugin can be zipped and installed from the admin Plugins page or the CLI:

```bash
cd docs/plugins/demo-payment-gateway
zip -r demo-payment-gateway.zip plugin.json README.md src
php ../../../bin/console app:plugin:install demo-payment-gateway.zip
```

The class is not executed in Phase 1.10.1. Payment gateway runtime integration is reserved for Phase 1.10.2.
