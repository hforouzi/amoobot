# Customer brand domain split: read-only audit and proposed design

Audit date: 2026-11-05. Baseline: fetched `origin/develop/v2`, commit `0c42c06`.
This document changes no runtime behavior. All paths and line numbers refer to that commit;
the appendices give source-level inventories, including route methods and URL-bearing fields.
No database, production server, provider panel, or Telegram account was accessed. Stored data
described below is what the schema and writers permit, not a claim about actual production rows.

## 1. Decision and invariants

Keep one Symfony installation, one database, one worker/job set, and two HTTPS virtual hosts.
`cloud.mr13.win` remains the operational origin and retains every existing route, including
old Mini App and payment-return URLs. `stacktunnel.net` serves the customer Mini App and its
dependencies plus tokenized payment return/cancel pages. It must return 404 for everything
else. Do not redirect the old host wholesale, move webhook registrations, rewrite stored links,
or change global router context to the brand host.

The important blockers to a configuration-only switch are:

1. Symfony absolute URL generation uses the incoming request origin in HTTP and `DEFAULT_URI`
   outside HTTP (`config/packages/routing.yaml:1-11`). A payment created inside the branded
   Mini App can therefore accidentally acquire branded operational callback URLs even if
   `DEFAULT_URI` stays old. Both origins need explicit URL ownership.
2. `/app` includes a substantial admin UI and `/app/api/admin/*` API. A prefix allowlist for
   `/app/` exposes admin functionality on the brand host. Deny these at nginx and Symfony,
   and suppress their navigation on that host. See the full route inventory below.
3. Winapay's callback is a browser redirect, not a separate signed server webhook. Keeping
   the callback on cloud while showing the result on stack requires an explicit cross-origin
   redirect after callback processing (`PaymentWebhookController.php:145-153`).
4. Mini App links to `/s/{token}` and `/d/{id}` must explicitly use cloud. They are navigation
   destinations, not APIs to proxy onto the brand host. Payment-return subscription links
   are panel access URLs; leave them intact.

## 2. URL ownership and construction audit

| Producer / configuration | Current behavior and proposed ownership |
| --- | --- |
| `.env:24`, `.env.example:14`, `config/packages/routing.yaml:5` | `DEFAULT_URI` is the fallback router origin. Keep `https://cloud.mr13.win`. No separate brand origin exists. |
| `config/services.yaml:110-136` | Mini App initData/session wiring and bearer transport; no separate brand URL injection. Add a scoped origin resolver rather than changing the shared router service/context. |
| `src/MiniApp/Application/MiniAppLinks.php:34-59` | Bot buttons, service query and guide query all build absolute `/app` URLs. Route these through the proposed brand resolver; preserve enabled/public-HTTPS checks. |
| `src/Bot/Application/TelegramKeyboardFactory.php:558-577,1028-1042` | Service page is an ordinary URL button; Mini App is a `web_app` button. Only the latter moves. Main-menu availability at line 71 uses MiniAppLinks. |
| `src/Command/SetTelegramMenuButtonCommand.php:18-61` | Uses MiniAppLinks public URL, independent of `miniapp.enabled`; command supports --remove and --dry-run, not a URL override. Generated URL moves; configured per-chat overrides require separate operator review. Existing Telegram menu configuration does not update itself. |
| `src/Command/SetTelegramWebhookCommand.php:29-44` | Required baseUrl argument, appends `/telegram/webhook/{secret}` and calls Telegram. Keep cloud argument; never pass brand resolver. `deploy.sh:2412` sets it from DOMAIN. Do not log the URL secret. |
| `src/MiniApp/Guide/GuideStartHandler.php:44-55` | Guide start payload becomes MiniAppLinks web_app URL; moves with MiniAppLinks. |
| `src/MiniApp/Guide/GuideLinks.php:69`, `MiniAppGuide.php:54` | Internal help topic links are relative Mini App routes. Stay same-origin. GuideRenderer/GuideTextRules also permit authored external HTTPS links; these do not migrate automatically. |
| `src/Subscription/Application/SubscriptionPageUrl.php:24` | Absolute `/s/{token}`, request-context dependent today. Must use operational origin explicitly, including when invoked from branded Mini App. Public URL policy still applies. |
| `src/Subscription/Application/AppDownloadCatalog.php:85`, `src/Admin/UI/Crud/AppDownloadCrudController.php:103` | Absolute `/d/{id}` download URL. Pin cloud. Also change the relative path() used by forPage() at lines 42-80 to an operational absolute URL when rendered in the branded Mini App; otherwise /d returns 404 there. External destinations resolved by download delivery remain unchanged. |
| `src/Bot/Application/SubscriptionPageButton.php:32`, `ServiceManagementService.php:1527` | Delivery/service buttons use SubscriptionPageUrl; keep cloud. Delivery text/config comes from stored service/panel data, not the brand origin. |
| `src/Provisioning/Application/PanelLinkSnapshot.php:103-173` | Reads panel's subLinks and stores config links/text and subscription URL. Not a brand link builder. Never replace panel/VPN hosts with either web host. |
| `src/Provisioning/Application/VpnAccessLinkGenerator.php:298-308`, `Infrastructure/Sanaei3xui/Modern/SubscriptionUrl.php:18` | Panel/inbound/public subscription bases determine VPN access links. Independent of DEFAULT_URI. |
| `src/Payment/Domain/PaymentReturnUrl.php:30-37` | Concatenates supplied base with unguessable return-token path and optional `/cancel`; no global URL configuration inside it. Give it validated brand-or-legacy base only at browser-return call sites. |
| `src/Payment/UI/PaymentWebhookController.php:145-153` | Browser callback currently redirects to relative `payment_return`, hence same callback origin. Needs explicit brand URL after verification, not a change to callback ingress. |
| `src/Payment/Infrastructure/CustomApiGateway.php:492-495` | Absolute callback_url, return_url, webhook_url share request router context today. Split operational callback/webhook from brand return; audit persisted custom request templates too. |
| `src/Payment/Infrastructure/NowPaymentsGateway.php:108-127` | IPN and success/cancel URLs share callback_base_url; must split bases. Config success_url/cancel_url examples are not the current tokenized URL generator. |
| `templates/miniapp/entry.html.twig:14-17` | Root-relative CSS/JS/font; Telegram JS is external. No global asset-domain change necessary. |
| `templates/public/layout.html.twig:32,269-272` | Return layout uses local Mini App font and relative brand_logo route. Allow that narrowly on brand host. Branding name/accent/logo is shared, not host-specific. |
| `templates/payment/success.html.twig:10-14,51-53,91` | Redirects to guarded panel subscription URL, refreshes same page, and retries request URI. No Mini App cookie/session required. Do not rewrite access URLs. |
| `src/Branding/SupportLinkSettings.php:25-57` | Validated Telegram support URL, not an app origin. Keep unchanged. `BrandingSettings.php:15-23` stores logo filename/name/accent, no domain setting. |
| `src/Reseller/Application/ClaimLinks.php:377-385` | `t.me/{bot}?start=...` / `?startapp=...`; bot identity stays, BotFather Main Mini App destination changes. Share URLs in `MiniAppResellerClaimController.php:104` stay Telegram URLs. |
| `src/Admin/UI/AdminLocaleController.php:56`, `PaymentModerationController.php:107` | Same-host referer validation for admin navigation; stays on cloud. No brand exception needed. |

Telegram API/file origins (`TelegramApiClient.php:200,262,292,392`), Zibal/Winapay/NovinoPay/
SwapWallet/NOWPayments API and checkout hosts, panel API/proxy addresses, client-app import
schemes/store URLs, required-channel invite URLs and dummy-driver example URLs are external
integration destinations, not aliases for the app. Do not substitute them. The source index
in Appendix B records the remaining URL builders, literal origins and relevant configuration.

## 3. Database persistence and already-issued links

Doctrine uses underscore-number-aware naming (`config/packages/doctrine.yaml:16`). Names below
are mapped table/column names; Appendix C maps properties to source lines. Do not run a bulk
domain replacement. A single row can contain application URLs, provider URLs, VPN credentials,
and immutable historical evidence together.

| Table / columns | Domain-change effect |
| --- | --- |
| `vpn_service.subscription_url`, `config_links` JSON, `config_text` | Persisted panel/VPN links, including absolute hosts. Keep unchanged. `public_token` identifies `/s/` but is not a stored app URL. PanelLinkSnapshot writes the panel answer under identity/locking rules; do not regenerate just to rebrand. |
| `vpn_panel.base_url`, `subscription_base_url`, `public_host`, `config` JSON | Panel API host and public access host, legacy config bases. Operational VPN infrastructure; unchanged. |
| `vpn_inbound.host`, `host_header`, `sni`, `config` JSON | VPN transport/host/proxy overrides; unchanged. |
| `payment_gateway.config` JSON | callback_base_url, API endpoints, custom templates, and plugin settings can store absolute URLs. Retain callback base cloud. Add browser-return semantics deliberately; never expose secret values in review output. |
| `payment.payment_url`, `crypto_invoice_url` | Provider checkout URLs; keep existing invoices usable. Their provider-side return URL was fixed when created and will not change by editing an env setting. |
| `payment.request_payload`, `callback_payload`, `verify_payload`, `ipn_payload` JSON | May contain absolute request/response/callback URLs depending on driver and sanitization. Historical evidence, not URLs to rewrite. Zibal and NOWPayments explicitly save request payloads (`ZibalGateway.php:78`, `NowPaymentsGateway.php:139,172`); custom API saves sanitized payload (`CustomApiGateway.php:81`). Plugin adapter passes config to plugin (`PluginPaymentGatewayDriverAdapter.php:44-46`); PaymentStarter.php:119-120 stores returned rawResponse as request_payload, which can overwrite a driver-saved request body. Therefore a payload column does not guarantee the original request URL survives. Do not infer every plugin request URL is stored. |
| `payment.return_token` | Token only, reusable on either allowed return origin. Existing token URLs on cloud continue working. Never print a real token. |
| `setting.key_name`, `value` | Free-form settings: support URL, authored guide texts and any administrator-provided URLs may be here. Brand logo is a filename, not an absolute URL. New proposed env setting requires no data migration. |
| `bot_message_template.body`, `broadcast_message.body` and related metadata | Authored content may embed hardcoded old-domain links. Old links stay valid. Future editorial updates must distinguish Mini App vs subscription/admin links; queued broadcasts may already contain old URLs. |
| `bot_message_log.payload` JSON | Incoming/outgoing Telegram content/keyboards may preserve absolute URLs. Historical; do not rewrite or dump. |
| `service_notification_log.payload` JSON | Caller-supplied notification metadata is stored, not automatically the rendered text/keyboards (`ServiceNotificationService.php:272-321`). Delivery-state rows use payment_id/attempts/accepted/created/claim (`PaymentDeliveryLog.php:11-16,52-55`). No authoritative app-origin column; preserve evidence and deduplication. Telegram message logs are the possible rendered-link carrier. |
| `app_download.external_url`, `client_app.store_url`, `import_url_template`, `required_channel.invite_url` | Configured external URLs/templates. Usually unrelated; only individually reviewed authored app-domain links might need editorial changes. `/d/{id}` itself is generated, not stored here. |
| `order_draft.price_snapshot` and other JSON/text snapshots, broadcast delivery/error and admin audit payloads | Can contain copied/configured content indirectly; not an authoritative app-origin setting. Review with a redacted count-only scan in a separately authorized non-production copy if needed. No evidence-based claim of actual hostname presence can be made without data. |

Previously sent Telegram buttons, configured menu buttons, BotFather URLs, provider-side invoices,
browser bookmarks and copied subscriptions live outside this database as well. Keeping cloud
routes operational is the compatibility mechanism. New link generation does not edit old messages.

## 4. Payment gateway split, especially Winapay

### Winapay: actual current protocol

`docs/plugins/winapay-gateway/src/WinapayGatewayPlugin.php:32,60-74` supplies one `CallbackURL`:
`{callback_base_url}/payment/callback/plugin/winapay?rt={return-token}`.
Lines 238 onward explicitly describe the unsigned browser callback. It does not configure a
separate server-to-server webhook. PaymentVerification is an outbound server-to-server API
request, not an inbound callback URL. The stored authority and expected amount are verified;
unsigned query values alone must never mark paid (`:126-155`). The core callback processes the
notification and issues a 303 to relative `/payment/return/{token}` (`PaymentWebhookController.php:145-153`).
Thus it is inaccurate to say Winapay independently builds RETURN and CALLBACK from one base:
the plugin builds the browser callback from that base, and core builds the final page locally.

**Option A (proposed):** keep callback_base_url = `https://cloud.mr13.win`. Keep Winapay's
registered callback domain **cloud.mr13.win** (repository README:50-61; status -10 at
`WinapayStatus.php:28`). Modify core's post-callback redirect to the validated brand return URL.
No Winapay plugin protocol change is required. The browser briefly visits cloud for callback
processing and then stack for the visible result. No callback route is allowed on stack.
No new stack callback domain registration is required by the repository-described protocol;
confirm merchant-panel UI restrictions with the operator before rollout.

