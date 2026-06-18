<?php
/**
 * Rollback (version downgrade) list template.
 *
 * Overridable via the `mds_sdk_template` filter.
 *
 * @package MeuMouse\MDS\SDK
 *
 * @var \MeuMouse\MDS\SDK\Config\Product $product
 * @var array<int,array<string,mixed>>   $versions
 * @var string                           $current_version
 * @var bool                             $is_active
 * @var string                           $action_url
 * @var string                           $action
 * @var string                           $nonce_action
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="mds-rollback-panel wrap">
	<h2><?php echo esc_html( sprintf( /* translators: %s: product name */ __( '%s — Versions', 'mds-sdk' ), $product->item_name() ) ); ?></h2>

	<p class="description">
		<?php
		echo esc_html(
			sprintf(
				/* translators: %s: version */
				__( 'Installed version: %s. Rolling back replaces the current files with an older release.', 'mds-sdk' ),
				$current_version
			)
		);
		?>
	</p>

	<?php if ( ! $is_active ) : ?>
		<div class="notice notice-warning inline"><p><?php esc_html_e( 'An active license is required to roll back.', 'mds-sdk' ); ?></p></div>
	<?php elseif ( empty( $versions ) ) : ?>
		<p><?php esc_html_e( 'No versions available.', 'mds-sdk' ); ?></p>
	<?php else : ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Version', 'mds-sdk' ); ?></th>
					<th><?php esc_html_e( 'Channel', 'mds-sdk' ); ?></th>
					<th><?php esc_html_e( 'Released', 'mds-sdk' ); ?></th>
					<th><?php esc_html_e( 'Changelog', 'mds-sdk' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $versions as $row ) : ?>
					<?php
					$v          = isset( $row['version'] ) ? (string) $row['version'] : '';
					$channel    = isset( $row['channel'] ) ? (string) $row['channel'] : '';
					$released   = ! empty( $row['published_at'] ) ? date_i18n( get_option( 'date_format' ), strtotime( $row['published_at'] ) ) : '—';
					$changelog  = isset( $row['changelog'] ) ? (string) $row['changelog'] : '';
					$is_current = ( $v === $current_version );
					?>
					<tr>
						<td>
							<strong><?php echo esc_html( $v ); ?></strong>
							<?php if ( $is_current ) : ?>
								<span class="description">(<?php esc_html_e( 'installed', 'mds-sdk' ); ?>)</span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $channel ); ?></td>
						<td><?php echo esc_html( $released ); ?></td>
						<td><?php echo esc_html( wp_trim_words( $changelog, 16 ) ); ?></td>
						<td>
							<?php if ( ! $is_current ) : ?>
								<form method="post" action="<?php echo esc_url( $action_url ); ?>" onsubmit="return confirm('<?php echo esc_js( sprintf( /* translators: %s: version */ __( 'Roll back to version %s? This replaces the current files.', 'mds-sdk' ), $v ) ); ?>');">
									<?php wp_nonce_field( $nonce_action ); ?>
									<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>" />
									<input type="hidden" name="version" value="<?php echo esc_attr( $v ); ?>" />
									<button type="submit" class="button"><?php esc_html_e( 'Roll back', 'mds-sdk' ); ?></button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
