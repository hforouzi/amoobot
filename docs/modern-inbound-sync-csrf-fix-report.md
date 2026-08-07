# Modern Inbound Sync CSRF Fix Report

## A. Exact EasyAdmin Version

- `easycorp/easyadmin-bundle`: `v5.0.7`
- Symfony: `7.4.8`
- PHP: `8.4.20`

`composer.lock` confirms EasyAdmin `v5.0.7` at reference `b19dcd69603eeae35b81cf6dde1078729e3b140c`.

## B. Exact Original Root Cause

The Modern inbound synchronization EasyAdmin action was configured as a state-changing action without a valid EasyAdmin action target after the direct route link was removed.

In EasyAdmin `v5.0.7`, `Action::getAsDto()` requires one of:

- `linkToCrudAction()`
- `linkToRoute()`
- `linkToUrl()`

`setTemplatePath()` only changes rendering; it does not define a target. Therefore an action with only `setTemplatePath()` throws:

`Actions must link to either a route, a CRUD action, or a URL.`

The earlier direct `linkToRoute()` pointed at the POST execution route, so EasyAdmin rendered a GET link and Symfony correctly returned `MethodNotAllowedException`.

## C. Why setTemplatePath Alone Failed

Installed EasyAdmin source:

- `vendor/easycorp/easyadmin-bundle/src/Config/Action.php`

The action contract validates that every action has a route, CRUD action, or URL. A custom template path is not a target and cannot satisfy that validation.

The installed action template is:

- `vendor/easycorp/easyadmin-bundle/templates/crud/action.html.twig`

There is no `src/Resources/views/crud/action.html.twig` path in this installed version.

## D. Final EasyAdmin Action Configuration

`syncModernInbounds` now uses:

`linkToRoute('admin_vpn_panel_sync_modern_inbounds_confirm', ['id' => panel id])`

The EasyAdmin action opens a safe GET confirmation page. It does not execute synchronization directly.

Visibility remains limited to persisted Modern Sanaei panels:

- panel ID is not null
- type is `sanaei_3xui`
- integration mode is `MODERN`

## E. GET Confirmation Route

Route:

- Name: `admin_vpn_panel_sync_modern_inbounds_confirm`
- Method: `GET`
- Path: `/admin/vpn-panel/{id}/sync-modern-inbounds/confirm`

This route validates that the selected panel is a persisted Modern Sanaei panel and renders:

- `templates/admin/vpn_panel/sync_modern_inbounds_confirm.html.twig`

It does not run the synchronizer and does not mutate state.

## F. POST Execution Route

Route:

- Name: `admin_vpn_panel_sync_modern_inbounds`
- Method: `POST`
- Path: `/admin/vpn-panel/{id}/sync-modern-inbounds`

This route:

- resolves `VpnPanel` through Symfony/Doctrine
- rejects non-Modern/non-Sanaei panels
- reads `_token` from the POST body
- validates CSRF before synchronization
- invokes `ModernInboundSynchronizer` only after validation
- redirects to the VpnPanel detail page
- avoids exposing raw exceptions or secrets

## G. CSRF Generation String

Twig confirmation form:

`csrf_token('sync_modern_inbounds_' ~ panel.id)`

Example for panel 12:

`sync_modern_inbounds_12`

The token is rendered as:

`<input type="hidden" name="_token" ...>`

It is not placed in the URL.

## H. CSRF Validation String

Controller validation:

`sync_modern_inbounds_'.$panel->getId()`

The submitted token is read from:

`$request->request->get('_token', '')`

Generation and validation use the same byte-for-byte token ID format.

## I. Redirect Implementation

The controller uses `AdminUrlGeneratorInterface` with `unsetAll()` before generating the VpnPanel detail URL:

- controller: `VpnPanelCrudController`
- action: `Action::DETAIL`
- entity ID: selected panel ID

This avoids carrying stale EasyAdmin query parameters.

## J. Tests Added

Added:

- `tests/Admin/VpnPanelModernInboundSyncCsrfTest.php`

This verifies:

