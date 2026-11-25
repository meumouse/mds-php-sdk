<?php
/**
 * Tests for what a heartbeat does to a stored license.
 *
 * The line that matters is between an answer about the license and a failure of
 * the conversation. Only the API can say a license is no good; a CDN error page,
 * a rate limit or an unverifiable body say nothing about it, and must leave a
 * paying customer's status alone for as long as the grace period lasts.
 *
 * @package MeuMouse\MDS\SDK
 */

namespace MeuMouse\MDS\SDK\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use MeuMouse\MDS\SDK\Api\ApiException;
use MeuMouse\MDS\SDK\Api\Client;
use MeuMouse\MDS\SDK\Config\Product;
use MeuMouse\MDS\SDK\License\LicenseStatus;
use MeuMouse\MDS\SDK\License\Manager;
use MeuMouse\MDS\SDK\Security\SignatureVerifier;
use MeuMouse\MDS\SDK\Support\Logger;
use PHPUnit\Framework\TestCase;

final class LicenseValidationTest extends TestCase {

	/** @var array<string,mixed> Stored WordPress options for the case at hand. */
	private $options = array();

	/** @var mixed What `wp_remote_request` answers. */
	private $response;

	/** @var string Raw ed25519 secret key the fake server signs with. */
	private $secret_key;

	/** @var string Base64 public key the SDK verifies against. */
	private $public_key;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$pair             = sodium_crypto_sign_keypair();
		$this->secret_key = sodium_crypto_sign_secretkey( $pair );
		$this->public_key = base64_encode( sodium_crypto_sign_publickey( $pair ) );

		$options  = &$this->options;
		$response = &$this->response;

