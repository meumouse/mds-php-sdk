<?php
/**
 * Public facade for registering products with the MDS SDK.
 *
 * @package MeuMouse\MDS\SDK
 */

namespace MeuMouse\MDS\SDK;

use MeuMouse\MDS\SDK\Config\Product;

defined( 'ABSPATH' ) || exit;

/**
 * Entry point. Consumers call SDK::register() on the `mds_sdk_loaded` hook to
 * wire a plugin/theme into licensing, updates and rollback.
 */
final class SDK {

	/** SemVer of the SDK; keep in sync with composer.json, mds-sdk.php, CHANGELOG. */
	const VERSION = '1.1.0';

	/** @var array<string,Integration> Registered integrations, keyed by slug. */
	private static $integrations = array();

	/**
	 * Register a product. Safe to call once per product.
	 *
	 * Required config keys: product_slug, file, current_version, api_base_url,
	 * api_key, public_key. Optional: type ("plugin"|"theme"), item_name,
	 * text_domain, channel, settings_parent, update_check_ttl, grace_period.
	 *
	 * @param array $config Product configuration.
	 * @return Integration|null The integration, or null if already registered.
	 */
	public static function register( array $config ) {
		$product = new Product( $config );
		$slug    = $product->slug();

		if ( isset( self::$integrations[ $slug ] ) ) {
			return self::$integrations[ $slug ];
		}

		$integration = new Integration( $product );
		$integration->boot();

		self::$integrations[ $slug ] = $integration;

		/**
		 * Fires after a product has been fully wired.
		 *
		 * @param Integration $integration The integration instance.
		 */
		do_action( 'mds_sdk_registered_' . $slug, $integration );

		return $integration;
	}

	/**
	 * Retrieve a previously registered integration.
	 *
	 * @param string $slug Product slug.
	 * @return Integration|null
	 */
	public static function get( $slug ) {
		return isset( self::$integrations[ $slug ] ) ? self::$integrations[ $slug ] : null;
	}

	/**
	 * All registered integrations.
	 *
	 * @return array<string,Integration>
	 */
	public static function all() {
		return self::$integrations;
	}
}
