<?php
/**
 * A parcel in an email to the customer: its items, how many of each, and its tracking.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/emails/parcel.php.
 *
 * @package Pixonaut\WooCommerce
 * @version 1.31.0
 *
 * @var list<array{item: WC_Order_Item_Product, quantity: int|null, split: string}> $parcel_lines   The items in the parcel; `quantity` is null when the printshop did not say, and `split` says that only some of an item's copies are in it, empty when all are.
 * @var string                                                                      $tracking_label The heading of the tracking.
 * @var string                                                                      $tracking_text  The carrier and tracking number; empty when the parcel has none yet.
 * @var string                                                                      $tracking_url   The carrier's tracking link; empty when unknown.
 */

defined( 'ABSPATH' ) || exit;

foreach ( $parcel_lines as $pixonaut_line ) : ?>
	<p>
		<strong><?php echo esc_html( $pixonaut_line['item']->get_name() ); ?></strong>
		<?php if ( null !== $pixonaut_line['quantity'] ) : ?>
			<?php echo esc_html( '× ' . $pixonaut_line['quantity'] ); ?>
		<?php endif; ?>
	</p>
	<?php wc_display_item_meta( $pixonaut_line['item'] ); ?>
	<?php if ( '' !== $pixonaut_line['split'] ) : ?>
		<p><em><?php echo esc_html( $pixonaut_line['split'] ); ?></em></p>
	<?php endif; ?>
<?php endforeach; ?>

<?php if ( '' !== $tracking_text ) : ?>
	<p>
		<strong><?php echo esc_html( $tracking_label ); ?>:</strong>
		<?php if ( '' !== $tracking_url ) : ?>
			<a href="<?php echo esc_url( $tracking_url ); ?>"><?php echo esc_html( $tracking_text ); ?></a>
		<?php else : ?>
			<?php echo esc_html( $tracking_text ); ?>
		<?php endif; ?>
	</p>
<?php endif; ?>
