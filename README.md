# MDS PHP SDK

WordPress PHP SDK for the **Modular Distribution Service (MDS)**. Drop it into any
plugin or theme to get licensing, signed update checks, version rollback and
anti-piracy, all talking to the MDS API in a controlled, performance-friendly way.

- **No constant polling.** Update checks ride WordPress's own update cron and are
  cached in transients (default 12h). License validation runs once a day via
  WP-Cron with per-site jitter. Nothing happens on a normal front-end request.
- **Anti-piracy by design.** The API signs responses with ed25519; the SDK
  verifies every license/update response with an embedded public key and refuses
  to act on unsigned or tampered data. A "nulled" build cannot forge a valid
  license nor fetch a genuine update package (downloads are token-gated
  server-side).
- **WordPress best practices.** WP HTTP API, transients, WP-Cron, capabilities,
  nonces, i18n, multisite-aware storage, and a collision-safe loader.
- **Zero runtime dependencies.** Uses `ext-json` and `ext-sodium` (bundled with
  PHP 7.2+).

## Table of contents

- [Feature overview](#feature-overview)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick start](#quick-start)
- [Configuration reference](#configuration-reference)
- [Feature flags](#feature-flags)
- [Licensing](#licensing)
- [Bundle licenses](#bundle-licenses)
- [Updates](#updates)
- [Rollback](#rollback)
- [Admin UI](#admin-ui)
- [Scheduling and throttling](#scheduling-and-throttling)
- [Public API reference](#public-api-reference)
- [Hooks reference](#hooks-reference)
- [Stored data reference](#stored-data-reference)
- [API endpoints used](#api-endpoints-used)
- [Security and anti-piracy model](#security-and-anti-piracy-model)
- [Multisite behaviour](#multisite-behaviour)
- [Namespace prefixing](#namespace-prefixing)
- [Server keys](#server-keys)
- [Debugging](#debugging)
- [Development](#development)
- [Versioning](#versioning)
- [License](#license)

## Feature overview

| Feature | What it does | Where |
|---------|--------------|-------|
| Version-aware loader | Multiple plugins can embed different SDK copies; the highest version boots and the others stand down, with no class collisions. | `mds-sdk.php` |
| Product registration | One `Integration` container per product, built and hooked in a single call. | `SDK::register()` |
| Feature flags | Every module — licensing, updates, rollback, notices, admin UI — can be switched off per product, by filter or by constant. | `Config\Features` |
| License activation | Activates a key against the current domain, fail-closed (no signed "yes", no valid status). | `License\Manager::activate()` |
| License deactivation | Releases this site's activation and clears local state, best-effort. | `License\Manager::deactivate()` |
| Daily heartbeat | Re-validates the license once a day through WP-Cron, with per-site jitter. | `Cron\Scheduler` |
| Grace period | Keeps the last valid status alive while the API is unreachable (default 14 days). | `License\Manager` |
| Extended license payload | Plan, renewal URL, support expiry, refusal reason and any other server field, kept for the product to render. | `License\LicenseStatus::extra()` / `::get()` |
| Bundle licenses | One key can cover several products; the response says which bundle granted it. | `LicenseStatus::get( 'bundle' )` |
| Plugin updates | Injects the update into the core `update_plugins` transient and powers the "View details" modal. | `Updates\PluginUpdater` |
| Theme updates | Injects the update into the core `update_themes` transient. | `Updates\ThemeUpdater` |
| Update throttling | Cached in a transient (default 12h) plus a short negative cache on errors. | `Updates\AbstractUpdater` |
| Version listing | Lists published versions available to the license, without minting download tokens. | `Rollback\Manager::list_versions()` |
| Rollback | Reinstalls a chosen older version using a single-use, token-gated package URL. | `Rollback\Manager::rollback()` |
| Signature verification | ed25519 verification of the exact response bytes, with an anti-replay freshness window. | `Security\SignatureVerifier` |
| API client | WP HTTP API transport with API-key auth, one retry, envelope normalisation and error codes. | `Api\Client` |
| Admin license panel | Ready-made activate / deactivate / re-check screen, embeddable or auto-registered as a submenu. | `Admin\LicenseSettings` |
| Admin rollback page | Version table with a rollback action behind capability and nonce checks. | `Admin\RollbackPage` |
| Admin notices | Actionable warning when the license is missing, invalid or expired. | `Admin\Notices` |
| Template overrides | Both admin templates can be replaced with branded markup through a filter. | `Admin\View` |
| Multisite-aware storage | Site options and site transients on multisite; network home URL as the licensed domain. | `Support\Environment`, `Support\Cache` |
| Environment reporting | Sends domain, site URL, environment type, WP and PHP versions with every request. | `Support\Environment::request_meta()` |
| Debug logging | Structured, step-based log lines, silent unless `WP_DEBUG` is on. | `Support\Logger` |

## Requirements

- PHP **7.4+**
- WordPress **5.8+**
- `ext-sodium`, `ext-json`

## Installation

```bash
composer require meumouse/mds-php-sdk
```

The SDK also works as a plain drop-in: copy the directory into your product and
require `mds-sdk.php`. When Composer's autoloader is not present, the loader
registers a minimal PSR-4 autoloader for the `MeuMouse\MDS\SDK\` namespace.

When shipping inside a distributed plugin or theme, prefix the namespace at build
time with [Strauss](https://github.com/BrianHenryIE/strauss) (or Mozart) so
multiple products can carry different SDK versions safely. See
[Namespace prefixing](#namespace-prefixing).

## Quick start

```php
require_once __DIR__ . '/vendor/meumouse/mds-php-sdk/mds-sdk.php';

add_action( 'mds_sdk_loaded', function () {
    \MeuMouse\MDS\SDK\SDK::register( array(
        'product_slug'    => 'my-plugin',           // matches the MDS product slug
        'type'            => 'plugin',              // 'plugin' | 'theme'
        'file'            => plugin_basename( __FILE__ ),
        'current_version' => '1.0.0',
        'api_base_url'    => 'https://api.meumouse.com',
        'api_key'         => 'mds_live_xxx',        // public, low-privilege product key
        'public_key'      => 'BASE64_ED25519_PUBLIC_KEY',
        'item_name'       => 'My Plugin',
        'settings_parent' => 'options-general.php', // optional auto License submenu
    ) );
} );
```

Always register on the `mds_sdk_loaded` action: it fires once, on
`plugins_loaded` (priority -100), after the newest embedded SDK copy has been
elected. Registering earlier would bind your product to a copy that may lose the
election.

For a theme, set `'type' => 'theme'` and `'file' => get_stylesheet()` (the
stylesheet directory name).

Clean up the scheduled heartbeat when the product is deactivated:

```php
register_deactivation_hook( __FILE__, function () {
    $integration = \MeuMouse\MDS\SDK\SDK::get( 'my-plugin' );

    if ( $integration ) {
        $integration->shutdown();
    }
} );
```

A complete reference integration lives in
[`examples/example-plugin`](examples/example-plugin/example-plugin.php).

## Configuration reference

| Key | Required | Default | Description |
|-----|----------|---------|-------------|
| `product_slug` | Yes | — | MDS product slug. Also the prefix for every option, transient and hook name. |
| `file` | Yes | — | Plugin basename (`dir/file.php`) or theme stylesheet. |
| `current_version` | Yes | — | Installed version (SemVer). |
| `api_base_url` | Yes | — | MDS API base URL. A trailing slash is stripped. |
| `api_key` | Yes | — | Public per-product API key (scopes: `updates:check`, `licenses:activate`, `licenses:deactivate`). |
| `public_key` | Yes | — | Base64 ed25519 public key used to verify signed responses. |
| `type` | No | `plugin` | `plugin` or `theme`. Any other value falls back to `plugin`. |
| `item_name` | No | slug | Display name used across the admin UI. |
| `text_domain` | No | slug | Text domain reported by the product context. |
| `channel` | No | `stable` | `stable` or `beta`. Sent when listing versions. |
| `settings_parent` | No | `null` | Parent menu slug for an auto License submenu. `null` registers no menu. |
| `update_check_ttl` | No | `12 * HOUR_IN_SECONDS` | Update-check and version-list cache lifetime, in seconds. Clamped to a minimum of one hour. |
| `grace_period` | No | `14 * DAY_IN_SECONDS` | How long a cached "valid" status survives an API outage. |
| `mode` | No | `full` | Feature preset: `full`, `license_only` or `updates_only`. An unknown value falls back to `full`. |
| `features` | No | `array()` | Partial map of feature flags overriding the preset. Unknown keys are ignored. |

A missing or empty required key throws `InvalidArgumentException` at
registration time.

## Feature flags

Not every product needs the whole stack. A product may want licensing but ship
its updates elsewhere; a free product may want updates with no license at all;
a product with its own settings screen may want everything except the built-in
notices. Each concern is a named flag.

```php
SDK::register( array(
    // …required keys…
    'mode'     => 'updates_only',        // preset
    'features' => array(                 // per-flag override on top of it
        'update_details' => false,
    ),
) );
```

### Presets

| Flag | `full` (default) | `license_only` | `updates_only` | Gates |
|------|:---:|:---:|:---:|-------|
| `license` | ✅ | ✅ | ❌ | The whole license module: stored key, activation, validation. |
| `updates` | ✅ | ❌ | ✅ | Injection into the core update transients. |
| `rollback` | ✅ | ❌ | ❌ | Version listing and downgrade. |
| `heartbeat` | ✅ | ✅ | ❌ | The daily WP-Cron license validation. |
| `admin_menu` | ✅ | ✅ | ❌ | The auto-registered "License" submenu. |
| `notices` | ✅ | ✅ | ❌ | Admin notices about license problems. |
| `update_details` | ✅ | ❌ | ✅ | The "View details" modal (`plugins_api`). |
| `license_gate_updates` | ✅ | — | ❌ | Whether an active license is required before an update is offered. |
| `admin_license_panel` | ✅ | ✅ | ❌ | Rendering of the bundled license panel. |
| `admin_rollback_panel` | ✅ | ✅ | ❌ | Rendering of the bundled rollback panel. |
| `logging` | `WP_DEBUG` | `WP_DEBUG` | `WP_DEBUG` | Debug log output. |

Registering without `mode` or `features` selects `full`, which behaves exactly
as the SDK did before flags existed.

### Dependencies

A flag is only active when everything it depends on is active too, so a
contradictory configuration degrades safely instead of half-working:

```
heartbeat, notices, rollback, license_gate_updates, admin_license_panel  →  license
admin_menu  →  admin_license_panel  →  license
admin_rollback_panel  →  rollback
update_details  →  updates
```

Asking for a flag whose dependency is off logs a `features.conflict` warning at
boot rather than failing silently.

### Resolution order

```
preset (mode)  →  features array  →  filters  →  constants
```

```php
// All products, once, before boot.
add_filter( 'mds_sdk_features', function ( array $features, $slug ) {
    if ( 'my-plugin' === $slug ) {
        $features['notices'] = false;
    }

    return $features;
}, 10, 2 );

// One flag of one product.
add_filter( 'mds_my_plugin_feature_rollback', '__return_false' );
```

```php
// wp-config.php — the site owner's kill switch. Constants are the final word:
// no filter can turn them back on.
define( 'MDS_MY_PLUGIN_FEATURE_NOTICES', false );  // one product
define( 'MDS_SDK_FEATURE_LOGGING', true );         // every product
```

The constant name is the hook prefix uppercased: slug `my-plugin` →
`mds_my_plugin` → `MDS_MY_PLUGIN_FEATURE_{FLAG}`.

### When your filter runs matters

`SDK::register()` runs on `mds_sdk_loaded`, which fires at `plugins_loaded`
priority **-100**. Flags split into two kinds by whether they must be known
that early:

| Kind | Flags | Read | Filterable from |
|------|-------|------|-----------------|
| **Structural** — registering the hook costs an API call or schedules a cron event | `license`, `updates`, `rollback`, `heartbeat`, `admin_menu` | once, at boot | a plugin or mu-plugin (top-level `add_filter`), or a constant |
| **Behavioural** — registering is free, so the decision is deferred | `notices`, `update_details`, `license_gate_updates`, `admin_license_panel`, `admin_rollback_panel`, `logging` | at the point of use | anywhere, including a theme's `functions.php` |

A theme is too late for a structural flag: `functions.php` loads on
`after_setup_theme`, well after the SDK has booted. Use a constant instead.

### Reading the resolved state

```php
$features = \MeuMouse\MDS\SDK\SDK::get( 'my-plugin' )->features();

$features->enabled( 'rollback' );  // bool, dependencies included
$features->mode();                 // "full" | "license_only" | "updates_only"
$features->active();               // names of the currently active flags
$features->conflicts();            // rows of { feature, requires }
```

### Products without a license

With `license` off the module is inert — no option is read or written, no
request is made — and the SDK reports the product as unlocked so your own gating
keeps working:

```php
$integration->is_licensed();          // true
$integration->license()->is_enabled();// false
$integration->license()->status()->status();      // "not_required"
$integration->license()->status()->is_required(); // false
```

`license_gate_updates => false` means *this product does not require a license*.
It does **not** relax any security check: the update-check response is still
verified with ed25519, and the request simply omits `license_key` so the API can
tell a free product from a missing key. The API must be configured to serve
unlicensed checks for that product, or the server will reject them.

## Licensing

`License\Manager` owns the stored key and its last verified status.

```php
$license = \MeuMouse\MDS\SDK\SDK::get( 'my-plugin' )->license();

$license->activate( 'MDS-XXXX-XXXX' );  // returns LicenseStatus, throws ApiException on transport failure
$license->deactivate();                 // releases this site and clears local state
$license->validate();                   // heartbeat: re-checks against the server
$license->status();                     // last persisted LicenseStatus, no network call
$license->is_active();                  // effective validity (honours expiry)
$license->has_key();
$license->get_key();
$license->is_enabled();                 // false when the `license` feature is off
```

With the `license` feature off every operation above becomes inert: no option is
read or written, no request is made, `status()` reports `not_required` and
`is_active()` returns true. See [Feature flags](#feature-flags).

**Activation** is fail-closed. The key is persisted only after a signed success
response; the SDK then runs one `validate()` call to pull the full status
(expiry, plan and everything else the server returns). A server rejection stores
an `invalid` status with the server's message; a transport failure throws
`ApiException` so the caller can show a "try again" message instead of marking
the license bad.

**Deactivation** is best-effort: even when the server call fails, local state is
cleared so an administrator can re-enter a key.

**Validation** is the heartbeat, run daily by the scheduler and on demand from
the admin panel. Failure handling splits in two:

- *Server rejection* (expired, forbidden, not found): the status becomes
  `expired` or `invalid` immediately.
- *Transport failure* (DNS, timeout, TLS): the last valid status survives while
  the grace period has not elapsed since the last successful check. After that,
  the status degrades to `unknown` and updates stop being offered.

**Status values** are `active`, `inactive`, `expired`, `invalid`, `unknown` and
`not_required` (the product runs without licensing). `LicenseStatus` also
exposes the whole server payload:

```php
$status = $license->status();

$status->is_valid();        // the server's last verdict
$status->status();          // one of the constants above
$status->expires_at();      // ISO-8601 string, or null for lifetime
$status->is_expired();
$status->is_signed();       // whether the last verdict came from a verified response
$status->is_required();     // false only when the `license` feature is off
$status->domain();
$status->checked_at();
$status->last_success_at();
$status->message();         // server-supplied message
$status->extra();           // every field beyond the ones modelled above
$status->get( 'plan' );     // single extra field, with an optional default
$status->to_array();        // serialisable state
```

`extra()` and `get()` exist so a product can render its own license screen (plan
name, renewal URL, support expiry, refusal reason) without a second round-trip.
The extras persist with the status, so the screen still renders during a
grace-period outage.

Gating premium behaviour:

```php
if ( \MeuMouse\MDS\SDK\SDK::get( 'my-plugin' )->is_licensed() ) {
    // unlock premium behaviour
}
```

## Bundle licenses

A single key can cover several products. Nothing changes on the wire for a
product: it keeps sending its own `product_slug`, and the same key simply
validates for every product the bundle grants. The validate response carries an
additive `bundle` field:

```php
$bundle = $license->status()->get( 'bundle' );

if ( is_array( $bundle ) ) {
    // $bundle = array( 'id' => …, 'name' => 'Clube M', 'slug' => …, 'products' => array( … ) )
    printf( 'Licensed via %s', esc_html( $bundle['name'] ) );
}
```

A seat is a *site*, so every product of a bundle shares one activation.
Deactivating releases only this product's hold on the site; the seat stays with
the remaining products until the last one leaves, so uninstalling one plugin
does not deactivate its siblings.

## Updates

The updater registers itself in any context where WordPress builds its update
transients, so a check costs an API call at most once per `update_check_ttl` and
never on a front-end request.

For plugins (`Updates\PluginUpdater`):

- `pre_set_site_transient_update_plugins` injects the update into
  `$transient->response`, or advertises `no_update` so core stops asking
  wordpress.org about the product.
- `plugins_api` (priority 20) serves the "View details" modal, including a
  changelog rendered to safe HTML, banners, `requires`, `tested` and
  `requires_php`.
- `upgrader_process_complete` clears the cached payload right after this plugin
  is updated.

For themes (`Updates\ThemeUpdater`) the same applies through
`pre_set_site_transient_update_themes` (no details modal, which core does not
offer for themes).

Updates are a licensed benefit by default: when `is_active()` is false the check
is skipped entirely and a short negative result is cached. Set
`license_gate_updates => false` (or use the `updates_only` preset) for a product
distributed free of charge — see [Feature flags](#feature-flags). Errors are
also cached briefly — `min( 1 hour, update_check_ttl )` — to prevent request
storms.

`Updates\UpdateTransformer` maps the API payload to the shapes core expects and
is pure (no side effects), which makes it straightforward to test against
fixtures.

## Rollback

```php
$rollback = \MeuMouse\MDS\SDK\SDK::get( 'my-plugin' )->rollback();

$versions = $rollback->list_versions();        // cached; pass true to force a refresh
$result   = $rollback->rollback( '1.4.2' );    // true|WP_Error
$rollback->clear_cache();
```

Listing never mints download tokens. A token is requested only at the moment of
an actual rollback, and the resulting `/v2/download?token=…` URL is single-use
and server-gated. The install path uses core's `Plugin_Upgrader` /
`Theme_Upgrader` with `overwrite_package`, and a plugin that was active before
the rollback is reactivated afterwards.

`Rollback\Manager::rollback()` performs no capability or nonce check — that is
the caller's responsibility. `Admin\RollbackPage` already does both; if you
build your own UI, replicate them.

Error codes returned as `WP_Error`: `mds_rollback_disabled`,
`mds_rollback_invalid`, `mds_rollback_unlicensed`, `mds_rollback_api`,
`mds_rollback_no_package`, `mds_rollback_not_found`, `mds_rollback_failed`.

## Admin UI

Three admin pieces are registered automatically when `is_admin()` is true, each
subject to its feature flag: the license settings handlers, the rollback action
handler, and the notices.

**Auto submenu.** Set `settings_parent` and a "License" submenu is added under
that parent, requiring `manage_options` (or whatever
`mds_{slug}_capability` returns for the `settings` context).

**Render the panels yourself.** The submenu is optional; both panels can be
embedded in your own settings screen:

```php
$integration = \MeuMouse\MDS\SDK\SDK::get( 'my-plugin' );

$integration->settings()->render();        // license activation panel
$integration->rollback_page()->render();   // available versions / rollback
```

The license panel handles three `admin-post.php` actions — activate, deactivate
and re-check — each guarded by `manage_options` plus a nonce, and each followed
by a redirect back with a one-minute notice transient. "Re-check now" clears the
update and version caches and re-validates the license.

The rollback page requires `update_plugins` (or `update_themes` for a theme) and
renders nothing for a user without it.

**Notices.** When the license is missing, invalid or expired, a warning notice
with a link to the license screen is shown to `manage_options` users on the
Dashboard, Plugins and Updates screens only. Turn it off with the `notices`
flag, or reshape it without turning it off:

```php
// Show it on your own settings screen too.
add_filter( 'mds_my_plugin_notice_screens', function ( array $screens ) {
    $screens[] = 'settings_page_my-plugin';

    return $screens;
} );

// Reword it, and make it dismissible.
add_filter( 'mds_my_plugin_notice_message', fn() => 'Ative sua licença para receber atualizações.' );
add_filter( 'mds_my_plugin_notice_args', function ( array $args ) {
    $args['dismissible'] = true;

    return $args;
} );
```

`mds_{slug}_should_show_notice` receives the decision, the `LicenseStatus` and
the current screen id, and has the final say in both directions.

**Template overrides.** Both templates live in `templates/` and can be replaced
through the `mds_sdk_template` filter:

```php
add_filter( 'mds_sdk_template', function ( $path, $name, $product ) {
    if ( 'license-settings' === $name && 'my-plugin' === $product->slug() ) {
        return plugin_dir_path( __FILE__ ) . 'templates/my-license-panel.php';
    }

    return $path;
}, 10, 3 );
```

An unreadable override silently falls back to the bundled template. The
variables each template receives are documented in its docblock:
[`license-settings.php`](templates/license-settings.php),
[`rollback-page.php`](templates/rollback-page.php).

## Scheduling and throttling

| Concern | Cadence | Mechanism |
|---------|---------|-----------|
| Update check | ~12h (configurable) | Cached transient; refreshed only when WP builds its update transients. |
| License heartbeat | Daily, first run offset by 0–6h of random jitter | WP-Cron event per product. |
| Version list (rollback) | ~12h | Cached transient; tokens minted only on an actual rollback. |
| Errors / no license | Up to 1h | Short negative cache to prevent request storms. |

The scheduler self-heals: if the event is missing on an admin or cron load, it is
rescheduled. Administrators can force a refresh with **Re-check now**, which
clears both caches and revalidates.

```php
$scheduler = \MeuMouse\MDS\SDK\SDK::get( 'my-plugin' )->scheduler();

$scheduler->hook();        // the per-product cron hook name
$scheduler->schedule();
$scheduler->unschedule();  // also called by Integration::shutdown()
$scheduler->run();         // run the heartbeat now
$scheduler->recurrence();  // the filtered cron schedule, "daily" by default
$scheduler->is_enabled();  // whether the heartbeat feature is on
```

Turning the `heartbeat` flag off also removes an event scheduled while it was
on, so the cron table does not keep an orphan.

## Public API reference

### `SDK` (facade)

| Member | Description |
|--------|-------------|
| `SDK::VERSION` | SemVer of the elected copy. |
| `SDK::register( array $config ): ?Integration` | Registers and boots a product. Calling it twice for the same slug returns the existing integration. |
| `SDK::get( string $slug ): ?Integration` | Retrieves a registered integration. |
| `SDK::all(): array` | Every registered integration, keyed by slug. |

### `Integration`

| Member | Description |
|--------|-------------|
| `boot()` | Registers every WordPress hook. Called by `SDK::register()`. |
| `shutdown()` | Removes the scheduled heartbeat. Call it from your deactivation hook. |
| `product(): Product` | The immutable configuration object. |
| `features(): Config\Features` | Resolved feature flags. |
| `license(): License\Manager` | License lifecycle. |
| `rollback(): Rollback\Manager` | Version listing and downgrade. |
| `scheduler(): Cron\Scheduler` | Heartbeat scheduling. |
| `settings(): Admin\LicenseSettings` | License panel and its actions. |
| `rollback_page(): Admin\RollbackPage` | Rollback panel and its action. |
| `notices(): Admin\Notices` | The license notice. |
| `is_licensed(): bool` | Shortcut for `license()->is_active()`. True when the `license` feature is off. |

### `Config\Product`

Read-only accessors for every configuration value: `slug()`, `type()`,
`is_plugin()`, `is_theme()`, `file()`, `current_version()`, `api_base_url()`,
`api_key()`, `public_key()`, `item_name()`, `text_domain()`, `channel()`,
`settings_parent()`, `update_check_ttl()`, `grace_period()`, `features()` and
`capability( $context )`, plus `key( $suffix )`, which builds the
`mds_{slug}_{suffix}` prefix used for every option, transient and hook name, and
its static counterpart `Product::prefix( $slug )`.

### `Config\Features`

`enabled( $name )`, `all()`, `active()`, `mode()` and `conflicts()`. Flag names
are exposed as class constants (`Features::UPDATES`, `Features::NOTICES`, …).
See [Feature flags](#feature-flags).

### `Api\Client`

`post( $path, array $body, $require_signature = true )` and
`get( $path, array $query = array(), $require_signature = true )`, both returning
`ApiResponse` and throwing `ApiException`. The client sends `X-Api-Key` and a
descriptive `User-Agent` (`MDS-SDK/{version}; {slug}/{version}; WordPress/…;
PHP/…`), uses a 10-second timeout, retries once on transport failure, requires
TLS verification and normalises the `{ success, data }` / `{ success, error }`
envelope.

`ApiResponse` exposes `data( $key = null, $default = null )`, `is_signed()` and
`status()`. `ApiException` exposes `error_code()`, `status()` and
`is_transport()` — the last one is what separates "server said no" from "could
not reach the server", and therefore whether the grace period applies.

### `Security\SignatureVerifier`

`is_supported()` reports whether libsodium's detached-signature verification is
available; `verify( $raw_body, array $headers, $now = null )` returns true only
for a present, fresh and valid signature. See
[Security and anti-piracy model](#security-and-anti-piracy-model).

### `Support\Environment`

`domain()`, `site_url()`, `normalize_domain( $url )`, `wp_version()`,
`php_version()`, `type()` (`local`, `staging` or `production`) and
`request_meta( $product )`, the metadata bag attached to every request.

### `Support\Cache`

`get( $name )`, `set( $name, $value, $ttl )` and `delete( $name )`, scoped per
product and multisite-aware.

### `Support\Logger`

`step( $step, array $context = array(), $level = 'debug' )`, `warning()` and
`error()`. See [Debugging](#debugging).

## Hooks reference

### Actions

| Hook | Arguments | Fired when |
|------|-----------|------------|
| `mds_sdk_loaded` | `array $winner` — the elected copy (`version`, `path`) | Once, on `plugins_loaded` (priority -100), after the newest embedded copy boots. Register products here. |
| `mds_{slug}_before_boot` | `Features $features`, `Product $product` | Before a product registers its hooks. |
| `mds_{slug}_booted` | `Integration $integration` | After a product has registered its hooks. |
| `mds_sdk_registered_{slug}` | `Integration $integration` | After a product has been fully wired. |
| `mds_{slug}_heartbeat` | — | The per-product daily cron event. |
| `admin_post_mds_{slug}_activate` | — | License activation form submit. |
| `admin_post_mds_{slug}_deactivate` | — | License deactivation form submit. |
| `admin_post_mds_{slug}_check` | — | "Re-check now" form submit. |
| `admin_post_mds_{slug}_rollback` | — | Rollback form submit. |

In every name above, `{slug}` is the product slug with non-alphanumeric
characters replaced by underscores.

### Filters

| Hook | Arguments | Purpose |
|------|-----------|---------|
| `mds_sdk_template` | `string $path`, `string $name`, `Product $product` | Override the admin template path. |
| `mds_sdk_features` | `array $features`, `string $slug`, `array $config` | Adjust the resolved feature map of any product, before it boots. |
| `mds_{slug}_feature_{name}` | `bool $enabled`, `string $slug` | Toggle one feature. |
| `mds_{slug}_notice_screens` | `array $screens`, `Product $product` | Screen ids the license notice may appear on. |
| `mds_{slug}_notice_message` | `string $message`, `LicenseStatus $status`, `Product $product` | Reword the notice. |
| `mds_{slug}_notice_args` | `array $args`, `LicenseStatus $status` | `type`, `dismissible`, `link_text`, `link_url`. |
| `mds_{slug}_should_show_notice` | `bool $show`, `LicenseStatus $status`, `string $screen` | Final say on rendering the notice, in both directions. |
| `mds_{slug}_capability` | `string $capability`, `string $context`, `Product $product` | Capability for the `settings`, `notices` or `rollback` context. |
| `mds_{slug}_settings_url` | `string $url`, `Product $product` | Where notices and links point — set it when embedding the panel in your own screen. |
| `mds_{slug}_update_check_ttl` | `int $ttl`, `Product $product` | Update-check cache lifetime. Still clamped to one hour minimum. |
| `mds_{slug}_grace_period` | `int $seconds`, `Product $product` | License grace window. |
| `mds_{slug}_heartbeat_recurrence` | `string $recurrence`, `Product $product` | Cron schedule for the heartbeat. Falls back to `daily` if unregistered. |
| `mds_{slug}_request_body` | `array $body`, `string $path`, `Product $product` | Outbound request payload. |

There is deliberately **no** filter on an API response, on `require_signature`
or on the verifier, and no feature flag can switch signature verification off.
Every hook above is outbound or presentational.

### WordPress hooks the SDK registers

`pre_set_site_transient_update_plugins`, `plugins_api`,
`pre_set_site_transient_update_themes`, `upgrader_process_complete`,
`admin_menu` (only when `settings_parent` is set), `admin_notices`,
`plugins_loaded`, and the `admin_post_*` and cron hooks listed above.

## Stored data reference

Every name is prefixed with `mds_{slug}`. On multisite, options become site
options and transients become site transients.

| Name | Kind | Contents |
|------|------|----------|
| `mds_{slug}_license_key` | Option | The license key, stored only after a signed activation. Autoload off. |
| `mds_{slug}_license_state` | Option | Serialised `LicenseStatus`, including the extra server fields. |
| `mds_{slug}_c_{hash}` | Transient | Update-check payload and version list. TTL is `update_check_ttl`. |
| `mds_{slug}_notice_{user_id}` | Transient | One-shot admin notice after a form action. TTL 60s. |
| `mds_{slug}_heartbeat` | Cron event | Daily license validation. |

`deactivate()` deletes the key and the state option. `Integration::shutdown()`
removes the cron event.

## API endpoints used

| Endpoint | Method | Signature required | Used by |
|----------|--------|--------------------|---------|
| `/v2/licenses/activate` | POST | Yes | `License\Manager::activate()` |
| `/v2/licenses/deactivate` | POST | No | `License\Manager::deactivate()` |
| `/v2/updates/validate` | POST | Yes | `License\Manager::validate()` |
| `/v2/update-check` | POST | Yes | `Updates\AbstractUpdater` |
| `/v2/updates/versions` | POST | Yes | `Rollback\Manager` |
| `/v2/download?token=…` | GET | Server-gated | Rollback package install |

Every request carries `domain`, `site_url`, `environment`, `wp_version`,
`php_version` and `plugin_version`, plus the endpoint's own fields.

## Security and anti-piracy model

- **Primary defence: signed responses.** The API signs the exact response bytes
  with its ed25519 private key. The signed message is
  `"{timestamp}.{nonce}.{sha256_hex(raw_body)}"`. The SDK recomputes the digest
  over the raw body (before JSON decoding, so there is no cross-language
  serialisation ambiguity), rebuilds the canonical message and verifies the
  detached signature with the embedded public key. License and update calls fail
  closed when the signature is missing or invalid, so a fake or MITM update
  server cannot return a forged `valid: true`.
- **Anti-replay.** Signatures older or newer than 300 seconds (`MAX_SKEW`) are
  rejected. The server signs in milliseconds; the SDK compares in seconds.
- **Token-gated downloads.** Update and rollback packages are served only via
  short-lived, single-use server tokens; a patched client cannot mint them.
- **Domain binding.** Activations bind to `home_url()` (network home on
  multisite), normalised the same way the API normalises it, and enforced on both
  sides.
- **Least-privilege key in the product.** Only the public key and a
  low-privilege API key are embedded. Private keys never live in the SDK or the
  consumer.
- **Feature flags are not a bypass.** Switching `license` or
  `license_gate_updates` off — by config, filter or constant — only changes what
  *this site* asks for. The update-check then goes out without a `license_key`,
  and the API refuses to serve a paid product on that request. Turning the flag
  off in `wp-config.php` therefore grants nothing: the entitlement decision is
  the server's, exactly as it is for a patched client. No flag and no filter can
  reach signature verification, `require_signature`, or the response itself.
- **Defence in depth.** Namespace prefixing and storing the public key and API
  base as constants raise the bar, but client-side code can always be patched —
  which is exactly why the cryptographic signature, which cannot be forged, is
  the real protection rather than obfuscation.

## Multisite behaviour

- The licensed domain is `network_home_url()`, so the whole network shares one
  seat.
- License key and state are stored as site options.
- Caches use site transients, so one network-wide entry serves every subsite.

## Namespace prefixing

When shipping inside a distributed product, prefix `MeuMouse\MDS\SDK` so two
plugins can carry different SDK versions safely. Example Strauss config in your
plugin's `composer.json`:

```json
{
  "extra": {
    "strauss": {
      "target_directory": "vendor-prefixed",
      "namespace_prefix": "MyVendor\\Vendor\\",
      "packages": [ "meumouse/mds-php-sdk" ]
    }
  }
}
```

The bundled `mds-sdk.php` loader still elects the newest embedded copy across all
plugins via a shared, class-free registry, so even unprefixed copies will not
fatal.

## Server keys

Generate a key pair and wire both sides:

```bash
php bin/generate-keys.php
```

Set `MDS_SIGNING_ENABLED`, `MDS_SIGNING_PRIVATE_KEY` and
`MDS_SIGNING_PUBLIC_KEY` in the `mds-api` environment, and embed the printed
public key as each product's `public_key`.

## Debugging

`Support\Logger` writes one line per step through `error_log()`, and only when
`WP_DEBUG` is enabled — it is silent in production:

```
[MDS-SDK][my-plugin][WARNING] license.grace {"remaining":1123200}
```

Step identifiers include `integration.booted` (which reports the resolved mode
and active flags), `features.conflict`, `api.ok`, `api.error`, `api.transport`,
`api.unsigned`, `license.validate`, `license.rejected`, `license.grace`,
`license.grace_expired`, `license.deactivate`, `license.disabled`,
`cron.scheduled`, `cron.heartbeat`, `update.checked`, `update.error`,
`update.skip_unlicensed`, `rollback.start`, `rollback.done` and
`rollback.list_error`.

Logging follows the `logging` flag, which defaults to `WP_DEBUG`. Force it on
for one site with `define( 'MDS_MY_PLUGIN_FEATURE_LOGGING', true );`.

## Development

```bash
composer install
composer test       # PHPUnit (Brain Monkey + WordPress stubs)
composer analyse    # PHPStan level 5 with the WordPress extension
composer keygen     # ed25519 key pair
```

There is no build step. Working rules for contributors, including the security
constraints that must not be weakened, live in [AGENTS.md](AGENTS.md).

## Versioning

The SDK follows SemVer. The version appears in `mds-sdk.php`, `src/SDK.php`
(`SDK::VERSION`) and [CHANGELOG.md](CHANGELOG.md), and all of them must be bumped
together: the loader elects the copy with the highest declared version, so a
stale value makes the correct copy lose the election.

## License

GPL-2.0-or-later.
