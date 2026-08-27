# Changelog

All notable changes to the MDS PHP SDK are recorded here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.3.0] - 2026-08-27

Additive on both sides. A 1.3.0 SDK against an API without the gates falls back to `is_active()` and behaves like 1.2.0; an older SDK against the new API ignores the new fields and keeps working, with the caveat that it does not honour waivers — a waived customer running 1.2.0 or earlier still receives no updates while the license is expired. **Matching API support is required** for the waivers to work: `/v2/updates/validate` has to return `updates_allowed` / `downloads_allowed`, and `/v2/update-check` the `access` block.

### Added

- `Integration::allows_updates()` and `Integration::allows_downloads()`, shortcuts for the two new permissions — this is what the product should gate its update-related interface on, not `is_licensed()`
- `LicenseStatus::allowance()`, `::updates_allowed()` and `::downloads_allowed()`, which read the `updates_allowed` / `downloads_allowed` fields returned by `/v2/updates/validate`. They return `null` when the server did not answer (an older API, or a status stored before the gates existed) — `null` is not "no", it is "no answer"
- `License\Manager::allows_updates()` and `::allows_downloads()`, which resolve the effective permission: the stored server answer wins, and in its absence it falls back to `is_active()`
- `Updates\UpdateTransformer::downloads_granted( $data )`, which reads `access.downloads_granted` from the update-check response. It assumes `true` when there is no `access` block, because an older API that sent a `release` sent the package along with it
- `Updates\AbstractUpdater::package_withheld( $data )`, so the product can recognize the "version announced, package withheld" case
- `LicenseStatus::GATE_UPDATES` and `::GATE_DOWNLOADS`

### Changed

- **The update check is no longer barred by `is_active()` and is now barred by `allows_updates()`.** This is the central fix of this release: the API can now waive a gate for a specific license — this is how a customer who bought before the product started requiring a license keeps updating — and that waiver lives on the server. A client deciding on its own refused the request before the server had a chance to honour its own waiver. With no stored answer, the behaviour is identical to 1.2.0
- `Rollback\Manager::list_versions()` and `::rollback()` now consult `allows_downloads()` instead of `is_active()`. Rollback is an installation, so it answers to the download gate: a waived customer lists versions normally, and a product that only opened the update feed does not
- `Admin\Notices` got wording of its own for a license the server keeps serving. Telling someone who is receiving updates that "updates are paused" is not a harmless inaccuracy — it sends the customer to support over a non-problem and teaches them that the notice can be ignored
- `LicenseStatus::STATUS_NOT_REQUIRED` now has two origins: the `license` feature switched off (as before) and the server answering that the product's gate is open and the key was not consulted

## [1.2.0] - 2026-08-09

### Added

- **Feature flag system** (`Config\Features`): every part of the SDK — license, updates, rollback, heartbeat, notices, details modal, automatic menu, admin panels and logging — becomes a named feature that can be switched on or off per product. With no configuration at all the behaviour is identical to 1.1.0
- Presets through the `mode` key in `SDK::register()`: `full` (default, everything on), `license_only` (right of use only — the product distributes updates through another channel) and `updates_only` (free product served by MDS, with no key and no license interface)
- `features` key in the configuration, which overrides the preset item by item — for example `array( 'notices' => false )` so the product renders its own notices
- Resolution in four layers, in this order: preset → `features` → filters → constants. The `MDS_SDK_FEATURE_{NAME}` and `MDS_{SLUG}_FEATURE_{NAME}` constants have the final word and cannot be reverted by product code, serving as the site owner's switch
- Feature filters: `mds_sdk_features` (all products, with the slug and the configuration as arguments) and `mds_{slug}_feature_{name}` (feature by feature)
- Notice filters: `mds_{slug}_notice_screens` (screens where the notice appears), `mds_{slug}_notice_message` (text), `mds_{slug}_notice_args` (type, dismissible, link text and URL) and `mds_{slug}_should_show_notice` (the final decision, able to force both display and suppression)
- Further filters: `mds_{slug}_capability` (capability required per context: `settings`, `notices` or `rollback`), `mds_{slug}_update_check_ttl`, `mds_{slug}_grace_period`, `mds_{slug}_heartbeat_recurrence`, `mds_{slug}_settings_url` (for when the panel is embedded in a screen of its own) and `mds_{slug}_request_body` (outgoing payload)
- `mds_{slug}_before_boot` (receives `Features` and `Product`) and `mds_{slug}_booted` (receives the `Integration`) actions
- `LicenseStatus::STATUS_NOT_REQUIRED`, `LicenseStatus::not_required()` and `LicenseStatus::is_required()`, which describe a product without licensing without having to fake an invalid status
- New accessors: `Integration::features()`, `Integration::notices()`, `Product::features()`, `Product::capability()`, `Product::prefix()`, `License\Manager::is_enabled()`, `Cron\Scheduler::is_enabled()` and `Cron\Scheduler::recurrence()`

