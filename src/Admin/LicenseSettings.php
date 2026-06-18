<?php
/**
 * Reusable license activation panel and its admin actions.
 *
 * @package MeuMouse\MDS\SDK
 */

namespace MeuMouse\MDS\SDK\Admin;

use MeuMouse\MDS\SDK\Api\ApiException;
use MeuMouse\MDS\SDK\Config\Product;
use MeuMouse\MDS\SDK\License\Manager as LicenseManager;
use MeuMouse\MDS\SDK\Rollback\Manager as RollbackManager;
use MeuMouse\MDS\SDK\Updates\AbstractUpdater;

defined( 'ABSPATH' ) || exit;

/**
 * Handles the activate/deactivate/check-now form actions and renders the license
 * panel, which a consumer can either embed in its own settings screen via
 * {@see render()} or expose as an auto-registered submenu.
 */
final class LicenseSettings {

	/** @var Product */
	private $product;

	/** @var LicenseManager */
	private $license;

	/** @var View */
	private $view;

	/** @var AbstractUpdater */
	private $updater;

	/** @var RollbackManager */
	private $rollback;

	/**
	 * @param Product         $product  Product context.
	 * @param LicenseManager  $license  License manager.
	 * @param View            $view     Template renderer.
	 * @param AbstractUpdater $updater  Updater (for cache busting on check-now).
	 * @param RollbackManager $rollback Rollback manager (for cache busting).
	 */
	public function __construct( Product $product, LicenseManager $license, View $view, AbstractUpdater $updater, RollbackManager $rollback ) {
		$this->product  = $product;
		$this->license  = $license;
		$this->view     = $view;
		$this->updater  = $updater;
		$this->rollback = $rollback;
	}

	/**
	 * @return void
	 */
	public function register() {
		add_action( 'admin_post_' . $this->product->key( 'activate' ), array( $this, 'handle_activate' ) );
		add_action( 'admin_post_' . $this->product->key( 'deactivate' ), array( $this, 'handle_deactivate' ) );
		add_action( 'admin_post_' . $this->product->key( 'check' ), array( $this, 'handle_check' ) );

		if ( null !== $this->product->settings_parent() ) {
			add_action( 'admin_menu', array( $this, 'register_menu' ) );
		}
	}

	/**
	 * Register an auto submenu under the configured parent.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			$this->product->settings_parent(),
			sprintf( /* translators: %s: product name */ __( '%s License', 'mds-sdk' ), $this->product->item_name() ),
			__( 'License', 'mds-sdk' ),
			'manage_options',
			$this->page_slug(),
			array( $this, 'render' )
		);
	}

	/**
	 * The admin page slug used by the auto submenu.
	 *
	 * @return string
	 */
	public function page_slug() {
		return $this->product->key( 'license' );
	}

	/**
	 * URL of the license screen (auto submenu when configured).
	 *
	 * @return string
	 */
	public function settings_url() {
		if ( null !== $this->product->settings_parent() ) {
			return admin_url( 'admin.php?page=' . $this->page_slug() );
		}

		return admin_url( 'plugins.php' );
	}

	/**
	 * Render the license panel.
	 *
	 * @return void
	 */
	public function render() {
		$this->view->render(
			'license-settings',
			array(
				'license'     => $this->license,
				'status'      => $this->license->status(),
				'is_active'   => $this->license->is_active(),
				'has_key'     => $this->license->has_key(),
				'action_url'  => admin_url( 'admin-post.php' ),
				'actions'     => array(
					'activate'   => $this->product->key( 'activate' ),
					'deactivate' => $this->product->key( 'deactivate' ),
					'check'      => $this->product->key( 'check' ),
				),
				'notice'      => $this->pull_notice(),
			)
		);
	}

	/* -------------------------------------------------------------------- */
	/* Action handlers                                                       */
	/* -------------------------------------------------------------------- */

	/**
	 * @return void
	 */
	public function handle_activate() {
		$this->guard( 'activate' );

		$key = isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '';

		try {
			$status = $this->license->activate( $key );
			$this->updater->clear_cache();
			$this->rollback->clear_cache();

			$this->set_notice(
				$status->is_valid() ? 'success' : 'error',
				$status->is_valid()
					? __( 'License activated.', 'mds-sdk' )
					: ( '' !== $status->message() ? $status->message() : __( 'License activation failed.', 'mds-sdk' ) )
			);
		} catch ( ApiException $e ) {
			$this->set_notice( 'error', __( 'Could not reach the licensing server. Please try again.', 'mds-sdk' ) );
		}

		$this->redirect_back();
	}

	/**
	 * @return void
	 */
	public function handle_deactivate() {
		$this->guard( 'deactivate' );

		$this->license->deactivate();
		$this->updater->clear_cache();
		$this->rollback->clear_cache();

		$this->set_notice( 'success', __( 'License deactivated for this site.', 'mds-sdk' ) );
		$this->redirect_back();
	}

	/**
	 * @return void
	 */
	public function handle_check() {
		$this->guard( 'check' );

		$this->updater->clear_cache();
		$this->rollback->clear_cache();
		$this->license->validate();

		$this->set_notice( 'success', __( 'License and updates re-checked.', 'mds-sdk' ) );
		$this->redirect_back();
	}

	/* -------------------------------------------------------------------- */
	/* Helpers                                                               */
	/* -------------------------------------------------------------------- */

	/**
	 * Capability + nonce guard shared by all handlers.
	 *
	 * @param string $action Action suffix.
	 * @return void
	 */
	private function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'mds-sdk' ) );
		}

		check_admin_referer( $this->product->key( $action ) );
	}

	/**
	 * @param string $type    "success"|"error".
	 * @param string $message Notice text.
	 * @return void
	 */
	private function set_notice( $type, $message ) {
		set_transient(
			$this->product->key( 'notice_' . get_current_user_id() ),
			array( 'type' => $type, 'message' => $message ),
			60
		);
	}

	/**
	 * @return array<string,string>|null
	 */
	private function pull_notice() {
		$key    = $this->product->key( 'notice_' . get_current_user_id() );
		$notice = get_transient( $key );

		if ( $notice ) {
			delete_transient( $key );

			return is_array( $notice ) ? $notice : null;
		}

		return null;
	}

	/**
	 * @return void
	 */
	private function redirect_back() {
		$referer = wp_get_referer();
		wp_safe_redirect( $referer ? $referer : $this->settings_url() );
		exit;
	}
}
