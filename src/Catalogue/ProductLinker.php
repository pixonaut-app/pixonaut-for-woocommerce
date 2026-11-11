<?php
/**
 * Links a product to a Pixonaut blank and its sides.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use Pixonaut\WooCommerce\Api\Catalogue;
use Pixonaut\WooCommerce\Api\Designs;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\ErrorCode;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Pixonaut as Sdk;
use Pixonaut\WooCommerce\Vendor\Psr\Log\LoggerInterface;

/**
 * Turns what the store manager chose into a `ProductConfig`, checked with
 * Pixonaut. A configuration that fails a check is still returned, marked
 * invalid with the reason, so the choice is kept and the product cannot be
 * bought until it is fixed. A side keeps only the print sizes Pixonaut judged
 * legal; a menu Pixonaut could not judge is kept as entered, unsold, for the
 * next save to check. A standing side of a sellable configuration is given
 * the standing design Pixonaut finds or creates for it, approved: one per
 * row of print areas on a blank whose areas change with an option.
 */
final class ProductLinker {

	/**
	 * Key of a side the store manager named no key or placement for, as the
	 * one side of a product linked before it could have several.
	 */
	public const SINGLE_SIDE_KEY = 'design';

	private const NOT_FOUND_STATUS = 404;

	/**
	 * Refusals of a standing design that say the store's key may not make one.
	 */
	private const STANDING_FORBIDDEN = array( ErrorCode::FORBIDDEN, ErrorCode::SECRET_KEY_REQUIRED );

	/**
	 * Statuses of a standing design refused for what it names: its template
	 * version, placement or size (`400`, `422`).
	 */
	private const DESIGN_REFUSED_STATUSES = array( 400, 422 );

	/**
	 * Finds a standing side's designs on a blank whose print areas vary.
	 *
	 * @var StandingRows
	 */
	private readonly StandingRows $rows;

	/**
	 * The constraints read in this check, by template, blank and placement,
	 * so a side's default size and its misfit sizes are read once.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $constraints = array();

	/**
	 * @param Catalogue       $catalogue Checks blanks, templates and print sizes.
	 * @param Designs         $designs   Finds standing designs.
	 * @param Sdk             $pixonaut  Reads standing designs.
	 * @param LoggerInterface $logger    Logs a standing design Pixonaut would not give, which the notice names by reason alone.
	 */
	public function __construct(
		private readonly Catalogue $catalogue,
		private readonly Designs $designs,
		Sdk $pixonaut,
		private readonly LoggerInterface $logger,
	) {
		$this->rows = new StandingRows( $catalogue, $designs, $pixonaut, $logger );
	}

	/**
	 * @param ProductConfig $current     What the product has now; its overrides are kept.
	 * @param bool          $personalise Whether customers personalise the product.
	 * @param string        $blank_id    The blank, or empty for a template-only product.
	 * @param PostedSides   $posted      The sides as entered. Without a blank only the first counts.
	 */
	public function link( ProductConfig $current, bool $personalise, string $blank_id, PostedSides $posted ): ProductConfig {
		$this->constraints = array();
		if ( ! $personalise ) {
			// The link is kept, so switching personalisation back on restores it.
			return $this->config( $current, false, $current->blank_id, $current->blank_name, $current->sides, null );
		}

		$posted = self::following( $current, $posted );
		$linked = '' === $blank_id
			? $this->template_only( $current, $posted->sides[0] ?? null, $posted->first_problem )
			: $this->checked( $current, $blank_id, $posted->sides, $posted->problem );
		return self::valued( $this->standing( $this->live( self::versioned( $linked, $current, $posted->sides ) ), $current ), $current, $posted->sides );
	}

	/**
	 * Each personalised side with its size per attribute value as entered,
	 * or, where none was entered, as the side with the same key had it. A
	 * standing side is printed at the size it starts on, so it has none.
	 *
	 * @param ProductConfig $linked  The configuration as checked.
	 * @param ProductConfig $current What the product had.
	 * @param SideInput[]   $inputs  The sides as entered.
	 * @phpstan-param list<SideInput> $inputs
	 */
	private static function valued( ProductConfig $linked, ProductConfig $current, array $inputs ): ProductConfig {
		$entered = array();
		foreach ( $inputs as $input ) {
			$entered[ $input->key ] = $input->value_sizes;
		}
		$had = array();
		foreach ( $current->sides as $side ) {
			$had[ $side->key ] = $side->value_sizes;
		}
		$sides = array();
		foreach ( $linked->sides as $side ) {
			$sizes   = $entered[ $side->key ] ?? $had[ $side->key ] ?? null;
			$sides[] = $side->with_value_sizes( $side->is_standing() || null === $sizes || $sizes->is_empty() ? null : $sizes );
		}
		return $linked->with_sides( $sides );
	}

	/**
	 * The sides as entered, each side that follows its template on the
	 * latest version the product knows of it: a form opened before a
	 * re-check moved the side on does not move it back.
	 *
	 * @param ProductConfig $current What the product has now.
	 * @param PostedSides   $posted  The sides as entered.
	 */
	private static function following( ProductConfig $current, PostedSides $posted ): PostedSides {
		$inputs = array();
		foreach ( $posted->sides as $input ) {
			$template = TemplateId::parse( $input->template_id );
			$latest   = null === $template ? null : self::latest_known( $current, $template->base_id );
			$inputs[] = $input->pinned || null === $template || null === $latest || $latest <= $template->version
				? $input
				: $input->with_template_id( (string) new TemplateId( $template->base_id, $latest ) );
		}
		return $posted->with_sides( $inputs );
	}