**Option B (only if browser must never visit cloud):** set CallbackURL to stack and allow a
Winapay callback there, verifying on the same install before return. Register **stacktunnel.net**
as the Winapay callback domain; preserve old cloud registrations if the panel permits multiple
domains, otherwise outstanding invoices need a provider-approved transition. This breaks the
stated route boundary, so it is not the proposed design and needs an explicit scope decision.
Adding a return_base_url key to Winapay alone does nothing unless core consumes it; there is
no second provider return field in this plugin. A true separate inbound server callback would
require a provider-supported protocol absent from this code. Do not invent a panel field.

| Other implementation | Current split / required change |
| --- | --- |
| `docs/plugins/novinopay-gateway/src/NovinoPayGatewayPlugin.php:52-58,246-254` | callback_url + callback_method GET; callback_base_url plus token. Same browser-callback/core-redirect solution as Winapay. Keep registered callback cloud; no broad brand callback allowlist. |
| `src/Payment/Infrastructure/ZibalGateway.php:58-78,234-240` | Unsigned browser callback with token; keep cloud callback base and split final core redirect. |
| `src/Payment/Infrastructure/NowPaymentsGateway.php:108-127` | Has distinct ipn_callback_url and success_url/cancel_url fields but derives all from callback_base_url. Keep IPN cloud; give success/cancel validated brand base with old gateway base fallback when unset. Preserve payment mode behavior. |
| `docs/plugins/swapwallet-gateway/src/SwapWalletGatewayPlugin.php:344-345,374,388-418` | Distinct returnUrl and webhookUrl, both normally from callback_base_url; success_url is fallback only when base absent. Add optional resolved return base for returnUrl; leave webhook builder cloud and HMAC handling unchanged. Coin-payment mode does not necessarily send a browser return field. |
| `src/Payment/Infrastructure/CustomApiGateway.php:492-495` | Separate placeholders return_url/callback_url/webhook_url, all use shared router context. Pin callbacks/webhooks to cloud and return to brand. Review actual configured templates: literal URLs and misused placeholders do not fix themselves. |
| `docs/plugin-sdk/payment-gateway-template/src/GatewayPlugin.php.example:34` and installed plugin contract | Update SDK guidance and optional runtime browser-return configuration. Upgrade packaged example code and actually installed plugin versions together; changes under docs/plugins alone do not update deployed plugin artifacts. |
| `docs/plugins/demo-payment-gateway/src/DemoPaymentGatewayPlugin.php:27-43` | Demo only: returns configured payment_url or example.com fallback; builds neither callback nor tokenized return URL. No production split to activate here; do not enable testing shortcuts. |
| Manual card / wallet | No gateway browser-return URL to register. Preserve receipts, approval and settlement. |

Do not overwrite per-gateway callback_base_url to stack. Preserve unsigned-notification rejection,
signed webhook verification, amount matching, settlement idempotence, order ownership checks,
and outstanding invoices. No new general redirect query parameter is needed.

## 5. Routes, assets, firewalls and nginx boundary

Appendix A is the complete static Mini App route inventory at the baseline, including reseller
and admin routes. Each row includes its attribute requirements/methods and source line. No
runtime route command was run, avoiding database/bootstrap side effects. No host constraints
are present in these route attributes. Symfony also accepts HEAD for GET routes; plan edge
method policy accordingly. Unknown paths and non-allowlisted methods get 404 at brand ingress.

| Surface | Firewall / authentication | Brand policy |
| --- | --- | --- |
| GET `/app` | `miniapp`, public shell | allow |
| POST `/app/auth` | `miniapp`, public endpoint; JSON initData HMAC and timestamp verification | allow with auth limits |
| All other customer and reseller `/app/view/*`, `/app/api/*`, POST `/app/logout` | stateless `miniapp`, ROLE_MINIAPP_USER; bearer session; writes require X-CSRF-Token; controllers/services enforce ownership, reseller rights, section availability, confirmation and throttles | allow only enumerated routes/methods |
| `/app/admin` and descendants; `/app/api/admin/*` | miniapp role plus AdminRouteAccess permission/freshness checks | deny on brand, even authenticated admins |
| GET `/payment/return/{token}` and `/{token}/cancel` | `main`, no login rule/IsGranted; possession of random token identifies payment | allow; exact token pattern from PaymentReturnUrl |
| `/brand/logo/{name}` GET/HEAD | `main`, public, validates current stored logo name | allow exact route only |
| `/miniapp/app.js`, `/miniapp/app.css`, `/miniapp/fonts/Vazirmatn-wght.woff2` GET/HEAD | nginx static, not Symfony routes | allow these exact files only |
| `/d/{id}`, `/s/{token}`, `/admin*`, `/telegram/webhook*`, `/payment/callback*`, `/payment/webhook*`, `/healthz`, `/index.php*`, all other files/routes | operational routes; retain existing firewalls/auth on cloud | 404 on brand |
| Legacy `/payment/success`, `/payment/cancel` | main public legacy pages | cloud only; no need on brand for newly issued token URLs |

References: `config/packages/security.yaml:15-73`, `MiniAppAuthenticator.php:31-70`,
`MiniAppAuthController.php:56-105`, `MiniAppAdminGuard.php`, `MiniAppSectionGuard.php`,
`PaymentReturnController.php:54-119`, `BrandLogoController.php:28-37`.
The return page needs no extra status API: pending state refreshes its own URL after ten seconds;
verification and settlement occur server-side (`PaymentReturnController.php:56-95,133-179`).
Receipt uploads need the existing body limits/timeouts even though large admin APK uploads are denied.

### Proposed nginx allowlist shape (design, not an applied configuration)

Generate a reviewed map from Appendix A's **customer/reseller** routes and methods, replacing
parameters with their exact route requirements; never use a broad `/app/` allow. Example skeleton:

```nginx
# http {} scope: generated map entries include every permitted route, with anchored regexes.
map "$request_method:$uri" $brand_route_allowed {
    default 0;
    ~^(GET|HEAD):/app$ 1;
    ~^POST:/app/auth$ 1;
    ~^POST:/app/logout$ 1;
    ~^(GET|HEAD):/app/api/me$ 1;
    # Expand ALL remaining non-admin Appendix A entries here before deployment.
    # Escape regex literals; substitute id/ticket/action requirements, never arbitrary .*
    "~^(GET|HEAD):/payment/return/[A-Za-z0-9_-]{32,64}(/cancel)?$" 1;
    "~^(GET|HEAD):/brand/logo/[a-f0-9]{32}\\.(png|jpg|webp)$" 1;
    "~^(GET|HEAD):/miniapp/app\\.(js|css)$" 1;
    "~^(GET|HEAD):/miniapp/fonts/Vazirmatn-wght\\.woff2$" 1;
}
server {
    listen 443 ssl;
    server_name stacktunnel.net;
    # Same release public root / FPM socket; dedicated TLS certificate and safe logs.
    # Existing security headers, body limits and rate limits must be included explicitly.
    location = /miniapp/app.js { if ($brand_route_allowed = 0) { return 404; } try_files $uri =404; }
    location = /miniapp/app.css { if ($brand_route_allowed = 0) { return 404; } try_files $uri =404; }
    location = /miniapp/fonts/Vazirmatn-wght.woff2 {
        if ($brand_route_allowed = 0) { return 404; } try_files $uri =404;
    }
    location / {
        if ($brand_route_allowed = 0) { return 404; }
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root/index.php;
        fastcgi_param SCRIPT_NAME /index.php;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock; # use deployment PHP_VERSION
    }
}
```

This is deliberately incomplete until the generated map, root/TLS/logging and existing hardening
are rendered and reviewed in an implementation PR. The method map returns 404 for disallowed static methods too. There must be **no** generic
PHP location or `try_files ... /index.php` bypass on brand, no inclusion of operational
`app_locations()`, no catch-all static file exposure, and no root redirect to admin. Set correct
HTTPS fastcgi parameters using the audited existing deployment template. Preserve query strings.
Review nginx normalization and test encoded slashes/dots, repeated slashes, PATH_INFO and
`/index.php/admin` bypasses. Repeat the same allowlist in an early Symfony request guard for
defense if another ingress reaches FPM. Unknown hosts must be rejected independently.

HTTP :80 should serve ACME challenges only as a narrowly defined infrastructure exception and
redirect approved customer paths to the same fixed HTTPS hostname; other paths return 404.
DNS-01 can avoid the challenge-path exception if a literal route-only policy is required.
Keep `/healthz` cloud-only; use `/app` plus static and negative probes to monitor brand.

Admin navigation also needs a host-aware presentation decision: auth can currently identify an
admin on either host, and navigation includes admin sections (`MiniAppSections` and the Mini App UI). Denying endpoints must remain the
security boundary; hiding admin links prevents a broken customer experience but is insufficient
alone. Reseller routes are customer functionality with authorization; confirm their inclusion.

## 6. Security effects

- **Host validation:** no explicit trusted_hosts configuration found in active framework config;
  `config/reference.php` is generated reference, not an active setting. Configure anchored exact
  operational/brand host patterns for production and nginx default-host rejection. Do not trust
  arbitrary forwarded host/proto headers. If a reverse proxy is present, explicitly verify its
  trusted proxy/header configuration. Do not allow Host to choose an outbound callback origin.
- **Cookies/sessions:** framework session is enabled (`framework.yaml:9-10`), main admin login
  and remember-me use it. No explicit cookie_domain override found. Keep cookies host-only;
  these unrelated registrable domains cannot share a cookie domain. Mini App is stateless and
  uses BearerTokenTransport, not Symfony login cookies (`security.yaml:19-26`,
  `BearerTokenTransport.php:10-33`). sessionStorage is origin-isolated, so a user switching hosts
  authenticates again; do not transfer tokens via URLs or cross-domain cookies.
- **CSRF/CORS:** writes require session-bound X-CSRF-Token (`MiniAppAuthenticator.php:61-65`);
  auth is JSON only (`MiniAppAuthController.php:64-66`). Retain admin form CSRF. No app CORS
  allowance found. Serve Mini App HTML/assets/APIs on the same brand origin; do not add wildcard
  CORS or cross-origin credential support. Download/subscription links navigate to cloud rather
  than fetch cloud APIs. A second host is not an authentication boundary by itself.
- **CSP/framing:** `MiniAppHeaders.php:15` allows Telegram scripts, same-origin connections,
  and frame-ancestors https://web.telegram.org. The second host fits `'self'`; do not add cloud
  to connect-src merely to compensate for incorrect URL generation. Preserve other headers.
  Return layout is self-contained (`templates/public/layout.html.twig:8-11`); return controller
  explicitly sets no-store/private, no-referrer and noindex (`PaymentReturnController.php:234-240`).
  Verify effective nginx headers on both hosts; copying a config can change/duplicate CSP or
  X-Frame-Options. Do not introduce framing restrictions that break Telegram Web.
- **Telegram initData:** `InitDataValidator.php:44-89,131-145` checks bot HMAC, auth_date, user,
  duplicates and length. It does not bind the signature to a hostname. Same bot tokens remain;
  retain age/skew validation. Telegram client configuration must point to the new HTTPS URL.
- **Limits:** auth IP/user, checkout, receipt, reseller and claim buckets are in
  `config/packages/rate_limiter.yaml:16-99`; keep one shared storage/key space across both hosts
  so using cloud is not a bypass. Main login throttle stays cloud. Return GETs can trigger
  provider verification, so propose explicit return-page rate limiting with realistic refresh
  allowance; preserve settlement idempotence. Forwarded client IP must be trustworthy.
- **Secrets/logs/cache:** return tokens, claim tokens, webhook secrets and initData must not
  enter query-bearing access logs. Brand logs need redaction/suppression for tokenized paths,
  not just removal of query strings; no analytics or shared-cache storage for private views.
  Review redirects and Referrer-Policy end-to-end. Never cache token HTML at a CDN.
- **Host-based behavior:** current admin locale/moderation referer checks are same-origin;
  current Mini App authentication/route authorization is path/identity based, not host based.
  Add the proposed host route guard with explicit operational fallback, without broadening
  customer or admin permissions. Keep provider callback validation independent of brand host.

## 7. Proposed configuration and implementation contract

Introduce optional **`CUSTOMER_BRAND_BASE_URL`**, empty by default, and an immutable scoped
resolver. Keep DEFAULT_URI and deployment DOMAIN operational. Empty/unset resolves to each
call site's current legacy base (router baseline for MiniAppLinks, existing gateway callback
base for direct return fields). With feature unset, URLs and behavior must match baseline.

Validate the entire candidate before exposing it to any producer: absolute HTTPS origin,
public DNS hostname, no credentials/query/fragment, no non-root path, no whitespace/control
characters or scheme-relative syntax; normalize one trailing slash and case. For this rollout,
accept only the approved `stacktunnel.net` hostname (and any explicitly approved aliases),
not arbitrary redirect targets. Decide port policy explicitly; production proposal is 443 only.
Use existing PublicUrlPolicy as one check, not a replacement for the stricter origin contract.

