<?php
/**
 * Puts a personalised order line back in the cart on "Order again".
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Cart;

use Closure;
use Pixonaut\WooCommerce\Api\Designs;
use Pixonaut\WooCommerce\ApprovalMode;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Labels;
use Pixonaut\WooCommerce\Milliseconds;
use Pixonaut\WooCommerce\Refs;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\ErrorCode;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\Model\DesignsExternalRefUpdateRequest;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Pixonaut as Sdk;
use Pixonaut\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use WC_Order_Factory;
use WC_Order_Item_Product;
use WC_Product;

/**
 * WooCommerce's "Order again" rebuilds the cart from an order's items
 * without the add-to-cart form: it asks for each item's cart data, checks
 * the item through `woocommerce_add_to_cart_validation`, then puts it in
 * the cart directly. A personalised order line's designs belong to that
 * order, so the line comes back with copies: each side's design is
 * duplicated in Pixonaut, checked against the product as it sells now, and
 * moved to the new cart line's ref. The earlier order's designs are left as
 * they were.
 *
 * The cart data names the order item (ITEM_KEY), so every reordered line is
 * a cart line of its own; `AddToCart` makes its line at validation, after
 * WooCommerce's own stock checks, and the line joins its cart item here.
 * WooCommerce skips `woocommerce_add_cart_item_data` on this path, so a
 * line of standing sides alone, which copies nothing, joins it here too.
 * Copies made for a line that is refused, or that WooCommerce then leaves
 * out, are deleted through `CartCleanup`'s jobs, which retry an outage.
 */
final class OrderAgain {

	/**
	 * Key of the reordered order item's id in the cart item data.
	 */
	public const ITEM_KEY = 'pixonaut_order_again';

	private readonly Closure $clock;

	/**
	 * Lines made at validation, by order item id, waiting for their cart item.
	 *
	 * @var array<int, CartLine>
	 */
	private array $pending = array();

	/**
	 * @param Sdk             $pixonaut Pixonaut's designs.
	 * @param CartCleanup     $cleanup Deletes the copies made for a line that does not join the cart.
	 * @param LoggerInterface $logger  Logs copies that could not be made or were refused.
	 * @param Closure|null    $clock   `fn(): int` Unix time; `time()` when null.
	 */
	public function __construct(
		private readonly Sdk $pixonaut,
		private readonly CartCleanup $cleanup,
		private readonly LoggerInterface $logger,
		?Closure $clock = null,
	) {
		$this->clock = $clock ?? static fn (): int => time();
	}

	public function register(): void {
		add_filter( 'woocommerce_order_again_cart_item_data', array( $this, 'cart_item_data' ), 10, 2 );
		add_filter( 'woocommerce_add_order_again_cart_item', array( $this, 'cart_item' ) );
		add_action( 'woocommerce_ordered_again', array( $this, 'ordered_again' ) );
	}

	/**
	 * Names a personalised order line in its cart item data.
	 *
	 * @param mixed $data The cart item data.
	 * @param mixed $item The order item.
	 *
	 * @return array<mixed>
	 */
	public function cart_item_data( mixed $data, mixed $item ): array {
		$data = is_array( $data ) ? $data : array();
		if ( $item instanceof WC_Order_Item_Product && LineState::is_tracked( $item ) ) {
			$data[ self::ITEM_KEY ] = $item->get_id();
		}
		return $data;
	}

	/**
	 * The order item a cart item data names, when the customer may order it again.
	 *
	 * @param mixed $cart_item_data Cart item data.
	 */
	public static function item_in( mixed $cart_item_data ): ?WC_Order_Item_Product {
		$item_id = is_array( $cart_item_data ) ? ( $cart_item_data[ self::ITEM_KEY ] ?? null ) : null;
		$item    = is_int( $item_id ) ? WC_Order_Factory::get_order_item( $item_id ) : null;
		if ( ! $item instanceof WC_Order_Item_Product || ! LineState::is_tracked( $item ) ) {
			return null;
		}
		// WooCommerce's own check before it orders anything again.
		return current_user_can( 'order_again', $item->get_order_id() ) ? $item : null; // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce's meta capability.
	}