	/**
	 * Each side as checked, staying on its version or following the latest
	 * as entered, and knowing the latest version of its template. A side
	 * moved off the version it had keeps that one as its previous version,
	 * so a design made just before is still accepted.
	 *
	 * @param ProductConfig $linked  The configuration as checked.
	 * @param ProductConfig $current What the product had.
	 * @param SideInput[]   $inputs  The sides as entered, in the order checked.
	 * @phpstan-param list<SideInput> $inputs
	 */
	private static function versioned( ProductConfig $linked, ProductConfig $current, array $inputs ): ProductConfig {
		if ( ! $linked->personalise ) {
			return $linked;
		}
		$sides = array();
		foreach ( $linked->sides as $i => $side ) {
			$before = null;
			foreach ( $current->sides as $had ) {
				if ( $had->key === $side->key && $had->template_base_id === $side->template_base_id && '' !== $side->template_base_id ) {
					$before = $had;
				}
			}
			$previous = $before?->template_version === $side->template_version ? $before?->previous_version : $before?->template_version;
			$listed   = $inputs[ $i ]->listed_version ?? 0;
			$latest   = null === $side->template_version ? null : max( $side->template_version, $listed, self::latest_known( $current, $side->template_base_id ) ?? 0 );
			$sides[]  = $side->with_versions( $inputs[ $i ]->pinned ?? false, $previous, $latest );
		}
		return $linked->with_sides( $sides );
	}

	/**
	 * The latest version of a template the product's sides know of; null
	 * when none of them has it.
	 *
	 * @param ProductConfig $config  The product's configuration.
	 * @param string        $base_id The template.
	 */
	private static function latest_known( ProductConfig $config, string $base_id ): ?int {
		$latest = null;
		foreach ( $config->sides as $side ) {
			if ( $side->template_base_id === $base_id && null !== $side->known_latest_version() ) {
				$latest = max( $latest ?? 0, $side->known_latest_version() );
			}
		}
		return $latest;
	}

	/**
	 * A sellable configuration whose template is paused cannot be sold:
	 * Pixonaut opens no designer for it. Each side's template version is
	 * read (`GET /v1/templates/{id}`), once per version, as the checks
	 * before say nothing of whether it is live; its `status` is the
	 * template's, whichever version is read. The read also says whether the
	 * version offers the customer a field, which each side keeps.
	 *
	 * @param ProductConfig $config The configuration as checked.
	 */
	private function live( ProductConfig $config ): ProductConfig {
		// A product without a size of its own still sells the variations that have one, so its template is checked too.
		if ( ! $config->valid && InvalidReason::MISSING_PRINT_SIZE !== $config->invalid_reason ) {
			return $config;
		}
		$read  = array();
		$sides = array();
		foreach ( $config->sides as $side ) {
			if ( null === $side->template_version ) {
				$sides[] = $side;
				continue;
			}
			$id = (string) new TemplateId( $side->template_base_id, $side->template_version );
			try {
				$read[ $id ] ??= $this->catalogue->template_state( $id );
			} catch ( ApiException $e ) {
				return $config->with_validity( false, self::reason( $e, InvalidReason::UNKNOWN_TEMPLATE ), self::refusal( $e )?->on_side( self::named_side( $config, $side ) ) );
			}
			if ( Catalogue::LIVE !== $read[ $id ]['status'] ) {
				return $config->with_validity( false, InvalidReason::TEMPLATE_NOT_LIVE, InvalidDetail::of( self::named_side( $config, $side ) ) );
			}
			$side = $side->with_offers_properties( $read[ $id ]['offers_properties'] );
			// The name Pixonaut gives now: for a side entered without one (by an ability), or a template renamed since.
			$sides[] = $side->with_template_name( $read[ $id ]['name'] );
		}
		return $config->with_sides( $sides );
	}

	/**
	 * Gives each standing side of a sellable configuration its standing
	 * design (`POST /v1/designs/standing`, approved): the one for its
	 * template version on its blank's placement at the size it starts on,
	 * which Pixonaut finds again on every save while none of them changed,
	 * and makes anew once one did. On a blank whose print areas change with
	 * an option, Pixonaut refuses that with `variant_required`, and the side
	 * is given a design per row instead (`StandingRows`), but none for a row
	 * the template cannot be printed at, which it names among its misfit
	 * sizes; a side the template fits at no row cannot be sold. A refusal
	 * leaves the configuration unsellable, saying why.
	 *
	 * @param ProductConfig      $config The configuration as checked.
	 * @param ProductConfig|null $before What the product held before, whose sides' row previews are kept for designs found again.
	 */
	private function standing( ProductConfig $config, ?ProductConfig $before = null ): ProductConfig {
		if ( ! $config->valid ) {
			return self::keeping_adjusted( $config, $before );
		}
		if ( array() === $config->standing_sides() ) {
			return $config;
		}
		$sides = array();
		foreach ( $config->sides as $side ) {
			if ( ! $side->is_standing() || null === $side->template_version ) {
				$sides[] = $side;
				continue;
			}
			$template_id = (string) new TemplateId( $side->template_base_id, $side->template_version );
			try {
				$sides[] = $this->standing_side( $template_id, $config->blank_id, $side, self::rows_of( $before, $side->key ) );
			} catch ( ApiException $e ) {
				$this->logger->warning(
					sprintf( 'Pixonaut gave no standing design for side "%s": %s', $side->key, $e->getMessage() ),
					array(
						'side'        => $side->key,
						'template_id' => $template_id,
						'blank_id'    => $config->blank_id,
						'placement'   => $side->placement_id,
						'status'      => $e->status,
						'code'        => $e->errorCode,
					)
				);
				return self::keeping_adjusted( $config, $before )->with_validity( false, self::standing_reason( $e ), InvalidDetail::of( self::named_side( $config, $side ), null, $e->errorCode ) );
			}
		}
		$standing = $config->with_sides( $sides );
		foreach ( $sides as $side ) {
			if ( $side->is_standing() && null === $side->standing_design_id && array() === $side->standing_rows && array() !== $side->misfit_sizes ) {
				return $standing->with_validity( false, InvalidReason::NO_FITTING_SIZE, InvalidDetail::of( self::named_side( $config, $side ) ) );
			}
		}
		return $standing;
	}

