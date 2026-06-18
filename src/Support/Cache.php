<?php
/**
 * Transient-backed cache, scoped per product.
 *
 * @package MeuMouse\MDS\SDK
 */

namespace MeuMouse\MDS\SDK\Support;

use MeuMouse\MDS\SDK\Config\Product;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper over the (site) transient API.
 *
 * On multisite we use site transients so the whole network shares one cache
 * entry, matching the network-level license binding. Object caches are honoured
 * automatically by the underlying transient functions.
 */
final class Cache {

	/** @var Product */
	private $product;

	/**
	 * @param Product $product Product context.
	 */
	public function __construct( Product $product ) {
		$this->product = $product;
	}

	/**
	 * Build a namespaced, length-safe transient key.
	 *
	 * @param string $name Logical name, e.g. "update" or "versions".
	 * @return string
	 */
	private function key( $name ) {
		// Transient keys are capped at 172 chars; hash to stay safe.
		return $this->product->key( 'c_' . substr( md5( $name ), 0, 12 ) );
	}

	/**
	 * @param string $name Logical name.
	 * @return mixed|false Stored value, or false when missing/expired.
	 */
	public function get( $name ) {
		$key = $this->key( $name );

		return is_multisite() ? get_site_transient( $key ) : get_transient( $key );
	}

	/**
	 * @param string $name  Logical name.
	 * @param mixed  $value Value to store.
	 * @param int    $ttl   Lifetime in seconds.
	 * @return void
	 */
	public function set( $name, $value, $ttl ) {
		$key = $this->key( $name );
		$ttl = max( 0, (int) $ttl );

		if ( is_multisite() ) {
			set_site_transient( $key, $value, $ttl );
		} else {
			set_transient( $key, $value, $ttl );
		}
	}

	/**
	 * @param string $name Logical name.
	 * @return void
	 */
	public function delete( $name ) {
		$key = $this->key( $name );

		if ( is_multisite() ) {
			delete_site_transient( $key );
		} else {
			delete_transient( $key );
		}
	}
}
