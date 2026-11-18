<?php
/**
 * The rule a price follows from its printshop cost.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Pricing;

/**
 * A target margin on the price excluding tax and a fixed amount on top of
 * it, the tolerance within which a price is left alone, the steps a price
 * lands on, and the lowest and highest price. The store has one, and a product may change any part
 * of it. Without a target margin there is no rule, and nothing is priced.
 *
 * Money is in thousandths (`Amount`). Steps, minimum and maximum count on
 * the price as the store enters it, including tax or not; the margin and
 * the fixed amount count on the price excluding tax (`PriceTax`):
 *
 *     price excluding tax = cost ÷ (1 − margin) + fixed amount
 *
 * Every item carries much the same fixed costs, so the fixed amount gives a
 * cheap item a high margin and an expensive one a lower, without jumps.
 */
final class PriceRule {

	public const MARGIN = 'margin';

	public const FIXED = 'fixed';

	public const TOLERANCE = 'tolerance';

	public const STEP = 'step';

	public const ENDING = 'ending';

	public const MIN = 'min';

	public const MAX = 'max';

	/**
	 * The rule's parts, in the order they are shown.
	 */
	public const PARTS = array( self::MARGIN, self::FIXED, self::TOLERANCE, self::STEP, self::ENDING, self::MIN, self::MAX );

	/**
	 * A margin is a share of the price, so it stays under all of it.
	 */
	private const WHOLE_PRICE_PERCENT = 100.0;

	public const DEFAULT_TOLERANCE = '10';

	/**
	 * Every ten, ending in nine: 379, 389, 399.
	 */
	public const DEFAULT_STEP = '10';

	public const DEFAULT_ENDING = '9';

	/**
	 * @param float|null $margin    Target margin in percent of the price excluding tax, above 0 and below 100; null for no rule.
	 * @param float      $tolerance Percentage points either side of the rule's margin at a cost within which a price is kept.
	 * @param int        $step      Distance between two prices the rule may set, in thousandths; above 0.
	 * @param int        $ending    Where the steps start, in thousandths, below the step: 9 with a step of 10 gives 9, 19, 29.
	 * @param int|null   $min       Lowest price the rule sets; null for none.
	 * @param int|null   $max       Highest price the rule sets; null for none.
	 * @param int        $fixed     Added to the price excluding tax the margin gives, in thousandths; 0 for none.
	 */
	public function __construct(
		public readonly ?float $margin,
		public readonly float $tolerance,
		public readonly int $step,
		public readonly int $ending,
		public readonly ?int $min = null,
		public readonly ?int $max = null,
		public readonly int $fixed = 0,
	) {
	}

	/**
	 * The rule its parts as written give, each a decimal string or empty:
	 * a margin outside 0 to 100 is no rule, a step of nothing is one unit,
	 * an ending that is not below the step is none, no fixed amount is 0.
	 *
	 * @param array<string, string> $parts The parts as written, by part; a missing one is empty.
	 */
	public static function from_parts( array $parts ): self {
		$margin    = self::percent( $parts[ self::MARGIN ] ?? '' );
		$tolerance = self::percent( $parts[ self::TOLERANCE ] ?? '' );
		$step      = Amount::parse( $parts[ self::STEP ] ?? '' );
		$step      = null === $step || 0 === $step ? Amount::PER_UNIT : $step;
		$ending    = Amount::parse( $parts[ self::ENDING ] ?? '' ) ?? 0;
		return new self(
			null !== $margin && $margin > 0 && $margin < self::WHOLE_PRICE_PERCENT ? $margin : null,
			$tolerance ?? 0.0,
			$step,
			$ending < $step ? $ending : 0,
			Amount::parse( $parts[ self::MIN ] ?? '' ),
			Amount::parse( $parts[ self::MAX ] ?? '' ),
			Amount::parse( $parts[ self::FIXED ] ?? '' ) ?? 0
		);
	}

	/**
	 * Whether a part as written is one the rule takes: empty, or a number
	 * that is not negative, with a point or a comma.
	 *
	 * @param mixed $written The part as written.
	 */
	public static function is_part( mixed $written ): bool {
		return is_string( $written ) && ( '' === trim( $written ) || null !== Amount::parse( $written ) );
	}

	/**
	 * A part as written, as it is stored: trimmed, with a point.
	 *
	 * @param string $written A part `is_part()` takes.
	 */
	public static function part( string $written ): string {
		return str_replace( ',', '.', trim( $written ) );
	}

	/**
	 * Whether there is a rule to price by.
	 */
	public function is_set(): bool {
		return null !== $this->margin;
	}

	/**
	 * The margin a price leaves over a cost, in percent of the price
	 * excluding tax; null for a price of nothing.
	 *
	 * @param int      $price A price as entered.
	 * @param int      $cost  What the printshop charges.
	 * @param PriceTax $tax   The tax in the price as entered.
	 */
	public static function margin_of( int $price, int $cost, PriceTax $tax ): ?float {
		$excluding = $tax->excluding( $price );
		return $excluding > 0 ? ( $excluding - $cost ) / $excluding * self::WHOLE_PRICE_PERCENT : null;
	}

