<?php
/**
 * Where a configuration that cannot be sold went wrong.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

/**
 * What an `InvalidReason` code alone cannot say: the side it is about, the
 * print size refused, and the code Pixonaut answered when it refused a
 * request, so the store manager reads where the problem is and what
 * Pixonaut said. Each part is optional.
 */
final class InvalidDetail {

	/**
	 * @param string|null $side The side's label, as the product screen shows it.
	 * @param string|null $size The print size's label, e.g. "300 × 400 mm".
	 * @param string|null $code The error code Pixonaut answered, when a request was refused.
	 * @param bool        $own  Whether it is about what a variation sets itself, in its Pixonaut fields, rather than what it follows of its product.
	 */
	public function __construct(
		public readonly ?string $side = null,
		public readonly ?string $size = null,
		public readonly ?string $code = null,
		public readonly bool $own = false,
	) {
	}

	/**
	 * A detail naming nothing is no detail.
	 *
	 * @param string|null $side The side's label.
	 * @param string|null $size The print size's label.
	 * @param string|null $code The error code Pixonaut answered.
	 * @param bool        $own  Whether it is about what a variation sets itself.
	 */
	public static function of( ?string $side = null, ?string $size = null, ?string $code = null, bool $own = false ): ?self {
		$side = '' === $side ? null : $side;
		$size = '' === $size ? null : $size;
		$code = '' === $code ? null : $code;
		return null === $side && null === $size && null === $code && ! $own ? null : new self( $side, $size, $code, $own );
	}

	/**
	 * This detail with the side named, unless it names one already.
	 *
	 * @param string|null $side The side's label.
	 */
	public function on_side( ?string $side ): self {
		return null === $this->side && null !== $side && '' !== $side ? new self( $side, $this->size, $this->code, $this->own ) : $this;
	}

	/**
	 * Where on the product the problem is, as the store manager reads it;
	 * empty when neither a side nor a size is named.
	 */
	public function where(): string {
		if ( null === $this->side ) {
			return null === $this->size ? '' : sprintf(
				/* translators: %s: a print size, e.g. "300 × 400 mm". Starts a message to the merchant saying what is wrong with that print size of a personalised product. */
				__( 'At %s', 'pixonaut-for-woocommerce' ),
				$this->size
			);
		}
		if ( null === $this->size ) {
			return sprintf(
				/* translators: %s: a side's label, e.g. "Front". Starts a message to the merchant saying what is wrong on that side of a personalised product. */
				__( 'Side “%s”', 'pixonaut-for-woocommerce' ),
				$this->side
			);
		}
		return sprintf(
			/* translators: 1: a side's label, e.g. "Front", 2: a print size, e.g. "300 × 400 mm". Starts a message to the merchant saying what is wrong with that print size on that side of a personalised product. */
			__( 'Side “%1$s” at %2$s', 'pixonaut-for-woocommerce' ),
			$this->side,
			$this->size
		);
	}

	/**
	 * What is stored of it: a variation's own sizes are judged when it is
	 * read, so `$own` is never stored.
	 *
	 * @return array{side?: string, size?: string, code?: string}
	 */
	public function to_array(): array {
		return array_filter(
			array(
				'side' => $this->side,
				'size' => $this->size,
				'code' => $this->code,
			),
			static fn ( ?string $part ): bool => null !== $part
		);
	}

	/**
	 * @param mixed $data As `to_array()` stored it.
	 */
	public static function from_array( mixed $data ): ?self {
		if ( ! is_array( $data ) ) {
			return null;
		}
		$text = static fn ( mixed $value ): ?string => is_string( $value ) ? $value : null;
		return self::of( $text( $data['side'] ?? null ), $text( $data['size'] ?? null ), $text( $data['code'] ?? null ) );
	}
}
