<?php
/**
 * Erases a line's personal data in Pixonaut.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Privacy;

use Closure;
use Pixonaut\WooCommerce\Api\ApiError;
use Pixonaut\WooCommerce\Api\Designs;
use Pixonaut\WooCommerce\Api\ErrorCodes;
use Pixonaut\WooCommerce\Api\Logger;
use Pixonaut\WooCommerce\Api\Orders;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use Pixonaut\WooCommerce\Fulfilment\FulfilmentJobs;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Fulfilment\OrderStatus;
use Pixonaut\WooCommerce\Fulfilment\RetryPolicy;
use Pixonaut\WooCommerce\Milliseconds;
use RuntimeException;
use WC_Order;
use WC_Order_Factory;
use WC_Order_Item_Product;

/**
 * Erases what the customer put into a line's designs, and the delivery its
 * printshop order was sent to, in Pixonaut:
 * `POST /v1/designs/{id}/erase-personal-data` for each side, then
 * `POST /v1/orders/{id}/erase-personal-data` for the printshop order and
 * any failed one a retry replaced, found under the line's ref.
 *
 * A paid line is never erased mid-fulfilment, since an erased design is never
 * printed or ordered again: the request is stored on the line, and its job
 * runs once the line has shipped, failed or been cancelled, or its printshop
 * order failed. A line whose order was never paid is erased at once. Refusals Pixonaut may lift (a busy
 * design, an order still on its way) are asked again on the fulfilment waits.
 */
final class LineErasure {

	/**
	 * Action Scheduler hook of the job, with the order item id as its one argument.
	 */
	public const ACTION = 'pixonaut_erase_item';

	// What a request came to.
	public const ERASED     = 'erased';
	public const ALREADY    = 'already';
	public const WAITING    = 'waiting';
	public const PENDING    = 'pending';
	public const NOT_ERASED = 'not_erased';

	/**
	 * States a line is done in: nothing more is printed or ordered for it.
	 * A line whose printshop order failed is done too, in `needs_attention`
	 * (`is_finished()`).
	 */
	public const FINISHED = array( LineState::SHIPPED, LineState::FAILED, LineState::CANCELLED );

	/**
	 * Tries to save a change when another request wrote the line meanwhile.
	 */
	private const MAX_ATTEMPTS = 3;

	/**
	 * Why the job is tried again when the line changed while it ran, for the log.
	 */
	private const LINE_CHANGED = 'line_changed';

	/**
	 * @param Designs $designs    Erases designs.
	 * @param Orders  $orders     Erases printshop orders.
	 * @param Closure $connection `fn(): ?StoredConnection`, the connection to act for; null while there is none or it is paused.
	 * @param Logger  $logger     Logs erasures Pixonaut refused or the store could not schedule.
	 */
	public function __construct(
		private readonly Designs $designs,
		private readonly Orders $orders,
		private readonly Closure $connection,
		private readonly Logger $logger,
	) {
	}

	public function register(): void {
		add_action( self::ACTION, array( $this, 'run' ) );
		add_action( 'pixonaut_wc_fulfilment_transition', array( $this, 'on_transition' ), 10, 4 );
	}

	/**
	 * Whether the line was paid for: its order was, or it went to the printshop.
	 *
	 * @param LineState $line The line.
	 */
	public static function is_paid( LineState $line ): bool {
		if ( null !== $line->ref( LineState::REF_ORDER_ID ) || null !== $line->ref( LineState::REF_ORDER_REQUEST ) ) {
			return true;
		}
		$order = wc_get_order( $line->item()->get_order_id() );
		return $order instanceof WC_Order && ( $order->is_paid() || null !== $order->get_date_paid() );
	}

	/**
	 * Whether every design of the line, and its printshop order, is erased.
	 *
	 * @param LineState $line The line.
	 */
	public static function is_erased( LineState $line ): bool {
		foreach ( self::design_sides( $line ) as $side ) {
			if ( null === $line->side_ref( $side, LineState::SIDE_ERASED_AT ) ) {
				return false;
			}
		}
		return null === self::order_to_erase( $line );
	}

