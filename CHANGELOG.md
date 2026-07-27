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

### Changed
- `Manager::validate()` now stores the server's `message` on the status instead
  of discarding it.

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
