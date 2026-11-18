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
 * shop showed the product, on the photo of the variant the line sells, in
 * the view the `line_picture_view` setting names (`PictureView`); a product
 * on no blank shows the flat proof. The
 * product is read as it is now: an order line whose product was deleted
 * still asks for the design on its blank, which Pixonaut knows, and the
 * proof route answers the flat proof when it cannot be drawn.
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
	 */
	public function of( ?WC_Product $product, string $side_key ): ProofPicture {
		if ( null === $product ) {
			return ProofPicture::on_blank();
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
		// Drawn on the variant the line sells, not the one the proof remembers: a
		// proof previewed on no variant would otherwise be drawn on the view's
		// default photo, a landscape poster for a portrait one. Pixonaut refuses
		// a variant without a photo in the view, and the flat proof stands in.
		$variant = ProductVariant::sold_on( $product, $config );
		if ( PictureView::FIRST !== $this->settings->get( Settings::LINE_PICTURE_VIEW ) ) {
			return ProofPicture::on_blank( null, $variant?->ref );
		}
		// A variant without a photo in a shown view leaves the view to Pixonaut: the previewed one, else the first.
		return ProofPicture::on_blank( $variant?->first_view( $placement, $config ), $variant?->ref );
	}
}
