# Telegram Mini App

The Mini App (`/app`) is a small web app that opens inside Telegram, for customers today and for admins and resellers
later. Customers buy a service (or claim a trial), pay it with any of the shop's payment methods, renew it or add
traffic, follow their orders, and use their subscription (link, QR code, configs, one-tap import into apps) without
leaving Telegram. They also see their services, have the bot send links or configs into the chat, refresh usage,
browse the client apps and downloads, and read the support text. Admins also get an **Admin** entry that lists the
areas their role allows, marked "coming soon"; admin forms stay in the bot and the web panel. The bot keeps working
exactly as before: both use the same checkout services (`src/Shop/Application/Checkout`).

## Requirements

- **A public HTTPS address.** Telegram only opens Mini Apps over HTTPS, and only on an address reachable from the
  internet. The app uses the shop's own domain: set `DEFAULT_URI=https://shop.example.com` (the same value the bot
  already uses for the subscription page). `localhost`, private IPs and plain `http://` are refused: no button is
  shown and the menu-button command stops with an error.
- **The migrations** `Version20261022120000` (table `mini_app_session`) and `Version20261023090000` (table
  `checkout_request`): `php bin/console doctrine:migrations:migrate`.
- **The bot token** `TELEGRAM_BOT_TOKEN`: the app checks that the login data was signed with it.
- No BotFather domain registration (`/setdomain` is for the Login Widget, not for Mini Apps).

Optional environment variable:

| Variable | Default | Meaning |
| --- | --- | --- |
| `MINIAPP_AUTH_MAX_AGE` | `3600` | How old (seconds) Telegram's login data may be when the app signs in. |

## Turning it on

1. Deploy and run the migration.
2. Set the **menu button** (the button next to the message field in every private chat with the bot):

   ```bash
   php bin/console app:telegram:set-menu-button --dry-run   # shows what would be sent
   php bin/console app:telegram:set-menu-button             # sets it to https://<DEFAULT_URI host>/app
   php bin/console app:telegram:set-menu-button --remove    # back to Telegram's default (the command list)
   ```

   The button text is the bot text `button.miniapp.menu` (editable under Bot texts).
3. To also show **"Open app" buttons in the chat**, set `miniapp.enabled` to `true`: Admin → Settings → *Advanced* →
   `miniapp.enabled` → Edit (`php bin/console app:settings:seed` adds the row, as `false`, if it is not listed). It is
   off by default. With it on:
   - the main menu gets a "📱 App" button; the bot answers it with an inline **Open app** button;
   - every service view gets a **View in the app** button that opens that service, next to the existing buttons.

   The main-menu button is a text button the bot answers with an inline button on purpose: a Mini App opened from a
   reply-keyboard button receives no login data from Telegram, so it could not sign the user in.

### BotFather (optional)

None of this is needed for the buttons above. If you also want the app in the bot's profile or a direct link:

