<?php
/**
 * Takes a paid line through approval and the printshop order.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Fulfilment;

use Closure;
use Pixonaut\WooCommerce\Api\ApiError;
use Pixonaut\WooCommerce\Api\Designs;
use Pixonaut\WooCommerce\Api\ErrorCodes;
use Pixonaut\WooCommerce\Api\Operations;
use Pixonaut\WooCommerce\Api\Orders;
use Pixonaut\WooCommerce\Api\PreparedRequest;
use Pixonaut\WooCommerce\ApprovalMode;
use Pixonaut\WooCommerce\Approval\ProofRequest;
use Pixonaut\WooCommerce\Cart\StandingSide;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use Pixonaut\WooCommerce\Refs;
use WC_Order;
use WC_Order_Item_Product;

/**
 * Runs a line from its stored state: approve every design, then place one
 * printshop order naming all the line's designs. Pixonaut renders their
 * prints and holds the order (`awaiting_prints`) until they are done, so the
 * order is placed at once; a print that fails, or takes too long, comes back
 * as `order.failed`. Every step is stored as it completes, and each request
 * that writes is stored before it is sent, so a run that stops anywhere
 * resumes where it stopped and sends the same bytes under the same key.
 *
 * Two runs of the same line are safe: the line's state is written only over
 * the version a run last read, so the run that loses a write stops, and the
 * other carries on.
 */
final class Fulfiller {

	/**
	 * A design's `status` once it is approved.
	 */
	private const DESIGN_APPROVED = 'approved';

	/**
	 * @param Designs      $designs       Approves designs.
	 * @param Orders       $orders        Places printshop orders.
	 * @param Closure      $connection    `fn(): ?StoredConnection`, the connection to act for; null while there is none or it is paused.
	 * @param ProofRequest $proof_request Asks the customer to approve a proof, for a line whose mode is proof by email.
	 */
	public function __construct(
		private readonly Designs $designs,
		private readonly Orders $orders,
		private readonly Closure $connection,
		private readonly ProofRequest $proof_request,
	) {
	}

	/**
	 * Takes the line as far as it can go now. Nothing is sent while the
	 * store is not connected, for a line made under another workspace, for
	 * a line the store is cancelling, or while `pixonaut_wc_fulfilment_can_fulfil`
	 * holds it back.
	 *
	 * @param WC_Order_Item_Product $item A Pixonaut line of a paid order.
	 *
	 * @throws ApiError When a call fails; the steps done before it stay stored.
	 */
	public function fulfil( WC_Order_Item_Product $item ): void {
		$connection = ( $this->connection )();
		$order      = wc_get_order( $item->get_order_id() );
		if ( ! $connection instanceof StoredConnection || ! $order instanceof WC_Order ) {
			return;
		}
		$line = LineState::of( $item );
		if ( null !== $line->ref( LineState::REF_CANCEL ) ) {
			// The store is cancelling the line: its own job takes it from here.
			return;
		}

		/**
		 * Filters whether the plugin takes a paid line on towards the
		 * printshop: approving its designs and placing its printshop order.
		 * Return false to take that over or hold the line back: the order
		 * gets a note saying so, and the line stays in its state until
		 * `FulfilmentJobs::schedule( $item_id )` runs its job again.
		 *
		 * @since 0.1.0
		 *
		 * @param bool                  $can_fulfil Whether the plugin fulfils the line; true.
		 * @param WC_Order_Item_Product $item       The order line.
		 * @param LineState             $line       The line's state, refs and designs.
		 * @param WC_Order              $order      The order.
		 *
		 * @example
		 * add_filter( 'pixonaut_wc_fulfilment_can_fulfil', function ( $can_fulfil, $item, $line, $order ) {
		 *     return 'yes' === $order->get_meta( '_hold_for_review' ) ? false : $can_fulfil;
		 * }, 10, 4 );
		 */
		if ( ! (bool) apply_filters( 'pixonaut_wc_fulfilment_can_fulfil', true, $item, $line, $order ) ) {
			$this->note(
				$order,
				/* translators: %s: product name of the order line. */
				__( 'Pixonaut: "%s" was not sent to the printshop. A plugin or the theme holds it back (pixonaut_wc_fulfilment_can_fulfil).', 'pixonaut-for-woocommerce' ),
				$item
			);
			return;
		}
		if ( $line->ref( LineState::REF_TENANT_ID ) !== $connection->tenant_id ) {
			$this->note(
				$order,
				/* translators: %s: product name of the order line. */
				__( 'Pixonaut: "%s" was not sent to the printshop. It was ordered under another Pixonaut workspace than the one the store is connected to now.', 'pixonaut-for-woocommerce' ),
				$item
			);
			return;
		}
		if ( ! $this->store_refs( $line, $connection->site, $order ) ) {
			return;
		}

		if ( LineState::COMMITTED === $line->state() && ! $this->approve( $line, $order ) ) {
			return;
		}
		// A line an earlier version printed holds its prints, and orders them.
		if ( in_array( $line->state(), array( LineState::APPROVED, LineState::PRINTED ), true ) ) {
			$this->place_order( $line, $order );
		}
	}

