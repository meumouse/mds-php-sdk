<?php
/**
 * Rollback (version downgrade) admin UI and action.
 *
 * @package MeuMouse\MDS\SDK
 */

namespace MeuMouse\MDS\SDK\Admin;

use MeuMouse\MDS\SDK\Config\Product;
use MeuMouse\MDS\SDK\License\Manager as LicenseManager;
use MeuMouse\MDS\SDK\Rollback\Manager as RollbackManager;

defined( 'ABSPATH' ) || exit;

/**
 * Lists available versions and reinstalls a chosen one after capability + nonce
 * checks. Requires the stronger `update_plugins`/`update_themes` capability.
 */
final class RollbackPage {

	/** @var Product */
	private $product;

	/** @var RollbackManager */
	private $rollback;

	/** @var LicenseManager */
	private $license;

	/** @var View */
	private $view;

	/**
	 * @param Product         $product  Product context.
	 * @param RollbackManager $rollback Rollback manager.
	 * @param LicenseManager  $license  License manager.
	 * @param View            $view     Template renderer.
	 */
	public function __construct( Product $product, RollbackManager $rollback, LicenseManager $license, View $view ) {
		$this->product  = $product;
		$this->rollback = $rollback;
		$this->license  = $license;
		$this->view     = $view;
	}

	/**
	 * @return void
	 */
	public function register() {
		add_action( 'admin_post_' . $this->product->key( 'rollback' ), array( $this, 'handle_rollback' ) );
	}

	/**
	 * Required capability for rollback.
	 *
	 * @return string
	 */
	private function capability() {
		return $this->product->is_theme() ? 'update_themes' : 'update_plugins';
	}

	/**
	 * Render the rollback list.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( $this->capability() ) ) {
			return;
		}

		$this->view->render(
			'rollback-page',
			array(
				'versions'        => $this->rollback->list_versions(),
				'current_version' => $this->product->current_version(),
				'is_active'       => $this->license->is_active(),
				'action_url'      => admin_url( 'admin-post.php' ),
				'action'          => $this->product->key( 'rollback' ),
				'nonce_action'    => $this->product->key( 'rollback' ),
			)
		);
	}

	/**
	 * Handle the rollback POST.
	 *
	 * @return void
	 */
	public function handle_rollback() {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'mds-sdk' ) );
		}

		check_admin_referer( $this->product->key( 'rollback' ) );

		$version = isset( $_POST['version'] ) ? sanitize_text_field( wp_unslash( $_POST['version'] ) ) : '';
		$result  = $this->rollback->rollback( $version );

		$type    = is_wp_error( $result ) ? 'error' : 'success';
		$message = is_wp_error( $result )
			? $result->get_error_message()
			: sprintf( /* translators: %s: version */ __( 'Rolled back to version %s.', 'mds-sdk' ), $version );

		set_transient(
			$this->product->key( 'notice_' . get_current_user_id() ),
			array( 'type' => $type, 'message' => $message ),
			60
		);

		$referer = wp_get_referer();
		wp_safe_redirect( $referer ? $referer : admin_url( 'plugins.php' ) );
		exit;
	}
}
