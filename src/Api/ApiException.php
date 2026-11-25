<?php
/**
 * Exception raised for any non-successful MDS API interaction.
 *
 * @package MeuMouse\MDS\SDK
 */

namespace MeuMouse\MDS\SDK\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Carries the API error code (e.g. "LICENSE_EXPIRED", see the MDS API reference) and HTTP status alongside the
 * human-readable message, plus a flag for transport-level (network) failures so
 * callers can distinguish "server said no" from "could not reach server".
 *
 * {@see reason()} narrows a failure down to its likely cause and {@see user_message()}
 * words it for the site admin, so a site with a wrong clock or a missing CA bundle
 * is told what to fix instead of "could not reach the licensing server".
 */
final class ApiException extends \RuntimeException {

	/** The API answered and refused (a 4xx with its own message). */
	const REASON_API = 'api';

	/** The site blocks outgoing HTTP (WP_HTTP_BLOCK_EXTERNAL). */
	const REASON_BLOCKED = 'blocked';

	/** The licensing server's host name could not be resolved. */
	const REASON_DNS = 'dns';

	/** TLS handshake or certificate verification failed on the site's side. */
	const REASON_SSL = 'ssl';

	/** The request timed out. */
	const REASON_TIMEOUT = 'timeout';

	/** Any other network failure (connection refused, reset, proxy…). */
	const REASON_CONNECTION = 'connection';

	/** Genuine signed answer, rejected because the site's clock is off. */
	const REASON_CLOCK = 'clock';

	/** PHP lacks ext-sodium, so no answer can be verified. */
	const REASON_SODIUM = 'sodium';

	/** The answer carried no valid signature. */
	const REASON_SIGNATURE = 'signature';

	/** Something other than the API answered (proxy, WAF, CDN page). */
	const REASON_MALFORMED = 'malformed';

	/** HTTP 429. */
	const REASON_RATE_LIMITED = 'rate_limited';

	/** HTTP 5xx. */
	const REASON_SERVER = 'server';

	/** cURL error numbers of TLS/certificate failures. */
	const CURL_SSL_ERRORS = array( 35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91 );

	/** @var string Machine error code from the API envelope, or a synthetic one. */
	private $error_code;

	/** @var int HTTP status (0 for transport failures). */
	private $status;

	/** @var bool Whether this was a network/transport failure rather than an API error. */
	private $is_transport;

	/** @var array<string,mixed> Diagnostic details (wp_error_code, host, signature, clock_skew). */
	private $context;

