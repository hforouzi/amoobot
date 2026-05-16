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

## Labels

Edit **Bot Content → Button Labels** in admin. Only the visible label changes. Reply keyboard actions are matched by internal button key and fallback labels, so changing a label does not break the flow. Inline `callback_data` remains fixed.
