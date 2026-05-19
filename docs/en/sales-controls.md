# Sales Controls

Use `Admin -> Settings -> Sales` to open or close sales without changing payment gateways or provisioning.

## Settings

- `sales.new_orders_enabled`: controls starting new service purchases.
- `sales.renewals_enabled`: controls starting service renewals.
- `sales.add_traffic_enabled`: controls starting extra traffic purchases.
- `sales.disabled_message`: message shown to users when a sales flow is closed.

Setting keys are not editable in the admin UI; only values and the disabled message are editable.

## What Is Blocked

When new service sales are disabled, pressing the buy service button shows the disabled message and does not create a new draft or order.

When renewals or extra traffic purchases are disabled, starting that flow is blocked and no renewal or add-traffic order is created.

## What Is Not Blocked

Orders that already reached payment can continue. Viewing existing services, tracking orders, receiving configs, admin approval of pending payments, notifications, and automation are not blocked.

## Commands

Seed default settings:

```bash
php bin/console app:settings:seed
```

Show current sales status:

```bash
php bin/console app:sales:status
```