	/**
	 * Builds the line's refs and keys the first time it runs; a later run
	 * finds them stored and keeps them. Designs still under the cart ref,
	 * because the move at checkout failed, are moved to the order line ref
	 * first: the approval and the printshop order never carry a cart ref.
	 *
	 * @param LineState $line  The line.
	 * @param string    $site  The connection's `<site>`.
	 * @param WC_Order  $order The order.
	 *
	 * @return bool False when the line cannot go on: a design is missing, its printshop order was sent under the cart ref, or the line changed elsewhere.
	 *
	 * @throws ApiError When a design could not be moved to the order line ref.
	 */
	private function store_refs( LineState $line, string $site, WC_Order $order ): bool {
		$item_id = $line->item()->get_id();
		if ( ! OrderLineRef::moved( $line ) && in_array( $line->state(), LineState::FULFILLABLE, true ) ) {
			if ( null !== $line->ref( LineState::REF_ORDER_REQUEST ) ) {
				// An earlier version sent this order under the cart ref. Replaying the stored request
				// would send the cart ref again; rebuilding it would change the body under a used idempotency key.
				$this->note(
					$order,
					/* translators: %s: product name of the order line. */
					__( 'Pixonaut: "%s" was not sent to the printshop. An earlier attempt sent its printshop order under the cart\'s reference, so the store cannot tell whether it was placed. Check the order in Pixonaut.', 'pixonaut-for-woocommerce' ),
					$line->item()
				);
				$line->transition( LineState::NEEDS_ATTENTION, 'The printshop order was sent under the cart ref.' );
				return false;
			}
			OrderLineRef::move( $this->designs, $line, self::order_line_ref( $line, $site, $order ) );
		}
		if ( null === $line->ref( LineState::REF_ORDER_KEY ) ) {
			// A reorder is a new request, so it is sent under a key of its own.
			$key = true === $line->ref( LineState::REF_REORDER ) ? Refs::reorder_key( $site, $item_id ) : Refs::order_key( $site, $item_id );
			$line->set_ref( LineState::REF_ORDER_KEY, $key );
		}
		foreach ( $line->sides() as $side ) {
			if ( null === self::design( $line, $side ) ) {
				$this->note(
					$order,
					/* translators: %s: product name of the order line. */
					__( 'Pixonaut: "%s" was not sent to the printshop. The line has no design for one of its sides.', 'pixonaut-for-woocommerce' ),
					$line->item()
				);
				return false;
			}
		}
		return $line->save();
	}

	/**
	 * The ref a line's designs move to: the order line ref the line stored,
	 * kept across a reconnect, or one built from the current `<site>` when
	 * it holds only the cart ref.
	 *
	 * @param LineState $line  The line.
	 * @param string    $site  The connection's `<site>`.
	 * @param WC_Order  $order The order.
	 */
	private static function order_line_ref( LineState $line, string $site, WC_Order $order ): string {
		$stored = $line->ref( LineState::REF_EXTERNAL_REF );
		if ( is_string( $stored ) && '' !== $stored && ! Refs::is_cart_ref( $stored ) ) {
			return $stored;
		}
		return Refs::order_item_ref( $site, $order->get_id(), $line->item()->get_id() );
	}

