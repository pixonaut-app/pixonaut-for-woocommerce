<?php
/**
 * Judges the print sizes variations are given of their own.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use Pixonaut\WooCommerce\Api\Catalogue;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use WC_Product;

/**
 * A variation's own size of a side, or else its attribute value's size the
 * product gives (`ValueSizes`), is judged for what the variation sells:
 * on a blank whose print areas change with an option, against its blank
 * variant's row (its area here, its template's ratio by Pixonaut, which
 * judges a size naming a placement against the placement's largest row
 * only); else by Pixonaut against the side's placement. The verdict is kept
 * with the size (`VariationSize`), and judged again whenever the product,
 * the variation or what Pixonaut holds changes.
 */
final class VariationSizes {

	/**
	 * Pixonaut's verdicts in this run, by what was asked, so variations
	 * sharing a value's size ask once.
	 *
	 * @var array<string, string|null>
	 */
	private array $verdicts = array();

	/**
	 * The first failure to ask Pixonaut in this run: after it, no size is
	 * asked about until `forget()`, so an outage costs one timeout.
	 *
	 * @var ApiException|null
	 */
	private ?ApiException $failure = null;

	/**
	 * @param Catalogue $catalogue Judges sizes.
	 */
	public function __construct( private readonly Catalogue $catalogue ) {
	}

	/**
	 * Starts a new run: Pixonaut is asked again, as what it holds may have changed.
	 */
	public function forget(): void {
		$this->verdicts = array();
		$this->failure  = null;
	}

	/**
	 * Judges a variation's own sizes, and its attribute values' sizes for
	 * the other sides, and stores them on it. A size judged before for the
	 * same variant, template version, blank and placement keeps its verdict
	 * without asking Pixonaut again.
	 *
	 * @param WC_Product                    $variation The variation.
	 * @param array<string, PrintSize|null> $sizes     Its own sizes by side key; null or absent for the product's.
	 */
	public function save( WC_Product $variation, array $sizes ): VariationSize {
		$failed        = $this->failure;
		$judged        = $this->judged( $variation, $sizes, VariationSize::stored_on( self::fresh( $variation ) ), $failed );
		$this->failure = $failed;
		$judged->save_to( $variation );
		return $judged;
	}

	/**
	 * Judges again the sizes a variation, or each variation of a product,
	 * has of its own or by its attribute values, as what they are sold
	 * under, or the product's sizes per value, may have changed. A
	 * verdict is stored only while the variation still holds the sizes it
	 * was judged from, so a store manager's save made meanwhile stands.
	 * Once Pixonaut cannot be asked, the rest keep the verdicts they had.
	 *
	 * @param WC_Product $product A variable product or a variation.
	 * @throws ApiException When Pixonaut could not judge a size, after storing what was judged: the caller checks again later.
	 */
	public function rejudge( WC_Product $product ): void {
		$variations = $product->is_type( 'variation' ) ? array( $product ) : array_filter( array_map( 'wc_get_product', $product->get_children() ), static fn ( mixed $child ): bool => $child instanceof WC_Product );
		$failed     = $this->failure;
		foreach ( $variations as $variation ) {
			$fresh    = self::fresh( $variation );
			$document = VariationSize::document_on( $fresh );
			$stored   = VariationSize::from_document( $document );
			$judged   = $this->judged( $fresh, $stored->own(), $stored, $failed );
			if ( $judged->to_array() === $stored->to_array() ) {
				continue;
			}
			if ( null === $document ) {
				// None was stored to write over: a size saved meanwhile is judged by its own save.
				$judged->add_to( $fresh );
			} else {
				$judged->replace_on( $fresh, $document );
			}
		}
		$this->failure = $failed;
		if ( null !== $failed ) {
			throw $failed;
		}
	}

