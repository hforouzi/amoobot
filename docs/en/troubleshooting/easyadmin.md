# EasyAdmin Troubleshooting

Known implementation constraints and previous issues:

- `renderAsBadges` may be unsupported in the installed EasyAdmin version.
- `addPanel` may be unsupported for some field layouts.
- `CodeEditorField` JSON language support may not exist.
- JSON arrays can trigger array-to-string errors if formatted directly.
- Do not add `Action::NEW` to `PAGE_DETAIL`; use supported pages/actions.
- i18n/layout values can be null; templates should handle missing values.
- Persian RTL forms need CSS checks for direction, spacing, and field alignment.

When changing admin UI, prefer existing field patterns and verify both `fa` and `en`.
