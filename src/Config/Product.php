<?php
/**
 * Immutable per-product configuration.
 *
 * @package MeuMouse\MDS\SDK
 */

namespace MeuMouse\MDS\SDK\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Value object describing a single plugin/theme integration.
 *
 * One instance is created per product via SDK::register(). It is read-only:
 * everything the SDK needs about a product is resolved up front and never mutated.
 */
final class Product {

	const TYPE_PLUGIN = 'plugin';
	const TYPE_THEME  = 'theme';

	/** @var string Public product slug, matches the MDS product `slug`. */
	private $slug;

	/** @var string Either {@see TYPE_PLUGIN} or {@see TYPE_THEME}. */
	private $type;

	/** @var string Plugin basename ("dir/file.php") or theme stylesheet ("my-theme"). */
	private $file;

	/** @var string Currently installed version (SemVer). */
	private $current_version;

	/** @var string MDS API base URL, no trailing slash. */
	private $api_base_url;

	/** @var string Public, low-privilege API key embedded in the product. */
	private $api_key;

	/** @var string Base64-encoded ed25519 public key used to verify signed responses. */
	private $public_key;

	/** @var string Human-readable product name (shown in admin UI / details modal). */
	private $item_name;

	/** @var string Text domain used for SDK strings (defaults to slug). */
	private $text_domain;

	/** @var string Release channel: "stable" (default) or "beta". */
	private $channel;

	/** @var string|null Parent admin menu slug to anchor the license panel under. */
	private $settings_parent;

	/** @var int Update-check cache TTL, in seconds, before filtering/clamping. */
	private $update_check_ttl;

	/** @var int License-status grace window, in seconds, when the API is unreachable. */
	private $grace_period;

	/** @var Features Which parts of the SDK this product uses. */
	private $features;

	/**
	 * @param array $args Raw configuration. See SDK::register() for the keys.
	 *
	 * @throws \InvalidArgumentException When a required field is missing or invalid.
	 */
	public function __construct( array $args ) {
		$args = array_merge(
			array(
				'type'             => self::TYPE_PLUGIN,
				'channel'          => 'stable',
				'text_domain'      => '',
				'item_name'        => '',
				'settings_parent'  => null,
				'update_check_ttl' => 12 * HOUR_IN_SECONDS,
				'grace_period'     => 14 * DAY_IN_SECONDS,
				'mode'             => Features::MODE_FULL,
				'features'         => array(),
			),
			$args
		);

		$this->slug = $this->require_string( $args, 'product_slug' );

		$this->type = self::TYPE_THEME === $args['type'] ? self::TYPE_THEME : self::TYPE_PLUGIN;

		$this->file            = $this->require_string( $args, 'file' );
		$this->current_version = $this->require_string( $args, 'current_version' );
		$this->api_base_url    = untrailingslashit( $this->require_string( $args, 'api_base_url' ) );
		$this->api_key         = $this->require_string( $args, 'api_key' );
		$this->public_key      = $this->require_string( $args, 'public_key' );

		$this->item_name       = '' !== $args['item_name'] ? (string) $args['item_name'] : $this->slug;
		$this->text_domain     = '' !== $args['text_domain'] ? (string) $args['text_domain'] : $this->slug;
		$this->channel         = 'beta' === $args['channel'] ? 'beta' : 'stable';
		$this->settings_parent = null !== $args['settings_parent'] ? (string) $args['settings_parent'] : null;

		// Stored raw: both are filterable, so clamping happens on read.
		$this->update_check_ttl = (int) $args['update_check_ttl'];
		$this->grace_period     = (int) $args['grace_period'];

		$this->features = new Features( $this->slug, $args );
	}

	/**
	 * Pull a required, non-empty string from the args array.
	 *
	 * @param array  $args Configuration.
	 * @param string $key  Key to read.
	 * @return string
	 *
	 * @throws \InvalidArgumentException When missing/empty.
	 */
	private function require_string( array $args, $key ) {
		if ( empty( $args[ $key ] ) || ! is_string( $args[ $key ] ) ) {
			throw new \InvalidArgumentException(
				sprintf( 'MDS SDK: missing required configuration key "%s".', $key )
			);
		}

		return $args[ $key ];
	}

	/** @return string */
	public function slug() {
		return $this->slug;
	}

	/** @return string */
	public function type() {
		return $this->type;
	}

	/** @return bool */
	public function is_theme() {
		return self::TYPE_THEME === $this->type;
	}

	/** @return bool */
	public function is_plugin() {
		return self::TYPE_PLUGIN === $this->type;
	}

	/** @return string */
	public function file() {
		return $this->file;
	}

	/** @return string */
	public function current_version() {
		return $this->current_version;
	}

	/** @return string */
	public function api_base_url() {
		return $this->api_base_url;
	}

	/** @return string */
	public function api_key() {
		return $this->api_key;
	}

	/** @return string */
	public function public_key() {
		return $this->public_key;
	}

	/** @return string */
	public function item_name() {
		return $this->item_name;
	}

	/** @return string */
	public function text_domain() {
		return $this->text_domain;
	}

	/** @return string */
	public function channel() {
		return $this->channel;
	}

	/** @return string|null */
	public function settings_parent() {
		return $this->settings_parent;
	}

	/**
	 * Update-check and version-list cache lifetime.
	 *
	 * @return int Seconds, never below one hour.
	 */
	public function update_check_ttl() {
		/**
		 * Filter the update-check cache TTL for this product.
		 *
		 * @param int     $ttl     Configured TTL in seconds.
		 * @param Product $product Product context.
		 */
		$ttl = (int) apply_filters( $this->key( 'update_check_ttl' ), $this->update_check_ttl, $this );

		return max( HOUR_IN_SECONDS, $ttl );
	}

	/**
	 * How long a cached "valid" status survives an API outage.
	 *
	 * @return int Seconds.
	 */
	public function grace_period() {
		/**
		 * Filter the license grace period for this product.
		 *
		 * @param int     $seconds Configured grace window.
		 * @param Product $product Product context.
		 */
		$grace = (int) apply_filters( $this->key( 'grace_period' ), $this->grace_period, $this );

		return max( 0, $grace );
	}

	/** @return Features */
	public function features() {
		return $this->features;
	}

	/**
	 * Capability required for an administrative context.
	 *
	 * @param string $context One of "settings", "notices" or "rollback".
	 * @return string
	 */
	public function capability( $context ) {
		$context = (string) $context;

		if ( 'rollback' === $context ) {
			$default = $this->is_theme() ? 'update_themes' : 'update_plugins';
		} else {
			$default = 'manage_options';
		}

		/**
		 * Filter the capability required by an SDK admin surface.
		 *
		 * @param string  $capability Default capability.
		 * @param string  $context    "settings", "notices" or "rollback".
		 * @param Product $product    Product context.
		 */
		$capability = apply_filters( $this->key( 'capability' ), $default, $context, $this );

		return '' !== (string) $capability ? (string) $capability : $default;
	}

	/**
	 * A stable, slug-derived prefix for option/transient/hook names.
	 *
	 * @param string $slug Product slug.
	 * @return string
	 */
	public static function prefix( $slug ) {
		return 'mds_' . preg_replace( '/[^a-z0-9_]/', '_', strtolower( (string) $slug ) );
	}

	/**
	 * A stable, slug-derived prefix for option/transient/hook names.
	 *
	 * @param string $suffix Optional suffix appended after the prefix.
	 * @return string
	 */
	public function key( $suffix = '' ) {
		$base = self::prefix( $this->slug );

		return '' === $suffix ? $base : $base . '_' . $suffix;
	}
}