	/**
	 * Asks for the line's personal data to be erased: erased now when the
	 * line was never paid for or is finished, else stored for when it is.
	 *
	 * @param LineState $line The line.
	 *
	 * @return string ERASED, ALREADY, WAITING (until the line is finished), PENDING (asked again later) or NOT_ERASED (the order notes say why).
	 */
	public function request( LineState $line ): string {
		if ( self::is_erased( $line ) ) {
			return self::ALREADY;
		}
		$item = $line->item();
		if ( ! $this->acts_for( $line ) ) {
			return $this->not_connected( $item );
		}
		try {
			$line = $this->update(
				$item,
				static function ( LineState $line ): bool {
					if ( is_array( $line->ref( LineState::REF_ERASE ) ) ) {
						return false;
					}
					$line->set_ref( LineState::REF_ERASE, array( 'failures' => 0 ) );
					return true;
				}
			);
		} catch ( RuntimeException $e ) {
			$this->logger->error( 'Erasure not stored: ' . $e->getMessage(), array( 'item' => $item->get_id() ) );
			return self::NOT_ERASED;
		}
		if ( self::waits( $line ) ) {
			return self::WAITING;
		}
		return $this->erase( $line );
	}

	/**
	 * Schedules the job of a line asked to be erased once it is finished.
	 *
	 * @param string                $to   The line's new state.
	 * @param string                $from Its previous state.
	 * @param WC_Order_Item_Product $item The line.
	 * @param LineState             $line Its state and refs.
	 */
	public function on_transition( string $to, string $from, WC_Order_Item_Product $item, LineState $line ): void {
		if ( ! self::is_finished( $line ) || ! is_array( $line->ref( LineState::REF_ERASE ) ) ) {
			return;
		}
		$args = array( 'item_id' => $item->get_id() );
		if ( false === as_has_scheduled_action( self::ACTION, $args, FulfilmentJobs::GROUP ) && 0 === as_enqueue_async_action( self::ACTION, $args, FulfilmentJobs::GROUP ) ) {
			$this->logger->error( 'Erasure could not be scheduled.', array( 'item' => $item->get_id() ) );
			$this->note(
				$item,
				/* translators: %s: product name of the order line. */
				__( 'Pixonaut: the customer\'s personal data in "%s" is not erased yet: the store could not schedule it. Run the erasure again from Tools > Erase Personal Data.', 'pixonaut-for-woocommerce' )
			);
		}
	}

	/**
	 * The job: erases the line once it may be.
	 *
	 * @param int|string $item_id The order item.
	 */
	public function run( int|string $item_id ): void {
		$item = WC_Order_Factory::get_order_item( (int) $item_id );
		if ( ! $item instanceof WC_Order_Item_Product || ! LineState::is_tracked( $item ) ) {
			return;
		}
		$line = LineState::of( $item );
		if ( ! is_array( $line->ref( LineState::REF_ERASE ) ) || self::waits( $line ) ) {
			return;
		}
		$this->erase( $line );
	}

	/**
	 * Whether the line must be kept until it is finished: it was paid for, so
	 * its designs may still be printed and its order sent.
	 *
	 * @param LineState $line The line.
	 */
	private static function waits( LineState $line ): bool {
		return ! self::is_finished( $line ) && self::is_paid( $line );
	}

	/**
	 * Whether nothing more is printed or ordered for the line: it is in a
	 * FINISHED state, or its printshop order failed. Retrying an erased
	 * line's failed order is refused, as its designs are erased.
	 *
	 * @param LineState $line The line.
	 */
	private static function is_finished( LineState $line ): bool {
		return in_array( $line->state(), self::FINISHED, true )
			|| ( LineState::NEEDS_ATTENTION === $line->state() && OrderStatus::FAILED === $line->ref( LineState::REF_ORDER_STATUS ) );
	}

