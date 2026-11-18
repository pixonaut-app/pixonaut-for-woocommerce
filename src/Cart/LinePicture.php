<?php
/**
 * The picture a personalised line shows its design as.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Cart;

use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ProductVariant;
use Pixonaut\WooCommerce\Proofs\ProofPicture;
use Pixonaut\WooCommerce\Settings\Settings;
use WC_Product;

/**
 * A cart or order line shows the customer's design on its blank, as the
 * shop showed the product, on the photo of the blank variant the line was
 * sold as, in the view the `line_picture_view` setting names
 * (`PictureView`); a product on no blank shows the flat proof. The product
 * is read as it is now: an order line whose product was deleted still asks
 * for the design on its blank, which Pixonaut knows, and the proof route
 * answers the flat proof when it cannot be drawn.
 */
final class LinePicture {

	/**
	 * @param Settings $settings Names the view.
	 */
	public function __construct( private readonly Settings $settings ) {
	}

	/**
	 * @param WC_Product|null $product  The line's product or variation; null when it is gone.
	 * @param string          $side_key The side whose design is shown.
	 * @param string|null     $variant  The blank variant the line was sold as, `v_…`; null when it recorded none, for the one its product sells now.
	 */
	public function of( ?WC_Product $product, string $side_key, ?string $variant = null ): ProofPicture {
		// Drawn on the variant the line was sold as, not the one the proof
		// remembers: a proof previewed on no variant, as a correction is, would
		// otherwise be drawn on the view's default photo, a landscape poster
		// for a portrait one. Pixonaut refuses a variant without a photo in
		// the view, and the flat proof stands in.
		if ( null === $product ) {
			return ProofPicture::on_blank( null, $variant );
		}
		$config    = ProductConfig::config_for( $product );
		$placement = null;
		foreach ( $config->sides as $side ) {
			if ( $side->key === $side_key ) {
				$placement = $side->placement_id;
			}
		}
		if ( null === $config->blank_id || null === $placement ) {
			return ProofPicture::flat();
		}
		$sold      = ProductVariant::sold_on( $product, $config );
		$variant ??= $sold?->ref;
		if ( PictureView::FIRST !== $this->settings->get( Settings::LINE_PICTURE_VIEW ) ) {
			return ProofPicture::on_blank( null, $variant );
		}
		// The product's views are known only for the variant it sells now; for
		// another, or one without a photo in a shown view, Pixonaut picks the
		// view: the previewed one, else the variant's first.
		$view = null !== $sold && $sold->ref === $variant ? $sold->first_view( $placement, $config ) : null;
		return ProofPicture::on_blank( $view, $variant );
	}
}
