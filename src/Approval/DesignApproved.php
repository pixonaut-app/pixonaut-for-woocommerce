<?php
/**
 * Applies `design.approved` to the lines waiting for the customer.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Approval;

use Pixonaut\WooCommerce\Api\Answers\Design;
use Pixonaut\WooCommerce\ApprovalMode;
use Pixonaut\WooCommerce\Connection\Connection;
use Pixonaut\WooCommerce\Fulfilment\FulfilmentJobs;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Fulfilment\NeedsAttention;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Pixonaut as Sdk;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Webhooks\Event;
use Pixonaut\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Pixonaut\WooCommerce\Webhooks\Payload;
use RuntimeException;
use WC_Order;
use WC_Order_Item_Product;

/**
 * Finds the lines holding the design by the design id index and, for one in
 * `awaiting_customer`, records the side as approved. Once every side of the
 * line is, approved by the customer or in the studio before payment, the
 * line moves to `approved` and its fulfilment job is scheduled.
 *
 * The event counts only for the revision the line holds. One about another
 * revision, or one without a revision, is checked against the design read
 * afresh: approved at the line's revision counts, approved at another moves
 * the line to `needs_attention`, anything else is ignored.
 *
 * An approval of a line whose order was cancelled, refunded or failed meanwhile
 * changes nothing and is noted on the order. One that arrives while the
 * line's job is still preparing its proof is thrown, for the event to be
 * handled again once the line waits. One that arrives again for a line `approved`
 * already schedules its fulfilment job when none is pending, which repairs a
 * job lost after the line was saved.
 */
final class DesignApproved {

	public const TYPE = 'design.approved';

	private const STATUS_APPROVED = 'approved';

	/**
	 * Tries to apply the approval when another request wrote the line meanwhile.
	 */
	private const MAX_ATTEMPTS = 3;

	/**
	 * Order statuses after which an approval no longer sends a line to the
	 * printshop. A cancelled or refunded order stops its lines through
	 * `LineCancellation` instead, which a store may keep a line out of.
	 */
	private const STOPPED_ORDER_STATUSES = array( 'failed' );

