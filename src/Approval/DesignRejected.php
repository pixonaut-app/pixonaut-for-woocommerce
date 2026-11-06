<?php
/**
 * Applies `design.rejected` to the lines waiting for the customer.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Approval;

use Pixonaut\WooCommerce\ApprovalMode;
use Pixonaut\WooCommerce\Connection\Connection;
use Pixonaut\WooCommerce\Fulfilment\FulfilmentJobs;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Pixonaut as Sdk;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Webhooks\Event;
use Pixonaut\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Pixonaut\WooCommerce\Webhooks\Payload;
use RuntimeException;
use WC_Order;

/**
 * The customer rejected a proof on its proof page: each proof-by-email line
 * holding the design and still waiting for the customer is denied, as the
 * "On deny" setting says. A line no longer waiting, because it was approved,
 * cancelled or denied at its deadline already, is left alone, and so is one
 * whose order failed. Past the line's deadline, its deadline job decides
 * instead: the rejection may be the job's own.
 *
 * The event counts for the revision the line holds. One about another
 * revision, or without one, counts only when the design read afresh is
 * rejected. One that arrives while the line's job is still preparing its
 * proof is thrown, for the event to be handled again once the line waits.
 */
final class DesignRejected {

	public const TYPE = 'design.rejected';

	private const STATUS_REJECTED = 'rejected';

	/**
	 * @param Connection      $connection The store's connection; a line of another workspace is never acted on.
	 * @param Sdk             $pixonaut   Reads a design whose rejection names another revision.
	 * @param ProofDenial     $denial     Applies "On deny".
	 * @param LoggerInterface $logger     Logs rejections ignored.
	 */
	public function __construct(
		private readonly Connection $connection,
		private readonly Sdk $pixonaut,
		private readonly ProofDenial $denial,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * @param Event $event A `design.rejected` event.
	 * @throws RuntimeException When a line was not waiting for the customer yet, or changed while it was denied; handling the event again retries it.
	 * @throws ApiException When the design had to be read again and could not be.
	 */
	public function handle( Event $event ): void {
		$design_id = Payload::string( $event, 'designId' );
		if ( null === $design_id ) {
			return;
		}
		$revision = $event->payload['revision'] ?? null;
		foreach ( LineState::for_design_id( $design_id ) as $line ) {
			$this->apply( $line, $design_id, is_int( $revision ) ? $revision : null );
		}
	}

	/**
	 * @param LineState $line      A line holding the design.
	 * @param string    $design_id The design rejected.
	 * @param int|null  $revision  The revision the event names; null when it names none.
	 *
	 * @throws RuntimeException When the line was not waiting yet, or changed meanwhile; an ApiException when the design could not be read.
	 */
	private function apply( LineState $line, string $design_id, ?int $revision ): void {
		$item       = $line->item();
		$side       = $line->side_of_design( $design_id );
		$connection = $this->connection->current();
		if ( null === $side || ApprovalMode::PROOF_EMAIL !== $line->ref( LineState::REF_APPROVAL_MODE ) ) {
			return;
		}
		if ( null === $connection || $line->ref( LineState::REF_TENANT_ID ) !== $connection->tenant_id ) {
			$this->logger->warning( 'Design rejection ignored: the line belongs to another workspace.', array( 'item' => $item->get_id() ) );
			return;
		}
		$state = $line->state();
		if ( LineState::COMMITTED === $state && FulfilmentJobs::is_scheduled( $item->get_id() ) ) {
			throw new RuntimeException( sprintf( 'Design rejection not applied to order item %d: the line is not waiting for the customer yet.', (int) $item->get_id() ) );
		}
		$order = wc_get_order( $item->get_order_id() );
		if ( LineState::AWAITING_CUSTOMER !== $state || null !== $line->ref( LineState::REF_DENIAL ) || ! $order instanceof WC_Order || DesignApproved::stopped_for( $order, $line ) ) {
			return;
		}
		$deadline = ProofDeadline::deadline_of( $line );
		if ( 0 !== $deadline && time() >= $deadline ) {
			// Past the deadline the deadline's job decides, following a rejection it finds; this may be its own.
			return;
		}
		if ( $revision !== $line->side_ref( $side, LineState::SIDE_REVISION ) && self::STATUS_REJECTED !== $this->pixonaut->designs()->get( $design_id )->status ) {
			return;
		}
		if ( null === $this->denial->deny( $line, $order, ProofDenial::CUSTOMER ) ) {
			throw new RuntimeException( sprintf( 'Design rejection not applied to order item %d: the line changed meanwhile.', (int) $item->get_id() ) );
		}
	}
}
