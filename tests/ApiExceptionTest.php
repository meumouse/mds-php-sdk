<?php
/**
 * Tests for how an API failure is narrowed down to a cause the admin can act on.
 *
 * @package MeuMouse\MDS\SDK
 */

namespace MeuMouse\MDS\SDK\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use MeuMouse\MDS\SDK\Api\ApiException;
use PHPUnit\Framework\TestCase;

final class ApiExceptionTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'human_time_diff' )->alias(
			static function ( $from, $to ) {
				return ( (int) $to - (int) $from ) . ' seconds';
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @dataProvider transport_failures
	 *
	 * @param string $message       WP_Error message.
	 * @param string $wp_error_code WP_Error code.
	 * @param string $expected      Expected reason.
	 */
	public function test_a_transport_failure_is_classified( string $message, string $wp_error_code, string $expected ): void {
		$e = new ApiException( $message, 'TRANSPORT_ERROR', 0, true, array( 'wp_error_code' => $wp_error_code ) );

		$this->assertSame( $expected, $e->reason() );
		$this->assertTrue( $e->is_transient() );
	}

	/**
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public function transport_failures(): array {
		return array(
			'missing ca bundle'  => array( 'cURL error 60: SSL certificate problem: unable to get local issuer certificate', 'http_request_failed', ApiException::REASON_SSL ),
			'tls handshake'      => array( 'cURL error 35: OpenSSL SSL_connect: SSL_ERROR_SYSCALL', 'http_request_failed', ApiException::REASON_SSL ),
			'timeout'            => array( 'cURL error 28: Operation timed out after 10001 milliseconds', 'http_request_failed', ApiException::REASON_TIMEOUT ),
			'dns'                => array( 'cURL error 6: Could not resolve host: cloud.example.com', 'http_request_failed', ApiException::REASON_DNS ),
			'refused'            => array( 'cURL error 7: Failed to connect to cloud.example.com port 443', 'http_request_failed', ApiException::REASON_CONNECTION ),
			'blocked by wp'      => array( 'User has blocked requests through HTTP.', 'http_request_not_executed', ApiException::REASON_BLOCKED ),
			'streams ssl'        => array( 'SSL: certificate verify failed', 'http_request_failed', ApiException::REASON_SSL ),
			'unknown'            => array( 'Something odd happened', 'http_request_failed', ApiException::REASON_CONNECTION ),
		);
	}

	public function test_a_wrong_clock_is_named_with_its_direction(): void {
		$behind = new ApiException( 'x', 'INVALID_SIGNATURE', 201, false, array( 'signature' => 'clock_skew', 'clock_skew' => -7200 ) );
		$ahead  = new ApiException( 'x', 'INVALID_SIGNATURE', 201, false, array( 'signature' => 'clock_skew', 'clock_skew' => 900 ) );

		$this->assertSame( ApiException::REASON_CLOCK, $behind->reason() );
		$this->assertStringContainsString( '7200 seconds behind', $behind->user_message() );
		$this->assertStringContainsString( '900 seconds ahead', $ahead->user_message() );
	}

	public function test_other_signature_failures_are_told_apart(): void {
		$sodium  = new ApiException( 'x', 'INVALID_SIGNATURE', 200, false, array( 'signature' => 'unsupported' ) );
		$invalid = new ApiException( 'x', 'INVALID_SIGNATURE', 200, false, array( 'signature' => 'invalid' ) );
		$legacy  = new ApiException( 'x', 'INVALID_SIGNATURE', 200 );

		$this->assertSame( ApiException::REASON_SODIUM, $sodium->reason() );
		$this->assertSame( ApiException::REASON_SIGNATURE, $invalid->reason() );
		$this->assertSame( ApiException::REASON_SIGNATURE, $legacy->reason() );
	}

	public function test_http_failures_are_classified(): void {
		$this->assertSame( ApiException::REASON_MALFORMED, ( new ApiException( 'x', 'MALFORMED_RESPONSE', 403 ) )->reason() );
		$this->assertSame( ApiException::REASON_RATE_LIMITED, ( new ApiException( 'x', 'TOO_MANY_REQUESTS', 429 ) )->reason() );
		$this->assertSame( ApiException::REASON_SERVER, ( new ApiException( 'x', 'INTERNAL_SERVER_ERROR', 503 ) )->reason() );
	}

	public function test_an_api_refusal_keeps_the_api_message(): void {
		$e = new ApiException( 'Licença não encontrada', 'LICENSE_NOT_FOUND', 404 );

		$this->assertSame( ApiException::REASON_API, $e->reason() );
		$this->assertFalse( $e->is_transient() );
		$this->assertSame( 'Licença não encontrada', $e->user_message() );
	}

	public function test_a_transport_message_carries_the_raw_error_for_support(): void {
		$e = new ApiException( 'cURL error 60: SSL certificate problem', 'TRANSPORT_ERROR', 0, true );

		$this->assertStringContainsString( 'cURL error 60: SSL certificate problem', $e->user_message() );
	}

	public function test_a_blocked_request_names_the_host_to_allow(): void {
		$e = new ApiException(
			'User has blocked requests through HTTP.',
			'TRANSPORT_ERROR',
			0,
			true,
			array(
				'wp_error_code' => 'http_request_not_executed',
				'host'          => 'cloud.example.com',
			)
		);

		$this->assertStringContainsString( 'Add cloud.example.com to WP_ACCESSIBLE_HOSTS', $e->user_message() );
	}

	public function test_a_server_failure_shows_the_status(): void {
		$e = new ApiException( 'Boom', 'INTERNAL_SERVER_ERROR', 503 );

		$this->assertStringContainsString( '(HTTP 503)', $e->user_message() );
	}
}