- **Main Mini App** (an "Open" button on the bot's profile): BotFather → `/mybots` → your bot → *Bot Settings* →
  *Configure Mini App* → *Enable Mini App* → send `https://shop.example.com/app`.
- **Menu button by hand** instead of the command: BotFather → `/mybots` → your bot → *Bot Settings* → *Menu Button* →
  send `https://shop.example.com/app` and a title.
- **Direct link** (`https://t.me/<bot>/<name>`): BotFather → `/newapp`, choose the bot, give a title, description and
  photo, then the URL `https://shop.example.com/app` and a short name.

Use the same URL everywhere; it must match `DEFAULT_URI`.

## How sign-in works (security)

- `/app` itself holds no data. Its script sends `Telegram.WebApp.initData` to `POST /app/auth`. The server checks it
  exactly as Telegram specifies: HMAC-SHA256 with the key `HMAC_SHA256("WebAppData", bot_token)`, compared in constant
  time; `auth_date` at most `MINIAPP_AUTH_MAX_AGE` seconds old and at most 60 s in the future. `initDataUnsafe` is
  never used. Opened outside Telegram, the page only says to open it from the bot.
- The Telegram user becomes the same customer the bot uses (created on first use, like `/start`). Whether they are an
  admin is decided by the same rules as in the bot (`AdminAccessPolicy`: an active admin linked to that Telegram id, or
  `TELEGRAM_ADMIN_CHAT_ID`), on every request: deactivating an admin removes their admin rights in the app at once.
- A successful sign-in opens a server-side session: a 256-bit random token (only its SHA-256 is stored), valid 12 hours,
  bound to the Telegram user and to the browser's user agent, ended by closing it (`POST /app/logout`) or by
  `MiniAppSessionManager::revokeAllFor()`. The page keeps it in `sessionStorage` and sends it as
  `Authorization: Bearer …` (a header, not a cookie: Telegram Web and Desktop show the app in a cross-site iframe where
  browsers drop third-party cookies). Every state-changing request also needs the session's CSRF token
  (`X-CSRF-Token`).
- `POST /app/auth` is limited to 20 attempts per minute per IP and 10 sign-ins per 5 minutes per Telegram user. Failed
  attempts are logged with the reason and the IP only, never the login data or a token.
- Pages are `no-store`, `noindex`, sent without a Referer, and allow only the site's own files and Telegram's
  `telegram-web-app.js` (Content-Security-Policy).
- The customer's own subscription link, QR code and configs appear in exactly one `/app` response: the subscription
  view `/app/view/services/{id}/subscription`, for its signed-in owner. Every other `/app` response shows a service
  only as the subscription page's status block does (plan, status, expiry, traffic). Panel addresses, panel tokens,
  the bot token and other customers' data appear in no response at all (tests check each of these).
- Prices, discounts and totals are always computed by the server; nothing the client sends about a price is read.
  Every call that creates something (order, payment, trial) carries an `Idempotency-Key` from the page: the first call
  with a key inserts it into `checkout_request` (unique per customer) in the same transaction, a repeat (double tap,
  retry after a lost answer, or a parallel request) gets the first answer back, so no second order or payment is
  ever made. Creating calls are limited to 20 per 10 minutes per Telegram user, receipt uploads to 10.
- The app never marks a payment paid. Its status endpoint only reads the payment's state; payments are confirmed
  by the gateway's webhook/callback, its return page (`/payment/return/{token}`), the bot's "check payment" button or
  an admin, exactly as before.

## Routes

| Route | Method | What |
| --- | --- | --- |
| `/app` | GET | The app (public, no data) |
| `/app/auth` | POST | initData in, session out (JSON) |
| `/app/logout` | POST | Ends the session |
| `/app/api/me` | GET | Name and whether the user is an admin |
| `/app/view/nav` | GET | Bottom navigation for this user |
| `/app/view/home`, `/app/view/services`, `/app/view/services/{id}`, `/app/view/apps`, `/app/view/help`, `/app/view/admin` | GET | Sections (HTML fragments) |
| `/app/api/services/{id}/send-link`, `send-qr`, `resend-config`, `refresh` | POST | The owner's service actions |
| `/app/view/shop`, `/app/view/shop/plans/{id}` | GET | Plans for sale, a plan's checkout form |
| `/app/api/checkout/quote`, `/app/api/checkout/new` | POST | Server price; create a new-service order (key) |
| `/app/api/trial/{id}` | POST | Claim a trial (key) |
| `/app/view/orders/{id}/pay`, `/app/api/orders/{id}/pay` | GET / POST | Choose a payment method; start the payment (key) |
| `/app/api/orders/{id}/cancel` | POST | Cancel an unpaid order |
| `/app/view/payments/{id}`, `/app/api/payments/{id}/status` | GET | A payment's page; its state (read only, polled) |
| `/app/api/payments/{id}/receipt` | POST | Card-to-card receipt photo (multipart) |
| `/app/view/services/{id}/renew`, `/app/api/services/{id}/renew[/quote]` | GET / POST | Renew a service |
| `/app/view/services/{id}/add-traffic`, `/app/api/services/{id}/add-traffic[/quote]` | GET / POST | Extra traffic |
| `/app/view/orders`, `/app/view/orders/{id}` | GET | My orders (paged), one order |
| `/app/view/services/{id}/subscription` | GET | The owner's subscription: link, QR, configs, apps |
| `/s/{token}/open/{app}` | GET | Public import bridge (opened in the external browser) |

## Buying and paying

- **Card to card**: the page shows the card number, holder and the exact amount, then takes a photo of the receipt
  (JPEG, PNG or WebP, at most 5 MB; the content is checked, not the name). The photo is posted into the customer's
  own chat with the bot and stored as its Telegram file id, exactly like a receipt sent to the bot, and the admins
  who moderate payments get the usual alert with approve/reject buttons.
- **Online gateways** (Zibal, custom API, plugins) and **NOWPayments**: the gateway's page opens with
  `Telegram.WebApp.openLink`. When the customer comes back (to the app, or to the bot), the payment page polls the
  status (2 s, then slower, up to every 30 s) and shows paid → the subscription, in review, failed / needs support,
  cancelled or expired.

## One-tap import into apps

Telegram's in-app WebView blocks custom URL schemes (`hiddify://`, `v2rayng://`, `sn://`), so an import button
inside the Mini App would do nothing. The subscription view's **Add** buttons therefore open an https bridge page,
`/s/{token}/open/{app}`, in the phone's real browser with `openLink`. That page has one big "Add to <app>" button
with the app's import link (tapped by the customer, never automatic) and the subscription link to copy if the app
does not open. The bridge uses the same secret token, rate limit and private headers as the public subscription page
and exists only for an active client app with an import link, on a working subscription.

What was verified and what was not:

- Verified by tests: the import links of the seeded apps (Hiddify, v2rayNG, NekoBox) are built from the schemes
  documented in those apps' sources; no custom-scheme link is rendered inside the Mini App; the bridge opens only
  for active importing apps; apps without a scheme (v2rayN) get the copy button.
- **Not verified on real devices** (no Telegram client or phones were available while building this): whether
  `openLink` lands in the external browser rather than Telegram's in-app browser on each client, and whether each
  app actually opens from the bridge on Android and iOS. Check Hiddify and v2rayNG/NekoBox on Android, and Hiddify
  on iOS, before announcing the feature; the copy-link fallback works everywhere.

## Adding sections later

Sections are registered by `MiniAppSectionProvider` services (any class implementing it is picked up). A section has a
key, a label, an icon, a position, an audience (customer or admin; reseller later) with optional permissions, an
optional parent and its route. The bottom navigation, the admin list and the server-side check all come from
`MiniAppSections::isAllowed()`; a route belongs to a section through its default `_miniapp_section`. A new admin screen
gets a route in a provider, and the shell stays as it is.

## Troubleshooting

- **No "Open app" button in the bot**: is `miniapp.enabled` = `true`, and is `DEFAULT_URI` `https://` on a public
  domain?
- **"Open this page from Telegram"** inside Telegram: the app was opened from a reply keyboard or an old link without
  login data; use the menu button or the inline button.
- **"This page has expired"**: the app was left open for more than `MINIAPP_AUTH_MAX_AGE` and its session ended; close
  it and open it again from the bot.
