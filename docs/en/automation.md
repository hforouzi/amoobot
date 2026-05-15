# Automation

Automation handles recurring lifecycle work for services and orders.

## Tasks

- Sync usage from panels.
- Check service expiry.
- Detect traffic exhaustion.
- Send lifecycle notifications.
- Suspend expired services when enabled.
- Suspend traffic-exhausted services when enabled.
- Expire incomplete orders.

## Settings

- `orders.incomplete_expire_hours`
- Service expiry notification days from `SERVICE_NOTIFY_EXPIRY_DAYS`.
- Traffic threshold notifications from `SERVICE_NOTIFY_TRAFFIC_THRESHOLDS`.
- Automation toggles are stored in settings where available.

## Commands

```bash
php bin/console app:automation:run --dry-run
php bin/console app:automation:run
php bin/console app:orders:expire-incomplete --dry-run
php bin/console app:orders:expire-incomplete
php bin/console app:service:sync-usage
php bin/console app:service:check-expiry
php bin/console app:service:send-notifications
```

Use dry-run first on production data.
