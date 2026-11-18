<?php
/**
 * The blank options a global attribute can be linked to.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use Closure;

/**
 * The options, each with its values, of the blanks that products with
 * values of an attribute use, themselves or through their variations, as
 * Pixonaut writes them: what `BlankOptionLinks` can link the attribute to.
 */
final class BlankOptionChoices {

	/**
	 * @param Closure $written_options A blank's options as Pixonaut writes them, by blank id, as `ServerReads::written_options()` reads them.
	 * @phpstan-param Closure(string): list<array<mixed>> $written_options
	 */
	public function __construct( private readonly Closure $written_options ) {
	}

	/**
	 * The options of the blanks used with an attribute, each with its
	 * values, in the blanks' order; an option two blanks share lists the
	 * values of both.
	 *
	 * @param string $taxonomy A global attribute's taxonomy.
	 * @return array<string, list<string>> Values by option.
	 */
	public function for_taxonomy( string $taxonomy ): array {
		$choices = array();
		foreach ( ProductIndex::blanks_with_attribute( $taxonomy ) as $blank ) {
			foreach ( ( $this->written_options )( $blank ) as $option ) {
				$name = is_string( $option['name'] ?? null ) ? $option['name'] : '';
				if ( '' === $name ) {
					continue;
				}
				$choices[ $name ] ??= array();
				foreach ( is_array( $option['values'] ?? null ) ? $option['values'] : array() as $value ) {
					if ( is_string( $value ) && '' !== $value && ! in_array( $value, $choices[ $name ], true ) ) {
						$choices[ $name ][] = $value;
					}
				}
			}
		}
		return $choices;
	}
}
