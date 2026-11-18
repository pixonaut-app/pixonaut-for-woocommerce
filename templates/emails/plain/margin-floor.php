<?php
/**
 * Plain text email to the store naming prices whose margin fell below its lowest margin.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/emails/plain/margin-floor.php.
 *
 * @package Pixonaut\WooCommerce
 * @version 1.29.0
 *
 * @var array[] $rows               Each price: `product`, `variation` (empty for a simple product), `price`, `cost`, `margin` and `edit_url`, as text.
 * @var string  $floor              The lowest margin, such as 25.0 %.
 * @var string  $email_heading      The heading.
 * @var string  $additional_content Text the store added in the email's settings.
 */

defined( 'ABSPATH' ) || exit;

echo '= ' . esc_html( wp_strip_all_tags( $email_heading ) ) . " =\n\n";

printf(
	/* translators: %s: the lowest margin the store accepts, such as 25.0 %. Plain text email to the merchant. */
	esc_html__( 'The margin of these prices fell below your lowest margin of %s, against what the printshop charges for the product and its shipping, excluding VAT:', 'pixonaut-for-woocommerce' ),
	esc_html( $floor )
);
echo "\n\n";
foreach ( $rows as $pixonaut_row ) {
	echo esc_html( '' === $pixonaut_row['variation'] ? $pixonaut_row['product'] : $pixonaut_row['product'] . ' – ' . $pixonaut_row['variation'] ) . "\n";
	printf(
		/* translators: 1: the price, 2: what the printshop charges for the product and its shipping, 3: the margin, such as 22.4 %. Plain text email to the merchant. */
		esc_html__( 'Price %1$s, cost %2$s, margin %3$s', 'pixonaut-for-woocommerce' ),
		esc_html( $pixonaut_row['price'] ),
		esc_html( $pixonaut_row['cost'] ),
		esc_html( $pixonaut_row['margin'] )
	);
	echo "\n" . esc_url_raw( $pixonaut_row['edit_url'] ) . "\n\n";
}
echo esc_html__( 'Each price is named once, until its margin is back at your lowest margin and falls below it again.', 'pixonaut-for-woocommerce' ) . "\n\n";

if ( '' !== $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WooCommerce.Commenting.CommentHooks.MissingHookComment -- WooCommerce's own email hook.
