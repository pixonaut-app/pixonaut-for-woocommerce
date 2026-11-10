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
 * A variation's own size of a side is judged for what the variation sells:
 * on a blank whose print areas change with an option, against its blank
 * variant's row (its area here, its template's ratio by Pixonaut, which
 * judges a size naming a placement against the placement's largest row
 * only); else by Pixonaut against the side's placement. The verdict is kept
 * with the size (`VariationSize`), and judged again whenever the product,
 * the variation or what Pixonaut holds changes.
 */
final class VariationSizes {

	/**
	 * @param Catalogue $catalogue Judges sizes.
	 */
	public function __construct( private readonly Catalogue $catalogue ) {
	}

	/**
	 * Judges a variation's own sizes and stores them on it. A size judged
	 * before for the same variant, template version, blank and placement
	 * keeps its verdict without asking Pixonaut again.
	 *
	 * @param WC_Product                    $variation The variation.
	 * @param array<string, PrintSize|null> $sizes     Its sizes by side key; null or absent for the product's.
	 */
	public function save( WC_Product $variation, array $sizes ): VariationSize {
		$failed = null;
		$judged = $this->judged( $variation, $sizes, VariationSize::stored_on( self::fresh( $variation ) ), $failed );
		$judged->save_to( $variation );
		return $judged;
	}

	/**
	 * Judges again the sizes a variation, or each variation of a product,
	 * has of its own, as what they are sold under may have changed. A
	 * verdict is stored only while the variation still holds the sizes it
	 * was judged from, so a store manager's save made meanwhile stands.
	 * Once Pixonaut cannot be asked, the rest keep the verdicts they had.
	 *
	 * @param WC_Product $product A variable product or a variation.
	 * @throws ApiException When Pixonaut could not judge a size, after storing what was judged: the caller checks again later.
	 */
	public function rejudge( WC_Product $product ): void {
		$variations = $product->is_type( 'variation' ) ? array( $product ) : array_filter( array_map( 'wc_get_product', $product->get_children() ), static fn ( mixed $child ): bool => $child instanceof WC_Product );
		$failed     = null;
		foreach ( $variations as $variation ) {
			$fresh    = self::fresh( $variation );
			$document = VariationSize::document_on( $fresh );
			$stored   = VariationSize::from_document( $document );
			if ( array() === $stored->sizes ) {
				continue;
			}
			$judged = $this->judged( $fresh, array_map( static fn ( array $entry ): PrintSize => $entry['size'], $stored->sizes ), $stored, $failed );
			if ( null !== $document && $judged->to_array() !== $stored->to_array() ) {
				$judged->replace_on( $fresh, $document );
			}
		}
		if ( null !== $failed ) {
			throw $failed;
		}
	}

	/**
	 * A variation's sizes judged; a side it no longer sells personalised is
	 * dropped. A size judged before for the same basis keeps its verdict
	 * unasked, unless Pixonaut could not judge it then. A size Pixonaut
	 * cannot judge now keeps the verdict it had, else it is refused as
	 * `check_failed`, so it is not sold unjudged; after the first failure
	 * Pixonaut is not asked again in the same run.
	 *
	 * @param WC_Product                    $variation The variation.
	 * @param array<string, PrintSize|null> $sizes     Its sizes by side key.
	 * @param VariationSize                 $stored    What it held, whose verdicts stand while their basis does.
	 * @param ApiException|null             $failed    The first failure to ask Pixonaut; set here on one.
	 */
	private function judged( WC_Product $variation, array $sizes, VariationSize $stored, ?ApiException &$failed ): VariationSize {
		$parent = wc_get_product( $variation->get_parent_id() );
		if ( ! $parent instanceof WC_Product ) {
			return new VariationSize();
		}
		$config  = ProductConfig::inherited_by( $parent, $variation );
		$variant = ProductVariant::sold_by( self::fresh( $variation ), $config );
		$judged  = array();
		foreach ( $config->sides as $side ) {
			$size = $sizes[ $side->key ] ?? null;
			if ( null === $size || $side->is_standing() ) {
				continue;
			}
			$basis = VariationSize::basis( $side, $config->blank_id, $variant );
			$held  = $stored->sizes[ $side->key ] ?? null;
			$same  = null !== $held && $held['size']->stored() === $size->stored();
			if ( $same && $held['basis'] === $basis && InvalidReason::CHECK_FAILED !== $held['refused'] ) {
				$judged[ $side->key ] = $held;
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
				$judged[ $side->key ] = $held;
				continue;
			}
			$judged[ $side->key ] = array(
				'size'    => $size,
				'refused' => $refused,
				'basis'   => $basis,
			);
		}
		return new VariationSize( $judged );
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
		$verdict = $this->catalogue->check_print_sizes(
			(string) new TemplateId( $side->template_base_id, $side->template_version ),
			array( $size ),
			$on_row ? null : $blank_id,
			$on_row ? null : $side->placement_id
		)[0];
		return $verdict->legal ? null : $verdict->reason ?? InvalidReason::CHECK_FAILED;
	}
}
