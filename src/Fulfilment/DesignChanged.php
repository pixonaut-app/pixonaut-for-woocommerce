<?php
/**
 * Applies changes made to a line's design outside the store.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Fulfilment;

use Closure;
use Pixonaut\WooCommerce\Api\Designs;
use Pixonaut\WooCommerce\Connection\Connection;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\ErrorCode;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Pixonaut as Sdk;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Webhooks\Event;
use Pixonaut\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Pixonaut\WooCommerce\Webhooks\Payload;
use RuntimeException;
use WC_Order;

/**
 * `design.edited`, `design.deleted` and `design.personal_data_erased` for a
 * design one of the store's lines holds, and the same rules for a read of the
 * design, so a line is never left out of step with it:
 *
 * - A newer revision of a line not approved yet is kept, and the line's
 *   approval mode runs again on it. On a line approved but not ordered yet,
 *   whose print may be of the old revision, the line needs attention. On a
 *   line ordered already, it is noted only.
 * - A deleted design makes an unfinished line need attention; a finished one
 *   is noted only.
 * - An erased design is marked on the line; its state does not change.
 *
 * Each change is noted on the order once, however often it is reported.
 */
final class DesignChanged {

	public const EDITED = 'design.edited';

	public const DELETED = 'design.deleted';

	public const ERASED = 'design.personal_data_erased';

	/**
	 * States a newer revision is taken in, and the approval runs again on.
	 */
	private const NOT_APPROVED = array( LineState::COMMITTED, LineState::AWAITING_CUSTOMER );

	/**
	 * States whose print may be of the revision the customer approved.
	 */
	private const APPROVED_NOT_ORDERED = array( LineState::APPROVED, LineState::PRINTED );

	/**
	 * States past the printshop order: a change is noted only.
	 */
	private const ORDERED = array( LineState::ORDERED, LineState::SHIPPED );

	/**
	 * States a line is done in: a deleted design is noted only.
	 */
	private const FINISHED = array( LineState::SHIPPED, LineState::CANCELLED, LineState::FAILED );

	/**
	 * Tries to apply a change when another request wrote the line meanwhile.
	 */
	private const MAX_ATTEMPTS = 3;

