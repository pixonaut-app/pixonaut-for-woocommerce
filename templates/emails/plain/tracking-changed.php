<?php
/**
 * Plain text email to the customer about tracking added or corrected after personalised items shipped.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/emails/plain/tracking-changed.php.
 *
 * @package Pixonaut\WooCommerce
 * @version 0.1.0
 *
 * @var WC_Order|null               $order              The order.
 * @var list<WC_Order_Item_Product> $items              The items whose tracking changed, each showing its tracking.
 * @var string                      $intro              The text above the items.
 * @var string                      $email_heading      The heading.
 * @var string                      $additional_content Text the store added in the email's settings.
 */

defined( 'ABSPATH' ) || exit;

echo '= ' . esc_html( wp_strip_all_tags( $email_heading ) ) . " =\n\n";

echo esc_html( $intro ) . "\n\n";

foreach ( $items as $pixonaut_item ) {
	echo esc_html( $pixonaut_item->get_name() . ' × ' . $pixonaut_item->get_quantity() ) . "\n";
	echo esc_html(
		wp_strip_all_tags(
			wc_display_item_meta(
				$pixonaut_item,
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
