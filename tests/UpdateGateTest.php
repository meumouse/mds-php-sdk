<?php
/**
 * Tests for the license gate in front of the update check.
 *
 * Covers the two shapes a product can take: the licensed default, where an
 * inactive license must stop the request before it leaves the site, and a free
 * product (`updates_only`), where the check goes out with no `license_key` at
 * all.
 *
 * @package MeuMouse\MDS\SDK
 */

namespace MeuMouse\MDS\SDK\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use MeuMouse\MDS\SDK\Api\Client;
use MeuMouse\MDS\SDK\Config\Features;
use MeuMouse\MDS\SDK\Config\Product;
use MeuMouse\MDS\SDK\License\Manager as LicenseManager;
use MeuMouse\MDS\SDK\Security\SignatureVerifier;
use MeuMouse\MDS\SDK\Support\Cache;
use MeuMouse\MDS\SDK\Support\Logger;
use MeuMouse\MDS\SDK\Updates\AbstractUpdater;
use PHPUnit\Framework\TestCase;

final class UpdateGateTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'untrailingslashit' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) {
				return $default;
			}
		);
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'get_bloginfo' )->justReturn( '6.4' );
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_inactive_license_stops_the_request_before_it_leaves(): void {
		Functions\expect( 'wp_remote_request' )->never();

		$updater = $this->updater( array( 'mode' => Features::MODE_FULL ) );

		$this->assertNull( $updater->fetch() );
	}

	public function test_updates_only_product_checks_without_a_license_key(): void {
		$captured = array();

		Functions\expect( 'wp_remote_request' )
			->once()
			->andReturnUsing(
				static function ( $url, $args ) use ( &$captured ) {
					$captured = json_decode( $args['body'], true );

					return array();
				}
			);

		$this->stub_error_response();

		$updater = $this->updater( array( 'mode' => Features::MODE_UPDATES_ONLY ) );
		$updater->fetch();

		$this->assertArrayNotHasKey( 'license_key', $captured );
		$this->assertSame( 'free-plugin', $captured['product_slug'] );
		$this->assertSame( '1.0.0', $captured['current_version'] );
	}

	public function test_gate_can_be_dropped_while_licensing_stays_on(): void {
		Functions\expect( 'wp_remote_request' )->once()->andReturn( array() );
		$this->stub_error_response();

		$updater = $this->updater(
			array(
				'mode'     => Features::MODE_FULL,
				'features' => array( Features::LICENSE_GATE_UPDATES => false ),
			)
		);

		// No license stored, yet the check still goes out.
		$this->assertNull( $updater->fetch() );
	}

	/* -------------------------------------------------------------------- */
	/* Helpers                                                               */
	/* -------------------------------------------------------------------- */

	/**
	 * Make the API answer with a plain error envelope, which the client turns
	 * into a non-transport ApiException before any signature check runs.
	 *
	 * @return void
	 */
	private function stub_error_response(): void {
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 403 );
		Functions\when( 'wp_remote_retrieve_headers' )->justReturn( array() );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn(
			'{"success":false,"error":{"code":"FORBIDDEN","message":"nope"}}'
		);
	}

	/**
	 * Build a concrete updater exposing the protected fetch.
	 *
	 * @param array $config Extra product configuration.
	 * @return AbstractUpdater&object{fetch: callable}
	 */
	private function updater( array $config ) {
		$product = new Product(
			array_merge(
				array(
					'product_slug'    => Features::MODE_UPDATES_ONLY === ( $config['mode'] ?? '' ) ? 'free-plugin' : 'paid-plugin',
					'type'            => 'plugin',
					'file'            => 'p/p.php',
					'current_version' => '1.0.0',
					'api_base_url'    => 'https://api.example.com',
					'api_key'         => 'mds_live_x',
					'public_key'      => base64_encode( str_repeat( "\0", 32 ) ),
				),
				$config
			)
		);

		$logger  = new Logger( $product->slug(), $product->features() );
		$client  = new Client( $product, new SignatureVerifier( $product->public_key() ), $logger );
		$license = new LicenseManager( $product, $client, $logger );

		return new class( $product, $client, $license, new Cache( $product ), $logger ) extends AbstractUpdater {

			public function register() {
			}

			/**
			 * @return array|null
			 */
			public function fetch() {
				return $this->get_update_data( false );
			}
		};
	}
}
