<?php
/**
 * Admin notices for license problems.
 *
 * @package MeuMouse\MDS\SDK
 */

namespace MeuMouse\MDS\SDK\Admin;

use MeuMouse\MDS\SDK\Config\Product;
use MeuMouse\MDS\SDK\License\LicenseStatus;
use MeuMouse\MDS\SDK\License\Manager as LicenseManager;

defined( 'ABSPATH' ) || exit;

/**
 * Surfaces a dismissible-free, actionable notice when the license is missing,
 * invalid or expired, linking to the settings screen.
 */
final class Notices {

	/** @var Product */
	private $product;

	/** @var LicenseManager */
	private $license;

	/** @var string */
	private $settings_url;

	/**
	 * @param Product        $product      Product context.
	 * @param LicenseManager $license      License manager.
	 * @param string         $settings_url URL to the license settings screen.
	 */
	public function __construct( Product $product, LicenseManager $license, $settings_url ) {
		$this->product      = $product;
		$this->license      = $license;
		$this->settings_url = (string) $settings_url;
	}

	/**
	 * @return void
	 */
	public function register() {
		add_action( 'admin_notices', array( $this, 'maybe_render' ) );
	}

	/**
	 * @return void
	 */
	public function maybe_render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Only nag where it is relevant: our own screen plus the plugins/updates list.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$where  = $screen ? $screen->id : '';

		if ( ! in_array( $where, array( 'plugins', 'update-core', 'dashboard' ), true ) ) {
			return;
		}

		$status = $this->license->status();
		$valid  = $this->license->is_active();

		if ( $valid ) {
			return;
		}

		$message = $this->message_for( $status );

		printf(
			'<div class="notice notice-warning"><p><strong>%s:</strong> %s <a href="%s">%s</a></p></div>',
			esc_html( $this->product->item_name() ),
			esc_html( $message ),
			esc_url( $this->settings_url ),
			esc_html__( 'Manage license', 'mds-sdk' )
		);
	}

	/**
	 * @param LicenseStatus $status Current status.
	 * @return string
	 */
	private function message_for( LicenseStatus $status ) {
		if ( ! $this->license->has_key() ) {
			return __( 'Enter your license key to enable updates and support.', 'mds-sdk' );
		}

		switch ( $status->status() ) {
			case LicenseStatus::STATUS_EXPIRED:
				return __( 'Your license has expired. Renew it to keep receiving updates.', 'mds-sdk' );
			case LicenseStatus::STATUS_INVALID:
				return __( 'Your license is invalid for this site. Please check it.', 'mds-sdk' );
			default:
				return __( 'Your license could not be verified. Updates are paused.', 'mds-sdk' );
		}
	}
}
