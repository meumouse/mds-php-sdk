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
}
