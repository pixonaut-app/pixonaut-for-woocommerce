<?php
/**
 * Applies `design.changes_requested` to the lines waiting for the customer.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Approval;

use Pixonaut\WooCommerce\ApprovalMode;
use Pixonaut\WooCommerce\Connection\Connection;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Webhooks\Event;
use Pixonaut\WooCommerce\Webhooks\Payload;
use WC_Order;
use WC_Order_Item_Product;

/**
 * The customer asked for changes on a proof page, which sends them back to
 * the store to make them. Each proof-by-email line holding the design gets
 * the request in an order note and `pixonaut_wc_approval_changes_requested`,
 * which tells the store what the customer asked and, for a customer who
 * closed the tab before making the changes, sends them a link that opens the
 * design again: only while the line waits for their approval of that side
 * at the revision the request was about. The line's state never changes.
 */
final class ChangesRequested {

	public const TYPE = 'design.changes_requested';

	/**
	 * @param Connection $connection The store's connection; a line of another workspace is never acted on.
	 * @param ProofState $state      Signs the link that opens the design again.
	 */
	public function __construct(
		private readonly Connection $connection,
		private readonly ProofState $state,
	) {
	}

	/**
	 * @param Event $event A `design.changes_requested` event.
	 */
	public function handle( Event $event ): void {
		$design_id = Payload::string( $event, 'designId' );
		$revision  = $event->payload['revision'] ?? null;
		if ( null === $design_id || ! is_int( $revision ) ) {
			return;
		}
		$message = Payload::string( $event, 'message' ) ?? '';
		foreach ( LineState::for_design_id( $design_id ) as $line ) {
			$this->apply( $line, $design_id, $revision, $message );
		}
	}

	/**
	 * The address that opens a line's design again for changes: the route a
	 * proof page returns the customer to, with a `state` signed for the
	 * revision the line holds.
	 *
	 * @param WC_Order_Item_Product $item      The order line.
	 * @param string                $design_id The design.
	 * @param int                   $revision  The revision the line holds.
	 */
	public function edit_url( WC_Order_Item_Product $item, string $design_id, int $revision ): string {
		return add_query_arg( 'state', rawurlencode( $this->state->sign( $item->get_order_id(), $item->get_id(), $design_id, $revision ) ), ProofLink::return_url( $item->get_id() ) );
	}

	/**
	 * @param LineState $line      A line holding the design.
	 * @param string    $design_id The design.
	 * @param int       $revision  The revision the request was about.
	 * @param string    $message   What the customer asked.
	 */
	private function apply( LineState $line, string $design_id, int $revision, string $message ): void {
		$item       = $line->item();
		$side       = $line->side_of_design( $design_id );
		$connection = $this->connection->current();
		if ( null === $side || null === $connection || $line->ref( LineState::REF_TENANT_ID ) !== $connection->tenant_id || ApprovalMode::PROOF_EMAIL !== $line->ref( LineState::REF_APPROVAL_MODE ) ) {
			return;
		}
		$open  = LineState::AWAITING_CUSTOMER === $line->state()
			&& true !== $line->side_ref( $side, LineState::SIDE_APPROVED )
			&& $line->side_ref( $side, LineState::SIDE_REVISION ) === $revision;
		$order = wc_get_order( $item->get_order_id() );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$order->add_order_note(
			/* translators: 1: product name of the order line, 2: what the customer asked. */
			sprintf( __( 'Pixonaut: the customer asked for changes to the design of "%1$s": %2$s', 'pixonaut-for-woocommerce' ), $item->get_name(), $message )
		);

		/**
		 * Fires when a customer asked for changes to the proof of a line
		 * whose approval mode is proof by email. The plugin's "changes
		 * requested" email to the store, and while the design can still be
		 * changed its "edit your design" email to the customer, are sent from
		 * here.
		 *
		 * @since 0.1.0
		 *
		 * @param WC_Order_Item_Product $item     The order line.
		 * @param string                $side     The side whose proof the customer asked to change, e.g. `front`.
		 * @param string                $message  What the customer asked.
		 * @param string|null           $edit_url The address that opens the design again for the changes; null when the customer changed and approved it already, or the line no longer waits for that revision.
		 *
		 * @example
		 * add_action( 'pixonaut_wc_approval_changes_requested', function ( $item, $side, $message ) {
		 *     error_log( sprintf( 'Order item %d: %s', $item->get_id(), $message ) );
		 * }, 10, 3 );
		 */
		do_action( 'pixonaut_wc_approval_changes_requested', $item, $side, $message, $open ? $this->edit_url( $item, $design_id, $revision ) : null );
	}
}
