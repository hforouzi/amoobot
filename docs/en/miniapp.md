# Telegram Mini App

The Mini App (`/app`) is a small web app that opens inside Telegram, for customers today and for admins and resellers
later. In this release customers see their services (status, expiry, traffic), open the subscription page, have the
bot send their link, QR code or configs into the chat, refresh usage, browse the client apps and downloads, and read
the support text. Admins also get an **Admin** entry that lists the areas their role allows, marked "coming soon".
Buying, paying and admin forms are not in the app yet: they stay in the bot and the web panel.

## Requirements

- **A public HTTPS address.** Telegram only opens Mini Apps over HTTPS, and only on an address reachable from the
  internet. The app uses the shop's own domain: set `DEFAULT_URI=https://shop.example.com` (the same value the bot
  already uses for the subscription page). `localhost`, private IPs and plain `http://` are refused: no button is
  shown and the menu-button command stops with an error.
- **The migration** `Version20261022120000` (table `mini_app_session`): `php bin/console doctrine:migrations:migrate`.
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
- What the app shows about a service is what the subscription page's status block shows: plan, status, expiry, traffic.
  No panel address, sub id, config, subscription URL or token is in any `/app` response. Those stay on the
  subscription page (opened from the app with Telegram's in-app browser) or reach the chat through the bot.

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
