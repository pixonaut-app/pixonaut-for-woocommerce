<?php
/**
 * The "edit your design" email, as plain text.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/emails/plain/changes-link.php.
 *
 * @package Pixonaut\WooCommerce
 * @version 0.1.0
 *
 * @var WC_Order_Item_Product $item               The order line whose proof the customer asked to change.
 * @var string                $email_heading      The heading.
 * @var string                $additional_content The store's extra text, if any.
 * @var string                $edit_url           The address that opens the design again.
 * @var string                $intro              The text above the link.
 * @var string                $link_text          The text of the link.
 */

defined( 'ABSPATH' ) || exit;

echo esc_html( wp_strip_all_tags( $email_heading ) ) . "\n\n";
echo esc_html( $intro ) . "\n\n";
echo esc_html( $item->get_name() ) . "\n";
echo esc_html( $link_text ) . ': ' . esc_url_raw( $edit_url ) . "\n";
if ( $additional_content ) {
	echo "\n" . esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n";
}
