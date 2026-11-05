<?php
/**
 * The "cancelled after deadline" email: a line whose proof the customer did
 * not approve by the deadline was cancelled.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/emails/proof-expired.php.
 *
 * @package Pixonaut\WooCommerce
 * @version 0.1.0
 *
 * @var WC_Order              $order              The order.
 * @var WC_Order_Item_Product $item               The order line cancelled.
 * @var string                $email_heading      The heading.
 * @var string                $additional_content The store's extra text, if any.
 * @var bool                  $sent_to_admin      False: the email goes to the customer.
 * @var bool                  $plain_text         False.
 * @var WC_Email              $email              The email.
 * @var string                $intro              Why the item was cancelled.
 * @var string                $refunded           That it was refunded; empty when it was not.
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own hook.
?>
<p><?php echo esc_html( $intro ); ?></p>
<p><strong><?php echo esc_html( $item->get_name() ); ?></strong></p>
<?php if ( '' !== $refunded ) : ?>
	<p><?php echo esc_html( $refunded ); ?></p>
<?php endif; ?>
<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own hook.
