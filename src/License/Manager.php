<?php
/**
 * License lifecycle: activate, deactivate, heartbeat-validate, storage, grace.
 *
 * @package MeuMouse\MDS\SDK
 */

namespace MeuMouse\MDS\SDK\License;

use MeuMouse\MDS\SDK\Api\ApiException;
use MeuMouse\MDS\SDK\Api\Client;
use MeuMouse\MDS\SDK\Config\Product;
use MeuMouse\MDS\SDK\Support\Environment;
use MeuMouse\MDS\SDK\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the stored license key and its last verified status.
 *
 * Activation is fail-closed (no valid status without a signed server "yes").
 * Heartbeat validation is fail-open within a configurable grace window so a
 * transient API/network outage never disables a legitimate customer, while a
 * sustained outage (or a server "no") eventually invalidates the license.
 */
final class Manager {

	/** @var Product */
	private $product;

	/** @var Client */
	private $client;

	/** @var Logger */
	private $logger;

	/**
	 * @param Product $product Product context.
	 * @param Client  $client  API client.
	 * @param Logger  $logger  Debug logger.
	 */
	public function __construct( Product $product, Client $client, Logger $logger ) {
		$this->product = $product;
		$this->client  = $client;
		$this->logger  = $logger;
	}

	/* -------------------------------------------------------------------- */
	/* Key storage                                                           */
	/* -------------------------------------------------------------------- */

	/** @return string The stored license key, or empty string. */
	public function get_key() {
		return (string) $this->get_option( $this->product->key( 'license_key' ), '' );
	}

	/** @return bool */
	public function has_key() {
		return '' !== $this->get_key();
	}

	/* -------------------------------------------------------------------- */
	/* Public operations                                                     */
	/* -------------------------------------------------------------------- */

	/**
	 * Activate a license key against the current domain.
	 *
	 * @param string $key License key entered by the admin.
	 * @return LicenseStatus
	 *
	 * @throws ApiException On transport failure (caller shows "try again").
	 */
	public function activate( $key ) {
		$key = $this->normalize_key( $key );

		// `product_slug` identifies which product is activating. A seat is a *site*,
		// so it does not affect the activation itself — under a bundle license every
		// product shares one seat. It lets the server record this product's install
		// straight away instead of waiting for the first heartbeat.
		$body = array_merge(
			array(
				'license_key'  => $key,
				'product_slug' => $this->product->slug(),
			),
			Environment::request_meta( $this->product )
		);

		try {
			$this->client->post( '/v2/licenses/activate', $body, true );
		} catch ( ApiException $e ) {
			if ( $e->is_transport() ) {
				throw $e;
			}

			$status = new LicenseStatus(
				array(
					'status'     => LicenseStatus::STATUS_INVALID,
					'valid'      => false,
					'domain'     => Environment::domain(),
					'checked_at' => time(),
					'signed'     => false,
					'message'    => $e->getMessage(),
				)
			);

			$this->store_status( $status );

			return $status;
		}

		// Persist the key only after a signed success, then pull full status
		// (including expiry) via a single validate call.
		$this->set_option( $this->product->key( 'license_key' ), $key );

		return $this->validate();
	}

	/**
	 * Deactivate the current site's activation and forget the key locally.
	 *
	 * Under a bundle licence this releases only *this* product's hold on the
	 * site: the seat stays with the other products of the bundle until the last
	 * one leaves. That is why `product_slug` is sent — and required by the API
	 * for a bundle key.
	 *
	 * Best-effort: a server-side failure still clears local state so the admin
	 * can re-enter a key.
	 *
	 * @return void
	 */
	public function deactivate() {
		$key = $this->get_key();

		if ( '' !== $key ) {
			try {
				$this->client->post(
					'/v2/licenses/deactivate',
					array(
						'license_key'  => $key,
						'domain'       => Environment::domain(),
						'product_slug' => $this->product->slug(),
					),
					false
				);
			} catch ( ApiException $e ) {
				$this->logger->warning( 'license.deactivate', array( 'code' => $e->error_code() ) );
			}
		}

		$this->delete_option( $this->product->key( 'license_key' ) );
		$this->delete_option( $this->product->key( 'license_state' ) );
	}

