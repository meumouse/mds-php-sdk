<?php
/**
 * Per-product service container and hook wiring.
 *
 * @package MeuMouse\MDS\SDK
 */

namespace MeuMouse\MDS\SDK;

use MeuMouse\MDS\SDK\Admin\LicenseSettings;
use MeuMouse\MDS\SDK\Admin\Notices;
use MeuMouse\MDS\SDK\Admin\RollbackPage;
use MeuMouse\MDS\SDK\Admin\View;
use MeuMouse\MDS\SDK\Api\Client;
use MeuMouse\MDS\SDK\Config\Features;
use MeuMouse\MDS\SDK\Config\Product;
use MeuMouse\MDS\SDK\Cron\Scheduler;
use MeuMouse\MDS\SDK\License\Manager as LicenseManager;
use MeuMouse\MDS\SDK\Rollback\Manager as RollbackManager;
use MeuMouse\MDS\SDK\Security\SignatureVerifier;
use MeuMouse\MDS\SDK\Support\Cache;
use MeuMouse\MDS\SDK\Support\Logger;
use MeuMouse\MDS\SDK\Updates\AbstractUpdater;
use MeuMouse\MDS\SDK\Updates\PluginUpdater;
use MeuMouse\MDS\SDK\Updates\ThemeUpdater;

defined( 'ABSPATH' ) || exit;

/**
 * Builds every service for one product and registers its WordPress hooks. One
 * Integration instance exists per registered product, owned by {@see SDK}.
 */
final class Integration {

	/** @var Product */
	private $product;

	/** @var Features */
	private $features;

	/** @var Logger */
	private $logger;

	/** @var Client */
	private $client;

	/** @var LicenseManager */
	private $license;

	/** @var AbstractUpdater */
	private $updater;

	/** @var RollbackManager */
	private $rollback;

	/** @var Scheduler */
	private $scheduler;

	/** @var LicenseSettings */
	private $settings;

	/** @var RollbackPage */
	private $rollback_page;

	/** @var Notices */
	private $notices;

	/**
	 * @param Product $product Product configuration.
	 */
	public function __construct( Product $product ) {
		$this->product  = $product;
		$this->features = $product->features();

		$this->logger    = new Logger( $product->slug(), $this->features );
		$cache           = new Cache( $product );
		$verifier        = new SignatureVerifier( $product->public_key() );
		$this->client    = new Client( $product, $verifier, $this->logger );
		$this->license   = new LicenseManager( $product, $this->client, $this->logger );
		$this->rollback  = new RollbackManager( $product, $this->client, $this->license, $cache, $this->logger );
		$this->scheduler = new Scheduler( $product, $this->license, $this->logger );

		$this->updater = $product->is_theme()
			? new ThemeUpdater( $product, $this->client, $this->license, $cache, $this->logger )
			: new PluginUpdater( $product, $this->client, $this->license, $cache, $this->logger );

		$view                = new View( $product );
		$this->settings      = new LicenseSettings( $product, $this->license, $view, $this->updater, $this->rollback );
		$this->rollback_page = new RollbackPage( $product, $this->rollback, $this->license, $view );
		$this->notices       = new Notices( $product, $this->license, $this->settings );
	}

	/**
	 * Register the hooks this product's feature set calls for. Idempotent per
	 * request.
	 *
	 * Structural features are decided here and only here: skipping them means
	 * never registering a hook that would cost an API call or schedule a cron
	 * event. Behavioural features are wired unconditionally — registering them
	 * is free — and re-checked at the point of use, so they stay filterable long
	 * after this runs.
	 *
	 * @return void
	 */
	public function boot() {
		/**
		 * Fires before a product registers its hooks.
		 *
		 * @param Features $features Resolved feature flags.
		 * @param Product  $product  Product context.
		 */
		do_action( $this->product->key( 'before_boot' ), $this->features, $this->product );

		// Surface a misconfiguration (e.g. heartbeat without license) in the log
		// instead of silently dropping it.
		foreach ( $this->features->conflicts() as $conflict ) {
			$this->logger->warning( 'features.conflict', $conflict );
		}

		// Updates run in any context where core builds the update transients.
		if ( $this->features->enabled( Features::UPDATES ) ) {
			$this->updater->register();
		}

		// Heartbeat cron (registers the action + self-heals scheduling).
		$this->scheduler->register();

		if ( is_admin() ) {
			$this->settings->register();
			$this->rollback_page->register();
			$this->notices->register();
		}

		$this->logger->step(
			'integration.booted',
			array(
				'type'     => $this->product->type(),
				'mode'     => $this->features->mode(),
				'features' => $this->features->active(),
			)
		);

		/**
		 * Fires after a product has registered its hooks.
		 *
		 * @param Integration $integration This integration.
		 */
		do_action( $this->product->key( 'booted' ), $this );
	}

	/**
	 * Tear down scheduled events. Call from the plugin/theme deactivation hook.
	 *
	 * @return void
	 */
	public function shutdown() {
		$this->scheduler->unschedule();
	}

	/* -------------------------------------------------------------------- */
	/* Public accessors (for consumers that render their own UI)             */
	/* -------------------------------------------------------------------- */

	/** @return Product */
	public function product() {
		return $this->product;
	}

	/** @return Features */
	public function features() {
		return $this->features;
	}

	/** @return Notices */
	public function notices() {
		return $this->notices;
	}

	/** @return LicenseManager */
	public function license() {
		return $this->license;
	}

	/** @return RollbackManager */
	public function rollback() {
		return $this->rollback;
	}

	/** @return Scheduler */
	public function scheduler() {
		return $this->scheduler;
	}

	/** @return LicenseSettings */
	public function settings() {
		return $this->settings;
	}

	/** @return RollbackPage */
	public function rollback_page() {
		return $this->rollback_page;
	}

	/**
	 * Convenience: is the license currently usable?
	 *
	 * Always true when the `license` feature is off — the product carries no
	 * entitlement check, so gating code should not lock anything.
	 *
	 * @return bool
	 */
	public function is_licensed() {
		return $this->license->is_active();
	}
}
