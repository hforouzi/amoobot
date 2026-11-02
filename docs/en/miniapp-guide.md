# Mini App guide (Help section)

The **Help** tab of the Mini App (`/app/view/help`) is a short guide for customers: one collapsible card per topic.
It works out of the box in Persian and English. Design: [docs/design/help-guide.md](../design/help-guide.md).

## Topics and when they are shown

A topic about a feature that is switched off is not shown, and the other topics do not mention it.

| Topic | Shown when |
|---|---|
| Getting started | always |
| Buying a service | new orders are open (`sales.new_orders_enabled`) |
| Payment methods | at least one payment method customers can use (an active method on an active, configured gateway), or wallet payment |
| Wallet | the wallet is on (`wallet.enabled`) |
| Install and connect | always: four short steps; step 1 shows the client apps you set up for the customer's device (see [Client apps in "Install and connect"](#client-apps-in-install-and-connect)), or a general text without app names when there is none. The text itself names no app |
| Usage and expiry | always |
| Renewing | renewals are open (`sales.renewals_enabled`) |
| Adding traffic | extra traffic can be bought (`sales.add_traffic_enabled`, `traffic_addon.enabled` and a price per GB) |
| When it does not connect | always |
| Got a service link from someone? | claim links are on (`reseller.enabled` and `reseller.claims_enabled`) |
| Questions | always |
| Contact support | always; it also shows your support text (**Bot Content → `bot.support`**), the back-to-bot button and, when a support chat is set (`support.url`), a **Message support** button |

Payment methods are listed by their **title** only (Payment methods admin), never with card numbers or gateway
settings. A trial account is mentioned under "Buying a service" while a trial plan is active.

The guide is the same for every customer: it shows no personal data, link, token or tracking code.

## Message support button (`support.url`)

**Settings → Branding → Support chat link** (permission *Manage settings*, audited with the branding form). Enter
`https://t.me/<username>` or `https://telegram.me/<username>`: a username of 5 to 32 letters, digits or `_`, nothing
after it (no `?start=…`, no path, no `tg://`, no other site). It is saved as the `t.me` link. Leave it empty for no
button. When it is set:

- the guide's **Contact support** topic shows a clearly visible **«پیام به پشتیبانی» / Message support** button that
  opens the chat inside Telegram;
- the bot's support answer (**🎧 Support**) gets the same button above **Back**.

With the field empty, both look exactly as before. The support text itself (`bot.support`) is still edited in the
bot texts; the button never takes a link from that text or from the guide texts. A value changed by hand in
**Settings → Advanced** that breaks the rule shows no button.

## Client apps in "Install and connect"

The connect topic is built from your **Catalog → Apps → Client apps** records (no app name is written in the guide
texts or in the code):

- **Device.** The guide picks the customer's device from the browser's User-Agent (Android, iOS/iPadOS, Windows,
  macOS, Linux) and shows a row of device buttons so the customer can switch (`/app/view/help?topic=connect&platform=ios`).
  When the device is unknown, the customer is asked to choose one.
- **Which apps.** Only **active** client apps whose platforms include that device, in the order of the Client apps
  list (sort order, then name). The **first** one is shown as **Recommended** with a big **Install** button; any others
  appear in a smaller "Other apps" line. To recommend Happ, make Happ the active app with the lowest sort order for
  each device and V2Box the next one.
- **Install link.** The app's active download for that device (through `/d/<id>`, which counts it), otherwise the app's
  install page (store URL). Only `http`/`https` links are used, exactly as you entered them (no tracking parameter is
  added); a broken link shows the app name without a button. Links open in Telegram's browser (`openLink`).
- **"Add subscription".** The guide never shows an import link, because that link contains the customer's
  subscription. If an app has a link template, the guide adds "you can add your service with one tap in **My
  services**", where the existing buttons are.
