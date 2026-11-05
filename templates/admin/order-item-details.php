<?php
/**
 * A personalised order line's details on the admin order screen: the values
 * the customer chose for each design's fields, the line's state and its
 * tracking, under WooCommerce's own meta.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/admin/order-item-details.php.
 *
 * @package Pixonaut\WooCommerce
 * @version 0.1.0
 *
 * @var array<string|int, stdClass> $rows Each row's `display_key` and `display_value`, both escaped HTML.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="view pixonaut-order-item-details">
	<table cellspacing="0" class="display_meta">
		<?php foreach ( $rows as $pixonaut_row ) : ?>
			<tr>
				<th><?php echo wp_kses_post( $pixonaut_row->display_key ); ?>:</th>
				<td><?php echo wp_kses_post( force_balance_tags( $pixonaut_row->display_value ) ); ?></td>
			</tr>
		<?php endforeach; ?>
	</table>
</div>
