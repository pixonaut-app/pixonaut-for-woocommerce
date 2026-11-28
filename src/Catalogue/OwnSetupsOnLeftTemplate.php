<?php
/**
 * Moves a product's variations with a setup of their own off the template the product left.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use LogicException;
use Pixonaut\WooCommerce\Admin\TrashedProducts;
use WC_Product;

/**
 * When a product is linked to another template, its variations with a setup
 * of their own that is the product's but for another blank, blank variant or
 * print size follow it there: a setup of their own is most often only that,
 * and left on the template the product left, they keep it in use and are
 * sold without the product's values. Such a variation has as many sides as
 * the product had and has now, each with the key of the product's side at
 * its place now, on the template of the product's side there before the
 * link, in the same mode, not pinned, and with no values of its own for that
 * template; a variation on any other template stays there. Each moved side keeps its placement, print sizes, size it
 * starts on, form arrangement and mode, and the variation its blank and
 * variant. It is stored on the version linked as not yet checked
 * (`CHECK_PENDING`), so it is not sold until Pixonaut has checked it there,
 * which `ProductRecheck::follow_link()` then does as for a version bump.
 */
final class OwnSetupsOnLeftTemplate {

	/**
	 * Stores each variation that follows the product onto the template
	 * version linked, unchecked.
	 *
	 * @param WC_Product    $product  The product, as linked.
	 * @param ProductConfig $before   What the product had before the link.
	 * @param TemplateId    $template The template version it is linked to.
	 * @return list<int> The variations moved.
	 */
	public static function move( WC_Product $product, ProductConfig $before, TemplateId $template ): array {
		if ( ! $product->is_type( 'variable' ) || ! $before->personalise ) {
			return array();
		}
		$linked = ProductConfig::stored_on( $product );
		$name   = null;
		foreach ( $linked->sides as $side ) {
			if ( $side->template_base_id === $template->base_id ) {
				$name = $side->template_name;
				break;
			}
		}
		$moved = array();
		foreach ( $product->get_children() as $child_id ) {
			$variation = wc_get_product( $child_id );
			$document  = $variation instanceof WC_Product ? ProductConfig::document_on( $variation ) : null;
			if ( null === $document || TrashedProducts::in_trash( $variation ) ) {
				continue;
			}
			$own = ProductConfig::from_json( $document );
			if ( ! self::follows( $variation, $own, $before, $linked, $template ) ) {
				continue;
			}
			$sides = array_map(
				static fn ( Side $side ): Side => $side->with_template_renamed( $side->template_base_id, $template->base_id )
					->with( $template->version, null, array(), $side->menu() )
					->with_versions( false, null, null )
					->with_offers_properties( null )
					->with_template_name( $name ),
				$own->sides
			);
			try {
				// Saved meanwhile, its new setup is the store manager's to keep.
				if ( $own->with_sides( $sides )->with_validity( false, InvalidReason::CHECK_PENDING )->replace_on( $variation, $document ) ) {
					$moved[] = $variation->get_id();
				}
			} catch ( LogicException $e ) {
				// A newer plugin version's setup is not this one's to change.
				continue;
			}
		}
		return $moved;
	}

	/**
	 * Whether a variation's own setup is its product's but for the blank,
	 * variant or print size, on the template the product left (see the class).
	 *
	 * @param WC_Product    $variation The variation.
	 * @param ProductConfig $own       Its own setup.
	 * @param ProductConfig $before    What the product had before the link.
	 * @param ProductConfig $linked    What it has now.
	 * @param TemplateId    $template  The template version it is linked to.
	 */
	private static function follows( WC_Product $variation, ProductConfig $own, ProductConfig $before, ProductConfig $linked, TemplateId $template ): bool {
		$sides = $own->sides;
		$was   = $before->sides;
		if ( ! $own->override || ! $own->personalise || array() === $sides || count( $sides ) !== count( $was ) || count( $sides ) !== count( $linked->sides ) ) {
			return false;
		}
		$values = SideValues::stored_on( $variation );
		if ( array() === $values && null !== SideValues::document_on( $variation ) ) {
			// Values of its own this plugin cannot read (a newer version's) may be for this template.
			return false;
		}
		foreach ( $sides as $i => $side ) {
			$base = $side->template_base_id;
			if ( '' === $base || $base === $template->base_id || $base !== $was[ $i ]->template_base_id || $side->mode !== $was[ $i ]->mode || $side->pinned ) {
				return false;
			}
			if ( $side->key !== $linked->sides[ $i ]->key ) {
				// The product's values reach a side only by its key.
				return false;
			}
			if ( isset( $values[ $side->key ] ) && $values[ $side->key ]->applies_to( $side ) ) {
				// Values of its own are for this template alone.
				return false;
			}
		}
		return true;
	}
}
