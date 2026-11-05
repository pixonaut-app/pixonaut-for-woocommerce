<?php
/**
 * The Pixonaut for WooCommerce section of WooCommerce > Status: the plugin's
 * templates the theme overrides.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/admin/status-report.php.
 * The `data-export-label` attributes are what WooCommerce copies into the
 * system report; keep them.
 *
 * @package Pixonaut\WooCommerce
 * @version 0.1.0
 *
 * @var list<array{file: string, version: string, plugin_version: string, outdated: bool}> $overrides    The overridden templates.
 * @var bool                                                                                $has_outdated Whether any override is outdated.
 */

defined( 'ABSPATH' ) || exit;
?>
<table class="wc_status_table widefat pixonaut-status-templates" cellspacing="0">
	<thead>
		<tr>
			<th colspan="3" data-export-label="Pixonaut for WooCommerce templates"><h2><?php esc_html_e( 'Pixonaut for WooCommerce templates', 'pixonaut-for-woocommerce' ); ?></h2></th>
		</tr>
	</thead>
	<tbody>
		<?php if ( array() === $overrides ) : ?>
			<tr>
				<td data-export-label="Overrides"><?php /* translators: Row heading in WooCommerce > Status: theme copies of this plugin's templates that replace the plugin's own. */ esc_html_e( 'Overrides', 'pixonaut-for-woocommerce' ); ?>:</td>
				<td class="help">&nbsp;</td>
				<td>&ndash;</td>
			</tr>
		<?php endif; ?>
		<?php foreach ( $overrides as $pixonaut_index => $pixonaut_override ) : ?>
			<tr class="<?php echo esc_attr( $pixonaut_override['outdated'] ? 'pixonaut-status-templates__outdated' : 'pixonaut-status-templates__current' ); ?>">
				<td data-export-label="Override">
					<?php if ( 0 === $pixonaut_index ) : ?>
						<?php /* translators: Row heading in WooCommerce > Status: theme copies of this plugin's templates that replace the plugin's own. */ esc_html_e( 'Overrides', 'pixonaut-for-woocommerce' ); ?>:
					<?php endif; ?>
				</td>
				<td class="help">&nbsp;</td>
				<td>
					<code><?php echo esc_html( $pixonaut_override['file'] ); ?></code>
					<?php if ( '' === $pixonaut_override['version'] ) : ?>
						<br><mark class="error"><span class="dashicons dashicons-warning"></span>
						<?php
						printf(
							/* translators: %s: version of the template the plugin ships, e.g. 0.2.0. */
							esc_html__( 'Version header is missing. The plugin ships version %s.', 'pixonaut-for-woocommerce' ),
							'<strong>' . esc_html( $pixonaut_override['plugin_version'] ) . '</strong>'
						);
						?>
						</mark>
					<?php elseif ( $pixonaut_override['outdated'] ) : ?>
						<br><mark class="error"><span class="dashicons dashicons-warning"></span>
						<?php
						printf(
							/* translators: 1: version of the theme's copy of a template, 2: version of the template the plugin ships. */
							esc_html__( 'Version %1$s is out of date. The plugin ships version %2$s.', 'pixonaut-for-woocommerce' ),
							'<strong>' . esc_html( $pixonaut_override['version'] ) . '</strong>',
							'<strong>' . esc_html( $pixonaut_override['plugin_version'] ) . '</strong>'
						);
						?>
						</mark>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
	</tbody>
	<?php if ( $has_outdated ) : ?>
		<tfoot>
			<tr>
				<td data-export-label="Outdated templates"><?php /* translators: Row heading in WooCommerce > Status: theme template copies whose version is older than the plugin's. */ esc_html_e( 'Outdated templates', 'pixonaut-for-woocommerce' ); ?>:</td>
				<td class="help">&nbsp;</td>
				<td>
					<mark class="error"><span class="dashicons dashicons-warning"></span></mark>
					<?php esc_html_e( 'Compare each outdated copy with the plugin template, carry its changes over, and copy its @version header.', 'pixonaut-for-woocommerce' ); ?>
				</td>
			</tr>
		</tfoot>
	<?php endif; ?>
</table>
