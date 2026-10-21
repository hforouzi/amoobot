# Amoobot

Amoobot is a self-hosted Telegram VPN shop with an admin panel, VPN panel integration, payments, automation, and a payment plugin SDK.

## EasyAdmin Security

Create the first admin user:

```bash
php bin/console app:admin:create-user --email=admin@example.com --role=ROLE_SUPER_ADMIN
```

The password is asked for interactively (hidden input), so it never ends up in your shell history.

Login URL: `/admin/login`

Admin URL: `/admin`

Logout URL: `/admin/logout`

Roles:

- `ROLE_SUPER_ADMIN`: full admin access, including admin user management and sensitive configuration.
- `ROLE_ADMIN`: EasyAdmin access without super-admin-only sections.
- `ROLE_SUPPORT`: supported admin role for limited future use; it does not grant `/admin` by itself unless combined with `ROLE_ADMIN`.

## Broadcast Notifications

Use EasyAdmin menu `ارسال پیام همگانی` to create Telegram broadcast messages.

Target types:

- `all`: all Telegram accounts with a Telegram chat id.
- `active_users`: users who have a Telegram account.
- `users_with_active_service`: users with an active VPN service.
- `users_with_expiring_service`: users with an active VPN service expiring soon. Default is 3 days; set metadata JSON like `{"expiringDays":3}` to override.
- `selected_users`: only user ids listed in `targetUserIds`, for example `[1,2,3]`.
- `admins`: the configured Telegram admin chat id.

Broadcasts can be previewed, prepared, sent immediately, scheduled with `sendAt`, cancelled, and retried from EasyAdmin. Deliveries are tracked per chat id so running send commands more than once does not duplicate already sent messages.

Run scheduled and queued broadcasts:

```bash
php bin/console app:broadcast:run --limit=50
```

Cron example:

```cron
*/2 * * * * php /path/to/app/bin/console app:broadcast:run --limit=50
```

Retry failed deliveries:

```bash
php bin/console app:broadcast:retry-failed 123 --limit=50
```

## Documentation

- [فارسی](docs/fa/README.md)
- [English](docs/en/README.md)
