<?php
/**
 * Customers' trips to the hosted designer.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use Closure;
use WC_Session;
use WC_Session_Handler;

/**
 * A trip to the hosted designer is kept in the customer's WooCommerce
 * session under its product and variation, with the chosen attributes and
 * quantity it was opened for: every customer opens the product's same
 * designer link, which names the product and variation on the way back. The
 * return handler takes the visit back once; a second return or an expired
 * trip finds nothing. A return whose design could not be committed for a
 * reason that may pass is kept with its proof tokens to try again.
 *
 * The return address is the same for every trip to a product, so the
 * session remembers the proof tokens its latest returns carried: the same
 * return a second time adds nothing.
 *
 * Each trip also gets a random `state`, which the store adds to the hosted
 * designer's address when the customer leaves, and Pixonaut echoes on the
 * way back. A return carrying the state of the trip it takes is the
 * customer's own; any other return to the product may be someone else's. A
 * trip's state is used once: taking the visit, or keeping it for another
 * try, forgets it.
 *
 * A trip opened before links were kept per product is kept under a random
 * `state` the hosted designer carries back, which is still taken.
 *
 * @phpstan-type Visit array{product_id: int, variation_id: int, quantity: int, attributes: array<string, string>, tokens: array<string, string>|null, state: string|null, expires: int}
 */
final class DesignerVisits {

	/**
	 * The session key holding open visits, by key.
	 */
	public const SESSION_KEY = 'pixonaut_designer_visits';

	/**
	 * The session key holding the hashes of the proof tokens the session's
	 * latest returns carried.
	 */
	public const RETURNS_SESSION_KEY = 'pixonaut_designer_returns';

	/**
	 * How long a visit stays open: 24 hours.
	 */
	public const LIFETIME_SECONDS = 86400;

	/**
	 * Visits one session keeps open; opening another forgets the oldest.
	 */
	public const MAX_OPEN = 10;

	/**
	 * Characters in a trip's state: letters and digits, within the 1 to 200
	 * characters of `A–Z a–z 0–9 . _ ~ -` Pixonaut echoes, and too many to guess.
	 */
	private const STATE_LENGTH = 32;

	private readonly Closure $clock;

	/**
	 * @param Closure|null $clock `fn(): int` Unix time; `time()` when null.
	 */
	public function __construct( ?Closure $clock = null ) {
		$this->clock = $clock ?? static fn (): int => time();
	}

	/**
	 * Opens a visit in the customer's session, with a new state for the trip.
	 *
	 * @param int                   $product_id   The product (the parent of a variation).
	 * @param int                   $variation_id The variation, or 0.
	 * @param int                   $quantity     How many the product page offers to add on return.
	 * @param array<string, string> $attributes   The customer's chosen `attribute_*` values.
	 *
	 * @return string|null The trip's state; null without a session.
	 */
	public function open( int $product_id, int $variation_id, int $quantity, array $attributes = array() ): ?string {
		$session = WC()->session;
		// Typed as always set, but null until WooCommerce starts a session.
		if ( null === $session ) { // @phpstan-ignore identical.alwaysFalse
			return null;
		}
		self::persist( $session );
		$key    = self::key( $product_id, $variation_id );
		$visits = $this->open_visits();
		unset( $visits[ $key ] );
		$state          = wp_generate_password( self::STATE_LENGTH, false );
		$visits[ $key ] = array(
			'quantity'   => $quantity,
			'attributes' => $attributes,
			'state'      => $state,
		) + $this->unopened( $product_id, $variation_id );
		$session->set( self::SESSION_KEY, array_slice( $visits, -self::MAX_OPEN, null, true ) );
		return $state;
	}

	/**
	 * The key of the visit to a product: a new trip to it replaces the last.
	 *
	 * @param int $product_id   The product (the parent of a variation).
	 * @param int $variation_id The variation, or 0.
	 */
	public static function key( int $product_id, int $variation_id ): string {
		return $product_id . ':' . $variation_id;
	}

	/**
	 * Whether a return's state is that of the trip it took.
	 *
	 * @param array<string, mixed> $visit The visit as `take()` gave it.
	 * @param string               $state The state the hosted designer carried back; empty when none.
	 * @phpstan-param Visit $visit
	 */
	public static function own_trip( array $visit, string $state ): bool {
		return null !== $visit['state'] && '' !== $state && hash_equals( $visit['state'], $state );
	}

	/**
	 * The visit of a return this session opened none for, in another
	 * browser or after the session ended: one of the product, with no
	 * attributes chosen and no trip's state.
	 *
	 * @param int $product_id   The product (the parent of a variation).
	 * @param int $variation_id The variation, or 0.
	 *
	 * @return array<string, mixed>
	 * @phpstan-return Visit
	 */
	public function unopened( int $product_id, int $variation_id ): array {
		return array(
			'product_id'   => $product_id,
			'variation_id' => $variation_id,
			'quantity'     => 1,
			'attributes'   => array(),
			'tokens'       => null,
			'state'        => null,
			'expires'      => ( $this->clock )() + self::LIFETIME_SECONDS,
		);
	}

