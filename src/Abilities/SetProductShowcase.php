<?php
/**
 * The ability that sets which showcase pictures a product shows.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use Pixonaut\WooCommerce\Admin\ShowcaseField;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ShowcaseImages;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Catalogue\SidePictures;
use WC_Product;
use WP_Error;
use WP_Http;

/**
 * Sets the showcase sets a personalised product's images are drawn with,
 * as the product images field of its Pixonaut tab does: each one of its
 * template's sets, drawn on the blank's photo or as the flat design, and
 * on the photo on a view and photo of the blank it names, a scene's
 * included; one named without a view keeps the view and photo chosen for it
 * before (`SetProductPictureView`). A set may be named more than once, each
 * time on another view or photo. With `side_pictures`, it also sets the
 * product's other sides its gallery shows after them (`SidePictures`); left
 * out, those are kept. The pictures are drawn again once the choice is
 * saved. Whoever may edit the product may set them.
 */
final class SetProductShowcase implements Ability {

	/**
	 * @param ProductLinks           $links     Gives the answer.
	 * @param ShowcaseField          $showcases Lists the template's sets.
	 * @param GetProductPictureViews $views     Checks a view and photo named against the blank's.
	 */
	public function __construct( private readonly ProductLinks $links, private readonly ShowcaseField $showcases, private readonly GetProductPictureViews $views ) {
	}

	public function name(): string {
		return 'set-product-showcase';
	}

