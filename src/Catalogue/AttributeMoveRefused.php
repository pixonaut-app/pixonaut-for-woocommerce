<?php
/**
 * Why a product's attribute was not moved.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use RuntimeException;

/**
 * A product `AttributeMove` leaves as it was: `$reason` is one of the
 * class's codes, and the message says what to do about it.
 */
final class AttributeMoveRefused extends RuntimeException {

	/** The global attribute is on the product already. */
	public const TARGET_ON_PRODUCT = 'target_on_product';

	/** The product has more variations than one move writes. */
	public const TOO_MANY_VARIATIONS = 'too_many_variations';

	/** Two values of the custom attribute have the same slug, so a variation's value could mean either. */
	public const AMBIGUOUS_VALUES = 'ambiguous_values';

	/** A value of the custom attribute has no term of the global one. */
	public const MISSING_TERMS = 'missing_terms';

	/** Two values of the custom attribute would become one term. */
	public const DUPLICATE_TERMS = 'duplicate_terms';

	/** A variation holds a value the custom attribute does not offer. */
	public const UNKNOWN_VALUE = 'unknown_value';

	/** The new names would make a variant matched by options on a blank stop selling. */
	public const BLANK_VARIANT = 'blank_variant';

	/** The move was written, and undone: it did not come out as planned. */
	public const NOT_SAVED = 'not_saved';

	/**
	 * @param string $reason  One of the class's codes.
	 * @param string $message What to do about it.
	 */
	public function __construct( public readonly string $reason, string $message ) {
		parent::__construct( $message );
	}
}
