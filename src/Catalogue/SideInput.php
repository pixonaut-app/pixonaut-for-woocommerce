<?php
/**
 * One side as the store manager entered it.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

/**
 * A row of the product screen's sides, before Pixonaut checked it. Empty
 * strings are fields left empty.
 */
final class SideInput {

	/**
	 * @param string             $key           Side key, already a valid one.
	 * @param string             $label         Name customers see, or empty to take the placement's.
	 * @param string             $placement_id  Placement on the blank, or empty.
	 * @param string             $template_id   `<baseId>@<version>`, or empty.
	 * @param string             $template_name The template's name as the search listed it.
	 * @param (PrintSize|null)[] $sizes         The print-size menu, in order: a custom size, or null for the placement's default.
	 * @param int                $start         The size the designer starts on, by index into `$sizes`.
	 * @param string             $mode          `Side::PERSONALISED` or `Side::STANDING`.
	 * @param FormGroups|null    $form_groups   How the designer's form is arranged; null for the template's own.
	 * @param bool               $pinned        Whether the side stays on the version in `$template_id`; false follows the latest.
	 * @param int|null           $listed_version The template's latest version as the product screen listed it; null when it did not.
	 * @param bool|null          $draw_at_template_ratio Whether the designer draws the side at its template's ratio (`CanvasRatio`); null for the product's choice.
	 * @phpstan-param list<PrintSize|null> $sizes
	 */
	public function __construct(
		public readonly string $key,
		public readonly string $label,
		public readonly string $placement_id,
		public readonly string $template_id,
		public readonly string $template_name,
		public readonly array $sizes,
		public readonly int $start = 0,
		public readonly string $mode = Side::PERSONALISED,
		public readonly ?FormGroups $form_groups = null,
		public readonly bool $pinned = false,
		public readonly ?int $listed_version = null,
		public readonly ?bool $draw_at_template_ratio = null,
	) {
	}

	/**
	 * A copy on another template version.
	 *
	 * @param string $template_id `<baseId>@<version>`.
	 */
	public function with_template_id( string $template_id ): self {
		return new self( $this->key, $this->label, $this->placement_id, $template_id, $this->template_name, $this->sizes, $this->start, $this->mode, $this->form_groups, $this->pinned, $this->listed_version, $this->draw_at_template_ratio );
	}
}
