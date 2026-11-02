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
| Install and connect | always (generic steps; it names no app and no store) |
| Usage and expiry | always |
| Renewing | renewals are open (`sales.renewals_enabled`) |
| Adding traffic | extra traffic can be bought (`sales.add_traffic_enabled`, `traffic_addon.enabled` and a price per GB) |
| When it does not connect | always |
| Got a service link from someone? | claim links are on (`reseller.enabled` and `reseller.claims_enabled`) |
| Questions | always |
| Contact support | always; it also shows your support text (**Bot Content → `bot.support`**) and the back-to-bot button |

Payment methods are listed by their **title** only (Payment methods admin), never with card numbers or gateway
settings. A trial account is mentioned under "Buying a service" while a trial plan is active.

The guide is the same for every customer: it shows no personal data, link, token or tracking code.

## Opening a topic

`/app/view/help?topic=<topic>` opens that topic's card (`start`, `buy`, `pay`, `wallet`, `connect`, `usage`, `renew`,
`traffic`, `troubleshoot`, `claim`, `faq`, `support`). An unknown or hidden topic opens none.

## The texts

The default texts are in `translations/bot.fa.yaml` and `translations/bot.en.yaml`, keys `guide.<topic>.title` and
`guide.<topic>.body`. Editing them in the admin panel comes in a later release, under the same keys.

The format is plain text (no HTML; anything that looks like HTML is shown as text):

| Write | Shows |
|---|---|
| blank line | new paragraph |
| `## Title` | sub-heading |
| `- item` or `1. item` | list |
| `**bold**` | bold |
| `[text](#troubleshoot)` | link to another topic |
| `[text](https://example.com)` | external link (https only) |
| `{{ payment_methods }}` (own line) | the payment methods' titles |
| `::: if wallet` … `:::` | shown only while that feature is on |

Features for `::: if`: `new_orders`, `renewals`, `add_traffic`, `wallet`, `wallet_payment`, `claims`, `trial`,
`payment_methods`, `card_to_card`, `online`, `crypto`. A topic whose text is empty is not shown.

Your support text (`bot.support`) is shown exactly as typed: Telegram formatting tags in it appear as text in the Mini
App.
