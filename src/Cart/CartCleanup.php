<?php
/**
 * Deletes the designs of cart lines that will never be ordered.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Cart;

use ActionScheduler;
use ActionScheduler_Action;
use ActionScheduler_Store;
use Pixonaut\WooCommerce\Api\Designs;
use Pixonaut\WooCommerce\Connection\Connection;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Fulfilment\RetryPolicy;
use Pixonaut\WooCommerce\Schema;
use Pixonaut\WooCommerce\Storefront\PersonalisedDesigns;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\ErrorCode;
use Pixonaut\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use WC_Cart;
use WC_Order;

/**
 * A design committed at add to cart lives in Pixonaut until it is ordered or
 * deleted. Each way a line leaves the cart without an order schedules one
 * Action Scheduler job per design, which sends `DELETE /v1/designs/{id}`:
 *
 * - The customer removes the line. The job waits REMOVAL_GRACE_SECONDS, as
 *   WooCommerce offers to undo a removal; undoing it, or adding the same
 *   designs again, cancels the job and any retry of it.
 * - WooCommerce drops the line while loading the cart, as its product can no
 *   longer be bought.
 * - The cart is emptied. A logged-in customer's cart emptied without its
 *   persistent copy (logging out) comes back at the next login, so it is left.
 * - The session expires. Its lines, and the designs kept for product pages
 *   (`PersonalisedDesigns`), are read before WooCommerce deletes it, except
 *   those still in the customer's persistent cart.
 * - "Order again" replaces the cart: WooCommerce drops the lines it held
 *   without removing them, so those whose designs the new cart does not
 *   hold are read from the session before it is saved over.
 * - Order again copied a design for a line that did not join the cart
 *   (`discard()`).
 *
 * A design an order line holds is never deleted, whatever its cart did: the
 * job looks for one before it sends anything. Pixonaut refusing a design that
 * is approved or printed, or not knowing it, ends the job; a transient failure
 * is retried on RetryPolicy's schedule.
 */
final class CartCleanup {

	/**
	 * Action Scheduler hook of the job, with the design id, the workspace it
	 * was committed to and the failures so far as its arguments.
	 */
	public const ACTION = 'pixonaut_delete_design';

	public const GROUP = Schema::ACTION_GROUP;

	/**
	 * How long a removed line's designs are kept, an hour, so the customer
	 * can undo the removal. Not HOUR_IN_SECONDS: the unit suite boots the
	 * plugin without WordPress.
	 */
	public const REMOVAL_GRACE_SECONDS = 3600;

	/**
	 * Expired sessions read at a time, so a backlog never sits in memory at once.
	 */
	public const SWEEP_BATCH = 100;

	/**
	 * Before WooCommerce's own handler, which deletes the expired sessions at the default priority.
	 */
	private const BEFORE_SESSION_CLEANUP = 9;

	/**
	 * Order status of the draft the block checkout keeps while the customer is on it.
	 */
	private const CHECKOUT_DRAFT = 'checkout-draft';

	/**
	 * Refusals that say the design is past deleting: the job is done.
	 */
	private const DONE_CODES = array( ErrorCode::DESIGN_APPROVED, ErrorCode::DESIGN_PRINTED );

	public function __construct(
		private readonly Connection $connection,
		private readonly Designs $designs,
		private readonly LoggerInterface $logger,
	) {
	}

	public function register(): void {
		add_action( 'woocommerce_remove_cart_item', array( $this, 'removed' ), 10, 2 );
		add_action( 'woocommerce_remove_cart_item_from_session', array( $this, 'dropped' ), 10, 2 );
		add_action( 'woocommerce_cart_item_restored', array( $this, 'restored' ), 10, 2 );
		add_action( 'woocommerce_add_to_cart', array( $this, 'added' ), 10, 6 );
		add_action( 'woocommerce_before_cart_emptied', array( $this, 'emptied' ) );
		add_action( 'woocommerce_cleanup_sessions', array( $this, 'expired' ), self::BEFORE_SESSION_CLEANUP );
		add_action( 'woocommerce_ordered_again', array( $this, 'reordered' ), 10, 3 );
		add_action( self::ACTION, array( $this, 'run' ), 10, 3 );
	}

	/**
	 * Schedules the deletion of a removed line's designs, after the grace period.
	 *
	 * @param mixed $cart_item_key The cart item's key.
	 * @param mixed $cart          The cart, still holding the item.
	 */
	public function removed( mixed $cart_item_key, mixed $cart ): void {
		$line = self::line_in( $cart, $cart_item_key );
		if ( null !== $line ) {
			$this->schedule( $line, time() + self::REMOVAL_GRACE_SECONDS );
		}
	}

