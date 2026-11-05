<?php
/**
 * Where a product's or variation's image is set, a line on its Pixonaut picture.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Admin;

use Pixonaut\WooCommerce\Catalogue\PictureImage;
use Pixonaut\WooCommerce\Catalogue\ProductPictures;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Templates;
use WC_Product;
use WP_Post;

/**
 * While the store uses Pixonaut pictures as product images, a personalised
 * product's Product image box, and each personalised variation's options
 * beside its image, say whether the image is Pixonaut's picture, the store
 * manager's own, or not drawn yet. Beside an own image, **Use the Pixonaut
 * picture** puts the picture back: the product's script sets it as the
 * image, or removes the image when none is drawn, which queues it.
 */
final class PictureFields {

	/**
	 * The image is Pixonaut's picture.
	 */
	public const PICTURE = 'picture';

	/**
	 * The image is one the store manager set, kept in place of the picture.
	 */
	public const OWN = 'own';

	/**
	 * The picture is not the image yet.
	 */
	public const WAITING = 'waiting';

	/**
	 * The arguments `admin_post_thumbnail_html` passes.
	 */
	private const THUMBNAIL_ARGS = 3;

	/**
	 * The arguments `woocommerce_variation_options` passes.
	 */
	private const VARIATION_ARGS = 3;

	/**
	 * @param Templates $templates Renders the line.
	 * @param Settings  $settings  Says whether pictures are product images.
	 */
	public function __construct( private readonly Templates $templates, private readonly Settings $settings ) {
	}

	public function register(): void {
		add_filter( 'admin_post_thumbnail_html', array( $this, 'product_field' ), 10, self::THUMBNAIL_ARGS );
		add_action( 'woocommerce_variation_options', array( $this, 'variation_field' ), 10, self::VARIATION_ARGS );
	}

	/**
	 * Adds the line under a personalised product's image. The box is drawn
	 * again for an image chosen and not saved yet, which the line is about.
	 *
	 * @param mixed $html         The Product image box's content.
	 * @param mixed $post_id      The post it is of.
	 * @param mixed $thumbnail_id The image it shows; null or -1 for none.
	 */
	public function product_field( mixed $html, mixed $post_id, mixed $thumbnail_id = null ): mixed {
		if ( ! is_string( $html ) || 'product' !== get_post_type( (int) $post_id ) ) {
			return $html;
		}
		$product = wc_get_product( (int) $post_id );
		return $product instanceof WC_Product && $this->applies( $product ) ? $html . $this->html( $product, max( 0, (int) $thumbnail_id ) ) : $html;
	}

	/**
	 * Prints the line among a personalised variation's options.
	 *
	 * @param mixed $loop      The variation's place in the variations list; unused.
	 * @param mixed $data      The variation's data; unused.
	 * @param mixed $variation The variation's post.
	 */
	public function variation_field( mixed $loop, mixed $data, mixed $variation ): void {
		unset( $loop, $data );
		$product = $variation instanceof WP_Post ? wc_get_product( $variation->ID ) : null;
		if ( $product instanceof WC_Product && $this->applies( $product ) ) {
			echo $this->html( $product, PictureImage::current( $product ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the template.
		}
	}

	/**
	 * Which of PICTURE, OWN and WAITING an image of the product is.
	 *
	 * @param WC_Product $product A product or variation.
	 * @param int        $image   The image it shows; 0 for none.
	 */
	public static function state( WC_Product $product, int $image ): string {
		if ( PictureImage::is_picture( $image, $product ) ) {
			return self::PICTURE;
		}
		return $image > 0 && PictureImage::remembers( $product ) ? self::OWN : self::WAITING;
	}

	/**
	 * @param WC_Product $product A product or variation.
	 */
	private function applies( WC_Product $product ): bool {
		return ProductPictures::applies( $this->settings, $product );
	}

	/**
	 * @param WC_Product $product The product or variation.
	 * @param int        $image   The image it shows; 0 for none.
	 */
	private function html( WC_Product $product, int $image ): string {
		$picture = ProductPictures::stored( $product )['attachment'];
		$picture = PictureImage::is_picture( $picture, $product ) ? $picture : 0;
		return $this->templates->html(
			'admin/product-picture.php',
			array(
				'state'       => self::state( $product, $image ),
				'picture'     => $picture,
				'picture_url' => $picture > 0 ? (string) wp_get_attachment_image_url( $picture, 'thumbnail' ) : '',
			)
		);
	}
}