	/**
	 * A configuration that could not be given its standing designs, its
	 * standing sides holding the rows laid out by hand before that still
	 * hold for them, so a save or check that fails for now loses none of the
	 * store manager's adjustments; the next one that succeeds keeps them.
	 *
	 * @param ProductConfig      $config The configuration as checked.
	 * @param ProductConfig|null $before What the product held before.
	 */
	private static function keeping_adjusted( ProductConfig $config, ?ProductConfig $before ): ProductConfig {
		$sides = array();
		$kept  = false;
		foreach ( $config->sides as $side ) {
			$adjusted = array_values(
				array_filter(
					self::rows_of( $before, $side->key ),
					static fn ( StandingRow $row ): bool => null !== $row->adjusted && $row->adjusted->holds( $side->template_base_id, $config->blank_id, $side->placement_id )
				)
			);
			$kept     = $kept || ( $side->is_standing() && array() !== $adjusted );
			$sides[]  = $side->is_standing() && array() !== $adjusted ? $side->with_standing_rows( $adjusted ) : $side;
		}
		return $kept ? $config->with_sides( $sides ) : $config;
	}

	/**
	 * A standing side with its design, or its design per row.
	 *
	 * @param string        $template_id `<baseId>@<version>`.
	 * @param string|null   $blank_id    The blank; null for a product without one.
	 * @param Side          $side        The standing side.
	 * @param StandingRow[] $known     The rows the side held before.
	 * @phpstan-param list<StandingRow> $known
	 *
	 * @throws ApiException When Pixonaut refused or could not be reached.
	 */
	private function standing_side( string $template_id, ?string $blank_id, Side $side, array $known ): Side {
		try {
			return $side->with_standing_design( $this->designs->standing( $template_id, $blank_id, $side->placement_id, $side->standing_size() ) );
		} catch ( ApiException $e ) {
			if ( ErrorCode::VARIANT_REQUIRED !== $e->errorCode || null === $blank_id ) {
				throw $e;
			}
			$varies_by = array_values( array_filter( is_array( $e->details['areasVaryBy'] ?? null ) ? $e->details['areasVaryBy'] : array(), 'is_string' ) );
			$found     = $this->rows->designs( $template_id, $blank_id, $side, $varies_by, $known );
			return $side->with_standing_rows( $found['rows'] )->with_misfit_sizes( $found['misfits'] );
		}
	}

	/**
	 * The rows a side of a configuration held; none without one.
	 *
	 * @param ProductConfig|null $config A configuration.
	 * @param string             $key    The side's key.
	 * @return list<StandingRow>
	 */
	private static function rows_of( ?ProductConfig $config, string $key ): array {
		foreach ( null === $config ? array() : $config->sides as $side ) {
			if ( $side->key === $key ) {
				return array_values( $side->standing_rows );
			}
		}
		return array();
	}

	/**
	 * Why a standing design was refused, as the product screen says it.
	 *
	 * @param ApiException $error What Pixonaut answered.
	 */
	private static function standing_reason( ApiException $error ): string {
		if ( in_array( $error->errorCode, self::STANDING_FORBIDDEN, true ) ) {
			return InvalidReason::STANDING_FORBIDDEN;
		}
		if ( InvalidReason::UNKNOWN_PLACEMENT === $error->errorCode ) {
			return InvalidReason::UNKNOWN_PLACEMENT;
		}
		// Anything else that is not a refusal of the design itself is checked again.
		return in_array( $error->status, self::DESIGN_REFUSED_STATUSES, true ) ? InvalidReason::STANDING_REFUSED : InvalidReason::CHECK_FAILED;
	}

