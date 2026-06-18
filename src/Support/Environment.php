<?php
/**
 * Environment helpers: domain, runtime versions, local/staging detection.
 *
 * @package MeuMouse\MDS\SDK
 */

namespace MeuMouse\MDS\SDK\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only view of the host environment, mirroring how the MDS API normalises
 * and classifies domains so the SDK reports values the server understands.
 */
final class Environment {

	/**
	 * The site domain bound to the license.
	 *
	 * On multisite the network home is used so all subsites share one seat.
	 * The value is normalised the same way the API does (lowercase, no scheme,
	 * no port/path, no "www.").
	 *
	 * @return string
	 */
	public static function domain() {
		$url = is_multisite() ? network_home_url() : home_url();

		return self::normalize_domain( $url );
	}

	/**
	 * Raw site URL reported as metadata (not used for seat binding).
	 *
	 * @return string
	 */
	public static function site_url() {
		return is_multisite() ? network_home_url() : home_url();
	}

	/**
	 * Normalise a URL/host the same way the MDS API does.
	 *
	 * Order: lowercase → strip scheme → strip path/query/fragment → strip port
	 * → strip leading "www.".
	 *
	 * @param string $url URL or bare host.
	 * @return string
	 */
	public static function normalize_domain( $url ) {
		$url = strtolower( trim( (string) $url ) );

		// Strip scheme.
		$url = preg_replace( '#^[a-z][a-z0-9+.\-]*://#', '', $url );

		// Strip path/query/fragment.
		$url = preg_replace( '#[/?\#].*$#', '', $url );

		// Strip credentials.
		$url = preg_replace( '#^[^@]*@#', '', $url );

		// Strip port.
		$url = preg_replace( '#:\d+$#', '', $url );

		// Strip leading www.
		$url = preg_replace( '#^www\.#', '', $url );

		return $url;
	}

	/** @return string */
	public static function wp_version() {
		global $wp_version;

		return (string) $wp_version;
	}

	/** @return string */
	public static function php_version() {
		return PHP_VERSION;
	}

	/**
	 * Best-effort environment classification.
	 *
	 * Note: the API is authoritative about whether a domain consumes a seat;
	 * this value is only sent as a hint/metadata.
	 *
	 * @return string One of "local", "staging" or "production".
	 */
	public static function type() {
		if ( function_exists( 'wp_get_environment_type' ) ) {
			$wp_type = wp_get_environment_type();

			if ( 'local' === $wp_type || 'development' === $wp_type ) {
				return 'local';
			}

			if ( 'staging' === $wp_type ) {
				return 'staging';
			}
		}

		$domain = self::domain();

		if ( self::looks_local( $domain ) ) {
			return 'local';
		}

		if ( false !== strpos( $domain, 'staging' ) || false !== strpos( $domain, 'stage.' ) ) {
			return 'staging';
		}

		return 'production';
	}

	/**
	 * Heuristic for local/dev hostnames.
	 *
	 * @param string $domain Normalised domain.
	 * @return bool
	 */
	private static function looks_local( $domain ) {
		static $suffixes = array( '.local', '.test', '.localhost', '.dev', '.example' );

		if ( 'localhost' === $domain || '127.0.0.1' === $domain || '::1' === $domain ) {
			return true;
		}

		foreach ( $suffixes as $suffix ) {
			if ( substr( $domain, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Common metadata bag attached to every API request.
	 *
	 * @param \MeuMouse\MDS\SDK\Config\Product $product Product context.
	 * @return array<string,string>
	 */
	public static function request_meta( $product ) {
		return array(
			'domain'         => self::domain(),
			'site_url'       => self::site_url(),
			'environment'    => self::type(),
			'wp_version'     => self::wp_version(),
			'php_version'    => self::php_version(),
			'plugin_version' => $product->current_version(),
		);
	}
}
