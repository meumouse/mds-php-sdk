<?php
/**
 * Per-product feature flags.
 *
 * @package MeuMouse\MDS\SDK
 */

namespace MeuMouse\MDS\SDK\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves which parts of the SDK a given product actually wants.
 *
 * A product may need the whole stack (licensing + updates + rollback + admin
 * UI), only licensing (it distributes updates through another channel), or only
 * updates (a free product served by MDS with no license at all). Each concern
 * is a named flag resolved through four layers, in order:
 *
 * 1. the preset chosen with `mode` ("full", "license_only", "updates_only");
 * 2. the explicit `features` map passed to {@see \MeuMouse\MDS\SDK\SDK::register()};
 * 3. the `mds_sdk_features` and `mds_{slug}_feature_{name}` filters;
 * 4. the `MDS_SDK_FEATURE_{NAME}` / `MDS_{SLUG}_FEATURE_{NAME}` constants, which
 *    are the site owner's final word and cannot be overridden by product code.
 *
 * Layers 1 and 2 are resolved once, at construction. Layers 3 and 4 are applied
 * on every read, so a *behavioural* flag (notices, update details, panels) can
 * still be flipped long after boot. *Structural* flags — the ones that decide
 * whether a WordPress hook gets registered at all, because registering costs an
 * API call or schedules a cron event — are only read during
 * {@see \MeuMouse\MDS\SDK\Integration::boot()}, which runs on `plugins_loaded`
 * at priority -100. Filtering those requires a plugin or mu-plugin; a theme's
 * `functions.php` is already too late, which is what the constants are for.
 */
final class Features {

	/* Structural — read once, at boot. */

	/** License module: activation, validation, stored key and status. */
	const LICENSE = 'license';

	/** Update injection into the core `update_plugins`/`update_themes` transients. */
	const UPDATES = 'updates';

	/** Version listing and downgrade. */
	const ROLLBACK = 'rollback';

	/** Daily license heartbeat via WP-Cron. */
	const HEARTBEAT = 'heartbeat';

	/** Auto-registered "License" submenu (also requires `settings_parent`). */
	const ADMIN_MENU = 'admin_menu';

	/* Behavioural — re-read on every use, so a late filter still applies. */

	/** Admin notices about license problems. */
	const NOTICES = 'notices';

	/** The "View details" modal served through `plugins_api`. */
	const UPDATE_DETAILS = 'update_details';

	/** Whether an active license is required before updates are offered. */
	const LICENSE_GATE_UPDATES = 'license_gate_updates';

	/** Rendering of the bundled license panel. */
	const ADMIN_LICENSE_PANEL = 'admin_license_panel';

	/** Rendering of the bundled rollback panel. */
	const ADMIN_ROLLBACK_PANEL = 'admin_rollback_panel';

	/** Debug log output. */
	const LOGGING = 'logging';

	/* Presets. */

	const MODE_FULL         = 'full';
	const MODE_LICENSE_ONLY = 'license_only';
	const MODE_UPDATES_ONLY = 'updates_only';

	/** @var string Product slug. */
	private $slug;

	/** @var string Hook/constant prefix derived from the slug, e.g. "mds_my_plugin". */
	private $prefix;

	/** @var string The resolved preset name. */
	private $mode;

	/** @var array<string,bool> Flags after layers 1 and 2. */
	private $resolved;

	/** @var array<int,array<string,string>> Requested flags whose dependency is off. */
	private $conflicts;

