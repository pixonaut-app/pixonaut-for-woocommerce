<?php
/**
 * One side's own values for its template's layers.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

/**
 * The values a store manager gave one side of a product or variation, by
 * layer and prop, with the value set Pixonaut made of them for the template
 * they were given for (`POST /v1/templates/{id}/value-sets`). The designer,
 * the pictures and the cart name the set, never the values: an offered prop
 * starts at its value and a fixed one prints it. They apply only while the
 * side is on that template (`applies_to()`); a template that changed its id
 * is still the same one.
 */
final class SideValue {

	/**
	 * What a value set's id starts with.
	 */
	public const ID_PREFIX = 'vs_';

	/**
	 * @param string                              $template_base_id The template the set was made for, by base id.
	 * @param string                              $value_set_id     The set, `vs_…`.
	 * @param array<string, array<string, mixed>> $values           The values, by layer id and prop.
	 */
	public function __construct(
		public readonly string $template_base_id,
		public readonly string $value_set_id,
		public readonly array $values,
	) {
	}

	/**
	 * Whether the values are for the template the side is on now.
	 *
	 * @param Side $side The side.
	 */
	public function applies_to( Side $side ): bool {
		return '' !== $side->template_base_id && TemplateRenames::same( $this->template_base_id, $side->template_base_id );
	}

	/**
	 * As stored.
	 *
	 * @return array{template: string, valueSet: string, values: array<string, array<string, mixed>>}
	 */
	public function to_array(): array {
		return array(
			'template' => $this->template_base_id,
			'valueSet' => $this->value_set_id,
			'values'   => $this->values,
		);
	}

	/**
	 * One side's entry as stored; null for one in no shape read here.
	 *
	 * @param mixed $data The entry.
	 */
	public static function from_array( mixed $data ): ?self {
		if ( ! is_array( $data ) ) {
			return null;
		}
		$template = $data['template'] ?? null;
		$set      = $data['valueSet'] ?? null;
		$values   = self::values( $data['values'] ?? null );
		if ( ! is_string( $template ) || '' === $template || ! is_string( $set ) || ! str_starts_with( $set, self::ID_PREFIX ) || null === $values ) {
			return null;
		}
		return new self( $template, $set, $values );
	}

	/**
	 * Values by layer id and prop; null for anything else, and for none.
	 *
	 * @param mixed $data What may be values.
	 * @return array<string, array<string, mixed>>|null
	 */
	public static function values( mixed $data ): ?array {
		if ( ! is_array( $data ) || array() === $data ) {
			return null;
		}
		$values = array();
		foreach ( $data as $layer => $props ) {
			// PHP keys an array by a layer id of digits as an integer.
			if ( '' === (string) $layer || ! is_array( $props ) || array() === $props || array_is_list( $props ) ) {
				return null;
			}
			$values[ (string) $layer ] = $props;
		}
		return $values;
	}
}
