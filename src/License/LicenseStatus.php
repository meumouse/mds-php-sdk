<?php
/**
 * Snapshot of a license's last known state.
 *
 * @package MeuMouse\MDS\SDK
 */

namespace MeuMouse\MDS\SDK\License;

defined( 'ABSPATH' ) || exit;

/**
 * Serialisable value object persisted in options and used across the SDK to
 * decide whether updates/rollback should be offered.
 */
final class LicenseStatus {

	const STATUS_ACTIVE   = 'active';
	const STATUS_INACTIVE = 'inactive';
	const STATUS_EXPIRED  = 'expired';
	const STATUS_INVALID  = 'invalid';
	const STATUS_UNKNOWN  = 'unknown';

	/** @var array<string,mixed> */
	private $state;

	/**
	 * @param array<string,mixed> $state Raw state map.
	 */
	public function __construct( array $state = array() ) {
		$this->state = array_merge(
			array(
				'status'          => self::STATUS_UNKNOWN,
				'valid'           => false,
				'domain'          => '',
				'expires_at'      => null,
				'checked_at'      => 0,
				'last_success_at' => 0,
				'signed'          => false,
				'message'         => '',
			),
			$state
		);
	}

	/**
	 * @param array<string,mixed> $state Raw state.
	 * @return self
	 */
	public static function from_array( array $state ) {
		return new self( $state );
	}

	/** @return array<string,mixed> */
	public function to_array() {
		return $this->state;
	}

	/** @return string */
	public function status() {
		return (string) $this->state['status'];
	}

	/** @return bool The server's last verdict (ignores grace period). */
	public function is_valid() {
		return (bool) $this->state['valid'];
	}

	/** @return bool */
	public function is_signed() {
		return (bool) $this->state['signed'];
	}

	/** @return string */
	public function domain() {
		return (string) $this->state['domain'];
	}

	/** @return string|null ISO-8601 expiry, or null for lifetime. */
	public function expires_at() {
		return $this->state['expires_at'];
	}

	/** @return int */
	public function checked_at() {
		return (int) $this->state['checked_at'];
	}

	/** @return int */
	public function last_success_at() {
		return (int) $this->state['last_success_at'];
	}

	/** @return string */
	public function message() {
		return (string) $this->state['message'];
	}

	/** @return bool */
	public function is_expired() {
		return self::STATUS_EXPIRED === $this->status();
	}
}
