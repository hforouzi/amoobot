# Gateway Not Configured

`gateway_not_configured` means one or more required keys are missing or empty.

## Check Required Keys

```bash
php bin/console app:payment:list-modules
php bin/console app:payment:debug-gateway-config <gatewayId>
```

For plugin gateways:

```bash
php bin/console app:plugin:doctor plugin_code
```

Required secret fields with no default must be configured manually. Repair commands apply schema defaults but do not fake missing secrets.

```bash
php bin/console app:payment:repair-plugin-gateway plugin_code
```