	/**
	 * A variation's sizes judged, its own or else its attribute value's; a
	 * side it no longer sells personalised is dropped. A size judged before for the same basis keeps its verdict
	 * unasked, unless Pixonaut could not judge it then. A size Pixonaut
	 * cannot judge now keeps the verdict it had, else it is refused as
	 * `check_failed`, so it is not sold unjudged; after the first failure
	 * Pixonaut is not asked again in the same run.
	 *
	 * @param WC_Product                    $variation The variation.
	 * @param array<string, PrintSize|null> $sizes     Its own sizes by side key.
	 * @param VariationSize                 $stored    What it held, whose verdicts stand while their basis does.
	 * @param ApiException|null             $failed    The first failure to ask Pixonaut; set here on one.
	 */
	private function judged( WC_Product $variation, array $sizes, VariationSize $stored, ?ApiException &$failed ): VariationSize {
		$parent = wc_get_product( $variation->get_parent_id() );
		if ( ! $parent instanceof WC_Product ) {
			return new VariationSize();
		}
		$config     = ProductConfig::inherited_by( $parent, $variation );
		$variant    = ProductVariant::sold_by( self::fresh( $variation ), $config );
		$attributes = $config->override ? array() : ValueSizes::attributes_of( $variation );
		$judged     = array();
		foreach ( $config->sides as $side ) {
			$size   = $sizes[ $side->key ] ?? null;
			$value  = null === $size ? VariationSize::value_size( $side, $attributes ) : null;
			$size ??= $value;
			if ( null === $size || $side->is_standing() ) {
				continue;
			}
			$from  = null === $value ? array() : array( 'value' => true );
			$basis = VariationSize::basis( $side, $config->blank_id, $variant );
			$held  = $stored->sizes[ $side->key ] ?? null;
			$same  = null !== $held && $held['size']->stored() === $size->stored();
			if ( $same && $held['basis'] === $basis && InvalidReason::CHECK_FAILED !== $held['refused'] ) {
				$judged[ $side->key ] = self::sourced( $held, $from );
				continue;
			}
			$refused = InvalidReason::CHECK_FAILED;
			if ( null === $failed ) {
				try {
					$refused = $this->refusal( $side, $config->blank_id, $variant, $size );
				} catch ( ApiException $e ) {
					$failed = $e;
				}
			}
			if ( null !== $failed && $same ) {
				// Unasked or unanswered: the verdict it had stands.
				$judged[ $side->key ] = self::sourced( $held, $from );
				continue;
			}
			$judged[ $side->key ] = array(
				'size'    => $size,
				'refused' => $refused,
				'basis'   => $basis,
			) + $from;
		}
		return new VariationSize( $judged );
	}

	/**
	 * An entry judged before, marked as the variation's own size or its
	 * attribute value's, as it is now.
	 *
	 * @param array{size: PrintSize, refused: string|null, basis: string, value?: bool} $held The entry.
	 * @param array{value?: true}                                                       $from Marks an attribute value's size.
	 * @return array{size: PrintSize, refused: string|null, basis: string, value?: bool}
	 */
	private static function sourced( array $held, array $from ): array {
		unset( $held['value'] );
		return $held + $from;
	}

	/**
	 * A variation loaded again: a variant or size just stored by a
	 * conditional write is not on the object the caller holds.
	 *
	 * @param WC_Product $variation The variation.
	 */
	private static function fresh( WC_Product $variation ): WC_Product {
		$fresh = wc_get_product( $variation->get_id() );
		return $fresh instanceof WC_Product ? $fresh : $variation;
	}

	/**
	 * Why a size cannot be printed for a side of a variation; null when it can.
	 *
	 * @param Side        $side     The side.
	 * @param string|null $blank_id The blank; null for none.
	 * @param string|null $variant  The variant the variation sells.
	 * @param PrintSize   $size     The size.
	 *
	 * @throws ApiException When Pixonaut could not be asked.
	 */
	private function refusal( Side $side, ?string $blank_id, ?string $variant, PrintSize $size ): ?string {
		if ( null === $side->template_version ) {
			return InvalidReason::MISSING_TEMPLATE;
		}
		$on_row = array() !== $side->row_sizes;
		if ( $on_row ) {
			$row = $side->row_size_for( $variant );
			if ( null === $row || null === $row->size ) {
				return InvalidReason::VARIANT_SIZE_UNFIT;
			}
			$refused = $row->refuses( $size );
			if ( null !== $refused ) {
				return $refused;
			}
		}
		$template  = (string) new TemplateId( $side->template_base_id, $side->template_version );
		$blank_id  = $on_row ? null : $blank_id;
		$placement = $on_row ? null : $side->placement_id;
		$asked     = (string) wp_json_encode( array( $template, $blank_id, $placement, $size->stored() ) );
		if ( ! array_key_exists( $asked, $this->verdicts ) ) {
			$verdict                  = $this->catalogue->check_print_sizes( $template, array( $size ), $blank_id, $placement )[0];
			$this->verdicts[ $asked ] = $verdict->legal ? null : $verdict->reason ?? InvalidReason::CHECK_FAILED;
		}
		return $this->verdicts[ $asked ];
	}
}
