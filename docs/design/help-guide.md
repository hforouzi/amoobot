# Customer Help / Guide in the Mini App — design

Status: **design, not implemented.** Persian version: [docs/fa/design/help-guide.md](../fa/design/help-guide.md).
Companion design (admin side): [activity-group-and-daily-report.md](activity-group-and-daily-report.md).

Items marked **DECISION NEEDED (Gx)** are decisions this document takes with a recommendation; the owner confirms or
changes them before the first PR. The guide replaces the current Help section and is **on by default** (it only adds
reading material); anything that changes other behaviour (the bot's Support answer) is behind a switch, default off.

## 0. What exists today and is reused

| Area | Today | Used for |
|---|---|---|
| Help section | Route `miniapp_help` → `MiniAppCustomerController::help()` (`src/MiniApp/UI/MiniAppCustomerController.php`) renders `templates/miniapp/view/help.html.twig`: one card with `bot.support` (from `BotTextResolver`, the bot's editable support text) and one card "Bot" with `help.bot_text` and a "back to the bot" button. Keys in `translations/miniapp.{fa,en}.yaml`: `nav.help`, `help.title`, `help.bot_title`, `help.bot_text`, `help.back_to_bot`. | Replaced by the guide; the support card stays (as the "Contact support" topic). |
| Stale text | `help.bot_text` says "Buying, renewing and adding traffic are done in the bot for now", but the Mini App has a shop (`miniapp_shop`), renew and add-traffic views (`templates/miniapp/view/renew.html.twig`, `add_traffic.html.twig`) | Fixed by G-1. |
| Support text rendering | `bot.support` is a bot template whose parse mode can be `html` (`BotMessageTemplate::$parseMode`, default `html`); the Mini App prints it with Twig auto-escaping in a `pre-line` paragraph, so an admin's `<b>` shows as literal `<b>` | The guide renders it through the guide renderer (section 3.1), which turns the Telegram HTML subset into safe markup or plain text. |
| Navigation / sections | `MiniAppSection` + `MiniAppSectionProvider` (tag `app.miniapp_section_provider`), `CoreSections` registers `help` (`💬`, order 40); `MiniAppSections::isAllowed()` + `MiniAppSectionGuard` enforce access server-side | The guide stays the `help` section (same key and route name, so nothing else breaks). |
| Client apps | `ClientApp` (`src/Entity/ClientApp.php`: name, `platforms` JSON, `importUrlTemplate`, `storeUrl`, `icon`, `notes`, `sortOrder`, `isActive`), `AppDownload` (`src/Entity/AppDownload.php`: title, platform, version, description, type `file`/`url`, external URL or stored file), `ClientPlatform` enum (android, ios, windows, macos, linux, `fromUserAgent()`); the Mini App `apps` view (`MiniAppCustomerController::apps()`, `clientApps->forCatalog($platform)`, `downloads->forPage($platform)`); admin under Apps (`APPS_MANAGE`) | The "Connect" topic is rendered **from these records** (no duplicated links in the guide text). |
| Admin-editable content | `BotMessageTemplate` (key, locale, title, body `text`, `parseMode` html/markdown/plain, `category`, `isActive`, `isSystem`); `BotTextResolver::message()` fallback: DB row for key+locale → default locale → `translations/bot.*.yaml` → emergency default in code; `app:bot-content:seed|list|missing`; admin "Bot Content" (`BotMessageTemplateCrudController`, `BOT_TEXTS_MANAGE`); docs `docs/en/telegram-bot-content.md` | Recommended content store (section 2). |
| Feature switches | `wallet_enabled()` Twig function (used in `services.html.twig`), `WalletSettings` (`wallet.enabled`, `wallet.payment_enabled`), `ResellerSettings` (`enabled()`, `claimsEnabled()`), `SalesSettingsProvider` (new orders, renewals, add traffic, closed message), `TrafficAddonSettingsProvider::canPurchase()`, trial plans (`TrialPlan`, `TrialAccountService`), store payment methods (`StorePaymentMethod`) and gateways, `MiniAppLinks` (`miniapp.enabled`, default off) | Topic and block visibility (section 4). |
| Deep links | `StartPayloadRouter` (`src/Bot/Application/DeepLink/StartPayloadRouter.php`, handlers by prefix, bot `/start <payload>` and Mini App `start_param`); `app.js`: `?service=ID` opens a service, `startapp=<payload>` opens the section `/app/auth` names in `start`; links: `a[data-nav]` (in-app), `a[data-open-link]` (`Telegram.WebApp.openLink`), `a[data-telegram-link]` (t.me) | Topic deep links (section 3.4). |
| Theming / RTL | `templates/miniapp/entry.html.twig`: `dir="rtl"` for `fa`, `ltr` otherwise; Telegram theme variables; CSP `MiniAppHeaders::CSP` — `script-src 'self' https://telegram.org`, no inline scripts | No inline JS; collapsible sections with `<details>` (section 3.3). |
| Bot support | `TelegramUpdateHandler::handleSupport()` sends `bot.support` with a "back to main menu" keyboard; `TelegramKeyboardFactory::miniAppOpen()` builds an inline `web_app` button via `MiniAppLinks::forTelegram()` (null when the Mini App is off or has no public HTTPS URL) | Optional "📖 Guide" button under the support answer (section 5.3). |
| Libraries | No Markdown or HTML-sanitizer library in `composer.json` (`twig/html-extra` only) | A small own renderer, no new dependency (section 3.1). |

## 1. Content outline

Topics have **stable slugs** (used in deep links, keys and tests). Each topic has a visibility rule (section 4) fixed
in code.

| Slug | Title (en) | Contents | Shown when |
|---|---|---|---|
| `start` | Getting started | What the shop sells in two lines; the three steps: buy → install an app → import the link | always |
| `buy` | Buying a service | Choosing a plan (and custom plans if any), discount codes, paying, where the service appears; trial account if offered | new orders open (else the shop's "sales closed" message) |
| `pay` | Payment methods | The active methods by their customer-facing label (card-to-card: send the receipt or tracking code, how review works; online gateways; crypto; wallet) — **labels only**, never card numbers or gateway credentials | at least one method active |
| `wallet` | Wallet | Top-up, paying from the wallet, where to see the balance and history | `wallet.enabled` |
| `connect` | Install and connect | Per platform (auto-selected from the user agent, switchable): the active client apps for that platform with their store links / downloads and **import button** (`importUrlTemplate`), then "copy the subscription link" / "scan the QR" steps pointing at *My services* (never the link itself in the guide) | always (degrades without apps, section 4.2) |
| `usage` | Usage and expiry | Where to see traffic and days left, what "suspended" and "expired" mean, refreshing usage | always |
| `renew` | Renewing | How renewal works (carry over of remaining traffic/days as configured in `renewal.*`), when to renew | renewals open |
| `traffic` | Adding traffic | Buying extra GB, limits | `TrafficAddonSettingsProvider::canPurchase()` and add-traffic sales open |
| `troubleshoot` | When it does not connect | Checklist: service active and not expired/out of traffic → update the subscription in the app → switch config/server → check date/time on the phone → another network (Wi-Fi/mobile) → reinstall/import again → contact support with the service number (`#S…`, never the link) | always |
| `claim` | Received a service link? | What a claim link is, that it is single-use and expires, what happens after accepting, that the seller keeps no access to links | `ResellerSettings::claimsEnabled()` |
| `faq` | Questions | Short Q&A (devices per service, speed, refunds policy text written by the admin, privacy) | always (admin can empty it → hidden) |
| `support` | Contact support | The existing `bot.support` text + "back to the bot" button | always |

**DECISION NEEDED (G1).** Confirm the list. In particular: is `faq` shipped with default questions (recommended: 4–5
neutral ones), and should the guide explain refunds (shop policy differs; recommended: only in FAQ, admin-written).

## 2. Content source

### 2.1 Alternatives

| | Store | Pros | Cons |
|---|---|---|---|
| a. Translation strings only | `translations/miniapp.{fa,en}.yaml` `guide.*` | Simplest, reviewed in git, always present | An admin cannot adapt it (their own payment rules, support hours, FAQ); every change is a deploy |
| b. Reuse bot content | `BotMessageTemplate` rows with keys `guide.<slug>`, category `guide`, defaults in `translations/bot.{fa,en}.yaml`, resolved by `BotTextResolver` | Admin-editable per language **today** (Bot Content admin, `BOT_TEXTS_MANAGE`), fallback chain, seed/missing commands and docs exist; no new table | Its parse modes are Telegram formats; the generic CRUD has no preview, no length cap, no guide-specific validation |
| c. New `guide_topic` table | own entity + own editor | Exactly shaped (order, visibility overrides, preview) | New migration, new admin, new seed path: duplicates (b) |

### 2.2 Decision: (b), with a guide-specific format and editor checks

- Each topic body is a bot text `guide.<slug>.body` and its title `guide.<slug>.title`; defaults shipped in `fa` and
  `en` in `translations/bot.*.yaml`, so it works out of the box with no DB rows and `app:bot-content:missing` reports
  them like any other key.
- A new parse mode value **`guide`** for these rows (section 3.1); the Bot Content form offers it only for `guide.*`
  keys and validates it (syntax, size ≤ 4,000 characters per body, ≤ 80 per title, `PlainText` character rules:
  no control/bidi-override characters).
- A **preview** on the Bot Content edit page for `guide.*` rows (renders with the same renderer, both directions).
- Variables: only the existing bot variables (`{{ bot.brandName }}` …) plus fixed guide placeholders
  (section 3.2); unknown placeholders are refused at save time.

**DECISION NEEDED (G2).** (b) as above (recommended), or (a) for v1 and editing later.

## 3. Rendering

### 3.1 The "guide" format (own renderer, no HTML from admins)

Input is plain text. The renderer **escapes everything first** and then recognises a fixed set of line constructs:

| Write | Renders |
|---|---|
| blank line | new paragraph |
| `## Title` | sub-heading (`h3`) |
| `- item` / `1. item` | list |
| `**bold**` | `<strong>` |
| `[text](https://…)` | external link, only `https://`, opened with `a[data-open-link]` (Telegram `openLink`), `rel="noopener noreferrer"` |
| `[text](#connect)` | link to another topic (`a[data-nav]`) — only existing slugs |
| `{{ placeholder }}` | fixed placeholders, section 3.2 |
| `::: if wallet` … `:::` | block shown only when the feature flag is on (section 4.1) |

No raw HTML, no images, no `javascript:`/`data:` URLs, no attributes. Output is built by the renderer from escaped
pieces only, then passed to Twig as safe; a fuzz/property test asserts that no input produces a tag outside the
allow-list. For `bot.support` (Telegram HTML), the same renderer has a mode that keeps `b/i/u/s/a href=https` and
escapes everything else.

**DECISION NEEDED (G3).** Own minimal format (recommended, zero dependencies, easy to review) or add
`symfony/html-sanitizer` and let admins write limited HTML (familiar for Telegram HTML, but a new dependency and a
larger attack surface).

### 3.2 Placeholders (fixed, data that is the same for every customer)

`{{ apps }}` (the client-app list for the selected platform, section 4.2), `{{ payment_methods }}` (labels of active
methods), `{{ support }}` (the support text), `{{ trial }}` (trial offer summary if any), `{{ bot.brandName }}`.
**No per-user placeholder exists**: the guide renders the same for every customer in one language and feature state,
so nothing of one customer can leak into another's page, and the HTML can be cached per (locale, settings
fingerprint). Personal data (the customer's link, balance, services) stays on the existing screens the guide links to.

### 3.3 Layout

- One page, topic list as `<details>` / `<summary>` cards (collapsible without JavaScript; CSP-safe), the topic from
  the deep link opened and scrolled to. `fa` RTL, `en` LTR from `entry.html.twig`; lists and headings use logical CSS
  properties (`margin-inline-start`) so both directions work; app names and URLs inside RTL text wrapped in `<bdi>`.
- Small screens: single column, no tables, tap targets ≥ 44 px, long URLs never shown as text (buttons instead).
- Search: a small client-side filter over titles and bodies in `public/miniapp/app.js` (`input[data-guide-filter]`,
  hides non-matching `<details>`; no server call). Cheap; **DECISION NEEDED (G4)**: include in v1 (recommended) or later.

### 3.4 Deep links

- In-app: `/app/view/help?topic=<slug>[&platform=<p>]` (unknown slug → the full list). Links added on:
  - empty *My services* (`services.html.twig`, `data-no-services`) → `buy`;
  - an expired / out-of-traffic service card or page → `renew` / `traffic` (only if visible);
  - the Apps view → `connect` with the same platform;
  - a card-to-card payment page → `pay`;
  - the service page "not working?" link → `troubleshoot`.
- From the bot or t.me: `startapp=guide_<slug>` handled by a `StartPayloadHandler` with prefix `guide` in
  `StartPayloadRouter` (opens the help section at that topic, Home behind it), and `?guide=<slug>` on the `web_app`
  URL for bot buttons (same handling as `?service=`).

## 4. Feature awareness and empty states

### 4.1 Rule

Visibility is computed in code from the same providers the shop uses, never typed by the admin:

| Flag | Source |
|---|---|
| `wallet` | `WalletSettings` enabled (and `payment_enabled` for "pay from wallet" lines) |
| `new_orders`, `renewals`, `add_traffic` | `SalesSettingsProvider` + `TrafficAddonSettingsProvider::canPurchase()` |
| `trial` | an active trial plan exists |
| `claims` | `ResellerSettings::claimsEnabled()` |
| `card_to_card`, `online`, `crypto` | active `StorePaymentMethod` / gateways by kind |

A hidden topic is absent from the list and its deep link falls back to the list. Inside visible topics, sentences about
optional features sit in `::: if <flag>` blocks (the shipped defaults use them, e.g. "or pay from your wallet" in
`buy`). An admin can still type a sentence about the wallet outside a block; the editor preview shows the page with
each flag on/off, and a warning lists flag words found outside blocks ("wallet", "کیف پول", …) — a hint, not a refusal.

### 4.2 No client apps configured

`connect` still renders: generic steps ("install a V2Ray-compatible app such as … from your app store, then import the
subscription link from *My services*"), without app names or store links of ours, plus "ask support which app to use".
The admin sees on the Apps admin page that the guide falls back (a hint, PR G-3). With apps configured, each app card
shows name, icon, store/download buttons (through the existing `/d/{id}` counter for downloads) and its `notes`; the
import button uses the existing import-URL mechanism of the Apps view, which needs the service's link and therefore
**links to the service page** instead of embedding the link in the guide.

### 4.3 Other empty states

- No payment method active → `pay` hidden, `buy` says sales are paused (the shop's closed message).
- `bot.support` empty → the support card shows only "back to the bot".
- Mini App disabled (`miniapp.enabled` off) → no guide anywhere (the bot Support answer is unchanged).

## 5. Admin side

### 5.1 Editing

Bot Content admin (`BOT_TEXTS_MANAGE`, roles super admin and admin): filter "Guide" (category `guide`), edit title and
body per language, preview, reset to default (delete the row → translation default). Audited like other bot text
edits (existing behaviour of the CRUD — confirmed in G-2). Topic order and visibility are code, not editable
(**DECISION NEEDED (G5)**: allow hiding a topic from the admin? recommended: yes, by setting its body to empty).

### 5.2 Docs

`docs/en/miniapp-guide.md` + `docs/fa/miniapp-guide.md`: what each topic shows, the format cheat sheet, placeholders,
feature blocks, deep links (for posting in channels: `https://t.me/<bot>?startapp=guide_connect`), how to reset; a
line in `docs/*/telegram-bot-content.md` pointing to it.

### 5.3 Bot

Behind `guide.bot_button_enabled` (**default off**, it changes the Support answer): `handleSupport()` adds an inline
`web_app` button "📖 Guide" (`?guide=start`) when `MiniAppLinks::forTelegram()` is not null. No new reply-keyboard
button (the main keyboard is already full and admin-labelled). The Telegram menu button keeps opening the app home.

## 6. Test plan

- Renderer unit tests: every construct; escaping of `<script>`, `"><img onerror>`, `javascript:`, `data:`, nested
  `**`; unknown slug links; placeholders; `if` blocks (nested refused at save); RTL text with Latin URLs; length caps;
  a property test (random input → output only contains allow-listed tags/attributes).
- Leak tests: a guide page rendered for customer A contains nothing of A's services, links or balance (compare the
  HTML of two customers: identical); no subscription URL, `/s/` token or claim token appears.
- Feature switches: each flag on/off → topic and block present/absent; deep link to a hidden topic → list.
- Client apps: none → generic text; per platform; inactive app hidden; download through `/d/{id}`.
- Fallback chain: no DB row → translation; `fa` row missing → default locale; invalid stored body (edited directly in
  the DB) → rendered as escaped plain text, not an error.
- Mini App functional tests (`MiniAppTestTrait`): `/app/view/help` 200 for a customer, `?topic=` opens the right card,
  `dir` attribute per locale, links from the empty services view and expired service card.
- Bot: switch off → Support answer unchanged; on and Mini App on → button present; Mini App off → no button.
- `startapp=guide_<slug>`: valid slug opens the topic, unknown slug opens the list, no account side effects.
- Translations: `fa` and `en` have every `guide.*` key (existing `MissingTranslationRecorder` pattern).

## 7. Pull requests

| # | Scope | Files (expected) | Tests | Migration / timer / deploy |
|---|---|---|---|---|
| G-1 | Guide renderer + topics from translations (fa/en defaults), help view rewritten (cards, `?topic=`), fix stale `help.bot_text`, support text rendered safely | `src/MiniApp/Guide/…`, `MiniAppCustomerController::help()`, `templates/miniapp/view/help.html.twig`, `translations/miniapp|bot.*.yaml`, CSS | renderer, escaping, RTL, feature flags, functional | no / no / normal |
| G-2 | Admin editing via Bot Content: `guide` parse mode, validation, preview, category filter; docs | `BotMessageTemplateCrudController`, `BotContentRegistry`, templates, `docs/*/miniapp-guide.md`, `docs/*/telegram-bot-content.md` | form validation, preview, permission | no (parse mode is a string column) / no / normal |
| G-3 | Connect topic from client apps + no-apps fallback + admin hint | guide topic provider, apps admin template | client-app cases | no |
| G-4 | Deep links: in-app links from services/expired/apps/payment views, `?guide=` and `startapp=guide_<slug>` | templates, `app.js`, new `StartPayloadHandler` | functional, start payload | no |
| G-5 | Client-side search filter | `app.js`, template | functional (JS-free assertion of markup) + manual | no |
| G-6 | Bot "📖 Guide" button behind `guide.bot_button_enabled` (default off) + setting in Settings | `TelegramUpdateHandler::handleSupport()`, settings | bot transcript tests | no |

Order: G-1 → G-3 → G-4 → G-2 → G-6 → G-5. G-1 alone already ships a usable guide with fixed text.

## 8. Open questions for the owner

- **G1** topic list; default FAQ; refunds only in FAQ.
- **G2** reuse Bot Content (recommended) vs translation-only v1.
- **G3** own minimal format (recommended) vs HTML sanitizer.
- **G4** search in v1.
- **G5** hiding a topic by emptying its body.
- **Q1** Should the subscription page (`/s/…`, `SubscriptionPageController`) link to the guide's `connect` and
  `troubleshoot` topics? It is outside Telegram, so it would need a public, non-Mini-App version of the guide — not
  proposed for v1.
- **Q2** Which client apps do you recommend per platform for the shipped default text (names only appear when you
  configure them; the generic text names none)?
- **Q3** Should resellers get their own guide topic in the reseller section (how to sell, claim links), or is
  `docs/*/resellers.md` enough?
