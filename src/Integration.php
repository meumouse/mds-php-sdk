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

	/**
	 * @param Product $product Product configuration.
	 */
	public function __construct( Product $product ) {
		$this->product = $product;

		$this->logger    = new Logger( $product->slug() );
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
	}

	/**
	 * Register all hooks. Idempotent per request.
	 *
	 * @return void
	 */
	public function boot() {
		// Updates run in any context where core builds the update transients.
		$this->updater->register();

		// Heartbeat cron (registers the action + self-heals scheduling).
		$this->scheduler->register();

		if ( is_admin() ) {
			$this->settings->register();
			$this->rollback_page->register();

			$notices = new Notices( $this->product, $this->license, $this->settings->settings_url() );
			$notices->register();
		}

		$this->logger->step( 'integration.booted', array( 'type' => $this->product->type() ) );
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
	 * @return bool
	 */
	public function is_licensed() {
		return $this->license->is_active();
	}
}
