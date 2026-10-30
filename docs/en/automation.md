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
- Claim links (`--only=claims`, every 15 minutes at :13/:28/:43/:58, no toggle): expire links past their time and retry pending panel owner updates ([resellers](resellers.md#claim-links)). It runs in the `lifecycle` lock group with sync, expiry, suspend and notifications: its panel owner updates replace the whole client row, like theirs, so two of them must never overlap.
- Additional inbounds of multi-inbound plans (`--only=attach-inbounds`, every 30 minutes at :25/:55, gated by `plans.multi_inbound_enabled` and `plans.attach_retry_enabled`): retry the additional inbounds that failed to attach (with back-off, up to a number of attempts), compare the attached ones with the panel, alert the admins once ([admin guide](admin-guide.md#scheduled-retry-and-alerts)). It is in the `lifecycle` lock group too: it attaches the client and refreshes its links, which must not overlap a sync or suspend of the same client. With the switch off it reads and changes nothing. Installed by a **full** `deploy.sh` run (not `--app-only`).

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
php bin/console app:claims:expire --dry-run
php bin/console app:claims:expire
php bin/console app:services:attach-inbounds --dry-run
php bin/console app:services:attach-inbounds
```

Use dry-run first on production data.