Invalid nonempty value should **refuse activation/startup** consistently with an actionable
redacted diagnostic; it must never switch Mini App links but not payment return links. Empty
is the fallback mode. Validate in deploy preflight before env compilation/cache warming and
restart all long-running workers after configuration changes. One resolver result is used by
every producer; do not catch validation errors independently and silently select different bases.
Keep a previous valid configuration/release for rollback. No runtime administrative setting or
database migration is needed in the first implementation. If a settings UI is later wanted,
validate and activate transactionally; env and DB must not become competing authorities.

Provide dedicated methods for Mini App entry/guide/service URLs and payment return/cancel
URLs, plus an operational-origin builder for `/s`, `/d`, callbacks/webhooks. Generate path and
query via the router, combine with the intended origin without mutating shared RequestContext.
Preserve gateway callback bases when the brand is unset. Explicit operational URL use when
brand is enabled prevents request-origin contamination. Add no generic user-controlled redirect.

Before activating the env setting, deploy the restrictive vhost, TLS, route guard and all URL
producer/plugin support together. Brand routing and URL publication are distinct stages: the
vhost can be tested before advertising it, but no producer should publish the new origin until
all required routes and dependencies pass. For old invoices, keep cloud callback and return
handling available regardless of the feature state. Rollback must keep already-issued brand
URLs served too; switching future generation back does not revoke them.

## 8. Deployment assumptions and required future changes

| Source | Single-domain assumption / required future change |
| --- | --- |
| `deploy.sh:76,114-119,1410-1452` | One nginx site, DOMAIN validation, app env allowlist. Add explicit optional brand input/env persistence and validation; never replace DOMAIN. |
| `deploy.sh:1912-1917` | DEFAULT_URI defaults to https://${DOMAIN}; retain this. Include new variable in env compilation/redeploy behavior. |
| `deploy.sh:2020,2033-2198` | Certificate directory and inline nginx renderers use DOMAIN. There is no separate standalone nginx template to edit: app_locations() exposes generic routes. Render brand server separately with strict map; same public root/FPM and maintenance handling for payment verification. |
| `deploy.sh:2212-2235` | Rewrites site on deploy; certbot certonly --nginx -d DOMAIN, one certificate lineage, timer/hook. Provision brand cert separately or approved SAN expansion, both renew correctly; support first-cert bootstrap before :443 references exist. A manual vhost must not be overwritten on next deploy. |
| `deploy.sh:2412,2453-2468` | Sets Telegram webhook from DOMAIN; keep cloud. Health/HSTS probes only DOMAIN; add customer-only probes and negative route checks for brand, with its own TLS/HSTS activation. |
| `bin/post-deploy-check.sh:26-28,79-84,109-126,158-200` | One site/DOMAIN inferred from env/server_name; expects /healthz, /admin/login and webhook on that host. Keep full checks cloud; separate brand checks must expect admin/webhook/healthz 404. Do not run existing suite against brand unchanged. Script also has live DB checks; audit did not execute it. |
| `bin/check-scheduled-jobs.sh:26-38` / installed `amoobot-check-jobs` | Job manifest/state/timers/backups, not a hostname probe. No second scheduler required. Outbound monitor ping config is not a customer origin. Keep one worker/job set and avoid duplicated deliveries. `deploy.sh:71,1229` installs its unit. |
| `docs/en/installation.md:20-34` | Describes one public URL, per-gateway callback_base_url. Document two roles explicitly, DEFAULT_URI cloud, optional brand, allowlist and provider differences. |
| `docs/en/miniapp.md:14,35,60,158`, `ubuntu_24_amoobot_deploy_script.md` | Existing instructions assume Mini App equals DEFAULT_URI/shop domain. Update examples and troubleshoot both origins without moving webhook. Persian installation/deployment/payment/Mini App docs and plugin READMEs need matching guidance. |

## 9. Rollout and rollback checklist

### Rollout (future authorized operational work)

- Record current release/env, nginx config/cert lineage, BotFather/Main Mini App/menu settings,
  and each active gateway's callback/return settings. Do not copy secrets into tickets.
- Confirm canonical host/aliases and admin/reseller policy. Inventory installed plugin versions
  and outstanding invoices. Keep cloud DNS/TLS and all existing paths unchanged.
- Add stack A/AAAA records only for reachable addresses; verify IPv4 and IPv6. Provision and
  test TLS with SNI, certificate chain and renewal before publishing new links.
- Deploy the new URL builders/guards/plugin versions with brand unset. Verify legacy URLs
  byte-for-byte, cloud webhook/admin/subscription/download/payment flows and scheduled jobs.
- Install the separate restrictive vhost with complete map, static dependencies, body limits,
  safe logs and monitoring. Test origin directly using host/SNI override before DNS cutover.
- On stack verify shell, JS/CSS/font/logo, valid initData sign-in, help topics, service views,
  checkout/receipt/status/claim/reseller flows as applicable; ensure data/ownership checks and
  CSRF still reject unauthorized attempts. Cloud `/s` and `/d` links must navigate correctly.
- Assert 404 for admin login, EasyAdmin, `/app/admin`, `/app/api/admin/*`, Telegram webhook,
  all payment callback/webhook variants, `/s`, `/d`, healthz, arbitrary static files, PHP paths,
  malformed tokens, disallowed methods and normalization bypasses. Repeat as an authenticated
  admin, not just anonymously. Check wrong Host and forwarded-Host handling.
- Validate and enable CUSTOMER_BRAND_BASE_URL once every URL producer is ready. Compile env,
  warm cache and restart workers via normal deployment. Verify newly generated bot and guide
  web_app buttons, menu command dry-run and provider request fields with redacted assertions.
- BotFather: change Main Mini App URL and any configured launch surfaces to stack `/app`;
  update default/per-chat menu overrides as applicable with the command/API. Keep bot identity
  and webhook cloud. Test Telegram Android/iOS/Desktop/Web launches, including startapp claims.
- Winapay Option A: retain callback_base_url and panel registered callback domain cloud; verify
  callback processing then 303 to stack token page. Do not select Option B without scope approval.
  NOWPayments/SwapWallet: set distinct generated browser returns, retain operational IPN/webhook;
  review their provider-side registered domains/settings if required by the actual merchant account.
- Use sandbox/non-production purchases to verify paid/pending/cancel/failed, duplicate callbacks,
  retries, late payment, ownership transfer, old outstanding invoice, old cloud return URL,
  receipt and notification delivery. Observe both hosts without logging bearer URLs.
- Verify TLS expiry/renewal, safe caching/security headers, unchanged cloud health/admin/webhook
  and job monitoring; then publish the new customer links. Document operator evidence separately.

### Rollback

- Clear the brand variable to restore legacy future URL generation; deploy validated env/cache
  and restart workers. Restore Telegram launch/menu URLs to cloud. Keep webhook and Winapay
  registration cloud throughout Option A, so they need no rollback change.
- Restore other gateways' future return generation/settings if changed; retain stored invoices,
  return tokens and historical payloads. Do not regenerate payments or settle based on redirects.
- Keep stack DNS/TLS and restrictive customer routes serving already-issued links. Do not remove
  the brand vhost or revert to code that cannot serve issued links until an explicit compatibility
  window and policy are agreed. If stack is unavailable, roll future publication back promptly;
  cloud provides the operational path but cannot repair an already-delivered dead hostname.
- Recheck cloud end-to-end and stack negative boundaries. No data migration rollback is required
  for the env-only proposal. Never roll back payment ledger changes as part of a domain rollback.

## 10. Suggested small PRs and risks

| PR | Scope / evidence required | Risk |
| --- | --- | --- |
| 1 | Scoped origin resolver, strict validation, disabled-default compatibility tests; no activation | Low-medium: URL byte compatibility and worker/env consistency. |
| 2 | Operational URL pinning for callbacks/webhooks, `/s`, `/d`; brand-aware MiniAppLinks/menu/guide; host guard and admin navigation suppression | Medium: incoming request-context contamination and accidentally blocking cloud/reseller features. Test two hosts and admin identities. |
| 3 | Core post-callback brand redirect; NOWPayments/SwapWallet/custom API split; SDK/plugin versioned artifacts/docs | High: outstanding payments, plugin deployment mismatch, settlement/verification regressions. Verify per-gateway request fields and duplicate notifications. Winapay protocol stays unchanged for Option A. |
| 4 | Optional dual-host deploy/TLS/nginx renderer and post-deploy brand probes, env allowlist; no automatic publication | High: nginx allowlist bypass, cert bootstrap/renewal, maintenance/logging and redeploy overwrite. Test rendered config and isolated deployment, never production in audit. |
| 5 | Operator documentation and controlled activation after all dependencies; rollback evidence | Medium-high: external BotFather/provider configuration and already-issued links. No big-bang DEFAULT_URI change. |

Each implementation PR should pass its code tests/static analysis and appropriate isolated nginx/
deployment tests. This audit PR is documentation only; per task instruction no PHPUnit/phpstan
run is needed. No application/config/deployment files were changed or executed for verification.

## Appendix A. Complete Mini App and payment return route attributes

The inventory below is extracted from tracked source at the baseline. `miniapp` firewall applies
to every `/app` row. Entry/auth are public as described above; other rows require Mini App role,
with customer ownership/reseller permissions or additional AdminRouteAccess checks. Admin rows
are listed to make the denied surface explicit; none is a proposed brand allowlist entry.
GET implies HEAD in Symfony. Return/logo rows use main firewall and bearer-token/current-logo
validation rather than Mini App login. Requirements are preserved verbatim to avoid loosening them.

