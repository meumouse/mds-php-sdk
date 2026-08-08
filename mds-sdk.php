<?php
/**
 * MDS PHP SDK — bootstrap & version-aware loader.
 *
 * This file is meant to be required by every plugin/theme that embeds the SDK:
 *
 *     require_once __DIR__ . '/vendor/meumouse/mds-php-sdk/mds-sdk.php';
 *
 *     add_action( 'mds_sdk_loaded', function () {
 *         \MeuMouse\MDS\SDK\SDK::register( array(
 *             'product_slug'    => 'my-plugin',
 *             'type'            => 'plugin',
 *             'file'            => 'my-plugin/my-plugin.php',
 *             'current_version' => '1.0.0',
 *             'api_base_url'    => 'https://api.meumouse.com',
 *             'api_key'         => 'mds_live_xxx',
 *             'public_key'      => 'BASE64_ED25519_PUBLIC_KEY',
 *             'item_name'       => 'My Plugin',
 *         ) );
 *     } );
 *
 * Multiple plugins may ship different versions of this SDK at once. To avoid
 * fatal "class already declared" errors and to make sure the newest code wins,
 * we do NOT declare any class at include time. Instead every copy registers its
 * (version, path) into a shared registry, and on `plugins_loaded` the single
 * highest version boots its own autoloader and fires `mds_sdk_loaded`.
 *
 * @package MeuMouse\MDS\SDK
 */

defined( 'ABSPATH' ) || exit;

// SemVer of THIS copy. Kept in sync with composer.json / CHANGELOG.md.
$mds_sdk_this_version = '1.1.0';
$mds_sdk_this_path = __DIR__;

// Shared, class-free registry of every embedded copy that has been required.
if ( ! isset( $GLOBALS['mds_sdk_registry'] ) || ! is_array( $GLOBALS['mds_sdk_registry'] ) ) {
	$GLOBALS['mds_sdk_registry'] = array();
}

$GLOBALS['mds_sdk_registry'][] = array(
	'version' => $mds_sdk_this_version,
	'path'    => $mds_sdk_this_path,
);

unset( $mds_sdk_this_version, $mds_sdk_this_path );

/**
 * Dispatcher: picks the highest registered version and boots it exactly once.
 *
 * Guarded by function_exists so only the first-loaded copy defines it; the body
 * is version-agnostic (it reads the shared registry), so it always elects the
 * newest path regardless of which copy happened to define the function.
 */
if ( ! function_exists( 'mds_sdk_boot' ) ) {

	/**
	 * Elect and boot the newest embedded SDK copy.
	 *
	 * @return void
	 */
	function mds_sdk_boot() {
		if ( ! empty( $GLOBALS['mds_sdk_booted'] ) ) {
			return;
		}

		$registry = isset( $GLOBALS['mds_sdk_registry'] ) ? $GLOBALS['mds_sdk_registry'] : array();

		if ( empty( $registry ) ) {
			return;
		}

		// Elect the highest SemVer; ties keep the first registered path.
		$winner = $registry[0];

		foreach ( $registry as $candidate ) {
			if ( version_compare( $candidate['version'], $winner['version'], '>' ) ) {
				$winner = $candidate;
			}
		}

		$GLOBALS['mds_sdk_booted']  = true;
		$GLOBALS['mds_sdk_winner']  = $winner;

		// Prefer Composer's autoloader when the winning copy was installed as a
		// dependency; otherwise fall back to a minimal PSR-4 autoloader so the
		// SDK also works as a plain drop-in.
		$composer = $winner['path'] . '/vendor/autoload.php';

		if ( is_readable( $composer ) ) {
			require_once $composer;
		}

		if ( ! class_exists( '\\MeuMouse\\MDS\\SDK\\SDK' ) ) {
			mds_sdk_register_autoloader( $winner['path'] . '/src' );
		}

		/**
		 * Fires once, after the newest SDK copy is loaded and ready.
		 *
		 * Consumers should call \MeuMouse\MDS\SDK\SDK::register() on this hook.
		 *
		 * @param array $winner The elected copy: { version, path }.
		 */
		do_action( 'mds_sdk_loaded', $winner );
	}

	/**
	 * Register a tiny PSR-4 autoloader for the SDK namespace.
	 *
	 * @param string $base_dir Absolute path to the SDK `src/` directory.
	 * @return void
	 */
	function mds_sdk_register_autoloader( $base_dir ) {
		$base_dir = rtrim( $base_dir, '/\\' ) . DIRECTORY_SEPARATOR;

		spl_autoload_register(
			static function ( $class ) use ( $base_dir ) {
				$prefix = 'MeuMouse\\MDS\\SDK\\';
				$len    = strlen( $prefix );

				if ( 0 !== strncmp( $prefix, $class, $len ) ) {
					return;
				}

				$relative = substr( $class, $len );
				$file     = $base_dir . str_replace( '\\', DIRECTORY_SEPARATOR, $relative ) . '.php';

				if ( is_readable( $file ) ) {
					require_once $file;
				}
			}
		);
	}

	// Boot as early as possible but after all plugins have registered their copy.
	if ( function_exists( 'add_action' ) ) {
		add_action( 'plugins_loaded', 'mds_sdk_boot', -100 );
	} else {
		// Theme/standalone context without the full plugin lifecycle.
		mds_sdk_boot();
	}
}
