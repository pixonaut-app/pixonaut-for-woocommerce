<?php
/**
 * Decides a proof the customer did not answer by its deadline.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Approval;

use Closure;
use Pixonaut\WooCommerce\Api\Designs;
use Pixonaut\WooCommerce\ApprovalMode;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use Pixonaut\WooCommerce\Fulfilment\FulfilmentJobs;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Fulfilment\NeedsAttention;
use Pixonaut\WooCommerce\Fulfilment\RetryPolicy;
use Pixonaut\WooCommerce\Milliseconds;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\ErrorCode;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Pixonaut as Sdk;
use Pixonaut\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use WC_Order;
use WC_Order_Factory;
use WC_Order_Item_Product;

/**
 * The job `ProofTimers` schedules for the deadline of a line still waiting
 * for the customer. With the deadline set to approve, each design still
 * waiting is approved with consent `deadline` and the line goes on to the
 * printshop; set to deny, each is rejected and "On deny" applies.
 *
 * Pixonaut refuses either `not_committed` when the customer decided first:
 * the design is read again and the customer's decision followed, an
 * approval at the line's revision going on, a rejection denied. A design
 * changed since, or in any other status, needs the merchant's attention.
 * A failure that may pass, or a decision another request's write to the
 * line kept from being saved, is tried again on the fulfilment backoff.
 *
 * Fires `pixonaut_wc_approval_deadline_passed` with what became of the
 * line, which sends the merchant's "deadline passed" email and, for a line
 * cancelled, the customer's "cancelled after deadline" email.
 */
final class ProofDeadline {

	/**
	 * Action Scheduler hook of the job, with the order item id as its one argument.
	 */
	public const ACTION = 'pixonaut_proof_deadline';

	private const STATUS_APPROVED = 'approved';

	private const STATUS_REJECTED = 'rejected';

	/**
	 * The consent kind of an approval made because the deadline passed.
	 */
	private const CONSENT_KIND = 'deadline';

	/**
	 * Why the job is tried again while the store is not connected, for the log.
	 */
	private const NOT_CONNECTED = 'not_connected';

	/**
	 * Why the job is tried again when another request wrote the line before
	 * its decision was saved, for the log.
	 */
	private const LINE_CHANGED = 'line_changed';

	/**
	 * @param Sdk             $pixonaut   Approves, rejects and reads designs.
	 * @param Closure         $connection `fn(): ?StoredConnection`, the connection to act for; null while there is none or it is paused.
	 * @param Settings        $settings   Holds what the deadline does.
	 * @param ProofDenial     $denial     Applies "On deny".
	 * @param LoggerInterface $logger     Logs retries.
	 * @param Closure         $clock      `fn(): int` Unix time.
	 */
	public function __construct(
		private readonly Sdk $pixonaut,
		private readonly Closure $connection,
		private readonly Settings $settings,
		private readonly ProofDenial $denial,
		private readonly LoggerInterface $logger,
		private readonly Closure $clock,
	) {
	}

	public function register(): void {
		add_action( self::ACTION, array( $this, 'run' ) );
	}