| Source | Policy | Route attribute (path, methods, requirements) |
| --- | --- | --- |
| `src/MiniApp/UI/Admin/MiniAppAdminAppsController.php:58` | DENY admin | `#[Route('/app/admin/apps', name: 'miniapp_admin_apps', defaults: ['_miniapp_section' => 'admin.apps'], methods: ['GET'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminAppsController.php:72` | DENY admin | `#[Route('/app/admin/client-apps/{id}', name: 'miniapp_admin_client_app', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.apps'], methods: ['GET'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminAppsController.php:85` | DENY admin | `#[Route('/app/api/admin/client-apps/{id}', name: 'miniapp_admin_client_app_update', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.apps'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminAppsController.php:109` | DENY admin | `#[Route('/app/api/admin/client-apps/check', name: 'miniapp_admin_client_app_check', defaults: ['_miniapp_section' => 'admin.apps'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminAppsController.php:120` | DENY admin | `#[Route('/app/api/admin/client-apps/{id}/activate', name: 'miniapp_admin_client_app_activate', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.apps'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminAppsController.php:126` | DENY admin | `#[Route('/app/api/admin/client-apps/{id}/deactivate', name: 'miniapp_admin_client_app_deactivate', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.apps'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminAppsController.php:132` | DENY admin | `#[Route('/app/api/admin/client-apps/{id}/move', name: 'miniapp_admin_client_app_move', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.apps'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminAppsController.php:140` | DENY admin | `#[Route('/app/admin/downloads/new', name: 'miniapp_admin_download_new', defaults: ['_miniapp_section' => 'admin.apps'], methods: ['GET'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminAppsController.php:146` | DENY admin | `#[Route('/app/admin/downloads/{id}', name: 'miniapp_admin_download', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.apps'], methods: ['GET'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminAppsController.php:153` | DENY admin | `#[Route('/app/api/admin/downloads', name: 'miniapp_admin_download_create', defaults: ['_miniapp_section' => 'admin.apps'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminAppsController.php:175` | DENY admin | `#[Route('/app/api/admin/downloads/{id}', name: 'miniapp_admin_download_update', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.apps'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminAppsController.php:198` | DENY admin | `#[Route('/app/api/admin/downloads/check', name: 'miniapp_admin_download_check', defaults: ['_miniapp_section' => 'admin.apps'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminAppsController.php:210` | DENY admin | `#[Route('/app/api/admin/downloads/{id}/activate', name: 'miniapp_admin_download_activate', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.apps'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminAppsController.php:216` | DENY admin | `#[Route('/app/api/admin/downloads/{id}/deactivate', name: 'miniapp_admin_download_deactivate', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.apps'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminAppsController.php:222` | DENY admin | `#[Route('/app/api/admin/downloads/{id}/move', name: 'miniapp_admin_download_move', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.apps'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminBroadcastController.php:73` | DENY admin | `#[Route('/app/admin/broadcasts', name: 'miniapp_admin_broadcasts', defaults: ['_miniapp_section' => 'admin.broadcast'], methods: ['GET'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminBroadcastController.php:94` | DENY admin | `#[Route('/app/admin/broadcasts/new', name: 'miniapp_admin_broadcast_new', defaults: ['_miniapp_section' => 'admin.broadcast'], methods: ['GET'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminBroadcastController.php:100` | DENY admin | `#[Route('/app/admin/broadcasts/{id}', name: 'miniapp_admin_broadcast', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.broadcast'], methods: ['GET'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminBroadcastController.php:106` | DENY admin | `#[Route('/app/api/admin/broadcasts', name: 'miniapp_admin_broadcast_create', defaults: ['_miniapp_section' => 'admin.broadcast'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminBroadcastController.php:130` | DENY admin | `#[Route('/app/api/admin/broadcasts/{id}', name: 'miniapp_admin_broadcast_update', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.broadcast'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminBroadcastController.php:162` | DENY admin | `#[Route('/app/api/admin/broadcasts/check', name: 'miniapp_admin_broadcast_check', defaults: ['_miniapp_section' => 'admin.broadcast'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminBroadcastController.php:184` | DENY admin | `#[Route('/app/api/admin/broadcasts/{id}/test', name: 'miniapp_admin_broadcast_test', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.broadcast'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminBroadcastController.php:207` | DENY admin | `#[Route('/app/api/admin/broadcasts/{id}/prepare', name: 'miniapp_admin_broadcast_prepare', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.broadcast'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminBroadcastController.php:239` | DENY admin | `#[Route('/app/api/admin/broadcasts/{id}/send', name: 'miniapp_admin_broadcast_send', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.broadcast'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminBroadcastController.php:281` | DENY admin | `#[Route('/app/api/admin/broadcasts/{id}/cancel', name: 'miniapp_admin_broadcast_cancel', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.broadcast'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminBroadcastController.php:297` | DENY admin | `#[Route('/app/api/admin/broadcasts/{id}/retry', name: 'miniapp_admin_broadcast_retry', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.broadcast'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminBroadcastController.php:324` | DENY admin | `#[Route('/app/api/admin/broadcasts/{id}/duplicate', name: 'miniapp_admin_broadcast_duplicate', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.broadcast'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminCustomerController.php:48` | DENY admin | `#[Route('/app/admin/customers', name: 'miniapp_admin_customers', defaults: ['_miniapp_section' => 'admin.customers'], methods: ['GET'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminCustomerController.php:61` | DENY admin | `#[Route('/app/admin/customers/{id}', name: 'miniapp_admin_customer', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.customers'], methods: ['GET'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminDiscountController.php:57` | DENY admin | `#[Route('/app/admin/discounts', name: 'miniapp_admin_discounts', defaults: ['_miniapp_section' => 'admin.discounts'], methods: ['GET'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminDiscountController.php:83` | DENY admin | `#[Route('/app/admin/discounts/new', name: 'miniapp_admin_discount_new', defaults: ['_miniapp_section' => 'admin.discounts'], methods: ['GET'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminDiscountController.php:89` | DENY admin | `#[Route('/app/admin/discounts/{id}', name: 'miniapp_admin_discount', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.discounts'], methods: ['GET'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminDiscountController.php:95` | DENY admin | `#[Route('/app/admin/discounts/{id}/usages', name: 'miniapp_admin_discount_usages', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.discounts'], methods: ['GET'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminDiscountController.php:111` | DENY admin | `#[Route('/app/api/admin/discounts', name: 'miniapp_admin_discount_create', defaults: ['_miniapp_section' => 'admin.discounts'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminDiscountController.php:131` | DENY admin | `#[Route('/app/api/admin/discounts/{id}', name: 'miniapp_admin_discount_update', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.discounts'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminDiscountController.php:154` | DENY admin | `#[Route('/app/api/admin/discounts/check', name: 'miniapp_admin_discount_check', defaults: ['_miniapp_section' => 'admin.discounts'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminDiscountController.php:170` | DENY admin | `#[Route('/app/api/admin/discounts/generate-code', name: 'miniapp_admin_discount_generate', defaults: ['_miniapp_section' => 'admin.discounts'], methods: ['GET'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminDiscountController.php:176` | DENY admin | `#[Route('/app/api/admin/discounts/{id}/activate', name: 'miniapp_admin_discount_activate', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.discounts'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminDiscountController.php:182` | DENY admin | `#[Route('/app/api/admin/discounts/{id}/deactivate', name: 'miniapp_admin_discount_deactivate', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.discounts'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminDiscountController.php:188` | DENY admin | `#[Route('/app/api/admin/discounts/{id}/delete', name: 'miniapp_admin_discount_delete', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.discounts'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminHomeController.php:33` | DENY admin | `#[Route('/app/admin', name: 'miniapp_admin', defaults: ['_miniapp_section' => 'admin'], methods: ['GET'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminLogController.php:41` | DENY admin | `#[Route('/app/admin/logs', name: 'miniapp_admin_logs', defaults: ['_miniapp_section' => 'admin.logs'], methods: ['GET'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminLogController.php:66` | DENY admin | `#[Route('/app/admin/logs/{kind}/{id}', name: 'miniapp_admin_log_entry', requirements: ['kind' => 'audit&#124;notifications&#124;messages', 'id' => '\d+'], defaults: ['_miniapp_section' => 'admin.logs'], methods: ['GET'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminOrderController.php:39` | DENY admin | `#[Route('/app/admin/orders', name: 'miniapp_admin_orders', defaults: ['_miniapp_section' => 'admin.orders'], methods: ['GET'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminOrderController.php:54` | DENY admin | `#[Route('/app/admin/orders/{id}', name: 'miniapp_admin_order', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.orders'], methods: ['GET'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminPanelController.php:53` | DENY admin | `#[Route('/app/admin/panels', name: 'miniapp_admin_panels', defaults: ['_miniapp_section' => 'admin.panels'], methods: ['GET'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminPanelController.php:72` | DENY admin | `#[Route('/app/admin/panels/{id}', name: 'miniapp_admin_panel', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.panels'], methods: ['GET'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminPanelController.php:94` | DENY admin | `#[Route('/app/api/admin/panels/{id}/test', name: 'miniapp_admin_panel_test', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.panels'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminPanelController.php:112` | DENY admin | `#[Route('/app/api/admin/panels/{id}/activate', name: 'miniapp_admin_panel_activate', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.panels'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminPanelController.php:118` | DENY admin | `#[Route('/app/api/admin/panels/{id}/deactivate', name: 'miniapp_admin_panel_deactivate', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.panels'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminPaymentController.php:58` | DENY admin | `#[Route('/app/admin/payments', name: 'miniapp_admin_payments', defaults: ['_miniapp_section' => 'admin.payments'], methods: ['GET'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminPaymentController.php:73` | DENY admin | `#[Route('/app/admin/payments/{id}', name: 'miniapp_admin_payment', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.payments'], methods: ['GET'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminPaymentController.php:89` | DENY admin | `#[Route('/app/api/admin/payments/{id}/receipt', name: 'miniapp_admin_payment_receipt', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.payments'], methods: ['GET'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminPaymentController.php:102` | DENY admin | `#[Route('/app/api/admin/payments/{id}/approve', name: 'miniapp_admin_payment_approve', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.payments'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminPaymentController.php:108` | DENY admin | `#[Route('/app/api/admin/payments/{id}/retry', name: 'miniapp_admin_payment_retry', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.payments'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminPaymentController.php:114` | DENY admin | `#[Route('/app/api/admin/payments/{id}/reject', name: 'miniapp_admin_payment_reject', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.payments'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminPaymentController.php:120` | DENY admin | `#[Route('/app/api/admin/payments/{id}/reject-refunded', name: 'miniapp_admin_payment_reject_refunded', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.payments'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminPlanBulkController.php:51` | DENY admin | `#[Route('/app/admin/plans/bulk', name: 'miniapp_admin_plan_bulk', defaults: ['_miniapp_section' => 'admin.plans'], methods: ['GET'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminPlanBulkController.php:61` | DENY admin | `#[Route('/app/api/admin/plans/bulk/preview', name: 'miniapp_admin_plan_bulk_preview', defaults: ['_miniapp_section' => 'admin.plans'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminPlanBulkController.php:74` | DENY admin | `#[Route('/app/api/admin/plans/bulk/apply', name: 'miniapp_admin_plan_bulk_apply', defaults: ['_miniapp_section' => 'admin.plans'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminPlanController.php:64` | DENY admin | `#[Route('/app/admin/plans', name: 'miniapp_admin_plans', defaults: ['_miniapp_section' => 'admin.plans'], methods: ['GET'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminPlanController.php:101` | DENY admin | `#[Route('/app/admin/plans/new', name: 'miniapp_admin_plan_new', defaults: ['_miniapp_section' => 'admin.plans'], methods: ['GET'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminPlanController.php:107` | DENY admin | `#[Route('/app/admin/plans/{id}', name: 'miniapp_admin_plan', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.plans'], methods: ['GET'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminPlanController.php:122` | DENY admin | `#[Route('/app/api/admin/plans', name: 'miniapp_admin_plan_create', defaults: ['_miniapp_section' => 'admin.plans'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminPlanController.php:143` | DENY admin | `#[Route('/app/api/admin/plans/{id}', name: 'miniapp_admin_plan_update', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.plans'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminPlanController.php:169` | DENY admin | `#[Route('/app/api/admin/plans/check', name: 'miniapp_admin_plan_check', defaults: ['_miniapp_section' => 'admin.plans'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminPlanController.php:189` | DENY admin | `#[Route('/app/api/admin/plans/{id}/duplicate', name: 'miniapp_admin_plan_duplicate', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.plans'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminPlanController.php:208` | DENY admin | `#[Route('/app/api/admin/plans/{id}/activate', name: 'miniapp_admin_plan_activate', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.plans'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminPlanController.php:214` | DENY admin | `#[Route('/app/api/admin/plans/{id}/deactivate', name: 'miniapp_admin_plan_deactivate', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.plans'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminPlanController.php:221` | DENY admin | `#[Route('/app/api/admin/plans/{id}/delete', name: 'miniapp_admin_plan_delete', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.plans'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminServiceController.php:76` | DENY admin | `#[Route('/app/admin/services', name: 'miniapp_admin_services', defaults: ['_miniapp_section' => 'admin.services'], methods: ['GET'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminServiceController.php:92` | DENY admin | `#[Route('/app/admin/services/{id}', name: 'miniapp_admin_service', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.services'], methods: ['GET'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminServiceController.php:108` | DENY admin | `#[Route('/app/api/admin/services/{id}/resend-config', name: 'miniapp_admin_service_resend_config', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.services'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminServiceController.php:125` | DENY admin | `#[Route('/app/api/admin/services/{id}/sync-usage', name: 'miniapp_admin_service_sync_usage', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.services'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminServiceController.php:142` | DENY admin | `#[Route('/app/api/admin/services/{id}/suspend', name: 'miniapp_admin_service_suspend', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.services'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminServiceController.php:148` | DENY admin | `#[Route('/app/api/admin/services/{id}/activate', name: 'miniapp_admin_service_activate', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.services'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminServiceController.php:154` | DENY admin | `#[Route('/app/api/admin/services/{id}/reset-usage', name: 'miniapp_admin_service_reset_usage', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.services'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminServiceController.php:160` | DENY admin | `#[Route('/app/api/admin/services/{id}/extend', name: 'miniapp_admin_service_extend', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.services'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminServiceController.php:171` | DENY admin | `#[Route('/app/api/admin/services/{id}/add-traffic', name: 'miniapp_admin_service_add_traffic', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.services'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminServiceController.php:182` | DENY admin | `#[Route('/app/api/admin/services/{id}/regenerate-config', name: 'miniapp_admin_service_regenerate_config', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.services'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminServiceController.php:205` | DENY admin | `#[Route('/app/api/admin/services/{id}/delete', name: 'miniapp_admin_service_delete', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.services'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminSettingsController.php:53` | DENY admin | `#[Route('/app/admin/settings', name: 'miniapp_admin_settings', defaults: ['_miniapp_section' => 'admin.settings'], methods: ['GET'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminSettingsController.php:63` | DENY admin | `#[Route('/app/api/admin/settings/sales', name: 'miniapp_admin_settings_sales', defaults: ['_miniapp_section' => 'admin.settings'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminSettingsController.php:75` | DENY admin | `#[Route('/app/api/admin/settings/automation', name: 'miniapp_admin_settings_automation', defaults: ['_miniapp_section' => 'admin.settings'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminSettingsController.php:87` | DENY admin | `#[Route('/app/admin/settings/channels', name: 'miniapp_admin_channels', defaults: ['_miniapp_section' => 'admin.settings'], methods: ['GET'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminSettingsController.php:95` | DENY admin | `#[Route('/app/api/admin/channels', name: 'miniapp_admin_channel_create', defaults: ['_miniapp_section' => 'admin.settings'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminSettingsController.php:125` | DENY admin | `#[Route('/app/api/admin/channels/{id}/activate', name: 'miniapp_admin_channel_activate', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.settings'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminSettingsController.php:131` | DENY admin | `#[Route('/app/api/admin/channels/{id}/deactivate', name: 'miniapp_admin_channel_deactivate', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.settings'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminSettingsController.php:137` | DENY admin | `#[Route('/app/api/admin/channels/{id}/delete', name: 'miniapp_admin_channel_delete', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.settings'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminTrialController.php:57` | DENY admin | `#[Route('/app/admin/trial-plans', name: 'miniapp_admin_trial_plans', defaults: ['_miniapp_section' => 'admin.plans'], methods: ['GET'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminTrialController.php:66` | DENY admin | `#[Route('/app/admin/trial-plans/new', name: 'miniapp_admin_trial_plan_new', defaults: ['_miniapp_section' => 'admin.plans'], methods: ['GET'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminTrialController.php:72` | DENY admin | `#[Route('/app/admin/trial-plans/{id}', name: 'miniapp_admin_trial_plan', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.plans'], methods: ['GET'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminTrialController.php:78` | DENY admin | `#[Route('/app/api/admin/trial-plans', name: 'miniapp_admin_trial_plan_create', defaults: ['_miniapp_section' => 'admin.plans'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminTrialController.php:98` | DENY admin | `#[Route('/app/api/admin/trial-plans/{id}', name: 'miniapp_admin_trial_plan_update', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.plans'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminTrialController.php:120` | DENY admin | `#[Route('/app/api/admin/trial-plans/check', name: 'miniapp_admin_trial_plan_check', defaults: ['_miniapp_section' => 'admin.plans'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminTrialController.php:155` | DENY admin | `#[Route('/app/api/admin/trial-plans/{id}/activate', name: 'miniapp_admin_trial_plan_activate', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.plans'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminTrialController.php:161` | DENY admin | `#[Route('/app/api/admin/trial-plans/{id}/deactivate', name: 'miniapp_admin_trial_plan_deactivate', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.plans'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminTrialController.php:171` | DENY admin | `#[Route('/app/api/admin/trial-plans/{id}/delete', name: 'miniapp_admin_trial_plan_delete', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.plans'], methods: ['POST'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminTrialController.php:199` | DENY admin | `#[Route('/app/admin/trial-claims', name: 'miniapp_admin_trial_claims', defaults: ['_miniapp_section' => 'admin.plans'], methods: ['GET'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminWalletController.php:51` | DENY admin | `#[Route('/app/admin/customers/{id}/wallet', name: 'miniapp_admin_customer_wallet', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.customers'], methods: ['GET'])]` |
| `src/MiniApp/UI/Admin/MiniAppAdminWalletController.php:68` | DENY admin | `#[Route('/app/api/admin/customers/{id}/wallet/adjust', name: 'miniapp_admin_customer_wallet_adjust', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.customers'], methods: ['POST'])]` |
| `src/MiniApp/UI/MiniAppAuthController.php:56` | ALLOW | `#[Route('/app/auth', name: 'miniapp_auth', methods: ['POST'])]` |
| `src/MiniApp/UI/MiniAppAuthController.php:95` | ALLOW | `#[Route('/app/logout', name: 'miniapp_logout', methods: ['POST'])]` |
| `src/MiniApp/UI/MiniAppAuthController.php:104` | ALLOW | `#[Route('/app/api/me', name: 'miniapp_me', methods: ['GET'])]` |
| `src/MiniApp/UI/MiniAppCheckoutController.php:54` | ALLOW | `#[Route('/app/api/checkout/quote', name: 'miniapp_checkout_quote', defaults: ['_miniapp_section' => 'shop'], methods: ['POST'])]` |
| `src/MiniApp/UI/MiniAppCheckoutController.php:65` | ALLOW | `#[Route('/app/api/checkout/new', name: 'miniapp_checkout_new', defaults: ['_miniapp_section' => 'shop'], methods: ['POST'])]` |
| `src/MiniApp/UI/MiniAppCheckoutController.php:76` | ALLOW | `#[Route('/app/api/services/{id}/renew/quote', name: 'miniapp_renew_quote', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'services'], methods: ['POST'])]` |
| `src/MiniApp/UI/MiniAppCheckoutController.php:82` | ALLOW | `#[Route('/app/api/services/{id}/renew', name: 'miniapp_renew', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'services'], methods: ['POST'])]` |
| `src/MiniApp/UI/MiniAppCheckoutController.php:92` | ALLOW | `#[Route('/app/api/services/{id}/add-traffic/quote', name: 'miniapp_add_traffic_quote', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'services'], methods: ['POST'])]` |
| `src/MiniApp/UI/MiniAppCheckoutController.php:102` | ALLOW | `#[Route('/app/api/services/{id}/add-traffic', name: 'miniapp_add_traffic', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'services'], methods: ['POST'])]` |
| `src/MiniApp/UI/MiniAppCheckoutController.php:113` | ALLOW | `#[Route('/app/api/orders/{id}/pay', name: 'miniapp_order_pay_api', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'orders'], methods: ['POST'])]` |
| `src/MiniApp/UI/MiniAppCheckoutController.php:128` | ALLOW | `#[Route('/app/api/wallet/topup', name: 'miniapp_wallet_topup', defaults: ['_miniapp_section' => 'services'], methods: ['POST'])]` |
| `src/MiniApp/UI/MiniAppCheckoutController.php:147` | ALLOW | `#[Route('/app/api/orders/{id}/pay-wallet', name: 'miniapp_order_pay_wallet', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'orders'], methods: ['POST'])]` |
| `src/MiniApp/UI/MiniAppCheckoutController.php:176` | ALLOW | `#[Route('/app/api/orders/{id}/cancel', name: 'miniapp_order_cancel', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'orders'], methods: ['POST'])]` |
| `src/MiniApp/UI/MiniAppCheckoutController.php:189` | ALLOW | `#[Route('/app/api/trial/{id}', name: 'miniapp_trial_claim', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'shop'], methods: ['POST'])]` |
| `src/MiniApp/UI/MiniAppCheckoutController.php:199` | ALLOW | `#[Route('/app/api/payments/{id}/receipt', name: 'miniapp_payment_receipt', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'orders'], methods: ['POST'])]` |
| `src/MiniApp/UI/MiniAppCheckoutController.php:213` | ALLOW | `#[Route('/app/api/payments/{id}/status', name: 'miniapp_payment_status', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'orders'], methods: ['GET'])]` |
| `src/MiniApp/UI/MiniAppClaimController.php:47` | ALLOW | `#[Route('/app/view/claim/{id}/{ticket}', name: 'miniapp_claim', requirements: ['id' => '[1-9]\d{0,9}', 'ticket' => self::TICKET], defaults: ['_miniapp_section' => 'services'], methods: ['GET'])]` |
| `src/MiniApp/UI/MiniAppClaimController.php:63` | ALLOW | `#[Route('/app/view/claim/failed/{error}', name: 'miniapp_claim_failed', requirements: ['error' => '[a-z_]{1,32}'], defaults: ['_miniapp_section' => 'services'], methods: ['GET'])]` |
| `src/MiniApp/UI/MiniAppClaimController.php:71` | ALLOW | `#[Route('/app/api/claim/{id}', name: 'miniapp_claim_accept', requirements: ['id' => '[1-9]\d{0,9}'], defaults: ['_miniapp_section' => 'services'], methods: ['POST'])]` |
| `src/MiniApp/UI/MiniAppCustomerController.php:61` | ALLOW | `#[Route('/app/view/home', name: 'miniapp_home', defaults: ['_miniapp_section' => 'home'], methods: ['GET'])]` |
| `src/MiniApp/UI/MiniAppCustomerController.php:83` | ALLOW | `#[Route('/app/view/services', name: 'miniapp_services', defaults: ['_miniapp_section' => 'services'], methods: ['GET'])]` |
| `src/MiniApp/UI/MiniAppCustomerController.php:89` | ALLOW | `#[Route('/app/view/services/{id}', name: 'miniapp_service', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'services'], methods: ['GET'])]` |
| `src/MiniApp/UI/MiniAppCustomerController.php:115` | ALLOW | `#[Route('/app/view/services/{id}/subscription', name: 'miniapp_subscription', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'services'], methods: ['GET'])]` |
| `src/MiniApp/UI/MiniAppCustomerController.php:133` | ALLOW | `#[Route('/app/view/apps', name: 'miniapp_apps', defaults: ['_miniapp_section' => 'apps'], methods: ['GET'])]` |
| `src/MiniApp/UI/MiniAppCustomerController.php:152` | ALLOW | `#[Route('/app/view/help', name: 'miniapp_help', defaults: ['_miniapp_section' => 'help'], methods: ['GET'])]` |
| `src/MiniApp/UI/MiniAppEntryController.php:29` | ALLOW | `#[Route('/app', name: 'miniapp_entry', methods: ['GET'])]` |
| `src/MiniApp/UI/MiniAppOrderController.php:57` | ALLOW | `#[Route('/app/view/orders/{id}/pay', name: 'miniapp_order_pay', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'orders'], methods: ['GET'])]` |
| `src/MiniApp/UI/MiniAppOrderController.php:79` | ALLOW | `#[Route('/app/view/payments/{id}', name: 'miniapp_payment', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'orders'], methods: ['GET'])]` |
| `src/MiniApp/UI/MiniAppOrderController.php:101` | ALLOW | `#[Route('/app/view/orders', name: 'miniapp_orders', defaults: ['_miniapp_section' => 'orders'], methods: ['GET'])]` |
| `src/MiniApp/UI/MiniAppOrderController.php:115` | ALLOW | `#[Route('/app/view/orders/{id}', name: 'miniapp_order', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'orders'], methods: ['GET'])]` |
| `src/MiniApp/UI/MiniAppServiceActionController.php:38` | ALLOW | `#[Route('/app/api/services/{id}/{action}', name: 'miniapp_service_action', requirements: ['id' => '\d+', 'action' => 'send-link&#124;send-qr&#124;resend-config&#124;refresh'], defaults: ['_miniapp_section' => 'services'], methods: ['POST'])]` |
| `src/MiniApp/UI/MiniAppServiceOrderController.php:41` | ALLOW | `#[Route('/app/view/services/{id}/renew', name: 'miniapp_service_renew', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'services'], methods: ['GET'])]` |
| `src/MiniApp/UI/MiniAppServiceOrderController.php:62` | ALLOW | `#[Route('/app/view/services/{id}/add-traffic', name: 'miniapp_service_add_traffic', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'services'], methods: ['GET'])]` |
| `src/MiniApp/UI/MiniAppShellController.php:29` | ALLOW | `#[Route('/app/view/nav', name: 'miniapp_nav', methods: ['GET'])]` |
| `src/MiniApp/UI/MiniAppShopController.php:46` | ALLOW | `#[Route('/app/view/shop', name: 'miniapp_shop', defaults: ['_miniapp_section' => 'shop'], methods: ['GET'])]` |
| `src/MiniApp/UI/MiniAppShopController.php:64` | ALLOW | `#[Route('/app/view/shop/plans/{id}', name: 'miniapp_plan', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'shop'], methods: ['GET'])]` |
| `src/MiniApp/UI/MiniAppWalletController.php:37` | ALLOW | `#[Route('/app/view/wallet', name: 'miniapp_wallet', defaults: ['_miniapp_section' => 'services'], methods: ['GET'])]` |
| `src/MiniApp/UI/Reseller/MiniAppResellerClaimController.php:55` | ALLOW | `#[Route('/app/view/reseller/links', name: 'miniapp_reseller_links', defaults: ['_miniapp_section' => 'reseller'], methods: ['GET'])]` |
| `src/MiniApp/UI/Reseller/MiniAppResellerClaimController.php:71` | ALLOW | `#[Route('/app/api/reseller/services/{id}/claim-link', name: 'miniapp_reseller_claim_create', requirements: ['id' => '[1-9]\d{0,9}'], defaults: ['_miniapp_section' => 'reseller'], methods: ['POST'])]` |
| `src/MiniApp/UI/Reseller/MiniAppResellerClaimController.php:111` | ALLOW | `#[Route('/app/api/reseller/links/{id}/revoke', name: 'miniapp_reseller_claim_revoke', requirements: ['id' => '[1-9]\d{0,9}'], defaults: ['_miniapp_section' => 'reseller'], methods: ['POST'])]` |
| `src/MiniApp/UI/Reseller/MiniAppResellerController.php:53` | ALLOW | `#[Route('/app/view/reseller', name: 'miniapp_reseller', defaults: ['_miniapp_section' => 'reseller'], methods: ['GET'])]` |
| `src/MiniApp/UI/Reseller/MiniAppResellerController.php:74` | ALLOW | `#[Route('/app/view/reseller/new', name: 'miniapp_reseller_new', defaults: ['_miniapp_section' => 'reseller'], methods: ['GET'])]` |
| `src/MiniApp/UI/Reseller/MiniAppResellerController.php:91` | ALLOW | `#[Route('/app/view/reseller/new/{id}', name: 'miniapp_reseller_plan', requirements: ['id' => '[1-9]\d{0,9}'], defaults: ['_miniapp_section' => 'reseller'], methods: ['GET'])]` |
| `src/MiniApp/UI/Reseller/MiniAppResellerController.php:120` | ALLOW | `#[Route('/app/api/reseller/new/quote', name: 'miniapp_reseller_new_quote', defaults: ['_miniapp_section' => 'reseller'], methods: ['POST'])]` |
| `src/MiniApp/UI/Reseller/MiniAppResellerController.php:145` | ALLOW | `#[Route('/app/api/reseller/new', name: 'miniapp_reseller_buy', defaults: ['_miniapp_section' => 'reseller'], methods: ['POST'])]` |
| `src/MiniApp/UI/Reseller/MiniAppResellerCustomerController.php:50` | ALLOW | `#[Route('/app/view/reseller/customers', name: 'miniapp_reseller_customers', defaults: ['_miniapp_section' => 'reseller'], methods: ['GET'])]` |
| `src/MiniApp/UI/Reseller/MiniAppResellerCustomerController.php:67` | ALLOW | `#[Route('/app/view/reseller/customers/new', name: 'miniapp_reseller_customer_new', defaults: ['_miniapp_section' => 'reseller'], methods: ['GET'])]` |
| `src/MiniApp/UI/Reseller/MiniAppResellerCustomerController.php:75` | ALLOW | `#[Route('/app/view/reseller/customers/{id}', name: 'miniapp_reseller_customer', requirements: ['id' => '[1-9]\d{0,9}'], defaults: ['_miniapp_section' => 'reseller'], methods: ['GET'])]` |
| `src/MiniApp/UI/Reseller/MiniAppResellerCustomerController.php:88` | ALLOW | `#[Route('/app/view/reseller/customers/{id}/edit', name: 'miniapp_reseller_customer_edit', requirements: ['id' => '[1-9]\d{0,9}'], defaults: ['_miniapp_section' => 'reseller'], methods: ['GET'])]` |
| `src/MiniApp/UI/Reseller/MiniAppResellerCustomerController.php:97` | ALLOW | `#[Route('/app/api/reseller/customers', name: 'miniapp_reseller_customer_create', defaults: ['_miniapp_section' => 'reseller'], methods: ['POST'])]` |
| `src/MiniApp/UI/Reseller/MiniAppResellerCustomerController.php:125` | ALLOW | `#[Route('/app/api/reseller/customers/{id}', name: 'miniapp_reseller_customer_update', requirements: ['id' => '[1-9]\d{0,9}'], defaults: ['_miniapp_section' => 'reseller'], methods: ['POST'])]` |
| `src/MiniApp/UI/Reseller/MiniAppResellerCustomerController.php:148` | ALLOW | `#[Route('/app/api/reseller/customers/{id}/delete', name: 'miniapp_reseller_customer_delete', requirements: ['id' => '[1-9]\d{0,9}'], defaults: ['_miniapp_section' => 'reseller'], methods: ['POST'])]` |
| `src/MiniApp/UI/Reseller/MiniAppResellerServiceController.php:68` | ALLOW | `#[Route('/app/view/reseller/services', name: 'miniapp_reseller_services', defaults: ['_miniapp_section' => 'reseller'], methods: ['GET'])]` |
| `src/MiniApp/UI/Reseller/MiniAppResellerServiceController.php:95` | ALLOW | `#[Route('/app/view/reseller/services/{id}', name: 'miniapp_reseller_service', requirements: ['id' => '[1-9]\d{0,9}'], defaults: ['_miniapp_section' => 'reseller'], methods: ['GET'])]` |
| `src/MiniApp/UI/Reseller/MiniAppResellerServiceController.php:121` | ALLOW | `#[Route('/app/view/reseller/services/{id}/renew', name: 'miniapp_reseller_renew', requirements: ['id' => '[1-9]\d{0,9}'], defaults: ['_miniapp_section' => 'reseller'], methods: ['GET'])]` |
| `src/MiniApp/UI/Reseller/MiniAppResellerServiceController.php:140` | ALLOW | `#[Route('/app/view/reseller/services/{id}/add-traffic', name: 'miniapp_reseller_add_traffic', requirements: ['id' => '[1-9]\d{0,9}'], defaults: ['_miniapp_section' => 'reseller'], methods: ['GET'])]` |
| `src/MiniApp/UI/Reseller/MiniAppResellerServiceController.php:162` | ALLOW | `#[Route('/app/api/reseller/services/{id}/renew/quote', name: 'miniapp_reseller_renew_quote', requirements: ['id' => '[1-9]\d{0,9}'], defaults: ['_miniapp_section' => 'reseller'], methods: ['POST'])]` |
| `src/MiniApp/UI/Reseller/MiniAppResellerServiceController.php:168` | ALLOW | `#[Route('/app/api/reseller/services/{id}/renew', name: 'miniapp_reseller_renew_buy', requirements: ['id' => '[1-9]\d{0,9}'], defaults: ['_miniapp_section' => 'reseller'], methods: ['POST'])]` |
| `src/MiniApp/UI/Reseller/MiniAppResellerServiceController.php:174` | ALLOW | `#[Route('/app/api/reseller/services/{id}/add-traffic/quote', name: 'miniapp_reseller_add_traffic_quote', requirements: ['id' => '[1-9]\d{0,9}'], defaults: ['_miniapp_section' => 'reseller'], methods: ['POST'])]` |
| `src/MiniApp/UI/Reseller/MiniAppResellerServiceController.php:180` | ALLOW | `#[Route('/app/api/reseller/services/{id}/add-traffic', name: 'miniapp_reseller_add_traffic_buy', requirements: ['id' => '[1-9]\d{0,9}'], defaults: ['_miniapp_section' => 'reseller'], methods: ['POST'])]` |
| `src/MiniApp/UI/Reseller/MiniAppResellerServiceController.php:190` | ALLOW | `#[Route('/app/api/reseller/services/{id}/customer', name: 'miniapp_reseller_service_customer', requirements: ['id' => '[1-9]\d{0,9}'], defaults: ['_miniapp_section' => 'reseller'], methods: ['POST'])]` |
| `src/Payment/UI/PaymentReturnController.php:54` | ALLOW | `#[Route('/payment/return/{token}', name: 'payment_return', requirements: ['token' => PaymentReturnUrl::TOKEN_PATTERN], methods: ['GET'])]` |
| `src/Payment/UI/PaymentReturnController.php:98` | ALLOW | `#[Route('/payment/return/{token}/cancel', name: 'payment_return_cancel', requirements: ['token' => PaymentReturnUrl::TOKEN_PATTERN], methods: ['GET'])]` |
| `src/Payment/UI/PaymentReturnController.php:113` | cloud legacy only | `#[Route('/payment/success', name: 'payment_success', methods: ['GET'])]` |
| `src/Payment/UI/PaymentReturnController.php:119` | cloud legacy only | `#[Route('/payment/cancel', name: 'payment_cancel', methods: ['GET'])]` |
| `src/Branding/UI/BrandLogoController.php:28` | ALLOW | `#[Route('/brand/logo/{name}', name: 'brand_logo', requirements: ['name' => '[a-f0-9]{32}\.(?:png&#124;jpg&#124;webp)'], methods: ['GET', 'HEAD'])]` |