	/**
	 * Approves every design the customer committed with their consent.
	 * Approving one that is approved already, at the same revision, is
	 * answered as a success. A line whose mode is proof by email asks the
	 * customer instead, and goes on here only when its designs were approved
	 * before. A standing design was approved when it was found, so it is
	 * never approved here.
	 *
	 * @param LineState $line  A `committed` line.
	 * @param WC_Order  $order The order.
	 *
	 * @throws ApiError When Pixonaut refused an approval or could not be reached.
	 */
	private function approve( LineState $line, WC_Order $order ): bool {
		if ( array() === $line->sides() ) {
			// Standing designs alone, approved when the product was saved: nothing for the customer to approve.
			return $line->transition( LineState::APPROVED );
		}
		if ( ApprovalMode::PROOF_EMAIL === $line->ref( LineState::REF_APPROVAL_MODE ) ) {
			// The customer approves a proof page, which approves the design; the line waits for that.
			return $this->proof_request->request( $line, $order );
		}
		$consent = $line->ref( LineState::REF_CONSENT );
		if ( ! is_array( $consent ) || ! is_string( $consent['kind'] ?? null ) || ! is_int( $consent['at'] ?? null ) ) {
			$this->note(
				$order,
				/* translators: %s: product name of the order line. */
				__( 'Pixonaut: "%s" was not sent to the printshop. The line does not record how the customer approved the design.', 'pixonaut-for-woocommerce' ),
				$line->item()
			);
			return false;
		}
		foreach ( $line->sides() as $side ) {
			$design = self::design( $line, $side );
			if ( null === $design ) {
				return false;
			}
			try {
				$this->designs->approve(
					$design[0],
					array(
						'kind' => $consent['kind'],
						'at'   => $consent['at'],
						'ref'  => (string) $line->ref( LineState::REF_EXTERNAL_REF ),
					),
					$design[1]
				);
			} catch ( ApiError $e ) {
				// Rejected, or approved already: only an approval at the line's revision lets it go on.
				if ( ErrorCodes::NOT_COMMITTED !== $e->error_code || ! $this->approved_at( $design[0], $design[1] ) ) {
					throw $e;
				}
			}
		}
		return $line->transition( LineState::APPROVED );
	}

	/**
	 * Whether a design is approved at a revision.
	 *
	 * @param string $design_id The design.
	 * @param int    $revision  The revision the line holds.
	 *
	 * @throws ApiError When Pixonaut could not be reached.
	 */
	private function approved_at( string $design_id, int $revision ): bool {
		$design = $this->designs->get( $design_id );
		return self::DESIGN_APPROVED === ( $design['status'] ?? null ) && ( $design['revision'] ?? null ) === $revision;
	}