	/**
	 * @param Connection      $connection The store's connection; a line of another workspace is never acted on.
	 * @param Sdk             $pixonaut   Reads a design afresh.
	 * @param Closure         $schedule   `fn(int $item_id): bool`, queues a line's fulfilment job again; false when it could not be queued.
	 * @param LoggerInterface $logger     Logs a fulfilment job that could not be queued.
	 */
	public function __construct(
		private readonly Connection $connection,
		private readonly Sdk $pixonaut,
		private readonly Closure $schedule,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * @param Event $event `design.edited`, `design.deleted` or `design.personal_data_erased`.
	 * @throws ApiException When the design could not be read again. A RuntimeException when a line kept changing while the change was applied; handling the event again retries either.
	 */
	public function handle( Event $event ): void {
		$design_id = Payload::string( $event, 'designId' );
		if ( null === $design_id ) {
			return;
		}
		$lines = array_values( array_filter( LineState::for_design_id( $design_id ), array( $this, 'belongs_here' ) ) );
		if ( array() === $lines ) {
			return;
		}
		if ( self::ERASED === $event->type ) {
			$this->erased( $lines, $design_id, $event->createdAt );
			return;
		}
		if ( self::DELETED === $event->type ) {
			$this->deleted( $lines, $design_id, $event->createdAt );
			return;
		}
		// The event may be older than the design: what counts is its revision now.
		try {
			$design = $this->pixonaut->designs()->get( $design_id )->raw();
		} catch ( ApiException $e ) {
			if ( ErrorCode::NOT_FOUND !== $e->errorCode ) {
				throw $e;
			}
			$this->deleted( $lines, $design_id, $event->createdAt );
			return;
		}
		$this->apply_read( $lines, $design_id, $design );
	}

	/**
	 * Applies what a read of the design answered.
	 *
	 * @param LineState[]          $lines     Lines holding the design.
	 * @param string               $design_id The design.
	 * @param array<string, mixed> $design    `GET /v1/designs/{id}`'s answer.
	 * @phpstan-param list<LineState> $lines
	 * @throws RuntimeException When a line kept changing.
	 */
	public function apply_read( array $lines, string $design_id, array $design ): void {
		$revision = $design['revision'] ?? null;
		if ( is_int( $revision ) ) {
			$this->edited( $lines, $design_id, $revision );
		}
		$erased = $design[ Designs::PERSONAL_DATA_ERASED_AT ] ?? null;
		if ( is_int( $erased ) || is_float( $erased ) ) {
			$this->erased( $lines, $design_id, (int) $erased );
		}
	}

	/**
	 * @param LineState[] $lines      Lines holding the design.
	 * @param string      $design_id  The design.
	 * @param int         $deleted_at When it was deleted, epoch milliseconds.
	 * @phpstan-param list<LineState> $lines
	 * @throws RuntimeException When a line kept changing.
	 */
	public function deleted( array $lines, string $design_id, int $deleted_at ): void {
		foreach ( $lines as $line ) {
			$this->change(
				$line,
				$design_id,
				function ( LineState $line, string $side ) use ( $deleted_at ): string|false|null {
					if ( null !== $line->side_ref( $side, LineState::SIDE_DELETED_AT ) ) {
						return null;
					}
					$line->set_side_ref( $side, LineState::SIDE_DELETED_AT, $deleted_at );
					if ( in_array( $line->state(), self::FINISHED, true ) ) {
						$saved = $line->save();
						/* translators: %s: product name */
						return $saved ? __( 'Pixonaut: the design for "%s" was deleted in Pixonaut.', 'pixonaut-for-woocommerce' ) : false;
					}
					// A line needing attention already stays so; the deletion is recorded and noted.
					$saved = LineState::NEEDS_ATTENTION === $line->state() ? $line->save() : $line->transition( LineState::NEEDS_ATTENTION, NeedsAttention::DESIGN_DELETED );
					/* translators: %s: product name */
					return $saved ? __( 'Pixonaut: the design for "%s" was deleted in Pixonaut, so the item cannot be printed. Check the order with the customer.', 'pixonaut-for-woocommerce' ) : false;
				}
			);
		}
	}

	/**
	 * @param LineState[] $lines     Lines holding the design.
	 * @param string      $design_id The design.
	 * @param int         $revision  Its revision now.
	 * @phpstan-param list<LineState> $lines
	 * @throws RuntimeException When a line kept changing.
	 */
	private function edited( array $lines, string $design_id, int $revision ): void {
		foreach ( $lines as $line ) {
			$rerun = false;
			$this->change(
				$line,
				$design_id,
				function ( LineState $line, string $side ) use ( $revision, &$rerun ): string|false|null {
					$known = max( (int) $line->side_ref( $side, LineState::SIDE_REVISION ), (int) $line->side_ref( $side, LineState::SIDE_SEEN_REVISION ) );
					if ( $revision <= $known ) {
						return null;
					}
					$line->set_side_ref( $side, LineState::SIDE_SEEN_REVISION, $revision );
					$state = $line->state();
					if ( in_array( $state, self::NOT_APPROVED, true ) ) {
						$line->set_side_ref( $side, LineState::SIDE_REVISION, $revision );
						// The values the customer chose describe the revision they approved, not this one.
						$line->set_side_ref( $side, LineState::SIDE_FIELDS, null );
						$rerun = $line->save();
						/* translators: %s: product name */
						return $rerun ? __( 'Pixonaut: the design for "%s" was changed in Pixonaut. The changed design goes to approval again.', 'pixonaut-for-woocommerce' ) : false;
					}
					if ( in_array( $state, self::APPROVED_NOT_ORDERED, true ) ) {
						$saved = $line->transition( LineState::NEEDS_ATTENTION, NeedsAttention::DESIGN_EDITED );
						/* translators: %s: product name */
						return $saved ? __( 'Pixonaut: the design for "%s" was changed in Pixonaut after it was approved, so it was not sent to the printshop. Check the design before it is printed.', 'pixonaut-for-woocommerce' ) : false;
					}
					if ( ! $line->save() ) {
						return false;
					}
					if ( in_array( $state, self::ORDERED, true ) ) {
						/* translators: %s: product name */
						return __( 'Pixonaut: the design for "%s" was changed in Pixonaut after it was ordered. The printshop order is unchanged.', 'pixonaut-for-woocommerce' );
					}
					/* translators: %s: product name */
					return __( 'Pixonaut: the design for "%s" was changed in Pixonaut.', 'pixonaut-for-woocommerce' );
				}
			);
			if ( $rerun ) {
				$this->approve_again( $line );
			}
		}
	}

	/**
	 * Marks the design erased on every line holding it; nothing else changes.
	 *
	 * @param LineState[] $lines     Lines holding the design.
	 * @param string      $design_id The design.
	 * @param int         $erased_at When it was erased, epoch milliseconds.
	 * @phpstan-param list<LineState> $lines
	 * @throws RuntimeException When a line kept changing.
	 */
	private function erased( array $lines, string $design_id, int $erased_at ): void {
		foreach ( $lines as $line ) {
			$this->change(
				$line,
				$design_id,
				static function ( LineState $line, string $side ) use ( $erased_at ): string|false|null {
					if ( null !== $line->side_ref( $side, LineState::SIDE_ERASED_AT ) ) {
						return null;
					}
					$line->set_side_ref( $side, LineState::SIDE_ERASED_AT, $erased_at );
					return $line->save() ? '' : false;
				}
			);
		}
	}

	/**
	 * Runs $apply on the line's sides holding the design, reading the line
	 * again and retrying when another request wrote it meanwhile, then notes
	 * what changed on the order.
	 *
	 * @param LineState                                       $line      The line.
	 * @param string                                          $design_id The design.
	 * @param Closure(LineState, string): (string|false|null) $apply     Changes and saves the line: the note to add (`%s` for the product name; '' for none), null when nothing changes, false when the save lost to another write.
	 * @throws RuntimeException When the line kept changing for MAX_ATTEMPTS tries.
	 */
	private function change( LineState $line, string $design_id, Closure $apply ): void {
		$item = $line->item();
		for ( $attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++ ) {
			$current = LineState::of( $item );
			$side    = self::side_of( $current, $design_id );
			if ( null === $side ) {
				return;
			}
			$note = $apply( $current, $side );
			if ( false === $note ) {
				continue;
			}
			if ( is_string( $note ) && '' !== $note ) {
				$order = wc_get_order( $item->get_order_id() );
				if ( $order instanceof WC_Order ) {
					$order->add_order_note( sprintf( $note, $item->get_name() ) );
				}
			}
			return;
		}
		throw new RuntimeException( sprintf( 'Design change not applied to order item %d: the line kept changing.', (int) $item->get_id() ) );
	}

	/**
	 * Runs the line's approval mode again on its new revision: the line's job
	 * approves it with the customer's consent, or waits for the customer to
	 * approve the proof. Nothing runs before the order is paid. A job already
	 * running may hold the line as it was, so a new one is queued after it.
	 *
	 * @param LineState $line The line.
	 */
	private function approve_again( LineState $line ): void {
		$order   = wc_get_order( $line->item()->get_order_id() );
		$item_id = (int) $line->item()->get_id();
		if ( $order instanceof WC_Order && $order->is_paid() && ! ( $this->schedule )( $item_id ) ) {
			// The edit is kept and the order note says the design goes to approval again.
			$this->logger->error( 'The fulfilment job of an edited design could not be queued.', array( 'item' => $item_id ) );
		}
	}

	/**
	 * The side holding the design.
	 *
	 * @param LineState $line      The line.
	 * @param string    $design_id The design.
	 */
	private static function side_of( LineState $line, string $design_id ): ?string {
		foreach ( $line->sides() as $side ) {
			if ( $line->side_ref( $side, LineState::SIDE_DESIGN_ID ) === $design_id ) {
				return $side;
			}
		}
		return null;
	}

	/**
	 * Whether the line belongs to the connected workspace.
	 *
	 * @param LineState $line The line.
	 */
	public function belongs_here( LineState $line ): bool {
		$connection = $this->connection->current();
		$tenant     = $line->ref( LineState::REF_TENANT_ID );
		return null !== $connection && ( null === $tenant || $tenant === $connection->tenant_id );
	}
}
