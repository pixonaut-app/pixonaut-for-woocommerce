<?php
/**
 * Takes a paid line through approval and the printshop order.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Fulfilment;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages here are reasons and log text, never printed as they are: whoever shows one escapes it.

use Closure;
use Pixonaut\WooCommerce\Api\Answers\Design;
use Pixonaut\WooCommerce\Api\Answers\Order;
use Pixonaut\WooCommerce\Api\Answers\OrderDetail;
use Pixonaut\WooCommerce\Api\Answers\OrderItem;
use Pixonaut\WooCommerce\Api\Answers\OrderList;
use Pixonaut\WooCommerce\Api\Designs;
use Pixonaut\WooCommerce\Api\StoredRequest;
use Pixonaut\WooCommerce\Approval\ProofRequest;
use Pixonaut\WooCommerce\ApprovalMode;
use Pixonaut\WooCommerce\Cart\StandingSide;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use Pixonaut\WooCommerce\Refs;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\ErrorCode;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\Model\OrdersCreateRequest;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Pixonaut as Sdk;
use WC_Order;
use WC_Order_Item_Product;

/**
 * Runs a line from its stored state: approve every design, then place the
 * order's printshop order, an item per line naming all its designs, once
 * every line of the order is approved. Pixonaut renders their prints and
 * holds each item (`awaiting_prints`) until they are done, so the order is
 * placed at once; a print that fails, or takes too long, comes back as
 * `item.failed`. Every step is stored as it completes, and each request
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
	 * The states of a line that is placed in the order's next printshop order.
	 */
	private const READY = array( LineState::APPROVED, LineState::PRINTED );

	/**
	 * The states of a line whose order's other lines wait for it to be approved.
	 */
	private const WAITING = array( LineState::COMMITTED, LineState::AWAITING_CUSTOMER );

	/**
	 * @param Sdk          $pixonaut      Approves designs, places printshop orders.
	 * @param Closure      $connection    `fn(): ?StoredConnection`, the connection to act for; null while there is none or it is paused.
	 * @param ProofRequest $proof_request Asks the customer to approve a proof, for a line whose mode is proof by email.
	 */
	public function __construct(
		private readonly Sdk $pixonaut,
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
	 * @throws ApiException When a call fails; the steps done before it stay stored.
	 * @throws OrderRefused When Pixonaut refused the printshop order of the line and the order's other lines.
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
			$this->place_order( $line, $order, $connection->site );
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
	 * @throws ApiException When a design could not be moved to the order line ref.
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
			OrderLineRef::move( $this->pixonaut, $line, self::order_line_ref( $line, $site, $order ) );
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
	 * @throws ApiException When Pixonaut refused an approval or could not be reached.
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
				$this->pixonaut->designs()->approve(
					$design[0],
					Designs::approval(
						array(
							'kind' => $consent['kind'],
							'at'   => $consent['at'],
							'ref'  => (string) $line->ref( LineState::REF_EXTERNAL_REF ),
						),
						$design[1]
					)
				);
			} catch ( ApiException $e ) {
				// Rejected, or approved already: only an approval at the line's revision lets it go on.
				if ( ErrorCode::NOT_COMMITTED !== $e->errorCode || ! $this->approved_at( $design[0], $design[1] ) ) {
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
	 * @throws ApiException When Pixonaut could not be reached, or answered without a field the store reads.
	 */
	private function approved_at( string $design_id, int $revision ): bool {
		$design = Design::get( $this->pixonaut, $design_id );
		return self::DESIGN_APPROVED === $design->status && $design->revision === $revision;
	}

	/**
	 * Places the printshop order of the order's lines that are ready, the
	 * line among them, once no other line of the order still waits to be
	 * approved; adopts the one an earlier attempt placed for them under the
	 * order's ref, or, for a line whose design an automation ordered first,
	 * the automation's.
	 *
	 * @param LineState $line  An `approved` line, or a `printed` one.
	 * @param WC_Order  $order The order.
	 * @param string    $site  The connection's `<site>`.
	 *
	 * @throws ApiException When Pixonaut could not be reached, or refused the order as a whole in a way the store cannot tell from a refusal of the line.
	 * @throws OrderRefused When Pixonaut refused the order: one of its items, or the whole order.
	 */
	private function place_order( LineState $line, WC_Order $order, string $site ): void {
		if ( self::legacy_request( $line ) ) {
			$this->settle_legacy_request( $line, $order );
			return;
		}
		// Each pass places the lines left, or settles one line refused among them; every pass takes one line off.
		for ( $pass = 0; $pass <= count( $order->get_items() ); $pass++ ) {
			$line  = LineState::of( $line->item() );
			$batch = $this->batch( $line, $order );
			if ( null === $batch || array() === $batch || ! in_array( $line->state(), self::READY, true ) ) {
				return;
			}
			if ( $this->place( $batch, $order, $site ) ) {
				return;
			}
		}
	}

	/**
	 * Places one printshop order of $batch.
	 *
	 * @param list<LineState> $batch The lines, in the order of the order's items.
	 * @param WC_Order        $order The order.
	 * @param string          $site  The connection's `<site>`.
	 *
	 * @return bool False when a line was taken off the batch, so the rest are to be placed again.
	 *
	 * @throws ApiException When Pixonaut could not be reached, or refused the order.
	 * @throws OrderRefused When Pixonaut refused the order: one of its items, or the whole order.
	 */
	private function place( array $batch, WC_Order $order, string $site ): bool {
		// A request kept from before a reconnect is looked for under the ref it was sent with.
		$requested = self::stored_body( $batch[0] )['externalRef'] ?? null;
		$store_ref = is_string( $requested ) && '' !== $requested ? $requested : Refs::store_order_ref( $site, $order->get_id() );
		$placed    = $this->placed_under( $store_ref, $batch );
		if ( null !== $placed ) {
			$this->record( $batch, $placed, $order, self::sent_address( $batch[0], $order ) );
			return true;
		}
		foreach ( $batch as $line ) {
			if ( LineState::APPROVED === $line->state() && ! $this->still_approved( $line, $order ) ) {
				// The other lines are placed without it, in a request of their own.
				foreach ( $batch as $other ) {
					if ( $other !== $line && null !== $other->ref( LineState::REF_ORDER_REQUEST ) ) {
						$other->set_ref( LineState::REF_ORDER_REQUEST, null );
						$other->save();
					}
				}
				return false;
			}
		}
		$stored = $batch[0]->ref( LineState::REF_ORDER_REQUEST );
		if ( is_array( $stored ) ) {
			$request = StoredRequest::restore( $stored );
		} else {
			$request = $this->pixonaut->orders()->prepareCreate( OrdersCreateRequest::fromArray( $this->order_body( $batch, $order, $store_ref ) ), self::key( $batch, $site ) );
			foreach ( $batch as $line ) {
				$line->set_ref( LineState::REF_ORDER_REQUEST, $request->toArray() );
				if ( ! $line->save() ) {
					// Another run changed a line meanwhile, and goes on from there; a line kept with this request is placed with it.
					return true;
				}
			}
		}
		try {
			$answer = Order::create( $this->pixonaut, $request );
			self::id_of( $answer, 'orders.create' );
		} catch ( ApiException $e ) {
			return $this->refused( $batch, $order, $e );
		}
		$this->record( $batch, $answer, $order, self::sent_address( $batch[0], $order ) );
		return true;
	}

	/**
	 * What a refusal of the order comes to. A refusal of one item takes its
	 * line off the order, which is placed again without it: the line adopts
	 * the order an automation placed for its design, or needs the merchant.
	 * Nothing was written, so the next attempt is a new request.
	 *
	 * @param list<LineState> $batch The lines the order was placed for.
	 * @param WC_Order        $order The order.
	 * @param ApiException    $error The refusal.
	 *
	 * @return bool False when the refused line was adopted, so the rest are to be placed again.
	 *
	 * @throws ApiException When Pixonaut could not be reached, or refused the order and every line keeps its request.
	 * @throws OrderRefused When Pixonaut refused the order: one of its items, or the whole order.
	 */
	private function refused( array $batch, WC_Order $order, ApiException $error ): bool {
		$alone   = 1 === count( $batch );
		$index   = $error->details['itemIndex'] ?? null;
		$refused = is_int( $index ) ? ( $batch[ $index ] ?? null ) : ( $alone ? $batch[0] : null );
		if ( null === $refused || RetryPolicy::RETRY === RetryPolicy::decide( $error ) ) {
			if ( $alone || RetryPolicy::RETRY === RetryPolicy::decide( $error ) ) {
				// Pixonaut may have placed it, or the line's job says why: the stored request is sent again.
				throw $error;
			}
			throw new OrderRefused( $error, array_map( static fn ( LineState $line ): WC_Order_Item_Product => $line->item(), $batch ), true );
		}
		if ( ! $alone || null !== AdoptedOrder::named_by( $error ) ) {
			// Nothing was written: the next attempt, without the refused line or as a reorder, is a new request.
			foreach ( $batch as $line ) {
				$line->set_ref( LineState::REF_ORDER_REQUEST, null );
				$line->save();
			}
		}
		$order_id = AdoptedOrder::named_by( $error );
		$adopted  = null === $order_id ? null : OrderDetail::get( $this->pixonaut, $order_id );
		$item     = null === $adopted ? null : AdoptedOrder::takes( $refused, $adopted, AdoptedOrder::item_named_by( $error ) );
		if ( null === $adopted || null === $item ) {
			if ( $alone ) {
				throw $error;
			}
			throw new OrderRefused( $error, array( $refused->item() ), false );
		}
		$refused = LineState::of( $refused->item() );
		AdoptedOrder::record( $refused, $adopted, $item );
		$this->adopted_note( $refused, $order, $adopted->order->id );
		// The refused request sent nothing: the address is the one the automation placed the order with.
		$this->record_line( $refused, $adopted->order, $item, $order, AdoptedOrder::address( $adopted->order ) );
		return false;
	}

	/**
	 * The order an earlier attempt placed under the order's ref for one of
	 * the lines, whose answer was lost.
	 *
	 * @param string          $store_ref The order's ref.
	 * @param list<LineState> $batch     The lines.
	 *
	 * @throws ApiException When Pixonaut could not be reached, or listed an order without an id.
	 */
	private function placed_under( string $store_ref, array $batch ): ?Order {
		foreach ( OrderList::under( $this->pixonaut, $store_ref )->orders as $row ) {
			foreach ( $batch as $line ) {
				if ( in_array( $line->ref( LineState::REF_EXTERNAL_REF ), $row->item_refs, true ) ) {
					if ( '' === $row->id ) {
						// Reading it as no order would place a second one.
						throw new ApiException( 'orders.list', 0, ApiException::UNEXPECTED_RESPONSE, false, null, null, array( 'missing' => 'id' ) );
					}
					return OrderDetail::get( $this->pixonaut, $row->id )->order;
				}
			}
		}
		return null;
	}

	/**
	 * The lines of the order to place together with $line: those approved
	 * or printed that have no printshop order yet, are not being cancelled
	 * and are in no request on its way. A line with a stored request is
	 * placed with the lines that request names, and no other.
	 *
	 * @param LineState $line  An `approved` or `printed` line.
	 * @param WC_Order  $order Its order.
	 *
	 * @return list<LineState>|null Null while another line of the order waits to be approved: the last one approved places them all.
	 */
	private function batch( LineState $line, WC_Order $order ): ?array {
		$stored = $line->ref( LineState::REF_ORDER_REQUEST );
		$batch  = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product || ! LineState::is_tracked( $item ) ) {
				continue;
			}
			$other = $item->get_id() === $line->item()->get_id() ? $line : LineState::of( $item );
			if ( null !== $other->ref( LineState::REF_ORDER_ID ) || null !== $other->ref( LineState::REF_CANCEL ) ) {
				continue;
			}
			if ( is_array( $stored ) ) {
				if ( $other->ref( LineState::REF_ORDER_REQUEST ) === $stored && in_array( $other->state(), self::READY, true ) ) {
					$batch[] = $other;
				}
				continue;
			}
			if ( in_array( $other->state(), self::WAITING, true ) || ( in_array( $other->state(), self::READY, true ) && ! OrderLineRef::moved( $other ) ) ) {
				return null;
			}
			if ( in_array( $other->state(), self::READY, true ) && null === $other->ref( LineState::REF_ORDER_REQUEST ) ) {
				$batch[] = $other;
			}
		}
		return $batch;
	}

	/**
	 * The `Idempotency-Key` of the order of $batch: a line's own key when it
	 * is placed alone, one for the set of lines otherwise.
	 *
	 * @param list<LineState> $batch The lines.
	 * @param string          $site  The connection's `<site>`.
	 */
	private static function key( array $batch, string $site ): string {
		if ( 1 === count( $batch ) ) {
			return (string) $batch[0]->ref( LineState::REF_ORDER_KEY );
		}
		return Refs::order_lines_key( $site, array_map( static fn ( LineState $line ): int => (int) $line->item()->get_id(), $batch ) );
	}

	/**
	 * Gives each line of $batch its item of the placed order.
	 *
	 * @param list<LineState> $batch  The lines.
	 * @param Order           $placed The order.
	 * @param WC_Order        $order  The WooCommerce order.
	 * @param string          $sent   The fingerprint of the address it was placed with.
	 *
	 * @throws ApiException When the order holds no item of a line.
	 */
	private function record( array $batch, Order $placed, WC_Order $order, string $sent ): void {
		foreach ( $batch as $line ) {
			$line = LineState::of( $line->item() );
			if ( null !== $line->ref( LineState::REF_ORDER_ID ) ) {
				continue;
			}
			$item = $placed->item( null, (string) $line->ref( LineState::REF_EXTERNAL_REF ) );
			if ( null === $item ) {
				throw new ApiException( 'orders.create', 0, ApiException::UNEXPECTED_RESPONSE, false, null, null, array( 'missing' => 'items[].externalRef' ) );
			}
			$this->record_line( $line, $placed, $item, $order, $sent );
		}
	}

	/**
	 * Makes a placed order's item the line's, and applies its status.
	 *
	 * @param LineState $line   The line.
	 * @param Order     $placed The order.
	 * @param OrderItem $item   The line's item in it.
	 * @param WC_Order  $order  The WooCommerce order.
	 * @param string    $sent   The fingerprint of the address it was placed with.
	 */
	private function record_line( LineState $line, Order $placed, OrderItem $item, WC_Order $order, string $sent ): void {
		$status = $item->status;
		$line->set_ref( LineState::REF_ORDER_ID, $placed->id );
		$line->set_ref( LineState::REF_ORDER_ITEM_ID, $item->id );
		$own = $line->ref( LineState::REF_EXTERNAL_REF );
		$line->set_ref( LineState::REF_ORDER_EXTERNAL_REF, null !== $placed->external_ref && $placed->external_ref !== $own ? $placed->external_ref : null );
		// The request holds the customer's name and address; it is kept only while its outcome is unknown.
		$line->set_ref( LineState::REF_ORDER_REQUEST, null );
		$line->set_ref( LineState::REF_DELIVERY, array( DeliveryChange::FIELD_ADDRESS => $sent ) + DeliveryChange::of( $line ) );
		// A status that leaves the line ordered is applied here. One further along, of an order adopted
		// after a lost answer, stays unapplied, so its event or the next read of the order applies it.
		if ( LineState::ORDERED === ( OrderStatus::LINE_STATES[ $status ] ?? null ) ) {
			$line->set_ref( LineState::REF_ORDER_STATUS, $status );
		}
		// A free workspace past its monthly orders: taken, and sent on once Pixonaut releases it.
		$held = OrderStatus::HELD === $status && OrderHold::store( $line, $placed->hold );
		if ( ! isset( OrderStatus::LINE_STATES[ $status ] ) ) {
			$line->set_ref( LineState::REF_ORDER_STATUS, $status );
			$this->order_not_placed( $line, $order, $status, $item );
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
	 * Whether the line keeps a request an earlier version made: one order of
	 * the line alone, placed under the line's ref.
	 *
	 * @param LineState $line The line.
	 */
	private static function legacy_request( LineState $line ): bool {
		$body = self::stored_body( $line );
		return null !== $body && ! array_key_exists( 'items', $body );
	}

	/**
	 * The body of the request the line keeps.
	 *
	 * @param LineState $line The line.
	 *
	 * @return array<string, mixed>|null Null when it keeps none.
	 */
	private static function stored_body( LineState $line ): ?array {
		$stored = $line->ref( LineState::REF_ORDER_REQUEST );
		$body   = is_array( $stored ) && is_string( $stored['body'] ?? null ) ? json_decode( $stored['body'], true ) : null;
		return is_array( $body ) ? $body : null;
	}

	/**
	 * A request an earlier version sent, whose answer was lost: the order it
	 * placed under the line's ref is the line's. Without one, the request is
	 * dropped, and the line is placed with the order's other lines.
	 *
	 * @param LineState $line  The line.
	 * @param WC_Order  $order Its order.
	 *
	 * @throws ApiException When Pixonaut could not be reached.
	 */
	private function settle_legacy_request( LineState $line, WC_Order $order ): void {
		$sent = self::sent_address( $line, $order );
		$row  = OrderList::under( $this->pixonaut, (string) $line->ref( LineState::REF_EXTERNAL_REF ) )->orders[0] ?? null;
		if ( null === $row ) {
			$line->set_ref( LineState::REF_ORDER_REQUEST, null );
			if ( $line->save() ) {
				FulfilmentJobs::schedule( $line->item()->get_id() );
			}
			return;
		}
		$placed = OrderDetail::get( $this->pixonaut, $row->id )->order;
		$item   = $placed->item( null, (string) $line->ref( LineState::REF_EXTERNAL_REF ) );
		if ( null === $item ) {
			throw new ApiException( 'orders.get', 0, ApiException::UNEXPECTED_RESPONSE, false, null, null, array( 'missing' => 'items' ) );
		}
		$this->record_line( $line, $placed, $item, $order, $sent );
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
		$body   = is_array( $stored ) ? json_decode( (string) StoredRequest::restore( $stored )->body, true ) : null;
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
	 * @throws ApiException When a design could not be read.
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
	 * @param LineState $line   An `approved` or `printed` line holding the order id.
	 * @param WC_Order  $order  The order.
	 * @param string    $status The status of the line's item.
	 * @param OrderItem $placed The line's item of the placed order.
	 */
	private function order_not_placed( LineState $line, WC_Order $order, string $status, OrderItem $placed ): void {
		if ( OrderStatus::FAILED === $status ) {
			$error = $placed->last_error ?? '';
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
	 * The `POST /v1/orders` body: an item per line, each naming the line's
	 * designs in side order, the first one leading, a standing side's
	 * standing design among the customer's, the variant its product sells,
	 * and the printshop its product chose when it has no blank; a blank
	 * decides its own, and without a choice the workspace's default makes it.
	 * A line an earlier version printed names its prints instead. The order
	 * ships to the order's own address, even when the workspace sends every
	 * order to a saved one. `reorder` is sent only once the merchant asked to
	 * order the customer's designs that have a live order again: a standing
	 * design is ordered any number of times.
	 *
	 * @param list<LineState> $batch     `approved` or `printed` lines of the order.
	 * @param WC_Order        $order     The order.
	 * @param string          $store_ref The order's ref.
	 *
	 * @return array<string, mixed>
	 */
	private function order_body( array $batch, WC_Order $order, string $store_ref ): array {
		return array(
			'externalRef'     => $store_ref,
			// Null is the customer's address; left out, the workspace's setting decides.
			'shipToAddressId' => null,
			'items'           => array_map( self::order_item( ... ), $batch ),
		) + OrderAddress::of( $order, $batch[0]->item() );
	}

	/**
	 * A line's item of the `POST /v1/orders` body.
	 *
	 * @param LineState $line An `approved` or `printed` line.
	 *
	 * @return array<string, mixed>
	 */
	private static function order_item( LineState $line ): array {
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
			'externalRef'                   => (string) $line->ref( LineState::REF_EXTERNAL_REF ),
			$printed ? 'prints' : 'designs' => $sides,
			'quantity'                      => max( 1, $line->item()->get_quantity() ),
		) + ( is_string( $variant ) && '' !== $variant ? array( 'variant' => $variant ) : array() )
			+ ( is_string( $printshop ) && '' !== $printshop ? array( 'printshopId' => $printshop ) : array() )
			+ ( true === $line->ref( LineState::REF_REORDER ) && array() !== $line->sides() ? array( 'reorder' => true ) : array() );
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
	 * @param Order  $answer    An order Pixonaut answered.
	 * @param string $operation The operation that answered.
	 *
	 * @throws ApiException When its id is empty.
	 */
	private static function id_of( Order $answer, string $operation ): string {
		if ( '' === $answer->id ) {
			throw new ApiException( $operation, 0, ApiException::UNEXPECTED_RESPONSE, false, null, null, array( 'missing' => 'id' ) );
		}
		return $answer->id;
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
