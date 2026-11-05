<?php
/**
 * An order line whose design a product page opens for the changes the
 * customer asked for.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Approval;

use Pixonaut\WooCommerce\ApprovalMode;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use WC_Order;
use WC_Order_Item_Product;

/**
 * The line, the side and design a proof page's `state` names, and whether
 * the design can be changed now: it is open while the order goes on, the
 * line waits for the customer, the side is not approved yet and the line
 * still holds the revision the proof showed, or one the customer's change
 * made from it and has not approved yet.
 */
final class OrderEditLine {

	/**
	 * The design can be changed.
	 */
	public const OPEN = 'open';

	/**
	 * The proof showed an older revision than the line holds.
	 */
	public const CHANGED = 'changed';

	/**
	 * The line no longer waits for the customer's approval of this side.
	 */
	public const CLOSED = 'closed';

	/**
	 * @param string                $state     The `state` that names the line, as signed.
	 * @param WC_Order              $order     The order.
	 * @param WC_Order_Item_Product $item      The order line.
	 * @param string                $side      The side whose design it names.
	 * @param string                $design_id The design.
	 * @param int                   $revision  The revision the line holds.
	 * @param string                $status    `OPEN`, `CHANGED` or `CLOSED`.
	 */
	public function __construct(
		public readonly string $state,
		public readonly WC_Order $order,
		public readonly WC_Order_Item_Product $item,
		public readonly string $side,
		public readonly string $design_id,
		public readonly int $revision,
		public readonly string $status,
	) {
	}

	/**
	 * Whether a line's design can be changed for a proof of `$shown`.
	 *
	 * @param string   $line_state    The line's state.
	 * @param string   $approval_mode The line's approval mode.
	 * @param bool     $order_stopped Whether an approval no longer sends the line to the printshop: its order failed, or the line is being cancelled.
	 * @param bool     $side_approved Whether the side is approved already.
	 * @param int|null $revision      The revision the line holds for the side.
	 * @param int|null $edit_base     The revision the customer's unapproved change started from, if any.
	 * @param int      $shown         The revision the proof showed, as `state` names it.
	 *
	 * @return string `OPEN`, `CHANGED` or `CLOSED`.
	 */
	public static function status( string $line_state, string $approval_mode, bool $order_stopped, bool $side_approved, ?int $revision, ?int $edit_base, int $shown ): string {
		if ( $order_stopped || LineState::AWAITING_CUSTOMER !== $line_state || ApprovalMode::PROOF_EMAIL !== $approval_mode || $side_approved ) {
			return self::CLOSED;
		}
		// A change saved but not approved yet (its approval failed) keeps the page that made it open.
		return $revision === $shown || $edit_base === $shown ? self::OPEN : self::CHANGED;
	}

	public function open(): bool {
		return self::OPEN === $this->status;
	}
}
