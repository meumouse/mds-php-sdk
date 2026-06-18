<?php
/**
 * License settings panel template.
 *
 * Overridable via the `mds_sdk_template` filter.
 *
 * @package MeuMouse\MDS\SDK
 *
 * @var \MeuMouse\MDS\SDK\Config\Product       $product
 * @var \MeuMouse\MDS\SDK\License\Manager       $license
 * @var \MeuMouse\MDS\SDK\License\LicenseStatus $status
 * @var bool                                    $is_active
 * @var bool                                    $has_key
 * @var string                                  $action_url
 * @var array<string,string>                    $actions
 * @var array<string,string>|null               $notice
 */

defined( 'ABSPATH' ) || exit;

$key       = $license->get_key();
$masked    = '' !== $key ? str_repeat( '•', max( 0, strlen( $key ) - 4 ) ) . substr( $key, -4 ) : '';
$status_id = $status->status();
?>
<div class="mds-license-panel wrap">
	<h2><?php echo esc_html( sprintf( /* translators: %s: product name */ __( '%s — License', 'mds-sdk' ), $product->item_name() ) ); ?></h2>

	<?php if ( is_array( $notice ) ) : ?>
		<div class="notice notice-<?php echo 'success' === $notice['type'] ? 'success' : 'error'; ?>">
			<p><?php echo esc_html( $notice['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Status', 'mds-sdk' ); ?></th>
			<td>
				<?php if ( $is_active ) : ?>
					<span class="mds-badge mds-badge--ok" style="color:#1a7f37;font-weight:600;">
						<?php esc_html_e( 'Active', 'mds-sdk' ); ?>
					</span>
				<?php else : ?>
					<span class="mds-badge mds-badge--off" style="color:#b32d2e;font-weight:600;">
						<?php echo esc_html( ucfirst( $status_id ) ); ?>
					</span>
				<?php endif; ?>

				<?php if ( '' !== $status->message() ) : ?>
					<p class="description"><?php echo esc_html( $status->message() ); ?></p>
				<?php endif; ?>

				<?php if ( $status->expires_at() ) : ?>
					<p class="description">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: date */
								__( 'Expires: %s', 'mds-sdk' ),
								date_i18n( get_option( 'date_format' ), strtotime( $status->expires_at() ) )
							)
						);
						?>
					</p>
				<?php endif; ?>
			</td>
		</tr>

		<tr>
			<th scope="row"><label for="mds-license-key"><?php esc_html_e( 'License key', 'mds-sdk' ); ?></label></th>
			<td>
				<?php if ( $has_key ) : ?>
					<code><?php echo esc_html( $masked ); ?></code>
				<?php else : ?>
					<form method="post" action="<?php echo esc_url( $action_url ); ?>" style="display:inline-flex;gap:8px;align-items:center;">
						<?php wp_nonce_field( $actions['activate'] ); ?>
						<input type="hidden" name="action" value="<?php echo esc_attr( $actions['activate'] ); ?>" />
						<input type="text" id="mds-license-key" name="license_key" class="regular-text" autocomplete="off" placeholder="MDS-XXXX-XXXX-XXXX" />
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Activate', 'mds-sdk' ); ?></button>
					</form>
				<?php endif; ?>
			</td>
		</tr>
	</table>

	<?php if ( $has_key ) : ?>
		<p>
			<form method="post" action="<?php echo esc_url( $action_url ); ?>" style="display:inline;">
				<?php wp_nonce_field( $actions['check'] ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( $actions['check'] ); ?>" />
				<button type="submit" class="button"><?php esc_html_e( 'Re-check now', 'mds-sdk' ); ?></button>
			</form>

			<form method="post" action="<?php echo esc_url( $action_url ); ?>" style="display:inline;" onsubmit="return confirm('<?php echo esc_js( __( 'Deactivate the license on this site?', 'mds-sdk' ) ); ?>');">
				<?php wp_nonce_field( $actions['deactivate'] ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( $actions['deactivate'] ); ?>" />
				<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Deactivate', 'mds-sdk' ); ?></button>
			</form>
		</p>
	<?php endif; ?>
</div>
