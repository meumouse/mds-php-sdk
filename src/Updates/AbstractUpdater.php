<?php
/**
 * Shared update-check logic for plugins and themes.
 *
 * @package MeuMouse\MDS\SDK
 */

namespace MeuMouse\MDS\SDK\Updates;

use MeuMouse\MDS\SDK\Api\ApiException;
use MeuMouse\MDS\SDK\Api\Client;
use MeuMouse\MDS\SDK\Config\Features;
use MeuMouse\MDS\SDK\Config\Product;
use MeuMouse\MDS\SDK\License\Manager as LicenseManager;
use MeuMouse\MDS\SDK\Support\Cache;
use MeuMouse\MDS\SDK\Support\Environment;
use MeuMouse\MDS\SDK\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Holds the cached, throttled update-check call. Update info is fetched at most
 * once per cache TTL (default 12h) and only when WordPress is already running
 * its own update routine — never on a regular front-end request.
 */
abstract class AbstractUpdater {

	const CACHE_UPDATE = 'update';

	/** @var Product */
	protected $product;

	/** @var Client */
	protected $client;

	/** @var LicenseManager */
	protected $license;

	/** @var Cache */
	protected $cache;

	/** @var Logger */
	protected $logger;

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
	 * Register the WordPress hooks for this updater type.
	 *
	 * @return void
	 */
	abstract public function register();

	/**
	 * Fetch (and cache) the update-check payload.
	 *
	 * @param bool $force Bypass the cache.
	 * @return array|null The `data` payload, or null when unavailable/ungated.
	 */
	protected function get_update_data( $force = false ) {
		if ( ! $force ) {
			$cached = $this->cache->get( self::CACHE_UPDATE );

			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		// Updates are a licensed benefit by default: never offer them without a
		// valid license. A product distributed free of charge turns the gate off
		// (`license_gate_updates`), and the server decides whether it will serve
		// an unlicensed check — the response is still signature-verified.
		if ( $this->license_gated() && ! $this->license->is_active() ) {
			$this->logger->step( 'update.skip_unlicensed', array() );
			$this->cache->set( self::CACHE_UPDATE, array( 'update_available' => false ), $this->negative_ttl() );

			return null;
		}

		$body = array(
			'product_slug'    => $this->product->slug(),
			'current_version' => $this->product->current_version(),
		);

		$key = $this->license->get_key();

		// Omitted entirely rather than sent empty when the product has no
		// licensing, so the API can tell "free product" from "missing key".
		if ( '' !== $key ) {
			$body['license_key'] = $key;
		}

		$body = array_merge( $body, Environment::request_meta( $this->product ) );

		try {
			$response = $this->client->post( '/v2/update-check', $body, true );
		} catch ( ApiException $e ) {
			$this->logger->warning( 'update.error', array( 'code' => $e->error_code() ) );

			// Cache a short negative result to avoid hammering on errors.
			$this->cache->set( self::CACHE_UPDATE, array( 'update_available' => false ), $this->negative_ttl() );

			return null;
		}

		$data = $response->data();

		$this->cache->set( self::CACHE_UPDATE, $data, $this->product->update_check_ttl() );
		$this->logger->step( 'update.checked', array( 'available' => ! empty( $data['update_available'] ) ) );

		return $data;
	}

	/**
	 * Whether the payload advertises a newer version than installed.
	 *
	 * @param array|null $data Update payload.
	 * @return bool
	 */
	protected function has_update( $data ) {
		if ( ! is_array( $data ) || empty( $data['update_available'] ) ) {
			return false;
		}

		$latest = isset( $data['latest_version'] ) ? (string) $data['latest_version'] : '';

		return '' !== $latest && version_compare( $latest, $this->product->current_version(), '>' );
	}

	/**
	 * Whether an active license is required before an update is offered.
	 *
	 * @return bool
	 */
	protected function license_gated() {
		return $this->product->features()->enabled( Features::LICENSE_GATE_UPDATES );
	}

	/**
	 * Drop the cached update payload (used by "Check now" and after upgrades).
	 *
	 * @return void
	 */
	public function clear_cache() {
		$this->cache->delete( self::CACHE_UPDATE );
	}

	/**
	 * Short TTL used for negative/error results to avoid request storms.
	 *
	 * @return int
	 */
	private function negative_ttl() {
		return min( HOUR_IN_SECONDS, $this->product->update_check_ttl() );
	}
}
