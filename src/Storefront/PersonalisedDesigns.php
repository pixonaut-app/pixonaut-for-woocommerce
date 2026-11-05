<?php
/**
 * The designs a customer brought back from the hosted designer, or opened from a cart line.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use Pixonaut\WooCommerce\Cart\CartCleanup;
use Pixonaut\WooCommerce\Cart\CartLine;
use WC_Product;
use WC_Product_Variation;

/**
 * A design approved on the hosted designer is committed as the customer comes
 * back, which is what lets Pixonaut draw it. It is kept in the customer's
 * WooCommerce session under a random key, which only the address of the page
 * the return lands on names, so a plain visit to the product never finds it, and
 * an address opened in another session finds nothing and shows nobody a photo.
 * It keeps its line's data under `CartLine::KEY`, as a cart item does, so
 * `CartCleanup` finds it in an expired session the same way.
 *
 * Forgetting a design no cart line holds deletes it from Pixonaut. A line
 * leaving the cart, removed or dropped by WooCommerce as its session loads
 * (the product can no longer be bought, or its data changed), forgets its
 * design too, and `CartCleanup` deals with the design.
 *
 * @phpstan-type Personalised array{key: string, product_id: int, variation_id: int, quantity: int, attributes: array<string, string>, line: CartLine}
 */
final class PersonalisedDesigns {

	/**
	 * The session key holding the designs, by product and variation.
	 */
	public const SESSION_KEY = 'pixonaut_personalised_designs';

	/**
	 * The query argument naming the design a product page shows.
	 */
	public const ARG = 'pixonaut_design';

	/**
	 * How many random bytes a design's key takes.
	 */
	private const KEY_BYTES = 16;

	/**
	 * Designs one session keeps; another forgets the oldest.
	 */
	public const MAX_KEPT = 10;

	/**
	 * @param CartCleanup $cleanup Deletes designs no line holds.
	 */
	public function __construct( private readonly CartCleanup $cleanup ) {
	}

	public function register(): void {
		add_action( 'woocommerce_remove_cart_item', array( $this, 'line_removed' ), 10, 2 );
		add_action( 'woocommerce_remove_cart_item_from_session', array( $this, 'line_dropped' ), 10, 2 );
		add_action( 'woocommerce_before_cart_emptied', array( $this, 'cart_emptied' ) );
	}

	/**
	 * What says which product and variation a design belongs to on the page,
	 * so the storefront script shows it with its variation.
	 *
	 * @param int $product_id   The product (the parent of a variation).
	 * @param int $variation_id The variation, or 0.
	 */
	public static function page_key( int $product_id, int $variation_id ): string {
		return $product_id . ':' . $variation_id;
	}

	/**
	 * Keeps a design, under the key already holding its line or a new one.
	 *
	 * @param int                   $product_id   The product (the parent of a variation).
	 * @param int                   $variation_id The variation, or 0.
	 * @param CartLine              $line         The line it joins the cart as.
	 * @param int                   $quantity     How many the product page offers to add.
	 * @param array<string, string> $attributes   The chosen `attribute_*` values.
	 *
	 * @return string|null Its key; null without a session.
	 */
	public function keep( int $product_id, int $variation_id, CartLine $line, int $quantity = 1, array $attributes = array() ): ?string {
		$session = WC()->session;
		// Typed as always set, but null until WooCommerce starts a session.
		if ( null === $session ) { // @phpstan-ignore identical.alwaysFalse
			return null;
		}
		DesignerVisits::persist( $session );
		$stored = self::stored();
		$key    = self::key_holding( $stored, $line ) ?? bin2hex( random_bytes( self::KEY_BYTES ) );
		unset( $stored[ $key ] );
		$stored[ $key ] = array(
			'product_id'   => $product_id,
			'variation_id' => $variation_id,
			'quantity'     => max( 1, $quantity ),
			'attributes'   => $attributes,
			CartLine::KEY  => $line->to_array(),
		);
		foreach ( array_slice( $stored, 0, -self::MAX_KEPT, true ) as $oldest => $item ) {
			$evicted = CartLine::of( $item );
			if ( null !== $evicted ) {
				$this->discard( $evicted, $line );
			}
			unset( $stored[ $oldest ] );
		}
		$session->set( self::SESSION_KEY, $stored );
		return $key;
	}