	/**
	 * The job.
	 *
	 * @param int|string $item_id The order item.
	 */
	public function run( int|string $item_id ): void {
		$item = WC_Order_Factory::get_order_item( (int) $item_id );
		if ( ! $item instanceof WC_Order_Item_Product || ! LineState::is_tracked( $item ) ) {
			return;
		}
		$line     = LineState::of( $item );
		$deadline = self::deadline_of( $line );
		$order    = wc_get_order( $item->get_order_id() );
		if ( ! $order instanceof WC_Order || ! self::waiting( $line ) || DesignApproved::stopped_for( $order, $line ) ) {
			return;
		}
		if ( ! $this->acts_for( $line ) ) {
			$this->again( $line, $order, self::NOT_CONNECTED, null );
			return;
		}

		/**
		 * Filters what the store does with a proof-by-email line whose
		 * customer neither approved nor rejected its proof by the deadline.
		 *
		 * @since 0.1.0
		 *
		 * @param string                $outcome `approve` or `deny`, as the "When no answer by the deadline" setting says.
		 * @param WC_Order_Item_Product $item    The order line.
		 * @param LineState             $line    The line's state and refs.
		 *
		 * @example
		 * add_filter( 'pixonaut_wc_approval_deadline_outcome', function ( $outcome, $item ) {
		 *     return $item->get_total() > 100 ? 'deny' : $outcome;
		 * }, 10, 2 );
		 */
		$outcome = apply_filters( 'pixonaut_wc_approval_deadline_outcome', $this->settings->get( Settings::PROOF_NO_ANSWER ), $item, $line );
		try {
			$done = ProofOutcome::APPROVE === $outcome ? $this->approve( $line, $order ) : $this->deny( $line, $order );
		} catch ( ApiException $error ) {
			if ( RetryPolicy::RETRY === RetryPolicy::decide( $error ) ) {
				$this->again( $line, $order, $error->errorCode, $error->retryAfter );
				return;
			}
			$done = $this->attention( LineState::of( $item ), $order, $error->errorCode );
		}
		if ( null === $done ) {
			$line = LineState::of( $item );
			if ( self::waiting( $line ) ) {
				// Another request wrote the line before this decision was saved: decide again.
				$this->again( $line, $order, self::LINE_CHANGED, null );
			}
			return;
		}

		/**
		 * Fires after a proof-by-email line's deadline passed without the
		 * customer's answer and the store decided it.
		 *
		 * @since 0.1.0
		 *
		 * @param WC_Order_Item_Product $item     The order line.
		 * @param string                $outcome  What became of it: `approve`, `refund_cancel`, `cancel`, `needs_attention` (denied, left to the merchant) or `unresolved` (the store could not decide; the line needs attention for the reason it records).
		 * @param int                   $deadline Unix time of the deadline.
		 *
		 * @example
		 * add_action( 'pixonaut_wc_approval_deadline_passed', function ( $item, $outcome ) {
		 *     error_log( "Item {$item->get_id()}: {$outcome} at the proof deadline" );
		 * }, 10, 2 );
		 */
		do_action( 'pixonaut_wc_approval_deadline_passed', $item, $done, $deadline );
	}

	/**
	 * Whether the line still waits for the customer, for a decision only the deadline makes.
	 *
	 * @param LineState $line The line.
	 */
	private static function waiting( LineState $line ): bool {
		return LineState::AWAITING_CUSTOMER === $line->state()
			&& ApprovalMode::PROOF_EMAIL === $line->ref( LineState::REF_APPROVAL_MODE )
			&& null === $line->ref( LineState::REF_CANCEL )
			&& null === $line->ref( LineState::REF_DENIAL );
	}

	/**
	 * Approves each design still waiting with consent `deadline`, then sends the line on.
	 *
	 * @param LineState $line  The line.
	 * @param WC_Order  $order Its order.
	 *
	 * @return string|null What became of the line; null when it changed elsewhere meanwhile.
	 *
	 * @throws ApiException When Pixonaut refused, other than for a decision the customer made first, or could not be reached.
	 */
	private function approve( LineState $line, WC_Order $order ): ?string {
		$consent = array(
			'kind' => self::CONSENT_KIND,
			'at'   => Milliseconds::of( ( $this->clock )() ),
		);
		$ref     = $line->ref( LineState::REF_EXTERNAL_REF );
		if ( is_string( $ref ) && '' !== $ref ) {
			$consent['ref'] = $ref;
		}
		$rejected = false;
		foreach ( $this->open_sides( $line ) as $side ) {
			$design_id = (string) $line->side_ref( $side, LineState::SIDE_DESIGN_ID );
			$revision  = (int) $line->side_ref( $side, LineState::SIDE_REVISION );
			try {
				$this->pixonaut->designs()->approve( $design_id, Designs::approval( $consent, $revision ) );
			} catch ( ApiException $error ) {
				if ( ErrorCode::REVISION_CHANGED === $error->errorCode ) {
					return $this->attention( $line, $order, NeedsAttention::DESIGN_CHANGED );
				}
				if ( ErrorCode::NOT_COMMITTED !== $error->errorCode ) {
					throw $error;
				}
				$status = $this->decided( $design_id, $revision );
				if ( self::STATUS_REJECTED === $status ) {
					$rejected = true;
					continue;
				}
				if ( self::STATUS_APPROVED !== $status ) {
					return $this->attention( $line, $order, NeedsAttention::DESIGN_CHANGED );
				}
			}
			$line->set_side_ref( $side, LineState::SIDE_APPROVED, true );
		}
		if ( $rejected ) {
			// The customer rejected it first: theirs is the decision that counts.
			return $line->save() ? $this->denial->deny( LineState::of( $line->item() ), $order, ProofDenial::CUSTOMER ) : null;
		}
		$line->set_ref( LineState::REF_CONSENT, $consent );
		return $this->approved(
			$line,
			$order,
			/* translators: %s: product name of the order line. */
			__( 'Pixonaut: the customer did not answer the proof of "%s" by the deadline, so its design was approved as shown.', 'pixonaut-for-woocommerce' )
		);
	}

