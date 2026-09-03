# Changelog

All notable changes to the MDS PHP SDK are recorded here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html). Every entry is a single short sentence — the detail lives in the commit it describes.

## [Unreleased]

## [1.3.0] - 2026-08-27

Additive on both sides, but the waivers need matching API support: `/v2/updates/validate` must return `updates_allowed` / `downloads_allowed` and `/v2/update-check` the `access` block, otherwise the SDK falls back to `is_active()` and behaves like 1.2.0.

### Added

- `Integration::allows_updates()` and `::allows_downloads()`, the two permissions a product should gate its update interface on.
- `LicenseStatus::allowance()`, `::updates_allowed()` and `::downloads_allowed()`, which return `null` when the server gave no answer.
- `License\Manager::allows_updates()` and `::allows_downloads()`, resolving the stored server answer and falling back to `is_active()`.
- `Updates\UpdateTransformer::downloads_granted()`, reading `access.downloads_granted` from the update-check response.
- `Updates\AbstractUpdater::package_withheld()`, so the product can recognize an announced version whose package is withheld.
- `LicenseStatus::GATE_UPDATES` and `::GATE_DOWNLOADS`.

### Changed

- The update check is barred by `allows_updates()` instead of `is_active()`, so a server-side waiver is honoured instead of being refused by the client.
- `Rollback\Manager::list_versions()` and `::rollback()` consult `allows_downloads()`, since rollback is an installation.
- `Admin\Notices` got its own wording for a license the server keeps serving, instead of announcing paused updates to someone still receiving them.
- `LicenseStatus::STATUS_NOT_REQUIRED` now also means the server answered that the product's gate is open.

## [1.2.0] - 2026-08-09

### Added

- Feature flag system (`Config\Features`), turning every part of the SDK into a named feature switchable per product.
- Presets through the `mode` key: `full`, `license_only` and `updates_only`.
- `features` key in the configuration, overriding the preset item by item.
- Four-layer resolution — preset → `features` → filters → constants — with the `MDS_*_FEATURE_*` constants having the final word.
- Feature filters `mds_sdk_features` and `mds_{slug}_feature_{name}`.
- Notice filters for the screens, message, arguments and the final display decision.
- Filters for capability, update-check TTL, grace period, heartbeat recurrence, settings URL and outgoing request body.
- `mds_{slug}_before_boot` and `mds_{slug}_booted` actions.
- `LicenseStatus::STATUS_NOT_REQUIRED`, `::not_required()` and `::is_required()`, describing a product without licensing.
- New accessors on `Integration`, `Product`, `License\Manager` and `Cron\Scheduler`.

### Changed

- Updates no longer require a license unconditionally: the `license_gate_updates` feature decides, and the response is still ed25519-verified either way.
- Structural features are decided once at boot; behavioural ones stay filterable long after boot.
- With `license` off the module goes inert and `is_licensed()` returns `true`, so consumer code keeps releasing the product.
- `Cron\Scheduler::register()` removes a previously scheduled event when the heartbeat is switched off.
- `Cron\Scheduler::schedule()` validates the filtered recurrence against `wp_get_schedules()` and falls back to `daily`.
- Capabilities are no longer hard-coded and go through the `mds_{slug}_capability` filter.
- `Admin\LicenseSettings::render()` requires the capability before rendering, not only in the form handlers.
- `Support\Logger` respects the `logging` feature, still defaulting to `WP_DEBUG`.
- `Admin\Notices` receives the `LicenseSettings` instead of the resolved URL, so the settings-URL filter works after boot.
- `Config\Product` applies the filter and clamp on read, so TTL and grace period can be adjusted per site.
- New `mds_rollback_disabled` error code when the feature is switched off.

## [1.1.0] - 2026-08-07

### Added

- Bundle licenses: one key covers several products, with the additive `bundle` field on the validation response.
- `LicenseStatus::extra()` / `::get()`, exposing and persisting the validation fields the class does not model.

### Changed

- `Manager::validate()` stores the `message` returned by the server instead of discarding it.
- `Manager::activate()` sends `product_slug`, which the API requires for bundle keys and ignores for single-product ones.
- `Manager::deactivate()` sends `product_slug`, releasing only this product's binding so uninstalling one plugin does not deactivate the others.

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
