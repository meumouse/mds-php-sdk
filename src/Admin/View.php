<?php
/**
 * Tiny, override-friendly template renderer for admin views.
 *
 * @package MeuMouse\MDS\SDK
 */

namespace MeuMouse\MDS\SDK\Admin;

use MeuMouse\MDS\SDK\Config\Product;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a bundled template from `templates/`, allowing consumers to override
 * the path via the `mds_sdk_template` filter (e.g. to ship branded markup).
 */
final class View {

	/** @var Product */
	private $product;

	/** @var string Absolute path to the SDK's templates directory. */
	private $base_dir;

	/**
	 * @param Product $product Product context.
	 */
	public function __construct( Product $product ) {
		$this->product  = $product;
		// src/Admin -> repo root /templates.
		$this->base_dir = dirname( __DIR__, 2 ) . '/templates';
	}

	/**
	 * Render a template with the given variables.
	 *
	 * @param string              $name Template file name without extension.
	 * @param array<string,mixed> $vars Variables exposed to the template.
	 * @return void
	 */
	public function render( $name, array $vars = array() ) {
		$default = $this->base_dir . '/' . $name . '.php';

		/**
		 * Filter the resolved template path.
		 *
		 * @param string  $default Bundled template path.
		 * @param string  $name    Template name.
		 * @param Product $product Product context.
		 */
		$path = apply_filters( 'mds_sdk_template', $default, $name, $this->product );

		if ( ! is_readable( $path ) ) {
			$path = $default;
		}

		if ( ! is_readable( $path ) ) {
			return;
		}

		$vars['product'] = $this->product;

		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		extract( $vars, EXTR_SKIP );

		include $path;
	}
}