	/**
	 * Schedules the deletion of a line's designs WooCommerce dropped while
	 * loading the cart, as its product can no longer be bought; there is no
	 * undo for it.
	 *
	 * @param mixed $cart_item_key The cart item's key; unused.
	 * @param mixed $values        The cart item.
	 */
	public function dropped( mixed $cart_item_key, mixed $values ): void {
		unset( $cart_item_key );
		$line = is_array( $values ) ? CartLine::of( $values ) : null;
		if ( null !== $line ) {
			$this->schedule( $line, time() );
		}
	}

	/**
	 * Keeps the designs of a line the customer put back.
	 *
	 * @param mixed $cart_item_key The cart item's key.
	 * @param mixed $cart          The cart, holding the item again.
	 */
	public function restored( mixed $cart_item_key, mixed $cart ): void {
		$line = self::line_in( $cart, $cart_item_key );
		if ( null !== $line ) {
			$this->cancel( $line );
		}
	}

	/**
	 * Keeps the designs of a line added again with the same designs.
	 *
	 * @param mixed $cart_item_key  The cart item's key; unused.
	 * @param mixed $product_id     Unused.
	 * @param mixed $quantity       Unused.
	 * @param mixed $variation_id   Unused.
	 * @param mixed $variation      Unused.
	 * @param mixed $cart_item_data The item's data.
	 */
	public function added( mixed $cart_item_key, mixed $product_id = 0, mixed $quantity = 1, mixed $variation_id = 0, mixed $variation = array(), mixed $cart_item_data = array() ): void {
		unset( $cart_item_key, $product_id, $quantity, $variation_id, $variation );
		$line = is_array( $cart_item_data ) ? CartLine::of( $cart_item_data ) : null;
		if ( null !== $line ) {
			$this->cancel( $line );
		}
	}

	/**
	 * Schedules the deletion of every line's designs in a cart being emptied.
	 *
	 * @param mixed $clear_persistent_cart Whether the customer's persistent cart is emptied too.
	 */
	public function emptied( mixed $clear_persistent_cart = true ): void {
		$session = WC()->session;
		// Typed as always set, but null until WooCommerce starts a session.
		if ( ! $clear_persistent_cart && null !== $session && self::is_user_id( (string) $session->get_customer_id() ) ) { // @phpstan-ignore notIdentical.alwaysTrue
			return;
		}
		$cart = WC()->cart;
		// Typed as always set, but null until WooCommerce loads the cart.
		if ( null === $cart ) { // @phpstan-ignore identical.alwaysFalse
			return;
		}
		foreach ( $cart->get_cart() as $item ) {
			$line = CartLine::of( $item );
			if ( null !== $line ) {
				$this->schedule( $line, time() );
			}
		}
	}

	/**
	 * Schedules the deletion of the designs of the lines "Order again"
	 * dropped from the cart: those in the session's cart, which WooCommerce
	 * has not saved over yet, and not in the cart it rebuilt.
	 *
	 * @param mixed $order_id The order ordered again; unused.
	 * @param mixed $items    Its items; unused.
	 * @param mixed $cart     The rebuilt cart's items.
	 */
	public function reordered( mixed $order_id, mixed $items, mixed $cart ): void {
		unset( $order_id, $items );
		$session = WC()->session;
		// Typed as always set, but null until WooCommerce starts a session.
		if ( null === $session ) { // @phpstan-ignore identical.alwaysFalse
			return;
		}
		$kept = array();
		foreach ( is_array( $cart ) ? $cart : array() as $item ) {
			$line = is_array( $item ) ? CartLine::of( $item ) : null;
			if ( null !== $line ) {
				array_push( $kept, ...self::design_ids( $line ) );
			}
		}
		$before = $session->get( 'cart' );
		foreach ( is_array( $before ) ? $before : array() as $item ) {
			$line = is_array( $item ) ? CartLine::of( $item ) : null;
			if ( null === $line ) {
				continue;
			}
			foreach ( array_diff( self::design_ids( $line ), $kept ) as $design_id ) {
				$this->discard( $design_id, $line->tenant_id );
			}
		}
	}

	/**
	 * Schedules the deletion of a design no cart line holds, now, unless an
	 * order line holds it or its deletion is scheduled already.
	 *
	 * @param string $design_id The design.
	 * @param string $tenant_id The workspace it was made in.
	 */
	public function discard( string $design_id, string $tenant_id ): void {
		$this->schedule_design( $design_id, $tenant_id, time() );
	}