	/**
	 * Rejects each design still waiting, then applies "On deny". A design
	 * the customer approved first counts as approved; once every one is,
	 * the line goes on instead.
	 *
	 * @param LineState $line  The line.
	 * @param WC_Order  $order Its order.
	 *
	 * @return string|null What became of the line; null when it changed elsewhere meanwhile.
	 *
	 * @throws ApiException When Pixonaut refused, other than for a decision the customer made first, or could not be reached.
	 */
	private function deny( LineState $line, WC_Order $order ): ?string {
		$open = $this->open_sides( $line );
		foreach ( $open as $side ) {
			$design_id = (string) $line->side_ref( $side, LineState::SIDE_DESIGN_ID );
			$revision  = (int) $line->side_ref( $side, LineState::SIDE_REVISION );
			try {
				$this->pixonaut->designs()->reject( $design_id );
			} catch ( ApiException $error ) {
				if ( ErrorCode::NOT_COMMITTED !== $error->errorCode ) {
					throw $error;
				}
				$status = $this->decided( $design_id, $revision );
				if ( self::STATUS_APPROVED === $status ) {
					$line->set_side_ref( $side, LineState::SIDE_APPROVED, true );
				} elseif ( self::STATUS_REJECTED !== $status ) {
					// Neither decision at the line's revision: nothing to deny or approve without the merchant.
					return $this->attention( $line, $order, NeedsAttention::DESIGN_CHANGED );
				}
			}
		}
		if ( array() === $this->open_sides( $line ) ) {
			return $this->approved(
				$line,
				$order,
				/* translators: %s: product name of the order line. */
				__( 'Pixonaut: the customer approved the design of "%s" just before its deadline.', 'pixonaut-for-woocommerce' )
			);
		}
		if ( ! $line->save() ) {
			return null;
		}
		return $this->denial->deny( LineState::of( $line->item() ), $order, ProofDenial::DEADLINE );
	}

	/**
	 * The status a design the customer decided first holds at the line's revision.
	 *
	 * @param string $design_id The design.
	 * @param int    $revision  The revision the line holds.
	 *
	 * @return string|null `approved` or `rejected`; null for any other status, or another revision.
	 *
	 * @throws ApiException When the design could not be read.
	 */
	private function decided( string $design_id, int $revision ): ?string {
		$design = $this->pixonaut->designs()->get( $design_id );
		if ( self::STATUS_REJECTED === $design->status ) {
			return self::STATUS_REJECTED;
		}
		return self::STATUS_APPROVED === $design->status && $design->revision === $revision ? self::STATUS_APPROVED : null;
	}

	/**
	 * Moves the line, every design approved, to `approved` and schedules its fulfilment.
	 *
	 * @param LineState $line  The line.
	 * @param WC_Order  $order Its order.
	 * @param string    $note  A translated order note with `%s` for the product name.
	 *
	 * @return string|null `approve`; null when the line changed elsewhere meanwhile.
	 */
	private function approved( LineState $line, WC_Order $order, string $note ): ?string {
		if ( ! $line->transition( LineState::APPROVED ) ) {
			return null;
		}
		$order->add_order_note( sprintf( $note, $line->item()->get_name() ) );
		if ( ! FulfilmentJobs::schedule( $line->item()->get_id() ) ) {
			// The line is approved; Pixonaut's `design.approved` schedules the job again.
			$this->logger->error( 'The fulfilment job of a line approved at its proof deadline could not be scheduled.', array( 'item' => $line->item()->get_id() ) );
		}
		return ProofOutcome::APPROVE;
	}

