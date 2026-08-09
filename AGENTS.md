# AGENTS.md — mds-php-sdk

Working guide for the **MDS PHP SDK** — a WordPress PHP library that gives plugins/themes licensing, signed update checks, version rollback, and anti-piracy while talking to the MDS API. Read this before editing. For usage/consumption, see the [README.md](README.md).

## Stack

PHP 7.4+ · WordPress 5.8+ · `ext-sodium` (ed25519) · `ext-json` · PSR-4 (`MeuMouse\MDS\SDK\` → `src/`) · PHPUnit 9 + Brain Monkey · PHPStan level 5 (+ WordPress extension). **Zero runtime dependencies.**

## Commands

```bash
composer install
composer test        # phpunit — ALWAYS run before finishing
composer analyse     # phpstan analyse (level 5) — ALWAYS run before finishing
composer keygen      # generates an ed25519 key pair (bin/generate-keys.php)
```

There is no build step: it's a library distributed via Composer and/or embedded (drop-in).

## Architecture

Dual entry point:
- **`mds-sdk.php`** — a *class-free*, collision-safe loader. Multiple plugins may embed different copies of the SDK; no class is declared at include time. Each copy registers itself into `$GLOBALS['mds_sdk_registry']` and, on `plugins_loaded`, the **highest version** wins and fires `mds_sdk_loaded`.
- **`SDK::register($config)`** (`src/SDK.php`) — public facade; consumers call it on the `mds_sdk_loaded` hook. Creates one `Integration` per product (keyed by slug).

`src/` by responsibility:

```
SDK.php                    # static facade (register/get)
Integration.php            # one per product: builds services and registers WP hooks
Config/Product.php         # normalizes/validates the config array
Config/Features.php        # feature flags: presets, overrides, filters, constants
Api/Client.php             # transport over the WP HTTP API (auth, retry, envelope, verifies signature)
Api/ApiResponse.php · ApiException.php
Security/SignatureVerifier.php  # ed25519 verification (anti-piracy CORE)
License/Manager.php · LicenseStatus.php
Updates/  AbstractUpdater · PluginUpdater · ThemeUpdater · UpdateTransformer
Rollback/Manager.php
Cron/Scheduler.php         # schedules checks via WP-Cron
Admin/    LicenseSettings · RollbackPage · Notices · View  (+ templates/)
Support/  Cache (transients) · Environment · Logger
```

## Security rules (do not break — this is the core of the product)

- **Every license/update response MUST pass through `SignatureVerifier` (ed25519).** Never act on unsigned or tampered data. Do not add a path that consumes an API response while skipping verification — that defeats the anti-nulled protection.
- Do not weaken signature verification, do not make `public_key` optional, and do not add an "insecure if the key is missing" fallback.
- **No feature flag and no filter may reach the response path.** Flags (`Config\Features`) decide which modules boot; filters are outbound (`mds_{slug}_request_body`) or presentational. Never add a filter on an API response, on `$require_signature`, or on the verifier. Turning `license`/`license_gate_updates` off just omits `license_key` from the request — the server still decides entitlement, and the response is still verified.
- Package downloads are token-gated on the server; do not try to bypass that on the client.
- Private keys never live in the SDK/consumer — only the `public_key`. `bin/generate-keys.php` is the only source of key generation.

## WordPress / performance rules

- **No polling.** Update checks piggyback on WP's own update cron and are cached in transients (`update_check_ttl`, default 12h). License validation runs once a day via WP-Cron with per-site jitter. **Nothing** should run on a normal front-end request — do not add synchronous work at boot.
- Always use WP APIs: `wp_remote_*` (via `Api\Client`), transients (via `Support\Cache`), WP-Cron (via `Cron\Scheduler`), capabilities, nonces, i18n (`text_domain`). Do not use `curl`/`file_get_contents`/`$_SESSION` directly.
- Storage is multisite-aware; respect the existing behavior of `Support\Environment`.
- Any other failure (API down) falls back to the `grace_period` (default 14d) over the cached status — preserve that fallback.

## Code conventions

- **Style:** WordPress Coding Standards — **tab** indentation, `snake_case` for free functions/variables, `PascalCase` for classes, spaces inside parentheses `func( $x )`, Yoda conditions.
- **Mandatory guard** at the top of every `src/` file: `defined( 'ABSPATH' ) || exit;`.
- **PSR-4:** namespace = path. `MeuMouse\MDS\SDK\Foo\Bar` → `src/Foo/Bar.php`.
- Final classes with constructor injection, following the `Integration` pattern (it builds and injects each service). Do not add new singletons or global state beyond the loader registry.
- Complete docblocks (`@param`/`@return`) — PHPStan runs with WordPress stubs; keep the types accurate.
- Compatible with **PHP 7.4**: no `enum`, `readonly`, typed-property union types, or other 8.x features.

## Versioning (critical)

The SemVer version appears in **three places** and must stay in sync on every bump:
1. `mds-sdk.php` (`$mds_sdk_this_version`)
2. `src/SDK.php` (`const VERSION`)
3. `CHANGELOG.md`

(`composer.json` carries no `version` field — Composer resolves it from the git tag.)

The loader elects the embedded copy with the **highest** version — a wrong value here makes the correct copy lose the election. Update all four together and record the change in the CHANGELOG.

## Testing

- PHPUnit + Brain Monkey (mocks WP functions) + `php-stubs/wordpress-stubs`. Tests in `tests/`, namespace `MeuMouse\MDS\SDK\Tests\`.
- `phpunit.xml` has `failOnWarning="true"` — warnings break the suite; don't ignore them.
- Cover especially `SignatureVerifier`, `UpdateTransformer`, and `Environment` (they already have tests — follow the pattern). Run `composer test` and `composer analyse` before finishing.

## Do not

- Do not skip/weaken ed25519 signature verification.
- Do not add a runtime dependency (the library is zero-dep by design).
- Do not declare classes in `mds-sdk.php` or break the loader's election logic.
- Do not make network calls or heavy work outside the cron/transient flow.
- Do not use PHP 8.x features or break WPCS.
- Do not change the version in only one of the four places.
