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
 * is still the same one. On a side with page layouts, the values reach each
 * layout too: a set per layout made of the values for the layers it has, so
 * one theme's art is on every page whatever its layout.
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
	 * @param array<string, string>               $layout_sets      The set made of the values for each page layout of
	 *                                                              the side, by the layout's base id; a layout that has
	 *                                                              none of the values' layers has none.
	 */
	public function __construct(
		public readonly string $template_base_id,
		public readonly string $value_set_id,
		public readonly array $values,
		public readonly array $layout_sets = array(),
	) {
	}

	/**
	 * A copy with other sets for the side's page layouts.
	 *
	 * @param array<string, string> $sets The sets, by the layout's base id.
	 */
	public function with_layout_sets( array $sets ): self {
		return new self( $this->template_base_id, $this->value_set_id, $this->values, $sets );
	}

	/**
	 * The set a page layout of the side opens on, a renamed template found
	 * by any of its ids; null when it has none.
	 *
	 * @param string $base_id The layout's template, by base id.
	 */
	public function layout_set( string $base_id ): ?string {
		foreach ( $this->layout_sets as $layout => $set ) {
			if ( TemplateRenames::same( (string) $layout, $base_id ) ) {
				return $set;
			}
		}
		return null;
	}

	/**
	 * The values a template with these layers takes: those for its layers
	 * alone.
	 *
	 * @param array<string, array<string, mixed>> $values    Values by layer id and prop.
	 * @param string[]                            $layer_ids The template's layers.
	 * @return array<string, array<string, mixed>>
	 */
	public static function for_layers( array $values, array $layer_ids ): array {
		return array_intersect_key( $values, array_flip( $layer_ids ) );
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
	 * @return array{template: string, valueSet: string, values: array<string, array<string, mixed>>, layoutSets?: array<string, string>}
	 */
	public function to_array(): array {
		return array(
			'template' => $this->template_base_id,
			'valueSet' => $this->value_set_id,
			'values'   => $this->values,
		) + ( array() === $this->layout_sets ? array() : array( 'layoutSets' => $this->layout_sets ) );
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
		$layouts = array();
		foreach ( is_array( $data['layoutSets'] ?? null ) ? $data['layoutSets'] : array() as $layout => $layout_set ) {
			if ( '' !== (string) $layout && is_string( $layout_set ) && str_starts_with( $layout_set, self::ID_PREFIX ) ) {
				$layouts[ (string) $layout ] = $layout_set;
			}
		}
		return new self( $template, $set, $values, $layouts );
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