	/**
	 * Checks a stored configuration again, as its template or blank changed
	 * in Pixonaut. Each side follows its template's live version, the one it
	 * leaves kept as the previous version, unless it is pinned to its own
	 * version, where it stays; a template no longer live makes
	 * the configuration unsellable without asking Pixonaut anything more.
	 * When the check finds a problem, every side keeps its whole menu as
	 * pending, so the next check, once the problem is gone, sells it again.
	 * A problem in what the store manager entered (`InvalidReason::INPUT`)
	 * stays until the product is saved again.
	 *
	 * @param ProductConfig     $stored The configuration as stored.
	 * @param array<string,int> $live   The live templates' versions, by base id.
	 * @return ProductConfig `invalid_reason` is `check_failed` when Pixonaut could not be asked; nothing else is to be kept then.
	 */
	public function recheck( ProductConfig $stored, array $live ): ProductConfig {
		$this->constraints = array();
		if ( ! $stored->personalise ) {
			return $stored;
		}
		$sides = array();
		foreach ( $stored->sides as $side ) {
			$sides[] = isset( $live[ $side->template_base_id ] ) ? $side->rolled_to( $live[ $side->template_base_id ] ) : $side;
		}
		$rolled = $stored->with_sides( $sides );
		foreach ( $sides as $side ) {
			if ( '' !== $side->template_base_id && ! isset( $live[ $side->template_base_id ] ) ) {
				return $rolled->with_validity( false, InvalidReason::TEMPLATE_NOT_LIVE, InvalidDetail::of( self::named_side( $rolled, $side ) ) );
			}
		}

		$inputs  = array();
		$problem = in_array( $stored->invalid_reason, InvalidReason::INPUT, true ) ? $stored->invalid_reason : null;
		foreach ( $sides as $side ) {
			$menu     = $side->menu();
			$inputs[] = new SideInput( $side->key, $side->typed_label(), $side->placement_id ?? '', null === $side->template_version ? '' : (string) new TemplateId( $side->template_base_id, $side->template_version ), $side->template_name ?? '', $menu, $side->start_size, $side->mode, $side->form_groups, $side->pinned, null, $side->draw_at_template_ratio, $side->value_sizes );
			if ( array() === $menu && null !== $side->placement_id ) {
				// The side sells nothing and its menu is gone: only the last save can say why,
				// unless Pixonaut did not answer it, which would keep the product retrying.
				$saved     = InvalidReason::CHECK_FAILED === $stored->invalid_reason ? null : $stored->invalid_reason;
				$problem ??= $saved ?? InvalidReason::INVALID_PRINT_SIZE;
			}
		}
		// A problem the last save found keeps what it said of where.
		$problem_detail = null !== $problem && $problem === $stored->invalid_reason ? $stored->invalid_detail : null;
		$checked        = null === $stored->blank_id
			? $this->template_only( $rolled, $inputs[0] ?? null, $problem, $problem_detail )
			: $this->checked( $rolled, $stored->blank_id, $inputs, $problem, $problem_detail );

		$kept = array();
		foreach ( $checked->sides as $i => $side ) {
			$before = $sides[ $i ] ?? null;
			$menu   = $checked->valid || array() === $inputs[ $i ]->sizes ? null : $inputs[ $i ]->sizes;
			// What was read of the template version stays while the side keeps it; a version it rolled to is read on the next save.
			$kept[] = $side->with( $side->template_version, $before?->previous_version, $checked->valid ? $side->print_sizes : array(), $menu, null === $menu ? $side->start_size : $inputs[ $i ]->start )->with_offers_properties( $before?->offers_properties )
				->with_versions( $before->pinned ?? false, $before?->previous_version, $before?->latest_version );
		}
		return self::valued( $this->standing( $checked->with_sides( $kept ), $stored ), $stored, $inputs );
	}

	/**
	 * The sides checked against the blank and its placements. A side
	 * Pixonaut could not check makes the configuration `check_failed`
	 * whatever else is wrong with it, so it is checked again.
	 *
	 * @param ProductConfig      $current  What the product has now; its overrides are kept.
	 * @param string             $blank_id The blank.
	 * @param SideInput[]        $inputs   The sides to check.
	 * @param string|null        $problem  Why the sides cannot be sold whatever Pixonaut says, if anything.
	 * @param InvalidDetail|null $problem_detail Where that problem is, when known.
	 * @phpstan-param list<SideInput> $inputs
	 */
	private function checked( ProductConfig $current, string $blank_id, array $inputs, ?string $problem, ?InvalidDetail $problem_detail = null ): ProductConfig {
		try {
			$blank = $this->catalogue->blank( $blank_id );
		} catch ( ApiException $e ) {
			// The choice is kept, unchecked, so updating again once Pixonaut answers retries it.
			$kept = $current->blank_id === $blank_id ? $current : null;
			return $this->config( $current, true, $blank_id, $kept?->blank_name, $this->unchecked( $kept, $inputs ), self::reason( $e, InvalidReason::UNKNOWN_BLANK ), self::refusal( $e ) );
		}
		$blank_name = is_string( $blank['name'] ?? null ) ? $blank['name'] : null;
		if ( self::is_deleted( $blank ) ) {
			// Nothing is made on a deleted blank; the choice is kept for when it is restored. A problem
			// in what was entered is kept over the deletion, which the panel reads from the blank,
			// so restoring it does not sell what only a save can fix.
			$kept = $current->blank_id === $blank_id ? $current : null;
			return $this->config( $current, true, $blank_id, $blank_name, $this->unchecked( $kept, $inputs ), $problem ?? InvalidReason::BLANK_DELETED, $problem_detail );
		}

		$placements = array();
		foreach ( SearchResults::placements( $blank ) as $placement ) {
			$placements[ $placement['id'] ] = $placement['text'];
		}

		// A template can fit a placement at some sizes only where its print area changes with an option.
		$areas_vary = array() !== ( $blank['areasVaryBy'] ?? array() );

		$sides   = array();
		$reasons = array();
		// What each reason is about, by its index in `$reasons`.
		$details = array();
		foreach ( $inputs as $input ) {
			list( $side, $reason, $detail ) = $this->side( $current, $blank_id, $placements, $input, $areas_vary ? $blank : null );
			$sides[]                        = $side;
			if ( null !== $reason ) {
				$reasons[] = $reason;
				$details[] = count( $inputs ) > 1 ? ( $detail ?? new InvalidDetail() )->on_side( $side->label ) : $detail;
			}
		}
		if ( array() === $inputs ) {
			$reasons[] = InvalidReason::MISSING_TEMPLATE;
			$details[] = null;
		}

		$failed = array_search( InvalidReason::CHECK_FAILED, $reasons, true );
		if ( false === $failed && null !== $problem ) {
			// A problem in what was entered, which PostedSides names without a side.
			return $this->config( $current, true, $blank_id, $blank_name, $sides, $problem, $problem_detail );
		}
		$first = false === $failed ? 0 : $failed;
		return $this->config( $current, true, $blank_id, $blank_name, $sides, $reasons[ $first ] ?? null, $details[ $first ] ?? null );
	}