	/**
	 * @param Connection      $connection The store's connection; a line of another workspace is never acted on.
	 * @param Sdk             $pixonaut   Reads a design whose approval names another revision.
	 * @param LoggerInterface $logger     Logs approvals ignored.
	 */
	public function __construct(
		private readonly Connection $connection,
		private readonly Sdk $pixonaut,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * @param Event $event A `design.approved` event.
	 * @throws RuntimeException When a line kept changing while the approval was applied, was not waiting for the customer yet, or its fulfilment job could not be scheduled; handling the event again retries it.
	 * @throws ApiException When the design had to be read again and could not be.
	 */
	public function handle( Event $event ): void {
		$design_id = Payload::string( $event, 'designId' );
		if ( null === $design_id ) {
			return;
		}
		$revision = $event->payload['revision'] ?? null;
		foreach ( LineState::for_design_id( $design_id ) as $line ) {
			if ( $this->belongs_here( $line ) ) {
				$this->apply( $line->item(), $design_id, is_int( $revision ) ? $revision : null );
			}
		}
	}

	private function belongs_here( LineState $line ): bool {
		$connection = $this->connection->current();
		if ( null === $connection || $line->ref( LineState::REF_TENANT_ID ) !== $connection->tenant_id ) {
			$this->logger->warning( 'Design approval ignored: the line belongs to another workspace.', array( 'item' => $line->item()->get_id() ) );
			return false;
		}
		return true;
	}

	/**
	 * @param WC_Order_Item_Product $item      The line.
	 * @param string                $design_id The design approved.
	 * @param int|null              $revision  The revision the event names; null when it names none.
	 *
	 * @throws RuntimeException When the line kept changing for MAX_ATTEMPTS tries, was not waiting for the customer yet, or its job could not be scheduled; an ApiException when the design could not be read.
	 */
	private function apply( WC_Order_Item_Product $item, string $design_id, ?int $revision ): void {
		$order = wc_get_order( $item->get_order_id() );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$verified = false;
		for ( $attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++ ) {
			$line = LineState::of( $item );
			$side = $line->side_of_design( $design_id );
			if ( null === $side || ApprovalMode::PROOF_EMAIL !== $line->ref( LineState::REF_APPROVAL_MODE ) ) {
				return;
			}
			$state = $line->state();
			if ( LineState::APPROVED === $state ) {
				// Approved by an earlier delivery; its job may have been lost after the line was saved.
				if ( ! self::stopped_for( $order, $line ) ) {
					$this->schedule( $item );
				}
				return;
			}
			if ( LineState::COMMITTED === $state && FulfilmentJobs::is_scheduled( $item->get_id() ) ) {
				// The job is preparing the proof: the line waits for the customer once it is sent.
				throw new RuntimeException( sprintf( 'Design approval not applied to order item %d: the line is not waiting for the customer yet.', (int) $item->get_id() ) );
			}
			if ( LineState::AWAITING_CUSTOMER !== $state || true === $line->side_ref( $side, LineState::SIDE_APPROVED ) ) {
				return;
			}
			if ( self::stopped_for( $order, $line ) ) {
				$this->stopped( $order, $item );
				return;
			}
			$line_revision = $line->side_ref( $side, LineState::SIDE_REVISION );
			if ( $revision !== $line_revision && ! $verified ) {
				$design = Design::get( $this->pixonaut, $design_id );
				if ( self::STATUS_APPROVED !== $design->status ) {
					return;
				}
				if ( $design->revision !== $line_revision ) {
					if ( $this->attention( $line, $order ) ) {
						return;
					}
					continue;
				}
				$verified = true;
			}

			$line->set_side_ref( $side, LineState::SIDE_APPROVED, true );
			$approved = array() === array_filter( $line->sides(), static fn ( string $key ): bool => true !== $line->side_ref( $key, LineState::SIDE_APPROVED ) );
			if ( ! $approved ) {
				if ( $line->save() ) {
					return;
				}
				continue;
			}
			// The transition saves the side's approval with the new state.
			if ( $line->transition( LineState::APPROVED ) ) {
				/* translators: %s: product name of the order line. */
				$order->add_order_note( sprintf( __( 'Pixonaut: the customer approved the design of "%s".', 'pixonaut-for-woocommerce' ), $item->get_name() ) );
				$this->schedule( $item );
				return;
			}
		}
		throw new RuntimeException( sprintf( 'Design approval not applied to order item %d: the line kept changing.', (int) $item->get_id() ) );
	}

	/**
	 * Schedules the approved line's fulfilment job.
	 *
	 * @param WC_Order_Item_Product $item The line.
	 *
	 * @throws RuntimeException When Action Scheduler would not store the job; handling the event again schedules it.
	 */
	private function schedule( WC_Order_Item_Product $item ): void {
		if ( ! FulfilmentJobs::schedule( $item->get_id() ) ) {
			throw new RuntimeException( sprintf( 'Fulfilment of order item %d could not be scheduled after its design was approved.', (int) $item->get_id() ) );
		}
	}

	/**
	 * Whether an approval no longer sends the line to the printshop: its
	 * order failed, or the line is being cancelled.
	 *
	 * @param WC_Order  $order The order.
	 * @param LineState $line  The line.
	 */
	public static function stopped_for( WC_Order $order, LineState $line ): bool {
		return $order->has_status( self::STOPPED_ORDER_STATUSES ) || null !== $line->ref( LineState::REF_CANCEL );
	}

	/**
	 * Notes an approval that came after the order was stopped, and leaves the line as it is.
	 *
	 * @param WC_Order              $order The order.
	 * @param WC_Order_Item_Product $item  The line.
	 */
	private function stopped( WC_Order $order, WC_Order_Item_Product $item ): void {
		$this->logger->warning(
			'Design approval ignored: the order was stopped.',
			array(
				'item'   => $item->get_id(),
				'status' => $order->get_status(),
			)
		);
		$order->add_order_note(
			/* translators: %s: product name of the order line. */
			sprintf( __( 'Pixonaut: the customer approved the design of "%s" after the order was cancelled, refunded or failed, so it was not sent to the printshop.', 'pixonaut-for-woocommerce' ), $item->get_name() )
		);
	}

	/**
	 * Moves a line whose design was approved at another revision than the
	 * customer bought to `needs_attention`.
	 *
	 * @param LineState $line  The line.
	 * @param WC_Order  $order The order.
	 * @return bool False when the line changed elsewhere meanwhile and was not moved.
	 */
	private function attention( LineState $line, WC_Order $order ): bool {
		return NeedsAttention::flag(
			$line,
			$order,
			NeedsAttention::DESIGN_CHANGED,
			/* translators: %s: product name of the order line. */
			sprintf( __( 'Pixonaut: the design of "%s" was approved after it was changed, so it was not sent to the printshop. Check the design in Pixonaut.', 'pixonaut-for-woocommerce' ), $line->item()->get_name() )
		);
	}
}
