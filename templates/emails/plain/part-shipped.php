<?php
/**
 * Plain text email to the customer about a personalised item that shipped before the rest of the order.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/emails/plain/part-shipped.php.
 *
 * @package Pixonaut\WooCommerce
 * @version 0.1.0
 *
 * @var WC_Order|null              $order              The order.
 * @var WC_Order_Item_Product|null $item               The item that shipped.
 * @var string                     $intro              The text above the item.
 * @var string                     $email_heading      The heading.
 * @var string                     $additional_content Text the store added in the email's settings.
 */

defined( 'ABSPATH' ) || exit;

echo '= ' . esc_html( wp_strip_all_tags( $email_heading ) ) . " =\n\n";

echo esc_html( $intro ) . "\n\n";

if ( null !== $item ) {
	echo esc_html( $item->get_name() . ' × ' . $item->get_quantity() ) . "\n";
	echo esc_html(
		wp_strip_all_tags(
			wc_display_item_meta(
				$item,
				array(
					'before'    => '',
					'separator' => "\n",
					'after'     => '',
					'echo'      => false,
					'autop'     => false,
				)
			)
		)
	);
	echo "\n\n";
}

if ( '' !== $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WooCommerce.Commenting.CommentHooks.MissingHookComment -- WooCommerce's own filter.
