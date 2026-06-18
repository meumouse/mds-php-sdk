<?php
/**
 * Structured, step-based debug logger (no-op unless WP_DEBUG is on).
 *
 * @package MeuMouse\MDS\SDK
 */

namespace MeuMouse\MDS\SDK\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Lightweight logger that emits one line per "step" with a JSON context blob.
 *
 * Mirrors the step-logger debugging convention used elsewhere in the MDS repos,
 * adapted to PHP/WordPress. Silent in production; writes via error_log() only
 * when WP_DEBUG is enabled, so it never leaks to end users.
 */
final class Logger {

	/** @var string Product slug, used as the log channel. */
	private $channel;

	/**
	 * @param string $channel Channel label (typically the product slug).
	 */
	public function __construct( $channel ) {
		$this->channel = (string) $channel;
	}

	/**
	 * Whether logging is active.
	 *
	 * @return bool
	 */
	private function enabled() {
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