	/**
	 * Places the line's one printshop order, or adopts the one an earlier
	 * attempt placed under the line's ref, or the one an automation placed
	 * for the line's designs first.
	 *
	 * @param LineState $line  An `approved` line, or a `printed` one.
	 * @param WC_Order  $order The order.
	 *
	 * @throws ApiError When Pixonaut refused the order or could not be reached; `409 design_already_ordered` when a design has a live order the line did not place.
	 */
	private function place_order( LineState $line, WC_Order $order ): void {
		$external_ref = (string) $line->ref( LineState::REF_EXTERNAL_REF );
		$order_id     = null;
		$answer       = array();
		$sent         = null;
		$placed       = $this->orders->with_external_ref( $external_ref );
		if ( array() !== $placed ) {
			// An order under the ref without an id stops the run: reading it as no order would place a second one.
			$order_id = self::string_field( $placed[0], 'id', Operations::LIST_ORDERS );
			$answer   = $placed[0];
		}

		if ( null === $order_id ) {
			if ( LineState::APPROVED === $line->state() && ! $this->still_approved( $line, $order ) ) {
				return;
			}
			$stored = $line->ref( LineState::REF_ORDER_REQUEST );
			if ( is_array( $stored ) ) {
				$request = PreparedRequest::from_array( $stored );
			} else {
				$request = $this->orders->prepare_create( $this->order_body( $line, $order, $external_ref ), (string) $line->ref( LineState::REF_ORDER_KEY ) );
				$line->set_ref( LineState::REF_ORDER_REQUEST, $request->to_array() );
				if ( ! $line->save() ) {
					return;
				}
			}
			try {
				$answer   = $this->orders->create( $request );
				$order_id = self::string_field( $answer, 'id', Operations::CREATE_ORDER );
			} catch ( ApiError $e ) {
				if ( ErrorCodes::DESIGN_ALREADY_ORDERED !== $e->error_code ) {
					throw $e;
				}
				// Nothing was written, so an order again is a new request rather than this one replayed.
				$line->set_ref( LineState::REF_ORDER_REQUEST, null );
				$order_id = AdoptedOrder::named_by( $e );
				$answer   = null === $order_id ? array() : $this->orders->get( $order_id );
				if ( null === $order_id || ! AdoptedOrder::takes( $line, $answer ) ) {
					$line->save();
					throw $e;
				}
				AdoptedOrder::record( $line, $answer );
				$this->adopted_note( $line, $order, $order_id );
				// The refused request sent nothing: the address is the one the automation placed the order with.
				$sent = AdoptedOrder::address( $answer );
			}
		}
		$status = is_string( $answer['status'] ?? null ) ? $answer['status'] : '';
		$sent ??= self::sent_address( $line, $order );

		$line->set_ref( LineState::REF_ORDER_ID, $order_id );
		// The request holds the customer's name and address; it is kept only while its outcome is unknown.
		$line->set_ref( LineState::REF_ORDER_REQUEST, null );
		$line->set_ref( LineState::REF_DELIVERY, array( DeliveryChange::FIELD_ADDRESS => $sent ) + DeliveryChange::of( $line ) );
		// A status that leaves the line ordered is applied here. One further along, of an order adopted
		// after a lost answer, stays unapplied, so its event or the next read of the order applies it.
		if ( LineState::ORDERED === ( OrderStatus::LINE_STATES[ $status ] ?? null ) ) {
			$line->set_ref( LineState::REF_ORDER_STATUS, $status );
		}
		// A free workspace past its monthly orders: taken, and sent on once Pixonaut releases it.
		$held = OrderStatus::HELD === $status && OrderHold::store( $line, $answer['hold'] ?? null );
		if ( ! isset( OrderStatus::LINE_STATES[ $status ] ) ) {
			$line->set_ref( LineState::REF_ORDER_STATUS, $status );
			$this->order_not_placed( $line, $order, $status, $answer );
			return;
		}
		if ( $line->save() ) {
			if ( $line->transition( LineState::ORDERED ) && $held ) {
				OrderHold::note( $line );
			}
			if ( LineState::ORDERED !== OrderStatus::LINE_STATES[ $status ] ) {
				// An adopted order further along has no event left to say so: a read of it applies its status.
				LineReader::queue( $line->item()->get_id() );
			}
			// The address changed after the order request was made, perhaps in another request while it was on its way: read the order as it is saved now.
			$saved = wc_get_order( $order->get_id() );
			if ( $saved instanceof WC_Order && OrderAddress::fingerprint( OrderAddress::of( $saved, $line->item() ) ) !== $sent ) {
				DeliveryChange::schedule( $line->item()->get_id() );
			}
		}
	}

	/**
	 * Tells the merchant the line took on an order an automation placed.
	 *
	 * @param LineState $line     The line.
	 * @param WC_Order  $order    The order.
	 * @param string    $order_id The Pixonaut order taken on.
	 */
	private function adopted_note( LineState $line, WC_Order $order, string $order_id ): void {
		$order->add_order_note(
			sprintf(
				/* translators: 1: product name of the order line, 2: Pixonaut order id. */
				__( 'Pixonaut: a Pixonaut automation already ordered "%1$s", as order %2$s. The store took that order on instead of placing another.', 'pixonaut-for-woocommerce' ),
				$line->item()->get_name(),
				$order_id
			)
		);
	}

