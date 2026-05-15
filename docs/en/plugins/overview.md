# Plugins Overview

Plugin Core installs trusted ZIP packages into:

```text
var/plugins/{code}
```

The `Plugin` entity tracks code, type, status, manifest, path, main class, permissions, and validation errors.

## Status

- `installed`: package is installed but not enabled.
- `enabled`: plugin can be used.
- `disabled`: plugin is installed but inactive.
- `error`: validation failed and the plugin must not be used.

Currently supported plugin type:

- `payment_gateway`

Only install trusted plugins. Plugin PHP code runs in the application process.
