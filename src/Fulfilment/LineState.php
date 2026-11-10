<?php
/**
 * A personalised order line's fulfilment state.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Fulfilment;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages here are reasons and log text, never printed as they are: whoever shows one escapes it.

use LogicException;
use Pixonaut\WooCommerce\Cart\FieldValue;
use Pixonaut\WooCommerce\Cart\StandingSide;
use Pixonaut\WooCommerce\Refs;
use Pixonaut\WooCommerce\Schema;
use RuntimeException;
use WC_Order_Factory;
use WC_Order_Item_Product;

/**
 * Where a line stands on its way to the printshop, and every Pixonaut id, ref
 * and idempotency key it has used, stored as versioned JSON in the order
 * item's `_pixonaut_line` meta, with the requests it may have to send again
 * byte for byte, and the Pixonaut order id copied to `_pixonaut_order_id` and
 * each side's design id to `_pixonaut_design_id` so a line can be found by
 * them. This class is the only code that reads or
 * writes that meta. Refs and keys are kept exactly as first used and reused on
 * every retry, never recomputed.
 *
 * States run along CHAIN and never move backwards. `needs_attention`,
 * `failed` and `cancelled` can be reached from any state that is not final;
 * `shipped` and `cancelled` are final, and `failed` only moves to `cancelled`.
 * A line leaving `needs_attention` resumes at the state it was in or later.
 *
 * Writes are optimistic: an instance only overwrites the document it last read
 * or wrote, so a stale instance cannot move a line backwards or drop refs
 * another request stored. A document from a newer plugin version reads as
 * `needs_attention` and is never overwritten.
 */
final class LineState {

	public const META_KEY = Schema::LINE_STATE_META;

	public const ORDER_ID_META_KEY = Schema::LINE_ORDER_ID_META;

	public const DESIGN_ID_META_KEY = Schema::LINE_DESIGN_ID_META;

	public const SCHEMA_VERSION = 2;

	public const COMMITTED = 'committed';

	public const AWAITING_CUSTOMER = 'awaiting_customer';

	public const APPROVED = 'approved';

	public const PRINTED = 'printed';

	public const ORDERED = 'ordered';

	public const SHIPPED = 'shipped';

	public const FAILED = 'failed';

	public const CANCELLED = 'cancelled';

	public const NEEDS_ATTENTION = 'needs_attention';

	/**
	 * The forward path, in rank order.
	 */
	public const CHAIN = array(
		self::COMMITTED,
		self::AWAITING_CUSTOMER,
		self::APPROVED,
		self::PRINTED,
		self::ORDERED,
		self::SHIPPED,
	);

	public const STATES = array(
		self::COMMITTED,
		self::AWAITING_CUSTOMER,
		self::APPROVED,
		self::PRINTED,
		self::ORDERED,
		self::SHIPPED,
		self::FAILED,
		self::CANCELLED,
		self::NEEDS_ATTENTION,
	);

	/**
	 * States a line never leaves.
	 */
	public const FINAL = array( self::SHIPPED, self::CANCELLED );

	/**
	 * States a fulfilment run takes a line on from.
	 */
	public const FULFILLABLE = array( self::COMMITTED, self::APPROVED, self::PRINTED );

	/**
	 * States reachable from any state that is not final.
	 */
	private const REACHABLE_ANYTIME = array( self::NEEDS_ATTENTION, self::FAILED, self::CANCELLED );

