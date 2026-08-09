<?php
/**
 * Structured, step-based debug logger (no-op unless WP_DEBUG is on).
 *
 * @package MeuMouse\MDS\SDK
 */

namespace MeuMouse\MDS\SDK\Support;

use MeuMouse\MDS\SDK\Config\Features;

defined( 'ABSPATH' ) || exit;

/**
 * Lightweight logger that emits one line per "step" with a JSON context blob.
 *
 * Mirrors the step-logger debugging convention used elsewhere in the MDS repos,
 * adapted to PHP/WordPress. Writes via error_log() only while the `logging`
 * feature is on — which defaults to WP_DEBUG, so it stays silent in production
 * unless a product or site explicitly opts in.
 */
final class Logger {

	/** @var string Product slug, used as the log channel. */
	private $channel;

	/** @var Features|null Feature flags; null falls back to WP_DEBUG. */
	private $features;

	/**
	 * @param string        $channel  Channel label (typically the product slug).
	 * @param Features|null $features Feature flags owning the `logging` switch.
	 */
	public function __construct( $channel, ?Features $features = null ) {
		$this->channel  = (string) $channel;
		$this->features = $features;
	}

	/**
	 * Whether logging is active. Re-evaluated on every call so the flag can be
	 * filtered at runtime, e.g. to capture one noisy request.
	 *
	 * @return bool
	 */
	private function enabled() {
		if ( null !== $this->features ) {
			return $this->features->enabled( Features::LOGGING );
		}

		return defined( 'WP_DEBUG' ) && WP_DEBUG;
	}

	/**
	 * Log a step with optional structured context.
	 *
	 * @param string $step    Short step identifier, e.g. "license.activate".
	 * @param array  $context Arbitrary context; secrets are redacted by the caller.
	 * @param string $level   "debug" | "info" | "warning" | "error".
	 * @return void
	 */
	public function step( $step, array $context = array(), $level = 'debug' ) {
		if ( ! $this->enabled() ) {
			return;
		}

		$payload = wp_json_encode( $context );

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log(
			sprintf(
				'[MDS-SDK][%s][%s] %s %s',
				$this->channel,
				strtoupper( $level ),
				$step,
				false === $payload ? '{}' : $payload
			)
		);
	}

	/**
	 * @param string $step    Step id.
	 * @param array  $context Context.
	 * @return void
	 */
	public function error( $step, array $context = array() ) {
		$this->step( $step, $context, 'error' );
	}

	/**
	 * @param string $step    Step id.
	 * @param array  $context Context.
	 * @return void
	 */
	public function warning( $step, array $context = array() ) {
		$this->step( $step, $context, 'warning' );
	}
}
