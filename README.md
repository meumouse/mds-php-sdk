# MDS PHP SDK

WordPress PHP SDK for the **Modular Distribution Service (MDS)**. Drop it into any
plugin or theme to get **licensing**, **signed update checks**, **version
rollback** and **anti-piracy** — all talking to the MDS API in a controlled,
performance-friendly way.

- **No constant polling.** Update checks ride WordPress's own update cron and are
  cached in transients (default 12h). License validation runs once a day via
  WP-Cron with per-site jitter. Nothing happens on a normal front-end request.
- **Anti-piracy by design.** The API signs responses with ed25519; the SDK
  verifies every license/update response with an embedded public key and refuses
  to act on unsigned or tampered data. A "nulled" build cannot forge a valid
  license nor fetch a genuine update package (downloads are token-gated server-side).
- **WordPress best practices.** WP HTTP API, transients, WP-Cron, capabilities,
  nonces, i18n, multisite-aware storage, and a collision-safe loader.
- **Zero runtime dependencies.** Uses `ext-json` and `ext-sodium` (bundled with
  PHP 7.2+).

## Requirements

- PHP **7.4+**
- WordPress **5.8+**
- `ext-sodium`, `ext-json`

## Installation

```bash
composer require meumouse/mds-php-sdk
```

> **Embedding in a distributed plugin/theme:** prefix the namespace at build time
> with [Strauss](https://github.com/BrianHenryIE/strauss) (or Mozart) so multiple
> products can ship different SDK versions without class clashes. See
> [Namespace prefixing](#namespace-prefixing).

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

For a theme, set `'type' => 'theme'` and `'file' => get_stylesheet()` (the
stylesheet directory name).

### Configuration keys

| Key | Required | Default | Description |
|-----|----------|---------|-------------|
| `product_slug` | ✅ | — | MDS product slug. |
| `file` | ✅ | — | Plugin basename (`dir/file.php`) or theme stylesheet. |
| `current_version` | ✅ | — | Installed version (SemVer). |
| `api_base_url` | ✅ | — | MDS API base URL. |
| `api_key` | ✅ | — | Public per-product API key (scopes: `updates:check`, `licenses:activate`, `licenses:deactivate`). |
| `public_key` | ✅ | — | Base64 ed25519 public key used to verify signed responses. |
| `type` | — | `plugin` | `plugin` or `theme`. |
| `item_name` | — | slug | Display name. |
| `text_domain` | — | slug | Text domain for SDK strings. |
| `channel` | — | `stable` | `stable` or `beta`. |
| `settings_parent` | — | `null` | Parent menu slug for an auto License submenu. |
| `update_check_ttl` | — | `12h` | Update-check cache lifetime (seconds). |
| `grace_period` | — | `14d` | How long a cached "valid" status survives an API outage. |

## Rendering the UI yourself

The auto submenu is optional. To embed the panels in your own settings page:

```php
$integration = \MeuMouse\MDS\SDK\SDK::get( 'my-plugin' );

$integration->settings()->render();        // license activation panel
$integration->rollback_page()->render();   // available versions / rollback
```

Query state programmatically:

```php
if ( \MeuMouse\MDS\SDK\SDK::get( 'my-plugin' )->is_licensed() ) {
    // unlock premium behaviour
}
```

## How throttling works

| Concern | Cadence | Mechanism |
|---------|---------|-----------|
| Update check | ~12h (configurable) | Cached transient; refreshed only when WP builds its update transients. |
| License heartbeat | Daily (+ jitter) | WP-Cron event per product. |
| Version list (rollback) | ~12h | Cached transient; tokens minted only on an actual rollback. |
| Errors / no license | ≤1h | Short negative cache to prevent request storms. |

Admins can force a refresh with **Re-check now** (clears caches + revalidates).

## Anti-piracy model (honest)

- **Primary defence — signed responses.** The MDS API signs the exact response
  bytes with its ed25519 private key. The SDK recomputes `sha256(raw_body)`,
  checks a ±5min freshness window (anti-replay) and verifies the detached
  signature with the embedded public key. License/update calls **fail closed**
  when the signature is missing or invalid, so a fake/MITM update server cannot
  return a forged `valid: true`.
- **Token-gated downloads.** Update and rollback packages are served only via
  short-lived, single-use server tokens — a patched client cannot mint them.
- **Domain binding.** Activations bind to `home_url()` (network home on
  multisite), enforced both client- and server-side.
- **Defence in depth.** Namespace prefixing and storing the public key / API base
  as constants raise the bar, but client-side code can always be patched — that is
  exactly why the cryptographic signature (which cannot be forged) is the real
  protection, not obfuscation.

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
plugins via a shared, class-free registry, so even unprefixed copies won't fatal.

## Server keys

Generate a key pair and wire both sides:

```bash
php bin/generate-keys.php
```

Set `MDS_SIGNING_ENABLED`, `MDS_SIGNING_PRIVATE_KEY` and `MDS_SIGNING_PUBLIC_KEY`
in the `mds-api` environment, and embed the printed public key as each product's
`public_key`.

## Development

```bash
composer install
composer test       # PHPUnit
composer analyse    # PHPStan
```

## License

GPL-2.0-or-later.
