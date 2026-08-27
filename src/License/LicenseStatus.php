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

	/**
	 * Nothing about the license was checked.
	 *
	 * Two ways to get here: the product runs with the `license` feature off, or
	 * the server answered that this product's gate is open and it never looked
	 * at the key.
	 */
	const STATUS_NOT_REQUIRED = 'not_required';

	/** Gate names as the API reports them, minus the `_allowed` suffix. */
	const GATE_UPDATES   = 'updates';
	const GATE_DOWNLOADS = 'downloads';

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
				'extra'           => array(),
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

	/**
	 * Status of a product that does not use licensing at all.
	 *
	 * Reported as valid so a consumer's `$status->is_valid()` gate keeps the
	 * product unlocked; use {@see is_required()} to tell the two apart.
	 *
	 * @return self
	 */
	public static function not_required() {
		return new self(
			array(
				'status'  => self::STATUS_NOT_REQUIRED,
				'valid'   => true,
				'message' => __( 'This product does not require a license.', 'mds-sdk' ),
			)
		);
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

	/**
	 * Whether this product uses licensing at all.
	 *
	 * @return bool False only when the `license` feature is off.
	 */
	public function is_required() {
		return self::STATUS_NOT_REQUIRED !== $this->status();
	}

	/**
	 * Server-supplied fields beyond the ones this class models — plan, renewal
	 * URL, support expiry, failure reason and anything the API adds later.
	 *
	 * The SDK does not interpret these; they exist so a product can render its
	 * own license screen without a second round-trip.
	 *
	 * @return array<string,mixed>
	 */
	public function extra() {
		return is_array( $this->state['extra'] ) ? $this->state['extra'] : array();
	}

	/**
	 * Read a single extra field.
	 *
	 * @param string $key     Field name as returned by the API.
	 * @param mixed  $default Value when the field is absent.
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		$extra = $this->extra();

		return array_key_exists( $key, $extra ) ? $extra[ $key ] : $default;
	}

	/**
	 * Whether the server said this site may still use one of the two gates,
	 * regardless of what the license's own state says.
	 *
	 * This is not the same question as {@see is_valid()}, and the difference is
	 * the point. A customer grandfathered past a gate the product turned on
	 * comes back `valid: false, reason: license_expired` — the license really
	 * did lapse and the license screen must say so — while the server keeps
	 * serving them. Only the server knows about the waiver, so only the server
	 * can answer this.
	 *
	 * @param string $gate {@see GATE_UPDATES} or {@see GATE_DOWNLOADS}.
	 * @return bool|null Null when the server did not say — an older API, or a
	 *                   status recorded before it started answering.
	 */
	public function allowance( $gate ) {
		$value = $this->get( $gate . '_allowed' );

		return null === $value ? null : (bool) $value;
	}

	/**
	 * @return bool|null Null when unanswered; callers fall back to validity.
	 */
	public function updates_allowed() {
		return $this->allowance( self::GATE_UPDATES );
	}

	/**
	 * @return bool|null Null when unanswered; callers fall back to validity.
	 */
	public function downloads_allowed() {
		return $this->allowance( self::GATE_DOWNLOADS );
	}
}
