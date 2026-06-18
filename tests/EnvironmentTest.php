<?php
/**
 * Tests for domain normalisation (must match the MDS API).
 *
 * @package MeuMouse\MDS\SDK
 */

namespace MeuMouse\MDS\SDK\Tests;

use MeuMouse\MDS\SDK\Support\Environment;
use PHPUnit\Framework\TestCase;

final class EnvironmentTest extends TestCase {

	/**
	 * @dataProvider domains
	 *
	 * @param string $input    Raw URL/host.
	 * @param string $expected Normalised domain.
	 */
	public function test_normalize_domain( $input, $expected ): void {
		$this->assertSame( $expected, Environment::normalize_domain( $input ) );
	}

	/**
	 * @return array<int,array{0:string,1:string}>
	 */
	public function domains(): array {
		return array(
			array( 'HTTPS://WWW.EXAMPLE.COM:8080/path?query=1', 'example.com' ),
			array( 'http://example.com', 'example.com' ),
			array( 'https://staging.example.com/wp', 'staging.example.com' ),
			array( 'www.example.com', 'example.com' ),
			array( 'user:pass@example.com:443', 'example.com' ),
			array( '127.0.0.1', '127.0.0.1' ),
			array( 'Example.COM', 'example.com' ),
		);
	}
}
