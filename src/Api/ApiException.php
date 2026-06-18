<?php
/**
 * Exception raised for any non-successful MDS API interaction.
 *
 * @package MeuMouse\MDS\SDK
 */

namespace MeuMouse\MDS\SDK\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Carries the API error code (e.g. "FORBIDDEN") and HTTP status alongside the
 * human-readable message, plus a flag for transport-level (network) failures so
 * callers can distinguish "server said no" from "could not reach server".
 */
final class ApiException extends \RuntimeException {

	/** @var string Machine error code from the API envelope, or a synthetic one. */
	private $error_code;

	/** @var int HTTP status (0 for transport failures). */
	private $status;

	/** @var bool Whether this was a network/transport failure rather than an API error. */
	private $is_transport;

	/**
	 * @param string $message      Human-readable message.
	 * @param string $error_code   Machine code.
	 * @param int    $status       HTTP status.
	 * @param bool   $is_transport Transport failure flag.
	 */
	public function __construct( $message, $error_code = 'ERROR', $status = 0, $is_transport = false ) {
		parent::__construct( (string) $message );

		$this->error_code   = (string) $error_code;
		$this->status       = (int) $status;
		$this->is_transport = (bool) $is_transport;
	}

	/** @return string */
	public function error_code() {
		return $this->error_code;
	}

	/** @return int */
	public function status() {
		return $this->status;
	}

	/**
	 * True when the request never reached/parsed the API (DNS, timeout, TLS…).
	 *
	 * Callers use this to apply the license grace period instead of failing closed.
	 *
	 * @return bool
	 */
	public function is_transport() {
		return $this->is_transport;
	}
}
