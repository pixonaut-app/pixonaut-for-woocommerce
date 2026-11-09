<?php
/**
 * The ability that sets what one of a product's images is drawn on.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use Pixonaut\WooCommerce\Catalogue\PictureView;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ShowcaseImages;
use WC_Product;
use WP_Error;

/**
 * Sets what one image of a personalised product or variation is drawn on,
 * as the product images field and a variation's options do: the photo the
 * product page starts on, a view of the blank and one of its photos, or,
 * on a product, the flat design. The pictures are drawn again once the
 * choice is saved. Whoever may edit the product may set it.
 */
final class SetProductPictureView implements Ability {

	/**
	 * @param GetProductPictureViews $views Reads the views and gives the answer.
	 */
	public function __construct( private readonly GetProductPictureViews $views ) {
	}

	public function name(): string {
		return 'set-product-picture-view';
	}

	public function definition(): array {
		return array(
			/* translators: Name of an ability an AI assistant or an MCP client can use on the store. */
			'label'               => __( 'Set what a product image is drawn on', 'pixonaut-for-woocommerce' ),
			'description'         => __( 'Sets what one Pixonaut image of a personalised product or variation is drawn on: a view of the blank and, of that view, a photo (a scene photo too), as `get-product-picture-views` lists them; neither for the photo the product page starts on; or `kind` `preview` for the flat design. On a product, `showcase` names one of the showcase sets chosen with `set-product-showcase`; without it, the image drawn while no set is chosen. On a variation, it sets the variation\'s own image, and neither view nor photo makes it follow its product again; a variation is not drawn flat. The pictures are drawn again. The answer is `get-product-picture-views`\'.', 'pixonaut-for-woocommerce' ),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'product_id' => array(
						'type'        => 'integer',
						'description' => __( 'A simple or variable product, or a variation.', 'pixonaut-for-woocommerce' ),
						'minimum'     => 1,
					),
					'showcase'   => array(
						'type'        => 'string',
						'description' => __( 'On a product, one of the showcase sets chosen as its images, by id; left out for the image drawn without a set.', 'pixonaut-for-woocommerce' ),
					),
					'kind'       => array(
						'type'    => 'string',
						'enum'    => array( PictureView::MOCKUP, PictureView::PREVIEW ),
						'default' => PictureView::MOCKUP,
					),
					'view'       => array(
						'type'        => 'string',
						'description' => __( 'One of the views `get-product-picture-views` lists; with no photo, its photo of the variant sold.', 'pixonaut-for-woocommerce' ),
					),
					'photo'      => array(
						'type'        => 'string',
						'description' => __( 'One of the photos `get-product-picture-views` lists; its view is found when left out.', 'pixonaut-for-woocommerce' ),
					),
				),
				'required'             => array( 'product_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => GetProductPictureViews::output_schema(),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( ProductLinks::class, 'can_edit' ),
			'annotations'         => array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => true,
			),
		);
	}

	/**
	 * @param mixed $input `product_id`, and `showcase`, `kind`, `view` and `photo`.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute( mixed $input = null ): array|WP_Error {
		$product = ProductLinks::product_or_variation( $input );
		if ( ! $product instanceof WC_Product ) {
			return $product;
		}
		$input     = is_array( $input ) ? $input : array();
		$variation = $product->is_type( 'variation' );
		$config    = ProductConfig::config_for( $product );
		if ( ! $config->personalise || array() === $config->sides ) {
			return new WP_Error( 'pixonaut_wc_ability_not_linked', __( 'The product is not personalised, so it has no Pixonaut images. Set it up first.', 'pixonaut-for-woocommerce' ) );
		}
		$kind  = PictureView::PREVIEW === ( $input['kind'] ?? null ) ? PictureView::PREVIEW : PictureView::MOCKUP;
		$view  = is_string( $input['view'] ?? null ) && '' !== $input['view'] ? $input['view'] : null;
		$photo = is_string( $input['photo'] ?? null ) && '' !== $input['photo'] ? $input['photo'] : null;
		if ( $variation && ( PictureView::PREVIEW === $kind || isset( $input['showcase'] ) ) ) {
			return new WP_Error( 'pixonaut_wc_ability_variation_picture_view', __( 'A variation chooses only the view and photo of its own image, or neither to follow its product.', 'pixonaut-for-woocommerce' ) );
		}
		$choice = array( 'kind' => $kind );
		if ( PictureView::MOCKUP === $kind && ( null !== $view || null !== $photo ) ) {
			$found = $this->find( $product, $view, $photo );
			if ( $found instanceof WP_Error ) {
				return $found;
			}
			$choice += $found;
		}
		if ( $variation || ! isset( $input['showcase'] ) ) {
			PictureView::save( $product, $choice );
			return $this->views->answer( $product );
		}
		$images = ShowcaseImages::on( $product );
		$index  = array_search( $input['showcase'], array_column( $images, 'id' ), true );
		if ( ! is_int( $index ) ) {
			return new WP_Error(
				'pixonaut_wc_ability_unknown_showcase',
				sprintf(
					/* translators: 1: a showcase set's id; 2: the ids chosen as the product's images, e.g. "graduate, wedding". */
					__( 'The product shows no showcase set "%1$s": choose it with set-product-showcase first. It shows: %2$s.', 'pixonaut-for-woocommerce' ),
					is_string( $input['showcase'] ) ? $input['showcase'] : '',
					array() === $images ? _x( 'none', 'showcase sets a product shows', 'pixonaut-for-woocommerce' ) : implode( ', ', array_column( $images, 'id' ) )
				)
			);
		}
		$images[ $index ] = array(
			'id'   => $images[ $index ]['id'],
			'name' => $images[ $index ]['name'],
		) + $choice;
		ShowcaseImages::save( $product, $images );
		return $this->views->answer( $product );
	}

	/**
	 * The view and photo named, checked against the blank's: a photo's view
	 * is found when not named.
	 *
	 * @param WC_Product  $product A product or variation.
	 * @param string|null $view    The view named.
	 * @param string|null $photo   The photo named.
	 *
	 * @return array{view: string, photo?: string}|WP_Error
	 */
	private function find( WC_Product $product, ?string $view, ?string $photo ): array|WP_Error {
		$views = $this->views->views( $product );
		if ( null === $views ) {
			return new WP_Error( 'pixonaut_wc_ability_pixonaut_unreachable', __( 'Pixonaut could not be asked for the blank\'s views and photos. Try again later.', 'pixonaut-for-woocommerce' ) );
		}
		foreach ( $views as $listed ) {
			if ( null !== $view && $listed['id'] !== $view ) {
				continue;
			}
			if ( null === $photo ) {
				return array( 'view' => $listed['id'] );
			}
			if ( in_array( $photo, array_column( $listed['photos'], 'id' ), true ) ) {
				return array(
					'view'  => $listed['id'],
					'photo' => $photo,
				);
			}
		}
		return new WP_Error(
			'pixonaut_wc_ability_unknown_picture_view',
			array() === $views
				? __( 'The product is not on a blank with photos for its side, so its images can only be drawn as the product page shows them, or flat.', 'pixonaut-for-woocommerce' )
				: sprintf(
					/* translators: 1: the views of the blank with their photos, e.g. "front (mock-1, mock-2), back (mock-3)". */
					__( 'The blank has no such view or photo for the product\'s side. It has: %1$s.', 'pixonaut-for-woocommerce' ),
					implode(
						', ',
						array_map( static fn ( array $listed ): string => $listed['id'] . ' (' . implode( ', ', array_column( $listed['photos'], 'id' ) ) . ')', $views )
					)
				)
		);
	}
}
