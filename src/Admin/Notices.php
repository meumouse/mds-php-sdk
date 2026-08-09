<?php
/**
 * Admin notices for license problems.
 *
 * @package MeuMouse\MDS\SDK
 */

namespace MeuMouse\MDS\SDK\Admin;

use MeuMouse\MDS\SDK\Config\Features;
use MeuMouse\MDS\SDK\Config\Product;
use MeuMouse\MDS\SDK\License\LicenseStatus;
use MeuMouse\MDS\SDK\License\Manager as LicenseManager;

defined( 'ABSPATH' ) || exit;

/**
 * Surfaces an actionable notice when the license is missing, invalid or expired,
 * linking to the settings screen.
 *
 * Everything here is decided at render time rather than at boot: hooking
 * `admin_notices` costs nothing, so the `notices` feature — and the screens,
 * wording and markup below — stay filterable long after `plugins_loaded`,
 * including from a theme.
 */
final class Notices {

	/** Screens the notice appears on unless filtered. */
	const DEFAULT_SCREENS = array( 'plugins', 'update-core', 'dashboard' );

	/** @var Product */
	private $product;

	/** @var LicenseManager */
	private $license;

	/** @var LicenseSettings */
	private $settings;

	/**
	 * @param Product         $product  Product context.
	 * @param LicenseManager  $license  License manager.
	 * @param LicenseSettings $settings License panel, queried lazily for its URL.
	 */
	public function __construct( Product $product, LicenseManager $license, LicenseSettings $settings ) {
		$this->product  = $product;
		$this->license  = $license;
		$this->settings = $settings;
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
		if ( ! $this->product->features()->enabled( Features::NOTICES ) ) {
			return;
		}

		if ( ! current_user_can( $this->product->capability( 'notices' ) ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$where  = $screen ? $screen->id : '';

		/**
		 * Filter the screens the license notice may appear on.
		 *
		 * Only nag where it is relevant. An empty array shows it nowhere; to
		 * show it somewhere else, add that screen id here rather than widening
		 * the list to everything.
		 *
		 * @param array<int,string> $screens Screen ids.
		 * @param Product           $product Product context.
		 */
		$screens = apply_filters( $this->product->key( 'notice_screens' ), self::DEFAULT_SCREENS, $this->product );
		$screens = is_array( $screens ) ? $screens : self::DEFAULT_SCREENS;

		$status = $this->license->status();
		$show   = in_array( $where, $screens, true ) && ! $this->license->is_active();

		/**
		 * Final say on whether the notice renders. Can force it on as well as off.
		 *
		 * @param bool          $show   Decision so far.
		 * @param LicenseStatus $status Current license status.
		 * @param string        $screen Current screen id.
		 */
		$show = (bool) apply_filters( $this->product->key( 'should_show_notice' ), $show, $status, $where );

		if ( ! $show ) {
			return;
		}

		/**
		 * Filter the notice text.
		 *
		 * @param string        $message Default message for the current status.
		 * @param LicenseStatus $status  Current license status.
		 * @param Product       $product Product context.
		 */
		$message = (string) apply_filters(
			$this->product->key( 'notice_message' ),
			$this->message_for( $status ),
			$status,
			$this->product
		);

		/**
		 * Filter the notice presentation.
		 *
		 * @param array<string,mixed> $args   { type, dismissible, link_text, link_url }.
		 * @param LicenseStatus       $status Current license status.
		 */
		$args = apply_filters(
			$this->product->key( 'notice_args' ),
			array(
				'type'        => 'warning',
				'dismissible' => false,
				'link_text'   => __( 'Manage license', 'mds-sdk' ),
				'link_url'    => $this->settings->settings_url(),
			),
			$status
		);

		$args = is_array( $args ) ? $args : array();
		$type = isset( $args['type'] ) ? (string) $args['type'] : 'warning';
		$type = in_array( $type, array( 'error', 'warning', 'success', 'info' ), true ) ? $type : 'warning';

		$link_text = isset( $args['link_text'] ) ? (string) $args['link_text'] : '';
		$link_url  = isset( $args['link_url'] ) ? (string) $args['link_url'] : '';
		$classes   = 'notice notice-' . $type . ( empty( $args['dismissible'] ) ? '' : ' is-dismissible' );

		printf(
			'<div class="%s"><p><strong>%s:</strong> %s%s</p></div>',
			esc_attr( $classes ),
			esc_html( $this->product->item_name() ),
			esc_html( $message ),
			'' !== $link_url && '' !== $link_text
				? sprintf( ' <a href="%s">%s</a>', esc_url( $link_url ), esc_html( $link_text ) )
				: ''
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
