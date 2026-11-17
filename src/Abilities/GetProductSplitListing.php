<?php
/**
 * The ability that reads which of a product's values are listed as their own products.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\SplitCardImageCopies;
use Pixonaut\WooCommerce\Catalogue\SplitCardLooks;
use Pixonaut\WooCommerce\Catalogue\SplitCards;
use Pixonaut\WooCommerce\Catalogue\SplitListing;
use WC_Product;
use WC_Product_Attribute;
use WP_Error;

/**
 * Reads, for each global attribute of a product, whether its values are
 * listed as their own products and each value's card's own title and image,
 * and each card the product is listed as now, a combination of values when
 * it is listed by several attributes, as the product's Attributes tab shows
 * them. Whoever may edit the product may read it.
 */
final class GetProductSplitListing implements Ability {

	public function name(): string {
		return 'get-product-split-listing';
	}

	public function definition(): array {
		return array(
			/* translators: Name of an ability an AI assistant or an MCP client can use on the store. */
			'label'               => __( 'Read which values a product lists as their own products', 'pixonaut-for-woocommerce' ),
			'description'         => __( 'Reads, for each global attribute of a simple or variable product, its "Cards in the shop" choice (`on`, a card per value always; `filtered`, a card per value only while a filter of the shop chooses one, as for a framing; `off`, none; `auto` follows the attribute\'s own setting, `attribute_mode`), what that comes to (`listed`), and each value\'s card\'s own title and image, with the image it waits for (`image_copy`). `listed_by` is the attribute the product is listed by now; null when it is listed once. `cards` are the cards it is listed as now: one per value, or, listed by several attributes, one per combination of their values that a variation sells, each with its values\' `term_ids` and its own title and image. Set them with `set-product-split-listing`.', 'pixonaut-for-woocommerce' ),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'product_id' => array(
						'type'    => 'integer',
						'minimum' => 1,
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
		$product = ProductLinks::product( $input );
		return $product instanceof WC_Product ? self::answer( $product ) : $product;
	}

	/**
	 * A product's global attributes, each with its choice and its values'
	 * cards, as this ability and `set-product-split-listing` answer them.
	 *
	 * @param WC_Product $product A simple or variable product.
	 * @return array<string, mixed>
	 */
	public static function answer( WC_Product $product ): array {
		$config    = ProductConfig::stored_on( $product );
		$looks     = SplitCardLooks::stored_on( $product->get_id() );
		$copies    = SplitCardImageCopies::stored_on( $product->get_id() );
		$listed_by = array_map( static fn ( WC_Product_Attribute $attribute ): int => $attribute->get_id(), SplitListing::attributes_of( $product ) );
		$modes     = array();
		foreach ( SplitListing::modes_of( $product ) as list( $listed, $mode ) ) {
			$modes[ $listed->get_id() ] = $mode;
		}
		$attributes = array();
		foreach ( self::global_attributes( $product ) as $attribute ) {
			$values = array();
			foreach ( $attribute->get_terms() ?? array() as $term ) {
				$look     = $looks[ $term->term_id ] ?? array(
					'title' => '',
					'image' => 0,
				);
				$values[] = array(
					'term_id'    => $term->term_id,
					'name'       => $term->name,
					'title'      => $look['title'],
					'image'      => self::image( $look['image'] ),
					'image_copy' => self::image_copy( $copies[ $term->term_id ] ?? null ),
				);
			}
			$attributes[] = array(
				'attribute_id'      => $attribute->get_id(),
				'name'              => wc_attribute_label( $attribute->get_name(), $product ),
				'choice'            => SplitListing::choice( $config, $attribute->get_id() ),
				'attribute_default' => SplitListing::OFF !== SplitListing::attribute_mode( $attribute->get_id() ),
				'attribute_mode'    => SplitListing::attribute_mode( $attribute->get_id() ),
				// Its choice alone does not say: an attribute on Auto is not listed by while the product set another itself.
				'own_products'      => in_array( $attribute->get_id(), $listed_by, true ),
				'listed'            => $modes[ $attribute->get_id() ] ?? SplitListing::OFF,
				'values'            => $values,
			);
		}
		return array(
			'product_id'    => $product->get_id(),
			// The first of them, as before a product could be listed by several.
			'listed_by'     => $listed_by[0] ?? null,
			'listed_by_all' => $listed_by,
			'attributes'    => $attributes,
			'cards'         => self::cards( $product, $looks, $copies ),
		);
	}

	/**
	 * The cards a product is listed as now, in its order of values, each
	 * with its values and its own title and image. The card of the
	 * variations no listed attribute applies to shows the product as itself
	 * and is left out.
	 *
	 * @param WC_Product                                                           $product A product.
	 * @param array<int|string, array{title: string, image: int}>                  $looks   Its cards' own titles and images, by card key.
	 * @param array<int|string, array{src: string, state: string, reason: string}> $copies The images its cards wait for, by card key.
	 * @return list<array{term_ids: list<int>, name: string, title: string, image: array{id: int, src: string}|null, image_copy: array{src: string, state: string, reason: string}|null}>
	 */
	private static function cards( WC_Product $product, array $looks, array $copies ): array {
		$cards = array();
		foreach ( SplitCards::keys( $product ) as $key ) {
			$term_ids = SplitCards::card_terms( $product, $key );
			if ( array() === $term_ids ) {
				continue;
			}
			$look    = $looks[ $key ] ?? array(
				'title' => '',
				'image' => 0,
			);
			$cards[] = array(
				'term_ids'   => $term_ids,
				'name'       => SplitCards::name( $term_ids ),
				'title'      => $look['title'],
				'image'      => self::image( $look['image'] ),
				'image_copy' => self::image_copy( $copies[ $key ] ?? null ),
			);
		}
		return $cards;
	}

	/**
	 * A product's global attributes, by id: the ones whose values can be
	 * listed as their own products (`SplitListing`).
	 *
	 * @param WC_Product $product A product.
	 * @return array<int, WC_Product_Attribute>
	 */
	public static function global_attributes( WC_Product $product ): array {
		$attributes = array();
		foreach ( $product->get_attributes() as $attribute ) {
			if ( $attribute instanceof WC_Product_Attribute && $attribute->is_taxonomy() && $attribute->get_id() > 0 ) {
				$attributes[ $attribute->get_id() ] = $attribute;
			}
		}
		return $attributes;
	}

	/**
	 * A card's own image; null when it has none, or the media library no
	 * longer has it.
	 *
	 * @param int $attachment_id The image's attachment id; 0 for none.
	 * @return array{id: int, src: string}|null
	 */
	private static function image( int $attachment_id ): ?array {
		$src = $attachment_id > 0 ? wp_get_attachment_url( $attachment_id ) : false;
		return is_string( $src ) && '' !== $src ? array(
			'id'  => $attachment_id,
			'src' => $src,
		) : null;
	}

	/**
	 * The image a card waits for, or could not get; null when it waits for
	 * none.
	 *
	 * @param array{src: string, state: string, reason: string}|null $copy The card's copy, as `SplitCardImageCopies` keeps it.
	 * @return array{src: string, state: string, reason: string}|null
	 */
	private static function image_copy( ?array $copy ): ?array {
		return null === $copy ? null : array(
			'src'    => $copy['src'],
			'state'  => $copy['state'],
			'reason' => $copy['reason'],
		);
	}

	/**
	 * What a card's `image_copy` answers, on a value's row and on a card's.
	 *
	 * @return array<string, mixed>
	 */
	private static function image_copy_schema(): array {
		return array(
			'type'        => array( 'object', 'null' ),
			'description' => __( 'The image the card is given by a URL and does not show yet: `src`, the URL, and `state`, `waiting` while the store copies it into the media library, as it does within minutes, or `failed`, with why in `reason`. Null once the card shows it, and when it was given none.', 'pixonaut-for-woocommerce' ),
			'properties'  => array(
				'src'    => array( 'type' => 'string' ),
				'state'  => array(
					'type' => 'string',
					'enum' => array( SplitCardImageCopies::WAITING, SplitCardImageCopies::FAILED ),
				),
				'reason' => array( 'type' => 'string' ),
			),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'product_id'    => array( 'type' => 'integer' ),
				'listed_by'     => array(
					'type'        => array( 'integer', 'null' ),
					'description' => __( 'The id of the attribute the product is listed by, one card per value; null when it is listed once. Only a variable product is listed by an attribute, one used for its variations.', 'pixonaut-for-woocommerce' ),
				),
				'listed_by_all' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'integer' ),
				),
				'attributes'    => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'attribute_id'      => array( 'type' => 'integer' ),
							'name'              => array( 'type' => 'string' ),
							'choice'            => array(
								'type' => 'string',
								'enum' => SplitListing::available(),
							),
							'attribute_default' => array(
								'type'        => 'boolean',
								'description' => __( 'Whether the attribute lists its values as their own products by default, always or while filtered.', 'pixonaut-for-woocommerce' ),
							),
							'attribute_mode'    => array(
								'type'        => 'string',
								'enum'        => SplitListing::modes(),
								'description' => __( 'How the attribute lists its values by default: `on`, a card per value; `filtered`, a card per value only while a filter of the shop chooses one; `off`, none. Set it with `set-attribute-split-listing`.', 'pixonaut-for-woocommerce' ),
							),
							'own_products'      => array(
								'type'        => 'boolean',
								'description' => __( 'What the choice comes to: whether the product lists each value of this attribute as its own product, always or while filtered.', 'pixonaut-for-woocommerce' ),
							),
							'listed'            => array(
								'type'        => 'string',
								'enum'        => SplitListing::modes(),
								'description' => __( 'What the choice comes to: `on`, a card per value always; `filtered`, only while a filter chooses one; `off`, none.', 'pixonaut-for-woocommerce' ),
							),
							'values'            => array(
								'type'  => 'array',
								'items' => array(
									'type'       => 'object',
									'properties' => array(
										'term_id'    => array( 'type' => 'integer' ),
										'name'       => array( 'type' => 'string' ),
										'title'      => array(
											'type'        => 'string',
											'description' => __( 'The card\'s own title; empty when it shows the product\'s.', 'pixonaut-for-woocommerce' ),
										),
										'image'      => array(
											'type'        => array( 'object', 'null' ),
											'description' => __( 'The card\'s own image, by its attachment id and URL; null when it shows the value\'s picture or the product\'s. A card the shop lists without a filter shows the Pixonaut picture of its design over it once that is drawn.', 'pixonaut-for-woocommerce' ),
											'properties'  => array(
												'id'  => array( 'type' => 'integer' ),
												'src' => array( 'type' => 'string' ),
											),
										),
										'image_copy' => self::image_copy_schema(),
									),
								),
							),
						),
					),
				),
				'cards'         => array(
					'type'        => 'array',
					'description' => __( 'The cards the product is listed as now: one per value, or one per combination of values when it is listed by several attributes. Empty when it is listed once.', 'pixonaut-for-woocommerce' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'term_ids'   => array(
								'type'        => 'array',
								'description' => __( 'The term ids of the card\'s values, one of each attribute the product is listed by that applies to it, in the product\'s order of attributes.', 'pixonaut-for-woocommerce' ),
								'items'       => array( 'type' => 'integer' ),
							),
							'name'       => array( 'type' => 'string' ),
							'title'      => array( 'type' => 'string' ),
							'image'      => array(
								'type'       => array( 'object', 'null' ),
								'properties' => array(
									'id'  => array( 'type' => 'integer' ),
									'src' => array( 'type' => 'string' ),
								),
							),
							'image_copy' => self::image_copy_schema(),
						),
					),
				),
			),
		);
	}
}