	/**
	 * Schedules the deletion of the designs in sessions that expired, before
	 * WooCommerce deletes them, reading SWEEP_BATCH sessions at a time.
	 */
	public function expired(): void {
		global $wpdb;
		$now   = time();
		$after = 0;
		do {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WooCommerce's session API reads one session by its key; the expired ones are found here.
				$wpdb->prepare(
					"SELECT session_id, session_key, session_value FROM {$wpdb->prefix}woocommerce_sessions WHERE session_expiry < %d AND session_id > %d AND session_value LIKE %s ORDER BY session_id LIMIT %d",
					$now,
					$after,
					'%' . $wpdb->esc_like( '"' . CartLine::KEY . '"' ) . '%',
					self::SWEEP_BATCH
				)
			);
			$rows = is_array( $rows ) ? $rows : array();
			$read = count( $rows );
			foreach ( $rows as $row ) {
				$after = (int) $row->session_id;
				$this->sweep_session( (string) $row->session_key, (string) $row->session_value );
			}
		} while ( self::SWEEP_BATCH === $read );
	}

	/**
	 * Schedules the deletion of an expired session's designs, except those
	 * still in the user's persistent cart.
	 *
	 * @param string $session_key   The session's customer id.
	 * @param string $session_value The session's data, serialized.
	 */
	private function sweep_session( string $session_key, string $session_value ): void {
		$session = maybe_unserialize( $session_value );
		if ( ! is_array( $session ) ) {
			return;
		}
		$cart  = maybe_unserialize( (string) ( $session['cart'] ?? '' ) );
		$lines = PersonalisedDesigns::lines_in( $session[ PersonalisedDesigns::SESSION_KEY ] ?? null );
		foreach ( is_array( $cart ) ? $cart : array() as $item ) {
			$line = is_array( $item ) ? CartLine::of( $item ) : null;
			if ( null !== $line ) {
				$lines[] = $line;
			}
		}
		$kept = array() === $lines ? array() : self::persistent_designs( $session_key );
		foreach ( $lines as $line ) {
			if ( array() === array_intersect( self::design_ids( $line ), $kept ) ) {
				$this->schedule( $line, time() );
			}
		}
	}

	/**
	 * The job: deletes one design, unless an order line holds it.
	 *
	 * @param mixed $design_id The design.
	 * @param mixed $tenant_id The workspace it was committed to.
	 * @param mixed $failures  Transient failures in a row before this run.
	 */
	public function run( mixed $design_id, mixed $tenant_id, mixed $failures = 0 ): void {
		$design_id = (string) $design_id;
		$tenant_id = (string) $tenant_id;
		$failures  = (int) $failures;
		$current   = $this->connection->current();
		// Another workspace's key cannot delete it, and without a connection no key can.
		if ( null === $current || $current->tenant_id !== $tenant_id || self::in_order( $design_id ) ) {
			return;
		}
		if ( null !== $this->connection->paused() ) {
			$this->retry( $design_id, $tenant_id, $failures, null );
			return;
		}
		try {
			$this->designs->delete( $design_id );
		} catch ( ApiException $error ) {
			if ( in_array( $error->errorCode, self::DONE_CODES, true ) ) {
				return;
			}
			if ( RetryPolicy::RETRY === RetryPolicy::decide( $error ) ) {
				$this->retry( $design_id, $tenant_id, $failures, $error );
				return;
			}
			$this->logger->warning(
				sprintf( 'Could not delete design %s of a cart line: %s', $design_id, $error->getMessage() ),
				array( 'code' => $error->errorCode )
			);
		}
	}

	/**
	 * @param string            $design_id The design.
	 * @param string            $tenant_id Its workspace.
	 * @param int               $failures  Failures in a row before this one.
	 * @param ApiException|null $error     Why this run failed; null while the connection is paused.
	 */
	private function retry( string $design_id, string $tenant_id, int $failures, ?ApiException $error ): void {
		$delay = RetryPolicy::SCHEDULE[ $failures ] ?? null;
		if ( null === $delay ) {
			$this->logger->warning(
				sprintf( 'Gave up deleting design %s of a cart line after %d attempts.', $design_id, $failures + 1 ),
				array( 'code' => $error?->errorCode )
			);
			return;
		}
		as_schedule_single_action( time() + max( $delay, $error->retryAfter ?? 0 ), self::ACTION, self::args( $design_id, $tenant_id, $failures + 1 ), self::GROUP );
	}

	/**
	 * Schedules a job for each of the line's designs no order line holds.
	 *
	 * @param CartLine $line The line.
	 * @param int      $when Unix time the jobs run at.
	 */
	private function schedule( CartLine $line, int $when ): void {
		foreach ( self::design_ids( $line ) as $design_id ) {
			$this->schedule_design( $design_id, $line->tenant_id, $when );
		}
	}

	/**
	 * Schedules a design's job, unless an order line holds the design or a
	 * job for it is pending.
	 *
	 * @param string $design_id The design.
	 * @param string $tenant_id Its workspace.
	 * @param int    $when      Unix time the job runs at.
	 */
	private function schedule_design( string $design_id, string $tenant_id, int $when ): void {
		if ( self::in_order( $design_id ) || array() !== self::pending( $design_id, $tenant_id ) ) {
			return;
		}
		as_schedule_single_action( $when, self::ACTION, self::args( $design_id, $tenant_id ), self::GROUP );
	}

	/**
	 * Cancels the jobs of the line's designs that have not run yet, retries
	 * included.
	 *
	 * @param CartLine $line The line.
	 */
	private function cancel( CartLine $line ): void {
		foreach ( self::design_ids( $line ) as $design_id ) {
			foreach ( self::pending( $design_id, $line->tenant_id ) as $action_id ) {
				ActionScheduler::store()->cancel_action( $action_id );
			}
		}
	}

	/**
	 * The design's pending jobs, whatever their failure count.
	 *
	 * @param string $design_id The design.
	 * @param string $tenant_id Its workspace.
	 *
	 * @return list<int> Action ids.
	 */
	private static function pending( string $design_id, string $tenant_id ): array {
		$actions = as_get_scheduled_actions(
			array(
				'hook'     => self::ACTION,
				'group'    => self::GROUP,
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				// Narrows the query to jobs whose arguments contain the id; each one is checked below.
				'search'   => $design_id,
				'per_page' => -1,
			)
		);
		$ids     = array();
		foreach ( $actions as $id => $action ) {
			$args = $action instanceof ActionScheduler_Action ? $action->get_args() : array();
			if ( ( $args['design_id'] ?? null ) === $design_id && ( $args['tenant_id'] ?? null ) === $tenant_id ) {
				$ids[] = (int) $id;
			}
		}
		return $ids;
	}

	/**
	 * The line of an item among the cart's items.
	 *
	 * @param mixed $cart          The cart.
	 * @param mixed $cart_item_key The item's key.
	 */
	private static function line_in( mixed $cart, mixed $cart_item_key ): ?CartLine {
		if ( ! $cart instanceof WC_Cart || ! is_string( $cart_item_key ) ) {
			return null;
		}
		$item = $cart->get_cart_item( $cart_item_key );
		return array() === $item ? null : CartLine::of( $item );
	}

	/**
	 * Whether a placed order's line holds the design. The block checkout's
	 * draft order does not count: it follows the cart.
	 *
	 * @param string $design_id The design.
	 */
	private static function in_order( string $design_id ): bool {
		foreach ( LineState::for_design_id( $design_id ) as $line ) {
			$order = wc_get_order( $line->item()->get_order_id() );
			if ( ! $order instanceof WC_Order || self::CHECKOUT_DRAFT !== $order->get_status() ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The designs in a user's persistent cart, which WooCommerce restores
	 * when they log in again; none for a guest's session key.
	 *
	 * @param string $session_key The session's customer id.
	 *
	 * @return list<string>
	 */
	private static function persistent_designs( string $session_key ): array {
		if ( ! self::is_user_id( $session_key ) ) {
			return array();
		}
		$saved = get_user_meta( (int) $session_key, '_woocommerce_persistent_cart_' . get_current_blog_id(), true );
		$ids   = array();
		foreach ( is_array( $saved ) && is_array( $saved['cart'] ?? null ) ? $saved['cart'] : array() as $item ) {
			$line = is_array( $item ) ? CartLine::of( $item ) : null;
			if ( null !== $line ) {
				array_push( $ids, ...self::design_ids( $line ) );
			}
		}
		return $ids;
	}

	/**
	 * Whether a session's customer id is a user's: WooCommerce keys a
	 * logged-in customer's session by their user id, a guest's by a hash.
	 *
	 * @param string $customer_id The session's customer id.
	 */
	private static function is_user_id( string $customer_id ): bool {
		return 1 === preg_match( '/^[1-9]\d*$/', $customer_id );
	}

	/**
	 * @param CartLine $line The line.
	 *
	 * @return list<string>
	 */
	private static function design_ids( CartLine $line ): array {
		return array_map( static fn ( CommittedSide $side ): string => $side->design_id, $line->sides );
	}

	/**
	 * The job's arguments.
	 *
	 * @param string $design_id The design.
	 * @param string $tenant_id Its workspace.
	 * @param int    $failures  Failures in a row before the job runs.
	 *
	 * @return array{design_id: string, tenant_id: string, failures: int}
	 */
	private static function args( string $design_id, string $tenant_id, int $failures = 0 ): array {
		return array(
			'design_id' => $design_id,
			'tenant_id' => $tenant_id,
			'failures'  => $failures,
		);
	}
}
