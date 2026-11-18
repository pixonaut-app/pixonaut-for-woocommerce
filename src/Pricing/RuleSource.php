<?php
/**
 * Where a part of a price's rule comes from.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Pricing;

/**
 * The level that set a part of the rule a price follows: the store, a value
 * of a global attribute the variation has, the product, or the variation
 * itself. An attribute value is named by its taxonomy, term and the names
 * the store manager reads.
 */
final class RuleSource {

	public const STORE = 'store';

	public const ATTRIBUTE = 'attribute';

	public const PRODUCT = 'product';

	public const VARIATION = 'variation';

	/**
	 * The levels, the most specific first: the order a part is looked for in.
	 */
	public const LEVELS = array( self::VARIATION, self::PRODUCT, self::ATTRIBUTE, self::STORE );

	/**
	 * @param string $level     One of `LEVELS`.
	 * @param int    $term_id   The attribute value, by term id; 0 for any other level.
	 * @param string $taxonomy  The attribute's taxonomy, such as `pa_size`; empty for any other level.
	 * @param string $attribute The attribute's name, such as Size; empty for any other level.
	 * @param string $value     The value's name, such as 70×100 cm; empty for any other level.
	 * @param string $slug      The value's slug; empty for any other level.
	 */
	public function __construct(
		public readonly string $level,
		public readonly int $term_id = 0,
		public readonly string $taxonomy = '',
		public readonly string $attribute = '',
		public readonly string $value = '',
		public readonly string $slug = '',
	) {
	}

	/**
	 * Whether two sources are the same level, and the same value of an attribute.
	 *
	 * @param RuleSource $other Another source.
	 */
	public function is( RuleSource $other ): bool {
		return $this->level === $other->level && $this->term_id === $other->term_id;
	}

	/**
	 * The source as the abilities answer it.
	 *
	 * @return array{level: string, attribute: string|null, taxonomy: string|null, term_id: int|null, value: string|null}
	 */
	public function to_array(): array {
		$attribute = self::ATTRIBUTE === $this->level;
		return array(
			'level'     => $this->level,
			'attribute' => $attribute ? $this->attribute : null,
			'taxonomy'  => $attribute ? $this->taxonomy : null,
			'term_id'   => $attribute ? $this->term_id : null,
			'value'     => $attribute ? $this->value : null,
		);
	}
}