	/**
	 * What a price leaves over a cost, excluding tax: its contribution.
	 *
	 * @param int      $price A price as entered.
	 * @param int      $cost  What the printshop charges.
	 * @param PriceTax $tax   The tax in the price as entered.
	 */
	public static function contribution_of( int $price, int $cost, PriceTax $tax ): int {
		return $tax->excluding( $price ) - $cost;
	}

	/**
	 * The price as entered the rule aims at for a cost, wherever the steps
	 * are: the target margin over the cost, and the fixed amount on top.
	 * Null without a rule.
	 *
	 * @param int      $cost What the printshop charges.
	 * @param PriceTax $tax  The tax in the price as entered.
	 */
	public function target( int $cost, PriceTax $tax ): ?int {
		$excluding = $this->target_excluding( $cost );
		return null === $excluding ? null : $tax->entered( $excluding );
	}

	/**
	 * The margin the rule's own price leaves at a cost, in percent of the
	 * price excluding tax: the target margin without a fixed amount, more
	 * the cheaper the cost with one. Null without a rule.
	 *
	 * @param int $cost What the printshop charges.
	 */
	public function margin_at( int $cost ): ?float {
		$excluding = $this->target_excluding( $cost );
		if ( null === $excluding || 0 === $this->fixed || $excluding <= 0 ) {
			return $this->margin;
		}
		return ( $excluding - $cost ) / $excluding * self::WHOLE_PRICE_PERCENT;
	}

	/**
	 * The price excluding tax the rule aims at for a cost; null without a rule.
	 *
	 * @param int $cost What the printshop charges.
	 */
	private function target_excluding( int $cost ): ?int {
		if ( null === $this->margin ) {
			return null;
		}
		return (int) round( $cost / ( 1 - $this->margin / self::WHOLE_PRICE_PERCENT ) ) + $this->fixed;
	}

	/**
	 * The price the rule sets for a cost: the step nearest the target, a
	 * target halfway between two taking the higher, moved to the nearest
	 * step within the minimum and maximum. Null without a rule, or when no
	 * step lies between the minimum and the maximum.
	 *
	 * @param int      $cost What the printshop charges.
	 * @param PriceTax $tax  The tax in the price as entered.
	 */
	public function price_for( int $cost, PriceTax $tax ): ?int {
		$target = $this->target( $cost, $tax );
		return null === $target ? null : $this->step_near( $target );
	}

	/**
	 * Whether a price is one the rule leaves alone at a cost: its margin is
	 * within the tolerance of the margin the rule's own price leaves at that
	 * cost (`margin_at()`), and it is within the minimum and the maximum.
	 *
	 * @param int      $price A price as entered.
	 * @param int      $cost  What the printshop charges.
	 * @param PriceTax $tax   The tax in the price as entered.
	 */
	public function holds( int $price, int $cost, PriceTax $tax ): bool {
		$margin = self::margin_of( $price, $cost, $tax );
		$target = $this->margin_at( $cost );
		if ( null === $target || null === $margin || abs( $margin - $target ) > $this->tolerance ) {
			return false;
		}
		return ( null === $this->min || $price >= $this->min ) && ( null === $this->max || $price <= $this->max );
	}

	/**
	 * The step nearest a price, within the minimum and the maximum and above
	 * nothing; null when no step is.
	 *
	 * @param int $price A price as entered.
	 */
	public function step_near( int $price ): ?int {
		// Halfway rounds up: a price a step too high keeps the margin, one too low does not.
		$steps = intdiv( 2 * ( $price - $this->ending ) + $this->step, 2 * $this->step );
		$near  = $this->ending + $steps * $this->step;
		// The lowest step a product can sell at: the first above nothing, or the first from the minimum up.
		$floor = max( 1, $this->min ?? 1 );
		if ( $near < $floor ) {
			$near = $this->ending + self::steps_up_to( $floor - $this->ending, $this->step ) * $this->step;
		}
		if ( null !== $this->max && $near > $this->max ) {
			$near = $this->ending + intdiv( $this->max - $this->ending, $this->step ) * $this->step;
		}
		return $near >= $floor && ( null === $this->max || $near <= $this->max ) ? $near : null;
	}

	/**
	 * How many whole steps reach a distance: the division rounded up.
	 *
	 * @param int $distance Thousandths to cover; may be negative.
	 * @param int $step     One step.
	 */
	private static function steps_up_to( int $distance, int $step ): int {
		return $distance <= 0 ? 0 : intdiv( $distance + $step - 1, $step );
	}

	/**
	 * A percentage as written; null when it is not a number.
	 *
	 * @param string $written A decimal string, with a point or a comma.
	 */
	private static function percent( string $written ): ?float {
		$amount = Amount::parse( $written );
		return null === $amount ? null : Amount::units( $amount );
	}
}
