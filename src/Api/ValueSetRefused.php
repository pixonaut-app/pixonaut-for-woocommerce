<?php
/**
 * Values Pixonaut would not make a set of.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api;

use RuntimeException;

/**
 * `422 value_set_invalid`: every value the template version does not take,
 * by layer and prop, with the rule each breaks (`unknown_layer`,
 * `unknown_prop`, `value_kind`, or a rule a publish holds the layer to).
 */
final class ValueSetRefused extends RuntimeException {

	/**
	 * @param list<array{layer_id: string, prop: string, rule: string}> $issues Each value refused; an empty `layer_id` is the document as a whole, an empty `prop` the whole layer.
	 */
	public function __construct( public readonly array $issues ) {
		parent::__construct( 'Pixonaut refused the values.' );
	}

	/**
	 * The issues of a `value_set_invalid` refusal's body; one in another
	 * shape is left out.
	 *
	 * @param mixed $issues The body's `issues`.
	 */
	public static function from_answer( mixed $issues ): self {
		$read = array();
		foreach ( is_array( $issues ) ? $issues : array() as $issue ) {
			if ( is_array( $issue ) && is_string( $issue['layerId'] ?? null ) && is_string( $issue['prop'] ?? null ) && is_string( $issue['rule'] ?? null ) ) {
				$read[] = array(
					'layer_id' => $issue['layerId'],
					'prop'     => $issue['prop'],
					'rule'     => $issue['rule'],
				);
			}
		}
		return new self( $read );
	}
}
