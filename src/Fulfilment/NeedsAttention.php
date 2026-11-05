<?php
/**
 * Moves a line to `needs_attention` and tells the merchant why.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Fulfilment;

use Pixonaut\WooCommerce\Api\ErrorCodes;
use WC_Order;
use WC_Order_Item_Product;

/**
 * A line the store cannot take further on its own: its state records the
 * code that stopped it, and the order gets a note in words. The admin notice
 * and the merchant email follow from the state change itself
 * (`pixonaut_wc_fulfilment_needs_attention`), so every way into the state
 * raises them.
 */
final class NeedsAttention {

	/**
	 * The line's retries ran out on a transient failure.
	 */
	public const RETRIES_EXHAUSTED = 'retries_exhausted';

	/**
	 * A transient failure was to be retried, but the store could not
	 * schedule the line's next job.
	 */
	public const RETRY_NOT_SCHEDULED = 'retry_not_scheduled';

	/**
	 * Pixonaut reported the printshop order failed.
	 */
	public const ORDER_FAILED = 'order_failed';

	/**
	 * Pixonaut placed the order with a status the line cannot go on from.
	 */
	public const ORDER_STATUS = 'order_status';

	/**
	 * The line's design was deleted in Pixonaut before the line was finished.
	 */
	public const DESIGN_DELETED = 'design_deleted';

	/**
	 * The line's design was changed in Pixonaut after it was approved, before
	 * it was ordered.
	 */
	public const DESIGN_EDITED = 'design_edited';

	/**
	 * A proof-by-email line's design changed in Pixonaut after it was
	 * bought, or was approved at another revision than the line holds.
	 */
	public const DESIGN_CHANGED = 'design_changed';

	/**
	 * A proof-by-email line's design was no longer waiting for approval at
	 * payment: rejected, or in a status this version does not know.
	 */
	public const DESIGN_NOT_WAITING = 'design_not_waiting';

	/**
	 * A proof-by-email line's customer did not approve the design by the
	 * deadline, and the store denies it then.
	 */
	public const PROOF_EXPIRED = 'proof_expired';

	/**
	 * A proof-by-email line's customer rejected the proof.
	 */
	public const PROOF_REJECTED = 'proof_rejected';

	/**
	 * The answer to the printshop order's submission was lost: Pixonaut
	 * cannot tell whether the printshop has it. Never retried on its own,
	 * which could print it twice; the merchant asks the printshop and
	 * settles it.
	 */
	public const OUTCOME_UNKNOWN = 'outcome_unknown';

	/**
	 * Moves the line to `needs_attention` with $code as its reason, clears its
	 * retry count, and adds $note to the order.
	 *
	 * @param LineState $line  The line.
	 * @param WC_Order  $order Its order.
	 * @param string    $code  What stopped it: Pixonaut's code, or one of this class's.
	 * @param string    $note  A translated order note.
	 *
	 * @return bool False when the line could not move: it is final, or changed elsewhere.
	 */
	public static function flag( LineState $line, WC_Order $order, string $code, string $note ): bool {
		$line->set_ref( LineState::REF_RETRY, null );
		if ( ! $line->transition( LineState::NEEDS_ATTENTION, $code ) ) {
			return false;
		}
		$order->add_order_note( $note );
		return true;
	}

