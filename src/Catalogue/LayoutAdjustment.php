<?php
/**
 * What a standing side's row was laid out by hand on.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

/**
 * A row whose layout the store manager adjusted by hand is printed from a
 * standing design of its own, made from the adjustment's proof. It keeps
 * that design while the side gets a new version of its template or another
 * size, and follows the side again once the side is printed from another
 * template, blank or placement.
 *
 * It keeps what the adjusted design was asked for by, the template version
 * and the size, which Pixonaut wants named again when the row is adjusted
 * once more.
 */
final class LayoutAdjustment {

	/**
	 * @param string         $template_id  `<baseId>@<version>` the adjusted design is of.
	 * @param string         $blank_id     The blank.
	 * @param string         $placement_id The placement.
	 * @param PrintSize|null $size         The size the design was asked for at; null for the row's usual size.
	 */
	public function __construct(
		public readonly string $template_id,
		public readonly string $blank_id,
		public readonly string $placement_id,
		public readonly ?PrintSize $size,
	) {
	}

	/**
	 * Whether the adjustment still holds for a side printed from a template
	 * on a blank's placement: the same template, in any version, blank and
	 * placement.
	 *
	 * @param string      $template_base_id The side's template.
	 * @param string|null $blank_id         The product's blank.
	 * @param string|null $placement_id     The side's placement.
	 */
	public function holds( string $template_base_id, ?string $blank_id, ?string $placement_id ): bool {
		return TemplateId::parse( $this->template_id )?->base_id === $template_base_id && $this->blank_id === $blank_id && $this->placement_id === $placement_id;
	}

	/**
	 * @param mixed $data Stored shape, see to_array().
	 * @return self|null Null when the data names no template, blank or placement.
	 */
	public static function from_array( mixed $data ): ?self {
		if ( ! is_array( $data ) ) {
			return null;
		}
		foreach ( array( 'templateId', 'blankId', 'placementId' ) as $key ) {
			if ( ! is_string( $data[ $key ] ?? null ) || '' === $data[ $key ] ) {
				return null;
			}
		}
		return new self( $data['templateId'], $data['blankId'], $data['placementId'], PrintSize::from_array( $data['size'] ?? null ) );
	}

	/**
	 * @return array{templateId: string, blankId: string, placementId: string, size: array<string, mixed>|null}
	 */
	public function to_array(): array {
		return array(
			'templateId'  => $this->template_id,
			'blankId'     => $this->blank_id,
			'placementId' => $this->placement_id,
			'size'        => $this->size?->stored(),
		);
	}
}