	/**
	 * The fingerprint of the address the line's order was placed with: the
	 * one its stored request holds, or the order's own when none is stored.
	 *
	 * @param LineState $line  The line.
	 * @param WC_Order  $order Its order.
	 */
	private static function sent_address( LineState $line, WC_Order $order ): string {
		$stored = $line->ref( LineState::REF_ORDER_REQUEST );
		$body   = is_array( $stored ) ? json_decode( (string) PreparedRequest::from_array( $stored )->body, true ) : null;
		return OrderAddress::fingerprint( is_array( $body ) ? $body : OrderAddress::of( $order, $line->item() ) );
	}

	/**
	 * Whether every design of the line is still approved at the revision the
	 * line holds. A design stays editable in Pixonaut after it is approved,
	 * and an order naming designs names no revision, so an edit whose
	 * `design.edited` has not reached the store yet would print a design the
	 * customer never approved. A line whose design moved on needs the
	 * merchant; nothing was sent, so its stored order request is dropped.
	 *
	 * @param LineState $line  An `approved` line.
	 * @param WC_Order  $order The order.
	 *
	 * @throws ApiError When a design could not be read.
	 */
	private function still_approved( LineState $line, WC_Order $order ): bool {
		foreach ( $line->sides() as $side ) {
			$design = self::design( $line, $side );
			if ( null !== $design && $this->approved_at( $design[0], $design[1] ) ) {
				continue;
			}
			$line->set_ref( LineState::REF_ORDER_REQUEST, null );
			NeedsAttention::flag(
				$line,
				$order,
				NeedsAttention::DESIGN_EDITED,
				sprintf(
					/* translators: %s: product name */
					__( 'Pixonaut: the design for "%s" was changed in Pixonaut after it was approved, so it was not sent to the printshop. Check the design before it is printed.', 'pixonaut-for-woocommerce' ),
					$line->item()->get_name()
				)
			);
			return false;
		}
		return true;
	}

	/**
	 * A placed order whose status the line cannot go on from: failed at
	 * once, its outcome unknown, or one this version does not know. The line
	 * keeps the order id and needs the merchant. A held order is not one of
	 * these: it is ordered, and goes on once Pixonaut releases it.
	 *
	 * @param LineState            $line   An `approved` or `printed` line holding the order id.
	 * @param WC_Order             $order  The order.
	 * @param string               $status The order's status.
	 * @param array<string, mixed> $placed The placed order.
	 */
	private function order_not_placed( LineState $line, WC_Order $order, string $status, array $placed ): void {
		if ( OrderStatus::FAILED === $status ) {
			$error = is_string( $placed['lastError'] ?? null ) ? $placed['lastError'] : '';
			NeedsAttention::flag(
				$line,
				$order,
				NeedsAttention::ORDER_FAILED,
				sprintf(
					/* translators: 1: product name of the order line, 2: the reason Pixonaut gave. */
					__( 'Pixonaut: the printshop order for "%1$s" failed when it was placed: %2$s', 'pixonaut-for-woocommerce' ),
					$line->item()->get_name(),
					/* translators: Fills in %2$s of an order note when the printshop order failed without a reason. Ends a sentence; lower case. */
					'' === $error ? __( 'no reason given.', 'pixonaut-for-woocommerce' ) : $error
				)
			);
			return;
		}
		if ( OrderStatus::OUTCOME_UNKNOWN === $status ) {
			NeedsAttention::flag( $line, $order, NeedsAttention::OUTCOME_UNKNOWN, NeedsAttention::outcome_unknown_note( $line->item() ) );
			return;
		}
		NeedsAttention::flag(
			$line,
			$order,
			NeedsAttention::ORDER_STATUS,
			sprintf(
				/* translators: 1: product name of the order line, 2: the order status Pixonaut answered. */
				__( 'Pixonaut: the printshop order for "%1$s" was placed with the status "%2$s", which the store cannot go on from. Check the order with the printshop.', 'pixonaut-for-woocommerce' ),
				$line->item()->get_name(),
				$status
			)
		);
	}

