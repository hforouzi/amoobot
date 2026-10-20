# Connecting a 3x-ui (Sanaei) panel — summary

The full guide is in Persian: [../fa/panel-connection.md](../fa/panel-connection.md). In short:

- **What you need:** the panel URL and an API token (3x-ui: **Settings → Security → API Token**). The token is a full-admin
  credential: restrict the panel to the bot server's IP and rotate the token periodically.
- **Admin:** VPN panels → Add. 1. name, URL, token → *Test connection* (reachable, TLS, authentication, detected API, latency,
  inbound count, and an explanation and fix for each failure); 2. choose the inbounds to sell from (read from the panel;
  disabled ones cannot be chosen); 3. map plans to inbounds. The connection mode is **auto** by default (decided by the
  test); forcing *modern* on a panel with active plans is refused until a test confirmed the new API.
- **URL:** exactly as opened in a browser, with the secret web base path (`https://panel.example.com:2053/secret/`);
  IPv6 as `https://[2001:db8::10]:2053/`. Plain `http://` only for `127.0.0.1`/`localhost`/`::1` or private IPs (a panel on
  the same server: `http://127.0.0.1:2053/secret/`); any other `http://` is refused because the token would travel in clear
  text.
- **Self-signed TLS:** verification is never turned off. Enter the certificate's public-key pin (advanced settings), made on
  the panel server with
  `openssl x509 -in cert.crt -pubkey -noout | openssl pkey -pubin -outform der | openssl dgst -sha256 -binary | base64`.
- **Transport:** 3 s connect, 10 s per request, no redirects; GETs retried at most 3 times on transient errors, writes never
  retried; the per-panel proxy (`PANEL_PROXY_*`) is honoured.
- **What the bot stores:** the token (and an old panel's password) encrypted with libsodium keyed from `APP_SECRET` — never
  shown (last 4 characters only), removed from logs, errors and command output. Changing `APP_SECRET` means entering the
  tokens again. Modern panels keep no admin username/password. A panel failing 3 times in a row is marked degraded and its
  plans are hidden from new purchases until a call succeeds.
- **Check a real panel after deploy:**
  `sudo -u www-data php bin/console app:panel:doctor <panelId> --env=prod` (report) and
  `... app:panel:doctor <panelId> --self-test --yes --env=prod` (creates, reads, changes, disables, enables and deletes a
  temporary client `amoobot_selftest_<random>`; never touches other clients; always cleans up).
- **Contract test (developers, opt-in, read-only):**
  `PANEL_E2E_URL=... PANEL_E2E_TOKEN=... php bin/phpunit --group panel-e2e` — never commit the values.

## Troubleshooting

| Report | Fix |
|---|---|
| DNS | check the domain and its DNS record |
| connection refused | port, panel running, panel firewall allows the bot server's IP |
| timeout | panel firewall dropping packets, wrong port, or enable the proxy |
| TLS certificate | install a valid certificate, or enter / update the public-key pin |
| token rejected (401/403) | create a new token in Settings → Security → API Token |
| wrong URL (404 / HTML) | exact URL with the web base path; update very old panels |
| panel refused (success=false) | read the panel's message in the detail |
| unexpected answer | update 3x-ui; make sure the URL points to the panel |
| HTTP error | panel log; for a redirect enter the final URL |
| not configured | URL (`https://`), token; `PANEL_PROXY_*` when the proxy is enabled |
