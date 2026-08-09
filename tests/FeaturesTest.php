<?php
/**
 * Tests for feature-flag resolution: presets, overrides, filters, constants
 * and dependency enforcement.
 *
 * @package MeuMouse\MDS\SDK
 */

namespace MeuMouse\MDS\SDK\Tests;

use Brain\Monkey;
use Brain\Monkey\Filters;
use MeuMouse\MDS\SDK\Config\Features;
use PHPUnit\Framework\TestCase;

final class FeaturesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_default_mode_is_full_and_keeps_every_module_on(): void {
		$features = new Features( 'my-plugin' );

		$this->assertSame( Features::MODE_FULL, $features->mode() );
		$this->assertTrue( $features->enabled( Features::LICENSE ) );
		$this->assertTrue( $features->enabled( Features::UPDATES ) );
		$this->assertTrue( $features->enabled( Features::ROLLBACK ) );
		$this->assertTrue( $features->enabled( Features::HEARTBEAT ) );
		$this->assertTrue( $features->enabled( Features::NOTICES ) );
		$this->assertTrue( $features->enabled( Features::LICENSE_GATE_UPDATES ) );
		$this->assertSame( array(), $features->conflicts() );
	}

	public function test_unknown_mode_falls_back_to_full(): void {
		$features = new Features( 'my-plugin', array( 'mode' => 'nonsense' ) );

		$this->assertSame( Features::MODE_FULL, $features->mode() );
		$this->assertTrue( $features->enabled( Features::UPDATES ) );
	}

	public function test_license_only_mode_drops_updates_and_rollback(): void {
		$features = new Features( 'my-plugin', array( 'mode' => Features::MODE_LICENSE_ONLY ) );

		$this->assertTrue( $features->enabled( Features::LICENSE ) );
		$this->assertTrue( $features->enabled( Features::HEARTBEAT ) );
		$this->assertTrue( $features->enabled( Features::NOTICES ) );
		$this->assertFalse( $features->enabled( Features::UPDATES ) );
		$this->assertFalse( $features->enabled( Features::UPDATE_DETAILS ) );
		$this->assertFalse( $features->enabled( Features::ROLLBACK ) );
	}

	public function test_updates_only_mode_drops_licensing_entirely(): void {
		$features = new Features( 'my-plugin', array( 'mode' => Features::MODE_UPDATES_ONLY ) );

		$this->assertTrue( $features->enabled( Features::UPDATES ) );
		$this->assertTrue( $features->enabled( Features::UPDATE_DETAILS ) );
		$this->assertFalse( $features->enabled( Features::LICENSE ) );
		$this->assertFalse( $features->enabled( Features::LICENSE_GATE_UPDATES ) );
		$this->assertFalse( $features->enabled( Features::HEARTBEAT ) );
		$this->assertFalse( $features->enabled( Features::NOTICES ) );
		$this->assertFalse( $features->enabled( Features::ADMIN_MENU ) );
	}

	public function test_explicit_features_override_the_preset(): void {
		$features = new Features(
			'my-plugin',
			array(
				'mode'     => Features::MODE_FULL,
				'features' => array(
					Features::NOTICES  => false,
					Features::ROLLBACK => false,
				),
			)
		);

		$this->assertFalse( $features->enabled( Features::NOTICES ) );
		$this->assertFalse( $features->enabled( Features::ROLLBACK ) );
		$this->assertTrue( $features->enabled( Features::UPDATES ) );
		$this->assertTrue( $features->enabled( Features::LICENSE ) );
	}

	public function test_unknown_feature_keys_are_ignored(): void {
		$features = new Features(
			'my-plugin',
			array( 'features' => array( 'teleportation' => true ) )
		);

		$this->assertFalse( $features->enabled( 'teleportation' ) );
		$this->assertArrayNotHasKey( 'teleportation', $features->all() );
	}

	public function test_a_feature_is_off_when_its_dependency_is_off(): void {
		$features = new Features(
			'my-plugin',
			array(
				'features' => array(
					Features::LICENSE   => false,
					Features::HEARTBEAT => true,
					Features::NOTICES   => true,
				),
			)
		);

		$this->assertFalse( $features->enabled( Features::HEARTBEAT ) );
		$this->assertFalse( $features->enabled( Features::NOTICES ) );
	}

	public function test_dependency_chain_is_walked_transitively(): void {
		// admin_menu -> admin_license_panel -> license
		$features = new Features(
			'my-plugin',
			array(
				'features' => array(
					Features::ADMIN_LICENSE_PANEL => false,
					Features::ADMIN_MENU          => true,
				),
			)
		);

		$this->assertFalse( $features->enabled( Features::ADMIN_MENU ) );
	}

	public function test_conflicts_are_reported_for_the_debug_log(): void {
		$features = new Features(
			'my-plugin',
			array(
				'features' => array(
					Features::LICENSE   => false,
					Features::HEARTBEAT => true,
				),
			)
		);

		$this->assertContains(
			array(
				'feature'  => Features::HEARTBEAT,
				'requires' => Features::LICENSE,
			),
			$features->conflicts()
		);
	}

	public function test_bulk_filter_overrides_the_configuration(): void {
		Filters\expectApplied( 'mds_sdk_features' )
			->zeroOrMoreTimes()
			->andReturnUsing(
				static function ( array $features ) {
					$features[ Features::UPDATES ] = false;

					return $features;
				}
			);

		$features = new Features( 'my-plugin' );

		$this->assertFalse( $features->enabled( Features::UPDATES ) );
		$this->assertTrue( $features->enabled( Features::LICENSE ) );
	}

	public function test_per_feature_filter_is_named_after_the_slug(): void {
		Filters\expectApplied( 'mds_my_plugin_feature_notices' )
			->zeroOrMoreTimes()
			->andReturn( false );

		$features = new Features( 'my-plugin' );

		$this->assertFalse( $features->enabled( Features::NOTICES ) );
		$this->assertTrue( $features->enabled( Features::UPDATES ) );
	}

	public function test_constant_beats_the_filter(): void {
		define( 'MDS_CONST_DEMO_FEATURE_UPDATES', false );

		Filters\expectApplied( 'mds_const_demo_feature_updates' )
			->zeroOrMoreTimes()
			->andReturn( true );

		$features = new Features( 'const-demo' );

		$this->assertFalse( $features->enabled( Features::UPDATES ) );
	}

	/**
	 * A global constant would otherwise leak into every later test in the run.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_global_constant_applies_to_any_product(): void {
		// Logging defaults to WP_DEBUG, which the test bootstrap leaves undefined.
		define( 'MDS_SDK_FEATURE_LOGGING', true );

		$this->assertTrue( ( new Features( 'one-product' ) )->enabled( Features::LOGGING ) );
		$this->assertTrue( ( new Features( 'another-product' ) )->enabled( Features::LOGGING ) );
	}

	public function test_active_lists_only_enabled_features(): void {
		$features = new Features( 'my-plugin', array( 'mode' => Features::MODE_UPDATES_ONLY ) );
		$active   = $features->active();

		$this->assertContains( Features::UPDATES, $active );
		$this->assertNotContains( Features::LICENSE, $active );
	}
}