	/**
	 * @param string              $message      Human-readable message.
	 * @param string              $error_code   Machine code.
	 * @param int                 $status       HTTP status.
	 * @param bool                $is_transport Transport failure flag.
	 * @param array<string,mixed> $context      Diagnostic details for {@see reason()}.
	 */
	public function __construct( $message, $error_code = 'ERROR', $status = 0, $is_transport = false, array $context = array() ) {
		parent::__construct( (string) $message );

		$this->error_code   = (string) $error_code;
		$this->status       = (int) $status;
		$this->is_transport = (bool) $is_transport;
		$this->context      = $context;
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
	 * Diagnostic details recorded by the client.
	 *
	 * @return array<string,mixed>
	 */
	public function context() {
		return $this->context;
	}

	/**
	 * True when the request never reached/parsed the API (DNS, timeout, TLS…).
	 *
	 * @return bool
	 */
	public function is_transport() {
		return $this->is_transport;
	}

	/**
	 * True when the failure says nothing about the license: the server was
	 * unreachable or overloaded, or what came back cannot be trusted as its answer.
	 *
	 * Callers use this to apply the license grace period instead of failing closed.
	 * Unverifiable bodies belong here because they are discarded, never acted on:
	 * the last signed status stands, and the grace period still bounds it.
	 *
	 * @return bool
	 */
	public function is_transient() {
		return $this->is_transport
			|| 429 === $this->status
			|| $this->status >= 500
			|| in_array( $this->error_code, array( 'MALFORMED_RESPONSE', 'INVALID_SIGNATURE' ), true );
	}

	/**
	 * The likely cause of the failure, as one of the REASON_* constants.
	 *
	 * @return string
	 */
	public function reason() {
		if ( $this->is_transport ) {
			return $this->transport_reason();
		}

		if ( 'INVALID_SIGNATURE' === $this->error_code ) {
			$signature = isset( $this->context['signature'] ) ? (string) $this->context['signature'] : '';

			if ( 'clock_skew' === $signature && isset( $this->context['clock_skew'] ) ) {
				return self::REASON_CLOCK;
			}

			return 'unsupported' === $signature ? self::REASON_SODIUM : self::REASON_SIGNATURE;
		}

		if ( 'MALFORMED_RESPONSE' === $this->error_code ) {
			return self::REASON_MALFORMED;
		}

		if ( 429 === $this->status ) {
			return self::REASON_RATE_LIMITED;
		}

		if ( $this->status >= 500 ) {
			return self::REASON_SERVER;
		}

		return self::REASON_API;
	}

	/**
	 * A translated message telling the site admin what went wrong and what to do.
	 *
	 * An API refusal keeps the API's own (already localised) message; every other
	 * cause gets wording of its own, with the HTTP status or the transport error
	 * appended as detail for support.
	 *
	 * @return string
	 */
	public function user_message() {
		$reason = $this->reason();
		$host   = isset( $this->context['host'] ) && '' !== (string) $this->context['host']
			? (string) $this->context['host']
			: __( 'the licensing server', 'mds-sdk' );

		switch ( $reason ) {
			case self::REASON_BLOCKED:
				$message = sprintf(
					/* translators: %s: licensing server host name */
					__( 'This site blocks outgoing HTTP requests (WP_HTTP_BLOCK_EXTERNAL). Add %s to WP_ACCESSIBLE_HOSTS and try again.', 'mds-sdk' ),
					$host
				);
				break;

			case self::REASON_DNS:
				$message = sprintf(
					/* translators: %s: licensing server host name */
					__( 'This site could not resolve the address of %s. Check its internet connection and DNS settings, then try again.', 'mds-sdk' ),
					$host
				);
				break;

			case self::REASON_SSL:
				$message = __( 'This site could not open a secure (SSL) connection to the licensing server. Its PHP/cURL is likely missing up-to-date CA certificates, which is common on local setups: update the CA bundle or ask your host, then try again.', 'mds-sdk' );
				break;

			case self::REASON_TIMEOUT:
				$message = __( 'The licensing server did not answer in time. Check this site\'s internet connection, firewall or proxy and try again.', 'mds-sdk' );
				break;

			case self::REASON_CONNECTION:
				$message = __( 'This site could not connect to the licensing server. Check its internet connection, firewall or proxy and try again.', 'mds-sdk' );
				break;

			case self::REASON_CLOCK:
				$skew     = (int) $this->context['clock_skew'];
				$distance = human_time_diff( 0, abs( $skew ) );

				$message = $skew > 0
					? sprintf(
						/* translators: %s: time difference, e.g. "2 hours" */
						__( 'This site\'s clock is %s ahead of the licensing server, so its answer could not be verified. Correct the date and time of this computer or server and try again.', 'mds-sdk' ),
						$distance
					)
					: sprintf(
						/* translators: %s: time difference, e.g. "2 hours" */
						__( 'This site\'s clock is %s behind the licensing server, so its answer could not be verified. Correct the date and time of this computer or server and try again.', 'mds-sdk' ),
						$distance
					);

				return $message;

			case self::REASON_SODIUM:
				$message = __( 'This site\'s PHP lacks the sodium extension, which is required to verify the licensing server\'s answers. Enable ext-sodium and try again.', 'mds-sdk' );
				break;

			case self::REASON_SIGNATURE:
				$message = __( 'The licensing server\'s answer could not be verified. A proxy, firewall or security plugin between this site and the server may be altering it.', 'mds-sdk' );
				break;

			case self::REASON_MALFORMED:
				$message = __( 'Something other than the licensing server answered, such as a proxy, firewall or CDN. Check this site\'s outgoing connection and try again.', 'mds-sdk' );
				break;

			case self::REASON_RATE_LIMITED:
				$message = __( 'Too many requests to the licensing server. Wait a minute and try again.', 'mds-sdk' );
				break;

			case self::REASON_SERVER:
				$message = __( 'The licensing server is temporarily unavailable. Please try again in a few minutes.', 'mds-sdk' );
				break;

			default:
				return $this->getMessage();
		}

		if ( $this->status > 0 ) {
			/* translators: %d: HTTP status code */
			$message .= ' ' . sprintf( __( '(HTTP %d)', 'mds-sdk' ), $this->status );
		}

		if ( $this->is_transport && '' !== $this->getMessage() ) {
			/* translators: %s: error reported by the site's HTTP transport, e.g. a cURL error */
			$message .= ' ' . sprintf( __( 'Details: %s', 'mds-sdk' ), $this->getMessage() );
		}

		return $message;
	}

	/**
	 * Classify a transport failure from the WP_Error code and the cURL error number.
	 *
	 * The number is read from "cURL error N:", which WordPress never translates;
	 * the text fallback covers the PHP streams transport.
	 *
	 * @return string
	 */
	private function transport_reason() {
		if ( isset( $this->context['wp_error_code'] ) && 'http_request_not_executed' === $this->context['wp_error_code'] ) {
			return self::REASON_BLOCKED;
		}

		$message = $this->getMessage();

		if ( preg_match( '/cURL error (\d+)/i', $message, $match ) ) {
			$errno = (int) $match[1];

			if ( 28 === $errno ) {
				return self::REASON_TIMEOUT;
			}

			if ( 5 === $errno || 6 === $errno ) {
				return self::REASON_DNS;
			}

			if ( in_array( $errno, self::CURL_SSL_ERRORS, true ) ) {
				return self::REASON_SSL;
			}

			return self::REASON_CONNECTION;
		}

		if ( preg_match( '/ssl|tls|certificate/i', $message ) ) {
			return self::REASON_SSL;
		}

		if ( preg_match( '/timed out|timeout/i', $message ) ) {
			return self::REASON_TIMEOUT;
		}

		if ( preg_match( '/resolve|getaddrinfo|name lookup/i', $message ) ) {
			return self::REASON_DNS;
		}

		return self::REASON_CONNECTION;
	}
}
