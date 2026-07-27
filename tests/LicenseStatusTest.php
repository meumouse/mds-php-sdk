<?php
/**
 * Tests for the persisted license status value object.
 *
 * @package MeuMouse\MDS\SDK
 */

namespace MeuMouse\MDS\SDK\Tests;

use MeuMouse\MDS\SDK\License\LicenseStatus;
use PHPUnit\Framework\TestCase;

final class LicenseStatusTest extends TestCase {

	public function test_defaults_to_an_unknown_invalid_status(): void {
		$status = new LicenseStatus();

		$this->assertSame( LicenseStatus::STATUS_UNKNOWN, $status->status() );
		$this->assertFalse( $status->is_valid() );
		$this->assertFalse( $status->is_signed() );
		$this->assertNull( $status->expires_at() );
		$this->assertSame( array(), $status->extra() );
	}

	public function test_round_trips_through_an_array(): void {
		$original = new LicenseStatus(
			array(
				'status'     => LicenseStatus::STATUS_ACTIVE,
				'valid'      => true,
				'domain'     => 'example.com',
				'expires_at' => '2030-01-01T00:00:00.000Z',
				'extra'      => array( 'plan' => 'pro' ),
			)
		);

		$restored = LicenseStatus::from_array( $original->to_array() );

		$this->assertSame( $original->to_array(), $restored->to_array() );
		$this->assertSame( 'pro', $restored->get( 'plan' ) );
	}

	public function test_exposes_server_fields_the_class_does_not_model(): void {
		$status = new LicenseStatus(
			array(
				'extra' => array(
					'plan'               => 'pro',
					'plan_name'          => 'Pro',
					'renew_url'          => 'https://example.com/renew',
					'support_expires_at' => '2029-01-01T00:00:00.000Z',
					'reason'             => null,
				),
			)
		);

		$this->assertSame( 'Pro', $status->get( 'plan_name' ) );
		$this->assertSame( 'https://example.com/renew', $status->get( 'renew_url' ) );
		$this->assertNull( $status->get( 'reason', 'fallback' ), 'an explicit null must win over the default' );
		$this->assertSame( 'fallback', $status->get( 'absent', 'fallback' ) );
	}

	public function test_tolerates_a_corrupt_extra_payload(): void {
		// Options survive plugin downgrades and hand edits, so a non-array here
		// must not fatal a license screen.
		$status = new LicenseStatus( array( 'extra' => 'not-an-array' ) );

		$this->assertSame( array(), $status->extra() );
		$this->assertNull( $status->get( 'plan' ) );
	}

	public function test_reports_expiry_from_the_status_field(): void {
		$expired = new LicenseStatus( array( 'status' => LicenseStatus::STATUS_EXPIRED ) );
		$active  = new LicenseStatus( array( 'status' => LicenseStatus::STATUS_ACTIVE ) );

		$this->assertTrue( $expired->is_expired() );
		$this->assertFalse( $active->is_expired() );
	}
}
