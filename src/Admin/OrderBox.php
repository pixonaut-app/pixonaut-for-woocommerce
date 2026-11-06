<?php
/**
 * The Pixonaut box on the order screen.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Admin;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Fulfilment\AdoptedOrder;
use Pixonaut\WooCommerce\Fulfilment\DeliveryChange;
use Pixonaut\WooCommerce\Fulfilment\LineActionResult;
use Pixonaut\WooCommerce\Fulfilment\LineActions;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Fulfilment\NeedsAttention;
use Pixonaut\WooCommerce\Fulfilment\OrderHold;
use Pixonaut\WooCommerce\Fulfilment\OrderStatus;
use Pixonaut\WooCommerce\Proofs\ProofUrls;
use Pixonaut\WooCommerce\Templates;
use WC_Order;
use WC_Order_Item_Product;
use WP_Post;

/**
 * One section per Pixonaut line of the order: its sides with their proofs
 * and a link to each design in the studio, where the line stands, and the
 * actions it offers. Works on both order stores' screens.
 *
 * The box sits inside the order's own form, which cannot hold another, so
 * an action is a submit button of that form: the order is saved as with
 * Update, then the action runs on `woocommerce_process_shop_order_meta`,
 * once WooCommerce's own saves are done.
 */
final class OrderBox {

	public const BOX_ID = 'pixonaut-order-lines';

	/**
	 * Submit button name; its value is `<item id>:<action>`.
	 */
	public const ACTION_FIELD = 'pixonaut_line_action';

	/**
	 * Per-line inputs, `pixonaut_line[<item id>][reason|ref]`.
	 */
	public const INPUT_FIELD = 'pixonaut_line';

	public const NONCE_FIELD = 'pixonaut_line_action_nonce';

	public const NONCE_ACTION = 'pixonaut_wc_line_action';

	/**
	 * Priority of the action on `woocommerce_process_shop_order_meta`.
	 * WooCommerce saves the order's items and data there at 10 to 50, from
	 * what the screen loaded, which would write a line's state back over
	 * what the action stored had the action run first.
	 */
	public const AFTER_ORDER_SAVED = 100;

	/**
	 * Who may act on a line: the store's managers, as for its connection.
	 */
	public const CAPABILITY = Notices::CAPABILITY;

	/**
	 * Width, in pixels, a side's proof is shown at.
	 */
	public const PROOF_WIDTH = 160;

	/**
	 * How long an action's result waits to be shown, in seconds: five
	 * minutes. Not `MINUTE_IN_SECONDS`, which is not defined outside WordPress.
	 */
	private const RESULT_TTL = 300;

	/**
	 * The code of the read that found Pixonaut unreachable during this
	 * render; the lines after it are not read, so an outage costs one
	 * timeout rather than one per line. Null while every read was answered.
	 *
	 * @var string|null
	 */
	private ?string $outage = null;