	/**
	 * Why a code stops a line, in words a merchant can act on.
	 *
	 * @param string $code A Pixonaut error code, or one of this class's.
	 */
	public static function explain( string $code ): string {
		return match ( $code ) {
			ErrorCodes::PRINTSHOP_DIFFERS_FROM_BLANK => __( 'Pixonaut refused the printshop the item was sent to: only the printshop of the product\'s blank can make it. Check the blank in Pixonaut, then retry.', 'pixonaut-for-woocommerce' ),
			ErrorCodes::PRINTSHOP_DISCONNECTED  => __( 'The printshop that makes the item is disconnected in Pixonaut. Reconnect it in Pixonaut, then retry.', 'pixonaut-for-woocommerce' ),
			ErrorCodes::PRINT_NEEDS_PRINTSHOP   => __( 'Pixonaut has no printshop to make the item: its design is on no blank, and the workspace has no default printshop. Set one in Pixonaut, then retry.', 'pixonaut-for-woocommerce' ),
			ErrorCodes::VARIANT_RETIRED         => __( 'The blank variant the item was bought as is retired in Pixonaut, so the printshop cannot make it. Refund or cancel the item.', 'pixonaut-for-woocommerce' ),
			ErrorCodes::VARIANT_NOT_OF_BLANK,
			ErrorCodes::VARIANT_REQUIRED        => __( 'Pixonaut could not tell which variant of the blank the item is: the product sells no variant of its blank. Pick its variant on the product, then refund or cancel the item.', 'pixonaut-for-woocommerce' ),
			ErrorCodes::PRINT_NOT_POSITION_SIZE,
			ErrorCodes::PRINT_EXCEEDS_MACHINE   => __( 'The item\'s print is at a size its position on the blank does not print, or larger than the printshop\'s machine takes. Check the blank\'s positions and the printshop\'s machine in Pixonaut, then have the customer personalise the item again at a size it prints.', 'pixonaut-for-woocommerce' ),
			ErrorCodes::FIELD_VALUE_MISSING     => __( 'The printshop needs a value the blank\'s variant or placement does not have. Fill it in for the blank in Pixonaut, then retry.', 'pixonaut-for-woocommerce' ),
			ErrorCodes::DESIGN_ALREADY_ORDERED  => __( 'The design already has a printshop order in Pixonaut that this item did not place, for example one placed in the studio. Check that order; if the item is to be made as well, choose Order again anyway.', 'pixonaut-for-woocommerce' ),
			ErrorCodes::DESIGN_ERASED           => __( 'The design\'s personal data was erased in Pixonaut, so it can no longer be printed.', 'pixonaut-for-woocommerce' ),
			ErrorCodes::REVISION_CHANGED,
			ErrorCodes::NOT_COMMITTED           => __( 'The design changed in Pixonaut since the customer approved it.', 'pixonaut-for-woocommerce' ),
			self::DESIGN_DELETED                => __( 'The design was deleted in Pixonaut, so it can no longer be printed. Check the order with the customer.', 'pixonaut-for-woocommerce' ),
			self::DESIGN_EDITED                 => __( 'The design was changed in Pixonaut after it was approved, so it was not sent to the printshop. Check the design before it is printed.', 'pixonaut-for-woocommerce' ),
			ErrorCodes::UNAUTHORIZED            => __( 'Pixonaut no longer accepts the store\'s key. Reconnect the store.', 'pixonaut-for-woocommerce' ),
			self::DESIGN_CHANGED                => __( 'The design was changed in Pixonaut after the customer bought it, so their proof no longer shows it. Check the design with the customer.', 'pixonaut-for-woocommerce' ),
			self::DESIGN_NOT_WAITING            => __( 'The design was no longer waiting for the customer\'s approval when the order was paid, for example because it was rejected in Pixonaut.', 'pixonaut-for-woocommerce' ),
			self::PROOF_EXPIRED                 => __( 'The customer did not approve the design by the deadline, so it was rejected and will not be printed. Refund or cancel the item.', 'pixonaut-for-woocommerce' ),
			self::PROOF_REJECTED                => __( 'The customer rejected the proof of the design, so it will not be printed. Refund or cancel the item.', 'pixonaut-for-woocommerce' ),
			self::RETRIES_EXHAUSTED,
			self::RETRY_NOT_SCHEDULED           => __( 'Pixonaut could not be reached, or kept failing. Retry the item.', 'pixonaut-for-woocommerce' ),
			self::ORDER_FAILED                  => __( 'The printshop order failed. Retry it, or cancel the item.', 'pixonaut-for-woocommerce' ),
			self::OUTCOME_UNKNOWN               => __( 'Pixonaut cannot tell whether the printshop received the order. Check with the printshop, then tell Pixonaut whether it has the order.', 'pixonaut-for-woocommerce' ),
			default                             => __( 'Check the order in Pixonaut.', 'pixonaut-for-woocommerce' ),
		};
	}

	/**
	 * The order note for a line whose printshop order's outcome is unknown.
	 *
	 * @param WC_Order_Item_Product $item The line.
	 */
	public static function outcome_unknown_note( WC_Order_Item_Product $item ): string {
		return sprintf(
			/* translators: %s: product name of the order line. */
			__( 'Pixonaut: the printshop order for "%s" was sent, but its answer was lost, so Pixonaut cannot tell whether the printshop has it. Check with the printshop, then settle it in the Pixonaut box of this order.', 'pixonaut-for-woocommerce' ),
			$item->get_name()
		);
	}
}
