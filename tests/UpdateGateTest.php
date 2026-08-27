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

	/** @var array<string,mixed> Stored WordPress options for the case at hand. */
	private $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'untrailingslashit' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'is_multisite' )->justReturn( false );
		$options = &$this->options;

		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) use ( &$options ) {
				return array_key_exists( $name, $options ) ? $options[ $name ] : $default;
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


	public function test_a_waived_licence_lets_the_request_leave_despite_being_expired(): void {
		// The case the whole waiver mechanism exists for. The licence lapsed, so
		// `is_active()` is false; the server has already said it keeps serving
		// this customer. Deciding locally would refuse the request before the
		// server ever got to honour its own waiver — the request MUST go out.
		$this->store_licence(
			array(
				'status'          => 'expired',
				'valid'           => false,
				'last_success_at' => time(),
				'extra'           => array(
					'updates_allowed'   => true,
					'downloads_allowed' => false,
				),
			)
		);

		Functions\expect( 'wp_remote_request' )->once()->andReturn( array() );
		$this->stub_error_response();

		$updater = $this->updater( array( 'mode' => Features::MODE_FULL ) );

		// The stubbed response is an error envelope, so the payload is null —
		// what this pins is that the call happened at all.
		$this->assertNull( $updater->fetch() );
	}

	public function test_an_expired_licence_with_no_waiver_still_stops_the_request(): void {
		$this->store_licence(
			array(
				'status'          => 'expired',
				'valid'           => false,
				'last_success_at' => time(),
				'extra'           => array(
					'updates_allowed'   => false,
					'downloads_allowed' => false,
				),
			)
		);

		Functions\expect( 'wp_remote_request' )->never();

		$updater = $this->updater( array( 'mode' => Features::MODE_FULL ) );

		$this->assertNull( $updater->fetch() );
	}

	public function test_an_older_server_that_never_answered_falls_back_to_validity(): void {
		// No allowances recorded: the gate reverts to the behaviour that
		// predates them, so an SDK talking to an older API is unaffected.
		$this->store_licence(
			array(
				'status'          => 'active',
				'valid'           => true,
				'last_success_at' => time(),
			)
		);

		Functions\expect( 'wp_remote_request' )->once()->andReturn( array() );
		$this->stub_error_response();

		$updater = $this->updater( array( 'mode' => Features::MODE_FULL ) );

		$this->assertNull( $updater->fetch() );
	}

	public function test_the_licence_key_rides_along_for_a_waived_customer(): void {
		$captured = array();

		$this->store_licence(
			array(
				'status'          => 'expired',
				'valid'           => false,
				'last_success_at' => time(),
				'extra'           => array( 'updates_allowed' => true ),
			)
		);

		Functions\expect( 'wp_remote_request' )
			->once()
			->andReturnUsing(
				static function ( $url, $args ) use ( &$captured ) {
					$captured = json_decode( $args['body'], true );

					return array();
				}
			);

		$this->stub_error_response();

		$updater = $this->updater( array( 'mode' => Features::MODE_FULL ) );
		$updater->fetch();

		// Without the key the server cannot find the licence, and a waiver lives
		// on the licence row — so it cannot be honoured.
		$this->assertSame( 'WAIVED-KEY-0001', $captured['license_key'] );
	}

	/**
	 * Plant a stored licence key and status for the product under test.
	 *
	 * @param array $state Licence state as `Manager` persists it.
	 * @return void
	 */
	private function store_licence( array $state ): void {
		$this->options['mds_paid_plugin_license_key']   = 'WAIVED-KEY-0001';
		$this->options['mds_paid_plugin_license_state'] = $state;
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
