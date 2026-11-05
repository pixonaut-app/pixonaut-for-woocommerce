<?php
/**
 * The Pixonaut data of a personalised cart line.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Cart;

/**
 * Kept in the cart item's data under KEY, so it travels with the cart
 * session and reaches the order line at checkout. Its line key makes every
 * personalised item a cart line of its own; a line of standing sides alone
 * has the same key for the same designs, so adding the product again adds
 * to its quantity.
 */
final class CartLine {

	/**
	 * Key of the line's data in the cart item.
	 */
	public const KEY = 'pixonaut';

	public const SCHEMA_VERSION = 1;

	/**
	 * @param string                            $line_key      From `Refs::line_key()`.
	 * @param string                            $external_ref  The designs' `externalRef` while in the cart.
	 * @param string                            $tenant_id     The workspace the designs were committed to.
	 * @param string                            $approval_mode The product's approval mode when added.
	 * @param array{kind: string, at: int}|null $consent       The customer's consent, `at` in epoch milliseconds; null when the mode takes it later.
	 * @param CommittedSide[]                   $sides         The designs the customer committed, in the product's side order.
	 * @param string|null                       $variant       The ref of the blank variant the line's product sells; null when it is on no blank.
	 * @param StandingSide[]                    $standing      The sides printed from a standing design; a line of these alone commits nothing.
	 * @phpstan-param list<CommittedSide> $sides
	 * @phpstan-param list<StandingSide> $standing
	 */
	public function __construct(
		public readonly string $line_key,
		public readonly string $external_ref,
		public readonly string $tenant_id,
		public readonly string $approval_mode,
		public readonly ?array $consent,
		public readonly array $sides,
		public readonly ?string $variant = null,
		public readonly array $standing = array(),
	) {
	}

	/**
	 * The line in a cart item, or null when the item is not personalised or
	 * its data is unreadable.
	 *
	 * @param array<string, mixed> $cart_item A cart item.
	 */
	public static function of( array $cart_item ): ?self {
		return self::from_array( $cart_item[ self::KEY ] ?? null );
	}

	/**
	 * @param mixed $data Shape of to_array().
	 */
	public static function from_array( mixed $data ): ?self {
		if ( ! is_array( $data ) || self::SCHEMA_VERSION !== ( $data['v'] ?? null ) ) {
			return null;
		}
		foreach ( array( 'lineKey', 'externalRef', 'tenantId', 'approvalMode' ) as $text ) {
			if ( ! is_string( $data[ $text ] ?? null ) || '' === $data[ $text ] ) {
				return null;
			}
		}
		$consent = $data['consent'] ?? null;
		if ( null !== $consent && ! ( is_array( $consent ) && is_string( $consent['kind'] ?? null ) && is_int( $consent['at'] ?? null ) ) ) {
			return null;
		}
		$sides = array();
		foreach ( is_array( $data['sides'] ?? null ) ? $data['sides'] : array() as $side ) {
			$parsed = CommittedSide::from_array( $side );
			if ( null === $parsed ) {
				return null;
			}
			$sides[] = $parsed;
		}
		$standing = array();
		foreach ( is_array( $data['standing'] ?? null ) ? $data['standing'] : array() as $side ) {
			$parsed = StandingSide::from_array( $side );
			if ( null === $parsed ) {
				return null;
			}
			$standing[] = $parsed;
		}
		if ( array() === $sides && array() === $standing ) {
			return null;
		}
		return new self(
			$data['lineKey'],
			$data['externalRef'],
			$data['tenantId'],
			$data['approvalMode'],
			null === $consent ? null : array(
				'kind' => $consent['kind'],
				'at'   => $consent['at'],
			),
			$sides,
			is_string( $data['variant'] ?? null ) && '' !== $data['variant'] ? $data['variant'] : null,
			$standing
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'v'            => self::SCHEMA_VERSION,
			'lineKey'      => $this->line_key,
			'externalRef'  => $this->external_ref,
			'tenantId'     => $this->tenant_id,
			'approvalMode' => $this->approval_mode,
			'consent'      => $this->consent,
			'sides'        => array_map( static fn ( CommittedSide $side ): array => $side->to_array(), $this->sides ),
			'variant'      => $this->variant,
			'standing'     => array_map( static fn ( StandingSide $side ): array => $side->to_array(), $this->standing ),
		);
	}

	/**
	 * The line with one side replaced, matched by its key, and the consent
	 * given for it.
	 *
	 * @param CommittedSide                     $side    The side's new state.
	 * @param array{kind: string, at: int}|null $consent The customer's consent now.
	 */
	public function with_side( CommittedSide $side, ?array $consent ): self {
		return new self(
			$this->line_key,
			$this->external_ref,
			$this->tenant_id,
			$this->approval_mode,
			$consent,
			array_map( static fn ( CommittedSide $held ): CommittedSide => $held->key === $side->key ? $side : $held, $this->sides ),
			$this->variant,
			$this->standing
		);
	}

	/**
	 * The line under the approval mode and with the consent given now.
	 *
	 * @param string                            $mode    The product's approval mode now.
	 * @param array{kind: string, at: int}|null $consent The customer's consent now.
	 */
	public function with_approval( string $mode, ?array $consent ): self {
		return new self( $this->line_key, $this->external_ref, $this->tenant_id, $mode, $consent, $this->sides, $this->variant, $this->standing );
	}

	/**
	 * The side with a key, if the line has it.
	 *
	 * @param string $key The side's key.
	 */
	public function side( string $key ): ?CommittedSide {
		foreach ( $this->sides as $side ) {
			if ( $side->key === $key ) {
				return $side;
			}
		}
		return null;
	}

	/**
	 * Whether the line holds a design.
	 *
	 * @param string $design_id Design id.
	 */
	public function holds( string $design_id ): bool {
		foreach ( $this->sides as $side ) {
			if ( $side->design_id === $design_id ) {
				return true;
			}
		}
		return false;
	}
}
