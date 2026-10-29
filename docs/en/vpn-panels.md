# VPN Panels

> Connecting a panel with an API token (recommended): [panel-connection.md](panel-connection.md). `app:panel:doctor` prints the connection-test report; with `--self-test --yes` it checks the panel end to end.

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

## Config links and 3x-ui Hosts

On a panel in **modern** mode the customer's config links (bot "config" button, `/s` page, QR codes, copy) are exactly
what the panel's own subscription gives: `GET /panel/api/clients/subLinks/{subId}`, with the panel's **Hosts** applied
(one link per enabled host address, in the panel's order). The bot stores that list as the service's link snapshot and
never adds links it built from the inbound. If the panel cannot be asked, refuses, or answers with no links, the
stored links are kept. A shorter list (for example after you disabled a host) is stored and reported. Legacy-mode
panels and other panel types work as before. Design: [Hosts, section 9](../design/multi-inbound-plans.md#9-hosts-new-2026-10-29).

`app:panel:doctor <panelId>` shows whether the panel has the Hosts API: `hosts=yes` (with the number of host groups),
`hosts=no` (an older panel: links still come from subLinks), `hosts=forbidden` (the API token cannot read
`/panel/api/hosts`: give it the admin scope).

### Re-reading links: dry run first

```bash
# One service. Shows old vs new link count and every link that would be added (+) or removed (-). Writes nothing.
php bin/console app:service:regenerate-config <serviceId>
php bin/console app:service:regenerate-config <serviceId> --apply      # stores them

# Every service of an inbound, in batches. A dry run unless --apply.
php bin/console app:inbound:regenerate-service-configs <inboundId>
php bin/console app:inbound:regenerate-service-configs <inboundId> --apply --batch-size=50
php bin/console app:inbound:regenerate-service-configs <inboundId> --apply --after-id=<last processed id>   # resume

# After syncing a panel's inbounds: every service of the panel, same rules (a dry run unless --apply).
php bin/console app:panel:sync-inbounds <panelId> --regenerate-configs
php bin/console app:panel:sync-inbounds <panelId> --regenerate-configs --apply
```

- **Both commands are dry runs unless you pass `--apply`.** `--apply` together with `--dry-run` is refused.
- Links are printed as `scheme address:port #remark`, never with the UUID or password.
- After each batch the bulk command prints `last processed id N`; if it stops, run it again with `--after-id=N`.
- A service whose panel call fails is skipped with `FAILED, stored links kept`. The run goes on and the command exits with 1.
- **After 5 failed services in a row the run stops** (the panel is probably down) and prints the `--after-id` to resume
  with. Batches are separated by a pause, `--pause` seconds (default 0.2).
- A bulk run **never marks the panel "degraded"** (which would stop its sales): its failed calls are not counted.
- Each service is written on its own, with its row locked. If a "reset my links" changed the service's credentials
  while its links were being read, the links read are thrown away (`credentials changed meanwhile`) and the panel is
  read again for the new credentials. Old links are never written over new credentials.
- The admin "Regenerate config" button (web panel and Mini App) does the same as `--apply` for one service. Its
  message shows the new and the previous count, for example "2 link(s) (before: 3)".
- The web action "Regenerate service configs" of an **inbound** handles at most **10 services** (one panel call each,
  inside one web request). For a larger inbound it shows the command to run instead:
  `php bin/console app:inbound:regenerate-service-configs <inboundId> --apply` (run it without `--apply` first).

### Verify links (read-only)

```bash
php bin/console app:service:verify-links <serviceId>          # add --json for a machine-readable report
```

It reads the database and the panel (GET requests only) and changes nothing. It checks:

| Check | Meaning of DIFF |
|---|---|
| stored subId = panel subId | The subId the bot reads links with is not the panel's, or is missing from the database (then `regenerate-config <id> --apply` stores it). Neither value is printed. |
| stored snapshot = panel subLinks | The service still has old links. Run `app:service:regenerate-config <id> --apply`. |
| panel subLinks = Hosts prediction | A host that should give a link does not, or its security / SNI / path differs. Skipped when the panel has no Hosts API or the client is bound to specific host rules. |
| every address is public | A link points at `127.0.0.1` or a private address (set the panel's Sub/Web domain, or add a host). |
| every link carries this client's credential | A link of another client. A host's "VLESS route" rewrites two bytes of the UUID; that is allowed. |
| U2: no info node | subLinks contained an item the bot drops (for example the `socks://127.0.0.1` info line). |
| U6: links/{email} = subLinks | The panel's two link endpoints disagree. The bot stores subLinks only. |

Exit code `0` = PASS, `1` = differences (listed), `2` = could not check (legacy panel, panel unreachable or refusing,
client missing, or any unexpected error).

**Manual checks** (things the command cannot see), on a test service:

- **U1.** In the panel, toggle "Shuffle host" on a host with several addresses, then "Hide", then change its Nodes. After
  each change run `regenerate-config <id> --apply` twice and `verify-links <id>`. Expected: same links, count and order.
- **U3.** On a REALITY inbound, add a host with Security = TLS. Run `regenerate-config <id>` (dry run) and compare the
  link with the one the panel's client page shows.
- **U4.** Panel settings: Subscription domain or Web domain is set. Otherwise inbounds without a host get the address
  the bot uses to reach the panel, and the bot withholds those links.
- **U5.** `app:panel:doctor <panelId>` does not say `hosts=forbidden`.

### First check on production

1. Pick a test service on an inbound with at least two enabled hosts and one disabled host (ideally one host with two
   addresses).
2. `php bin/console app:panel:doctor <panelId>`. Expected: `PASS connection` and `hosts=yes`.
3. `php bin/console app:service:verify-links <serviceId>`. Expected: PASS, or DIFF only in "stored snapshot" (old
   links from before this fix).
4. `php bin/console app:service:regenerate-config <serviceId>`. Read the + / - lines, then run it again with `--apply`.
   Run `verify-links` again. Expected: PASS.
5. In the panel, disable one enabled host. `verify-links`: DIFF "only in the stored snapshot". `regenerate-config
   <id> --apply` prints `! fewer links than before`. `verify-links`: PASS. Enable the host again and repeat.
6. Add a second address to a host, then mark a host "Exclude from formats: raw". After each change, `regenerate-config
   --apply` and `verify-links`. Expected: one more link, then one less.
7. Compare with what a phone gets: `curl -s '<subscription URL>' | base64 -d` should list the same links. With the
   panel's info node switched on, it also has one `socks://127.0.0.1` line.
8. Then, per inbound: `app:inbound:regenerate-service-configs <inboundId>` (dry run), read it, then `--apply`.

Services on a modern panel with **no** stored links still get links built from the inbound when shown (this is not
covered by this fix yet). Count them, read-only:

```sql
SELECT COUNT(*) AS modern_services_without_link_snapshot
FROM vpn_service s JOIN vpn_panel p ON p.id = s.panel_id
WHERE p.type = 'sanaei_3xui'
  AND (p.sanaei_integration_mode = 'modern'
       OR (p.sanaei_integration_mode NOT IN ('legacy', 'modern') AND p.sanaei_detected_mode = 'modern'))
  AND s.status <> 'deleted'
  AND (s.config_links IS NULL OR TRIM(s.config_links) IN ('', '[]', 'null'));
```

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
