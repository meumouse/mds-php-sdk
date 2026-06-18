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
