# Sanaei / 3x-ui Troubleshooting

## Legacy vs v3

Legacy:

- `api_version=legacy`
- `auth_mode=cookie`
- username/password

v3+:

- `api_version=v3`
- `auth_mode=bearer`
- `api_token`
- endpoints such as `/panel/api/inbounds/list`
- official link APIs such as `getClientLinks`

## Diagnostics

```bash
php bin/console app:panel:detect-version
php bin/console app:panel:test-login
php bin/console app:panel:list-inbounds
php bin/console app:panel:test-client-links
php bin/console app:panel:debug-transport
```

## Common Issues

- Wrong bearer token for v3.
- Cookie auth used against v3-only endpoint.
- Timeout or blocked network path; check proxy settings.
- Inbound ID stored as string/int differently than panel response.
- `externalProxy` links depend on panel response and synced metadata.