	/**
	 * The key of the kept design holding a line's first design, if any.
	 *
	 * @param array<string, array<string, mixed>> $stored The kept designs.
	 * @param CartLine                            $line   The line.
	 */
	private static function key_holding( array $stored, CartLine $line ): ?string {
		$side = $line->sides[0] ?? null;
		if ( null === $side ) {
			return null;
		}
		foreach ( $stored as $key => $item ) {
			if ( CartLine::of( $item )?->holds( $side->design_id ) ) {
				return $key;
			}
		}
		return null;
	}

	/**
	 * A kept design.
	 *
	 * @param string $key Its key.
	 *
	 * @return array<string, mixed>|null
	 * @phpstan-return Personalised|null
	 */
	public function get( string $key ): ?array {
		$item = self::stored()[ $key ] ?? null;
		return null === $item ? null : self::read( $key, $item );
	}

	/**
	 * Puts a kept design's line in its place, once it was corrected.
	 *
	 * @param string   $key  Its key.
	 * @param CartLine $line The line now.
	 */
	public function update( string $key, CartLine $line ): void {
		$stored = self::stored();
		if ( isset( $stored[ $key ] ) ) {
			$stored[ $key ][ CartLine::KEY ] = $line->to_array();
			WC()->session->set( self::SESSION_KEY, $stored );
		}
	}

	/**
	 * Forgets a design, and deletes it unless a cart line holds it.
	 *
	 * @param string $key Its key.
	 */
	public function forget( string $key ): void {
		$stored = self::stored();
		$line   = isset( $stored[ $key ] ) ? CartLine::of( $stored[ $key ] ) : null;
		if ( null === $line ) {
			return;
		}
		$this->discard( $line );
		unset( $stored[ $key ] );
		WC()->session->set( self::SESSION_KEY, $stored );
	}

	/**
	 * Deletes copies of designs that joined no line.
	 *
	 * @param string[] $design_ids The copies.
	 * @param string   $tenant_id  The workspace they were made in.
	 */
	public function discard_copies( array $design_ids, string $tenant_id ): void {
		foreach ( $design_ids as $design_id ) {
			$this->cleanup->discard( $design_id, $tenant_id );
		}
	}

	/**
	 * The key of the customer's cart line holding a line's first design, if any.
	 *
	 * @param CartLine $line The line.
	 */
	public static function cart_key_of( CartLine $line ): ?string {
		$cart = WC()->cart;
		$side = $line->sides[0] ?? null;
		// Typed as always set, but null until WooCommerce loads the cart.
		if ( null === $cart || null === $side ) { // @phpstan-ignore identical.alwaysFalse
			return null;
		}
		foreach ( $cart->get_cart() as $key => $item ) {
			if ( CartLine::of( $item )?->holds( $side->design_id ) ) {
				return (string) $key;
			}
		}
		return null;
	}

	/**
	 * The product page showing a design, with its chosen options selected.
	 *
	 * @param array<string, mixed> $design The design.
	 * @phpstan-param Personalised $design
	 */
	public static function page_url( array $design ): string {
		$product = wc_get_product( $design['variation_id'] > 0 ? $design['variation_id'] : $design['product_id'] );
		if ( ! $product instanceof WC_Product ) {
			return home_url( '/' );
		}
		$page = $product instanceof WC_Product_Variation
			? $product->get_permalink( array( 'variation' => $design['attributes'] ) )
			: $product->get_permalink();
		return add_query_arg( self::ARG, rawurlencode( $design['key'] ), $page );
	}

	/**
	 * The lines of the designs an expired session kept.
	 *
	 * @param mixed $stored The session's stored value, serialized.
	 *
	 * @return list<CartLine>
	 */
	public static function lines_in( mixed $stored ): array {
		$items = maybe_unserialize( is_string( $stored ) ? $stored : '' );
		$lines = array();
		foreach ( is_array( $items ) ? $items : array() as $item ) {
			$line = is_array( $item ) ? CartLine::of( $item ) : null;
			if ( null !== $line ) {
				$lines[] = $line;
			}
		}
		return $lines;
	}

