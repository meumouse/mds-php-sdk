<?php
/**
 * Plugin update integration with WordPress core.
 *
 * @package MeuMouse\MDS\SDK
 */

namespace MeuMouse\MDS\SDK\Updates;

defined( 'ABSPATH' ) || exit;

/**
 * Injects MDS update data into the plugin update transient and powers the
 * "View details" modal via the plugins_api filter.
 */
final class PluginUpdater extends AbstractUpdater {

	/**
	 * @inheritDoc
	 */
	public function register() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_information' ), 20, 3 );

		// Refresh our cache right after this plugin is updated.
		add_action( 'upgrader_process_complete', array( $this, 'on_upgrade' ), 10, 2 );
	}

	/**
	 * Add our plugin to the core update transient when an update exists.
	 *
	 * @param mixed $transient The `update_plugins` transient (object) or empty.
	 * @return mixed
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			$transient = new \stdClass();
		}

		// Core sets `checked` right before this filter runs during an update scan;
		// only then do we spend an API call. Otherwise we serve from cache.
		$data = $this->get_update_data( false );
		$file = $this->product->file();

		if ( $this->has_update( $data ) ) {
			$update = UpdateTransformer::to_plugin_update( $this->product, $data );

			if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
				$transient->response = array();
			}

			$transient->response[ $file ] = $update;

			if ( isset( $transient->no_update[ $file ] ) ) {
				unset( $transient->no_update[ $file ] );
			}
		} else {
			// Advertise "no update" so core stops asking wordpress.org about us.
			$no_update = new \stdClass();

			$no_update->slug        = $this->product->slug();
			$no_update->plugin      = $file;
			$no_update->new_version = $this->product->current_version();
			$no_update->package     = '';
			$no_update->url         = '';

			if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
				$transient->no_update = array();
			}

			$transient->no_update[ $file ] = $no_update;
		}

		return $transient;
	}

	/**
	 * Serve the "View details" modal payload for our plugin slug.
	 *
	 * @param mixed  $result False by default, or another plugin's data.
	 * @param string $action The plugins_api action.
	 * @param object $args   Request args (expects ->slug).
	 * @return mixed
	 */
	public function plugin_information( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( ! isset( $args->slug ) || $args->slug !== $this->product->slug() ) {
			return $result;
		}

		$data = $this->get_update_data( false );

		if ( ! is_array( $data ) ) {
			return $result;
		}

		return UpdateTransformer::to_plugin_information( $this->product, $data );
	}

	/**
	 * Clear our cache after a successful update of this plugin.
	 *
	 * @param \WP_Upgrader $upgrader Upgrader instance.
	 * @param array        $hook_extra Context about what was upgraded.
	 * @return void
	 */
	public function on_upgrade( $upgrader, $hook_extra ) {
		if ( ! is_array( $hook_extra ) || 'plugin' !== ( $hook_extra['type'] ?? '' ) ) {
			return;
		}

		$plugins = isset( $hook_extra['plugins'] ) ? (array) $hook_extra['plugins'] : array();

		if ( in_array( $this->product->file(), $plugins, true ) ) {
			$this->clear_cache();
		}
	}
}