### Changed

- Updates no longer require a license unconditionally. The gate is now the `license_gate_updates` feature, on by default; with it switched off the update check is sent without `license_key` (the field is omitted, not sent empty, so the API can tell "free product" from "missing key"). The response is still mandatorily verified by ed25519 signature — no feature and no filter affects that. **Matching API support is required**: `/v2/update-check` has to accept a request without `license_key` for products marked as free, otherwise `updates_only` mode gets an error from the server
- Features are registered according to what registering them costs: the structural ones (`updates`, `heartbeat`, `admin_menu`, `license`, `rollback`) are decided once at boot, because registering them costs an API call or schedules a cron event; the behavioural ones (`notices`, `update_details`, panels, `logging`) are re-evaluated on every use and stay filterable long after boot, including from a theme's `functions.php`
- With `license` switched off the whole module goes inert: no option is read or written, no request is made, `status()` returns `not_required` and `is_active()` / `Integration::is_licensed()` return `true`, so consumer code doing `if ( $sdk->is_licensed() )` keeps releasing the product
- `Cron\Scheduler::register()` now removes a previously scheduled event when the heartbeat is switched off, instead of leaving it orphaned
- `Cron\Scheduler::schedule()` uses the filtered recurrence, validated against `wp_get_schedules()` and falling back to `daily` when the value does not match a registered schedule
- Capabilities are no longer hard-coded: `manage_options` for license and notices, `update_plugins` / `update_themes` for rollback, all of them going through the `mds_{slug}_capability` filter
- `Admin\LicenseSettings::render()` now requires the capability before rendering, and not only in the form handlers
- `Support\Logger` respects the `logging` feature, which still defaults to `WP_DEBUG` — the previous behaviour — but can now be forced on to capture a specific request
- `Admin\Notices` receives the `LicenseSettings` instead of the already resolved URL, so that `mds_{slug}_settings_url` works even when applied after boot
- `Config\Product` stores `update_check_ttl` and `grace_period` untreated and applies the filter and the clamp on read, so both can be adjusted per site
- New `mds_rollback_disabled` error code, returned by `Rollback\Manager::rollback()` when the feature is switched off

## [1.1.0] - 2026-08-07

### Added

- **Bundle licenses**: a single key now covers several products (e.g. "Clube M"). Nothing changes in the protocol for the product — it keeps sending its own `product_slug` and the same key simply validates for every product the bundle grants. The validation response carries the additive `bundle` field (`id`, `name`, `slug`, `products[]`), reachable through `LicenseStatus::get( 'bundle' )`, which makes it possible to show "Licensed through Clube M" and list what is included
- `LicenseStatus::extra()` / `::get()` expose the fields the validation endpoint returns beyond the ones modelled by the class (plan, renewal URL, support expiry, failure reason), so the product can render its own license screen without a second request. They are persisted along with the status, so they stay available during an outage within the grace period

### Changed

- `Manager::validate()` now stores the `message` returned by the server in the status, instead of discarding it
- `Manager::activate()` sends `product_slug` along with the `plugin_version` that `Environment::request_meta()` already provided. The API requires both when the key is a bundle license — its products share a single seat, so the request has to say which product is activating — and keeps the fields optional for single-product licenses. Older API versions ignore the extra field
- `Manager::deactivate()` sends `product_slug` as well. On a bundle license this releases only this product's binding to the site — the seat stays with the remaining products until the last one leaves — so uninstalling one plugin does not deactivate the others. The API requires the field for bundle keys. Consequence for consumers: a copy of the SDK older than this release cannot activate or deactivate a bundle license (it gets a `400`); single-product licenses are unaffected

## [1.0.0] - 2026-06-18

### Added

- Version-aware loader (`mds-sdk.php`), which boots the most recent embedded copy and avoids class collisions between plugins
- `SDK::register()` facade and a per-product `Integration` container
- License lifecycle: activate / deactivate / validate by heartbeat, with a configurable grace period and multisite-safe option storage
- ed25519 signature verification of the responses (`SignatureVerifier`) — the anti-piracy core, which rejects unsigned, tampered or replayed responses on the critical license calls
- Plugin and theme updaters, with the update check bounded by a transient and fired by cron
- Version listing and rollback (downgrade), protected by capability and nonce
- Daily license heartbeat through WP-Cron, with per-site jitter
- Reusable, overridable admin interface (license panel, rollback list, notices)
- PHPUnit test suite and CI for PHP 7.4–8.3

[Unreleased]: https://github.com/meumouse/mds-php-sdk/compare/v1.3.0...HEAD
[1.3.0]: https://github.com/meumouse/mds-php-sdk/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/meumouse/mds-php-sdk/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/meumouse/mds-php-sdk/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/meumouse/mds-php-sdk/releases/tag/v1.0.0
