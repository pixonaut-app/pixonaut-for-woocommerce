<?php
/**
 * The "design approved" email.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/emails/design-approved.php.
 *
 * @package Pixonaut\WooCommerce
 * @version 0.1.0
 *
 * @var WC_Order              $order              The order.
 * @var WC_Order_Item_Product $item               The order line just approved.
 * @var string                $email_heading      The heading.
 * @var string                $additional_content The store's extra text, if any.
 * @var bool                  $sent_to_admin      False: the email goes to the customer.
 * @var bool                  $plain_text         False.
 * @var WC_Email              $email              The email.
 * @var string                $intro              The text of the email.
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own hook.
?>
<p><?php echo esc_html( $intro ); ?></p>
<p><strong><?php echo esc_html( $item->get_name() ); ?></strong></p>
<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own hook.
