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
| Install and connect | always: four short steps with **Happ**, the app the shop recommends on every platform (App Store or Google Play; on Windows/Linux the download button when there is one, otherwise ask support). V2Box is named once there as an alternative, and nowhere else. No link, store URL or deep link |
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

## Download buttons for Happ and V2Box

The connect topic tells Windows and Linux customers to use "the download button below, if you see one". To offer
those buttons, add Happ (and V2Box if you like) under **Catalog → Apps**: a **Download** per platform (file or
external link) and, if you want, a **Client app** linked to it. The guide shows them once **G-3** (the connect topic
built from the client apps) ships; until then the topic is the same for every shop, and the downloads appear on the
subscription page and the Apps tab as before.

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
- placeholders: only `{{ payment_methods }}` (body, on a line of its own) and `{{ bot.brandName }}`,
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
| `::: if wallet` … `:::` | shown only while that feature is on |

Features for `::: if`: `new_orders`, `renewals`, `add_traffic`, `wallet`, `wallet_payment`, `claims`, `trial`,
`payment_methods`, `card_to_card`, `online`, `crypto`. A topic whose text is empty is not shown.

Your support text (`bot.support`) is shown exactly as typed: Telegram formatting tags in it appear as text in the Mini
App.