	/**
	 * Without a blank there is no placement to size or check a print against:
	 * one side, its template alone, sold in the sizes the store manager
	 * entered, each judged against the template's ratio. A size taken from
	 * the placement means nothing here, so it is dropped; a side left with
	 * no size cannot be sold, and so cannot one whose entered sizes had a
	 * problem, which is kept until the product is saved again.
	 *
	 * @param ProductConfig      $current What the product has now.
	 * @param SideInput|null     $input   The first side entered.
	 * @param string|null        $problem Why the side cannot be sold whatever Pixonaut says, if anything.
	 * @param InvalidDetail|null $problem_detail Where that problem is, when known.
	 */
	private function template_only( ProductConfig $current, ?SideInput $input, ?string $problem, ?InvalidDetail $problem_detail = null ): ProductConfig {
		$template = null === $input ? null : TemplateId::parse( $input->template_id );
		if ( null === $input || null === $template ) {
			return $this->config( $current, true, null, null, array(), InvalidReason::MISSING_TEMPLATE );
		}
		$name    = '' === $input->template_name ? null : $input->template_name;
		$entered = array();
		// Where each size entered landed in `$entered`, by its index in the menu.
		$entered_at = array();
		foreach ( $input->sizes as $index => $size ) {
			if ( null !== $size ) {
				$entered_at[ $index ] = count( $entered );
				$entered[]            = $size;
			}
		}
		$sized = static fn ( array $sizes, ?array $pending, int $start ): Side => new Side( $input->key, self::label( $input, null, $name ), null, null, $template->base_id, $template->version, null, $name, array_values( $sizes ), null === $pending ? null : array_values( $pending ), $start, $input->mode, form_groups: $input->form_groups, draw_at_template_ratio: $input->draw_at_template_ratio );
		if ( array() === $entered ) {
			return $this->config( $current, true, null, null, array( $sized( array(), null, 0 ) ), $problem ?? InvalidReason::MISSING_PRINT_SIZE, $problem_detail );
		}

		try {
			list( $legal, $legal_at, $reason, $refused, $soft ) = $this->judged( (string) $template, $entered, null, null );
		} catch ( ApiException $e ) {
			// Nothing unchecked is sold; the menu waits for the next save.
			return $this->config( $current, true, null, null, array( $sized( array(), $entered, $entered_at[ $input->start ] ?? 0 ) ), self::reason( $e, InvalidReason::UNKNOWN_TEMPLATE ), self::refusal( $e ) );
		}
		$side = $sized( $legal, null, $legal_at[ $entered_at[ $input->start ] ?? -1 ] ?? 0 )->with_low_resolution( $soft );
		if ( null !== $problem ) {
			return $this->config( $current, true, null, null, array( $side ), $problem, $problem_detail );
		}
		return $this->config( $current, true, null, null, array( $side ), array() === $legal ? $reason ?? InvalidReason::MISSING_PRINT_SIZE : $reason, InvalidDetail::of( null, $refused ) );
	}