	/**
	 * @param string $slug   Product slug.
	 * @param array  $config Raw configuration passed to SDK::register().
	 */
	public function __construct( $slug, array $config = array() ) {
		$this->slug   = (string) $slug;
		$this->prefix = Product::prefix( $this->slug );

		$modes = self::modes();
		$mode  = isset( $config['mode'] ) ? (string) $config['mode'] : self::MODE_FULL;

		if ( ! isset( $modes[ $mode ] ) ) {
			$mode = self::MODE_FULL;
		}

		$this->mode = $mode;
		$features   = $modes[ $mode ];

		$overrides = isset( $config['features'] ) && is_array( $config['features'] ) ? $config['features'] : array();
		$features  = self::merge( $features, $overrides );

		/**
		 * Filter the resolved feature map for a product, before it boots.
		 *
		 * Unknown keys are ignored. This fires inside SDK::register(), i.e. on
		 * `plugins_loaded` priority -100 — hook it from a plugin or mu-plugin.
		 *
		 * @param array<string,bool> $features Resolved flags.
		 * @param string             $slug     Product slug.
		 * @param array              $config   Raw product configuration.
		 */
		$filtered = apply_filters( 'mds_sdk_features', $features, $this->slug, $config );

		if ( is_array( $filtered ) ) {
			$features = self::merge( $features, $filtered );
		}

		$this->resolved  = $features;
		$this->conflicts = self::detect_conflicts( $features );
	}

	/**
	 * Is a feature active right now?
	 *
	 * A feature is only active when its whole dependency chain is active too, so
	 * `heartbeat` can never run with `license` switched off.
	 *
	 * @param string $name One of the class constants.
	 * @return bool
	 */
	public function enabled( $name ) {
		return $this->resolve( (string) $name, array() );
	}

	/**
	 * Every flag with its effective value.
	 *
	 * @return array<string,bool>
	 */
	public function all() {
		$out = array();

		foreach ( array_keys( $this->resolved ) as $name ) {
			$out[ $name ] = $this->enabled( $name );
		}

		return $out;
	}

	/**
	 * Names of the currently active features.
	 *
	 * @return array<int,string>
	 */
	public function active() {
		return array_values( array_keys( array_filter( $this->all() ) ) );
	}

	/** @return string The resolved preset name. */
	public function mode() {
		return $this->mode;
	}

	/**
	 * Flags that were asked for while a feature they depend on is off. Reported
	 * once at boot by {@see \MeuMouse\MDS\SDK\Integration::boot()} so a
	 * misconfiguration is visible in the debug log instead of failing silently.
	 *
	 * @return array<int,array<string,string>> Rows of { feature, requires }.
	 */
	public function conflicts() {
		return $this->conflicts;
	}

	/* -------------------------------------------------------------------- */
	/* Internals                                                             */
	/* -------------------------------------------------------------------- */

	/**
	 * Resolve one flag through the filter, constant and dependency layers.
	 *
	 * @param string              $name Feature name.
	 * @param array<string,bool>  $seen Guard against a cyclic dependency map.
	 * @return bool
	 */
	private function resolve( $name, array $seen ) {
		if ( ! array_key_exists( $name, $this->resolved ) || isset( $seen[ $name ] ) ) {
			return false;
		}

		$seen[ $name ] = true;

		/**
		 * Filter a single feature flag.
		 *
		 * For behavioural flags this runs at the point of use, so it can be
		 * hooked at any time. For structural flags it only runs during boot.
		 *
		 * @param bool   $enabled Current value.
		 * @param string $slug    Product slug.
		 */
		$value = (bool) apply_filters( $this->prefix . '_feature_' . $name, $this->resolved[ $name ], $this->slug );

		// Constants win over everything: they are the site owner's kill switch,
		// and no product code can filter them back on.
		$value = self::constant_override( 'MDS_SDK_FEATURE_' . strtoupper( $name ), $value );
		$value = self::constant_override( strtoupper( $this->prefix ) . '_FEATURE_' . strtoupper( $name ), $value );

		if ( ! $value ) {
			return false;
		}

		$dependencies = self::dependencies();

		if ( isset( $dependencies[ $name ] ) ) {
			return $this->resolve( $dependencies[ $name ], $seen );
		}

		return true;
	}