	/**
	 * @param LineState $line  The line.
	 * @param WC_Order  $order Its order.
	 * @param string    $code  What stopped it.
	 *
	 * @return string|null `unresolved`; null when the line changed elsewhere meanwhile.
	 */
	private function attention( LineState $line, WC_Order $order, string $code ): ?string {
		$flagged = NeedsAttention::flag(
			$line,
			$order,
			$code,
			sprintf(
				/* translators: 1: product name of the order line, 2: why, as NeedsAttention explains it. */
				__( 'Pixonaut: the proof deadline of "%1$s" passed, but the store could not decide it. %2$s', 'pixonaut-for-woocommerce' ),
				$line->item()->get_name(),
				NeedsAttention::explain( $code )
			)
		);
		return $flagged ? ProofOutcome::UNRESOLVED : null;
	}

	/**
	 * Schedules the job again on the fulfilment backoff; once that is used
	 * up, the line needs the merchant's attention.
	 *
	 * @param LineState $line        The line.
	 * @param WC_Order  $order       Its order.
	 * @param string    $code        Why it is tried again.
	 * @param int|null  $retry_after Seconds Pixonaut asked to wait, when it did.
	 */
	private function again( LineState $line, WC_Order $order, string $code, ?int $retry_after ): void {
		$item     = $line->item();
		$line     = LineState::of( $item );
		$deadline = (array) $line->ref( LineState::REF_DEADLINE );
		$failures = is_int( $deadline['failures'] ?? null ) ? $deadline['failures'] : 0;
		$delay    = RetryPolicy::delay( $failures, $retry_after );
		if ( null !== $delay ) {
			$deadline['failures'] = $failures + 1;
			$line->set_ref( LineState::REF_DEADLINE, $deadline );
			// A line changed meanwhile keeps its own count; the job is scheduled all the same.
			$line->save();
			if ( 0 !== as_schedule_single_action( ( $this->clock )() + $delay, self::ACTION, array( 'item_id' => $item->get_id() ), FulfilmentJobs::GROUP ) ) {
				$this->logger->warning(
					'Proof deadline not decided; retrying later.',
					array(
						'item'     => $item->get_id(),
						'code'     => $code,
						'failures' => $failures + 1,
						'delay'    => $delay,
					)
				);
				return;
			}
		}
		$this->attention( LineState::of( $item ), $order, null === $delay ? NeedsAttention::RETRIES_EXHAUSTED : NeedsAttention::RETRY_NOT_SCHEDULED );
	}

	/**
	 * The sides whose design the customer has not approved yet.
	 *
	 * @param LineState $line The line.
	 * @return list<string>
	 */
	private function open_sides( LineState $line ): array {
		return array_values( array_filter( $line->sides(), static fn ( string $side ): bool => true !== $line->side_ref( $side, LineState::SIDE_APPROVED ) ) );
	}

	/**
	 * Whether the store may act on the line in Pixonaut: it is connected, to
	 * the workspace the line was made under.
	 *
	 * @param LineState $line The line.
	 */
	private function acts_for( LineState $line ): bool {
		$connection = ( $this->connection )();
		return $connection instanceof StoredConnection && $line->ref( LineState::REF_TENANT_ID ) === $connection->tenant_id;
	}

	/**
	 * Unix time of the line's deadline; 0 when none is stored.
	 *
	 * @param LineState $line The line.
	 */
	public static function deadline_of( LineState $line ): int {
		$deadline = $line->ref( LineState::REF_DEADLINE );
		return is_array( $deadline ) && is_int( $deadline['at'] ?? null ) ? $deadline['at'] : 0;
	}
}