	/**
	 * One side checked with Pixonaut: its sizes judged in one call, the legal
	 * ones kept. A side that cannot be checked keeps its menu as entered. A
	 * personalised side on a blank whose print areas change with an option
	 * also knows the sizes its template cannot be printed at; a standing
	 * side learns them from its rows' designs (`standing()`). Until a check
	 * replaces them, a side keeps the misfits it held for the same template
	 * version on the same placement. A personalised side on such a blank
	 * also knows what it prints at per row (`row_sizes()`), so a variation
	 * sold at the placement's default prints at its variant's row, and a
	 * size of its own is judged against that row. A
	 * size Pixonaut refused is kept in the menu as entered, unsold, so the
	 * product screen shows it with the reason.
	 *
	 * @param ProductConfig             $current    What the product has now.
	 * @param string                    $blank_id   The blank.
	 * @param array<string, string>     $placements The blank's placements, names by id.
	 * @param SideInput                 $input      The side as entered.
	 * @param array<string, mixed>|null $varying    The blank, when its print areas change with an option; null otherwise.
	 * @return array{0: Side, 1: string|null, 2: InvalidDetail|null} The side, why it cannot be sold, if it cannot, and the size or Pixonaut's answer that says more.
	 */
	private function side( ProductConfig $current, string $blank_id, array $placements, SideInput $input, ?array $varying = null ): array {
		$template       = TemplateId::parse( $input->template_id );
		$name           = '' === $input->template_name ? null : $input->template_name;
		$placement_id   = '' === $input->placement_id ? null : $input->placement_id;
		$placement_name = null === $placement_id ? null : $placements[ $placement_id ] ?? null;
		$sized          = static fn ( array $sizes, bool $keep_menu = false, int $start = 0 ): Side => new Side( $input->key, self::label( $input, $placement_name, $name ), $placement_id, $placement_name, $template->base_id ?? '', $template?->version, null, $name, array_values( $sizes ), $keep_menu ? $input->sizes : null, $keep_menu ? $input->start : $start, $input->mode, form_groups: $input->form_groups, draw_at_template_ratio: $input->draw_at_template_ratio );

		if ( null === $template ) {
			return array( $sized( array(), true ), InvalidReason::MISSING_TEMPLATE, null );
		}
		if ( null === $placement_id ) {
			return array( $sized( array(), true ), InvalidReason::MISSING_PLACEMENT, null );
		}
		if ( null === $placement_name ) {
			return array( $sized( array(), true ), InvalidReason::UNKNOWN_PLACEMENT, null );
		}
		if ( array() === $input->sizes ) {
			// Every size entered was unusable; PostedSides says why.
			return array( $sized( array() ), null, null );
		}

		$held   = self::held_misfits( $current, $blank_id, $placement_id, $template );
		$reason = null;
		try {
			$sizes = array();
			// Where each size entered landed in `$sizes`, by its index in the menu.
			$checked_at = array();
			foreach ( $input->sizes as $entered => $size ) {
				$size ??= $this->default_size( (string) $template, $blank_id, $placement_id );
				if ( null === $size ) {
					$reason = InvalidReason::UNKNOWN_PLACEMENT;
					continue;
				}
				$checked_at[ $entered ] = count( $sizes );
				$sizes[]                = $size;
			}
			list( $legal, $legal_at, $refused, $refused_size, $soft ) = $this->judged( (string) $template, $sizes, $blank_id, $placement_id );
			$misfits = $held;
			$rows    = array();
			if ( Side::STANDING !== $input->mode ) {
				$misfits = null !== $varying && array() !== $legal
					? MisfitSizes::of( self::placement_of( $this->constraints( (string) $template, $blank_id, $placement_id ) )['sizes'] ?? null )
					: array();
				$rows    = null !== $varying ? $this->row_sizes( (string) $template, $blank_id, $varying, $placement_id ) : array();
			}
		} catch ( ApiException $e ) {
			// Nothing unchecked is sold; the menu waits for the next save.
			return array( $sized( array(), true )->with_misfit_sizes( $held ), self::reason( $e, InvalidReason::UNKNOWN_TEMPLATE ), self::refusal( $e ) );
		}
		if ( null !== $refused ) {
			// The menu stays as entered, so the refused size is shown with why; the product is not sold meanwhile.
			return array( $sized( $legal, true )->with_misfit_sizes( $misfits )->with_row_sizes( $rows )->with_low_resolution( $soft ), $reason ?? $refused, null === $reason ? InvalidDetail::of( null, $refused_size ) : null );
		}
		// A placement with an area per row, none of which any variant sold can be printed at, sells nothing at the default.
		$unfit = null !== $varying && Side::STANDING !== $input->mode && array() === $rows && array() !== PrintAreas::areas( $varying, $placement_id ) ? InvalidReason::VARIANT_SIZE_UNFIT : null;
		// A starting size Pixonaut cannot print leaves the first legal one to start on.
		return array( $sized( $legal, false, $legal_at[ $checked_at[ $input->start ] ?? -1 ] ?? 0 )->with_misfit_sizes( $misfits )->with_row_sizes( $rows )->with_low_resolution( $soft ), $reason ?? $unfit, null );
	}

	/**
	 * Sizes judged by Pixonaut in one call, the legal ones kept in order.
	 *
	 * @param string      $template_id  `<baseId>@<version>`.
	 * @param PrintSize[] $sizes        The sizes to judge.
	 * @param string|null $blank_id     The blank; null to judge against the template alone.
	 * @param string|null $placement_id Its placement; null without a blank.
	 * @phpstan-param list<PrintSize> $sizes
	 * @return array{0: list<PrintSize>, 1: array<int, int>, 2: string|null, 3: string|null, 4: list<LowResolutionSize>} The legal sizes, where each judged size landed among them by its index in `$sizes`, why the first one refused was, its label, and the sizes, legal or not, at which an image of the template prints below its recommended resolution.
	 *
	 * @throws ApiException When Pixonaut refused or could not be reached.
	 */
	private function judged( string $template_id, array $sizes, ?string $blank_id, ?string $placement_id ): array {
		$verdicts = array() === $sizes ? array() : $this->catalogue->check_print_sizes( $template_id, $sizes, $blank_id, $placement_id );
		$legal    = array();
		$legal_at = array();
		$reason   = null;
		$refused  = null;
		$soft     = array();
		foreach ( $sizes as $i => $size ) {
			$verdict = $verdicts[ $i ];
			if ( array() !== $verdict->low_resolution ) {
				$soft[] = new LowResolutionSize( $size, $verdict->low_resolution, ! $verdict->legal && InvalidReason::LOW_RESOLUTION === $verdict->reason );
			}
			if ( $verdict->legal ) {
				$legal_at[ $i ] = count( $legal );
				$legal[]        = $size;
				continue;
			}
			$refused ??= $size->described();
			$reason  ??= $verdict->reason ?? InvalidReason::CHECK_FAILED;
		}
		return array( $legal, $legal_at, $reason, $refused, $soft );
	}