- confirmation page renders a real POST form
- hidden `_token` is present
- token ID is `sync_modern_inbounds_<panel-id>`
- POST action contains the selected panel ID
- CSRF token is not in the URL
- controller reads `_token` from the POST body
- action links to the GET confirmation route
- action is limited to persisted Modern panels

The project does not currently provide PHPUnit/browser functional test tooling in `vendor/bin`, so the added test is a standalone executable PHP regression test consistent with the existing local test style.

## K. Full Command Outputs

Key audit output:

```text
composer show easycorp/easyadmin-bundle
versions : * v5.0.7
path     : E:\Laragon\www\amoobot\vendor\easycorp\easyadmin-bundle
```

```text
php bin\console about
Symfony Version 7.4.8
PHP Version 8.4.20
Environment dev
```

Route output:

```text
admin_vpn_panel_sync_modern_inbounds_confirm GET  /admin/vpn-panel/{id}/sync-modern-inbounds/confirm
admin_vpn_panel_sync_modern_inbounds         POST /admin/vpn-panel/{id}/sync-modern-inbounds
```

Validation output:

```text
php bin\console cache:clear
[OK] Cache for the "dev" environment (debug=true) was successfully cleared.

php bin\console lint:container
[OK] The container was linted successfully: all services are injected with values that are compatible with their type declarations.

php bin\console lint:twig templates
[OK] All 17 Twig files contain valid syntax.
```

Focused tests:

```text
php tests\Admin\VpnPanelModernInboundSyncCsrfTest.php
VPN panel Modern inbound sync CSRF tests passed.

php tests\Admin\VpnPanelAdminFormTest.php
VPN panel admin form tests passed.

php tests\Admin\VpnInboundAdminReadOnlyTest.php
VPN inbound read-only admin tests passed.

php tests\Provisioning\SanaeiModernInboundSyncTest.php
Sanaei Modern inbound sync API and mapper tests passed.

php tests\Provisioning\SanaeiModernHttpCoreTest.php
Sanaei Modern HTTP core tests passed.

php tests\Provisioning\SanaeiModernConnectionTesterTest.php
Sanaei Modern connection tester tests passed.

php tests\Provisioning\SanaeiIntegrationFoundationTest.php
Sanaei integration foundation tests passed.
```

Diff hygiene:

```text
git diff --check
exit code 0
Only Git CRLF warnings were reported.
```

## L. Files Added

- `templates/admin/vpn_panel/sync_modern_inbounds_confirm.html.twig`
- `tests/Admin/VpnPanelModernInboundSyncCsrfTest.php`
- `docs/modern-inbound-sync-csrf-fix-report.md`

## M. Files Modified

- `src/Admin/UI/Crud/VpnPanelCrudController.php`
- `src/Admin/UI/VpnPanelInboundActionsController.php`
- `translations/admin.en.yaml`
- `translations/admin.fa.yaml`

Related uncommitted feature work from the same branch remains preserved.

## N. Manual Verification Result

Manual browser verification was not performed in this CLI session. The automated Twig rendering test verifies the confirmation page contains:

- `<form`
- `method="post"`
- hidden `name="_token"`
- correct panel ID in POST action
- no CSRF token in the URL

Recommended browser checks:

1. Open VpnPanel EasyAdmin index.
2. Confirm the page renders without exception.
3. Confirm Modern Sync action appears only for persisted Modern Sanaei panels.
4. Click the action.
5. Confirm browser performs GET to `/sync-modern-inbounds/confirm`.
6. Confirm the page contains a POST form with `_token`.
7. Submit.
8. Confirm POST reaches `/sync-modern-inbounds`.
9. Confirm CSRF succeeds and synchronizer runs.
10. Confirm redirect and flash.
11. Confirm direct GET to execution route returns 405.

## O. Remaining Issue

No remaining implementation issue is known for the CSRF flow. Browser-level verification should still be performed against a local authenticated EasyAdmin session.

Suggested commit message:

`fix(admin): complete CSRF-safe modern inbound sync flow`
