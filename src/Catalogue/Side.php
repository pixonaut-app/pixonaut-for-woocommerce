<?php
/**
 * One side of a product Pixonaut prints.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

/**
 * A side (front, back, …): its placement on the blank, its template, and what
 * it is sold in. A personalised side's template is what customers fill in; a
 * standing side is the template as its author left it, printed from the
 * store's standing design the product screen finds for it. Names are
 * cached copies for display; ids are what the plugin sends to Pixonaut.
 */
final class Side {

	/**
	 * The customer fills in the template; a design is committed at add to cart.
	 */
	public const PERSONALISED = 'personalised';

	/**
	 * The template as authored, from one standing design ordered for every sale.
	 */
	public const STANDING = 'standing';

	public const MODES = array( self::PERSONALISED, self::STANDING );

	/**
	 * Most template versions the product screen offers to stay on, the
	 * newest first: a version number is not bounded, and older ones are
	 * rarely what a shop goes back to.
	 */
	public const MAX_VERSIONS_OFFERED = 50;

	/**
	 * A template id as Pixonaut writes one: lower-case words joined by
	 * hyphens, e.g. `kids-poster-safari-17`. Earlier saves named a side
	 * after its template's id; such a name was never typed by the merchant.
	 */
	private const TEMPLATE_ID_SHAPE = '/^[a-z0-9]+(?:-[a-z0-9]+)+$/';

	/**
	 * @param string          $key              Stable key, unique within the product, e.g. `front`.
	 * @param string          $label            Name customers see, e.g. "Front".
	 * @param string|null     $placement_id     Placement on the blank; null for a product without a blank.
	 * @param string|null     $placement_name   Cached placement name.
	 * @param string          $template_base_id Template id without the `@version` suffix; empty while the
	 *                                          store manager has not chosen one, which only an invalid
	 *                                          configuration has.
	 * @param int|null        $template_version The template's live version when last checked; null without a template.
	 * @param int|null        $previous_version The live version before that, set when a re-check moved it.
	 * @param string|null     $template_name    Cached template name.
	 * @param PrintSize[]     $print_sizes      Sizes the side is sold in; empty means the placement's default.
	 * @param array|null      $pending_sizes    The print-size menu as entered, kept while Pixonaut could not
	 *                                          check it (a custom size, or null for the placement's default),
	 *                                          so the product screen shows it again; null once checked.
	 * @param int             $start_size       The size the designer starts on, by its index into the menu the
	 *                                          side holds: the pending one while it is kept, else the sizes sold.
	 *                                          A standing side is printed at it.
	 * @param string          $mode             `PERSONALISED` or `STANDING`.
	 * @param string|null     $standing_design_id A standing side's design, as Pixonaut last found it; null until found.
	 * @param bool|null       $offers_properties Whether the template version offers the customer a field, as last read; null unknown.
	 * @param FormGroups|null $form_groups  How the designer's form is arranged on the product page; null for the template's own.
	 * @param StandingRow[]   $standing_rows A standing side's design per row of its blank's print areas, on a blank whose
	 *                                       areas change with an option; empty on any other, which has `standing_design_id`.
	 * @param string[]        $misfit_sizes  The blank's sizes the template cannot be printed at on the placement, by option
	 *                                       value such as `XL` (several options' values joined with ` / `), as last checked:
	 *                                       a standing side sells none of them. Empty when it prints at every size.
	 * @param bool            $pinned        Whether the side stays on `template_version` when a new version is
	 *                                       published; false follows the template's latest version.
	 * @param int|null        $latest_version The template's latest published version, as last known; null when
	 *                                        never read, which `template_version` then stands in for.
	 * @param bool|null       $draw_at_template_ratio Whether the designer draws the side's canvas at its template's
	 *                                        ratio whatever print size is chosen (`CanvasRatio`); null for the
	 *                                        product's choice.
	 * @param RowSize[]       $row_sizes     A personalised side's size per row of its blank's print areas, on a blank whose
	 *                                       areas change with an option: a variation sold at the placement's default
	 *                                       prints at its variant's row. Empty on any other side.
	 * @phpstan-param list<PrintSize> $print_sizes
	 * @phpstan-param list<PrintSize|null>|null $pending_sizes
	 * @phpstan-param list<string> $misfit_sizes
	 * @phpstan-param list<RowSize> $row_sizes
	 */
	public function __construct(
		public readonly string $key,
		public readonly string $label,
		public readonly ?string $placement_id,
		public readonly ?string $placement_name,
		public readonly string $template_base_id,
		public readonly ?int $template_version,
		public readonly ?int $previous_version,
		public readonly ?string $template_name,
		public readonly array $print_sizes = array(),
		public readonly ?array $pending_sizes = null,
		public readonly int $start_size = 0,
		public readonly string $mode = self::PERSONALISED,
		public readonly ?string $standing_design_id = null,
		public readonly ?bool $offers_properties = null,
		public readonly ?FormGroups $form_groups = null,
		public readonly array $standing_rows = array(),
		public readonly array $misfit_sizes = array(),
		public readonly bool $pinned = false,
		public readonly ?int $latest_version = null,
		public readonly ?bool $draw_at_template_ratio = null,
		public readonly array $row_sizes = array(),
	) {
	}

