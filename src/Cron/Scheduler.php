<?php
/**
 * Controlled, low-frequency license heartbeat via WP-Cron.
 *
 * @package MeuMouse\MDS\SDK
 */

namespace MeuMouse\MDS\SDK\Cron;

use MeuMouse\MDS\SDK\Config\Product;
use MeuMouse\MDS\SDK\License\Manager as LicenseManager;
use MeuMouse\MDS\SDK\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Schedules one daily license validation per product. The first run is offset by
 * a random jitter so a fleet of sites does not stampede the API at the same
 * minute. Update checks are NOT scheduled here — those piggyback on WordPress's
 * native update cron through the cached AbstractUpdater.
 */
final class Scheduler {

	/** @var Product */
	private $product;

	/** @var LicenseManager */
	private $license;

	/** @var Logger */
	private $logger;

	/**
	 * @param Product        $product Product context.
	 * @param LicenseManager $license License manager.
	 * @param Logger         $logger  Logger.
	 */
	public function __construct( Product $product, LicenseManager $license, Logger $logger ) {
		$this->product = $product;
		$this->license = $license;
		$this->logger  = $logger;
	}

	/**
	 * The per-product cron hook name.
	 *
	 * @return string
	 */
	public function hook() {
		return $this->product->key( 'heartbeat' );
	}

	/**
	 * Wire the cron callback and ensure the event is scheduled.
	 *
	 * @return void
	 */
	public function register() {
		add_action( $this->hook(), array( $this, 'run' ) );

		// Self-heal: schedule on the first admin/cron load if missing.
		if ( ! wp_next_scheduled( $this->hook() ) ) {
			$this->schedule();
		}
	}

	/**
	 * Schedule the daily heartbeat with a randomised first run.
	 *
	 * @return void
	 */
	public function schedule() {
		if ( wp_next_scheduled( $this->hook() ) ) {
			return;
		}

		$jitter = wp_rand( 0, 6 * HOUR_IN_SECONDS );

		wp_schedule_event( time() + $jitter, 'daily', $this->hook() );
		$this->logger->step( 'cron.scheduled', array( 'jitter' => $jitter ) );
	}

	/**
	 * Remove the scheduled heartbeat (call on plugin/theme deactivation).
	 *
	 * @return void
	 */
	public function unschedule() {
		$timestamp = wp_next_scheduled( $this->hook() );

		while ( $timestamp ) {
			wp_unschedule_event( $timestamp, $this->hook() );
			$timestamp = wp_next_scheduled( $this->hook() );
		}
	}

	/**
	 * Cron callback: run the heartbeat validation.
	 *
	 * @return void
	 */
	public function run() {
		if ( ! $this->license->has_key() ) {
			return;
		}

		$this->logger->step( 'cron.heartbeat', array() );
		$this->license->validate();
	}
}
