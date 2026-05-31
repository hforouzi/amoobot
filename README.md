# Amoobot

Amoobot is a self-hosted Telegram VPN shop with an admin panel, VPN panel integration, payments, automation, and a payment plugin SDK.

## EasyAdmin Security

Create the first admin user:

```bash
php bin/console app:admin:create-user --email=admin@example.com --password='StrongPass123!' --role=ROLE_SUPER_ADMIN
```

Login URL: `/admin/login`

Admin URL: `/admin`

Logout URL: `/admin/logout`

Roles:

- `ROLE_SUPER_ADMIN`: full admin access, including admin user management and sensitive configuration.
- `ROLE_ADMIN`: EasyAdmin access without super-admin-only sections.
- `ROLE_SUPPORT`: supported admin role for limited future use; it does not grant `/admin` by itself unless combined with `ROLE_ADMIN`.

## Documentation

- [فارسی](docs/fa/README.md)
- [English](docs/en/README.md)
