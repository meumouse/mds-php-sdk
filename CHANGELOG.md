# Changelog

All notable changes to `mds-php-sdk` are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/) and the project adheres to
[Semantic Versioning](https://semver.org/).

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