	/**
	 * The sides as entered, unchecked: no sizes sold, the menu kept as
	 * entered, for when the blank cannot be read. A side whose placement and
	 * template are the ones the product already has keeps its cached names
	 * for display, and the sizes its template was found not to fit.
	 *
	 * @param ProductConfig|null $current What the product has now, when it is linked to the same blank.
	 * @param SideInput[]        $inputs  The sides as entered.
	 * @phpstan-param list<SideInput> $inputs
	 * @return list<Side>
	 */
	private function unchecked( ?ProductConfig $current, array $inputs ): array {
		$sides = array();
		foreach ( $inputs as $input ) {
			$template     = TemplateId::parse( $input->template_id );
			$base_id      = $template->base_id ?? '';
			$version      = $template?->version;
			$placement_id = '' === $input->placement_id ? null : $input->placement_id;
			$same         = null;
			foreach ( null === $current ? array() : $current->sides as $side ) {
				if ( $side->placement_id === $placement_id && $side->template_base_id === $base_id && $side->template_version === $version ) {
					$same = $side;
				}
			}
			$name    = '' === $input->template_name ? $same?->template_name : $input->template_name;
			$sides[] = new Side( $input->key, self::label( $input, $same?->placement_name, $name ), $placement_id, $same?->placement_name, $base_id, $version, $same?->previous_version, $name, array(), $input->sizes, $input->start, $input->mode, form_groups: $input->form_groups, misfit_sizes: $same->misfit_sizes ?? array(), draw_at_template_ratio: $input->draw_at_template_ratio );
		}
		return $sides;
	}

	/**
	 * The size the template prints at on the placement when the customer
	 * names none: its ratio fitted into the placement's default box.
	 *
	 * @param string $template_id  `<baseId>@<version>`.
	 * @param string $blank_id     The blank.
	 * @param string $placement_id The placement.
	 * @return PrintSize|null Null when Pixonaut answered without a usable box.
	 *
	 * @throws ApiException When Pixonaut refused or could not be reached.
	 */
	private function default_size( string $template_id, string $blank_id, string $placement_id ): ?PrintSize {
		$placement = self::placement_of( $this->constraints( $template_id, $blank_id, $placement_id ) );
		$size      = PrintSize::from_array(
			array(
				'widthMm'  => $placement['printWidthMm'] ?? null,
				'heightMm' => $placement['printHeightMm'] ?? null,
			)
		);
		return null === $size ? null : new PrintSize( $size->width_mm, $size->height_mm, PrintSize::label_for( $size->width_mm, $size->height_mm ), true );
	}

	/**
	 * What a side prints at on each row of the blank's print areas when it
	 * names no size: the template's ratio fitted into the row's
	 * usual box, or the row's area where a print covers it whole, as
	 * Pixonaut sizes a print naming no size there. A row Pixonaut names
	 * among the template's misfit sizes has no size: it cannot be printed.
	 * A row the placement has no area for is left out, so its variants are
	 * not sold.
	 *
	 * @param string               $template_id  `<baseId>@<version>`.
	 * @param string               $blank_id     The blank.
	 * @param array<string, mixed> $blank        The blank as read.
	 * @param string               $placement_id The side's placement.
	 * @return list<RowSize>
	 *
	 * @throws ApiException When Pixonaut refused or could not be reached.
	 */
	private function row_sizes( string $template_id, string $blank_id, array $blank, string $placement_id ): array {
		$constraints = $this->constraints( $template_id, $blank_id, $placement_id );
		$ratio       = is_array( $constraints['ratio'] ?? null ) ? $constraints['ratio'] : array();
		$ratio_w     = is_numeric( $ratio['w'] ?? null ) ? (float) $ratio['w'] : 0.0;
		$ratio_h     = is_numeric( $ratio['h'] ?? null ) ? (float) $ratio['h'] : 0.0;
		$misfits     = MisfitSizes::of( self::placement_of( $constraints )['sizes'] ?? null );
		$areas       = PrintAreas::areas( $blank, $placement_id );
		$full        = PrintAreas::covers_area( $blank, $placement_id );
		$varies_by   = array_values( array_filter( is_array( $blank['areasVaryBy'] ?? null ) ? $blank['areasVaryBy'] : array(), 'is_string' ) );
		$rows        = array();
		foreach ( PrintAreas::rows( $this->catalogue->variants( $blank_id ), $varies_by ) as $found ) {
			$area = PrintAreas::area_of( $areas, $found['row'] );
			$size = null === $area ? null : RowSize::fitted( $area, $full, $ratio_w, $ratio_h );
			if ( null === $area || null === $size ) {
				continue;
			}
			$fits   = ! in_array( MisfitSizes::value( $found['row'] ), $misfits, true );
			$rows[] = new RowSize( $found['row'], $found['variants'], (float) $area['widthMm'], (float) $area['heightMm'], $full, $fits ? $size : null );
		}
		return $rows;
	}

