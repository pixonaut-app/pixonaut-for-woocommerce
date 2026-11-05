<?php
/**
 * Email to the customer about a personalised item that shipped before the rest of the order.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/emails/part-shipped.php.
 *
 * @package Pixonaut\WooCommerce
 * @version 0.1.0
 *
 * @var WC_Order|null              $order              The order.
 * @var WC_Order_Item_Product|null $item               The item that shipped.
 * @var string                     $intro              The text above the item.
 * @var string                     $email_heading      The heading.
 * @var string                     $additional_content Text the store added in the email's settings.
 * @var WC_Email                   $email              The email.
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WooCommerce.Commenting.CommentHooks.MissingHookComment -- WooCommerce's own email hook.
?>

<p><?php echo esc_html( $intro ); ?></p>

<?php if ( null !== $item ) : ?>
	<p>
		<strong><?php echo esc_html( $item->get_name() ); ?></strong>
		<?php echo esc_html( '× ' . $item->get_quantity() ); ?>
	</p>
	<?php wc_display_item_meta( $item ); ?>
<?php endif; ?>

<?php
if ( '' !== $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WooCommerce.Commenting.CommentHooks.MissingHookComment -- WooCommerce's own email hook.
