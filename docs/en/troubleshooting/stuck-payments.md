# Stuck confirmed payments

Before the payment settlement hardening, a payment could be marked **confirmed** and then fail to
create or renew the service. Such orders were paid but never fulfilled, and they do not appear in the
"provisioning failed" admin list because their payment is already confirmed.
`PaymentSettlementService` no longer produces them (it confirms only together with a successful
fulfilment), but old data may contain some.

## Command

```bash
# dry-run (default): lists stuck payments, writes nothing
php bin/console app:payments:repair-stuck

# move the retryable ones to provisioning_failed
php bin/console app:payments:repair-stuck --apply
```

Options:

- `--apply`: write. Without it nothing changes.
- `--include-service-missing`: also mark new-service orders that are marked provisioned but have no
  service row. Check these first: an admin may have deleted the service on purpose.
- `--min-age-minutes=15`: only payments confirmed at least this long ago.
- `--limit=200`

The command never provisions or renews anything. Marked payments appear in
**Admin > Payments: provisioning failed**, where **Retry provisioning** runs the fulfilment once.

## Categories

| Category | Meaning | `--apply` |
|---|---|---|
| `new_service_not_provisioned` | confirmed, order never provisioned, no service | marks for retry |
| `new_service_service_missing` | order says provisioned, but no service row | only with `--include-service-missing` |
| `renewal_not_applied` | renewal/add-traffic not recorded, **panel still shows the old expiry and traffic** | marks for retry (panel snapshot stored in the provisioning error) |
| `renewal_maybe_applied_on_panel` | panel expiry later or traffic higher than the database | never: reconcile by hand |
| `renewal_unverifiable` | panel unreachable, target service missing, or values not comparable (e.g. unlimited traffic) | never: reconcile by hand |

Renewals are never offered for retry without first comparing the panel's current state: a retry
renews from the database values, so retrying a renewal that already reached the panel would extend
the service twice.

## Manual reconciliation of renewals

For rows marked `skip (manual)`:

- `renewal_maybe_applied_on_panel`: open the service on the panel and compare it with the order
  (duration, traffic). If the renewal is there, update the service and the order in the admin to match
  the panel; do not retry it.
- `renewal_unverifiable`: make the panel readable (or fix the missing target service) and run the
  command again; it then reports `renewal_not_applied` or `renewal_maybe_applied_on_panel`.
