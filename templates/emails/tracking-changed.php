<?php
/**
 * Email to the customer about tracking added or corrected after personalised items shipped.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/emails/tracking-changed.php.
 *
 * @package Pixonaut\WooCommerce
 * @version 0.1.0
 *
 * @var WC_Order|null               $order              The order.
 * @var list<WC_Order_Item_Product> $items              The items whose tracking changed, each showing its tracking.
 * @var string                      $intro              The text above the items.
 * @var string                      $email_heading      The heading.
 * @var string                      $additional_content Text the store added in the email's settings.
 * @var WC_Email                    $email              The email.
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WooCommerce.Commenting.CommentHooks.MissingHookComment -- WooCommerce's own email hook.
?>

<p><?php echo esc_html( $intro ); ?></p>

<?php foreach ( $items as $pixonaut_item ) : ?>
	<p>
		<strong><?php echo esc_html( $pixonaut_item->get_name() ); ?></strong>
		<?php echo esc_html( '× ' . $pixonaut_item->get_quantity() ); ?>
	</p>
	<?php wc_display_item_meta( $pixonaut_item ); ?>
<?php endforeach; ?>

<?php
if ( '' !== $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WooCommerce.Commenting.CommentHooks.MissingHookComment -- WooCommerce's own email hook.