	/**
	 * Re-validate the license against the server (the heartbeat).
	 *
	 * @return LicenseStatus
	 */
	public function validate() {
		$key = $this->get_key();

		if ( '' === $key ) {
			$status = new LicenseStatus(
				array(
					'status'     => LicenseStatus::STATUS_UNKNOWN,
					'valid'      => false,
					'checked_at' => time(),
					'message'    => __( 'No license key configured.', 'mds-sdk' ),
				)
			);

			$this->store_status( $status );

			return $status;
		}

		$body = array_merge(
			array(
				'license_key'  => $key,
				'product_slug' => $this->product->slug(),
			),
			Environment::request_meta( $this->product )
		);

		try {
			$response = $this->client->post( '/v2/updates/validate', $body, true );
		} catch ( ApiException $e ) {
			return $e->is_transport()
				? $this->handle_transport_failure( $e )
				: $this->handle_server_rejection( $e );
		}

		$data = $response->data();
		$now  = time();

		// Everything the API returned beyond the fields modelled below is kept
		// verbatim, so a product can render plan/renewal details from the same
		// round-trip instead of querying again.
		$extra = $data;
		unset( $extra['valid'], $extra['license_status'], $extra['expires_at'] );

		$status = new LicenseStatus(
			array(
				'status'          => isset( $data['license_status'] ) ? (string) $data['license_status'] : LicenseStatus::STATUS_ACTIVE,
				'valid'           => ! empty( $data['valid'] ),
				'domain'          => Environment::domain(),
				'expires_at'      => isset( $data['expires_at'] ) ? $data['expires_at'] : null,
				'checked_at'      => $now,
				'last_success_at' => $now,
				'signed'          => $response->is_signed(),
				'message'         => isset( $data['message'] ) ? (string) $data['message'] : '',
				'extra'           => $extra,
			)
		);

		$this->store_status( $status );
		$this->logger->step( 'license.validate', array( 'valid' => $status->is_valid(), 'status' => $status->status() ) );

		return $status;
	}

	/**
	 * Last persisted status (no network call).
	 *
	 * @return LicenseStatus
	 */
	public function status() {
		$stored = $this->get_option( $this->product->key( 'license_state' ), array() );

		return is_array( $stored ) ? LicenseStatus::from_array( $stored ) : new LicenseStatus();
	}

	/**
	 * Effective validity used to gate updates/rollback, honouring the grace
	 * window and any locally-known expiry date.
	 *
	 * @return bool
	 */
	public function is_active() {
		$status = $this->status();

		if ( ! $status->is_valid() ) {
			return false;
		}

		$expires = $status->expires_at();

		if ( $expires && strtotime( $expires ) < time() ) {
			return false;
		}

		return true;
	}

	/* -------------------------------------------------------------------- */
	/* Failure handling                                                      */
	/* -------------------------------------------------------------------- */

	/**
	 * Server explicitly rejected the license (expired/forbidden/not found).
	 *
	 * @param ApiException $e Exception.
	 * @return LicenseStatus
	 */
	private function handle_server_rejection( ApiException $e ) {
		$status_code = 'license_expired' === strtolower( $e->error_code() )
			? LicenseStatus::STATUS_EXPIRED
			: LicenseStatus::STATUS_INVALID;

		$status = new LicenseStatus(
			array(
				'status'     => $status_code,
				'valid'      => false,
				'domain'     => Environment::domain(),
				'checked_at' => time(),
				'signed'     => false,
				'message'    => $e->getMessage(),
			)
		);

		$this->store_status( $status );
		$this->logger->warning( 'license.rejected', array( 'code' => $e->error_code() ) );

		return $status;
	}

	/**
	 * Network/transport failure: keep the last good status within the grace
	 * window, otherwise degrade to invalid.
	 *
	 * @param ApiException $e Exception.
	 * @return LicenseStatus
	 */
	private function handle_transport_failure( ApiException $e ) {
		$previous = $this->status();
		$now      = time();
		$within   = $previous->last_success_at() > 0
			&& ( $now - $previous->last_success_at() ) <= $this->product->grace_period();

		$state              = $previous->to_array();
		$state['checked_at'] = $now;

		if ( $previous->is_valid() && $within ) {
			$state['message'] = __( 'Could not reach the licensing server; using cached status.', 'mds-sdk' );
			$this->logger->warning( 'license.grace', array( 'remaining' => $this->product->grace_period() - ( $now - $previous->last_success_at() ) ) );
		} else {
			$state['valid']   = false;
			$state['status']  = LicenseStatus::STATUS_UNKNOWN;
			$state['message'] = __( 'Licensing server unreachable and grace period elapsed.', 'mds-sdk' );
			$this->logger->error( 'license.grace_expired', array() );
		}

		$status = new LicenseStatus( $state );
		$this->store_status( $status );

		return $status;
	}

	/* -------------------------------------------------------------------- */
	/* Helpers                                                               */
	/* -------------------------------------------------------------------- */

	/**
	 * @param LicenseStatus $status Status to persist.
	 * @return void
	 */
	private function store_status( LicenseStatus $status ) {
		$this->set_option( $this->product->key( 'license_state' ), $status->to_array() );
	}

	/**
	 * @param string $key Raw key.
	 * @return string
	 */
	private function normalize_key( $key ) {
		return trim( preg_replace( '/\s+/', '', (string) $key ) );
	}

	/**
	 * @param string $name    Option name.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	private function get_option( $name, $default ) {
		return is_multisite() ? get_site_option( $name, $default ) : get_option( $name, $default );
	}

	/**
	 * @param string $name  Option name.
	 * @param mixed  $value Value.
	 * @return void
	 */
	private function set_option( $name, $value ) {
		if ( is_multisite() ) {
			update_site_option( $name, $value );
		} else {
			update_option( $name, $value, false );
		}
	}

	/**
	 * @param string $name Option name.
	 * @return void
	 */
	private function delete_option( $name ) {
		if ( is_multisite() ) {
			delete_site_option( $name );
		} else {
			delete_option( $name );
		}
	}
}
