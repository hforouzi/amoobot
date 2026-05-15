# VPN Panels

`VpnPanel` stores connection/auth settings for a remote VPN panel. `VpnInbound` represents a sellable inbound on that panel. `VpnService` links a customer service to a remote client.

## Sanaei/3x-ui Modes

Legacy panels usually use:

- `api_version=legacy`
- `auth_mode=cookie`
- username/password login

v3+ panels usually use:

- `api_version=v3`
- `auth_mode=bearer`
- `api_token`
- `/panel/api/inbounds/list`
- official link endpoints such as `getClientLinks` and `getSubLinks`

## Subscription URL Settings

- `subscription_base_url`: public base URL for generated subscription links.
- `subscription_path_prefix`: path prefix used when building subscription URLs.

## Commands

```bash
php bin/console app:panel:detect-version
php bin/console app:panel:test-login
php bin/console app:panel:list-inbounds
php bin/console app:panel:test-client-links
php bin/console app:panel:sync-inbounds
php bin/console app:panel:sync-inbound-metadata
```

Use `php bin/console help <command>` for required arguments.

## Troubleshooting

- Use `app:panel:debug-transport` for proxy and timeout diagnostics.
- SOCKS/HTTP proxy values come from panel proxy environment/settings.
- Inbound IDs may differ between legacy and v3 APIs; check int/string handling in synced metadata.
- External proxy links depend on panel response and local inbound metadata.
- v3 bearer auth requires a valid API token and v3 endpoint support.
