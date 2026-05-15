# Plugin Package

## ZIP Structure

```text
plugin.json
README.md
src/
translations/
templates/
assets/
```

Only `plugin.json`, `README.md`, and `src/` are required for a simple payment gateway plugin.

## Manifest Fields

- `manifestVersion`: currently `1`.
- `type`: currently `payment_gateway`.
- `code`: unique lowercase code with letters, numbers, `_`, or `-`.
- `name`: localized display names.
- `version`: plugin version.
- `mainClass`: payment plugin class FQCN.
- `permissions`: declared permissions.
- `configSchema`: gateway config fields.

## ZIP Rules

- `plugin.json` may be at ZIP root or inside one root folder.
- macOS metadata such as `__MACOSX`, `.DS_Store`, and `._*` is ignored by the validator.
- Path traversal is rejected.
- Disallowed roots such as `vendor`, `public`, `var`, and `.git` are rejected.
- Duplicate plugin code is rejected during install.

Validate first:

```bash
php bin/console app:plugin:validate-package /path/to/plugin.zip
```