	/**
	 * A line left the cart: its design is no longer kept.
	 *
	 * @param mixed $cart_item_key The cart item's key.
	 * @param mixed $cart          The cart, still holding the item.
	 */
	public function line_removed( mixed $cart_item_key, mixed $cart ): void {
		$item = is_object( $cart ) && is_string( $cart_item_key ) ? ( $cart->cart_contents[ $cart_item_key ] ?? null ) : null;
		if ( is_array( $item ) ) {
			$this->drop_holding( array( $item ) );
		}
	}

	/**
	 * WooCommerce dropped a line as the cart's session loaded: its design is
	 * no longer kept.
	 *
	 * @param mixed $cart_item_key The cart item's key; unused.
	 * @param mixed $values        The cart item.
	 */
	public function line_dropped( mixed $cart_item_key, mixed $values ): void {
		unset( $cart_item_key );
		$this->drop_holding( array( $values ) );
	}

	/**
	 * The cart was emptied, by an order or otherwise: its lines' designs are
	 * no longer kept. Those in no line stay.
	 */
	public function cart_emptied(): void {
		$cart = WC()->cart;
		// Typed as always set, but null until WooCommerce loads the cart.
		if ( null !== $cart ) { // @phpstan-ignore notIdentical.alwaysTrue
			$this->drop_holding( $cart->get_cart_contents() );
		}
	}

	/**
	 * Forgets the designs cart lines hold, without deleting anything.
	 *
	 * @param array<mixed> $items Cart items.
	 */
	private function drop_holding( array $items ): void {
		$stored = self::stored();
		$before = count( $stored );
		foreach ( $items as $item ) {
			$held = is_array( $item ) ? CartLine::of( $item ) : null;
			if ( null === $held ) {
				continue;
			}
			foreach ( $stored as $key => $kept ) {
				$side = CartLine::of( $kept )?->sides[0] ?? null;
				if ( null !== $side && $held->holds( $side->design_id ) ) {
					unset( $stored[ $key ] );
				}
			}
		}
		if ( count( $stored ) !== $before ) {
			WC()->session->set( self::SESSION_KEY, $stored );
		}
	}

	/**
	 * Deletes a line's designs unless the cart or a line kept in its place holds them.
	 *
	 * @param CartLine      $line The line.
	 * @param CartLine|null $kept The line kept in its place.
	 */
	private function discard( CartLine $line, ?CartLine $kept = null ): void {
		if ( null !== self::cart_key_of( $line ) ) {
			return;
		}
		foreach ( $line->sides as $side ) {
			if ( null === $kept || ! $kept->holds( $side->design_id ) ) {
				$this->cleanup->discard( $side->design_id, $line->tenant_id );
			}
		}
	}

	/**
	 * The session's stored designs, by key.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function stored(): array {
		$session = WC()->session;
		// Typed as always set, but null until WooCommerce starts a session.
		$stored = null === $session ? null : $session->get( self::SESSION_KEY ); // @phpstan-ignore identical.alwaysFalse
		$items  = array();
		foreach ( is_array( $stored ) ? $stored : array() as $key => $item ) {
			if ( is_string( $key ) && is_array( $item ) ) {
				$items[ $key ] = $item;
			}
		}
		return $items;
	}

	/**
	 * @param string               $key  Its key.
	 * @param array<string, mixed> $item A stored design.
	 *
	 * @return array<string, mixed>|null Null when its line is unreadable.
	 * @phpstan-return Personalised|null
	 */
	private static function read( string $key, array $item ): ?array {
		$line = CartLine::of( $item );
		if ( null === $line ) {
			return null;
		}
		$attributes = array();
		foreach ( is_array( $item['attributes'] ?? null ) ? $item['attributes'] : array() as $name => $value ) {
			if ( is_string( $name ) && is_string( $value ) ) {
				$attributes[ $name ] = $value;
			}
		}
		return array(
			'key'          => $key,
			'product_id'   => (int) ( $item['product_id'] ?? 0 ),
			'variation_id' => (int) ( $item['variation_id'] ?? 0 ),
			'quantity'     => max( 1, (int) ( $item['quantity'] ?? 1 ) ),
			'attributes'   => $attributes,
			'line'         => $line,
		);
	}
}
