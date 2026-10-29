# Commands

Only commands currently present in the application are listed here.

## Plugin

```bash
php bin/console app:plugin:list
php bin/console app:plugin:install <zipPath>
php bin/console app:plugin:enable <code>
php bin/console app:plugin:disable <code>
php bin/console app:plugin:doctor <code>
php bin/console app:plugin:validate-package <zipPath>
```

## Payment

```bash
php bin/console app:payment:list-modules
php bin/console app:payment:list-gateways
php bin/console app:payment:list-methods
php bin/console app:payment:debug-methods-for-order <orderId>
php bin/console app:payment:debug-gateway-config <gatewayId>
php bin/console app:payment:test-zibal <gatewayId>
php bin/console app:payment:test-nowpayments <gatewayId>
php bin/console app:payment:test-nowpayments-auth <gatewayId>
php bin/console app:payment:test-plugin-gateway <gatewayId>
php bin/console app:payment:repair-plugin-gateway <pluginCode>
```

## Panel

```bash
php bin/console app:panel:detect-version
php bin/console app:panel:test-login
php bin/console app:panel:list-inbounds
php bin/console app:panel:test-client-links
php bin/console app:panel:sync-inbounds <panelId> [--regenerate-configs [--apply]]   # --regenerate-configs is a dry run unless --apply (batches, --after-id, --pause; stops after 5 failures in a row)
php bin/console app:panel:sync-inbound-metadata
php bin/console app:panel:debug-transport
```

## Orders And Automation

```bash
php bin/console app:orders:backfill-tracking-codes
php bin/console app:orders:expire-incomplete
php bin/console app:automation:run
```

## Service

```bash
php bin/console app:service:sync-usage
php bin/console app:service:check-expiry
php bin/console app:service:send-notifications
# Dry runs unless --apply: old vs new links, nothing written. See vpn-panels.md, "Config links and 3x-ui Hosts".
php bin/console app:service:regenerate-config <serviceId> [--apply]
php bin/console app:inbound:regenerate-service-configs <inboundId> [--apply] [--batch-size=50] [--pause=0.2] [--after-id=N]
php bin/console app:service:verify-links <serviceId> [--json]   # read-only; exit 0 PASS, 1 DIFF, 2 could not check
php bin/console app:service:debug-links
```

## Telegram

```bash
php bin/console app:telegram:set-webhook <url>
php bin/console app:telegram:delete-webhook
php bin/console app:telegram:webhook-info
php bin/console app:telegram:mode-info
php bin/console app:telegram:poll
```