	// Refs held for the whole line.
	public const REF_TENANT_ID     = 'tenantId';
	public const REF_APPROVAL_MODE = 'approvalMode';
	public const REF_CONSENT       = 'consent';
	public const REF_EXTERNAL_REF  = 'externalRef';
	// The ref of the blank variant the line's product sells, as it was added to the cart; null for one on no blank.
	public const REF_VARIANT = 'variant';
	// The printshop connection a line on no blank is ordered from, as its product chose it at checkout; absent for the workspace's default or a blank's.
	public const REF_PRINTSHOP = 'printshop';
	// True once the designs hold the order line ref; absent while they may still hold the cart ref.
	public const REF_EXTERNAL_REF_MOVED = 'externalRefMoved';
	public const REF_ORDER_ID           = 'orderId';
	public const REF_ORDER_KEY          = 'orderKey';
	public const REF_ORDER_REQUEST      = 'orderRequest';
	// True once the merchant asked to order the line's designs although they have a live order (`reorder`).
	public const REF_REORDER  = 'reorder';
	public const REF_TRACKING = 'tracking';
	// When Pixonaut created the event whose tracking `tracking` holds, epoch milliseconds; absent for tracking from a read of the order.
	public const REF_TRACKING_AT = 'trackingAt';
	// True from when a shipped line's tracking was added or corrected until the customer was emailed about it.
	public const REF_TRACKING_NOTICE = 'trackingNotice';
	// The Pixonaut order's status as last applied to the line.
	public const REF_ORDER_STATUS = 'orderStatus';
	// `{state, failures}`: transient failures in a row while the line held that state.
	public const REF_RETRY = 'retry';
	// True once the order was taken on after the line shipped: completed, or the customer and store told.
	public const REF_SHIPMENT_HANDLED = 'shipmentHandled';
	// `{trigger, failures}`: the store asked for the line to be cancelled, and why; cleared once that was settled.
	public const REF_CANCEL = 'cancel';
	// `{failures}`: the customer's personal data is to be erased in Pixonaut; cleared once it was.
	public const REF_ERASE = 'erase';
	// When the printshop order's personal data was erased, epoch milliseconds.
	public const REF_ORDER_ERASED_AT = 'orderErasedAt';
	// The Pixonaut order ids the line held before `orderId`, oldest first, each replaced by a retry of it.
	public const REF_REPLACED_ORDER_IDS = 'replacedOrderIds';
	// The name of the automation that placed the printshop order, when the line took on an order one of
	// the workspace's automations placed; '' when its history did not name it. Absent for the store's own.
	public const REF_ORDER_AUTOMATION = 'orderAutomation';
	// The `externalRef` of an order the line took on that was placed under another ref than the line's.
	public const REF_ORDER_EXTERNAL_REF = 'orderExternalRef';
	// `{at, reminders, failures}`: with proof by email, when the customer's deadline passes and the reminders go out (Unix times), and the deadline job's transient failures in a row.
	public const REF_DEADLINE = 'deadline';
	// `{cause}`: the line's proof was denied, by its deadline or the customer, and "On deny" is being applied; claims it for one handler.
	public const REF_DENIAL = 'denial';
	// `{address, change, sending, key, request, failures, awaiting, refused, state, words, code, at}`: the printshop order's delivery address, by OrderAddress::fingerprint(), as last sent; the change being sent, with its request; and what came of the last one.
	public const REF_DELIVERY = 'delivery';
	// `[{key, designId, position}]`: the sides printed from a standing design, each at its place among the product's sides. The line holds none of these designs: they are not in `sides`, so nothing moves, approves or deletes them.
	public const REF_STANDING = 'standing';
	// `{reason, limit, periodEnd}`: why Pixonaut holds the line's order, by OrderHold::of(); null once it is released.
	public const REF_HOLD = 'hold';
	// What released the held order (`upgrade`, `month_reset`), once `order.released` was applied.
	public const REF_RELEASED_BY = 'releasedBy';
	// The WordPress locale the customer bought in, e.g. `de_CH`, as `determine_locale()` answered at checkout; absent for a line bought before it was kept.
	public const REF_LOCALE = 'locale';

	public const REFS = array(
		self::REF_TENANT_ID,
		self::REF_APPROVAL_MODE,
		self::REF_CONSENT,
		self::REF_EXTERNAL_REF,
		self::REF_VARIANT,
		self::REF_PRINTSHOP,
		self::REF_EXTERNAL_REF_MOVED,
		self::REF_ORDER_ID,
		self::REF_ORDER_KEY,
		self::REF_ORDER_REQUEST,
		self::REF_REORDER,
		self::REF_TRACKING,
		self::REF_TRACKING_AT,
		self::REF_TRACKING_NOTICE,
		self::REF_ORDER_STATUS,
		self::REF_RETRY,
		self::REF_SHIPMENT_HANDLED,
		self::REF_CANCEL,
		self::REF_ERASE,
		self::REF_ORDER_ERASED_AT,
		self::REF_REPLACED_ORDER_IDS,
		self::REF_DEADLINE,
		self::REF_DENIAL,
		self::REF_DELIVERY,
		self::REF_ORDER_AUTOMATION,
		self::REF_ORDER_EXTERNAL_REF,
		self::REF_STANDING,
		self::REF_HOLD,
		self::REF_RELEASED_BY,
		self::REF_LOCALE,
	);

	// Refs held per side.
	public const SIDE_DESIGN_ID        = 'designId';
	public const SIDE_REVISION         = 'revision';
	public const SIDE_TEMPLATE_BASE_ID = 'templateBaseId';
	public const SIDE_TEMPLATE_VERSION = 'templateVersion';
	public const SIDE_COMMIT_KEY       = 'commitKey';
	// Names the side's proof image for the plugin's proof route.
	public const SIDE_PROOF_ID      = 'proofId';
	public const SIDE_PRINT_ID      = 'printId';
	public const SIDE_PRINT_KEY     = 'printKey';
	public const SIDE_PRINT_REQUEST = 'printRequest';
	// The newest revision of the design a change made in Pixonaut was noted for.
	public const SIDE_SEEN_REVISION = 'seenRevision';
	// When the design was deleted in Pixonaut, epoch milliseconds.
	public const SIDE_DELETED_AT = 'deletedAt';
	// When the design's personal data was erased in Pixonaut, epoch milliseconds.
	public const SIDE_ERASED_AT = 'erasedAt';
	// The proof page the customer approves the design on, with proof by email.
	public const SIDE_PROOF_URL = 'proofUrl';
	// True once the design is approved at the line's revision, in the studio before payment or by the customer on its proof page.
	public const SIDE_APPROVED = 'approved';
	// The revision a customer's change after asking for changes started from, while that change waits for their approval.
	public const SIDE_EDIT_BASE = 'editBase';
	// The design's field values as the customer chose them, FieldValue::to_array() entries; absent on a line that kept none, and once the design's personal data was erased.
	public const SIDE_FIELDS = 'fields';

