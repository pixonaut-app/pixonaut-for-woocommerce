<?php
/**
 * One standing side of a cart line.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Cart;

use Closure;

/**
 * A side printed from the store's standing design rather than one the
 * customer committed: the line names the design, which no cart or order
 * holds as its own, so nothing moves, approves or deletes it.
 */
final class StandingSide {

	/**
	 * @param string $key       The side's key in the product configuration.
	 * @param string $design_id The standing design.
	 * @param int    $position  Where the side is among all the product's sides, from 0.
	 */
	public function __construct(
		public readonly string $key,
		public readonly string $design_id,
		public readonly int $position,
	) {
	}

	/**
	 * @return array{key: string, designId: string, position: int}
	 */
	public function to_array(): array {
		return array(
			'key'      => $this->key,
			'designId' => $this->design_id,
			'position' => $this->position,
		);
	}

	/**
	 * @param mixed $data Shape of to_array().
	 * @return self|null Null when a field is missing or of the wrong type.
	 */
	public static function from_array( mixed $data ): ?self {
		if ( ! is_array( $data ) || ! is_string( $data['key'] ?? null ) || '' === $data['key'] || ! is_string( $data['designId'] ?? null ) || '' === $data['designId'] || ! is_int( $data['position'] ?? null ) || $data['position'] < 0 ) {
			return null;
		}
		return new self( $data['key'], $data['designId'], $data['position'] );
	}

	/**
	 * A line's sides in the product's side order: its committed ones, in
	 * their order, with each standing one at its position.
	 *
	 * @template T
	 *
	 * @param array          $committed The committed sides, in order, as `$as_side` makes a standing one.
	 * @param StandingSide[] $standing  The standing sides.
	 * @param Closure        $as_side   `fn( string $design_id ): T`, a standing side as the committed ones are given.
	 * @phpstan-param list<T> $committed
	 * @phpstan-param list<StandingSide> $standing
	 * @phpstan-param Closure(string): T $as_side
	 * @return list<T>
	 */
	public static function in_side_order( array $committed, array $standing, Closure $as_side ): array {
		usort( $standing, static fn ( self $a, self $b ): int => $a->position <=> $b->position );
		foreach ( $standing as $side ) {
			array_splice( $committed, min( $side->position, count( $committed ) ), 0, array( $as_side( $side->design_id ) ) );
		}
		return $committed;
	}
}
