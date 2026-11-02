# Telegram Bot Content

Bot Content Manager lets admins edit user-facing Telegram text without changing bot logic.

Editable records:

- Message Templates: message, guide, help, rule, notification, and footer text.
- Button Labels: visible reply keyboard and inline button labels.

Protected records:

- Template keys and button keys are internal identifiers.
- `callback_data`, commands, state names, payment/order/service states, and system actions are not editable.

## Fallback

Text resolves in this order:

1. Active database override for key and locale.
2. Active database override for the default locale.
3. Symfony translation in `translations/bot.*.yaml`.
4. Emergency default in code.

The Mini App guide texts (`guide.*`) are the exception: an active row for exactly that language, else the shipped
default of that language (a Persian edit never shows in English).

## Mini App guide texts

The customer guide of the Mini App (Help section) is edited here too: **Message Templates → Mini App guide texts**
(or the category filter **Guide**) lists every `guide.<topic>.title` / `guide.<topic>.body` in Persian and English as
**Default** or **Customized**, with **Customize** / **Edit**. These rows use the parse mode **Guide (Mini App)**, are
checked on save (length, characters, placeholders, `::: if` blocks, links), show a live preview of the Mini App card,
and have **Reset to default** (confirmation; deletes your row; audited). They are not seeded. Syntax cheat sheet,
rules and examples: [Mini App guide](miniapp-guide.md#the-texts).

Quick syntax: blank line = paragraph · `## Title` · `- item` / `1. item` · `**bold**` · `[text](#pay)` ·
`[text](https://…)` · `{{ payment_methods }}` on its own line · `::: if wallet` … `:::`. HTML is shown as text.

## Variables

Templates support simple placeholders only, such as `{{ order.trackingCode }}`, `{{ payment.amount }}`, `{{ service.expiresAt }}`, `{{ bot.brandName }}`, and `{{ bot.footerText }}`. PHP, Twig expressions, and arbitrary code execution are not allowed.

## Commands

```bash
php bin/console app:bot-content:seed --locale=fa
php bin/console app:bot-content:seed --locale=en
php bin/console app:bot-content:list
php bin/console app:bot-content:missing --locale=fa
```

Use `--force` only when you intentionally want to overwrite existing admin edits.

The Mini App guide texts are never seeded (also not with `--force`): an untouched text follows the shipped default after
every update. `list` shows each guide text as default / customized per language; `missing` checks that each has a
shipped default (no database row is needed).

## Labels

Edit **Bot Content → Button Labels** in admin. Only the visible label changes. Reply keyboard actions are matched by internal button key and fallback labels, so changing a label does not break the flow. Inline `callback_data` remains fixed.
