<?php
/**
 * What a product's or variation's price follows.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Pricing;

use Pixonaut\WooCommerce\Schema;
use WC_Product;

/**
 * Kept on each product and variation sold on a blank variant, as JSON in
 * its `_pixonaut_price` meta: the printshop cost last read for it, whether
 * its price follows the pricing rule, the price the rule last set and when,
 * and what the rule would change now.
 *
 * A price follows the rule from the moment the store manager applies the
 * rule to it. From then on, a regular price that is no longer the one the
 * rule set was edited by hand, and is kept until it is given back to the
 * rule.
 */
final class PriceState {

	public const META_KEY = Schema::PRICE_META;

	public const SCHEMA_VERSION = 1;

	/**
	 * The store manager never applied the rule to the price.
	 */
	public const OWN = 'own';

	/**
	 * The price is the one the rule set.
	 */
	public const FOLLOWS = 'follows';

	/**
	 * The price was edited by hand after the rule set it.
	 */
	public const BY_HAND = 'by_hand';

	/**
	 * The margin left the tolerance: the rule suggests another price.
	 */
	public const FLAG_MARGIN = 'margin';

	/**
	 * The printshop publishes no price for its variant.
	 */
	public const FLAG_NO_COST = 'no_cost';

	/**
	 * No cost was read for it: Pixonaut has not read its variant's yet, or
	 * it sells no blank variant.
	 */
	public const FLAG_UNREAD = 'unread';

	/**
	 * The cost is in a currency no exchange rate is known for.
	 */
	public const FLAG_CURRENCY = 'currency';

	/**
	 * The cost is in another currency, and the exchange rates are over a
	 * week old.
	 */
	public const FLAG_RATE_STALE = 'rate_stale';

	/**
	 * No price step lies between the rule's minimum and maximum.
	 */
	public const FLAG_NO_STEP = 'no_step';

	/**
	 * The price the rule would set is not above the sale price.
	 */
	public const FLAG_SALE = 'sale';

	/**
	 * A level of the rule changed, and the rule's price is another now.
	 * Kept until the price is the rule's, whatever the tolerance says.
	 */
	public const FLAG_RULE = 'rule';

	/**
	 * @param VariantCost|null $cost       The cost last read; null while Pixonaut listed none for its variant.
	 * @param bool             $follows    Whether the rule was applied to it.
	 * @param int|null         $rule_price The price the rule last set, in thousandths; null when it set none.
	 * @param int|null         $rule_at    When the rule last set it, a Unix time.
	 * @param int|null         $suggested  The price the rule would set now when it is another than the price it has.
	 * @param string|null      $flag       Why it needs the store manager, a `FLAG_` constant; null when it does not.
	 * @param int|null         $checked_at When its cost was last asked for, a Unix time; null when it never was.
	 * @param string|null      $blank_id   The blank whose variant the cost is of.
	 * @param string|null      $ref        The blank variant the cost is of.
	 * @param int|null         $total      The cost in the store's currency when it was last read, in thousandths: a
	 *                                     later read with another total, because the printshop's price or the
	 *                                     exchange rate moved, is a cost that changed.
	 * @param bool             $ruled      Whether a level of the rule changed since the rule set its price, and the
	 *                                     rule has another price for it: kept, whatever flag shows, until the price
	 *                                     is the rule's.
	 */
	public function __construct(
		public readonly ?VariantCost $cost = null,
		public readonly bool $follows = false,
		public readonly ?int $rule_price = null,
		public readonly ?int $rule_at = null,
		public readonly ?int $suggested = null,
		public readonly ?string $flag = null,
		public readonly ?int $checked_at = null,
		public readonly ?string $blank_id = null,
		public readonly ?string $ref = null,
		public readonly ?int $total = null,
		public readonly bool $ruled = false,
	) {
	}

	/**
	 * How a price stands to the rule: `OWN`, `FOLLOWS` or `BY_HAND`.
	 *
	 * @param int|null $price The regular price it has, in thousandths; null for none.
	 */
	public function standing( ?int $price ): string {
		if ( ! $this->follows ) {
			return self::OWN;
		}
		// A price the rule could not set follows it until one is entered by hand.
		return $this->rule_price === $price ? self::FOLLOWS : self::BY_HAND;
	}

	/**
	 * The cost last read, while it is the cost of what the product sells
	 * now: of the same variant of the same blank, shipped to the same
	 * country. A product paired with another variant since, or a shop that
	 * moved, has no cost until its own is read.
	 *
	 * @param string|null $blank_id The blank it is sold on now.
	 * @param string|null $ref      The blank variant it sells now.
	 * @param string      $region   The country shipping is priced to now.
	 */
	public function cost_of( ?string $blank_id, ?string $ref, string $region ): ?VariantCost {
		return null !== $this->cost && null !== $ref && $this->blank_id === $blank_id && $this->ref === $ref && $this->cost->region === $region ? $this->cost : null;
	}

