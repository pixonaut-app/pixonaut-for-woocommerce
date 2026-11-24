<?php
/**
 * What a customer email about a parcel shows.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Emails;

use Pixonaut\WooCommerce\Fulfilment\LineDisplay;
use Pixonaut\WooCommerce\Fulfilment\ParcelLine;
use Pixonaut\WooCommerce\Fulfilment\Tracking;
use Pixonaut\WooCommerce\Labels;

/**
 * A parcel's lines with how many of each are in it and its tracking once, as
 * the `emails/parcel.php` templates show them. A line's own tracking row is
 * left out of its details while the email renders, the parcel's tracking
 * standing for it.
 */
final class ParcelContent {

	/**
	 * @param ParcelLine[]                                               $lines    The parcel's lines.
	 * @param array{carrier?: string, number: string, url?: string}|null $tracking The parcel's tracking; null when it has none yet.
	 * @phpstan-param list<ParcelLine> $lines
	 */
	public function __construct(
		public readonly array $lines,
		public readonly ?array $tracking,
	) {
	}

	/**
	 * What the `emails/parcel.php` templates receive.
	 *
	 * @return array{parcel_lines: list<array{item: \WC_Order_Item_Product, quantity: int|null, split: string}>, tracking_label: string, tracking_text: string, tracking_url: string}
	 */
	public function template_args(): array {
		$labels = new Labels();
		$lines  = array();
		foreach ( $this->lines as $line ) {
			$lines[] = array(
				'item'     => $line->item,
				'quantity' => $line->quantity,
				'split'    => self::split( $labels, $line ),
			);
		}
		return array(
			'parcel_lines'   => $lines,
			'tracking_label' => $labels->get( Labels::TRACKING, array() === $this->lines ? array() : array( 'item_id' => $this->lines[0]->item->get_id() ) ),
			'tracking_text'  => null === $this->tracking ? '' : Tracking::text( $this->tracking ),
			'tracking_url'   => $this->tracking['url'] ?? '',
		);
	}

	/**
	 * What a line says when the parcel holds only some of its copies: that
	 * the rest come in another parcel, or went in one before; empty when it
	 * holds them all.
	 *
	 * @param Labels     $labels The customer-facing texts.
	 * @param ParcelLine $line   A line of the parcel.
	 */
	private static function split( Labels $labels, ParcelLine $line ): string {
		if ( $line->whole ) {
			return '';
		}
		$context = array( 'item_id' => $line->item->get_id() );
		if ( null === $line->quantity ) {
			return $labels->get( $line->rest_to_come ? Labels::PARCEL_PART : Labels::PARCEL_PART_LAST, $context );
		}
		return sprintf( $labels->get( $line->rest_to_come ? Labels::PARCEL_SPLIT : Labels::PARCEL_SPLIT_LAST, $context ), $line->quantity, $line->of_quantity );
	}

	/**
	 * Runs $render with the lines' own tracking rows left out of their details.
	 *
	 * @template T
	 * @param callable(): T $render Renders or sends the email.
	 * @return T
	 */
	public static function without_line_tracking( callable $render ): mixed {
		$drop = static function ( array $rows ): array {
			unset( $rows[ LineDisplay::TRACKING_ROW ] );
			return $rows;
		};
		add_filter( 'pixonaut_wc_fulfilment_line_display', $drop, PHP_INT_MAX );
		try {
			return $render();
		} finally {
			remove_filter( 'pixonaut_wc_fulfilment_line_display', $drop, PHP_INT_MAX );
		}
	}
}
