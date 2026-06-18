<?php
/**
 * Version listing and rollback (downgrade) installs.
 *
 * @package MeuMouse\MDS\SDK
 */

namespace MeuMouse\MDS\SDK\Rollback;

use MeuMouse\MDS\SDK\Api\ApiException;
use MeuMouse\MDS\SDK\Api\Client;
use MeuMouse\MDS\SDK\Config\Product;
use MeuMouse\MDS\SDK\License\Manager as LicenseManager;
use MeuMouse\MDS\SDK\Support\Cache;
use MeuMouse\MDS\SDK\Support\Environment;
use MeuMouse\MDS\SDK\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Lists published versions available to the license and reinstalls a chosen
 * (older) version. The version list is cached; download tokens are minted only
 * at the moment of an actual rollback, never during listing.
 */
final class Manager {

	const CACHE_VERSIONS = 'versions';

	/** @var Product */
	private $product;

	/** @var Client */
	private $client;

	/** @var LicenseManager */
	private $license;

	/** @var Cache */
	private $cache;

	/** @var Logger */
	private $logger;

	/**
	 * @param Product        $product Product context.
	 * @param Client         $client  API client.
	 * @param LicenseManager $license License manager.
	 * @param Cache          $cache   Cache.
	 * @param Logger         $logger  Logger.
	 */
	public function __construct( Product $product, Client $client, LicenseManager $license, Cache $cache, Logger $logger ) {
		$this->product = $product;
		$this->client  = $client;
		$this->license = $license;
		$this->cache   = $cache;
		$this->logger  = $logger;
	}

	/**
	 * List published versions available for rollback.
	 *
	 * @param bool $force Bypass cache.
	 * @return array<int,array<string,mixed>> Version rows (without download tokens).
	 */
	public function list_versions( $force = false ) {
		if ( ! $force ) {
			$cached = $this->cache->get( self::CACHE_VERSIONS );

			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		if ( ! $this->license->is_active() ) {
			return array();
		}

		try {
			$response = $this->request_versions( false );
		} catch ( ApiException $e ) {
			$this->logger->warning( 'rollback.list_error', array( 'code' => $e->error_code() ) );

			return array();
		}

		$versions = $response->data( 'versions', array() );
		$versions = is_array( $versions ) ? array_values( $versions ) : array();

		$this->cache->set( self::CACHE_VERSIONS, $versions, $this->product->update_check_ttl() );

		return $versions;
	}

	/**
	 * Reinstall a specific version (downgrade/rollback).
	 *
	 * The caller is responsible for capability and nonce checks.
	 *
	 * @param string $version Target version string.
	 * @return true|\WP_Error
	 */
	public function rollback( $version ) {
		$version = trim( (string) $version );

		if ( '' === $version ) {
			return new \WP_Error( 'mds_rollback_invalid', __( 'No version specified.', 'mds-sdk' ) );
		}

		if ( ! $this->license->is_active() ) {
			return new \WP_Error( 'mds_rollback_unlicensed', __( 'An active license is required to roll back.', 'mds-sdk' ) );
		}

		$package = $this->package_url_for( $version );

		if ( is_wp_error( $package ) ) {
			return $package;
		}

		$this->logger->step( 'rollback.start', array( 'version' => $version ) );

		return $this->product->is_theme()
			? $this->install_theme( $package )
			: $this->install_plugin( $package );
	}

	/* -------------------------------------------------------------------- */
	/* Internals                                                             */
	/* -------------------------------------------------------------------- */

	/**
	 * Resolve a single-use, token-gated package URL for a version.
	 *
	 * @param string $version Target version.
	 * @return string|\WP_Error
	 */
	private function package_url_for( $version ) {
		try {
			$response = $this->request_versions( true );
		} catch ( ApiException $e ) {
			return new \WP_Error( 'mds_rollback_api', $e->getMessage() );
		}

		$versions = (array) $response->data( 'versions', array() );

		foreach ( $versions as $row ) {
			if ( isset( $row['version'] ) && (string) $row['version'] === $version ) {
				if ( empty( $row['download_token'] ) ) {
					return new \WP_Error( 'mds_rollback_no_package', __( 'No downloadable package for this version.', 'mds-sdk' ) );
				}

				return add_query_arg(
					'token',
					rawurlencode( (string) $row['download_token'] ),
					$this->product->api_base_url() . '/v2/download'
				);
			}
		}

		return new \WP_Error( 'mds_rollback_not_found', __( 'Requested version is not available.', 'mds-sdk' ) );
	}

	/**
	 * Call the rollback versions endpoint.
	 *
	 * @param bool $with_tokens Whether to request per-version download tokens.
	 * @return \MeuMouse\MDS\SDK\Api\ApiResponse
	 *
	 * @throws ApiException
	 */
	private function request_versions( $with_tokens ) {
		$body = array_merge(
			array(
				'license_key'             => $this->license->get_key(),
				'product_slug'            => $this->product->slug(),
				'channel'                 => $this->product->channel(),
				'include_download_tokens' => (bool) $with_tokens,
			),
			Environment::request_meta( $this->product )
		);

		return $this->client->post( '/v2/updates/versions', $body, true );
	}

	/**
	 * Install a plugin package, preserving its active state.
	 *
	 * @param string $package Package URL.
	 * @return true|\WP_Error
	 */
	private function install_plugin( $package ) {
		$this->load_upgrader();

		$file        = $this->product->file();
		$was_active  = is_plugin_active( $file );
		$skin        = new \WP_Ajax_Upgrader_Skin();
		$upgrader    = new \Plugin_Upgrader( $skin );

		$result = $upgrader->install( $package, array( 'overwrite_package' => true ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( false === $result || $skin->get_errors()->has_errors() ) {
			return new \WP_Error( 'mds_rollback_failed', __( 'Rollback installation failed.', 'mds-sdk' ) );
		}

		if ( $was_active ) {
			activate_plugin( $file );
		}

		$this->logger->step( 'rollback.done', array( 'type' => 'plugin' ) );

		return true;
	}

	/**
	 * Install a theme package.
	 *
	 * @param string $package Package URL.
	 * @return true|\WP_Error
	 */
	private function install_theme( $package ) {
		$this->load_upgrader();

		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new \Theme_Upgrader( $skin );

		$result = $upgrader->install( $package, array( 'overwrite_package' => true ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( false === $result || $skin->get_errors()->has_errors() ) {
			return new \WP_Error( 'mds_rollback_failed', __( 'Rollback installation failed.', 'mds-sdk' ) );
		}

		$this->logger->step( 'rollback.done', array( 'type' => 'theme' ) );

		return true;
	}

	/**
	 * Lazy-load the WordPress upgrader API.
	 *
	 * @return void
	 */
	private function load_upgrader() {
		if ( ! function_exists( 'request_filesystem_credentials' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! class_exists( '\\Plugin_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}

	/**
	 * Drop the cached version list.
	 *
	 * @return void
	 */
	public function clear_cache() {
		$this->cache->delete( self::CACHE_VERSIONS );
	}
}
