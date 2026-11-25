<?php
/**
 * Tests for the ed25519 signature verifier (anti-piracy core).
 *
 * @package MeuMouse\MDS\SDK
 */

namespace MeuMouse\MDS\SDK\Tests;

use MeuMouse\MDS\SDK\Security\SignatureVerifier;
use PHPUnit\Framework\TestCase;

final class SignatureVerifierTest extends TestCase {

	/** @var string */
	private $public_b64;

	/** @var string Raw 64-byte secret key. */
	private $secret;

	protected function setUp(): void {
		parent::setUp();

		$pair             = sodium_crypto_sign_keypair();
		$this->secret     = sodium_crypto_sign_secretkey( $pair );
		$this->public_b64 = base64_encode( sodium_crypto_sign_publickey( $pair ) );
	}

	/**
	 * Build the headers a correctly-signed response would carry.
	 *
	 * @param string $body Raw body bytes.
	 * @param int    $ts   Timestamp in ms.
	 * @return array<string,string>
	 */
	private function sign( $body, $ts ) {
		$nonce     = bin2hex( random_bytes( 16 ) );
		$digest    = hash( 'sha256', $body );
		$canonical = $ts . '.' . $nonce . '.' . $digest;
		$signature = sodium_crypto_sign_detached( $canonical, $this->secret );

		return array(
			'X-MDS-Signature' => base64_encode( $signature ),
			'X-MDS-Timestamp' => (string) $ts,
			'X-MDS-Nonce'     => $nonce,
			'X-MDS-Key-Id'    => 'k1',
		);
	}

	public function test_accepts_a_valid_fresh_signature(): void {
		$body    = '{"success":true,"data":{"valid":true}}';
		$now     = 1_700_000_000;
		$headers = $this->sign( $body, $now * 1000 );

		$verifier = new SignatureVerifier( $this->public_b64 );

		$this->assertTrue( $verifier->verify( $body, $headers, $now ) );
	}

	public function test_rejects_tampered_body(): void {
		$body    = '{"success":true,"data":{"valid":true}}';
		$now     = 1_700_000_000;
		$headers = $this->sign( $body, $now * 1000 );

		$verifier = new SignatureVerifier( $this->public_b64 );

		$this->assertFalse(
			$verifier->verify( '{"success":true,"data":{"valid":false}}', $headers, $now )
		);
	}

	public function test_rejects_stale_timestamp_replay(): void {
		$body    = '{"success":true,"data":{"valid":true}}';
		$signed  = 1_700_000_000;
		$headers = $this->sign( $body, $signed * 1000 );

		$verifier = new SignatureVerifier( $this->public_b64 );

		// 10 minutes later — beyond MAX_SKEW.
		$this->assertFalse( $verifier->verify( $body, $headers, $signed + 600 ) );
	}

	public function test_rejects_missing_signature_headers(): void {
		$verifier = new SignatureVerifier( $this->public_b64 );

		$this->assertFalse( $verifier->verify( '{}', array(), 1_700_000_000 ) );
	}

	public function test_rejects_wrong_public_key(): void {
		$body    = '{"success":true,"data":{"valid":true}}';
		$now     = 1_700_000_000;
		$headers = $this->sign( $body, $now * 1000 );

		$other    = base64_encode( sodium_crypto_sign_publickey( sodium_crypto_sign_keypair() ) );
		$verifier = new SignatureVerifier( $other );

		$this->assertFalse( $verifier->verify( $body, $headers, $now ) );
	}

	public function test_diagnoses_a_wrong_site_clock(): void {
		$body    = '{"success":true,"data":{"valid":true}}';
		$signed  = 1_700_000_000;
		$headers = $this->sign( $body, $signed * 1000 );

		$verifier = new SignatureVerifier( $this->public_b64 );

		// Site two hours behind the server.
		$this->assertFalse( $verifier->verify( $body, $headers, $signed - 7200 ) );
		$this->assertSame(
			array(
				'reason'     => SignatureVerifier::DIAGNOSIS_CLOCK_SKEW,
				'clock_skew' => -7200,
			),
			$verifier->diagnose( $body, $headers, $signed - 7200 )
		);
	}

	public function test_a_forged_timestamp_is_not_diagnosed_as_clock_skew(): void {
		$body    = '{"success":true,"data":{"valid":true}}';
		$signed  = 1_700_000_000;
		$headers = $this->sign( $body, $signed * 1000 );

		// Timestamp rewritten after signing: the signature no longer covers it.
		$headers['X-MDS-Timestamp'] = (string) ( ( $signed + 7200 ) * 1000 );

		$verifier = new SignatureVerifier( $this->public_b64 );

		$this->assertSame(
			array( 'reason' => SignatureVerifier::DIAGNOSIS_INVALID ),
			$verifier->diagnose( $body, $headers, $signed )
		);
	}

	public function test_diagnoses_missing_and_tampered_signatures(): void {
		$body    = '{"success":true,"data":{"valid":true}}';
		$now     = 1_700_000_000;
		$headers = $this->sign( $body, $now * 1000 );

		$verifier = new SignatureVerifier( $this->public_b64 );

		$this->assertSame( SignatureVerifier::DIAGNOSIS_MISSING, $verifier->diagnose( $body, array(), $now )['reason'] );
		$this->assertSame( SignatureVerifier::DIAGNOSIS_INVALID, $verifier->diagnose( '{"tampered":1}', $headers, $now )['reason'] );
	}
}
