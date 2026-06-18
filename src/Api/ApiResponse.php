<?php
/**
 * Successful, parsed API response.
 *
 * @package MeuMouse\MDS\SDK
 */

namespace MeuMouse\MDS\SDK\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable container for the `data` payload of a `{ success: true, data }`
 * envelope, plus whether the response carried a valid cryptographic signature.
 */
final class ApiResponse {

	/** @var array Decoded `data` payload. */
	private $data;

	/** @var bool Whether the ed25519 signature was present and verified. */
	private $signed;

	/** @var int HTTP status code. */
	private $status;

	/**
	 * @param array $data   Decoded payload.
	 * @param bool  $signed Signature verification result.
	 * @param int   $status HTTP status.
	 */
	public function __construct( array $data, $signed, $status ) {
		$this->data   = $data;
		$this->signed = (bool) $signed;
		$this->status = (int) $status;
	}

	/**
	 * @param string|null $key     Optional dot-free top-level key.
	 * @param mixed       $default Default when key is absent.
	 * @return mixed
	 */
	public function data( $key = null, $default = null ) {
		if ( null === $key ) {
			return $this->data;
		}

		return array_key_exists( $key, $this->data ) ? $this->data[ $key ] : $default;
	}

	/** @return bool */
	public function is_signed() {
		return $this->signed;
	}

	/** @return int */
	public function status() {
		return $this->status;
	}
}