	/**
	 * Makes the cart line of a reordered order line: a copy of each
	 * personalised side's design, checked against the product's side now.
	 *
	 * @param WC_Order_Item_Product $item       The order line.
	 * @param WC_Product            $product    The product or variation it sells.
	 * @param ProductConfig         $config     Its configuration now.
	 * @param string                $mode       Its approval mode now.
	 * @param string|null           $variant    The blank variant it sells now.
	 * @param StandingSide[]        $standing   Its standing sides now.
	 * @param StoredConnection      $connection The store's connection.
	 * @param string                $owner      Who the cart belongs to.
	 * @phpstan-param list<StandingSide> $standing
	 *
	 * @throws AddRefused When the line cannot join the cart.
	 */
	public function line( WC_Order_Item_Product $item, WC_Product $product, ProductConfig $config, string $mode, ?string $variant, array $standing, StoredConnection $connection, string $owner ): CartLine {
		$state = LineState::of( $item );
		if ( $state->ref( LineState::REF_TENANT_ID ) !== $connection->tenant_id ) {
			throw new AddRefused( Labels::DESIGN_REQUIRED );
		}
		$sources = array();
		foreach ( $config->personalised_sides() as $side ) {
			$source = in_array( $side->key, $state->sides(), true ) && ! $state->is_side_erased( $side->key ) ? $state->side_ref( $side->key, LineState::SIDE_DESIGN_ID ) : null;
			if ( ! is_string( $source ) || '' === $source ) {
				// The product has a side the order line was not designed for, or its design was erased.
				throw new AddRefused( Labels::DESIGN_REQUIRED );
			}
			$sources[ $side->key ] = $source;
		}

		$copies = array();
		$sides  = array();
		try {
			foreach ( $config->personalised_sides() as $side ) {
				$copy                 = $this->copy( $product, $sources[ $side->key ] );
				$copies[ $side->key ] = $copy;
				$design               = $this->pixonaut->designs()->get( $copy )->raw();
				$mismatch             = DesignCheck::mismatch( $design, $side, $config->blank_id );
				if ( null !== $mismatch ) {
					$this->logger->warning(
						sprintf( 'Refused to order product %d again: the copy of its design for side "%s" does not match the product\'s %s.', $product->get_id(), $side->key, $mismatch ),
						array(
							'order_item_id' => $item->get_id(),
							'design_id'     => $copy,
							'mismatch'      => $mismatch,
						)
					);
					throw new AddRefused( DesignCheck::TEMPLATE_OUTDATED === $mismatch ? Labels::DESIGN_REQUIRED : Labels::DESIGN_MISMATCH );
				}
				$commit_key = $state->side_ref( $side->key, LineState::SIDE_COMMIT_KEY );
				$sides[]    = new CommittedSide(
					$side->key,
					$copy,
					is_int( $design['revision'] ?? null ) ? $design['revision'] : 0,
					$side->template_base_id,
					(int) $design[ Designs::TEMPLATE_VERSION ],
					// The copy was made by no commit: the line keeps the key that committed its source.
					is_string( $commit_key ) && '' !== $commit_key ? $commit_key : Refs::commit_key( $connection->site, $copy ),
					is_string( $design[ Designs::PROOF_ID ] ?? null ) && '' !== $design[ Designs::PROOF_ID ] ? $design[ Designs::PROOF_ID ] : null,
					$state->side_fields( $side->key )
				);
			}
			$line_key = Refs::line_key( $copies, $owner );
			$ref      = Refs::cart_ref( $connection->site, $line_key );
			foreach ( $copies as $copy ) {
				$this->pixonaut->designs()->updateExternalRef( $copy, new DesignsExternalRefUpdateRequest( externalRef: $ref ) );
			}
		} catch ( ApiException $error ) {
			$this->logger->warning( sprintf( 'Could not copy the designs of order item %d to order it again: %s', $item->get_id(), $error->getMessage() ), array( 'code' => $error->errorCode ) );
			$this->discard( $copies, $connection->tenant_id );
			throw new AddRefused( Labels::DESIGN_NOT_SAVED );
		} catch ( AddRefused $refused ) {
			$this->discard( $copies, $connection->tenant_id );
			throw $refused;
		}

		$consent = ApprovalMode::consent_in_cart( $mode, Milliseconds::of( ( $this->clock )() ) );
		$line    = new CartLine( $line_key, $ref, $connection->tenant_id, $mode, $consent, $sides, $variant, $standing );

		$this->hold( $item, $line );
		return $line;
	}

