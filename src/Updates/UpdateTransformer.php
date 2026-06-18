<?php
/**
 * Translates MDS update-check payloads into the shapes WordPress core expects.
 *
 * @package MeuMouse\MDS\SDK
 */

namespace MeuMouse\MDS\SDK\Updates;

use MeuMouse\MDS\SDK\Config\Product;

defined( 'ABSPATH' ) || exit;

/**
 * Pure mapping helpers (no side effects), kept separate so they are trivial to
 * unit test against fixture payloads.
 */
final class UpdateTransformer {

	/**
	 * Build the object stored in the plugin update transient `response` array.
	 *
	 * @param Product $product Product context.
	 * @param array   $data    Update-check `data` payload (update_available=true).
	 * @return \stdClass
	 */
	public static function to_plugin_update( Product $product, array $data ) {
		$release = isset( $data['release'] ) && is_array( $data['release'] ) ? $data['release'] : array();
		$info    = isset( $data['product'] ) && is_array( $data['product'] ) ? $data['product'] : array();

		$obj = new \stdClass();

		$obj->slug         = $product->slug();
		$obj->plugin       = $product->file();
		$obj->new_version  = isset( $data['latest_version'] ) ? (string) $data['latest_version'] : ( $release['version'] ?? '' );
		$obj->url          = $info['homepage'] ?? '';
		$obj->package      = $release['package_url'] ?? '';
		$obj->tested       = $release['tested'] ?? ( $info['tested'] ?? '' );
		$obj->requires     = $release['requires'] ?? ( $info['requires'] ?? '' );
		$obj->requires_php = $release['requires_php'] ?? ( $info['requires_php'] ?? '' );
		$obj->icons        = self::assets( $release, 'icons' );
		$obj->banners      = self::assets( $release, 'banners' );

		return $obj;
	}

	/**
	 * Build the array stored in the theme update transient `response` array.
	 *
	 * @param Product $product Product context.
	 * @param array   $data    Update-check `data` payload.
	 * @return array<string,string>
	 */
	public static function to_theme_update( Product $product, array $data ) {
		$release = isset( $data['release'] ) && is_array( $data['release'] ) ? $data['release'] : array();
		$info    = isset( $data['product'] ) && is_array( $data['product'] ) ? $data['product'] : array();

		return array(
			'theme'        => $product->file(),
			'new_version'  => isset( $data['latest_version'] ) ? (string) $data['latest_version'] : ( $release['version'] ?? '' ),
			'url'          => $info['homepage'] ?? '',
			'package'      => $release['package_url'] ?? '',
			'requires'     => $release['requires'] ?? '',
			'requires_php' => $release['requires_php'] ?? '',
		);
	}

	/**
	 * Build the object returned to the "View details" (plugins_api) modal.
	 *
	 * @param Product $product Product context.
	 * @param array   $data    Update-check `data` payload.
	 * @return \stdClass
	 */
	public static function to_plugin_information( Product $product, array $data ) {
		$release = isset( $data['release'] ) && is_array( $data['release'] ) ? $data['release'] : array();
		$info    = isset( $data['product'] ) && is_array( $data['product'] ) ? $data['product'] : array();

		$res = new \stdClass();

		$res->name          = $info['name'] ?? $product->item_name();
		$res->slug          = $product->slug();
		$res->version       = isset( $data['latest_version'] ) ? (string) $data['latest_version'] : ( $release['version'] ?? $product->current_version() );
		$res->homepage      = $info['homepage'] ?? '';
		$res->requires      = $release['requires'] ?? ( $info['requires'] ?? '' );
		$res->tested        = $release['tested'] ?? ( $info['tested'] ?? '' );
		$res->requires_php  = $release['requires_php'] ?? ( $info['requires_php'] ?? '' );
		$res->download_link = $release['package_url'] ?? '';
		$res->banners       = self::assets( $release, 'banners' );

		$changelog        = isset( $release['changelog'] ) ? (string) $release['changelog'] : '';
		$res->sections    = array(
			'changelog' => self::format_changelog( $changelog ),
		);

		return $res;
	}

	/**
	 * Normalise an icons/banners map from the release payload.
	 *
	 * @param array  $release Release payload.
	 * @param string $key     "icons" or "banners".
	 * @return array<string,string>
	 */
	private static function assets( array $release, $key ) {
		return isset( $release[ $key ] ) && is_array( $release[ $key ] ) ? $release[ $key ] : array();
	}

	/**
	 * Render a changelog string (markdown-ish) into safe HTML for the modal.
	 *
	 * @param string $changelog Raw changelog.
	 * @return string
	 */
	private static function format_changelog( $changelog ) {
		if ( '' === $changelog ) {
			return '';
		}

		$lines = preg_split( '/\r\n|\r|\n/', $changelog );
		$html  = '';
		$open  = false;

		foreach ( $lines as $line ) {
			$line = trim( $line );

			if ( '' === $line ) {
				continue;
			}

			if ( 0 === strpos( $line, '- ' ) || 0 === strpos( $line, '* ' ) ) {
				if ( ! $open ) {
					$html .= '<ul>';
					$open  = true;
				}

				$html .= '<li>' . esc_html( substr( $line, 2 ) ) . '</li>';
			} else {
				if ( $open ) {
					$html .= '</ul>';
					$open  = false;
				}

				$html .= '<p>' . esc_html( $line ) . '</p>';
			}
		}

		if ( $open ) {
			$html .= '</ul>';
		}

		return $html;
	}
}
