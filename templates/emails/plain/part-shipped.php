<?php
/**
 * Plain text email to the customer about a parcel of personalised items that shipped before the rest of the order.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/emails/plain/part-shipped.php.
 *
 * @package Pixonaut\WooCommerce
 * @version 1.31.0
 *
 * @var WC_Order|null             $order              The order.
 * @var array<string, mixed>|null $parcel             What `emails/plain/parcel.php` shows of the parcel that shipped.
 * @var string                    $intro              The text above the parcel.
 * @var string                    $email_heading      The heading.
 * @var string                    $additional_content Text the store added in the email's settings.
 * @var WC_Email                  $email              The email.
 */

defined( 'ABSPATH' ) || exit;

echo '= ' . esc_html( wp_strip_all_tags( $email_heading ) ) . " =\n\n";

echo esc_html( $intro ) . "\n\n";

if ( null !== $parcel ) {
	wc_get_template( 'emails/plain/parcel.php', $parcel, 'woocommerce/pixonaut/', $email->template_base );
}

if ( '' !== $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WooCommerce.Commenting.CommentHooks.MissingHookComment -- WooCommerce's own filter.
