<?php
/**
 * The sides the product screen posted.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

/**
 * Reads the sides repeater into `SideInput`s and finds what Pixonaut need not
 * be asked about: more sides than a designer takes, a placement or key used
 * twice, a custom size that is not one. Such a problem is kept with the sides
 * rather than dropping them, so the store manager's choice survives the save.
 */
final class PostedSides {

	/**
	 * Most sides a product has: a multi-side designer link takes ten.
	 */
	public const MAX_SIDES = 10;

	/**
	 * Most sides Pixonaut's designer is built for. A link with more is made,
	 * answered with a `sides_over_recommended` warning: each side is one more
	 * step on the hosted page and one more designer in the customer's browser.
	 */
	public const RECOMMENDED_SIDES = 4;

	/**
	 * Most sizes in one side's menu: what one print-size check judges.
	 */
	public const MAX_SIZES = 50;

	/**
	 * Longest side key Pixonaut takes (`^[a-z0-9_-]{1,32}$`).
	 */
	public const KEY_MAX_LENGTH = 32;

	/**
	 * Longest side label Pixonaut takes.
	 */
	public const LABEL_MAX_LENGTH = 120;

	/**
	 * The `kind` of a size row that is the placement's default print area.
	 */
	public const SIZE_PLACEMENT_DEFAULT = 'default';

	/**
	 * The `kind` of a size row with its own width and height.
	 */
	public const SIZE_CUSTOM = 'custom';

	/**
	 * @param SideInput[] $sides         The sides, in order.
	 * @param string|null $problem       The first problem found, an `InvalidReason` code.
	 * @param string|null $first_problem The first problem found in the first side alone, which is all a product without a blank keeps.
	 * @phpstan-param list<SideInput> $sides
	 */
	private function __construct(
		public readonly array $sides,
		public readonly ?string $problem,
		public readonly ?string $first_problem = null,
	) {
	}