	/**
	 * Keeps a reordered order line's cart line until its cart item is built.
	 *
	 * @param WC_Order_Item_Product $item The order line.
	 * @param CartLine              $line Its cart line.
	 */
	public function hold( WC_Order_Item_Product $item, CartLine $line ): void {
		$this->pending[ $item->get_id() ] = $line;
	}

	/**
	 * Gives a reordered cart item its line.
	 *
	 * @param mixed $cart_item The cart item WooCommerce built from the order item.
	 *
	 * @return mixed
	 */
	public function cart_item( mixed $cart_item ): mixed {
		$item_id = is_array( $cart_item ) ? ( $cart_item[ self::ITEM_KEY ] ?? null ) : null;
		if ( ! is_array( $cart_item ) || ! is_int( $item_id ) || ! isset( $this->pending[ $item_id ] ) ) {
			return $cart_item;
		}
		$line = $this->pending[ $item_id ];
		unset( $this->pending[ $item_id ], $cart_item[ self::ITEM_KEY ] );
		$product_id                 = (int) ( $cart_item['product_id'] ?? 0 );
		$variation_id               = (int) ( $cart_item['variation_id'] ?? 0 );
		$cart_item[ CartLine::KEY ] = AddToCart::line_data( $line, $product_id, $variation_id );
		return $cart_item;
	}

	/**
	 * Deletes the copies made for lines WooCommerce left out of the cart
	 * after they passed validation.
	 */
	public function ordered_again(): void {
		foreach ( $this->pending as $line ) {
			$this->discard( array_map( static fn ( CommittedSide $side ): string => $side->design_id, $line->sides ), $line->tenant_id );
		}
		$this->pending = array();
	}

	/**
	 * @param WC_Product $product   The product, for the log.
	 * @param string     $design_id The order line's design.
	 *
	 * @throws AddRefused When the design is gone or erased, so there is nothing to copy.
	 * @throws ApiException When Pixonaut could not be reached, or refused otherwise.
	 */
	private function copy( WC_Product $product, string $design_id ): string {
		try {
			return $this->pixonaut->designs()->duplicate( $design_id )->designId;
		} catch ( ApiException $error ) {
			if ( in_array( $error->errorCode, array( ErrorCode::NOT_FOUND, ErrorCode::DESIGN_ERASED ), true ) ) {
				$this->logger->warning( sprintf( 'Could not order product %d again: its design %s is %s.', $product->get_id(), $design_id, $error->errorCode ), array( 'code' => $error->errorCode ) );
				throw new AddRefused( Labels::DESIGN_REQUIRED );
			}
			throw $error;
		}
	}

	/**
	 * Schedules the deletion of copies made for a line that does not join
	 * the cart, so an outage does not leave them behind.
	 *
	 * @param array<string> $design_ids The copies.
	 * @param string        $tenant_id  The workspace they were made in.
	 */
	private function discard( array $design_ids, string $tenant_id ): void {
		foreach ( $design_ids as $design_id ) {
			$this->cleanup->discard( $design_id, $tenant_id );
		}
	}
}
