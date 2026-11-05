<?php
/**
 * Plain text email to the store about a proof whose deadline passed without the customer's answer.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/emails/plain/deadline-passed.php.
 *
 * @package Pixonaut\WooCommerce
 * @version 0.1.0
 *
 * @var WC_Order|null $order              The order.
 * @var string        $item_name          The item's product name.
 * @var string        $outcome            What became of the item, in words.
 * @var string        $order_url          The order's admin screen.
 * @var string        $email_heading      The heading.
 * @var string        $additional_content Text the store added in the email's settings.
 */

defined( 'ABSPATH' ) || exit;

echo '= ' . esc_html( wp_strip_all_tags( $email_heading ) ) . " =\n\n";

printf(
	/* translators: 1: name of the ordered product, 2: WooCommerce order number. Plain text email to the merchant. */
	esc_html__( 'The customer did not answer the proof of "%1$s" in order #%2$s by the deadline.', 'pixonaut-for-woocommerce' ),
	esc_html( $item_name ),
	esc_html( null === $order ? '' : $order->get_order_number() )
);
echo "\n\n" . esc_html( $outcome ) . "\n\n";
if ( '' !== $order_url ) {
	echo /* translators: Link to the order in WordPress admin; here Open is a verb. Plain text email to the merchant. */ esc_html__( 'Open the order', 'pixonaut-for-woocommerce' ) . "\n" . esc_url_raw( $order_url ) . "\n\n";
}

if ( '' !== $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WooCommerce.Commenting.CommentHooks.MissingHookComment -- WooCommerce's own email hook.