- **No app.** With no active app for the device (or none at all), the topic shows a general text ("install a VPN app
  that can add a subscription link from your device's app store… ask support") without any app name or button. The
  Client apps page warns you about each device without an active app.

The page is the same for every customer on the same device and language: names and icons are escaped and nothing of
the customer is in it. Setting up Happ and V2Box: [Admin guide](admin-guide.md#client-apps-in-the-customer-guide).

## Opening a topic

`/app/view/help?topic=<topic>` opens that topic's card (`start`, `buy`, `pay`, `wallet`, `connect`, `usage`, `renew`,
`traffic`, `troubleshoot`, `claim`, `faq`, `support`). An unknown or hidden topic opens none.

## The texts

The default texts are in `translations/bot.fa.yaml` and `translations/bot.en.yaml`, keys `guide.<topic>.title` and
`guide.<topic>.body`. You can change any of them in the admin panel (next section); a text you have not changed follows
the shipped default, also after an update.

### Editing a text in the admin panel

1. Open **Bot Content → Message Templates** and press **Mini App guide texts** (or filter the list on the category
   **Guide**). Every guide text is listed in Persian and English: **Default** (the shipped text is shown) or
   **Customized** (your own text). A text marked *Hidden now* belongs to a topic whose feature is switched off.
2. Press **Customize** (your own copy starts from the shipped text) or **Edit**.
3. Change the text. The **Preview** under the form shows the topic card as customers will see it in the Mini App
   (right to left for Persian, left to right for English), updated while you type, with the problems saving would
   meet. Nothing changes for customers until you press **Save**.
4. Save. The rules below are checked; a broken text is refused with a message that says what to fix.

Each language is its own text: a Persian edit never shows on the English guide (and the other way round).

**Hide a topic:** save its body empty. **Show it again:** reset it (below) or write a text.
**Which topics show** is decided in code by the shop's settings (table above), never by the texts: an edited text
cannot make the topic of a switched-off feature appear.

**Reset to default:** on the edit page press **Reset to default** and confirm. Your text for that language is deleted
and the shipped default shows again. Creating, editing and resetting guide texts are recorded in the audit log (the
key and language, never the text). You need the Bot Content permission (`BOT_TEXTS_MANAGE`).

The rules on save:

- parse mode **Guide (Mini App)**, only for the `guide.*` keys;
- a body at most 4,000 characters, a title at most 80 characters on one line;
- no control characters (tab, escape codes) and no invisible characters (direction overrides and isolates, zero-width
  space, byte order mark); the zero-width non-joiner of Persian is fine;
- placeholders: only `{{ payment_methods }}` and `{{ apps }}` (body, on a line of its own; `{{ apps }}` only in the
  "Install and connect" body) and `{{ bot.brandName }}`,
  `{{ bot.footerText }}`, `{{ bot.supportText }}`; nothing of one customer can be shown;
- `::: if <feature>` only with a feature from the list below, each closed by `:::` on its own line (blocks may nest);
- links only to a topic of the guide (`#pay`) or a full `https://` address.

The format is plain text (no HTML; anything that looks like HTML is shown as text):

| Write | Shows |
|---|---|
| blank line | new paragraph |
| `## Title` | sub-heading |
| `- item` or `1. item` | list (a numbered list always counts from 1, also when a `::: if` block hides its first items) |
| `**bold**` | bold |
| `[text](#troubleshoot)` | link to another topic |
| `[text](https://example.com)` | external link (https only) |
| `{{ payment_methods }}` (own line) | the payment methods' titles |
| `{{ apps }}` (own line, connect only) | the client apps of the customer's device; if the text has no such line, the apps are shown on top of the topic |
| `::: if wallet` … `:::` | shown only while that feature is on |

Features for `::: if`: `new_orders`, `renewals`, `add_traffic`, `wallet`, `wallet_payment`, `claims`, `trial`,
`payment_methods`, `card_to_card`, `online`, `crypto`. A topic whose text is empty is not shown.

Your support text (`bot.support`) is shown exactly as typed: Telegram formatting tags in it appear as text in the Mini
App.