	/**
	 * The state once its cost was asked for.
	 *
	 * @param VariantCost|null $cost     The cost read; null while Pixonaut listed none for it.
	 * @param int              $now      The time, a Unix time.
	 * @param string|null      $blank_id The blank whose variant the cost is of.
	 * @param string|null      $ref      The blank variant the cost is of.
	 * @param int|null         $total    The cost in the store's currency, in thousandths.
	 */
	public function with_cost( ?VariantCost $cost, int $now, ?string $blank_id, ?string $ref, ?int $total ): self {
		return new self( $cost, $this->follows, $this->rule_price, $this->rule_at, $this->suggested, $this->flag, $now, $blank_id, $ref, $total, $this->ruled );
	}

	/**
	 * The state once the rule set a price, which follows the rule from then on.
	 *
	 * @param int $price The price, in thousandths.
	 * @param int $now   The time, a Unix time.
	 */
	public function priced( int $price, int $now ): self {
		return new self( $this->cost, true, $price, $now, null, null, $this->checked_at, $this->blank_id, $this->ref, $this->total );
	}

	/**
	 * The state with what the rule would change, or with nothing to change.
	 *
	 * @param string|null $flag      A `FLAG_` constant, or null.
	 * @param int|null    $suggested The price the rule would set.
	 */
	public function with_flag( ?string $flag = null, ?int $suggested = null ): self {
		return new self( $this->cost, $this->follows, $this->rule_price, $this->rule_at, $suggested, $flag, $this->checked_at, $this->blank_id, $this->ref, $this->total, $this->ruled );
	}

	/**
	 * The state with whether a changed rule has another price for it.
	 *
	 * @param bool $ruled Whether it has.
	 */
	public function with_ruled( bool $ruled ): self {
		return new self( $this->cost, $this->follows, $this->rule_price, $this->rule_at, $this->suggested, $this->flag, $this->checked_at, $this->blank_id, $this->ref, $this->total, $ruled );
	}

	/**
	 * The state stored on a product or variation; an empty one when none
	 * is, or it is of a version this plugin does not know.
	 *
	 * @param WC_Product $product The product or variation.
	 */
	public static function stored_on( WC_Product $product ): self {
		$raw  = $product->get_meta( self::META_KEY, true );
		$data = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : null;
		if ( ! is_array( $data ) || self::SCHEMA_VERSION !== ( $data['v'] ?? null ) ) {
			return new self();
		}
		return new self(
			VariantCost::from_array( $data['cost'] ?? null ),
			true === ( $data['follows'] ?? null ),
			Amount::parse( $data['rulePrice'] ?? null ),
			is_int( $data['ruleAt'] ?? null ) ? $data['ruleAt'] : null,
			Amount::parse( $data['suggested'] ?? null ),
			is_string( $data['flag'] ?? null ) ? $data['flag'] : null,
			is_int( $data['checkedAt'] ?? null ) ? $data['checkedAt'] : null,
			is_string( $data['blankId'] ?? null ) ? $data['blankId'] : null,
			is_string( $data['ref'] ?? null ) ? $data['ref'] : null,
			Amount::parse( $data['total'] ?? null ),
			true === ( $data['ruled'] ?? null )
		);
	}

	/**
	 * Stores the state on a product or variation, unless it is stored already.
	 *
	 * @param WC_Product $product The product or variation.
	 */
	public function save_to( WC_Product $product ): void {
		$json = (string) wp_json_encode( $this->to_array() );
		if ( $product->get_meta( self::META_KEY, true ) === $json ) {
			return;
		}
		$product->update_meta_data( self::META_KEY, $json );
		$product->save_meta_data();
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		// Written only while true, so a state from before it reads as it was written.
		$ruled = $this->ruled ? array( 'ruled' => true ) : array();
		return $ruled + array(
			'v'         => self::SCHEMA_VERSION,
			'cost'      => $this->cost?->to_array(),
			'follows'   => $this->follows,
			'rulePrice' => null === $this->rule_price ? null : Amount::decimal( $this->rule_price ),
			'ruleAt'    => $this->rule_at,
			'suggested' => null === $this->suggested ? null : Amount::decimal( $this->suggested ),
			'flag'      => $this->flag,
			'checkedAt' => $this->checked_at,
			'blankId'   => $this->blank_id,
			'ref'       => $this->ref,
			'total'     => null === $this->total ? null : Amount::decimal( $this->total ),
		);
	}
}
