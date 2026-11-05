<?php
/**
 * Carries personalised cart lines into the order.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Cart;

use Pixonaut\WooCommerce\Api\ApiError;
use Pixonaut\WooCommerce\Api\Designs;
use Pixonaut\WooCommerce\Api\Logger;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ProductVariant;
use Pixonaut\WooCommerce\Connection\Connection;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Fulfilment\OrderLineRef;
use Pixonaut\WooCommerce\Refs;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;

/**
 * Both checkouts build order lines with `woocommerce_checkout_create_order_line_item`
 * (the block checkout again whenever the cart changes, on fresh items), so
 * each personalised line's designs are copied to its `LineState` there. Item
 * meta is what links a line to its designs from then on.
 *
 * Once the order is placed and its items have ids, each design's
 * `externalRef` moves from the cart to the order line. A move that fails is
 * logged and leaves the cart ref in the line's refs, not marked as moved; the
 * line still holds its designs, and fulfilment makes the move before it sends
 * anything else.
 */
final class OrderLines {

	public function __construct(
		private readonly Connection $connection,
		private readonly Designs $designs,
		private readonly Logger $logger,
	) {
	}

	public function register(): void {
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'copy_to_line' ), 10, 3 );
		add_action( 'woocommerce_checkout_order_created', array( $this, 'move_refs' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'move_refs' ) );
	}

	/**
	 * Starts tracking a new order line with its cart line's designs. A line
	 * already tracked is left as it is.
	 *
	 * @param mixed $item          The new order line.
	 * @param mixed $cart_item_key The cart item's key; unused.
	 * @param mixed $values        The cart item.
	 */
	public function copy_to_line( mixed $item, mixed $cart_item_key, mixed $values ): void {
		unset( $cart_item_key );
		if ( ! $item instanceof WC_Order_Item_Product || ! is_array( $values ) || LineState::is_tracked( $item ) ) {
			return;
		}
		$line = CartLine::of( $values );
		if ( null === $line ) {
			return;
		}

		$state = LineState::start( $item, array_map( static fn ( CommittedSide $side ): string => $side->key, $line->sides ) );
		$state->set_ref( LineState::REF_TENANT_ID, $line->tenant_id );
		$state->set_ref( LineState::REF_APPROVAL_MODE, $line->approval_mode );
		$state->set_ref( LineState::REF_CONSENT, $line->consent );
		$state->set_ref( LineState::REF_EXTERNAL_REF, $line->external_ref );
		$state->set_ref( LineState::REF_VARIANT, $line->variant ?? self::variant_sold_now( $values ) );
		$state->set_ref( LineState::REF_PRINTSHOP, self::printshop( $values ) );
		$state->set_ref( LineState::REF_LOCALE, determine_locale() );
		$state->set_ref( LineState::REF_STANDING, array() === $line->standing ? null : array_map( static fn ( StandingSide $side ): array => $side->to_array(), $line->standing ) );
		foreach ( $line->sides as $side ) {
			$state->set_side_ref( $side->key, LineState::SIDE_DESIGN_ID, $side->design_id );
			$state->set_side_ref( $side->key, LineState::SIDE_REVISION, $side->revision );
			$state->set_side_ref( $side->key, LineState::SIDE_TEMPLATE_BASE_ID, $side->template_base_id );
			$state->set_side_ref( $side->key, LineState::SIDE_TEMPLATE_VERSION, $side->template_version );
			$state->set_side_ref( $side->key, LineState::SIDE_COMMIT_KEY, $side->commit_key );
			$state->set_side_ref( $side->key, LineState::SIDE_PROOF_ID, $side->proof_id );
			$state->set_side_ref( $side->key, LineState::SIDE_FIELDS, array() === $side->fields ? null : FieldValue::list_to_array( $side->fields ) );
		}
		$state->save();
	}

	/**
	 * The blank variant a cart item's product sells now: for a line put in
	 * the cart before lines carried their variant. Null when it is on no
	 * blank; one on a blank without a variant cannot be bought, so
	 * WooCommerce takes it out of the cart before checkout.
	 *
	 * @param array<mixed> $values The cart item.
	 */
	private static function variant_sold_now( array $values ): ?string {
		$product = $values['data'] ?? null;
		return $product instanceof WC_Product ? ProductVariant::sold_by( $product, ProductConfig::config_for( $product ) ) : null;
	}

	/**
	 * The printshop a cart item's product on no blank chose for its orders;
	 * null for the workspace's default, and for one on a blank, whose blank
	 * decides.
	 *
	 * @param array<mixed> $values The cart item.
	 */
	private static function printshop( array $values ): ?string {
		$product = $values['data'] ?? null;
		return $product instanceof WC_Product ? ProductConfig::config_for( $product )->order_printshop() : null;
	}

	/**
	 * Moves the designs of a placed order's lines to their order line refs.
	 * Lines whose refs moved already, or that belong to another workspace,
	 * are skipped, so a second call sends nothing.
	 *
	 * @param mixed $order The placed order.
	 */
	public function move_refs( mixed $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$connection = $this->connection->current();
		if ( null === $connection || null !== $this->connection->paused() ) {
			return;
		}
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product || ! LineState::is_tracked( $item ) ) {
				continue;
			}
			$state = LineState::of( $item );
			if ( $state->ref( LineState::REF_TENANT_ID ) !== $connection->tenant_id || OrderLineRef::moved( $state ) ) {
				continue;
			}
			$ref = Refs::order_item_ref( $connection->site, $order->get_id(), $item->get_id() );
			try {
				OrderLineRef::move( $this->designs, $state, $ref );
			} catch ( ApiError $error ) {
				$this->logger->warning(
					sprintf( 'Could not move the designs of order item %d to %s: %s', $item->get_id(), $ref, $error->getMessage() ),
					array(
						'order_item_id' => $item->get_id(),
						'code'          => $error->error_code,
					)
				);
				continue;
			}
			$state->save();
		}
	}
}