### Complete proposed dynamic map entries

Replace the dynamic map entries in the skeleton with this complete set; retain its three static asset paths (two regex entries). Requirements retain source semantics; admin and legacy routes are excluded. Quoted nginx regex strings escape backslashes. Validate rendered syntax in the implementation PR.

```nginx
"~^POST:/app/auth$" 1;
"~^POST:/app/logout$" 1;
"~^(GET|HEAD):/app/api/me$" 1;
"~^POST:/app/api/checkout/quote$" 1;
"~^POST:/app/api/checkout/new$" 1;
"~^POST:/app/api/services/(?:\\d+)/renew/quote$" 1;
"~^POST:/app/api/services/(?:\\d+)/renew$" 1;
"~^POST:/app/api/services/(?:\\d+)/add-traffic/quote$" 1;
"~^POST:/app/api/services/(?:\\d+)/add-traffic$" 1;
"~^POST:/app/api/orders/(?:\\d+)/pay$" 1;
"~^POST:/app/api/wallet/topup$" 1;
"~^POST:/app/api/orders/(?:\\d+)/pay-wallet$" 1;
"~^POST:/app/api/orders/(?:\\d+)/cancel$" 1;
"~^POST:/app/api/trial/(?:\\d+)$" 1;
"~^POST:/app/api/payments/(?:\\d+)/receipt$" 1;
"~^(GET|HEAD):/app/api/payments/(?:\\d+)/status$" 1;
"~^(GET|HEAD):/app/view/claim/(?:[1-9]\\d{0,9})/(?:[0-9a-z]{7}[A-Za-z0-9_-]{16})$" 1;
"~^(GET|HEAD):/app/view/claim/failed/(?:[a-z_]{1,32})$" 1;
"~^POST:/app/api/claim/(?:[1-9]\\d{0,9})$" 1;
"~^(GET|HEAD):/app/view/home$" 1;
"~^(GET|HEAD):/app/view/services$" 1;
"~^(GET|HEAD):/app/view/services/(?:\\d+)$" 1;
"~^(GET|HEAD):/app/view/services/(?:\\d+)/subscription$" 1;
"~^(GET|HEAD):/app/view/apps$" 1;
"~^(GET|HEAD):/app/view/help$" 1;
"~^(GET|HEAD):/app$" 1;
"~^(GET|HEAD):/app/view/orders/(?:\\d+)/pay$" 1;
"~^(GET|HEAD):/app/view/payments/(?:\\d+)$" 1;
"~^(GET|HEAD):/app/view/orders$" 1;
"~^(GET|HEAD):/app/view/orders/(?:\\d+)$" 1;
"~^POST:/app/api/services/(?:\\d+)/(?:send-link|send-qr|resend-config|refresh)$" 1;
"~^(GET|HEAD):/app/view/services/(?:\\d+)/renew$" 1;
"~^(GET|HEAD):/app/view/services/(?:\\d+)/add-traffic$" 1;
"~^(GET|HEAD):/app/view/nav$" 1;
"~^(GET|HEAD):/app/view/shop$" 1;
"~^(GET|HEAD):/app/view/shop/plans/(?:\\d+)$" 1;
"~^(GET|HEAD):/app/view/wallet$" 1;
"~^(GET|HEAD):/app/view/reseller/links$" 1;
"~^POST:/app/api/reseller/services/(?:[1-9]\\d{0,9})/claim-link$" 1;
"~^POST:/app/api/reseller/links/(?:[1-9]\\d{0,9})/revoke$" 1;
"~^(GET|HEAD):/app/view/reseller$" 1;
"~^(GET|HEAD):/app/view/reseller/new$" 1;
"~^(GET|HEAD):/app/view/reseller/new/(?:[1-9]\\d{0,9})$" 1;
"~^POST:/app/api/reseller/new/quote$" 1;
"~^POST:/app/api/reseller/new$" 1;
"~^(GET|HEAD):/app/view/reseller/customers$" 1;
"~^(GET|HEAD):/app/view/reseller/customers/new$" 1;
"~^(GET|HEAD):/app/view/reseller/customers/(?:[1-9]\\d{0,9})$" 1;
"~^(GET|HEAD):/app/view/reseller/customers/(?:[1-9]\\d{0,9})/edit$" 1;
"~^POST:/app/api/reseller/customers$" 1;
"~^POST:/app/api/reseller/customers/(?:[1-9]\\d{0,9})$" 1;
"~^POST:/app/api/reseller/customers/(?:[1-9]\\d{0,9})/delete$" 1;
"~^(GET|HEAD):/app/view/reseller/services$" 1;
"~^(GET|HEAD):/app/view/reseller/services/(?:[1-9]\\d{0,9})$" 1;
"~^(GET|HEAD):/app/view/reseller/services/(?:[1-9]\\d{0,9})/renew$" 1;
"~^(GET|HEAD):/app/view/reseller/services/(?:[1-9]\\d{0,9})/add-traffic$" 1;
"~^POST:/app/api/reseller/services/(?:[1-9]\\d{0,9})/renew/quote$" 1;
"~^POST:/app/api/reseller/services/(?:[1-9]\\d{0,9})/renew$" 1;
"~^POST:/app/api/reseller/services/(?:[1-9]\\d{0,9})/add-traffic/quote$" 1;
"~^POST:/app/api/reseller/services/(?:[1-9]\\d{0,9})/add-traffic$" 1;
"~^POST:/app/api/reseller/services/(?:[1-9]\\d{0,9})/customer$" 1;
"~^(GET|HEAD):/payment/return/(?:[A-Za-z0-9_-]{32,64})$" 1;
"~^(GET|HEAD):/payment/return/(?:[A-Za-z0-9_-]{32,64})/cancel$" 1;
"~^(GET|HEAD):/brand/logo/(?:[a-f0-9]{32}\\.(?:png|jpg|webp))$" 1;
```

