<?php
/**
 * ed25519 response signature verification — the anti-piracy core.
 *
 * @package MeuMouse\MDS\SDK
 */

namespace MeuMouse\MDS\SDK\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Verifies that a response really came from the MDS API and was not tampered
 * with in transit (e.g. by a "nulled" build pointed at a fake update server).
 *
 * The API signs the EXACT bytes it sends. The signed message is:
 *
 *     "{timestamp}.{nonce}.{sha256_hex(raw_body)}"
 *
 * signed with the server's ed25519 private key. We recompute sha256 over the
 * raw response body (before JSON decoding, so no cross-language serialisation
 * ambiguity), rebuild the canonical message and verify the detached signature
 * with the embedded public key. A clock-skew window bounds replay attacks.
 */
final class SignatureVerifier {

	/** Maximum accepted clock skew between server and site, in seconds. */
	const MAX_SKEW = 300;

	/** {@see diagnose()}: ext-sodium is missing, nothing can be verified. */
	const DIAGNOSIS_UNSUPPORTED = 'unsupported';

	/** {@see diagnose()}: the response carries no signature headers. */
	const DIAGNOSIS_MISSING = 'missing';

	/** {@see diagnose()}: genuine signature, rejected only because the clocks disagree. */
	const DIAGNOSIS_CLOCK_SKEW = 'clock_skew';

	/** {@see diagnose()}: the signature does not match the body or the key. */
	const DIAGNOSIS_INVALID = 'invalid';

	/** @var string Raw (base64) ed25519 public key as configured for the product. */
	private $public_key_b64;

	/**
	 * @param string $public_key_b64 Base64-encoded 32-byte ed25519 public key.
	 */
	public function __construct( $public_key_b64 ) {
		$this->public_key_b64 = (string) $public_key_b64;
	}

	/**
	 * Whether the runtime can verify ed25519 signatures at all.
	 *
	 * @return bool
	 */
	public static function is_supported() {
		return function_exists( 'sodium_crypto_sign_verify_detached' );
	}

	/**
	 * Verify a signed response.
	 *
	 * @param string                $raw_body Raw response body bytes.
	 * @param array<string,string>  $headers  Lower-cased response headers.
	 * @param int|null              $now      Override for "now" (testing); defaults to time().
	 * @return bool True only when the signature is present, fresh and valid.
	 */
	public function verify( $raw_body, array $headers, $now = null ) {
		if ( ! self::is_supported() ) {
			return false;
		}

		$signature_b64 = $this->header( $headers, 'x-mds-signature' );
		$timestamp     = $this->header( $headers, 'x-mds-timestamp' );
		$nonce         = $this->header( $headers, 'x-mds-nonce' );

		if ( '' === $signature_b64 || '' === $timestamp || '' === $nonce ) {
			return false;
		}

		// The server signs in milliseconds; compare in seconds.
		$ts_seconds = (int) floor( ( (float) $timestamp ) / 1000 );
		$now        = null === $now ? time() : (int) $now;

		if ( abs( $now - $ts_seconds ) > self::MAX_SKEW ) {
			return false;
		}

		$public_key = base64_decode( $this->public_key_b64, true );
		$signature  = base64_decode( $signature_b64, true );

		if ( false === $public_key || false === $signature ) {
			return false;
		}

		if ( SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $public_key ) ) {
			return false;
		}

		if ( SODIUM_CRYPTO_SIGN_BYTES !== strlen( $signature ) ) {
			return false;
		}

		$digest    = hash( 'sha256', (string) $raw_body );
		$canonical = $timestamp . '.' . $nonce . '.' . $digest;

		try {
			return sodium_crypto_sign_verify_detached( $signature, $canonical, $public_key );
		} catch ( \SodiumException $e ) {
			return false;
		}
	}

	/**
	 * Explain why {@see verify()} rejected a response. Diagnostic only.
	 *
	 * Nothing here accepts a response: it only names the cause, so a site whose
	 * clock is wrong is told so instead of being told the server is unreachable.
	 * Clock skew is reported only when the signature is genuine apart from its
	 * freshness — checked by verifying it against the server's own timestamp —
	 * so a forged timestamp header cannot produce that diagnosis.
	 *
	 * @param string               $raw_body Raw response body bytes.
	 * @param array<string,string> $headers  Response headers.
	 * @param int|null             $now      Override for "now" (testing); defaults to time().
	 * @return array{reason:string,clock_skew?:int} `reason` is one of the DIAGNOSIS_*
	 *         constants; `clock_skew` is the site's clock minus the server's, in seconds.
	 */
	public function diagnose( $raw_body, array $headers, $now = null ) {
		if ( ! self::is_supported() ) {
			return array( 'reason' => self::DIAGNOSIS_UNSUPPORTED );
		}

		$timestamp = $this->header( $headers, 'x-mds-timestamp' );

		if ( '' === $this->header( $headers, 'x-mds-signature' ) || '' === $timestamp || '' === $this->header( $headers, 'x-mds-nonce' ) ) {
			return array( 'reason' => self::DIAGNOSIS_MISSING );
		}

		$ts_seconds = (int) floor( ( (float) $timestamp ) / 1000 );
		$now        = null === $now ? time() : (int) $now;
		$skew       = $now - $ts_seconds;

		if ( abs( $skew ) > self::MAX_SKEW && $this->verify( $raw_body, $headers, $ts_seconds ) ) {
			return array(
				'reason'     => self::DIAGNOSIS_CLOCK_SKEW,
				'clock_skew' => $skew,
			);
		}

		return array( 'reason' => self::DIAGNOSIS_INVALID );
	}

	/**
	 * Case-insensitive header read.
	 *
	 * @param array<string,string> $headers Header map.
	 * @param string                $name    Lower-cased header name.
	 * @return string
	 */
	private function header( array $headers, $name ) {
		foreach ( $headers as $key => $value ) {
			if ( strtolower( (string) $key ) === $name ) {
				return is_array( $value ) ? (string) reset( $value ) : (string) $value;
			}
		}

		return '';
	}
}