		Functions\when( 'untrailingslashit' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
		Functions\when( 'determine_locale' )->justReturn( 'pt_BR' );
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'is_wp_error' )->alias(
			static function ( $value ) {
				return is_object( $value );
			}
		);
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) use ( &$options ) {
				return array_key_exists( $name, $options ) ? $options[ $name ] : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value ) use ( &$options ) {
				$options[ $name ] = $value;

				return true;
			}
		);
		Functions\when( 'wp_remote_request' )->alias(
			static function () use ( &$response ) {
				return $response;
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static function ( $answer ) {
				return $answer['code'];
			}
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static function ( $answer ) {
				return $answer['body'];
			}
		);
		Functions\when( 'wp_remote_retrieve_headers' )->alias(
			static function ( $answer ) {
				return $answer['headers'];
			}
		);

		$this->options['mds_acme_license_key'] = 'MDS-AAAA-BBBB-CCCC';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @dataProvider failures_that_say_nothing_about_the_license
	 *
	 * @param mixed $answer HTTP answer.
	 */
	public function test_a_failure_keeps_the_license_during_the_grace_period( $answer ): void {
		$last_success = time() - DAY_IN_SECONDS;
		$this->store_valid_license( $last_success );
		$this->response = $answer;

		$manager = $this->manager();
		$manager->validate();

		$status = $manager->status();

		$this->assertSame( LicenseStatus::STATUS_ACTIVE, $status->status() );
		$this->assertTrue( $status->is_valid() );
		$this->assertSame( $last_success, $status->last_success_at() );
		$this->assertTrue( $manager->allows_updates() );
		$this->assertTrue( $manager->allows_downloads() );
	}

	/**
	 * @dataProvider failures_that_say_nothing_about_the_license
	 *
	 * @param mixed $answer HTTP answer.
	 */
	public function test_the_grace_period_still_runs_out( $answer ): void {
		$this->store_valid_license( time() - ( 15 * DAY_IN_SECONDS ) );
		$this->response = $answer;

		$manager = $this->manager();
		$manager->validate();

		$this->assertSame( LicenseStatus::STATUS_UNKNOWN, $manager->status()->status() );
		$this->assertFalse( $manager->is_active() );
	}

	/**
	 * @return array<string,array{0:mixed}>
	 */
	public function failures_that_say_nothing_about_the_license(): array {
		$timeout = new class() {
			/** @return string */
			public function get_error_message() {
				return 'cURL error 28';
			}
		};

		return array(
			'timeout'            => array( $timeout ),
			'rate limit'         => array( self::answer( 429, '{"success":false,"error":{"code":"TOO_MANY_REQUESTS","message":"Slow down"}}' ) ),
			'api error'          => array( self::answer( 500, '{"success":false,"error":{"code":"INTERNAL_SERVER_ERROR","message":"Boom"}}' ) ),
			'cdn error page'     => array( self::answer( 502, '<html>Bad gateway</html>' ) ),
			'waf challenge page' => array( self::answer( 403, '<html>Just a moment...</html>' ) ),
			'waf error in json'  => array( self::answer( 403, '{"title":"Error 1020: Access denied","status":403}' ) ),
			'unsigned success'   => array( self::answer( 200, '{"success":true,"data":{"valid":true,"license_status":"active"}}' ) ),
		);
	}

	/**
	 * @dataProvider refusals_from_the_api
	 *
	 * @param string $code     API error code.
	 * @param int    $http     HTTP status.
	 * @param string $expected Stored status.
	 */
	public function test_a_refusal_from_the_api_is_stored_at_once( string $code, int $http, string $expected ): void {
		$this->store_valid_license( time() - HOUR_IN_SECONDS );
		$this->response = self::answer( $http, sprintf( '{"success":false,"error":{"code":"%s","message":"No"}}', $code ) );

		$manager = $this->manager();
		$manager->validate();

		$this->assertSame( $expected, $manager->status()->status() );
		$this->assertFalse( $manager->is_active() );
	}

	/**
	 * @return array<string,array{0:string,1:int,2:string}>
	 */
	public function refusals_from_the_api(): array {
		return array(
			'banned'  => array( 'LICENSE_BANNED', 403, LicenseStatus::STATUS_INVALID ),
			'expired' => array( 'LICENSE_EXPIRED', 403, LicenseStatus::STATUS_EXPIRED ),
		);
	}

	public function test_a_signed_verdict_is_stored_with_its_reason(): void {
		$this->store_valid_license( time() - HOUR_IN_SECONDS );
		$this->response = $this->signed( '{"success":true,"data":{"valid":false,"license_status":"active","reason":"domain_not_activated","updates_allowed":false,"downloads_allowed":false}}' );

		$manager = $this->manager();
		$manager->validate();

		$this->assertFalse( $manager->is_active() );
		$this->assertFalse( $manager->allows_updates() );
		$this->assertSame( 'domain_not_activated', $manager->status()->get( 'reason' ) );
	}

	public function test_activation_asks_to_try_again_instead_of_refusing_the_key(): void {
		$this->store_valid_license( time() - HOUR_IN_SECONDS );
		$this->response = self::answer( 429, '{"success":false,"error":{"code":"TOO_MANY_REQUESTS","message":"Slow down"}}' );

		$manager = $this->manager();

		try {
			$manager->activate( 'MDS-DDDD-EEEE-FFFF' );
			$this->fail( 'A transient failure must surface as an exception.' );
		} catch ( ApiException $e ) {
			$this->assertTrue( $e->is_transient() );
		}

		$this->assertSame( 'MDS-AAAA-BBBB-CCCC', $manager->get_key() );
		$this->assertTrue( $manager->status()->is_valid() );
	}

	public function test_activation_names_a_wrong_site_clock(): void {
		// Genuine answer from a server two hours ahead: the site's clock is behind.
		$this->response = $this->signed( '{"success":true,"data":{"id":"act_1"}}', 2 * HOUR_IN_SECONDS );

		$manager = $this->manager();

		try {
			$manager->activate( 'MDS-DDDD-EEEE-FFFF' );
			$this->fail( 'An unverifiable answer must surface as an exception.' );
		} catch ( ApiException $e ) {
			$this->assertTrue( $e->is_transient() );
			$this->assertSame( ApiException::REASON_CLOCK, $e->reason() );
			$this->assertEqualsWithDelta( -2 * HOUR_IN_SECONDS, $e->context()['clock_skew'], 5 );
		}

		$this->assertSame( 'MDS-AAAA-BBBB-CCCC', $manager->get_key() );
	}

	public function test_activation_names_a_tls_failure_and_the_host(): void {
		$this->response = new class() {
			/** @return string */
			public function get_error_code() {
				return 'http_request_failed';
			}

			/** @return string */
			public function get_error_message() {
				return 'cURL error 60: SSL certificate problem: unable to get local issuer certificate';
			}
		};

		try {
			$this->manager()->activate( 'MDS-DDDD-EEEE-FFFF' );
			$this->fail( 'A transport failure must surface as an exception.' );
		} catch ( ApiException $e ) {
			$this->assertSame( ApiException::REASON_SSL, $e->reason() );
			$this->assertSame( 'mds.example.com', $e->context()['host'] );
		}
	}

	public function test_activation_stores_a_refusal_from_the_api(): void {
		$this->response = self::answer( 404, '{"success":false,"error":{"code":"LICENSE_NOT_FOUND","message":"Not found"}}' );

		$manager = $this->manager();
		$status  = $manager->activate( 'MDS-DDDD-EEEE-FFFF' );

		$this->assertSame( LicenseStatus::STATUS_INVALID, $status->status() );
		$this->assertSame( 'MDS-AAAA-BBBB-CCCC', $manager->get_key() );
	}

	/**
	 * @param int    $code HTTP status.
	 * @param string $body Response body.
	 * @return array<string,mixed>
	 */
	private static function answer( int $code, string $body ): array {
		return array(
			'code'    => $code,
			'body'    => $body,
			'headers' => array(),
		);
	}

	/**
	 * A 200 signed the way the API signs it.
	 *
	 * @param string $body          Response body.
	 * @param int    $server_offset Seconds the server's clock is ahead of the site's.
	 * @return array<string,mixed>
	 */
	private function signed( string $body, int $server_offset = 0 ): array {
		$timestamp = (string) ( ( time() + $server_offset ) * 1000 );
		$nonce     = 'nonce';
		$signature = sodium_crypto_sign_detached(
			$timestamp . '.' . $nonce . '.' . hash( 'sha256', $body ),
			$this->secret_key
		);

		return array(
			'code'    => 200,
			'body'    => $body,
			'headers' => array(
				'x-mds-timestamp' => $timestamp,
				'x-mds-nonce'     => $nonce,
				'x-mds-signature' => base64_encode( $signature ),
			),
		);
	}

	/**
	 * @param int $last_success When the server last answered.
	 */
	private function store_valid_license( int $last_success ): void {
		$this->options['mds_acme_license_state'] = ( new LicenseStatus(
			array(
				'status'          => LicenseStatus::STATUS_ACTIVE,
				'valid'           => true,
				'checked_at'      => $last_success,
				'last_success_at' => $last_success,
				'signed'          => true,
				'extra'           => array(
					'updates_allowed'   => true,
					'downloads_allowed' => true,
				),
			)
		) )->to_array();
	}

	private function manager(): Manager {
		$product = new Product(
			array(
				'product_slug'    => 'acme',
				'file'            => 'acme/acme.php',
				'current_version' => '1.0.0',
				'api_base_url'    => 'https://mds.example.com',
				'api_key'         => 'mds_test',
				'public_key'      => $this->public_key,
			)
		);
		$logger  = new Logger( 'acme' );

		return new Manager( $product, new Client( $product, new SignatureVerifier( $this->public_key ), $logger ), $logger );
	}
}