	public const SIDE_REFS = array(
		self::SIDE_DESIGN_ID,
		self::SIDE_REVISION,
		self::SIDE_TEMPLATE_BASE_ID,
		self::SIDE_TEMPLATE_VERSION,
		self::SIDE_COMMIT_KEY,
		self::SIDE_PROOF_ID,
		self::SIDE_PRINT_ID,
		self::SIDE_PRINT_KEY,
		self::SIDE_PRINT_REQUEST,
		self::SIDE_SEEN_REVISION,
		self::SIDE_DELETED_AT,
		self::SIDE_ERASED_AT,
		self::SIDE_PROOF_URL,
		self::SIDE_APPROVED,
		self::SIDE_EDIT_BASE,
		self::SIDE_FIELDS,
	);

	/**
	 * Meta type of order item meta in the WordPress metadata API.
	 */
	private const META_TYPE = 'order_item';

	/**
	 * Object cache group the metadata API keeps order item meta in.
	 */
	private const META_CACHE_GROUP = 'order_item_meta';

	/**
	 * @param WC_Order_Item_Product $item       The order line.
	 * @param array<string, mixed>  $data       The stored document.
	 * @param string|null           $stored_raw The document as last read or written; null when none is stored.
	 */
	private function __construct(
		private readonly WC_Order_Item_Product $item,
		private array $data,
		private ?string $stored_raw = null,
	) {
	}

	/**
	 * Starts tracking a line at `committed`. Saved with save().
	 *
	 * @param WC_Order_Item_Product $item  The order line.
	 * @param string[]              $sides Side keys, in the order their designs are sent.
	 * @phpstan-param list<string> $sides
	 * @throws LogicException When the line is tracked already or a side key repeats.
	 */
	public static function start( WC_Order_Item_Product $item, array $sides ): self {
		if ( self::is_tracked( $item ) ) {
			throw new LogicException( sprintf( 'Order item %d is tracked already.', $item->get_id() ) );
		}
		if ( count( array_unique( $sides ) ) !== count( $sides ) ) {
			throw new LogicException( 'Side keys must be unique.' );
		}
		return new self(
			$item,
			array(
				'v'          => self::SCHEMA_VERSION,
				'state'      => self::COMMITTED,
				'resumeFrom' => null,
				'reason'     => null,
				'changedAt'  => time(),
				'refs'       => array(),
				'sides'      => array_map( static fn ( string $key ): array => array( 'key' => $key ), $sides ),
			)
		);
	}

	/**
	 * @param WC_Order_Item_Product $item The order line.
	 * @throws LogicException When the line is not tracked.
	 */
	public static function of( WC_Order_Item_Product $item ): self {
		$raw = self::read_raw( $item );
		if ( null === $raw ) {
			throw new LogicException( sprintf( 'Order item %d is not a Pixonaut line.', $item->get_id() ) );
		}
		return new self( $item, self::decode( $raw ), $raw );
	}

	/**
	 * The lines whose Pixonaut order is $order_id. One line places one order,
	 * so this is one line at most unless refs were copied by hand. A line the
	 * index finds is returned only when its own `orderId` ref is $order_id.
	 *
	 * @param string $order_id A Pixonaut order id.
	 * @return list<self>
	 */
	public static function for_order_id( string $order_id ): array {
		return array_values( array_filter( self::indexed_under( $order_id ), static fn ( self $line ): bool => $line->ref( self::REF_ORDER_ID ) === $order_id ) );
	}

	/**
	 * The lines the order id index still lists under $order_id although a
	 * retry replaced it: a save wrote the document but not the index. The
	 * next save of such a line brings the index in step.
	 *
	 * @param string $order_id A Pixonaut order id a retry replaced.
	 * @return list<self>
	 */
	public static function indexed_under_replaced( string $order_id ): array {
		return array_values( array_filter( self::indexed_under( $order_id ), static fn ( self $line ): bool => in_array( $order_id, $line->replaced_order_ids(), true ) ) );
	}