## Appendix B. Source URL/host construction index

Source expressions below supplement the ownership table. They are not deployed values. Provider origins, example URLs and VPN builders are intentionally independent of the app origin.

**config/packages/framework.yaml**

- Line 1: `# see https://symfony.com/doc/current/reference/configuration/framework.html`

**config/packages/messenger.yaml**

- Line 7: `# https://symfony.com/doc/current/messenger.html#transport-configuration`
- Line 21: `#                # For more Messenger testing tools, see https://github.com/zenstruck/messenger-test`

**config/packages/routing.yaml**

- Line 4: `# See https://symfony.com/doc/current/routing.html#generating-urls-in-commands`

**config/routes.yaml**

- Line 5: `# See also https://symfony.com/doc/current/routing.html`

**docs/plugin-sdk/payment-gateway-template/plugin.json.example**

- Line 31: `"key": "callback_base_url",`

**docs/plugin-sdk/payment-gateway-template/src/GatewayPlugin.php.example**

- Line 34: `$callbackBaseUrl = rtrim((string) ($config['callback_base_url'] ?? ''), '/');`
- Line 42: `paymentUrl: 'https://provider.example/pay/'.$providerReference,`

**docs/plugins/demo-payment-gateway/plugin.json**

- Line 28: `"default": "success_url",`
- Line 34: `"نمایش لینک پرداخت تستی": "success_url",`
- Line 42: `"default": "https://example.com/demo-payment",`

