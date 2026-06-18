<?php
/**
 * Theme update integration with WordPress core.
 *
 * @package MeuMouse\MDS\SDK
 */

namespace MeuMouse\MDS\SDK\Updates;

defined( 'ABSPATH' ) || exit;

/**
 * Injects MDS update data into the theme update transient.
 */
final class ThemeUpdater extends AbstractUpdater {

	/**
	 * @inheritDoc
	 */
	public function register() {
		add_filter( 'pre_set_site_transient_update_themes', array( $this, 'inject_update' ) );
		add_action( 'upgrader_process_complete', array( $this, 'on_upgrade' ), 10, 2 );
	}

	/**
	 * Add our theme to the core update transient when an update exists.
	 *
	 * @param mixed $transient The `update_themes` transient (object) or empty.
	 * @return mixed
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			$transient = new \stdClass();
		}

		$data       = $this->get_update_data( false );
		$stylesheet = $this->product->file();

		if ( $this->has_update( $data ) ) {
			if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
				$transient->response = array();
			}

			$transient->response[ $stylesheet ] = UpdateTransformer::to_theme_update( $this->product, $data );

			if ( isset( $transient->no_update[ $stylesheet ] ) ) {
				unset( $transient->no_update[ $stylesheet ] );
			}
		} else {
			if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
				$transient->no_update = array();
			}

			$transient->no_update[ $stylesheet ] = array(
				'theme'       => $stylesheet,
				'new_version' => $this->product->current_version(),
				'url'         => '',
				'package'     => '',
			);
		}

		return $transient;
	}

	/**
	 * Clear our cache after a successful update of this theme.
	 *
	 * @param \WP_Upgrader $upgrader Upgrader instance.
	 * @param array        $hook_extra Context about what was upgraded.
	 * @return void
	 */
	public function on_upgrade( $upgrader, $hook_extra ) {
		if ( ! is_array( $hook_extra ) || 'theme' !== ( $hook_extra['type'] ?? '' ) ) {
			return;
		}

		$themes = isset( $hook_extra['themes'] ) ? (array) $hook_extra['themes'] : array();

		if ( in_array( $this->product->file(), $themes, true ) ) {
			$this->clear_cache();
		}
	}
}
