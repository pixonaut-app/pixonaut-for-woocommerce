<?php
/**
 * A parcel in a plain text email to the customer: its items, how many of each, and its tracking.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/emails/plain/parcel.php.
 *
 * @package Pixonaut\WooCommerce
 * @version 1.31.0
 *
 * @var list<array{item: WC_Order_Item_Product, quantity: int|null, split: string}> $parcel_lines   The items in the parcel; `quantity` is null when the printshop did not say, and `split` says that only some of an item's copies are in it, empty when all are.
 * @var string                                                                      $tracking_label The heading of the tracking.
 * @var string                                                                      $tracking_text  The carrier and tracking number; empty when the parcel has none yet.
 * @var string                                                                      $tracking_url   The carrier's tracking link; empty when unknown.
 */

defined( 'ABSPATH' ) || exit;

foreach ( $parcel_lines as $pixonaut_line ) {
	echo esc_html( $pixonaut_line['item']->get_name() . ( null === $pixonaut_line['quantity'] ? '' : ' × ' . $pixonaut_line['quantity'] ) ) . "\n";
	echo esc_html(
		wp_strip_all_tags(
			wc_display_item_meta(
				$pixonaut_line['item'],
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
	if ( '' !== $pixonaut_line['split'] ) {
		echo "\n" . esc_html( $pixonaut_line['split'] );
	}
	echo "\n\n";
}

if ( '' !== $tracking_text ) {
	echo esc_html( $tracking_label . ': ' . $tracking_text ) . "\n";
	if ( '' !== $tracking_url ) {
		echo esc_url_raw( $tracking_url ) . "\n";
	}
	echo "\n";
}
