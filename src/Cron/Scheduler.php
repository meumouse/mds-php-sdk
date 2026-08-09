<?php
/**
 * Controlled, low-frequency license heartbeat via WP-Cron.
 *
 * @package MeuMouse\MDS\SDK
 */

namespace MeuMouse\MDS\SDK\Cron;

use MeuMouse\MDS\SDK\Config\Features;
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
		// Structural: registering schedules a recurring event, so an unwanted
		// heartbeat must never be wired in the first place.
		if ( ! $this->is_enabled() ) {
			// An event scheduled while the feature was on would otherwise outlive
			// it. Cleaned up on an admin or cron load only — never at the cost of
			// a front-end request.
			if ( is_admin() || ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) ) {
				$this->unschedule();
			}

			return;
		}

		add_action( $this->hook(), array( $this, 'run' ) );

		// Self-heal: schedule on the first admin/cron load if missing.
		if ( ! wp_next_scheduled( $this->hook() ) ) {
			$this->schedule();
		}
	}

	/**
	 * Schedule the heartbeat with a randomised first run.
	 *
	 * @return void
	 */
	public function schedule() {
		if ( wp_next_scheduled( $this->hook() ) ) {
			return;
		}

		$jitter = wp_rand( 0, 6 * HOUR_IN_SECONDS );

		wp_schedule_event( time() + $jitter, $this->recurrence(), $this->hook() );
		$this->logger->step( 'cron.scheduled', array( 'jitter' => $jitter, 'recurrence' => $this->recurrence() ) );
	}

	/**
	 * Heartbeat interval. Falls back to "daily" when the filtered value is not a
	 * schedule WordPress actually knows about.
	 *
	 * @return string
	 */
	public function recurrence() {
		/**
		 * Filter the license heartbeat recurrence.
		 *
		 * @param string  $recurrence A registered cron schedule name.
		 * @param Product $product    Product context.
		 */
		$recurrence = (string) apply_filters( $this->product->key( 'heartbeat_recurrence' ), 'daily', $this->product );

		$schedules = function_exists( 'wp_get_schedules' ) ? wp_get_schedules() : array();

		if ( is_array( $schedules ) && ! empty( $schedules ) && ! isset( $schedules[ $recurrence ] ) ) {
			return 'daily';
		}

		return '' !== $recurrence ? $recurrence : 'daily';
	}

	/**
	 * Whether the heartbeat is active for this product.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return $this->product->features()->enabled( Features::HEARTBEAT );
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
		if ( ! $this->is_enabled() || ! $this->license->has_key() ) {
			return;
		}

		$this->logger->step( 'cron.heartbeat', array() );
		$this->license->validate();
	}
}