	/**
	 * @param string $order_id A Pixonaut order id.
	 * @return list<self> The tracked lines the order id index lists under it.
	 */
	private static function indexed_under( string $order_id ): array {
		global $wpdb;
		if ( '' === $order_id ) {
			return array();
		}
		// Order items live in WooCommerce's own tables under both order stores.
		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- an index lookup no API offers; the line is read through the metadata API below.
			$wpdb->prepare(
				"SELECT order_item_id FROM {$wpdb->prefix}woocommerce_order_itemmeta WHERE meta_key = %s AND meta_value = %s ORDER BY order_item_id",
				self::ORDER_ID_META_KEY,
				$order_id
			)
		);
		return self::lines( $ids );
	}

	/**
	 * Order item ids of the lines that may not be final. Rows whose document
	 * says `shipped` or `cancelled`, the FINAL states, are left out; the
	 * caller reads each line and decides on its state. Ids come in
	 * ascending order, a page at a time.
	 *
	 * @param int $after_item_id Only ids above this one; 0 for the first page.
	 * @param int $limit         Ids at most.
	 * @return list<int>
	 */
	public static function open_item_ids( int $after_item_id, int $limit ): array {
		global $wpdb;
		$state_is = static fn ( string $state ): string => '%' . $wpdb->esc_like( '"state":"' . $state . '"' ) . '%';
		$ids      = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a lookup no API offers, paged once per connection.
			$wpdb->prepare(
				"SELECT order_item_id FROM {$wpdb->prefix}woocommerce_order_itemmeta WHERE meta_key = %s AND meta_value NOT LIKE %s AND meta_value NOT LIKE %s AND order_item_id > %d ORDER BY order_item_id LIMIT %d",
				self::META_KEY,
				$state_is( self::SHIPPED ),
				$state_is( self::CANCELLED ),
				$after_item_id,
				$limit
			)
		);
		return array_values( array_map( 'intval', $ids ) );
	}

	/**
	 * A page of the lines one of whose sides was made from template
	 * `$base_id`, in item id order. A line the search finds is returned
	 * only when a side's own `templateBaseId` ref is `$base_id`.
	 *
	 * @param string $base_id       A template's base id.
	 * @param int    $after_item_id Only items above this one; 0 for the first page.
	 * @param int    $limit         Items at most.
	 * @return array{lines: list<self>, last: int, more: bool} The lines, the last item id looked at (0 when none was), and whether the page was full, so more may follow.
	 */
	public static function for_template( string $base_id, int $after_item_id, int $limit ): array {
		global $wpdb;
		$ref = wp_json_encode( $base_id );
		if ( '' === $base_id || false === $ref ) {
			return array(
				'lines' => array(),
				'last'  => 0,
				'more'  => false,
			);
		}
		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a lookup no API offers, made when Pixonaut changes a template's id.
			$wpdb->prepare(
				"SELECT order_item_id FROM {$wpdb->prefix}woocommerce_order_itemmeta WHERE meta_key = %s AND meta_value LIKE %s AND order_item_id > %d ORDER BY order_item_id LIMIT %d",
				self::META_KEY,
				'%' . $wpdb->esc_like( '"' . self::SIDE_TEMPLATE_BASE_ID . '":' . $ref ) . '%',
				$after_item_id,
				$limit
			)
		);
		$ids = array_values( array_map( 'intval', $ids ) );
		return array(
			'lines' => array_values( array_filter( self::lines( $ids ), static fn ( self $line ): bool => $line->uses_template( $base_id ) ) ),
			'last'  => array() === $ids ? 0 : $ids[ count( $ids ) - 1 ],
			'more'  => count( $ids ) === $limit,
		);
	}

	/**
	 * Whether one of the line's sides was made from template `$base_id`.
	 *
	 * @param string $base_id A template's base id.
	 */
	public function uses_template( string $base_id ): bool {
		foreach ( $this->sides() as $key ) {
			if ( $this->side_ref( $key, self::SIDE_TEMPLATE_BASE_ID ) === $base_id ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Names template `$to` on the sides made from `$from`, as Pixonaut
	 * changed the template's id. The caller saves the line.
	 *
	 * @param string $from The template's old base id.
	 * @param string $to   Its new base id.
	 * @return bool Whether a side was on `$from`.
	 */
	public function rename_template( string $from, string $to ): bool {
		$renamed = false;
		foreach ( $this->sides() as $key ) {
			if ( $this->side_ref( $key, self::SIDE_TEMPLATE_BASE_ID ) === $from ) {
				$this->set_side_ref( $key, self::SIDE_TEMPLATE_BASE_ID, $to );
				$renamed = true;
			}
		}
		return $renamed;
	}

	/**
	 * @param array<mixed> $ids Order item ids.
	 * @return list<self> The tracked lines among them.
	 */
	private static function lines( array $ids ): array {
		$lines = array();
		foreach ( $ids as $id ) {
			$item = WC_Order_Factory::get_order_item( (int) $id );
			if ( $item instanceof WC_Order_Item_Product && self::is_tracked( $item ) ) {
				$lines[] = self::of( $item );
			}
		}
		return $lines;
	}

	/**
	 * The lines one of whose sides is design $design_id. A line the index
	 * finds is returned only when a side's own `designId` ref is $design_id.
	 *
	 * @param string $design_id A Pixonaut design id.
	 * @return list<self>
	 */
	public static function for_design_id( string $design_id ): array {
		global $wpdb;
		if ( '' === $design_id ) {
			return array();
		}
		// Order items live in WooCommerce's own tables under both order stores.
		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- an index lookup no API offers; the line is read through the metadata API below.
			$wpdb->prepare(
				"SELECT DISTINCT order_item_id FROM {$wpdb->prefix}woocommerce_order_itemmeta WHERE meta_key = %s AND meta_value = %s ORDER BY order_item_id",
				self::DESIGN_ID_META_KEY,
				$design_id
			)
		);
		return array_values( array_filter( self::lines( $ids ), static fn ( self $line ): bool => in_array( $design_id, self::design_ids_in( $line->data ), true ) ) );
	}

	/**
	 * The key of the side holding $design_id, or null when none does.
	 *
	 * @param string $design_id A Pixonaut design id.
	 */
	public function side_of_design( string $design_id ): ?string {
		foreach ( $this->sides() as $side ) {
			if ( $this->side_ref( $side, self::SIDE_DESIGN_ID ) === $design_id ) {
				return $side;
			}
		}
		return null;
	}

	/**
	 * Whether the line carries Pixonaut state.
	 *
	 * @param WC_Order_Item_Product $item The order line.
	 */
	public static function is_tracked( WC_Order_Item_Product $item ): bool {
		return null !== self::read_raw( $item );
	}

	/**
	 * Whether a line in state $from may move to $to.
	 *
	 * @param string      $from        Current state.
	 * @param string      $to          Requested state.
	 * @param string|null $resume_from The state held before `needs_attention`, when $from is that.
	 */
	public static function can_move( string $from, string $to, ?string $resume_from = null ): bool {
		if ( $from === $to || in_array( $from, self::FINAL, true ) || ! in_array( $to, self::STATES, true ) ) {
			return false;
		}
		if ( self::FAILED === $from ) {
			return self::CANCELLED === $to;
		}
		if ( in_array( $to, self::REACHABLE_ANYTIME, true ) ) {
			return true;
		}
		if ( self::NEEDS_ATTENTION === $from ) {
			return self::rank( $to ) >= self::rank( $resume_from ?? self::COMMITTED );
		}
		return self::rank( $to ) > self::rank( $from );
	}

	/**
	 * The state; a stored state this version does not know, or a document from
	 * a newer plugin version, reads as `needs_attention`.
	 */
	public function state(): string {
		if ( $this->is_from_newer_version() ) {
			return self::NEEDS_ATTENTION;
		}
		$state = $this->data['state'] ?? null;
		return is_string( $state ) && in_array( $state, self::STATES, true ) ? $state : self::NEEDS_ATTENTION;
	}

	/**
	 * Why the line reached its state, when the transition gave a reason.
	 */
	public function reason(): ?string {
		return is_string( $this->data['reason'] ?? null ) ? $this->data['reason'] : null;
	}

	/**
	 * The state held before `needs_attention`; null in any other state.
	 */
	public function resume_from(): ?string {
		$resume = $this->data['resumeFrom'] ?? null;
		return self::NEEDS_ATTENTION === $this->state() && is_string( $resume ) ? $resume : null;
	}

	/**
	 * Unix time of the last transition.
	 */
	public function changed_at(): int {
		return is_int( $this->data['changedAt'] ?? null ) ? $this->data['changedAt'] : 0;
	}

	public function item(): WC_Order_Item_Product {
		return $this->item;
	}

	/**
	 * Moves the line to another state, saves it, then fires the transition
	 * actions. An unknown state moves the line to `needs_attention` instead.
	 *
	 * @param string $to     One of STATES.
	 * @param string $reason Why, for order notes and the admin; optional.
	 * @return bool False when the guard refuses the move, the document comes
	 *              from a newer plugin version, or the line changed elsewhere
	 *              since this instance read it; nothing is saved or fired then.
	 *              Reload with of() and decide again.
	 * @throws RuntimeException When the state cannot be encoded, and nothing is
	 *                          saved or fired; or when the order id index
	 *                          cannot be written, and the state is saved but
	 *                          nothing fired.
	 */
	public function transition( string $to, string $reason = '' ): bool {
		if ( $this->is_from_newer_version() ) {
			return false;
		}
		$from = $this->state();
		if ( ! in_array( $to, self::STATES, true ) ) {
			$reason = sprintf( 'Unknown line state "%s".', $to );
			$to     = self::NEEDS_ATTENTION;
		}
		if ( ! self::can_move( $from, $to, $this->resume_from() ) ) {
			return false;
		}
		$previous                 = $this->data;
		$previous_raw             = $this->stored_raw;
		$this->data['state']      = $to;
		$this->data['resumeFrom'] = self::NEEDS_ATTENTION === $to ? $from : null;
		$this->data['reason']     = '' === $reason ? null : $reason;
		$this->data['changedAt']  = time();
		try {
			$saved = $this->save();
		} catch ( RuntimeException $e ) {
			// Undone in memory only when the document was not written.
			if ( $this->stored_raw === $previous_raw ) {
				$this->data = $previous;
			}
			throw $e;
		}
		if ( ! $saved ) {
			$this->data = $previous;
			return false;
		}

		/**
		 * Fires after a Pixonaut order line changed state and was saved.
		 *
		 * @since 0.1.0
		 *
		 * @param string                $to    The new state.
		 * @param string                $from  The previous state.
		 * @param WC_Order_Item_Product $item  The order line.
		 * @param LineState             $state The line's state and refs.
		 *
		 * @example
		 * add_action( 'pixonaut_wc_fulfilment_transition', function ( $to, $from, $item ) {
		 *     error_log( "Line {$item->get_id()}: {$from} -> {$to}" );
		 * }, 10, 3 );
		 */
		do_action( 'pixonaut_wc_fulfilment_transition', $to, $from, $this->item, $this );

		/**
		 * Fires after a Pixonaut order line reached a state, one hook per state:
		 * `pixonaut_wc_fulfilment_committed`, `_awaiting_customer`, `_approved`,
		 * `_ordered`, `_shipped`, `_failed`, `_cancelled` and `_needs_attention`.
		 * `_printed` fires only for a line an earlier version printed, as it
		 * leaves `needs_attention`: a line now goes from `approved` to `ordered`.
		 *
		 * @since 0.1.0
		 *
		 * @param WC_Order_Item_Product $item  The order line.
		 * @param string                $from  The previous state.
		 * @param LineState             $state The line's state and refs.
		 *
		 * @example
		 * add_action( 'pixonaut_wc_fulfilment_shipped', function ( $item ) {
		 *     // Tell the warehouse.
		 * } );
		 */
		do_action( "pixonaut_wc_fulfilment_{$to}", $this->item, $from, $this );

		return true;
	}

	/**
	 * @param string $name One of REFS.
	 */
	public function ref( string $name ): mixed {
		self::assert_known( $name, self::REFS );
		$refs = is_array( $this->data['refs'] ?? null ) ? $this->data['refs'] : array();
		return $refs[ $name ] ?? null;
	}

	/**
	 * Sets a line ref. Saved with save().
	 *
	 * @param string $name  One of REFS.
	 * @param mixed  $value A JSON-encodable value; null removes it.
	 */
	public function set_ref( string $name, mixed $value ): void {
		self::assert_known( $name, self::REFS );
		$refs               = is_array( $this->data['refs'] ?? null ) ? $this->data['refs'] : array();
		$refs[ $name ]      = $value;
		$this->data['refs'] = array_filter( $refs, static fn ( mixed $ref ): bool => null !== $ref );
	}

	/**
	 * Hands the line to the Pixonaut order that replaced its own, a retry of
	 * it: `orderId` becomes $order_id, the replaced id is kept in
	 * `replacedOrderIds`, and no status of the new order counts as applied
	 * yet, so the old order's cannot hold its first one back. Nor does the
	 * old order's hold or release, so the new order can be held and released
	 * in its turn. Saved with save().
	 *
	 * @param string $order_id The new order's id.
	 */
	public function replace_order( string $order_id ): void {
		$current = $this->order_id();
		if ( $current === $order_id ) {
			return;
		}
		if ( null !== $current ) {
			$replaced   = $this->replaced_order_ids();
			$replaced[] = $current;
			$this->set_ref( self::REF_REPLACED_ORDER_IDS, $replaced );
		}
		$this->set_ref( self::REF_ORDER_ID, $order_id );
		$this->set_ref( self::REF_ORDER_STATUS, null );
		$this->set_ref( self::REF_ORDER_AUTOMATION, null );
		$this->set_ref( self::REF_HOLD, null );
		$this->set_ref( self::REF_RELEASED_BY, null );
	}

	/**
	 * @return list<string> The Pixonaut order ids the line held before its current one, oldest first.
	 */
	public function replaced_order_ids(): array {
		$ids = $this->ref( self::REF_REPLACED_ORDER_IDS );
		return is_array( $ids ) ? array_values( array_filter( $ids, 'is_string' ) ) : array();
	}

	/**
	 * The sides printed from a standing design.
	 *
	 * @return list<StandingSide>
	 */
	public function standing(): array {
		$standing = array();
		$stored   = $this->ref( self::REF_STANDING );
		foreach ( is_array( $stored ) ? $stored : array() as $side ) {
			$parsed = StandingSide::from_array( $side );
			if ( null !== $parsed ) {
				$standing[] = $parsed;
			}
		}
		return $standing;
	}

	/**
	 * @return list<string> The keys of the sides the customer designed, in the order their designs are sent.
	 */
	public function sides(): array {
		$keys = array();
		foreach ( $this->stored_sides() as $side ) {
			$keys[] = $side['key'];
		}
		return $keys;
	}

	/**
	 * Whether the side's design had its personal data erased in Pixonaut,
	 * after the workspace's retention period or on a request alike: its
	 * proof is gone.
	 *
	 * @param string $side A side key.
	 */
	public function is_side_erased( string $side ): bool {
		return null !== $this->side_ref( $side, self::SIDE_ERASED_AT );
	}

	/**
	 * The proof pages still waiting for the customer, one per side, in the
	 * order the sides' designs are sent: a side approved already, in the
	 * studio or on its page, has none to show, and one whose design was
	 * erased has none left.
	 *
	 * @return list<string>
	 */
	public function waiting_proof_pages(): array {
		$urls = array();
		foreach ( $this->sides() as $side ) {
			$url = $this->side_ref( $side, self::SIDE_PROOF_URL );
			if ( is_string( $url ) && '' !== $url && true !== $this->side_ref( $side, self::SIDE_APPROVED ) && ! $this->is_side_erased( $side ) ) {
				$urls[] = $url;
			}
		}
		return $urls;
	}

	/**
	 * The side's field values, as the customer chose them; none on a line
	 * that kept none.
	 *
	 * @param string $side A side key.
	 *
	 * @return list<FieldValue>
	 */
	public function side_fields( string $side ): array {
		return FieldValue::list_from( $this->side_ref( $side, self::SIDE_FIELDS ) );
	}

	/**
	 * @param string $side A side key.
	 * @param string $name One of SIDE_REFS.
	 */
	public function side_ref( string $side, string $name ): mixed {
		self::assert_known( $name, self::SIDE_REFS );
		$sides = $this->stored_sides();
		return $sides[ $this->side_index( $side ) ][ $name ] ?? null;
	}

	/**
	 * Sets a side ref. Saved with save().
	 *
	 * @param string $side  A side key.
	 * @param string $name  One of SIDE_REFS.
	 * @param mixed  $value A JSON-encodable value; null removes it.
	 */
	public function set_side_ref( string $side, string $name, mixed $value ): void {
		self::assert_known( $name, self::SIDE_REFS );
		$sides = $this->stored_sides();
		$index = $this->side_index( $side );
		if ( null === $value ) {
			unset( $sides[ $index ][ $name ] );
		} else {
			$sides[ $index ][ $name ] = $value;
		}
		// The values the customer chose are their personal data too: they go with the design's.
		if ( self::SIDE_ERASED_AT === $name && null !== $value ) {
			unset( $sides[ $index ][ self::SIDE_FIELDS ] );
		}
		$this->data['sides'] = $sides;
	}

	/**
	 * Writes the state to the item meta. An item already in the database is
	 * written at once, only over the document this instance last read or
	 * wrote; a new one is saved with its order.
	 *
	 * @return bool False when the line changed elsewhere since this instance
	 *              read it; nothing is written then. Reload with of().
	 * @throws LogicException When the stored document comes from a newer plugin version.
	 * @throws RuntimeException When the state cannot be encoded, and nothing is
	 *                          written; or when the order id index cannot be
	 *                          brought in step, after the document was written.
	 */
	public function save(): bool {
		if ( $this->is_from_newer_version() ) {
			throw new LogicException( 'The stored Pixonaut line state comes from a newer plugin version; it is not overwritten.' );
		}
		$json = wp_json_encode( $this->data );
		if ( false === $json ) {
			throw new RuntimeException( 'The Pixonaut line state could not be encoded as JSON.' );
		}
		$order_id = $this->order_id();
		$id       = $this->item->get_id();
		if ( $id <= 0 ) {
			$this->item->update_meta_data( self::META_KEY, $json );
			if ( null !== $order_id ) {
				$this->item->update_meta_data( self::ORDER_ID_META_KEY, $order_id );
			}
			$this->item->delete_meta_data( self::DESIGN_ID_META_KEY );
			foreach ( self::design_ids_in( $this->data ) as $design_id ) {
				$this->item->add_meta_data( self::DESIGN_ID_META_KEY, $design_id );
			}
			$this->stored_raw = $json;
			return true;
		}
		if ( $json !== $this->stored_raw ) {
			// The metadata API unslashes the new value but compares the previous one as is.
			$written = null === $this->stored_raw
				? false !== add_metadata( self::META_TYPE, $id, self::META_KEY, wp_slash( $json ), true )
				: false !== update_metadata( self::META_TYPE, $id, self::META_KEY, wp_slash( $json ), $this->stored_raw );
			if ( ! $written ) {
				// The cached meta may be what went stale: the next of() reads the database.
				wp_cache_delete( (string) $id, self::META_CACHE_GROUP );
				return false;
			}
			$this->stored_raw = $json;
		}
		self::index_order_id( $id, $order_id );
		self::index_design_ids( $id, self::design_ids_in( $this->data ) );
		return true;
	}

	/**
	 * Brings the item's order id index in step with its document, repairing
	 * an index an earlier write left behind.
	 *
	 * @param int         $id       Order item id.
	 * @param string|null $order_id The order id the document holds.
	 * @throws RuntimeException When the index cannot be written.
	 */
	private static function index_order_id( int $id, ?string $order_id ): void {
		$indexed = get_metadata( self::META_TYPE, $id, self::ORDER_ID_META_KEY, true );
		$indexed = is_string( $indexed ) && '' !== $indexed ? $indexed : null;
		if ( $order_id === $indexed ) {
			return;
		}
		$written = null === $order_id
			? delete_metadata( self::META_TYPE, $id, self::ORDER_ID_META_KEY )
			: false !== update_metadata( self::META_TYPE, $id, self::ORDER_ID_META_KEY, wp_slash( $order_id ) );
		if ( ! $written ) {
			throw new RuntimeException( sprintf( 'The Pixonaut order id index of order item %d could not be written.', (int) $id ) );
		}
	}

	/**
	 * Brings the item's design id index in step with its document.
	 *
	 * @param int      $id         Order item id.
	 * @param string[] $design_ids The design ids the document holds.
	 * @throws RuntimeException When the index cannot be written.
	 */
	private static function index_design_ids( int $id, array $design_ids ): void {
		$indexed = get_metadata( self::META_TYPE, $id, self::DESIGN_ID_META_KEY, false );
		$indexed = is_array( $indexed ) ? array_values( array_filter( $indexed, 'is_string' ) ) : array();
		sort( $indexed );
		sort( $design_ids );
		if ( $indexed === $design_ids ) {
			return;
		}
		if ( array() !== $indexed && ! delete_metadata( self::META_TYPE, $id, self::DESIGN_ID_META_KEY ) ) {
			throw new RuntimeException( sprintf( 'The Pixonaut design id index of order item %d could not be written.', (int) $id ) );
		}
		foreach ( $design_ids as $design_id ) {
			if ( false === add_metadata( self::META_TYPE, $id, self::DESIGN_ID_META_KEY, wp_slash( $design_id ) ) ) {
				throw new RuntimeException( sprintf( 'The Pixonaut design id index of order item %d could not be written.', (int) $id ) );
			}
		}
	}

	/**
	 * @param array<string, mixed> $data A stored document.
	 * @return list<string> Its sides' design ids, each once.
	 */
	private static function design_ids_in( array $data ): array {
		$ids = array();
		foreach ( is_array( $data['sides'] ?? null ) ? $data['sides'] : array() as $side ) {
			$id = is_array( $side ) ? ( $side[ self::SIDE_DESIGN_ID ] ?? null ) : null;
			if ( is_string( $id ) && '' !== $id ) {
				$ids[] = $id;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	private function order_id(): ?string {
		return self::order_id_in( $this->data );
	}

	/**
	 * @param array<string, mixed> $data A stored document.
	 */
	private static function order_id_in( array $data ): ?string {
		$order_id = is_array( $data['refs'] ?? null ) ? ( $data['refs'][ self::REF_ORDER_ID ] ?? null ) : null;
		return is_string( $order_id ) && '' !== $order_id ? $order_id : null;
	}

	/**
	 * The stored document as is. An item in the database is read from there,
	 * not from the item's meta, which may be stale.
	 *
	 * @param WC_Order_Item_Product $item The order line.
	 * @return string|null Null when there is none.
	 */
	private static function read_raw( WC_Order_Item_Product $item ): ?string {
		$id  = $item->get_id();
		$raw = $id > 0
			? get_metadata( self::META_TYPE, $id, self::META_KEY, true )
			: $item->get_meta( self::META_KEY, true );
		return is_string( $raw ) && '' !== $raw ? $raw : null;
	}

	/**
	 * @param string $raw A stored document.
	 * @return array<string, mixed> The document at SCHEMA_VERSION, or as stored when it is newer.
	 */
	private static function decode( string $raw ): array {
		$data    = json_decode( $raw, true );
		$version = is_array( $data ) && is_int( $data['v'] ?? null ) ? $data['v'] : 0;
		if ( ! is_array( $data ) || $version < 1 ) {
			// Unreadable state still marks a Pixonaut line; state() reads it as needs_attention.
			return array( 'v' => self::SCHEMA_VERSION );
		}
		if ( $version > self::SCHEMA_VERSION ) {
			return $data;
		}
		foreach ( self::upgrades() as $from => $upgrade ) {
			if ( $from >= $version ) {
				$data = $upgrade( $data );
			}
		}
		$data['v'] = self::SCHEMA_VERSION;
		return $data;
	}

	/**
	 * Steps that upgrade a stored document by one version, keyed by the
	 * version they upgrade from, in order. Add one with every schema change.
	 *
	 * @return array<int, callable(array<string, mixed>): array<string, mixed>>
	 */
	private static function upgrades(): array {
		return array(
			// Version 1 had no `externalRefMoved`: a line whose ref is not a cart ref was moved at checkout.
			1 => static function ( array $data ): array {
				$refs = is_array( $data['refs'] ?? null ) ? $data['refs'] : array();
				$ref  = $refs[ self::REF_EXTERNAL_REF ] ?? null;
				if ( is_string( $ref ) && '' !== $ref && ! Refs::is_cart_ref( $ref ) ) {
					$refs[ self::REF_EXTERNAL_REF_MOVED ] = true;
					$data['refs']                         = $refs;
				}
				return $data;
			},
		);
	}

	private function is_from_newer_version(): bool {
		return is_int( $this->data['v'] ?? null ) && $this->data['v'] > self::SCHEMA_VERSION;
	}

	/**
	 * @return list<array<string, mixed>&array{key: string}>
	 */
	private function stored_sides(): array {
		$sides = array();
		foreach ( is_array( $this->data['sides'] ?? null ) ? $this->data['sides'] : array() as $side ) {
			if ( is_array( $side ) && is_string( $side['key'] ?? null ) ) {
				$sides[] = $side;
			}
		}
		return $sides;
	}

	/**
	 * @param string $side A side key.
	 * @throws LogicException When the line has no such side.
	 */
	private function side_index( string $side ): int {
		foreach ( $this->stored_sides() as $index => $stored ) {
			if ( $stored['key'] === $side ) {
				return $index;
			}
		}
		throw new LogicException( sprintf( 'The line has no side "%s".', $side ) );
	}

	private static function rank( string $state ): int {
		$rank = array_search( $state, self::CHAIN, true );
		return false === $rank ? 0 : $rank;
	}

	/**
	 * @param string   $name  A ref name.
	 * @param string[] $known The names allowed.
	 * @phpstan-param list<string> $known
	 * @throws LogicException When the name is not allowed.
	 */
	private static function assert_known( string $name, array $known ): void {
		if ( ! in_array( $name, $known, true ) ) {
			throw new LogicException( sprintf( 'Unknown line ref "%s".', $name ) );
		}
	}
}
