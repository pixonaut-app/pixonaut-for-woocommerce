<?php
/**
 * What becomes of a line whose proof was denied.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Approval;

use Pixonaut\WooCommerce\Fulfilment\LineCancellation;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Fulfilment\NeedsAttention;
use Pixonaut\WooCommerce\Settings\Settings;
use WC_Order;
use WC_Order_Item_Product;

/**
 * Applies the "On deny" setting to a proof-by-email line the customer
 * rejected on its proof page, or whose deadline passed with the deadline
 * set to deny: the line is refunded and cancelled, cancelled, or moved to
 * `needs_attention` for the merchant to decide. Cancelling goes through
 * `LineCancellation`, which rejects the designs still waiting; a line that
 * cancellation keeps going needs the merchant's attention instead.
 */
final class ProofDenial {

	// Who denied the proof.
	public const DEADLINE = 'deadline';
	public const CUSTOMER = 'customer';

	/**
	 * Tries to mark the line when another request wrote it meanwhile.
	 */
	private const MAX_ATTEMPTS = 3;

	/**
	 * @param Settings         $settings     Holds the "On deny" setting.
	 * @param LineCancellation $cancellation Cancels the line.
	 * @param LineRefund       $refund       Refunds the line.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly LineCancellation $cancellation,
		private readonly LineRefund $refund,
	) {
	}

	/**
	 * @param LineState $line  A proof-by-email line waiting for the customer.
	 * @param WC_Order  $order Its order.
	 * @param string    $cause Who denied it: DEADLINE or CUSTOMER.
	 *
	 * @return string|null What was done, one of ProofOutcome's "On deny" choices (`cancel` for a refund that failed); null when the line was denied already, or changed elsewhere meanwhile, and nothing was.
	 */
	public function deny( LineState $line, WC_Order $order, string $cause ): ?string {
		$item = $line->item();
		if ( null !== $line->ref( LineState::REF_DENIAL ) ) {
			return null;
		}
		// Claimed over the document this handler read: of two handlers denying the line at once, only one refunds it.
		$line->set_ref( LineState::REF_DENIAL, array( 'cause' => $cause ) );
		if ( ! $line->save() ) {
			return null;
		}

		/**
		 * Filters what becomes of a proof-by-email line whose proof was
		 * denied: by the customer rejecting it on the proof page, or by its
		 * deadline passing with the deadline set to deny.
		 *
		 * @since 0.1.0
		 *
		 * @param string                $action `refund_cancel`, `cancel` or `needs_attention`, as the "On deny" setting says.
		 * @param WC_Order_Item_Product $item   The order line.
		 * @param string                $cause  Who denied it: `deadline` or `customer`.
		 * @param LineState             $line   The line's state and refs.
		 *
		 * @example
		 * add_filter( 'pixonaut_wc_approval_on_deny', function ( $action, $item, $cause ) {
		 *     return 'customer' === $cause ? 'needs_attention' : $action;
		 * }, 10, 3 );
		 */
		$action = apply_filters( 'pixonaut_wc_approval_on_deny', $this->settings->get( Settings::PROOF_ON_DENY ), $item, $cause, $line );
		if ( ! in_array( $action, ProofOutcome::on_deny_choices(), true ) ) {
			$action = ProofOutcome::DEFAULT_ON_DENY;
		}

		if ( ProofOutcome::NEEDS_ATTENTION !== $action ) {
			$trigger = self::DEADLINE === $cause ? LineCancellation::PROOF_EXPIRED : LineCancellation::PROOF_REJECTED;
			if ( $this->cancellation->request( $line, $trigger ) ) {
				$order->add_order_note(
					self::DEADLINE === $cause
						/* translators: %s: product name of the order line. */
						? sprintf( __( 'Pixonaut: the customer did not approve the design of "%s" by the deadline, so it is cancelled.', 'pixonaut-for-woocommerce' ), $item->get_name() )
						/* translators: %s: product name of the order line. */
						: sprintf( __( 'Pixonaut: the customer rejected the proof of "%s", so it is cancelled.', 'pixonaut-for-woocommerce' ), $item->get_name() )
				);
				if ( ProofOutcome::REFUND_AND_CANCEL === $action ) {
					// A refund that failed leaves a note for the merchant: the line is cancelled only.
					$refunded = $this->refund->refund(
						$item,
						$order,
						self::DEADLINE === $cause
							/* translators: Reason recorded on the refund of an order item, shown to the merchant in the order. */
							? __( 'Design not approved by the deadline', 'pixonaut-for-woocommerce' )
							/* translators: Reason recorded on the refund of an order item, shown to the merchant in the order. */
							: __( 'Design proof rejected by the customer', 'pixonaut-for-woocommerce' )
					);
					return $refunded ? $action : ProofOutcome::CANCEL;
				}
				return $action;
			}
			// Kept going by the cancellation filter, or not storable: the merchant decides.
		}

		$note = self::DEADLINE === $cause
			/* translators: %s: product name of the order line. */
			? sprintf( __( 'Pixonaut: the customer did not approve the design of "%s" by the deadline, so it was rejected and will not be printed. Refund or cancel the item.', 'pixonaut-for-woocommerce' ), $item->get_name() )
			/* translators: %s: product name of the order line. */
			: sprintf( __( 'Pixonaut: the customer rejected the proof of "%s", so it will not be printed. Refund or cancel the item.', 'pixonaut-for-woocommerce' ), $item->get_name() );
		// The claim is held: no other handler moves the line, so a write lost to an unrelated one is tried again.
		for ( $attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++ ) {
			if ( NeedsAttention::flag( LineState::of( $item ), $order, self::DEADLINE === $cause ? NeedsAttention::PROOF_EXPIRED : NeedsAttention::PROOF_REJECTED, $note ) ) {
				return ProofOutcome::NEEDS_ATTENTION;
			}
		}
		return null;
	}
}