	/**
	 * @param mixed $rows `pixonaut_sides` as posted, already unslashed and
	 *                    sanitised: rows of `key`, `label`, `placement`,
	 *                    `template`, `template_name`, `sizes` (rows of
	 *                    `kind`, `width`, `height`, `label`),
	 *                    `start_size`, the key of the size row the designer
	 *                    starts on (absent for the first), `mode`
	 *                    (`standing`, else personalised), `form_groups`
	 *                    (the arrangement, decoded, see `FormGroups`),
	 *                    `pinned` (`1` to stay on one template version)
	 *                    and `version`, the version it stays on, no later
	 *                    than the one `template` names (its latest), and
	 *                    `canvas_ratio`, a `CanvasRatio` choice.
	 *                    Rows with nothing chosen are skipped.
	 */
	public static function parse( mixed $rows ): self {
		$problems = array();
		$sides    = array();
		$first    = null;
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! is_array( $row ) || self::is_empty_row( $row ) ) {
				continue;
			}
			if ( count( $sides ) === self::MAX_SIDES ) {
				$problems[] = InvalidReason::TOO_MANY_SIDES;
				break;
			}
			$sides[] = self::side( $row, count( $sides ), $problems );
			if ( 1 === count( $sides ) ) {
				$first = $problems[0] ?? null;
			}
		}

		$placements = array_filter( array_map( static fn ( SideInput $side ): string => $side->placement_id, $sides ), static fn ( string $id ): bool => '' !== $id );
		if ( count( $placements ) !== count( array_unique( $placements ) ) ) {
			$problems[] = InvalidReason::DUPLICATE_PLACEMENT;
		}
		$keys = array_map( static fn ( SideInput $side ): string => $side->key, $sides );
		if ( count( $keys ) !== count( array_unique( $keys ) ) ) {
			$problems[] = InvalidReason::DUPLICATE_SIDE_KEY;
		}

		return new self( $sides, $problems[0] ?? null, $first );
	}

	/**
	 * The posted `pinned` value of a side that stays on one template version.
	 */
	public const PINNED = '1';

	/**
	 * The posted row's `CanvasRatio` choice.
	 */
	public const CANVAS_RATIO = 'canvas_ratio';

	/**
	 * Put before a key of digits alone, which PHP would read back from the
	 * posted `pixonaut_designs[<key>]` as an integer rather than the key.
	 */
	public const NUMERIC_KEY_PREFIX = 'side-';

	/**
	 * A side key from what was typed: lower case, other characters as `-`,
	 * digits alone prefixed. Empty when nothing usable was typed.
	 *
	 * @param string $typed What the store manager entered.
	 */
	public static function key_from( string $typed ): string {
		$key = trim( (string) preg_replace( '/[^a-z0-9_-]+/', '-', strtolower( $typed ) ), '-' );
		if ( ctype_digit( $key ) ) {
			return self::NUMERIC_KEY_PREFIX . substr( $key, 0, self::KEY_MAX_LENGTH - strlen( self::NUMERIC_KEY_PREFIX ) );
		}
		return substr( $key, 0, self::KEY_MAX_LENGTH );
	}

	/**
	 * @param array<mixed> $row      One posted side.
	 * @param int          $index    Its position among the sides kept so far.
	 * @param string[]     $problems Problems found so far; this side's are added.
	 */
	private static function side( array $row, int $index, array &$problems ): SideInput {
		$placement = self::text( $row, 'placement' );
		$key       = self::key_from( self::text( $row, 'key' ) );
		if ( '' === $key ) {
			$key = self::key_from( $placement );
		}
		if ( '' === $key ) {
			// The key a lone side always had, so a product linked before sides could be added keeps it.
			$key = 0 === $index ? ProductLinker::SINGLE_SIDE_KEY : ProductLinker::SINGLE_SIDE_KEY . '-' . ( $index + 1 );
		}

		$sizes        = array();
		$default_at   = null;
		$had_unusable = false;
		$start_row    = self::text( $row, 'start_size' );
		$start        = 0;
		foreach ( is_array( $row['sizes'] ?? null ) ? $row['sizes'] : array() as $row_key => $size_row ) {
			if ( ! is_array( $size_row ) ) {
				continue;
			}
			if ( count( $sizes ) === self::MAX_SIZES ) {
				$problems[] = InvalidReason::TOO_MANY_PRINT_SIZES;
				break;
			}
			$starts = (string) $row_key === $start_row;
			if ( self::SIZE_CUSTOM !== self::text( $size_row, 'kind' ) ) {
				// One default entry is the whole of it; a second adds nothing.
				if ( null === $default_at ) {
					$default_at = count( $sizes );
					$sizes[]    = null;
				}
				$start = $starts ? $default_at : $start;
				continue;
			}
			$size = self::custom_size( $size_row );
			if ( null === $size ) {
				$problems[]   = InvalidReason::INVALID_PRINT_SIZE;
				$had_unusable = true;
				continue;
			}
			$start   = $starts ? count( $sizes ) : $start;
			$sizes[] = $size;
		}

		$template = self::text( $row, 'template' );
		$pinned   = self::PINNED === self::text( $row, 'pinned' );
		$parsed   = TemplateId::parse( $template );
		$version  = self::text( $row, 'version' );
		// A side stays on a version up to the one the template field lists, the latest the screen knew.
		if ( $pinned && null !== $parsed && ctype_digit( $version ) && (int) $version > 0 && (int) $version <= $parsed->version ) {
			$template = (string) new TemplateId( $parsed->base_id, (int) $version );
		}

		return new SideInput(
			$key,
			mb_substr( self::text( $row, 'label' ), 0, self::LABEL_MAX_LENGTH ),
			$placement,
			$template,
			self::text( $row, 'template_name' ),
			array() === $sizes && ! $had_unusable ? array( null ) : $sizes,
			$start,
			in_array( self::text( $row, 'mode' ), Side::MODES, true ) ? self::text( $row, 'mode' ) : Side::PERSONALISED,
			FormGroups::from_array( $row['form_groups'] ?? null ),
			$pinned && null !== $parsed,
			$parsed?->version,
			CanvasRatio::from_posted( self::text( $row, self::CANVAS_RATIO ) ),
		);
	}

	/**
	 * A copy with the sides replaced, its problems kept.
	 *
	 * @param SideInput[] $sides The sides, in the same order.
	 * @phpstan-param list<SideInput> $sides
	 */
	public function with_sides( array $sides ): self {
		return new self( $sides, $this->problem, $this->first_problem );
	}

	/**
	 * A custom size row as a size; its label defaults to its millimetres.
	 *
	 * @param array<mixed> $row `width`, `height` (millimetres, `.` or `,` as the decimal mark) and `label`.
	 */
	private static function custom_size( array $row ): ?PrintSize {
		$width  = self::millimetres( self::text( $row, 'width' ) );
		$height = self::millimetres( self::text( $row, 'height' ) );
		if ( null === $width || null === $height || ! PrintSize::fits( $width, $height ) ) {
			return null;
		}
		$label = mb_substr( self::text( $row, 'label' ), 0, self::LABEL_MAX_LENGTH );
		return new PrintSize( $width, $height, '' === $label ? PrintSize::label_for( $width, $height ) : $label );
	}

	private static function millimetres( string $typed ): ?float {
		$typed = str_replace( ',', '.', $typed );
		return is_numeric( $typed ) ? (float) $typed : null;
	}

	/**
	 * @param array<mixed> $row A posted side.
	 */
	private static function is_empty_row( array $row ): bool {
		foreach ( array( 'key', 'label', 'placement', 'template' ) as $field ) {
			if ( '' !== self::text( $row, $field ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param array<mixed> $row   A posted row.
	 * @param string       $field One of its fields.
	 */
	private static function text( array $row, string $field ): string {
		return is_string( $row[ $field ] ?? null ) ? trim( $row[ $field ] ) : '';
	}
}
