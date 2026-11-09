<?php
/**
 * The ability that reads what a product's images are drawn on.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use Pixonaut\WooCommerce\Admin\LinkedBlank;
use Pixonaut\WooCommerce\Catalogue\PictureView;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ShowcaseImages;
use WC_Product;
use WP_Error;

/**
 * Reads, for a personalised product or variation, what each of its images
 * is drawn on, as the product images field and a variation's options show
 * it, and the views and photos of its side's placement it can be drawn on.
 * Whoever may edit the product may read it.
 */
final class GetProductPictureViews implements Ability {

	/**
	 * @param LinkedBlank $blank Reads the blank's views and photos.
	 */
	public function __construct( private readonly LinkedBlank $blank ) {
	}

	public function name(): string {
		return 'get-product-picture-views';
	}

	public function definition(): array {
		return array(
			/* translators: Name of an ability an AI assistant or an MCP client can use on the store. */
			'label'               => __( 'Read what a product\'s images are drawn on', 'pixonaut-for-woocommerce' ),
			'description'         => __( 'Reads what a personalised product\'s or variation\'s Pixonaut images are drawn on: the image drawn without a showcase set (a variation: its own choice), and each showcase set chosen, each on the photo the product page starts on, on a view and photo of the blank, or flat; and the views of its side\'s placement with their photos, scenes included. Set them with `set-product-picture-view`.', 'pixonaut-for-woocommerce' ),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'product_id' => array(
						'type'        => 'integer',
						'description' => __( 'A simple or variable product, or a variation.', 'pixonaut-for-woocommerce' ),
						'minimum'     => 1,
					),
				),
				'required'             => array( 'product_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => self::output_schema(),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( ProductLinks::class, 'can_edit' ),
			'annotations'         => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
		);
	}

	/**
	 * @param mixed $input `product_id`.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute( mixed $input = null ): array|WP_Error {
		$product = ProductLinks::product_or_variation( $input );
		if ( ! $product instanceof WC_Product ) {
			return $product;
		}
		return $this->answer( $product );
	}

	/**
	 * What the product's images are drawn on, and what they can be.
	 *
	 * @param WC_Product $product A product or variation.
	 *
	 * @return array{product_id: int, picture_view: array<string, mixed>|null, showcase_images: list<array<string, mixed>>, views: list<array<string, mixed>>|null}
	 */
	public function answer( WC_Product $product ): array {
		$views  = $this->views( $product );
		$images = array();
		foreach ( $product->is_type( 'variation' ) ? array() : ShowcaseImages::on( $product ) as $image ) {
			$images[] = array(
				'id'   => $image['id'],
				'name' => $image['name'],
			) + self::choice( $image, $views );
		}
		$own = PictureView::on( $product );
		return array(
			'product_id'      => $product->get_id(),
			'picture_view'    => null === $own ? null : self::choice( $own, $views ),
			'showcase_images' => $images,
			'views'           => null === $views ? null : array_map(
				static fn ( array $view ): array => array(
					'id'     => $view['id'],
					'label'  => $view['label'],
					'photos' => array_map(
						static fn ( array $photo ): array => array(
							'id'            => $photo['id'],
							'thumbnail_url' => $photo['thumbnail'],
							'variant_refs'  => $photo['variants'],
						),
						$view['photos']
					),
				),
				$views
			),
		);
	}

	/**
	 * The side's placement's views and photos; empty without a blank or
	 * placement, null when Pixonaut cannot be asked.
	 *
	 * @param WC_Product $product A product or variation.
	 *
	 * @return list<array{id: string, label: string, photos: list<array{id: string, thumbnail: string, variants: list<string>}>}>|null
	 */
	public function views( WC_Product $product ): ?array {
		$config       = ProductConfig::config_for( $product );
		$placement_id = $config->sides[0]->placement_id ?? null;
		return null === $config->blank_id || null === $placement_id ? array() : $this->blank->placement_photos( $config->blank_id, $placement_id );
	}

	/**
	 * A choice as the answer gives it.
	 *
	 * @param array{kind: string, view?: string, photo?: string}                                                                      $choice The choice.
	 * @param list<array{id: string, label: string, photos: list<array{id: string, thumbnail: string, variants: list<string>}>}>|null $views  The placement's views; null when Pixonaut cannot be asked.
	 *
	 * @return array{kind: string, view: string|null, photo: string|null, gone: bool}
	 */
	private static function choice( array $choice, ?array $views ): array {
		return array(
			'kind'  => $choice['kind'],
			'view'  => $choice['view'] ?? null,
			'photo' => $choice['photo'] ?? null,
			'gone'  => isset( $choice['view'] ) && null !== $views && null === PictureView::resolve( $views, $choice, null, true ),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function output_schema(): array {
		$choice = array(
			'kind'  => array(
				'type'        => 'string',
				'enum'        => array( PictureView::MOCKUP, PictureView::PREVIEW ),
				'description' => __( '`mockup` on the blank\'s photo, `preview` the flat design.', 'pixonaut-for-woocommerce' ),
			),
			'view'  => array(
				'type'        => array( 'string', 'null' ),
				'description' => __( 'The view chosen; null for the one the product page starts on.', 'pixonaut-for-woocommerce' ),
			),
			'photo' => array(
				'type'        => array( 'string', 'null' ),
				'description' => __( 'The view\'s photo chosen; null for its photo of the variant sold.', 'pixonaut-for-woocommerce' ),
			),
			'gone'  => array(
				'type'        => 'boolean',
				'description' => __( 'True when the blank no longer has the view or photo: the image is drawn on the photo the product page starts on until another is chosen.', 'pixonaut-for-woocommerce' ),
			),
		);
		return array(
			'type'       => 'object',
			'properties' => array(
				'product_id'      => array( 'type' => 'integer' ),
				'picture_view'    => array(
					'type'        => array( 'object', 'null' ),
					'description' => __( 'On a product, what its image is drawn on while no showcase set is chosen; on a variation, its own choice. Null for none: the photo the product page starts on, a variation\'s product\'s choice.', 'pixonaut-for-woocommerce' ),
					'properties'  => $choice,
				),
				'showcase_images' => array(
					'type'        => 'array',
					'description' => __( 'The showcase sets chosen as the product\'s images, the first its image; empty on a variation.', 'pixonaut-for-woocommerce' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'id'   => array( 'type' => 'string' ),
							'name' => array( 'type' => 'string' ),
						) + $choice,
					),
				),
				'views'           => array(
					'type'        => array( 'array', 'null' ),
					'description' => __( 'The views of the side\'s placement with the photos an image can be drawn on, in the blank\'s order; empty without a blank; null when Pixonaut could not be asked.', 'pixonaut-for-woocommerce' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'id'     => array( 'type' => 'string' ),
							'label'  => array( 'type' => 'string' ),
							'photos' => array(
								'type'  => 'array',
								'items' => array(
									'type'       => 'object',
									'properties' => array(
										'id'            => array( 'type' => 'string' ),
										'thumbnail_url' => array(
											'type'        => 'string',
											'description' => __( 'A link to the photo that opens for an hour or two.', 'pixonaut-for-woocommerce' ),
										),
										'variant_refs'  => array(
											'type'        => 'array',
											'items'       => array( 'type' => 'string' ),
											'description' => __( 'The blank variants the photo shows; empty for every one.', 'pixonaut-for-woocommerce' ),
										),
									),
								),
							),
						),
					),
				),
			),
		);
	}
}