	/**
	 * The `POST /v1/orders` body: the line's designs in side order, the first
	 * one leading, a standing side's standing design among the customer's,
	 * the variant its product sells, and the printshop its product chose when
	 * it has no blank; a blank decides its own, and without a choice the
	 * workspace's default makes it. A line an earlier version printed names its prints instead. It
	 * ships to the order's own address, even when the workspace sends every
	 * order to a saved one. `reorder` is sent only once the merchant asked to
	 * order the customer's designs that have a live order again: a standing
	 * design is ordered any number of times.
	 *
	 * @param LineState $line         An `approved` or `printed` line.
	 * @param WC_Order  $order        The order.
	 * @param string    $external_ref The line's ref.
	 *
	 * @return array<string, mixed>
	 */
	private function order_body( LineState $line, WC_Order $order, string $external_ref ): array {
		$printed = LineState::PRINTED === $line->state();
		$sides   = array();
		if ( $printed ) {
			foreach ( $line->sides() as $side ) {
				$sides[] = array( 'printId' => (string) $line->side_ref( $side, LineState::SIDE_PRINT_ID ) );
			}
		} else {
			$committed = array_map( static fn ( string $side ): string => (string) $line->side_ref( $side, LineState::SIDE_DESIGN_ID ), $line->sides() );
			foreach ( StandingSide::in_side_order( $committed, $line->standing() ) as $design_id ) {
				$sides[] = array( 'designId' => $design_id );
			}
		}
		$variant   = $line->ref( LineState::REF_VARIANT );
		$printshop = $line->ref( LineState::REF_PRINTSHOP );
		return array(
			$printed ? 'prints' : 'designs' => $sides,
			'quantity'                      => max( 1, $line->item()->get_quantity() ),
			'externalRef'                   => $external_ref,
			// Null is the customer's address; left out, the workspace's setting decides.
			'shipToAddressId'               => null,
		) + ( is_string( $variant ) && '' !== $variant ? array( 'variant' => $variant ) : array() )
			+ ( is_string( $printshop ) && '' !== $printshop ? array( 'printshopId' => $printshop ) : array() )
			+ ( true === $line->ref( LineState::REF_REORDER ) && array() !== $line->sides() ? array( 'reorder' => true ) : array() )
			+ OrderAddress::of( $order, $line->item() );
	}

	/**
	 * A side's design id and revision.
	 *
	 * @param LineState $line The line.
	 * @param string    $side A side key.
	 *
	 * @return array{0: string, 1: int}|null Null when either is missing.
	 */
	private static function design( LineState $line, string $side ): ?array {
		$id       = $line->side_ref( $side, LineState::SIDE_DESIGN_ID );
		$revision = $line->side_ref( $side, LineState::SIDE_REVISION );
		return is_string( $id ) && '' !== $id && is_int( $revision ) ? array( $id, $revision ) : null;
	}

	/**
	 * @param array<string, mixed> $answer    A decoded answer.
	 * @param string               $field     A field that must be a string.
	 * @param string               $operation The operation that answered.
	 *
	 * @throws ApiError When it is not one.
	 */
	private static function string_field( array $answer, string $field, string $operation ): string {
		if ( ! is_string( $answer[ $field ] ?? null ) || '' === $answer[ $field ] ) {
			throw new ApiError( $operation, 0, ErrorCodes::UNEXPECTED_RESPONSE, false, null, array( 'missing' => $field ) );
		}
		return $answer[ $field ];
	}

	/**
	 * @param WC_Order              $order   The order.
	 * @param string                $message A translated message with one `%s` for the product name.
	 * @param WC_Order_Item_Product $item    The line.
	 */
	private function note( WC_Order $order, string $message, WC_Order_Item_Product $item ): void {
		$order->add_order_note( sprintf( $message, $item->get_name() ) );
	}
}
