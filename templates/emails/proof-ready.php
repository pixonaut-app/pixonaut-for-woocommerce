<?php
/**
 * The "proof ready" email: links to the proof pages of a line's designs.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/emails/proof-ready.php.
 *
 * @package Pixonaut\WooCommerce
 * @version 0.1.0
 *
 * @var WC_Order              $order              The order.
 * @var WC_Order_Item_Product $item               The order line waiting for approval.
 * @var string                $email_heading      The heading.
 * @var string                $additional_content The store's extra text, if any.
 * @var bool                  $sent_to_admin      False: the email goes to the customer.
 * @var bool                  $plain_text         False.
 * @var WC_Email              $email              The email.
 * @var string[]              $proof_urls         One proof page per design.
 * @var string                $removed            That the personalisation of an erased side was removed; empty when none was.
 * @var string                $intro              The text above the links.
 * @var string                $link_text          The text of each link.
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own hook.
?>
<p><?php echo esc_html( $intro ); ?></p>
<p><strong><?php echo esc_html( $item->get_name() ); ?></strong></p>
<?php foreach ( $proof_urls as $pixonaut_url ) : ?>
	<p><a href="<?php echo esc_url( $pixonaut_url ); ?>"><?php echo esc_html( $link_text ); ?></a></p>
<?php endforeach; ?>
<?php if ( '' !== $removed ) : ?>
	<p><?php echo esc_html( $removed ); ?></p>
<?php endif; ?>
<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own hook.