**docs/plugins/demo-payment-gateway/src/DemoPaymentGatewayPlugin.php**

- Line 27: `if (($config['demo_mode'] ?? 'success_url') === 'failure') {`
- Line 40: `paymentUrl: trim((string) ($config['payment_url'] ?? '')) ?: 'https://example.com/demo-payment',`

**docs/plugins/novinopay-gateway/plugin.json**

- Line 58: `"default": "https://api.novinopay.com",`
- Line 65: `"key": "callback_base_url",`

**docs/plugins/novinopay-gateway/src/NovinoPayGatewayPlugin.php**

- Line 208: `if ('' === trim((string) ($config['callback_base_url'] ?? ''))) {`
- Line 209: `return 'NovinoPay callback_base_url is not configured.';`
- Line 235: `$baseUrl = 'https://api.novinopay.com';`
- Line 246: `$baseUrl = rtrim(trim((string) ($config['callback_base_url'] ?? '')), '/');`

**docs/plugins/swapwallet-gateway/plugin.json**

- Line 57: `"default": "https://api.swapwallet.app",`
- Line 64: `"key": "callback_base_url",`
- Line 159: `"key": "success_url",`
- Line 168: `"key": "cancel_url",`

**docs/plugins/swapwallet-gateway/src/SwapWalletGatewayPlugin.php**

- Line 233: `foreach (['callback_base_url', 'payment_mode', 'price_currency', 'amount_unit', 'toman_per_usd'] as $key) {`
- Line 264: `$baseUrl = 'https://swapwallet.app/api';`
- Line 390: `$baseUrl = $this->stringOrNull($config['callback_base_url'] ?? null);`
- Line 392: `return PaymentReturnUrl::returnUrl($baseUrl, $payment);`
- Line 395: `return $this->stringOrNull($config['success_url'] ?? null);`
- Line 411: `$baseUrl = rtrim(trim((string) ($config['callback_base_url'] ?? '')), '/');`

**docs/plugins/winapay-gateway/plugin.json**

- Line 34: `"key": "callback_base_url",`

**docs/plugins/winapay-gateway/src/WinapayApi.php**

- Line 19: `public const BASE_URL = 'https://winapay.io';`

**docs/plugins/winapay-gateway/src/WinapayGatewayPlugin.php**

- Line 62: `return new PaymentRequestResult(success: false, message: 'Winapay callback_base_url must be an absolute http(s) URL.');`
- Line 261: `$url = rtrim(trim((string) $this->scalar($config['callback_base_url'] ?? null)), '/');`

**src/Admin/Application/SettingsAdministration.php**

- Line 131: `* and the colour is #rrggbb; the support link is https://t.me/<username> or https://telegram.me/<username>`

**src/Admin/UI/AdminLocaleController.php**

- Line 56: `$requestHost = strtolower((string) $request->getHost());`

**src/Admin/UI/Crud/AppDownloadCrudController.php**

- Line 103: `AdminFormat::field('publicLink', fn (mixed $v, ?AppDownload $d): string => null === $d?->getId() ? AdminFormat::NONE : $f->copyable($this->generateUrl('app_download', ['id' => $d->getId()], UrlGeneratorInterface::ABSOLUTE_URL), 64), 'field.public_link')->onlyOnDetail(),`

**src/Admin/UI/Crud/ClientAppCrudController.php**

- Line 38: `private const EXAMPLE_URL = 'https://sub.example.com/sub/abc123';`

**src/Admin/UI/Crud/CustomApiPaymentGatewayCrudController.php**

- Line 36: `"url": "https://gateway.example.com/api/payment/create",`
- Line 43: `"url": "https://gateway.example.com/api/payment/verify",`

**src/Admin/UI/Crud/NowPaymentsPaymentGatewayCrudController.php**

- Line 54: `->setNowPaymentsApiBaseUrl('https://api.nowpayments.io/v1')`
- Line 117: `TextField::new('nowPaymentsCallbackBaseUrl')->setHelp('help.now_payments_payment_gateway.now_payments_callback_base_url'),`
- Line 133: `TextField::new('nowPaymentsSuccessUrl')->setHelp('help.now_payments_payment_gateway.now_payments_success_url')->hideOnIndex(),`
- Line 134: `TextField::new('nowPaymentsCancelUrl')->setHelp('help.now_payments_payment_gateway.now_payments_cancel_url')->hideOnIndex(),`

**src/Admin/UI/PaymentModerationController.php**

- Line 107: `if ('' !== $referer && str_starts_with($referer, $request->getSchemeAndHttpHost().'/admin')) {`

**src/Bot/Application/ServiceManagementService.php**

- Line 1476: `sprintf('%s / %s', $inbound?->getHost() ?? '-', $inbound?->getCountry() ?? '-'),`

**src/Bot/Infrastructure/TelegramApiClient.php**

- Line 200: `$data = $this->httpClient->request('POST', sprintf('https://api.telegram.org/bot%s/sendPhoto', $this->botToken), $options)->toArray(false);`
- Line 262: `sprintf('https://api.telegram.org/bot%s/%s', $this->botToken, $method),`
- Line 292: `$response = $this->httpClient->request('GET', sprintf('https://api.telegram.org/file/bot%s/%s', $this->botToken, $path), $options);`
- Line 392: `$response = $this->httpClient->request('POST', sprintf('https://api.telegram.org/bot%s/%s', $this->botToken, $method), $options);`
- Line 428: `if (str_starts_with($proxy, 'http://') || str_starts_with($proxy, 'https://')) {`

**src/Branding/SupportLinkSettings.php**

- Line 16: `* Only https://t.me/<username> or https://telegram.me/<username> is accepted: lower-case host, a username of 5 to 32`
- Line 19: `* always given out as https://t.me/<username> (the same chat; Telegram opens only t.me links inside the app).`
- Line 25: `private const PATTERN = '#^https://(?:t|telegram)\.me/([A-Za-z0-9_]{5,32})$#D';`
- Line 33: `/** The support chat as https://t.me/<username>, or null when none is set (or the stored value is not valid). */`
- Line 50: `/** https://t.me/<username> for a valid link, else null ('' included). */`
- Line 57: `return 'https://t.me/'.$m[1];`

**src/Broadcast/BroadcastFormat.php**

- Line 22: `/** Telegram's HTML tags (https://core.telegram.org/bots/api#html-style). */`

**src/Command/SetTelegramMenuButtonCommand.php**

- Line 45: `$output->writeln('<error>The Mini App needs a public HTTPS address: set DEFAULT_URI to https://your-domain (see docs/en/miniapp.md).</error>');`

**src/Command/SetTelegramWebhookCommand.php**

- Line 29: `$this->addArgument('baseUrl', InputArgument::REQUIRED, 'Base URL like https://mydomain.com');`

**src/Entity/PaymentGateway.php**

- Line 320: `return $this->configString('callback_base_url');`
- Line 325: `return $this->setConfigString('callback_base_url', $value);`
- Line 502: `return $this->configString('callback_base_url');`
- Line 507: `return $this->setConfigString('callback_base_url', $value);`
- Line 512: `return $this->configString('api_base_url') ?? 'https://api.nowpayments.io/v1';`
- Line 518: `if ('' === $text || 'https://api.nowpayments.io/v1' === $text) {`
- Line 690: `return $this->configString('success_url');`
- Line 695: `return $this->setConfigString('success_url', $value);`
- Line 700: `return $this->configString('cancel_url');`
- Line 705: `return $this->setConfigString('cancel_url', $value);`

**src/Entity/RequiredChannel.php**

- Line 37: `#[Assert\Regex(pattern: '#^https://(t\.me|telegram\.me|telegram\.dog)/[^\s"\'<>]+$#i', message: 'required_channel.invite_url.invalid')]`

**src/Entity/VpnInbound.php**

- Line 218: `public function getHost(): ?string`

**src/MiniApp/Application/MiniAppLinks.php**

- Line 46: `$url = $this->urlGenerator->generate('miniapp_entry', ['guide' => $slug], UrlGeneratorInterface::ABSOLUTE_URL);`
- Line 48: `return null !== $this->publicUrl() && str_starts_with($url, 'https://') ? $url : null;`
- Line 57: `$url = $this->urlGenerator->generate('miniapp_entry', null === $serviceId ? [] : ['service' => $serviceId], UrlGeneratorInterface::ABSOLUTE_URL);`
- Line 59: `return str_starts_with($url, 'https://') && PublicUrlPolicy::isPubliclyUsableUrl($url) ? $url : null;`

**src/MiniApp/Checkout/MiniAppCheckout.php**

- Line 340: `'url' => $channel->getInviteUrl() ?? (str_starts_with($channel->getChatId(), '@') ? 'https://t.me/'.substr($channel->getChatId(), 1) : null),`

**src/MiniApp/Guide/GuideRenderer.php**

- Line 20: `*   [text](https://…)          external link, opened by Telegram (a[data-open-link]); any other URL: the text alone`

**src/MiniApp/Guide/GuideTextRules.php**

- Line 24: `*  - links: [text](#slug) to a topic of the guide, or [text](https://host/...) without credentials; anything else`

**src/MiniApp/UI/Admin/MiniAppAdminAppsController.php**

- Line 43: `public const EXAMPLE_URL = 'https://sub.example.com/sub/abc123';`

**src/MiniApp/UI/MiniAppCustomerController.php**

- Line 148: `* only a validated https://t.me/<username>, never anything from the guide texts). ?topic=<slug> opens that topic's`

**src/MiniApp/UI/MiniAppHeaders.php**

- Line 15: `public const CSP = "default-src 'none'; script-src 'self' https://telegram.org; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors https://web.telegram.org";`

**src/MiniApp/UI/Reseller/MiniAppResellerClaimController.php**

- Line 104: `'shareUrl' => null !== $shareable ? 'https://t.me/share/url?'.http_build_query(['url' => $shareable], '', '&', PHP_QUERY_RFC3986) : null,`

**src/Payment/Application/PaymentGatewayModuleRegistry.php**

- Line 80: `'callback_base_url' => '',`
- Line 85: `['name' => 'callback_base_url', 'type' => 'text', 'required' => true],`
- Line 104: `'api_base_url' => 'https://api.nowpayments.io/v1',`
- Line 109: `'callback_base_url' => '',`
- Line 110: `'success_url' => '',`
- Line 111: `'cancel_url' => '',`
- Line 116: `['name' => 'api_base_url', 'type' => 'text', 'required' => false, 'default' => 'https://api.nowpayments.io/v1'],`
- Line 140: `['name' => 'callback_base_url', 'type' => 'text', 'required' => true],`
- Line 141: `['name' => 'success_url', 'type' => 'text', 'required' => false],`
- Line 142: `['name' => 'cancel_url', 'type' => 'text', 'required' => false],`
- Line 244: `'callback_base_url' => 'https://example.com',`
- Line 249: `'api_base_url' => 'https://api.nowpayments.io/v1',`
- Line 255: `'callback_base_url' => 'https://example.com',`
- Line 256: `'success_url' => 'https://example.com/payment/success',`
- Line 257: `'cancel_url' => 'https://example.com/payment/cancel',`

**src/Payment/Domain/PaymentReturnUrl.php**

- Line 13: `*   - return page (where the provider sends the customer back): returnUrl() / cancelUrl()`
- Line 30: `public static function returnUrl(string $baseUrl, Payment $payment): string`
- Line 35: `public static function cancelUrl(string $baseUrl, Payment $payment): string`
- Line 37: `return self::returnUrl($baseUrl, $payment).'/cancel';`

**src/Payment/Infrastructure/CustomApiGateway.php**