	public function definition(): array {
		$schema                                      = ProductLinks::output_schema();
		$schema['properties']['available_showcases'] = self::available_schema( 'array', __( 'The sets the product\'s template offers, by id and name.', 'pixonaut-for-woocommerce' ) );
		$schema['properties']['side_pictures']       = GetProductPictureViews::side_pictures_schema();
		return array(
			/* translators: Name of an ability an AI assistant or an MCP client can use on the store. */
			'label'               => __( 'Set a product\'s showcase pictures', 'pixonaut-for-woocommerce' ),
			'description'         => sprintf(
				/* translators: 1: how many showcase pictures a product shows at most, e.g. 10; 2: how many pictures of its other sides, e.g. 9. */
				__( 'Sets which of its template\'s showcase sets a personalised simple or variable product\'s images are drawn with, in order, up to %1$d; replaces the ones it has, and an empty list removes them. Each is drawn on the blank\'s photo (`mockup`) or as the flat design (`preview`); a product without a blank has only the flat design. On the photo, `view` and `photo` name a view of the blank and one of its photos, a scene\'s too, as `get-product-picture-views` lists them; `view` null is the photo the product page starts on, and leaving both out keeps what the set was drawn on before. A set may be named more than once, on another view or photo each time, so a gallery can show the same set on several views. `side_pictures`, up to %2$d, names the product\'s other sides its gallery shows after them, such as a calendar\'s month page or a shirt\'s back, each drawn with its side\'s template and the values that side is sold with, from the sides `get-product-picture-views` lists; it replaces the ones it has, and left out keeps them. The pictures are drawn again. The answer lists the sets the template offers.', 'pixonaut-for-woocommerce' ),
				ShowcaseImages::MAX_IMAGES,
				SidePictures::MAX_PICTURES
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'product_id'    => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'images'        => array(
						'type'     => 'array',
						'maxItems' => ShowcaseImages::MAX_IMAGES,
						'items'    => array(
							'type'                 => 'object',
							'properties'           => array(
								'id'    => array(
									'type'        => 'string',
									'description' => __( 'One of the template\'s showcase sets, by id.', 'pixonaut-for-woocommerce' ),
								),
								'kind'  => array(
									'type'    => 'string',
									'enum'    => array( ShowcaseImages::MOCKUP, ShowcaseImages::PREVIEW ),
									'default' => ShowcaseImages::MOCKUP,
								),
								'view'  => array(
									'type'        => array( 'string', 'null' ),
									'description' => __( 'On the photo, one of the views `get-product-picture-views` lists; null for the one the product page starts on. Left out with `photo`, the set keeps what it was drawn on before.', 'pixonaut-for-woocommerce' ),
								),
								'photo' => array(
									'type'        => 'string',
									'description' => __( 'On the photo, one of the photos `get-product-picture-views` lists; its view is found when `view` is left out. Without it, the view\'s photo of the variant sold.', 'pixonaut-for-woocommerce' ),
								),
							),
							'required'             => array( 'id' ),
							'additionalProperties' => false,
						),
					),
					'side_pictures' => array(
						'type'     => 'array',
						'maxItems' => SidePictures::MAX_PICTURES,
						'items'    => array(
							'type'                 => 'object',
							'properties'           => array(
								'side' => array(
									'type'        => 'string',
									'description' => __( 'One of the product\'s sides, by key, as `get-product-picture-views` lists them in `sides`.', 'pixonaut-for-woocommerce' ),
								),
								'kind' => array(
									'type'    => 'string',
									'enum'    => array( ShowcaseImages::MOCKUP, ShowcaseImages::PREVIEW ),
									'default' => ShowcaseImages::MOCKUP,
								),
							),
							'required'             => array( 'side' ),
							'additionalProperties' => false,
						),
					),
				),
				'required'             => array( 'product_id', 'images' ),
				'additionalProperties' => false,
			),
			'output_schema'       => $schema,
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
	 * @param mixed $input `product_id` and `images`.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute( mixed $input = null ): array|WP_Error {
		$product = ProductLinks::product( $input );
		if ( ! $product instanceof WC_Product ) {
			return $product;
		}
		$template = ShowcaseField::template( $product );
		if ( null === $template ) {
			return new WP_Error( 'pixonaut_wc_ability_not_linked', __( 'The product is not personalised with a template, so it has no showcase sets. Set it up first.', 'pixonaut-for-woocommerce' ) );
		}
		try {
			$sets = $this->showcases->offered( $template );
		} catch ( ApiException $e ) {
			return new WP_Error( 'pixonaut_wc_ability_pixonaut_unreachable', __( 'Pixonaut could not be asked for the template\'s showcase sets. Try again later.', 'pixonaut-for-woocommerce' ), array( 'status' => WP_Http::BAD_GATEWAY ) );
		}
		$by_id   = array_column( $sets, null, 'id' );
		$on_flat = null === ProductConfig::stored_on( $product )->blank_id;
		$images  = array();
		// The first choice of each set, which a set named without a view keeps.
		$before = array_column( array_reverse( ShowcaseImages::on( $product ) ), null, 'id' );
		foreach ( is_array( $input['images'] ?? null ) ? $input['images'] : array() as $image ) {
			$id  = is_array( $image ) && is_string( $image['id'] ?? null ) ? $image['id'] : '';
			$set = $by_id[ $id ] ?? null;
			if ( null === $set ) {
				return new WP_Error(
					'pixonaut_wc_ability_unknown_showcase',
					sprintf(
						/* translators: 1: a showcase set's id; 2: the ids the template offers, e.g. "graduate, wedding". */
						__( 'The template has no showcase set "%1$s". It has: %2$s.', 'pixonaut-for-woocommerce' ),
						$id,
						array() === $sets ? _x( 'none', 'showcase sets a template offers', 'pixonaut-for-woocommerce' ) : implode( ', ', array_column( $sets, 'id' ) )
					)
				);
			}
			$kind  = $on_flat || ShowcaseImages::PREVIEW === ( $image['kind'] ?? null ) ? ShowcaseImages::PREVIEW : ShowcaseImages::MOCKUP;
			$named = is_array( $image ) && ( array_key_exists( 'view', $image ) || array_key_exists( 'photo', $image ) );
			if ( $named && ShowcaseImages::MOCKUP === $kind ) {
				$choice = $this->named( $product, $image );
				if ( $choice instanceof WP_Error ) {
					return $choice;
				}
			} else {
				$earlier = $before[ $set['id'] ] ?? array( 'kind' => $kind );
				$choice  = ! $named && $earlier['kind'] === $kind ? array_intersect_key( $earlier, array_flip( array( 'kind', 'view', 'photo' ) ) ) : array( 'kind' => $kind );
			}
			$images[] = array(
				'id'   => $set['id'],
				'name' => $set['name'],
			) + $choice;
		}
		$sides = null;
		if ( is_array( $input['side_pictures'] ?? null ) ) {
			$sides = self::side_pictures( $product, $input['side_pictures'], $on_flat );
			if ( $sides instanceof WP_Error ) {
				return $sides;
			}
		}
		ShowcaseImages::save( $product, $images );
		if ( null !== $sides ) {
			SidePictures::save( $product, $sides );
		}
		return $this->links->summary( $product ) + array(
			'available_showcases' => $sets,
			'side_pictures'       => SidePictures::on( $product ),
		);
	}

	/**
	 * The side pictures posted, each a side the product's gallery can show.
	 *
	 * @param WC_Product   $product The product.
	 * @param array<mixed> $posted  The side pictures as posted.
	 * @param bool         $on_flat Whether the product has no blank, so each is drawn flat.
	 *
	 * @return list<array{side: string, kind: string}>|WP_Error
	 */
	private static function side_pictures( WC_Product $product, array $posted, bool $on_flat ): array|WP_Error {
		$keys   = array_map( static fn ( Side $side ): string => $side->key, SidePictures::sides_of( $product ) );
		$chosen = array();
		foreach ( $posted as $row ) {
			$side = is_array( $row ) && is_string( $row['side'] ?? null ) ? $row['side'] : '';
			if ( ! in_array( $side, $keys, true ) ) {
				return new WP_Error(
					'pixonaut_wc_ability_unknown_side',
					sprintf(
						/* translators: 1: a side's key; 2: the keys of the product's sides, e.g. "front, back". */
						__( 'The product has no side "%1$s". It has: %2$s.', 'pixonaut-for-woocommerce' ),
						$side,
						implode( ', ', $keys )
					)
				);
			}
			$chosen[] = array(
				'side' => $side,
				'kind' => $on_flat || ShowcaseImages::PREVIEW === ( $row['kind'] ?? null ) ? ShowcaseImages::PREVIEW : ShowcaseImages::MOCKUP,
			);
		}
		return $chosen;
	}

	/**
	 * The choice an image names: on the photo the product page starts on
	 * when its view is null, else the view and photo, checked against the
	 * blank's.
	 *
	 * @param WC_Product          $product The product.
	 * @param array<mixed, mixed> $image   The image as posted, naming `view` or `photo`.
	 *
	 * @return array{kind: string, view?: string, photo?: string}|WP_Error
	 */
	private function named( WC_Product $product, array $image ): array|WP_Error {
		$view  = is_string( $image['view'] ?? null ) && '' !== $image['view'] ? $image['view'] : null;
		$photo = is_string( $image['photo'] ?? null ) && '' !== $image['photo'] ? $image['photo'] : null;
		if ( null === $view && null === $photo ) {
			return array( 'kind' => ShowcaseImages::MOCKUP );
		}
		$found = $this->views->found( $product, $view, $photo );
		return $found instanceof WP_Error ? $found : array( 'kind' => ShowcaseImages::MOCKUP ) + $found;
	}

	/**
	 * The schema of the sets a product's template offers, as this ability
	 * and `get-product-picture-views` answer them.
	 *
	 * @param string|list<string> $type        `array`, or with `null` when the sets may be unknown.
	 * @param string              $description What the list is.
	 *
	 * @return array<string, mixed>
	 */
	public static function available_schema( string|array $type, string $description ): array {
		return array(
			'type'        => $type,
			'description' => $description,
			'items'       => array(
				'type'       => 'object',
				'properties' => array(
					'id'   => array( 'type' => 'string' ),
					'name' => array( 'type' => 'string' ),
				),
			),
		);
	}
}