	/**
	 * @param string $name    Constant name.
	 * @param bool   $current Value to keep when the constant is absent.
	 * @return bool
	 */
	private static function constant_override( $name, $current ) {
		return defined( $name ) ? (bool) constant( $name ) : $current;
	}

	/**
	 * Overlay a partial map onto a full one, ignoring unknown keys.
	 *
	 * @param array<string,bool>  $base      Full flag map.
	 * @param array<string,mixed> $overrides Partial map.
	 * @return array<string,bool>
	 */
	private static function merge( array $base, array $overrides ) {
		foreach ( $overrides as $name => $value ) {
			if ( array_key_exists( $name, $base ) ) {
				$base[ $name ] = (bool) $value;
			}
		}

		return $base;
	}

	/**
	 * Features that are meaningless without another one.
	 *
	 * @return array<string,string> Feature name => the feature it requires.
	 */
	private static function dependencies() {
		return array(
			self::HEARTBEAT            => self::LICENSE,
			self::LICENSE_GATE_UPDATES => self::LICENSE,
			self::NOTICES              => self::LICENSE,
			self::ADMIN_LICENSE_PANEL  => self::LICENSE,
			self::ADMIN_MENU           => self::ADMIN_LICENSE_PANEL,
			self::ROLLBACK             => self::LICENSE,
			self::ADMIN_ROLLBACK_PANEL => self::ROLLBACK,
			self::UPDATE_DETAILS       => self::UPDATES,
		);
	}

	/**
	 * @param array<string,bool> $features Flags after layers 1 and 2.
	 * @return array<int,array<string,string>>
	 */
	private static function detect_conflicts( array $features ) {
		$conflicts = array();

		foreach ( self::dependencies() as $name => $requires ) {
			if ( ! empty( $features[ $name ] ) && empty( $features[ $requires ] ) ) {
				$conflicts[] = array(
					'feature'  => $name,
					'requires' => $requires,
				);
			}
		}

		return $conflicts;
	}

	/**
	 * The preset table.
	 *
	 * @return array<string,array<string,bool>>
	 */
	private static function modes() {
		$debug = defined( 'WP_DEBUG' ) && WP_DEBUG;

		return array(
			// Everything on — the historical behaviour and the default.
			self::MODE_FULL => array(
				self::LICENSE              => true,
				self::UPDATES              => true,
				self::ROLLBACK             => true,
				self::HEARTBEAT            => true,
				self::ADMIN_MENU           => true,
				self::NOTICES              => true,
				self::UPDATE_DETAILS       => true,
				self::LICENSE_GATE_UPDATES => true,
				self::ADMIN_LICENSE_PANEL  => true,
				self::ADMIN_ROLLBACK_PANEL => true,
				self::LOGGING              => $debug,
			),
			// Entitlement only: the product ships updates through another channel.
			self::MODE_LICENSE_ONLY => array(
				self::LICENSE              => true,
				self::UPDATES              => false,
				self::ROLLBACK             => false,
				self::HEARTBEAT            => true,
				self::ADMIN_MENU           => true,
				self::NOTICES              => true,
				self::UPDATE_DETAILS       => false,
				self::LICENSE_GATE_UPDATES => true,
				self::ADMIN_LICENSE_PANEL  => true,
				self::ADMIN_ROLLBACK_PANEL => false,
				self::LOGGING              => $debug,
			),
			// Distribution only: a free product, no key, no licence UI.
			self::MODE_UPDATES_ONLY => array(
				self::LICENSE              => false,
				self::UPDATES              => true,
				self::ROLLBACK             => false,
				self::HEARTBEAT            => false,
				self::ADMIN_MENU           => false,
				self::NOTICES              => false,
				self::UPDATE_DETAILS       => true,
				self::LICENSE_GATE_UPDATES => false,
				self::ADMIN_LICENSE_PANEL  => false,
				self::ADMIN_ROLLBACK_PANEL => false,
				self::LOGGING              => $debug,
			),
		);
	}
}
