<?php
/**
 * Plugin Name: MDS SDK Example
 * Description: Reference integration of the MDS PHP SDK (license, updates, rollback).
 * Version: 1.0.0
 * Author: MeuMouse
 * Requires PHP: 7.4
 * Requires at least: 5.8
 * Text Domain: mds-example
 *
 * @package MeuMouse\MDS\SDK\Example
 */

defined( 'ABSPATH' ) || exit;

// 1) Load the SDK bootstrap (installed via Composer in your real plugin).
require_once __DIR__ . '/vendor/meumouse/mds-php-sdk/mds-sdk.php';

// 2) Register the product once the newest embedded SDK has booted.
add_action(
	'mds_sdk_loaded',
	static function () {
		$integration = \MeuMouse\MDS\SDK\SDK::register(
			array(
				'product_slug'    => 'mds-example',
				'type'            => 'plugin',
				'file'            => plugin_basename( __FILE__ ),
				'current_version' => '1.0.0',
				'api_base_url'    => 'https://api.meumouse.com',
				'api_key'         => 'mds_live_PUBLIC_PRODUCT_KEY',
				'public_key'      => 'BASE64_ED25519_PUBLIC_KEY',
				'item_name'       => 'MDS SDK Example',
				'settings_parent' => 'options-general.php', // auto License submenu under Settings.
			)
		);

		// Optional: render the rollback list on your own settings tab.
		add_action(
			'admin_menu',
			static function () use ( $integration ) {
				add_management_page(
					'MDS Example Versions',
					'MDS Example Versions',
					'update_plugins',
					'mds-example-versions',
					array( $integration->rollback_page(), 'render' )
				);
			}
		);
	}
);

// 3) Clean up scheduled events on deactivation.
register_deactivation_hook(
	__FILE__,
	static function () {
		$integration = \MeuMouse\MDS\SDK\SDK::get( 'mds-example' );

		if ( $integration ) {
			$integration->shutdown();
		}
	}
);
