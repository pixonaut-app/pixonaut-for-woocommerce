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
	 * Judges a variation's own sizes and stores them on it.
	 *
	 * @param WC_Product                    $variation The variation.
	 * @param array<string, PrintSize|null> $sizes     Its sizes by side key; null or absent for the product's.
	 */
	public function save( WC_Product $variation, array $sizes ): VariationSize {
		$judged = $this->judged( $variation, $sizes, new VariationSize() );
		$judged->save_to( $variation );
		return $judged;
	}

	/**
	 * Judges again the sizes a variation, or each variation of a product,
	 * has of its own, as what they are sold under may have changed.
	 *
	 * @param WC_Product $product A variable product or a variation.
	 */
	public function rejudge( WC_Product $product ): void {
		$variations = $product->is_type( 'variation' ) ? array( $product ) : array_filter( array_map( 'wc_get_product', $product->get_children() ), static fn ( mixed $child ): bool => $child instanceof WC_Product );
		foreach ( $variations as $variation ) {
			$stored = VariationSize::stored_on( $variation );
			if ( array() === $stored->sizes ) {
				continue;
			}
			$judged = $this->judged( $variation, array_map( static fn ( array $entry ): PrintSize => $entry['size'], $stored->sizes ), $stored );
			if ( $judged->to_array() !== $stored->to_array() ) {
				$judged->save_to( $variation );
			}
		}
	}

	/**
	 * A variation's sizes judged; a side it no longer sells personalised is
	 * dropped. A size Pixonaut could not judge keeps the verdict it had,
	 * else it is refused as `check_failed`, so it is not sold unjudged.
	 *
	 * @param WC_Product                    $variation The variation.
	 * @param array<string, PrintSize|null> $sizes     Its sizes by side key.
	 * @param VariationSize                 $stored    What it held, whose verdicts stand while Pixonaut cannot be asked.
	 */
	private function judged( WC_Product $variation, array $sizes, VariationSize $stored ): VariationSize {
		$parent = wc_get_product( $variation->get_parent_id() );
		if ( ! $parent instanceof WC_Product ) {
			return new VariationSize();
		}
		$config = ProductConfig::inherited_by( $parent, $variation );
		// Loaded again: a variant just resolved is written by `replace_on()`, not on the object the caller holds.
		$fresh   = wc_get_product( $variation->get_id() );
		$variant = ProductVariant::sold_by( $fresh instanceof WC_Product ? $fresh : $variation, $config );
		$judged  = array();
		foreach ( $config->sides as $side ) {
			$size = $sizes[ $side->key ] ?? null;
			if ( null === $size || $side->is_standing() ) {
				continue;
			}
			try {
				$judged[ $side->key ] = array(
					'size'    => $size,
					'refused' => $this->refusal( $side, $config->blank_id, $variant, $size ),
					'basis'   => VariationSize::basis( $side, $config->blank_id, $variant ),
				);
			} catch ( ApiException $e ) {
				$held                 = $stored->sizes[ $side->key ] ?? null;
				$judged[ $side->key ] = null !== $held && $held['size']->stored() === $size->stored() ? $held : array(
					'size'    => $size,
					'refused' => InvalidReason::CHECK_FAILED,
					'basis'   => VariationSize::basis( $side, $config->blank_id, $variant ),
				);
			}
		}
		return new VariationSize( $judged );
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
