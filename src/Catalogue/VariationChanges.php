<?php
/**
 * What generating a product's variations from its plan adds, changes and disables.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

/**
 * The difference between the variations a product's plan offers and the
 * ones it has, as `VariationCombinations` works it out: what a dry run
 * lists, and what generating does. Attributes are given by attribute name
 * (`pa_frame`) with the value a variation holds (a term's slug, a custom
 * attribute's text), `''` for "Any".
 *
 * @phpstan-type Added array{attributes: array<string, string>, label: string, entry: int, blank_id: string, ref: string, variant: string}
 * @phpstan-type Changed array{variation_id: int, attributes: array<string, string>, label: string, entry: int, blank_id: string, ref: string, variant: string, why: string}
 * @phpstan-type Disabled array{variation_id: int, attributes: array<string, string>, label: string, why: string}
 * @phpstan-type Skipped array{attributes: array<string, string>, label: string, entry: int, blank_id: string, why: string}
 */
final class VariationChanges {

	/**
	 * A variation the plan disabled is offered again: it is published again.
	 */
	public const ENABLE = 'enable';

	/**
	 * A variation that sells no variant of its blank now gets the plan's.
	 */
	public const VARIANT = 'variant';

	/**
	 * The variation's values are no longer all offered, or name no blank.
	 */
	public const NOT_OFFERED = 'not_offered';

	/**
	 * The blank variant the variation's values name is retired.
	 */
	public const RETIRED = 'retired';

	/**
	 * The values name no variant of the blank.
	 */
	public const NO_VARIANT = 'no_variant';

	/**
	 * The values name several variants of the blank: an option is left open.
	 */
	public const SEVERAL_VARIANTS = 'several_variants';

	/**
	 * @param array[] $add     Variations to make.
	 * @param array[] $change  Variations kept, changed as `why` says.
	 * @param array[] $disable Variations to disable.
	 * @param array[] $skipped Combinations of values not made, as `why` says.
	 * @param int[]   $kept    The variations kept as they are.
	 * @phpstan-param list<Added> $add
	 * @phpstan-param list<Changed> $change
	 * @phpstan-param list<Disabled> $disable
	 * @phpstan-param list<Skipped> $skipped
	 * @phpstan-param list<int> $kept
	 */
	public function __construct(
		public readonly array $add,
		public readonly array $change,
		public readonly array $disable,
		public readonly array $skipped,
		public readonly array $kept,
	) {
	}

	/**
	 * Whether generating would change nothing.
	 */
	public function none(): bool {
		return array() === $this->add && array() === $this->change && array() === $this->disable;
	}
}
