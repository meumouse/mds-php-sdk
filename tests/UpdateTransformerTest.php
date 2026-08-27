<?php
/**
 * Tests for MDS -> WordPress update payload mapping.
 *
 * @package MeuMouse\MDS\SDK
 */

namespace MeuMouse\MDS\SDK\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use MeuMouse\MDS\SDK\Config\Product;
use MeuMouse\MDS\SDK\Updates\UpdateTransformer;
use PHPUnit\Framework\TestCase;

final class UpdateTransformerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'untrailingslashit' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @return Product
	 */
	private function product(): Product {
		return new Product(
			array(
				'product_slug'    => 'flexify-checkout',
				'type'            => 'plugin',
				'file'            => 'flexify-checkout/flexify-checkout.php',
				'current_version' => '1.0.0',
				'api_base_url'    => 'https://api.example.com',
				'api_key'         => 'mds_live_x',
				'public_key'      => 'AAAA',
			)
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function payload(): array {
		return array(
			'update_available' => true,
			'current_version'  => '1.0.0',
			'latest_version'   => '1.2.0',
			'product'          => array(
				'name'     => 'Flexify Checkout',
				'slug'     => 'flexify-checkout',
				'homepage' => 'https://example.com',
			),
			'release'          => array(
				'version'      => '1.2.0',
				'requires'     => '6.0',
				'tested'       => '6.4',
				'requires_php' => '7.4',
				'package_url'  => 'https://api.example.com/v2/download?token=abc',
			),
		);
	}

	public function test_to_plugin_update_maps_core_fields(): void {
		$obj = UpdateTransformer::to_plugin_update( $this->product(), $this->payload() );

		$this->assertSame( 'flexify-checkout', $obj->slug );
		$this->assertSame( 'flexify-checkout/flexify-checkout.php', $obj->plugin );
		$this->assertSame( '1.2.0', $obj->new_version );
		$this->assertSame( 'https://api.example.com/v2/download?token=abc', $obj->package );
		$this->assertSame( '6.0', $obj->requires );
		$this->assertSame( '7.4', $obj->requires_php );
	}

	public function test_to_theme_update_maps_core_fields(): void {
		$product = new Product(
			array(
				'product_slug'    => 'my-theme',
				'type'            => 'theme',
				'file'            => 'my-theme',
				'current_version' => '1.0.0',
				'api_base_url'    => 'https://api.example.com',
				'api_key'         => 'mds_live_x',
				'public_key'      => 'AAAA',
			)
		);

		$arr = UpdateTransformer::to_theme_update( $product, $this->payload() );

		$this->assertSame( 'my-theme', $arr['theme'] );
		$this->assertSame( '1.2.0', $arr['new_version'] );
		$this->assertSame( 'https://api.example.com/v2/download?token=abc', $arr['package'] );
	}

	public function test_treats_a_response_without_an_access_block_as_granted(): void {
		// An older API that sent a `release` sent a package with it.
		$this->assertTrue( UpdateTransformer::downloads_granted( array( 'update_available' => true ) ) );
	}

	public function test_reads_the_download_verdict_from_the_access_block(): void {
		$this->assertTrue(
			UpdateTransformer::downloads_granted(
				array( 'access' => array( 'downloads_granted' => true ) )
			)
		);

		$this->assertFalse(
			UpdateTransformer::downloads_granted(
				array( 'access' => array( 'downloads_granted' => false ) )
			)
		);
	}

	public function test_announces_the_version_without_a_package_when_downloads_are_withheld(): void {
		// The nudge shape: the customer is told a newer build exists and left to
		// renew for it. WordPress renders an empty package as "Automatic update
		// is unavailable", which is the intended outcome rather than a failure.
		$update = UpdateTransformer::to_plugin_update(
			$this->product(),
			array(
				'update_available' => true,
				'latest_version'   => '2.0.0',
				'product'          => array( 'homepage' => 'https://example.com/p' ),
				'access'           => array(
					'updates_granted'   => true,
					'downloads_granted' => false,
				),
			)
		);

		$this->assertSame( '2.0.0', $update->new_version );
		$this->assertSame( '', $update->package );
		$this->assertSame( 'https://example.com/p', $update->url );
	}
}
