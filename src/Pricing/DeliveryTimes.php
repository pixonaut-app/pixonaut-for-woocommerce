<?php
/**
 * How long a printshop says a blank variant takes to reach a shopper.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Pricing;

/**
 * The `times` of a blank variant's cost, as
 * `GET /v1/blanks/{id}/variants/costs` answers them: up to three spans of
 * whole calendar days by the printshop's cheapest shipping, each from a
 * shortest to a longest. A printshop answers the spans it knows, and one it
 * does not answer is null: making it (order to dispatch), in the post
 * (dispatch to door), and the whole way (order to door).
 *
 * A span is a list of its shortest and longest day count.
 */
final class DeliveryTimes {

	/**
	 * The most days a span holds: ten years. A longer one is no time a
	 * shopper is told, and reads as none, so day counts stay whole numbers
	 * when spans and a store's handling days are added.
	 */
	public const MAX_DAYS = 3650;

	/**
	 * @param array{0: int, 1: int}|null $production Making it, from the order to its dispatch.
	 * @param array{0: int, 1: int}|null $shipping   In the post, from its dispatch to the door.
	 * @param array{0: int, 1: int}|null $delivery   The whole way, from the order to the door, where the printshop answers that itself.
	 */
	public function __construct(
		public readonly ?array $production = null,
		public readonly ?array $shipping = null,
		public readonly ?array $delivery = null,
	) {
	}

	/**
	 * The times in a cost's `times`, as the answer or `to_array()` writes
	 * them; a span that is not two whole day counts, shortest first, reads
	 * as none, as does one past `MAX_DAYS`, and anything that is no `times`
	 * at all.
	 *
	 * @param mixed $data `{production, shipping, delivery}`, each `{minDays, maxDays}` or null.
	 */
	public static function from_array( mixed $data ): self {
		if ( ! is_array( $data ) ) {
			return new self();
		}
		return new self( self::span( $data['production'] ?? null ), self::span( $data['shipping'] ?? null ), self::span( $data['delivery'] ?? null ) );
	}

	/**
	 * Whether the printshop answered no span at all.
	 */
	public function none(): bool {
		return null === $this->production && null === $this->shipping && null === $this->delivery;
	}

	/**
	 * The days from the order to the door: the span the printshop answers
	 * for the whole way, else its making and its post together when it
	 * answers both. Null when it answers neither: a part alone is not the
	 * whole way.
	 *
	 * @return array{0: int, 1: int}|null
	 */
	public function to_door(): ?array {
		if ( null !== $this->delivery ) {
			return $this->delivery;
		}
		if ( null === $this->production || null === $this->shipping ) {
			return null;
		}
		return array( $this->production[0] + $this->shipping[0], $this->production[1] + $this->shipping[1] );
	}

	/**
	 * @return array{production: array{minDays: int, maxDays: int}|null, shipping: array{minDays: int, maxDays: int}|null, delivery: array{minDays: int, maxDays: int}|null}
	 */
	public function to_array(): array {
		$span = static fn ( ?array $days ): ?array => null === $days ? null : array(
			'minDays' => $days[0],
			'maxDays' => $days[1],
		);
		return array(
			'production' => $span( $this->production ),
			'shipping'   => $span( $this->shipping ),
			'delivery'   => $span( $this->delivery ),
		);
	}

	/**
	 * @param mixed $span `{minDays, maxDays}`, or null.
	 * @return array{0: int, 1: int}|null
	 */
	private static function span( mixed $span ): ?array {
		$min = is_array( $span ) ? ( $span['minDays'] ?? null ) : null;
		$max = is_array( $span ) ? ( $span['maxDays'] ?? null ) : null;
		return is_int( $min ) && is_int( $max ) && $min >= 0 && $max >= $min && $max <= self::MAX_DAYS ? array( $min, $max ) : null;
	}
}