	/**
	 * Erases each design not erased yet, then the printshop order. A line
	 * paid for meanwhile stops before its next design, and waits.
	 *
	 * @param LineState $line The line, asked to be erased.
	 *
	 * @return string ERASED, WAITING, PENDING or NOT_ERASED.
	 */
	private function erase( LineState $line ): string {
		$item = $line->item();
		if ( ! $this->acts_for( $line ) ) {
			return $this->not_connected( $item );
		}
		try {
			$sent = false;
			foreach ( self::design_sides( $line ) as $side ) {
				$design_id = (string) $line->side_ref( $side, LineState::SIDE_DESIGN_ID );
				if ( null !== $line->side_ref( $side, LineState::SIDE_ERASED_AT ) ) {
					continue;
				}
				if ( $sent ) {
					// The order may have been paid while the previous design was erased; the request stays for when the line is finished.
					$line = LineState::of( $item );
					if ( self::waits( $line ) ) {
						return self::WAITING;
					}
				}
				$sent      = true;
				$erased_at = $this->designs->erase_personal_data( $design_id ) ?? Milliseconds::of( time() );
				$line      = $this->update(
					$item,
					static function ( LineState $line ) use ( $design_id, $erased_at ): bool {
						$side = $line->side_of_design( $design_id );
						if ( null === $side || null !== $line->side_ref( $side, LineState::SIDE_ERASED_AT ) ) {
							return false;
						}
						$line->set_side_ref( $side, LineState::SIDE_ERASED_AT, $erased_at );
						return true;
					}
				);
			}
			$order_id = self::order_to_erase( $line );
			if ( null !== $order_id ) {
				$erased_at = 0;
				foreach ( $this->orders_of( $line, $order_id ) as $placed ) {
					$erased_at = max( $erased_at, $this->erase_order( $placed ) );
				}
				$this->update(
					$item,
					static function ( LineState $line ) use ( $erased_at ): bool {
						$line->set_ref( LineState::REF_ORDER_ERASED_AT, $erased_at );
						// Fingerprints of the erased address, and the request of a change holding it.
						$line->set_ref( LineState::REF_DELIVERY, null );
						return true;
					}
				);
			}
			$this->update(
				$item,
				static function ( LineState $line ): bool {
					$line->set_ref( LineState::REF_ERASE, null );
					return true;
				}
			);
		} catch ( ApiError $e ) {
			return $this->refused( $item, $e );
		} catch ( RuntimeException $e ) {
			if ( $this->again( $item, self::LINE_CHANGED, null ) ) {
				return self::PENDING;
			}
			$this->logger->error( 'Erasure given up: ' . $e->getMessage(), array( 'item' => $item->get_id() ) );
			return self::NOT_ERASED;
		}
		$this->note(
			$item,
			/* translators: %s: product name of the order line. */
			__( 'Pixonaut: the customer\'s personal data in "%s" was erased in Pixonaut.', 'pixonaut-for-woocommerce' )
		);
		return self::ERASED;
	}

