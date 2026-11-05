<?php
/**
 * The references and idempotency keys the store sends Pixonaut.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce;

/**
 * Every ref and key starts `wc:<site>:`, `<site>` being the id minted when
 * the store connected, so two stores on one workspace, or one store before
 * and after a reconnect, never share one. A line keeps the refs and keys it
 * first used; these build new ones only.
 */
final class Refs {

	private const PREFIX = 'wc';

	/**
	 * Ends the key of a commit saying the preview was opened.
	 */
	private const PREVIEW_OPENED = 'opened';

	/**
	 * Ends the key of a commit saying the preview was not opened.
	 */
	private const PREVIEW_NOT_OPENED = 'unopened';

	private const HASH = 'sha256';

	/**
	 * The segment after `<site>` that marks a cart ref.
	 */
	private const CART = 'cart';

	/**
	 * Segments of a cart ref: the prefix, `<site>`, CART and the line key.
	 */
	private const CART_REF_SEGMENTS = 4;

	/**
	 * `Idempotency-Key` of a design commit: the same proof token always gets
	 * the same key, so a repeated add to cart replays the first commit. What
	 * the commit says of the preview is in the key too: a repeat that says
	 * otherwise is another body, which Pixonaut refuses under the same key,
	 * and under its own key finds the proof committed and adopts its design.
	 *
	 * @param string    $site           The connection's `<site>`.
	 * @param string    $proof_token    The proof token.
	 * @param bool|null $preview_opened Whether the commit says the preview was opened; null when it says nothing.
	 */
	public static function commit_key( string $site, string $proof_token, ?bool $preview_opened = null ): string {
		$key = self::join( $site, 'commit', hash( self::HASH, $proof_token ) );
		if ( null === $preview_opened ) {
			return $key;
		}
		return $key . ':' . ( $preview_opened ? self::PREVIEW_OPENED : self::PREVIEW_NOT_OPENED );
	}

	/**
	 * Key of a cart line, from its owner and its sides' proof tokens: the
	 * same designs in the same cart give the same key, so a line's ref, and
	 * with it its commit body, never changes when the add is repeated, while
	 * another cart sending the same tokens gets another ref.
	 *
	 * @param array<string, string> $proof_tokens Proof token by side key, in the product's side order.
	 * @param string                $owner        Who the cart belongs to, e.g. the session's customer id.
	 */
	public static function line_key( array $proof_tokens, string $owner ): string {
		$parts = array( $owner );
		foreach ( $proof_tokens as $side => $token ) {
			$parts[] = $side . '=' . $token;
		}
		return hash( self::HASH, implode( "\n", $parts ) );
	}

	/**
	 * `externalRef` of a design while it is in a cart.
	 *
	 * @param string $site     The connection's `<site>`.
	 * @param string $line_key From line_key().
	 */
	public static function cart_ref( string $site, string $line_key ): string {
		return self::join( $site, self::CART, $line_key );
	}

	/**
	 * Whether $ref is a cart ref, `wc:<site>:cart:<line key>`, of any `<site>`.
	 *
	 * @param string $ref An `externalRef`.
	 */
	public static function is_cart_ref( string $ref ): bool {
		$segments = explode( ':', $ref, self::CART_REF_SEGMENTS );
		return self::CART_REF_SEGMENTS === count( $segments )
			&& self::PREFIX === $segments[0]
			&& self::CART === $segments[2];
	}

	/**
	 * `externalRef` of a design once its line is in an order.
	 *
	 * @param string $site     The connection's `<site>`.
	 * @param int    $order_id Order id.
	 * @param int    $item_id  Order item id.
	 */
	public static function order_item_ref( string $site, int $order_id, int $item_id ): string {
		return self::join( $site, 'order', (string) $order_id, 'item', (string) $item_id );
	}

	/**
	 * `Idempotency-Key` of a line's printshop order.
	 *
	 * @param string $site    The connection's `<site>`.
	 * @param int    $item_id The order line.
	 */
	public static function order_key( string $site, int $item_id ): string {
		return self::join( $site, 'item', (string) $item_id );
	}

	/**
	 * `Idempotency-Key` of a line's printshop order placed again on purpose,
	 * for designs that have a live order already.
	 *
	 * @param string $site    The connection's `<site>`.
	 * @param int    $item_id The order line.
	 */
	public static function reorder_key( string $site, int $item_id ): string {
		return self::join( $site, 'item', (string) $item_id, 'reorder' );
	}

	/**
	 * `Idempotency-Key` of a change of a line's delivery address, one per
	 * change: a retry of the change sends it again, while a later change,
	 * even back to an earlier address, gets a key of its own.
	 *
	 * @param string $site    The connection's `<site>`.
	 * @param int    $item_id The order line.
	 * @param int    $change  The change's number on the line, from 1.
	 */
	public static function delivery_key( string $site, int $item_id, int $change ): string {
		return self::join( $site, 'item', (string) $item_id, 'delivery', (string) $change );
	}

	private static function join( string $site, string ...$parts ): string {
		return implode( ':', array( self::PREFIX, $site, ...$parts ) );
	}
}