	/**
	 * @param LineActions $actions   The actions a line offers.
	 * @param ProofUrls   $urls      Mints the proof URLs.
	 * @param Templates   $templates Renders the box.
	 */
	public function __construct(
		private readonly LineActions $actions,
		private readonly ProofUrls $urls,
		private readonly Templates $templates,
	) {
	}

	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add' ), 10, 2 );
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'handle' ), self::AFTER_ORDER_SAVED, 1 );
	}

	/**
	 * Adds the box to an order with Pixonaut lines.
	 *
	 * @param string $screen_id     The screen's id or post type.
	 * @param mixed  $post_or_order The order, or its post on the posts store.
	 */
	public function add( string $screen_id, mixed $post_or_order ): void {
		$order_screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		$order        = self::order( $post_or_order );
		if ( $order_screen !== $screen_id || ! $order instanceof WC_Order || array() === self::lines( $order ) ) {
			return;
		}
		/* translators: Title of the Pixonaut box on the order screen, shown to the merchant; the product name, keep it as it is. */
		add_meta_box( self::BOX_ID, __( 'Pixonaut', 'pixonaut-for-woocommerce' ), array( $this, 'render' ), $order_screen, 'normal', 'default' );
	}

	/**
	 * @param mixed $post_or_order The order, or its post on the posts store.
	 */
	public function render( mixed $post_or_order ): void {
		$order = self::order( $post_or_order );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$this->outage = null;
		$this->templates->render(
			'admin/order-box.php',
			array(
				'lines'       => array_map( fn ( LineState $line ): array => $this->view( $line ), self::lines( $order ) ),
				'can_act'     => current_user_can( self::CAPABILITY ),
				'nonce_field' => wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD, true, false ),
				'result'      => $this->take_result( $order->get_id() ),
			)
		);
	}

	/**
	 * Runs the action whose button saved the order.
	 *
	 * @param int|string $order_id The order saved.
	 */
	public function handle( int|string $order_id ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified below, once the order form is known to carry an action.
		if ( ! isset( $_POST[ self::ACTION_FIELD ] ) ) {
			return;
		}
		$pressed = sanitize_text_field( wp_unslash( $_POST[ self::ACTION_FIELD ] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) ? sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) || ! current_user_can( self::CAPABILITY ) ) {
			$this->keep_result( (int) $order_id, new LineActionResult( LineActions::NOT_AVAILABLE ) );
			return;
		}
		list( $item_id, $action ) = array_pad( explode( ':', $pressed, 2 ), 2, '' );
		$line                     = self::line( (int) $order_id, (int) $item_id );
		if ( null === $line ) {
			$this->keep_result( (int) $order_id, new LineActionResult( LineActions::NOT_AVAILABLE ) );
			return;
		}
		$inputs = isset( $_POST[ self::INPUT_FIELD ][ $item_id ] ) && is_array( $_POST[ self::INPUT_FIELD ][ $item_id ] ) ? map_deep( wp_unslash( $_POST[ self::INPUT_FIELD ][ $item_id ] ), 'sanitize_textarea_field' ) : array();
		$reason = is_string( $inputs['reason'] ?? null ) ? $inputs['reason'] : '';
		$ref    = is_string( $inputs['ref'] ?? null ) ? $inputs['ref'] : '';
		$this->keep_result( (int) $order_id, $this->actions->run( $line, $action, $reason, $ref ) );
	}

	/**
	 * What the template shows of a line. Reading its order brings the line
	 * in step with it first, so the line shown is the one Pixonaut has.
	 *
	 * @param LineState $line The line.
	 *
	 * @return array<string, mixed>
	 */
	private function view( LineState $line ): array {
		$order      = null;
		$unreadable = '';
		if ( $this->actions->reads_order( $line ) ) {
			if ( null !== $this->outage ) {
				$unreadable = $this->outage;
			} else {
				try {
					list( $line, $order ) = $this->actions->current( $line );
				} catch ( ApiException $e ) {
					$unreadable = $e->errorCode;
					if ( 0 === $e->status || $e->retryable ) {
						$this->outage = $e->errorCode;
					}
				}
			}
		} elseif ( null === $this->outage && $this->actions->reads_erasure( $line ) ) {
			try {
				$read = $this->actions->order_of( $line );
				$line = null === $read ? $line : $this->actions->keep_erasure( $line, $read );
			} catch ( ApiException $e ) {
				// Nothing is done on a finished line: the erasure is asked about again on the next view.
				if ( 0 === $e->status || $e->retryable ) {
					$this->outage = $e->errorCode;
				}
			}
		}
		$order_id = $line->ref( LineState::REF_ORDER_ID );
		$status   = $line->ref( LineState::REF_ORDER_STATUS );
		return array(
			'item_id'      => $line->item()->get_id(),
			'name'         => $line->item()->get_name(),
			'state'        => $line->state(),
			'state_label'  => self::state_label( $line->state(), is_string( $status ) ? $status : null ),
			'reason'       => self::reason( $line ),
			'sides'        => $this->sides( $line ),
			'order_id'     => is_string( $order_id ) ? $order_id : '',
			'order_url'    => is_string( $order_id ) && '' !== $order_id ? StudioPage::url( StudioPage::ORDERS, $order_id ) : '',
			'order_status' => is_string( $order['status'] ?? null ) ? $order['status'] : '',
			'made_by'      => AdoptedOrder::label( $line ),
			'delivery'     => self::delivery_label( $line ),
			// The order's recipient was erased in Pixonaut: after the retention period, or on a request.
			'order_erased' => null !== $line->ref( LineState::REF_ORDER_ERASED_AT ),
			'unreadable'   => $unreadable,
			'actions'      => '' === $unreadable ? $this->actions->available( $line, $order ) : array(),
		);
	}

	/**
	 * Why the line waits, for a line that needs the merchant or whose order
	 * Pixonaut holds; empty otherwise.
	 *
	 * @param LineState $line The line.
	 */
	public static function reason( LineState $line ): string {
		$reason = $line->reason();
		if ( LineState::NEEDS_ATTENTION === $line->state() && null !== $reason ) {
			return NeedsAttention::explain( $reason );
		}
		if ( LineState::ORDERED === $line->state() && OrderStatus::HELD === $line->ref( LineState::REF_ORDER_STATUS ) ) {
			return OrderHold::explain( $line );
		}
		return '';
	}

	/**
	 * A side whose design's personal data was erased has no proof left, and
	 * its design in the studio holds nothing of the customer's: neither is
	 * linked.
	 *
	 * @param LineState $line The line.
	 *
	 * @return list<array{key: string, design_id: string, proof_url: string, proof_srcset: string, studio_url: string, erased: bool}>
	 */
	private function sides( LineState $line ): array {
		$sides = array();
		foreach ( $line->sides() as $side ) {
			$design_id = $line->side_ref( $side, LineState::SIDE_DESIGN_ID );
			$proof_id  = $line->side_ref( $side, LineState::SIDE_PROOF_ID );
			$design_id = is_string( $design_id ) ? $design_id : '';
			$erased    = $line->is_side_erased( $side );
			$has_proof = ! $erased && '' !== $design_id && is_string( $proof_id ) && '' !== $proof_id;
			$sides[]   = array(
				'key'          => $side,
				'design_id'    => $design_id,
				'proof_url'    => $has_proof ? $this->urls->url( $design_id, $proof_id, self::PROOF_WIDTH ) : '',
				'proof_srcset' => $has_proof ? $this->urls->url( $design_id, $proof_id, self::PROOF_WIDTH * 2 ) . ' 2x' : '',
				'studio_url'   => $erased || '' === $design_id ? '' : StudioPage::url( StudioPage::DESIGNS, $design_id ),
				'erased'       => $erased,
			);
		}
		return $sides;
	}

	/**
	 * A line state in words.
	 *
	 * @param string      $state        One of LineState::STATES.
	 * @param string|null $order_status The Pixonaut order's status as last applied to the line; null when it has none.
	 */
	public static function state_label( string $state, ?string $order_status = null ): string {
		if ( LineState::ORDERED === $state && OrderStatus::AWAITING_PRINTS === $order_status ) {
			/* translators: Status of an order line in the order box on the order screen, shown to the merchant: the order waits for the printed items. */
			return __( 'Waiting for prints', 'pixonaut-for-woocommerce' );
		}
		if ( LineState::ORDERED === $state && OrderStatus::HELD === $order_status ) {
			/* translators: Status of an order line in the order box on the order screen, shown to the merchant: Pixonaut holds the order. */
			return __( 'Held by Pixonaut', 'pixonaut-for-woocommerce' );
		}
		return match ( $state ) {
			/* translators: Status of an order line in the order box on the order screen, shown to the merchant: the order is not paid yet. */
			LineState::COMMITTED         => __( 'Waiting for payment', 'pixonaut-for-woocommerce' ),
			/* translators: Status of an order line in the order box on the order screen, shown to the merchant: the customer has not yet approved the proof. */
			LineState::AWAITING_CUSTOMER => __( 'Waiting for the customer to approve the proof', 'pixonaut-for-woocommerce' ),
			/* translators: Status of an order line in the order box on the order screen, shown to the merchant: the design is approved and being printed. */
			LineState::APPROVED          => __( 'Approved, printing', 'pixonaut-for-woocommerce' ),
			/* translators: Status of an order line in the order box on the order screen, shown to the merchant: the print file is ready and the order is being placed at the printshop. */
			LineState::PRINTED           => __( 'Printed, ordering from the printshop', 'pixonaut-for-woocommerce' ),
			/* translators: Status of an order line in the order box on the order screen, shown to the merchant: the printshop has the order. */
			LineState::ORDERED           => __( 'At the printshop', 'pixonaut-for-woocommerce' ),
			/* translators: Status of an order line in the order box on the order screen, shown to the merchant: the printshop has shipped it. */
			LineState::SHIPPED           => __( 'Shipped', 'pixonaut-for-woocommerce' ),
			/* translators: Status of an order line in the order box on the order screen, shown to the merchant: the item could not be produced. */
			LineState::FAILED            => __( 'Failed', 'pixonaut-for-woocommerce' ),
			/* translators: Status of an order line in the order box on the order screen, shown to the merchant: the item is cancelled. */
			LineState::CANCELLED         => __( 'Cancelled', 'pixonaut-for-woocommerce' ),
			/* translators: Status of an order line in the order box on the order screen, shown to the merchant: the merchant has to look at the item. */
			default                      => __( 'Needs attention', 'pixonaut-for-woocommerce' ),
		};
	}

	/**
	 * Where the line's last change of delivery address stands, in words;
	 * empty when its address never changed after it was ordered.
	 *
	 * @param LineState $line The line.
	 */
	public static function delivery_label( LineState $line ): string {
		$delivery = DeliveryChange::of( $line );
		$code     = $delivery[ DeliveryChange::FIELD_CODE ] ?? null;
		if ( isset( $delivery[ DeliveryChange::FIELD_SENDING ] ) ) {
			// With a change on its way, a code is the last failure of one tried as often as the backoff allows.
			return is_string( $code )
				/* translators: %s: the error code Pixonaut answered. */
				? sprintf( __( 'New delivery address: not known whether it reached the printshop (Pixonaut answered "%s"). Update the order to send it again.', 'pixonaut-for-woocommerce' ), $code )
				/* translators: Note on an order line in the order box, shown to the merchant, after the delivery address changed. */
				: __( 'New delivery address: being sent to Pixonaut.', 'pixonaut-for-woocommerce' );
		}
		if ( is_string( $code ) ) {
			/* translators: %s: the error code Pixonaut answered. */
			return sprintf( __( 'New delivery address: not sent, Pixonaut answered "%s". The order ships to the address it had.', 'pixonaut-for-woocommerce' ), $code );
		}
		$words = $delivery[ DeliveryChange::FIELD_WORDS ] ?? null;
		return match ( $delivery[ DeliveryChange::FIELD_STATE ] ?? null ) {
			/* translators: Note in the order box, shown to the merchant, after the delivery address changed. */
			DeliveryChange::ACCEPTED => __( 'New delivery address: taken by the printshop.', 'pixonaut-for-woocommerce' ),
			/* translators: Note in the order box, shown to the merchant, after the delivery address changed. */
			DeliveryChange::CARRIED  => __( 'New delivery address: goes to the printshop with the order.', 'pixonaut-for-woocommerce' ),
			/* translators: Note in the order box, shown to the merchant, after the delivery address changed. */
			DeliveryChange::SENDING  => __( 'New delivery address: Pixonaut is telling the printshop.', 'pixonaut-for-woocommerce' ),
			DeliveryChange::REFUSED  => is_string( $words ) && '' !== $words
				/* translators: %s: the printshop's answer. */
				? sprintf( __( 'New delivery address: refused by the printshop ("%s"). The order ships to the address it had.', 'pixonaut-for-woocommerce' ), $words )
				/* translators: Note in the order box, shown to the merchant, after the delivery address changed. */
				: __( 'New delivery address: refused by the printshop. The order ships to the address it had.', 'pixonaut-for-woocommerce' ),
			default                  => '',
		};
	}

	/**
	 * Keeps an action's result for the screen the save returns to.
	 *
	 * @param int              $order_id The order.
	 * @param LineActionResult $result   What came of the action.
	 */
	private function keep_result( int $order_id, LineActionResult $result ): void {
		set_transient(
			self::result_key( $order_id ),
			array(
				'code'    => $result->code,
				'detail'  => $result->detail,
				'message' => $result->message,
			),
			self::RESULT_TTL
		);
	}

	/**
	 * The result kept for this order and user, once.
	 *
	 * @param int $order_id The order.
	 *
	 * @return array{code: string, detail: string, message: string}|null
	 */
	private function take_result( int $order_id ): ?array {
		$key    = self::result_key( $order_id );
		$result = get_transient( $key );
		if ( ! is_array( $result ) || ! is_string( $result['code'] ?? null ) ) {
			return null;
		}
		delete_transient( $key );
		return array(
			'code'    => $result['code'],
			'detail'  => is_string( $result['detail'] ?? null ) ? $result['detail'] : '',
			'message' => is_string( $result['message'] ?? null ) ? $result['message'] : '',
		);
	}

	private static function result_key( int $order_id ): string {
		return 'pixonaut_wc_line_action_' . get_current_user_id() . '_' . $order_id;
	}

	/**
	 * @param WC_Order $order The order.
	 *
	 * @return list<LineState>
	 */
	private static function lines( WC_Order $order ): array {
		$lines = array();
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof WC_Order_Item_Product && LineState::is_tracked( $item ) ) {
				$lines[] = LineState::of( $item );
			}
		}
		return $lines;
	}

	/**
	 * The order's Pixonaut line with the id; null for any other item, including
	 * another order's (WC_Order::get_item() loads any item by its id).
	 *
	 * @param int $order_id The order.
	 * @param int $item_id  The item.
	 */
	public static function line( int $order_id, int $item_id ): ?LineState {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return null;
		}
		$item = $order->get_item( $item_id );
		return $item instanceof WC_Order_Item_Product
			&& $item->get_order_id() === $order->get_id()
			&& LineState::is_tracked( $item ) ? LineState::of( $item ) : null;
	}

	/**
	 * @param mixed $post_or_order The order, or its post on the posts store.
	 */
	private static function order( mixed $post_or_order ): ?WC_Order {
		$order = $post_or_order instanceof WP_Post ? wc_get_order( $post_or_order->ID ) : $post_or_order;
		return $order instanceof WC_Order ? $order : null;
	}
}
