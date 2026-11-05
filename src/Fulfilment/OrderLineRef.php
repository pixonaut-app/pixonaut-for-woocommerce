<?php
/**
 * Moves a line's designs to its order line ref.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Fulfilment;

use Pixonaut\WooCommerce\Api\ApiError;
use Pixonaut\WooCommerce\Api\Designs;

/**
 * A design's `externalRef` is the cart ref until its line is in an order,
 * then the order line ref. Checkout moves it; fulfilment moves it again when
 * that failed, so nothing leaves the store under a cart ref. A move replaces
 * the design's ref, so one that stopped half way is made again from the
 * start.
 */
final class OrderLineRef {

	/**
	 * Moves every design of the line to $ref, then records $ref and the move
	 * in the line's refs. Saved with save().
	 *
	 * @param Designs   $designs Sends the moves.
	 * @param LineState $line    The line.
	 * @param string    $ref     The order line ref, from `Refs::order_item_ref()`.
	 *
	 * @throws ApiError When a design could not be moved; the line's refs are left as they were.
	 */
	public static function move( Designs $designs, LineState $line, string $ref ): void {
		foreach ( $line->sides() as $side ) {
			$design_id = $line->side_ref( $side, LineState::SIDE_DESIGN_ID );
			if ( is_string( $design_id ) ) {
				$designs->set_external_ref( $design_id, $ref );
			}
		}
		$line->set_ref( LineState::REF_EXTERNAL_REF, $ref );
		$line->set_ref( LineState::REF_EXTERNAL_REF_MOVED, true );
	}

	/**
	 * Whether the line's designs hold its order line ref.
	 *
	 * @param LineState $line The line.
	 */
	public static function moved( LineState $line ): bool {
		return true === $line->ref( LineState::REF_EXTERNAL_REF_MOVED );
	}
}
