# Changelog

All notable changes to `mds-php-sdk` are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/) and the project adheres to
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- `LicenseStatus::extra()` / `::get()` expose the fields the validate endpoint
  returns beyond the ones the class models (plan, renewal URL, support expiry,
  failure reason), so a product can render its own license screen without a
  second round-trip. Persisted with the status, so they survive a grace-period
  outage.

- Bundle licences: a single key can now cover several products (e.g. "Clube M").
  Nothing changes on the wire for a product — it keeps sending its own
  `product_slug` and the same key simply validates for every product the bundle
  grants. The validate response carries an additive `bundle` field
  (`id`, `name`, `slug`, `products[]`), reachable through
  `LicenseStatus::get( 'bundle' )`, so a plugin can show "Licensed via Clube M"
  and list what is included.

### Changed
- `Manager::validate()` now stores the server's `message` on the status instead
  of discarding it.
- `Manager::activate()` sends `product_slug` alongside the `plugin_version` that
  `Environment::request_meta()` already provided. The API **requires both when the
  key is a bundle licence** — its products share one seat, so the request has to
  say which product is activating — and keeps them optional for a single-product
  licence. Older API versions ignore the extra field.

- `Manager::deactivate()` sends `product_slug` too. Under a bundle licence this
  releases only this product's hold on the site — the seat stays with the other
  products until the last one leaves — so uninstalling one plugin no longer
  deactivates its siblings. The API requires the field for a bundle key.

  Consequence for consumers: a copy of the SDK older than this release cannot
  activate or deactivate a bundle licence; it will get a `400`. Single-product
  licences are unaffected.

## [1.0.0] - 2026-06-18

### Added
- Version-aware loader (`mds-sdk.php`) that boots the newest embedded copy and
  avoids class collisions across plugins.
- `SDK::register()` facade and per-product `Integration` container.
- License lifecycle: activate / deactivate / heartbeat-validate with a
  configurable grace period and multisite-aware option storage.
- ed25519 response signature verification (`SignatureVerifier`) — the anti-piracy
  core, rejecting unsigned/tampered/replayed responses for license-critical calls.
- Plugin and theme updaters with transient-throttled, cron-gated update checks.
- Version listing and rollback (downgrade) with capability + nonce protection.
- Daily license heartbeat via WP-Cron with per-site jitter.
- Reusable, override-friendly admin UI (license panel, rollback list, notices).
- PHPUnit test suite and CI for PHP 7.4–8.3.