	/**
	 * The misfit sizes a side of the product held for a template version on
	 * a blank's placement; none when no side held that one.
	 *
	 * @param ProductConfig   $current      What the product has now.
	 * @param string          $blank_id     The blank.
	 * @param string|null     $placement_id The placement.
	 * @param TemplateId|null $template     The template version.
	 * @return list<string>
	 */
	private static function held_misfits( ProductConfig $current, string $blank_id, ?string $placement_id, ?TemplateId $template ): array {
		if ( null === $template || $current->blank_id !== $blank_id ) {
			return array();
		}
		foreach ( $current->sides as $side ) {
			if ( $side->placement_id === $placement_id && $side->template_base_id === $template->base_id && $side->template_version === $template->version ) {
				return $side->misfit_sizes;
			}
		}
		return array();
	}

	/**
	 * The template's constraints on a placement, read once per check.
	 *
	 * @param string $template_id  `<baseId>@<version>`.
	 * @param string $blank_id     The blank.
	 * @param string $placement_id The placement.
	 * @return array<string, mixed>
	 *
	 * @throws ApiException When Pixonaut refused or could not be reached.
	 */
	private function constraints( string $template_id, string $blank_id, string $placement_id ): array {
		return $this->constraints[ (string) wp_json_encode( array( $template_id, $blank_id, $placement_id ) ) ] ??= $this->catalogue->constraints( $template_id, $blank_id, $placement_id );
	}

	/**
	 * The `placement` of a constraints answer; empty without one.
	 *
	 * @param array<string, mixed> $constraints The answer.
	 * @return array<mixed>
	 */
	private static function placement_of( array $constraints ): array {
		return is_array( $constraints['placement'] ?? null ) ? $constraints['placement'] : array();
	}

	/**
	 * The side's label: the one entered, else what it follows (`Side::followed_label()`).
	 *
	 * @param SideInput   $input          The side as entered.
	 * @param string|null $placement_name The placement's name.
	 * @param string|null $template_name  The template's name.
	 */
	private static function label( SideInput $input, ?string $placement_name, ?string $template_name ): string {
		return '' !== $input->label ? $input->label : Side::followed_label( $placement_name, $template_name, $input->key );
	}

	/**
	 * Whether Pixonaut answered a blank that is deleted: it still reads one
	 * by id, with when and by whom it was deleted, until it is restored.
	 *
	 * @param array<string, mixed> $blank A `GET /v1/blanks/{id}` answer.
	 */
	public static function is_deleted( array $blank ): bool {
		return null !== ( $blank['deleted'] ?? null );
	}

	/**
	 * @param ApiException $error     What Pixonaut answered.
	 * @param string       $not_found The reason when it answered `404`.
	 */
	private static function reason( ApiException $error, string $not_found ): string {
		if ( self::NOT_FOUND_STATUS === $error->status ) {
			return $not_found;
		}
		return InvalidReason::UNKNOWN_PLACEMENT === $error->errorCode ? InvalidReason::UNKNOWN_PLACEMENT : InvalidReason::CHECK_FAILED;
	}

	/**
	 * The error code Pixonaut answered, which says more than the reason does.
	 *
	 * @param ApiException $error What Pixonaut answered.
	 */
	private static function refusal( ApiException $error ): ?InvalidDetail {
		return InvalidDetail::of( null, null, $error->errorCode );
	}

	/**
	 * A side's label, when the configuration has more than one side to
	 * tell it from; null otherwise.
	 *
	 * @param ProductConfig $config The configuration.
	 * @param Side          $side   One of its sides.
	 */
	private static function named_side( ProductConfig $config, Side $side ): ?string {
		return count( $config->sides ) > 1 ? $side->label : null;
	}

	/**
	 * @param ProductConfig      $current     Overrides, the printshop and the canvas ratio, and the views and starting view on the same blank, come from here.
	 * @param bool               $personalise Whether customers personalise the product.
	 * @param string|null        $blank_id    The blank.
	 * @param string|null        $blank_name  Its cached name.
	 * @param Side[]             $sides       The sides.
	 * @param string|null        $reason      Why it cannot be sold; null when it can.
	 * @param InvalidDetail|null $detail      Where, and what Pixonaut answered, when known.
	 * @phpstan-param list<Side> $sides
	 */
	private function config( ProductConfig $current, bool $personalise, ?string $blank_id, ?string $blank_name, array $sides, ?string $reason, ?InvalidDetail $detail = null ): ProductConfig {
		return new ProductConfig(
			$personalise,
			$current->override,
			$blank_id,
			$blank_name,
			$sides,
			$current->layout,
			$current->approval_mode,
			null === $reason,
			$reason,
			// View ids are the blank's own: another blank shows all of its views until some are chosen.
			$blank_id === $current->blank_id ? $current->views : array(),
			$current->form_layout,
			$blank_id === $current->blank_id ? $current->initial_view : null,
			$current->printshop_id,
			$current->draw_at_template_ratio,
			$current->option_groups,
			$current->group_numbers,
			$current->split_listing,
			null === $reason ? null : $detail,
		);
	}
}