	/**
	 * The name a side takes while the merchant has typed none: its
	 * placement's, else its template's, else its key. Never a template id.
	 *
	 * @param string|null $placement_name The placement's name; null without one.
	 * @param string|null $template_name  The template's name; null while unknown.
	 * @param string      $key            The side's key.
	 */
	public static function followed_label( ?string $placement_name, ?string $template_name, string $key ): string {
		foreach ( array( $placement_name, $template_name ) as $name ) {
			if ( null !== $name && '' !== $name ) {
				return $name;
			}
		}
		return $key;
	}

	/**
	 * The name the merchant typed for the side, or empty when its name is
	 * one it follows: its placement's or template's name, its key, or a
	 * template id an earlier save stored. An empty name follows the
	 * placement or template as they change.
	 */
	public function typed_label(): string {
		$followed = array( $this->placement_name, $this->template_name, $this->template_base_id, $this->key );
		if ( in_array( $this->label, $followed, true ) || 1 === preg_match( self::TEMPLATE_ID_SHAPE, $this->label ) ) {
			return '';
		}
		return $this->label;
	}

	/**
	 * Whether the side is printed from a standing design rather than a customer's.
	 */
	public function is_standing(): bool {
		return self::STANDING === $this->mode;
	}

	/**
	 * The size a standing side is printed at: the one marked as the start.
	 */
	public function standing_size(): ?PrintSize {
		return $this->print_sizes[ $this->selected_size_index() ] ?? null;
	}

	/**
	 * A copy printed from the standing design Pixonaut found, or from none.
	 *
	 * @param string|null $design_id The design; null until one is found.
	 */
	public function with_standing_design( ?string $design_id ): self {
		return $this->copy(
			array(
				'standing_design_id' => $design_id,
				'standing_rows'      => array(),
			)
		);
	}

	/**
	 * A copy printed from a standing design per row of its blank's print areas.
	 *
	 * @param StandingRow[] $rows The rows' designs.
	 * @phpstan-param list<StandingRow> $rows
	 */
	public function with_standing_rows( array $rows ): self {
		return $this->copy(
			array(
				'standing_design_id' => null,
				'standing_rows'      => $rows,
			)
		);
	}

	/**
	 * The standing design a sale of a blank variant is printed from: the
	 * side's one design, or, per row, the one of the row the variant is in.
	 * Null when there is none yet, or no row holds the variant.
	 *
	 * @param string|null $variant The variant sold, `v_…`; null for a product without one.
	 */
	public function standing_design_for( ?string $variant ): ?string {
		if ( array() === $this->standing_rows ) {
			return $this->standing_design_id;
		}
		foreach ( $this->standing_rows as $row ) {
			if ( null !== $variant && in_array( $variant, $row->variants, true ) ) {
				return $row->design_id;
			}
		}
		return null;
	}

	/**
	 * A copy that knows which of the blank's sizes its template cannot be
	 * printed at on its placement.
	 *
	 * @param string[] $sizes Option values, such as `XL`; empty for none.
	 * @phpstan-param list<string> $sizes
	 */
	public function with_misfit_sizes( array $sizes ): self {
		return $this->copy( array( 'misfit_sizes' => $sizes ) );
	}

