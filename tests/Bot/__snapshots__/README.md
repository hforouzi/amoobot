# Golden snapshots

Golden files of what users see, compared byte for byte so a refactoring can prove it changed nothing visible.

## Where they live

| Files | Written by | Content |
|---|---|---|
| `tests/Bot/__snapshots__/*.txt` | `BotTranscript::assertMatchesSnapshot()` | bot checkout transcripts (`BotCheckoutCharacterizationTest`) |
| `tests/Bot/__snapshots__/customer-view/` | `CustomerViewSnapshot::assertTranscript()` | bot service views, deliveries, notifications, claims |
| `tests/MiniApp/__snapshots__/customer-view/` | `CustomerViewSnapshot::assertMatches()` | Mini App HTML fragments and action JSON, fa and en |
| `tests/Subscription/__snapshots__/customer-view/` | `CustomerViewSnapshot::assertMatches()` | the `/s/{token}` page and the `/open/{appId}` bridge, fa and en |

The customer-view set was recorded before PM1d (`docs/design/product-modules.md`, section 9).

## Recording

Only `UPDATE_BOT_SNAPSHOTS=1` writes files (`tests/Support/SnapshotFile.php`). A missing snapshot fails the test; it is
never created silently, so CI cannot write or pass against a snapshot it just made.

```sh
UPDATE_BOT_SNAPSHOTS=1 vendor/bin/phpunit tests/Bot/CustomerViewBotSnapshotTest.php   # record or update
vendor/bin/phpunit tests/Bot/CustomerViewBotSnapshotTest.php                          # compare
```

Review the diff of every re-recorded file before committing it: a change there is a change customers see.
Line endings are compared as `\n` whatever the checkout's `core.autocrlf`.

## What is normalised

Only values a test cannot fix; everything else is the real output.

- **Database ids** (`BotTranscript`): named by where they appear, never by value alone. A callback argument is named by
  its route's table (`CALLBACK_ARGUMENTS`; `null` marks an argument that is not an id, such as the reset nonce), a
  text id by the words before it (`TEXT_CONTEXTS`). An unregistered id or an unknown route fails.
- **Service ids** (`CustomerViewSnapshot`): only after `/`, `#`, `=`, a quote, `:`, `VPN `, `VPN%20` or `سرویس `, and
  not followed by a letter or digit (so a CSS colour is never taken for an id).
- **Opaque values** by exact value, longest first: public tokens, uuids, panel usernames and emails, sub ids, tracking
  codes, the reset nonce, and client app ids together with their context (`/open/12`, `data-app-id="12"`).
  `BotTranscript` refuses a bare number as a value.
- **Dates**: services are built at fixed offsets from the test's `$now`. `Y-m-d H:i:s` becomes
  `<datetime now+20d+12h>` (rounded to the hour). A calendar day alone (`Y-m-d`, or the Persian calendar form) is
  named after the expiry it shows: `<date of now+20d+12h>`, `<jdate of now+20d+12h>`.
- **Callback query ids**: `answerCallbackQuery chat=cb`.