	/**
	 * The line's printshop order, the orders it replaced, and every other
	 * order placed under its ref: a failed order a retry replaced keeps the
	 * delivery it was sent to.
	 *
	 * @param LineState $line     The line.
	 * @param string    $order_id Its printshop order.
	 *
	 * @return list<string>
	 *
	 * @throws ApiError When Pixonaut could not be asked.
	 */
	private function orders_of( LineState $line, string $order_id ): array {
		// Replaced orders are listed too: one the line took on was placed under another ref.
		$ids = array_merge( array( $order_id ), $line->replaced_order_ids() );
		$ref = $line->ref( LineState::REF_EXTERNAL_REF );
		if ( is_string( $ref ) && '' !== $ref ) {
			foreach ( $this->orders->with_external_ref( $ref ) as $order ) {
				if ( is_string( $order['id'] ?? null ) && '' !== $order['id'] ) {
					$ids[] = $order['id'];
				}
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * @param string $order_id A printshop order.
	 *
	 * @return int When its personal data was erased, epoch milliseconds; now for an order Pixonaut no longer has.
	 *
	 * @throws ApiError When Pixonaut refused otherwise or could not be reached.
	 */
	private function erase_order( string $order_id ): int {
		try {
			$erased = $this->orders->erase_personal_data( $order_id )['personalDataErasedAt'] ?? null;
		} catch ( ApiError $e ) {
			if ( ! in_array( $e->error_code, array( ErrorCodes::ORDER_NOT_FOUND, ErrorCodes::NOT_FOUND ), true ) ) {
				throw $e;
			}
			$erased = null;
		}
		return is_int( $erased ) || is_float( $erased ) ? (int) $erased : Milliseconds::of( time() );
	}

	/**
	 * What a refused erasure comes to: asked again while Pixonaut may lift
	 * the refusal, else given up with a note.
	 *
	 * @param WC_Order_Item_Product $item  The line.
	 * @param ApiError              $error The refusal.
	 *
	 * @return string PENDING or NOT_ERASED.
	 */
	private function refused( WC_Order_Item_Product $item, ApiError $error ): string {
		$retries = ErrorCodes::ORDER_IN_FLIGHT === $error->error_code || RetryPolicy::RETRY === RetryPolicy::decide( $error );
		if ( $retries && $this->again( $item, $error->error_code, $error->retry_after ) ) {
			return self::PENDING;
		}
		$this->logger->warning(
			'Erasure refused by Pixonaut.',
			array(
				'item'      => $item->get_id(),
				'operation' => $error->operation,
				'status'    => $error->status,
				'code'      => $error->error_code,
			)
		);
		return $this->give_up(
			$item,
			/* translators: 1: product name of the order line, 2: the error code Pixonaut answered. */
			__( 'Pixonaut: the customer\'s personal data in "%1$s" could not be erased in Pixonaut, which answered "%2$s". Run the erasure again from Tools > Erase Personal Data.', 'pixonaut-for-woocommerce' ),
			$error->error_code
		);
	}

	/**
	 * Schedules the line's job again on the fulfilment backoff.
	 *
	 * @param WC_Order_Item_Product $item        The line.
	 * @param string                $code        Why it is tried again.
	 * @param int|null              $retry_after Seconds Pixonaut asked to wait, when it did.
	 *
	 * @return bool False when the backoff is used up or the job could not be scheduled.
	 */
	private function again( WC_Order_Item_Product $item, string $code, ?int $retry_after ): bool {
		$line     = LineState::of( $item );
		$erase    = $line->ref( LineState::REF_ERASE );
		$failures = is_array( $erase ) && is_int( $erase['failures'] ?? null ) ? $erase['failures'] : 0;
		$delay    = RetryPolicy::delay( $failures, $retry_after );
		if ( null === $delay ) {
			return false;
		}
		$line->set_ref( LineState::REF_ERASE, array( 'failures' => $failures + 1 ) );
		// A line changed meanwhile keeps its own count; the job is scheduled all the same.
		$line->save();
		if ( 0 === as_schedule_single_action( time() + $delay, self::ACTION, array( 'item_id' => $item->get_id() ), FulfilmentJobs::GROUP ) ) {
			$this->logger->error( 'Erasure retry could not be scheduled.', array( 'item' => $item->get_id() ) );
			return false;
		}
		$this->logger->warning(
			'Erasure failed; retrying later.',
			array(
				'item'     => $item->get_id(),
				'code'     => $code,
				'failures' => $failures + 1,
				'delay'    => $delay,
			)
		);
		return true;
	}

	/**
	 * Gives up on a line the store may not act on in Pixonaut.
	 *
	 * @param WC_Order_Item_Product $item The line.
	 *
	 * @return string NOT_ERASED.
	 */
	private function not_connected( WC_Order_Item_Product $item ): string {
		return $this->give_up(
			$item,
			/* translators: %s: product name of the order line. */
			__( 'Pixonaut: the customer\'s personal data in "%s" was not erased: the store is not connected to the Pixonaut workspace it was ordered under. Erase it in Pixonaut.', 'pixonaut-for-woocommerce' )
		);
	}

	/**
	 * Drops the request and notes why the line was not erased.
	 *
	 * @param WC_Order_Item_Product $item    The line.
	 * @param string                $note    A translated note with `%1$s` (or `%s`) for the product name.
	 * @param string                ...$args The note's further values.
	 *
	 * @return string NOT_ERASED.
	 */
	private function give_up( WC_Order_Item_Product $item, string $note, string ...$args ): string {
		try {
			$this->update(
				$item,
				static function ( LineState $line ): bool {
					$line->set_ref( LineState::REF_ERASE, null );
					return true;
				}
			);
		} catch ( RuntimeException $e ) {
			$this->logger->error( 'Erasure request not dropped: ' . $e->getMessage(), array( 'item' => $item->get_id() ) );
		}
		$this->note( $item, $note, ...$args );
		return self::NOT_ERASED;
	}

	/**
	 * Applies a change to the line as stored, reading it again and retrying
	 * when another request wrote it meanwhile.
	 *
	 * @param WC_Order_Item_Product $item   The line.
	 * @param Closure               $change `fn(LineState): bool`, changes the line; false when there is nothing to save.
	 * @phpstan-param Closure(LineState): bool $change
	 *
	 * @return LineState The line as saved.
	 *
	 * @throws RuntimeException When the line kept changing.
	 */
	private function update( WC_Order_Item_Product $item, Closure $change ): LineState {
		for ( $attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++ ) {
			$line = LineState::of( $item );
			if ( ! $change( $line ) || $line->save() ) {
				return $line;
			}
		}
		throw new RuntimeException( sprintf( 'Erasure of order item %d not saved: the line kept changing.', (int) $item->get_id() ) );
	}

	/**
	 * The sides holding a design.
	 *
	 * @param LineState $line The line.
	 *
	 * @return list<string>
	 */
	private static function design_sides( LineState $line ): array {
		return array_values(
			array_filter(
				$line->sides(),
				static function ( string $side ) use ( $line ): bool {
					$design_id = $line->side_ref( $side, LineState::SIDE_DESIGN_ID );
					return is_string( $design_id ) && '' !== $design_id;
				}
			)
		);
	}

	/**
	 * The line's printshop order, while its personal data is not erased.
	 *
	 * @param LineState $line The line.
	 */
	private static function order_to_erase( LineState $line ): ?string {
		$order_id = $line->ref( LineState::REF_ORDER_ID );
		return is_string( $order_id ) && '' !== $order_id && null === $line->ref( LineState::REF_ORDER_ERASED_AT ) ? $order_id : null;
	}

	/**
	 * Whether the store may act on the line in Pixonaut: it is connected, to
	 * the workspace the line was made under.
	 *
	 * @param LineState $line The line.
	 */
	private function acts_for( LineState $line ): bool {
		$connection = ( $this->connection )();
		$tenant     = $line->ref( LineState::REF_TENANT_ID );
		return $connection instanceof StoredConnection && ( null === $tenant || $tenant === $connection->tenant_id );
	}

	/**
	 * @param WC_Order_Item_Product $item    The line.
	 * @param string                $message A translated message with `%1$s` (or `%s`) for the product name.
	 * @param string                ...$args The message's further values.
	 */
	private function note( WC_Order_Item_Product $item, string $message, string ...$args ): void {
		$order = wc_get_order( $item->get_order_id() );
		if ( $order instanceof WC_Order ) {
			$order->add_order_note( sprintf( $message, $item->get_name(), ...$args ) );
		}
	}
}