	/**
	 * A copy that prints at the placement's default per row of its blank's print areas.
	 *
	 * @param RowSize[] $rows The rows; empty for none.
	 * @phpstan-param list<RowSize> $rows
	 */
	public function with_row_sizes( array $rows ): self {
		return $this->copy( array( 'row_sizes' => $rows ) );
	}

	/**
	 * The row of the blank's print areas a variant is in; null when the
	 * side has no rows, or none holds the variant.
	 *
	 * @param string|null $variant The variant's ref; null for none.
	 */
	public function row_size_for( ?string $variant ): ?RowSize {
		foreach ( $this->row_sizes as $row ) {
			if ( $row->holds( $variant ) ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * A copy with its designer form arranged by `$groups`.
	 *
	 * @param FormGroups|null $groups The arrangement; null for the template's own.
	 */
	public function with_form_groups( ?FormGroups $groups ): self {
		return $this->copy( array( 'form_groups' => $groups ) );
	}

	/**
	 * A copy that draws its canvas at its template's ratio, at the chosen
	 * print size's, or as the product chooses.
	 *
	 * @param bool|null $draw Whether it draws at the template's ratio; null for the product's choice.
	 */
	public function with_draw_at_template_ratio( ?bool $draw ): self {
		return $this->copy( array( 'draw_at_template_ratio' => $draw ) );
	}

	/**
	 * A copy that knows whether its template version offers a field.
	 *
	 * @param bool|null $offers Whether it does; null when unknown.
	 */
	public function with_offers_properties( ?bool $offers ): self {
		return $this->copy( array( 'offers_properties' => $offers ) );
	}

	/**
	 * A copy with its template's name as Pixonaut gives it now.
	 *
	 * @param string|null $name The name; null keeps the one it has.
	 */
	public function with_template_name( ?string $name ): self {
		if ( null === $name || $name === $this->template_name ) {
			return $this;
		}
		$renamed = $this->copy( array( 'template_name' => $name ) );
		// A name that followed the template follows its new name.
		return '' === $this->typed_label() ? $renamed->copy( array( 'label' => self::followed_label( $this->placement_name, $name, $this->key ) ) ) : $renamed;
	}

	/**
	 * @param mixed $data Stored shape, see to_array().
	 * @return self|null Null when the data lacks a key.
	 */
	public static function from_array( mixed $data ): ?self {
		if ( ! is_array( $data ) || ! self::is_text( $data['key'] ?? null ) ) {
			return null;
		}
		$template  = is_array( $data['template'] ?? null ) ? $data['template'] : array();
		$base_id   = self::text_or_null( $template['baseId'] ?? null );
		$placement = is_array( $data['placement'] ?? null ) ? $data['placement'] : array();

		$sizes = array();
		foreach ( is_array( $data['printSizes'] ?? null ) ? $data['printSizes'] : array() as $size ) {
			$parsed = PrintSize::from_array( $size );
			if ( null !== $parsed ) {
				$sizes[] = $parsed;
			}
		}

		$pending = null;
		if ( is_array( $data['pendingSizes'] ?? null ) ) {
			$pending = array();
			foreach ( $data['pendingSizes'] as $size ) {
				$parsed = PrintSize::from_array( $size );
				if ( null !== $parsed ) {
					$pending[] = $parsed;
				} elseif ( is_array( $size ) && true === ( $size['placementDefault'] ?? null ) ) {
					$pending[] = null;
				}
			}
		}

		$side = new self(
			$data['key'],
			self::is_text( $data['label'] ?? null ) ? $data['label'] : $data['key'],
			self::text_or_null( $placement['id'] ?? null ),
			self::text_or_null( $placement['name'] ?? null ),
			$base_id ?? '',
			null === $base_id ? null : self::int_or_null( $template['version'] ?? null ),
			self::int_or_null( $template['previousVersion'] ?? null ),
			self::text_or_null( $template['name'] ?? null ),
			$sizes,
			$pending,
			is_int( $data['startSize'] ?? null ) && $data['startSize'] >= 0 ? $data['startSize'] : 0,
			self::STANDING === ( $data['mode'] ?? null ) ? self::STANDING : self::PERSONALISED,
			self::text_or_null( $data['standingDesignId'] ?? null ),
			is_bool( $template['offersProperties'] ?? null ) ? $template['offersProperties'] : null,
			FormGroups::from_array( $data['formGroups'] ?? null ),
			self::standing_rows_from( $data['standingRows'] ?? null ),
			self::strings( $data['misfitSizes'] ?? null ),
			null !== $base_id && true === ( $template['pinned'] ?? null ),
			null === $base_id ? null : self::int_or_null( $template['latestVersion'] ?? null ),
			CanvasRatio::from_stored( $data['drawAtTemplateRatio'] ?? null ),
			array_values( array_filter( array_map( array( RowSize::class, 'from_array' ), is_array( $data['rowSizes'] ?? null ) ? $data['rowSizes'] : array() ) ) ),
		);
		// A name the merchant did not type is what the side follows now, never a template id an earlier save stored.
		return '' === $side->typed_label() ? $side->copy( array( 'label' => self::followed_label( $side->placement_name, $side->template_name, $side->key ) ) ) : $side;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'key'                 => $this->key,
			'label'               => $this->label,
			'placement'           => null === $this->placement_id ? null : array(
				'id'   => $this->placement_id,
				'name' => $this->placement_name,
			),
			'template'            => array(
				'baseId'           => $this->template_base_id,
				'version'          => $this->template_version,
				'previousVersion'  => $this->previous_version,
				'name'             => $this->template_name,
				'offersProperties' => $this->offers_properties,
				'pinned'           => $this->pinned,
				'latestVersion'    => $this->latest_version,
			),
			'printSizes'          => array_map( static fn ( PrintSize $size ): array => $size->stored(), $this->print_sizes ),
			'startSize'           => $this->start_size,
			'mode'                => $this->mode,
			'standingDesignId'    => $this->standing_design_id,
			'formGroups'          => $this->form_groups?->to_array(),
			'standingRows'        => array_map( static fn ( StandingRow $row ): array => $row->to_array(), $this->standing_rows ),
			'misfitSizes'         => $this->misfit_sizes,
			'drawAtTemplateRatio' => $this->draw_at_template_ratio,
			'rowSizes'            => array_map( static fn ( RowSize $row ): array => $row->to_array(), $this->row_sizes ),
		) + ( null === $this->pending_sizes ? array() : array(
			'pendingSizes' => array_map(
				static fn ( ?PrintSize $size ): array => null === $size ? array( 'placementDefault' => true ) : $size->stored(),
				$this->pending_sizes
			),
		) );
	}

	/**
	 * The side following its template's live version: when that moved, the
	 * version it moved from becomes the previous one, so a design made just
	 * before is still accepted, and the side keeps its setup. What was read
	 * of the version it left is not known of the new one. A pinned side
	 * stays on its version, and only learns which one is the latest.
	 *
	 * @param int $live_version The template's live version.
	 */
	public function rolled_to( int $live_version ): self {
		// A followed side's own version stands in for the latest until one is read.
		$latest = $this->latest_version ?? $this->template_version;
		$known  = $latest === $live_version ? $this : $this->copy( array( 'latest_version' => $live_version ) );
		if ( $this->pinned || $live_version === $this->template_version ) {
			return $known;
		}
		return $known->with( $live_version, $this->template_version, $this->print_sizes, $this->pending_sizes )->with_offers_properties( null );
	}

	/**
	 * The index into `print_sizes` of the size the designer starts on: the
	 * first size when the one chosen is not sold.
	 */
	public function selected_size_index(): int {
		return null === $this->pending_sizes && $this->start_size < count( $this->print_sizes ) ? $this->start_size : 0;
	}

	/**
	 * A copy with other versions and sizes, everything else kept.
	 *
	 * @param int|null    $template_version The template's live version.
	 * @param int|null    $previous_version The live version before it.
	 * @param PrintSize[] $print_sizes      Sizes the side is sold in.
	 * @param array|null  $pending_sizes    The menu kept while it cannot be sold; null once checked.
	 * @param int|null    $start_size       The size it starts on, by index into the menu it now holds; null keeps it.
	 * @phpstan-param list<PrintSize> $print_sizes
	 * @phpstan-param list<PrintSize|null>|null $pending_sizes
	 */
	public function with( ?int $template_version, ?int $previous_version, array $print_sizes, ?array $pending_sizes, ?int $start_size = null ): self {
		return $this->copy(
			array(
				'template_version' => $template_version,
				'previous_version' => $previous_version,
				'print_sizes'      => $print_sizes,
				'pending_sizes'    => $pending_sizes,
				'start_size'       => $start_size ?? $this->start_size,
			)
		);
	}

	/**
	 * A copy that stays on its template version, or follows the latest one,
	 * and knows which version is the latest.
	 *
	 * @param bool     $pinned         Whether it stays on its version.
	 * @param int|null $previous_version The version a design is still accepted from besides its own.
	 * @param int|null $latest_version The template's latest published version; null when unknown.
	 */
	public function with_versions( bool $pinned, ?int $previous_version, ?int $latest_version ): self {
		return $this->copy(
			array(
				'pinned'           => $pinned,
				'previous_version' => $previous_version,
				'latest_version'   => $latest_version,
			)
		);
	}

	/**
	 * The template's latest version as known: the one last read, else the
	 * side's own; null without a template.
	 */
	public function known_latest_version(): ?int {
		if ( null === $this->template_version ) {
			return null;
		}
		return max( $this->template_version, $this->latest_version ?? 0 );
	}

	/**
	 * The versions a side can stay on, the latest first: the newest
	 * `MAX_VERSIONS_OFFERED` published up to the latest known, and the
	 * side's own when it is older. Empty without a template.
	 *
	 * @return list<int>
	 */
	public function versions(): array {
		$latest = $this->known_latest_version();
		if ( null === $latest || null === $this->template_version ) {
			return array();
		}
		$versions = range( $latest, max( 1, $latest - self::MAX_VERSIONS_OFFERED + 1 ) );
		if ( ! in_array( $this->template_version, $versions, true ) ) {
			$versions[] = $this->template_version;
		}
		return $versions;
	}

	/**
	 * A copy with some properties changed, by name.
	 *
	 * @param array<string, mixed> $changes New values by property name.
	 */
	private function copy( array $changes ): self {
		return new self( ...array_merge( get_object_vars( $this ), $changes ) );
	}
	/**
	 * The print-size menu to check again: the one kept unchecked, else the
	 * sizes sold, the placement's default as null so it is sized afresh.
	 * Empty when the side sells nothing and kept no menu, which a check of
	 * its own cannot tell the reason of.
	 *
	 * @return list<PrintSize|null>
	 */
	public function menu(): array {
		return $this->pending_sizes ?? array_map(
			static fn ( PrintSize $size ): ?PrintSize => $size->placement_default ? null : $size,
			$this->print_sizes
		);
	}

	/**
	 * @param mixed $data The stored rows.
	 * @return list<StandingRow>
	 */
	private static function standing_rows_from( mixed $data ): array {
		$rows = array();
		foreach ( is_array( $data ) ? $data : array() as $row ) {
			$parsed = StandingRow::from_array( $row );
			if ( null !== $parsed ) {
				$rows[] = $parsed;
			}
		}
		return $rows;
	}

	/**
	 * @param mixed $data A stored list.
	 * @return list<string>
	 */
	private static function strings( mixed $data ): array {
		return array_values( array_filter( is_array( $data ) ? $data : array(), array( self::class, 'is_text' ) ) );
	}

	/**
	 * @param mixed $value Any value.
	 * @phpstan-assert-if-true non-empty-string $value
	 */
	private static function is_text( mixed $value ): bool {
		return is_string( $value ) && '' !== $value;
	}

	private static function text_or_null( mixed $value ): ?string {
		return self::is_text( $value ) ? $value : null;
	}

	private static function int_or_null( mixed $value ): ?int {
		return is_int( $value ) ? $value : null;
	}
}