	/**
	 * Puts a taken visit back with the proof tokens it returned, so adding
	 * them can be tried again until the visit expires. Its trip's state has
	 * been used, and no later return matches it.
	 *
	 * @param string                $key  Its key.
	 * @param array<string, mixed>  $visit  The visit as `take()` gave it.
	 * @param array<string, string> $tokens Proof token by side key.
	 * @phpstan-param Visit $visit
	 */
	public function keep( string $key, array $visit, array $tokens ): void {
		$session = WC()->session;
		// Typed as always set, but null until WooCommerce starts a session.
		if ( null === $session ) { // @phpstan-ignore identical.alwaysFalse
			return;
		}
		self::persist( $session );
		$visits          = $this->open_visits();
		$visit['tokens'] = $tokens;
		$visit['state']  = null;
		$visits[ $key ]  = $visit;
		$session->set( self::SESSION_KEY, array_slice( $visits, -self::MAX_OPEN, null, true ) );
	}

	/**
	 * The latest kept visit for a product whose designs could not be committed yet.
	 *
	 * @param int $product_id The product (the parent of a variation).
	 *
	 * @return string|null Its key.
	 */
	public function waiting_for( int $product_id ): ?string {
		// Typed as always set, but null until WooCommerce starts a session.
		if ( null === WC()->session ) {
			return null;
		}
		$waiting = null;
		foreach ( $this->open_visits() as $key => $visit ) {
			if ( $product_id === $visit['product_id'] && null !== $visit['tokens'] ) {
				$waiting = $key;
			}
		}
		return $waiting;
	}

	/**
	 * Whether this session sees a return's proof tokens for the first time;
	 * they are remembered either way.
	 *
	 * @param array<string, string> $tokens Proof token by side key.
	 */
	public function first_return( array $tokens ): bool {
		$session = WC()->session;
		// Typed as always set, but null until WooCommerce starts a session.
		if ( null === $session ) { // @phpstan-ignore identical.alwaysFalse
			return true;
		}
		self::persist( $session );
		ksort( $tokens );
		$hash    = hash( 'sha256', (string) wp_json_encode( $tokens ) );
		$stored  = $session->get( self::RETURNS_SESSION_KEY );
		$returns = is_array( $stored ) ? array_values( array_filter( $stored, 'is_string' ) ) : array();
		if ( in_array( $hash, $returns, true ) ) {
			return false;
		}
		$returns[] = $hash;
		$session->set( self::RETURNS_SESSION_KEY, array_slice( $returns, -self::MAX_OPEN ) );
		return true;
	}

	/**
	 * Takes the visit a key names out of the session.
	 *
	 * @param string $key The visit's key, or the state the hosted designer carried back.
	 *
	 * @return array<string, mixed>|null Null when this session has no such open visit.
	 * @phpstan-return Visit|null
	 */
	public function take( string $key ): ?array {
		$session = WC()->session;
		// Typed as always set, but null until WooCommerce starts a session.
		if ( null === $session || '' === $key ) { // @phpstan-ignore identical.alwaysFalse
			return null;
		}
		$visits = $this->open_visits();
		$visit  = $visits[ $key ] ?? null;
		unset( $visits[ $key ] );
		$session->set( self::SESSION_KEY, $visits );
		return $visit;
	}

	/**
	 * Keeps a guest's session past this request: WooCommerce stores it only
	 * once its cookie is set.
	 *
	 * @param WC_Session $session The customer's session.
	 */
	public static function persist( WC_Session $session ): void {
		if ( $session instanceof WC_Session_Handler && ! $session->has_session() ) {
			$session->set_customer_session_cookie( true );
		}
	}

	/**
	 * The session's visits that have not expired.
	 *
	 * @return array<string, array<string, mixed>>
	 * @phpstan-return array<string, Visit>
	 */
	private function open_visits(): array {
		$stored = WC()->session->get( self::SESSION_KEY );
		$now    = ( $this->clock )();
		$visits = array();
		foreach ( is_array( $stored ) ? $stored : array() as $key => $visit ) {
			if ( is_string( $key ) && is_array( $visit ) && is_int( $visit['expires'] ?? null ) && $visit['expires'] > $now ) {
				$visits[ $key ] = array(
					'product_id'   => (int) ( $visit['product_id'] ?? 0 ),
					'variation_id' => (int) ( $visit['variation_id'] ?? 0 ),
					'quantity'     => max( 1, (int) ( $visit['quantity'] ?? 1 ) ),
					'attributes'   => self::strings( $visit['attributes'] ?? null ),
					'tokens'       => is_array( $visit['tokens'] ?? null ) ? self::strings( $visit['tokens'] ) : null,
					'state'        => is_string( $visit['state'] ?? null ) ? $visit['state'] : null,
					'expires'      => $visit['expires'],
				);
			}
		}
		return $visits;
	}

	/**
	 * The string values of a stored map, by string key.
	 *
	 * @param mixed $stored A stored map.
	 * @return array<string, string>
	 */
	private static function strings( mixed $stored ): array {
		$strings = array();
		foreach ( is_array( $stored ) ? $stored : array() as $key => $value ) {
			if ( is_string( $key ) && is_string( $value ) ) {
				$strings[ $key ] = $value;
			}
		}
		return $strings;
	}
}
