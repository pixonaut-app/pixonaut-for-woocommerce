<?php
/**
 * Customers' trips to Pixonaut's hosted correction page from their cart.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Cart;

use Closure;
use WC_Session_Handler;

/**
 * Each trip to the hosted correction page gets a `state`: a random value
 * kept in the customer's WooCommerce session with the cart line and side it
 * corrects. The return handler takes it back once; a state from another
 * session, a second return or an expired trip finds nothing. A return whose
 * correction could be saved when tried again is kept with its proof token
 * until it is.
 *
 * @phpstan-type Trip array{key: string, side: string, token: string|null, expires: int}
 */
final class CorrectionTrips {

	/**
	 * The session key holding open trips, by state.
	 */
	public const SESSION_KEY = 'pixonaut_correction_trips';

	/**
	 * How long a trip stays open: as long as the correction link it went
	 * out with works, 2 hours.
	 */
	public const LIFETIME_SECONDS = 7200;

	/**
	 * Trips one session keeps open; opening another forgets the oldest.
	 */
	public const MAX_OPEN = 10;

	/**
	 * Characters in a state.
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
	 * Opens a trip in the customer's session.
	 *
	 * @param string $key  The cart item's key.
	 * @param string $side The side corrected.
	 *
	 * @return string|null Its state; null without a session.
	 */
	public function open( string $key, string $side ): ?string {
		$session = WC()->session;
		// Typed as always set, but null until WooCommerce starts a session.
		if ( null === $session ) { // @phpstan-ignore identical.alwaysFalse
			return null;
		}
		if ( $session instanceof WC_Session_Handler && ! $session->has_session() ) {
			// A guest's session is kept only once its cookie is set.
			$session->set_customer_session_cookie( true );
		}
		$state           = wp_generate_password( self::STATE_LENGTH, false );
		$trips           = $this->open_trips();
		$trips[ $state ] = array(
			'key'     => $key,
			'side'    => $side,
			'token'   => null,
			'expires' => ( $this->clock )() + self::LIFETIME_SECONDS,
		);
		$session->set( self::SESSION_KEY, array_slice( $trips, -self::MAX_OPEN, null, true ) );
		return $state;
	}

	/**
	 * Puts a taken trip back with the proof token it returned, so saving the
	 * correction can be tried again until the trip expires.
	 *
	 * @param string               $state Its state.
	 * @param array<string, mixed> $trip  The trip as `take()` gave it.
	 * @param string               $token The correction's proof token.
	 * @phpstan-param Trip $trip
	 */
	public function keep( string $state, array $trip, string $token ): void {
		$session = WC()->session;
		// Typed as always set, but null until WooCommerce starts a session.
		if ( null === $session ) { // @phpstan-ignore identical.alwaysFalse
			return;
		}
		$trips           = $this->open_trips();
		$trip['token']   = $token;
		$trips[ $state ] = $trip;
		$session->set( self::SESSION_KEY, array_slice( $trips, -self::MAX_OPEN, null, true ) );
	}

	/**
	 * The latest kept trip for a side of a cart line whose correction still
	 * waits to be saved.
	 *
	 * @param string $key  The cart item's key.
	 * @param string $side The side.
	 *
	 * @return string|null Its state.
	 */
	public function waiting_for( string $key, string $side ): ?string {
		// Typed as always set, but null until WooCommerce starts a session.
		if ( null === WC()->session ) {
			return null;
		}
		$waiting = null;
		foreach ( $this->open_trips() as $state => $trip ) {
			if ( $key === $trip['key'] && $side === $trip['side'] && null !== $trip['token'] ) {
				$waiting = $state;
			}
		}
		return $waiting;
	}

	/**
	 * Takes the trip a state names out of the session.
	 *
	 * @param string $state The state the hosted page carried back.
	 *
	 * @return array<string, mixed>|null Null when this session has no such open trip.
	 * @phpstan-return Trip|null
	 */
	public function take( string $state ): ?array {
		$session = WC()->session;
		// Typed as always set, but null until WooCommerce starts a session.
		if ( null === $session || '' === $state ) { // @phpstan-ignore identical.alwaysFalse
			return null;
		}
		$trips = $this->open_trips();
		$trip  = $trips[ $state ] ?? null;
		unset( $trips[ $state ] );
		$session->set( self::SESSION_KEY, $trips );
		return $trip;
	}

	/**
	 * The session's trips that have not expired.
	 *
	 * @return array<string, array<string, mixed>>
	 * @phpstan-return array<string, Trip>
	 */
	private function open_trips(): array {
		$stored = WC()->session->get( self::SESSION_KEY );
		$now    = ( $this->clock )();
		$trips  = array();
		foreach ( is_array( $stored ) ? $stored : array() as $state => $trip ) {
			if ( is_string( $state ) && is_array( $trip ) && is_string( $trip['key'] ?? null ) && is_string( $trip['side'] ?? null ) && is_int( $trip['expires'] ?? null ) && $trip['expires'] > $now ) {
				$trips[ $state ] = array(
					'key'     => $trip['key'],
					'side'    => $trip['side'],
					'token'   => is_string( $trip['token'] ?? null ) ? $trip['token'] : null,
					'expires' => $trip['expires'],
				);
			}
		}
		return $trips;
	}
}
