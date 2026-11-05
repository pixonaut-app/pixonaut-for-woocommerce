<?php
/**
 * The "proof ready" email, as plain text.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/emails/plain/proof-ready.php.
 *
 * @package Pixonaut\WooCommerce
 * @version 0.1.0
 *
 * @var WC_Order_Item_Product $item               The order line waiting for approval.
 * @var string                $email_heading      The heading.
 * @var string                $additional_content The store's extra text, if any.
 * @var string[]              $proof_urls         One proof page per design.
 * @var string                $removed            That the personalisation of an erased side was removed; empty when none was.
 * @var string                $intro              The text above the links.
 * @var string                $link_text          The text of each link.
 */

defined( 'ABSPATH' ) || exit;

echo esc_html( wp_strip_all_tags( $email_heading ) ) . "\n\n";
echo esc_html( $intro ) . "\n\n";
echo esc_html( $item->get_name() ) . "\n";
foreach ( $proof_urls as $pixonaut_url ) {
	echo esc_html( $link_text ) . ': ' . esc_url_raw( $pixonaut_url ) . "\n";
}
if ( '' !== $removed ) {
	// Texturised, as WooCommerce writes a plain email's quotes back from their curly entities only.
	echo esc_html( wptexturize( $removed ) ) . "\n";
}
if ( $additional_content ) {
	echo "\n" . esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n";
}
