<?php
/**
 * Email to the store about a customer who asked for changes to a proof.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/emails/changes-requested.php.
 *
 * @package Pixonaut\WooCommerce
 * @version 0.1.0
 *
 * @var WC_Order|null $order              The order.
 * @var string        $item_name          The item's product name.
 * @var string        $message            What the customer asked.
 * @var string        $order_url          The order's admin screen.
 * @var string        $email_heading      The heading.
 * @var string        $additional_content Text the store added in the email's settings.
 * @var WC_Email      $email              The email.
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WooCommerce.Commenting.CommentHooks.MissingHookComment -- WooCommerce's own email hook.
?>

<p>
	<?php
	printf(
		/* translators: 1: name of the ordered product, 2: WooCommerce order number. Email to the merchant. */
		esc_html__( 'The customer asked for changes to the proof of "%1$s" in order #%2$s. They make the changes on your store; the item is printed once they approve the changed design.', 'pixonaut-for-woocommerce' ),
		esc_html( $item_name ),
		esc_html( null === $order ? '' : $order->get_order_number() )
	);
	?>
</p>
<blockquote><?php echo esc_html( $message ); ?></blockquote>
<?php if ( '' !== $order_url ) : ?>
	<p><a href="<?php echo esc_url( $order_url ); ?>"><?php /* translators: Link to the order in WordPress admin; here Open is a verb. Email to the merchant. */ esc_html_e( 'Open the order', 'pixonaut-for-woocommerce' ); ?></a></p>
<?php endif; ?>

<?php
if ( '' !== $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WooCommerce.Commenting.CommentHooks.MissingHookComment -- WooCommerce's own email hook.
