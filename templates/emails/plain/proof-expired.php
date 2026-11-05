<?php
/**
 * The "cancelled after deadline" email, as plain text.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/emails/plain/proof-expired.php.
 *
 * @package Pixonaut\WooCommerce
 * @version 0.1.0
 *
 * @var WC_Order_Item_Product $item               The order line cancelled.
 * @var string                $email_heading      The heading.
 * @var string                $additional_content The store's extra text, if any.
 * @var string                $intro              Why the item was cancelled.
 * @var string                $refunded           That it was refunded; empty when it was not.
 */

defined( 'ABSPATH' ) || exit;

echo esc_html( wp_strip_all_tags( $email_heading ) ) . "\n\n";
echo esc_html( $intro ) . "\n\n";
echo esc_html( $item->get_name() ) . "\n";
if ( '' !== $refunded ) {
	echo "\n" . esc_html( $refunded ) . "\n";
}
if ( $additional_content ) {
	echo "\n" . esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n";
}