- Line 492: `'callback_url' => $gatewayId > 0 ? PaymentReturnUrl::withToken($this->urlGenerator->generate('payment_callback_custom_api', ['gatewayId' => $gatewayId], UrlGeneratorInterface::ABSOLUTE_URL), $payment) : '',`
- Line 493: `'return_url' => $this->urlGenerator->generate('payment_return', ['token' => $payment->ensureReturnToken()], UrlGeneratorInterface::ABSOLUTE_URL),`
- Line 495: `'webhook_url' => $gatewayId > 0 ? $this->urlGenerator->generate('payment_webhook_custom_api', ['gatewayId' => $gatewayId], UrlGeneratorInterface::ABSOLUTE_URL) : '',`

**src/Payment/Infrastructure/NowPaymentsGateway.php**

- Line 38: `public const DEFAULT_API_BASE = 'https://api.nowpayments.io/v1';`
- Line 72: `$callbackBaseUrl = rtrim(trim((string) ($config['callback_base_url'] ?? '')), '/');`
- Line 87: `return new PaymentRequestResult(success: false, message: 'NOWPayments callback_base_url is not configured.');`
- Line 110: `$successUrl = PaymentReturnUrl::returnUrl($callbackBaseUrl, $payment);`
- Line 111: `$cancelUrl = PaymentReturnUrl::cancelUrl($callbackBaseUrl, $payment);`
- Line 124: `$requestBody['success_url'] = $successUrl;`
- Line 127: `$requestBody['cancel_url'] = $cancelUrl;`

**src/Payment/Infrastructure/ZibalGateway.php**

- Line 26: `private const REQUEST_URL = 'https://gateway.zibal.ir/v1/request';`
- Line 27: `private const VERIFY_URL = 'https://gateway.zibal.ir/v1/verify';`
- Line 28: `private const START_URL = 'https://gateway.zibal.ir/start/';`
- Line 234: `$base = rtrim(trim((string) ($config['callback_base_url'] ?? '')), '/');`

**src/Provisioning/Application/CustomerAccessGuard.php**

- Line 100: `'Fix: fill "Subscription base URL" (and the public host) in the panel\'s Advanced section with the address customers use, e.g. https://sub.example.com:2096, then run app:panel:doctor.',`

**src/Provisioning/Application/PanelSubscriptionUrlCheck.php**

- Line 13: `* URL" only (never from the address the bot uses to reach the panel, often http://127.0.0.1:2096); shown by`

**src/Provisioning/Application/VpnAccessLinkGenerator.php**

- Line 87: `$inbound->getHost(),`

**src/Provisioning/Application/VpnInboundSyncService.php**

- Line 696: `$existingHost = trim((string) ($inbound->getHost() ?? ''));`
- Line 794: `(string) ($inbound->getHost() ?? ''),`

**src/Provisioning/Infrastructure/DummyVpnPanelDriver.php**

- Line 33: `subscriptionUrl: 'https://example.com/sub/'.$token,`

**src/Provisioning/Infrastructure/Sanaei3xui/Modern/Http/ModernPanelHttpClient.php**

- Line 27: `* - plain http:// only to loopback or private addresses (the bearer token would otherwise travel in clear text);`

**src/Provisioning/Infrastructure/Sanaei3xui/Modern/Http/PanelEndpoint.php**

- Line 13: `* (https://host:2053/secret/panel/inbounds -> base path /secret), and the old separate "base path" setting.`
- Line 40: `throw new PanelApiException(PanelFailure::NOT_CONFIGURED, 'The panel URL must start with https:// (or http:// for a panel on this server or a private network).');`
- Line 78: `* https:// always; http:// only to "localhost" or a loopback/private/link-local IP literal (a panel on this`
- Line 79: `* server, e.g. http://127.0.0.1:2096/, or on the same private network). Any other http:// URL would send the`
- Line 89: `throw new PanelApiException(PanelFailure::NOT_CONFIGURED, sprintf('Refusing http://%s: the API token would travel in clear text. Use https://, or http:// only with 127.0.0.1 / a private IP when the panel runs on this server or the same private network.', $this->host));`

**src/Provisioning/Infrastructure/Sanaei3xui/Sanaei3xuiConfigGenerator.php**

- Line 187: `'dest' => $this->firstNonEmpty($localInbound->getHost(), $panel->getPublicHost(), $this->hostFromUrl($panel->getSubscriptionBaseUrl()), $this->hostFromUrl($panel->getBaseUrl())),`

**src/Reseller/Application/ClaimLinks.php**

- Line 377: `return null === $username ? null : sprintf('https://t.me/%s?start=%s', $username, ClaimToken::payload($token));`
- Line 385: `return null === $username || !$this->miniAppLinks->isEnabled() ? null : sprintf('https://t.me/%s?startapp=%s', $username, ClaimToken::payload($token));`

**src/Reseller/Domain/CreatedClaimLink.php**

- Line 18: `/** https://t.me/<bot>?start=claim_<token>, or null when the bot's username is unknown. */`
- Line 20: `/** https://t.me/<bot>?startapp=claim_<token> (the bot's main Mini App), or null when the Mini App is off. */`

**src/Subscription/Application/AppDownloadCatalog.php**

- Line 85: `return $this->urlGenerator->generate('app_download', ['id' => $download->getId()], UrlGeneratorInterface::ABSOLUTE_URL);`

**src/Subscription/Application/SubscriptionPageUrl.php**

- Line 24: `return $this->urlGenerator->generate('subscription_page', ['token' => $service->getPublicToken()], UrlGeneratorInterface::ABSOLUTE_URL);`

**src/Subscription/UI/SubscriptionPageController.php**

- Line 114: `$response->headers->set('Content-Security-Policy', "default-src 'none'; img-src 'self' data:; font-src 'self'; style-src 'unsafe-inline'; script-src 'unsafe-inline' https://telegram.org; connect-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors https://web.telegram.org 'self'");`

**templates/admin/settings/_branding.html.twig**

- Line 32: `<input class="form-control" type="url" id="support_url" name="support_url" dir="ltr" maxlength="{{ constant('App\\Branding\\SupportLinkSettings::MAX_LENGTH') }}" placeholder="https://t.me/your_support" autocomplete="off" value="{{ values.support_url }}">`

**templates/miniapp/admin/channels.html.twig**

- Line 54: `<input class="input ltr" type="url" name="inviteUrl" maxlength="255" autocomplete="off" spellcheck="false" placeholder="https://t.me/…">`

**templates/miniapp/admin/customer.html.twig**

- Line 21: `{% if c.username %}<a class="btn secondary small spaced" href="https://t.me/{{ c.username }}" data-open-link>💬 {{ 'admin.customer.open_chat'|trans }}</a>{% endif %}`

**templates/miniapp/admin/download.html.twig**

- Line 39: `<input class="input ltr" type="url" name="externalUrl" value="{{ d.externalUrl }}" maxlength="500" autocomplete="off" spellcheck="false" placeholder="https://">`

**templates/miniapp/entry.html.twig**

- Line 16: `<script src="https://telegram.org/js/telegram-web-app.js"></script>`

**templates/miniapp/view/help.html.twig**

- Line 6: `their own when that topic is not shown. supportUrl (SupportLinkSettings, a validated https://t.me/<username>, or`

**templates/subscription/_script.html.twig**

- Line 127: `script.src = 'https://telegram.org/js/telegram-web-app.js';`

## Appendix C. Persisted URL/content/config field source index

Properties map to snake_case columns via the configured Doctrine naming strategy (explicit tables take precedence). JSON/text carriers below indicate possible indirect URL storage, not actual hostname presence. No database values were read.

- `admin_audit_log` ? `src/Entity/AdminAuditLog.php`: `details` (line 52), `created_at` (line 55).
- `admin_user` ? `src/Entity/AdminUser.php`: `roles` (line 56), `is_active` (line 59).
- `app_download` ? `src/Entity/AppDownload.php`: `external_url` (line 54).
- `bot_message_log` ? `src/Entity/BotMessageLog.php`: `payload` (line 28), `created_at` (line 31).
- `bot_message_template` ? `src/Entity/BotMessageTemplate.php`: `body` (line 32).
- `broadcast_delivery` ? `src/Entity/BroadcastDelivery.php`: `telegram_message_id` (line 53), `error_message` (line 56).
- `broadcast_message` ? `src/Entity/BroadcastMessage.php`: `body` (line 66), `target_user_ids` (line 77), `parse_mode` (line 81), `metadata` (line 118).
- `checkout_request` ? `src/Entity/CheckoutRequest.php`: `result` (line 37), `created_at` (line 40).
- `client_app` ? `src/Entity/ClientApp.php`: `platforms` (line 35), `import_url_template` (line 39), `store_url` (line 44), `notes` (line 53).
- `discount_code` ? `src/Entity/DiscountCode.php`: `value` (line 42).
- `discount_usage` ? `src/Entity/DiscountUsage.php`: `metadata` (line 43).
- `inbound_move_run` ? `src/Entity/InboundMoveRun.php`: `scope` (line 70), `status` (line 73).
- `mini_app_session` ? `src/Entity/MiniAppSession.php`: `token_hash` (line 25).
- ``order`` ? `src/Entity/Order.php`: `metadata` (line 54), `tracking_code` (line 57).
- `order_draft` ? `src/Entity/OrderDraft.php`: `price_snapshot` (line 54), `step` (line 57), `data` (line 60), `created_at` (line 63).
- `payment` ? `src/Entity/Payment.php`: `payment_url` (line 71), `provisioning_error` (line 106), `callback_payload` (line 125), `request_payload` (line 128), `verify_payload` (line 131), `verified_at` (line 134), `receipt_message` (line 155), `admin_note` (line 158), `crypto_invoice_url` (line 198), `ipn_payload` (line 210), `return_token` (line 218).
- `payment_gateway` ? `src/Entity/PaymentGateway.php`: `config` (line 47), `created_at` (line 50).
- `plugin` ? `src/Entity/Plugin.php`: `name` (line 51), `version` (line 54), `description` (line 58), `status` (line 62), `manifest` (line 72), `permissions` (line 76), `installed_at` (line 79), `error_message` (line 88).
- `required_channel` ? `src/Entity/RequiredChannel.php`: `invite_url` (line 38).
- `reseller` ? `src/Entity/Reseller.php`: `note` (line 50).
- `reseller_customer` ? `src/Entity/ResellerCustomer.php`: `note` (line 39).
- `service_claim` ? `src/Entity/ServiceClaim.php`: `token_hash` (line 71), `panel_sync_error` (line 124).
- `service_inbound_move` ? `src/Entity/ServiceInboundMove.php`: `last_error` (line 104).
- `service_notification_log` ? `src/Entity/ServiceNotificationLog.php`: `payload` (line 38).
- `setting` ? `src/Entity/Setting.php`: `value` (line 22).
- `vpn_inbound` ? `src/Entity/VpnInbound.php`: `host` (line 47), `sni` (line 53), `host_header` (line 59), `config` (line 86), `last_synced_at` (line 89), `last_access_metadata_synced_at` (line 92).
- `vpn_panel` ? `src/Entity/VpnPanel.php`: `base_url` (line 26), `api_token` (line 37), `plain_api_token` (line 41), `config` (line 44), `subscription_base_url` (line 47), `public_host` (line 50).
- `vpn_service` ? `src/Entity/VpnService.php`: `subscription_url` (line 43), `config_links` (line 58), `last_access_info_synced_at` (line 61), `config_text` (line 72), `public_token` (line 112).
- `vpn_service_inbound` ? `src/Entity/VpnServiceInbound.php`: `last_error` (line 62).
- `wallet_transaction` ? `src/Entity/WalletTransaction.php`: `note` (line 58).

## Open questions for the owner

1. Does the admin exclusion include Mini App admin (this proposal denies it and EasyAdmin)? Should reseller customer functionality be available on stack, or stay cloud-only?
2. Is a brief cloud browser callback hop acceptable for Winapay/NovinoPay/Zibal (Option A), provided the final page is stack? Otherwise the callback boundary needs an explicit scope change.
3. Which gateways and installed plugin versions are active? Can the Winapay merchant panel register multiple callback domains, and are there merchant-specific restrictions beyond the repository-described host match? No panel was inspected.
4. Is only apex stacktunnel.net supported, or also www/other aliases? Is 443-only acceptable? What proxy/CDN and trusted proxy configuration is used?
5. Is shared branding/name/support appropriate on both hosts, or is host-specific branding a separate future task? Should authored guide/broadcast URLs receive an editorial review?
6. Is an env-only CUSTOMER_BRAND_BASE_URL acceptable? Confirm refusal of activation for invalid nonempty values; an admin setting can follow separately if required.
7. How long must issued stack URLs survive rollback? They need DNS/TLS and compatible customer routes after future generation returns to cloud.
8. Are cloud download navigation and panel subscription destinations acceptable from the branded experience? The requested scope keeps /s and other internal/download routes off stack.
9. Who will perform later non-production gateway/Telegram verification and authorized DNS/TLS/BotFather/provider-panel changes? None was performed in this audit.
