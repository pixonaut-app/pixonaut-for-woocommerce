<?php
/**
 * The ability that sets which of a product's values are listed as their own products.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use LogicException;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\SplitCardLooks;
use Pixonaut\WooCommerce\Catalogue\SplitCards;
use Pixonaut\WooCommerce\Catalogue\SplitListing;
use WC_Product;
use WC_Product_Attribute;
use WP_Error;
use WP_Http;

/**
 * Sets, for the global attributes of a product named, the "Show each value
 * as its own product" choice and each value's card's own title and image,
 * and the title and image of the cards named by their values, as those of
 * a combination of values are, as the product's Attributes tab does. Only
 * what is named changes. Every
 * attribute, value and image is checked before anything is saved: one that
 * does not fit refuses the whole call. Whoever may edit the product may set
 * them.
 */
final class SetProductSplitListing implements Ability {

	/**
	 * The most attributes one call sets, and the most values of one.
	 */
	private const MAX_ROWS = 100;

	/**
	 * The most cards one call sets: a product listed by several attributes
	 * has one per combination of their values.
	 */
	private const MAX_CARDS = 500;

	/**
	 * How long the store waits for a card's image to download, in seconds.
	 */
	private const DOWNLOAD_TIMEOUT_SECONDS = 30;

	public function name(): string {
		return 'set-product-split-listing';
	}

	public function definition(): array {
		return array(
			/* translators: Name of an ability an AI assistant or an MCP client can use on the store. */
			'label'               => __( 'Set which values a product lists as their own products', 'pixonaut-for-woocommerce' ),
			'description'         => __( 'Sets, for each global attribute of a simple or variable product listed, its "Show each value as its own product" choice (`on`, `off`, or `auto` to follow the attribute\'s own setting) and each listed value\'s card\'s own title and image, as the product\'s Attributes tab does. `cards` sets the title and image of the cards named by their values\' `term_ids`: one of a product listed by several attributes is a combination, one value of each, as `get-product-split-listing` lists them. Give `attributes`, `cards` or both. Only what is given changes: an attribute, value, card, title or image left out keeps what it has. An empty title or a null image gives the card the product\'s own again. Nothing is saved unless everything listed fits. The answer is what the product has now, as `get-product-split-listing` gives it.', 'pixonaut-for-woocommerce' ),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'product_id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'attributes' => array(
						'type'     => 'array',
						'maxItems' => self::MAX_ROWS,
						'items'    => array(
							'type'                 => 'object',
							'properties'           => array(
								'attribute_id' => array(
									'type'        => 'integer',
									'minimum'     => 1,
									'description' => __( 'One of the product\'s global attributes, by id.', 'pixonaut-for-woocommerce' ),
								),
								'choice'       => array(
									'type' => 'string',
									'enum' => SplitListing::available(),
								),
								'values'       => array(
									'type'     => 'array',
									'maxItems' => self::MAX_ROWS,
									'items'    => array(
										'type'       => 'object',
										'properties' => array(
											'term_id' => array(
												'type'    => 'integer',
												'minimum' => 1,
												'description' => __( 'One of the values the product has of the attribute, by its term id.', 'pixonaut-for-woocommerce' ),
											),
											'title'   => array(
												'type' => 'string',
												'description' => __( 'The card\'s own title; empty for the product\'s.', 'pixonaut-for-woocommerce' ),
											),
											'image'   => self::image_schema(),
										),
										'required'   => array( 'term_id' ),
										'additionalProperties' => false,
									),
								),
							),
							'required'             => array( 'attribute_id' ),
							'additionalProperties' => false,
						),
					),
					'cards'      => array(
						'type'     => 'array',
						'maxItems' => self::MAX_CARDS,
						'items'    => array(
							'type'                 => 'object',
							'properties'           => array(
								'term_ids' => array(
									'type'        => 'array',
									'minItems'    => 1,
									'maxItems'    => self::MAX_ROWS,
									'description' => __( 'The card\'s values, by term id: values the product has, at most one of an attribute, in any order.', 'pixonaut-for-woocommerce' ),
									'items'       => array(
										'type'    => 'integer',
										'minimum' => 1,
									),
								),
								'title'    => array(
									'type'        => 'string',
									'description' => __( 'The card\'s own title; empty for the product\'s.', 'pixonaut-for-woocommerce' ),
								),
								'image'    => self::image_schema(),
							),
							'required'             => array( 'term_ids' ),
							'additionalProperties' => false,
						),
					),
				),
				'required'             => array( 'product_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => GetProductSplitListing::output_schema(),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( ProductLinks::class, 'can_edit' ),
			'annotations'         => array(
				'readonly'    => false,
				'destructive' => true,
				// An image given by `src` is copied into the media library anew on each call.
				'idempotent'  => false,
			),
		);
	}

	/**
	 * What a card's `image` takes, on a value's row and on a card's.
	 *
	 * @return array<string, mixed>
	 */
	private static function image_schema(): array {
		return array(
			'type'                 => array( 'object', 'null' ),
			'description'          => __( 'The card\'s own image: `id`, an image of the media library, or `src`, a public URL the store copies into it; null for the value\'s picture or the product\'s.', 'pixonaut-for-woocommerce' ),
			'properties'           => array(
				'id'  => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'src' => array( 'type' => 'string' ),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * @param mixed $input `product_id`, and `attributes`, `cards` or both.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute( mixed $input = null ): array|WP_Error {
		$product = ProductLinks::product( $input );
		if ( ! $product instanceof WC_Product ) {
			return $product;
		}
		$rows    = is_array( $input ) && is_array( $input['attributes'] ?? null ) ? $input['attributes'] : array();
		$cards   = is_array( $input ) && is_array( $input['cards'] ?? null ) ? $input['cards'] : array();
		$changes = self::changes( $product, $rows, $cards );
		if ( $changes instanceof WP_Error ) {
			return $changes;
		}
		if ( ! ProductConfig::writable_on( $product ) ) {
			return self::newer_configuration();
		}
		// The images are copied before anything stored is read, so a change made meanwhile is kept.
		$imported = array();
		$images   = array();
		foreach ( $changes['looks'] as $key => $look ) {
			$image = $look['image'] ?? null;
			if ( is_string( $image ) ) {
				$image = self::downloaded( $image, $product->get_id() );
				if ( $image instanceof WP_Error ) {
					self::forget( $imported );
					return $image;
				}
				$imported[] = $image;
			}
			$images[ $key ] = $image;
		}
		$fresh   = wc_get_product( $product->get_id() );
		$product = $fresh instanceof WC_Product ? $fresh : $product;
		$config  = ProductConfig::stored_on( $product );
		$choices = $changes['choices'] + $config->split_listing;
		if ( SplitListing::choices_from( $choices ) !== $config->split_listing ) {
			try {
				$config->with_split_listing( $choices )->save_to( $product );
			} catch ( LogicException $e ) {
				self::forget( $imported );
				return self::newer_configuration();
			}
		}
		$looks = SplitCardLooks::stored_on( $product->get_id() );
		foreach ( $changes['looks'] as $key => $look ) {
			$before        = $looks[ $key ] ?? array(
				'title' => '',
				'image' => 0,
			);
			$looks[ $key ] = array(
				'title' => $look['title'] ?? $before['title'],
				'image' => $images[ $key ] ?? $before['image'],
			);
		}
		SplitCardLooks::save( $product->get_id(), $looks );
		$saved = wc_get_product( $product->get_id() );
		return GetProductSplitListing::answer( $saved instanceof WC_Product ? $saved : $product );
	}

	/**
	 * The refusal when the product's configuration is a newer plugin version's.
	 */
	private static function newer_configuration(): WP_Error {
		return new WP_Error( 'pixonaut_wc_ability_newer_configuration', __( 'The product\'s Pixonaut configuration comes from a newer version of the plugin, so it is not changed. Update the plugin.', 'pixonaut-for-woocommerce' ), array( 'status' => WP_Http::CONFLICT ) );
	}

	/**
	 * Deletes the images a refused call copied into the media library.
	 *
	 * @param int[] $attachment_ids The attachments the call made.
	 * @phpstan-param list<int> $attachment_ids
	 */
	private static function forget( array $attachment_ids ): void {
		foreach ( $attachment_ids as $attachment_id ) {
			wp_delete_attachment( $attachment_id, true );
		}
	}

	/**
	 * What the rows change, or why the call is refused: each attribute's
	 * choice by attribute id, and each card's title and image by card key
	 * (`SplitCards::key()`, a value's term id for its own card), an image as
	 * an attachment's id, 0 for none, or the URL to copy.
	 *
	 * @param WC_Product   $product   The product.
	 * @param array<mixed> $rows      The input's `attributes`.
	 * @param array<mixed> $card_rows The input's `cards`.
	 * @return array{choices: array<int, string>, looks: array<int|string, array{title?: string, image?: int|string}>}|WP_Error
	 */
	private static function changes( WC_Product $product, array $rows, array $card_rows ): array|WP_Error {
		if ( array() === $rows && array() === $card_rows ) {
			return new WP_Error( 'pixonaut_wc_ability_nothing_to_set', __( 'Give `attributes`, `cards` or both.', 'pixonaut-for-woocommerce' ) );
		}
		$attributes = GetProductSplitListing::global_attributes( $product );
		$choices    = array();
		$looks      = array();
		$seen       = array();
		foreach ( $rows as $row ) {
			$id        = Abilities::integer( $row, 'attribute_id', 0 );
			$attribute = $attributes[ $id ] ?? null;
			if ( null === $attribute ) {
				/* translators: %d: an attribute's id. Why an AI assistant's change to the store was refused. */
				return new WP_Error( 'pixonaut_wc_ability_not_a_product_attribute', sprintf( __( 'The product has no global attribute %d. Only a global attribute\'s values can be listed as their own products.', 'pixonaut-for-woocommerce' ), $id ) );
			}
			if ( isset( $seen[ $id ] ) ) {
				/* translators: %d: an attribute's id. Why an AI assistant's change to the store was refused. */
				return new WP_Error( 'pixonaut_wc_ability_attribute_twice', sprintf( __( 'Attribute %d is listed twice.', 'pixonaut-for-woocommerce' ), $id ) );
			}
			$seen[ $id ] = true;
			if ( is_array( $row ) && is_string( $row['choice'] ?? null ) ) {
				$choices[ $id ] = $row['choice'];
			}
			$cards = self::cards( $attribute, is_array( $row ) && is_array( $row['values'] ?? null ) ? $row['values'] : array() );
			if ( $cards instanceof WP_Error ) {
				return $cards;
			}
			$looks += $cards;
		}
		foreach ( $card_rows as $row ) {
			$key = self::card_key( $attributes, $row );
			if ( $key instanceof WP_Error ) {
				return $key;
			}
			if ( isset( $looks[ $key ] ) ) {
				/* translators: %s: a card's values' term ids, e.g. "12,15". Why an AI assistant's change to the store was refused. */
				return new WP_Error( 'pixonaut_wc_ability_card_twice', sprintf( __( 'Card %s is listed twice.', 'pixonaut-for-woocommerce' ), $key ) );
			}
			$look = self::look( $key, $row );
			if ( $look instanceof WP_Error ) {
				return $look;
			}
			$looks[ $key ] = $look;
		}
		return array(
			'choices' => $choices,
			'looks'   => $looks,
		);
	}

	/**
	 * What an attribute's rows change of its values' cards, by term id, or
	 * why the call is refused.
	 *
	 * @param WC_Product_Attribute $attribute One of the product's global attributes.
	 * @param array<mixed>         $rows      Its `values`.
	 * @return array<int, array{title?: string, image?: int|string}>|WP_Error
	 */
	private static function cards( WC_Product_Attribute $attribute, array $rows ): array|WP_Error {
		$terms = array_map( 'intval', $attribute->get_options() );
		$cards = array();
		foreach ( $rows as $row ) {
			$term_id = Abilities::integer( $row, 'term_id', 0 );
			if ( ! in_array( $term_id, $terms, true ) ) {
				/* translators: 1: a value's term id; 2: an attribute's id. Why an AI assistant's change to the store was refused. */
				return new WP_Error( 'pixonaut_wc_ability_not_a_product_value', sprintf( __( 'The product has no value %1$d of attribute %2$d.', 'pixonaut-for-woocommerce' ), $term_id, $attribute->get_id() ) );
			}
			if ( isset( $cards[ $term_id ] ) ) {
				/* translators: %d: a value's term id. Why an AI assistant's change to the store was refused. */
				return new WP_Error( 'pixonaut_wc_ability_value_twice', sprintf( __( 'Value %d is listed twice.', 'pixonaut-for-woocommerce' ), $term_id ) );
			}
			$card = self::look( (string) $term_id, $row );
			if ( $card instanceof WP_Error ) {
				return $card;
			}
			$cards[ $term_id ] = $card;
		}
		return $cards;
	}

	/**
	 * The key of the card a row of `cards` names by its values, or why the
	 * call is refused: each a value the product has of a global attribute,
	 * at most one of an attribute. A card the product is not listed as now
	 * is taken too, as the Attributes tab keeps one: the product shows it
	 * once it is listed by those attributes.
	 *
	 * @param array<int, WC_Product_Attribute> $attributes The product's global attributes, by id.
	 * @param mixed                            $row        One of the input's `cards`.
	 */
	private static function card_key( array $attributes, mixed $row ): string|WP_Error {
		$term_ids = is_array( $row ) && is_array( $row['term_ids'] ?? null ) ? array_values( $row['term_ids'] ) : array();
		$held     = array();
		foreach ( array_keys( $term_ids ) as $index ) {
			$term_id      = Abilities::integer( $term_ids, (string) $index, 0 );
			$attribute_id = 0;
			foreach ( $attributes as $id => $attribute ) {
				if ( $term_id > 0 && in_array( $term_id, array_map( 'intval', $attribute->get_options() ), true ) ) {
					$attribute_id = $id;
					break;
				}
			}
			if ( 0 === $attribute_id ) {
				/* translators: %d: a value's term id. Why an AI assistant's change to the store was refused. */
				return new WP_Error( 'pixonaut_wc_ability_not_a_product_value', sprintf( __( 'The product has no value %d of a global attribute.', 'pixonaut-for-woocommerce' ), $term_id ) );
			}
			if ( isset( $held[ $attribute_id ] ) ) {
				/* translators: 1, 2: two values' term ids; 3: an attribute's id. Why an AI assistant's change to the store was refused. */
				return new WP_Error( 'pixonaut_wc_ability_card_values', sprintf( __( 'A card takes one value of an attribute: %1$d and %2$d are both of attribute %3$d.', 'pixonaut-for-woocommerce' ), $held[ $attribute_id ], $term_id, $attribute_id ) );
			}
			$held[ $attribute_id ] = $term_id;
		}
		if ( array() === $held ) {
			return new WP_Error( 'pixonaut_wc_ability_card_values', __( 'Name a card by at least one of its values.', 'pixonaut-for-woocommerce' ) );
		}
		return SplitCards::key( array_values( $held ) );
	}

	/**
	 * What a row changes of its card: the title, the image, or both; or why
	 * the call is refused.
	 *
	 * @param string $key The card's key, for the refusal.
	 * @param mixed  $row A row naming a card.
	 * @return array{title?: string, image?: int|string}|WP_Error
	 */
	private static function look( string $key, mixed $row ): array|WP_Error {
		$look = array();
		if ( is_array( $row ) && is_string( $row['title'] ?? null ) ) {
			$look['title'] = sanitize_text_field( $row['title'] );
		}
		if ( is_array( $row ) && array_key_exists( 'image', $row ) ) {
			$image = self::image( $key, $row['image'] );
			if ( $image instanceof WP_Error ) {
				return $image;
			}
			$look['image'] = $image;
		}
		return $look;
	}

	/**
	 * The image a row gives a card: an attachment's id, 0 for none, or the
	 * URL to copy into the media library; or why the call is refused.
	 *
	 * @param string $key   The card's key, for the refusal.
	 * @param mixed  $image The row's `image`.
	 */
	private static function image( string $key, mixed $image ): int|string|WP_Error {
		if ( null === $image ) {
			return 0;
		}
		$id  = Abilities::integer( $image, 'id', 0 );
		$src = is_array( $image ) && is_string( $image['src'] ?? null ) ? $image['src'] : '';
		if ( ( $id > 0 ) === ( '' !== $src ) ) {
			/* translators: %s: a card's values' term ids, e.g. "12" or "12,15". Why an AI assistant's change to the store was refused. */
			return new WP_Error( 'pixonaut_wc_ability_card_image', sprintf( __( 'Give the image of card %s as either `id` or `src`.', 'pixonaut-for-woocommerce' ), $key ) );
		}
		if ( $id > 0 ) {
			if ( ! wp_attachment_is_image( $id ) ) {
				/* translators: %d: an attachment's id. Why an AI assistant's change to the store was refused. */
				return new WP_Error( 'pixonaut_wc_ability_card_image', sprintf( __( 'The media library has no image %d.', 'pixonaut-for-woocommerce' ), $id ) );
			}
			return $id;
		}
		if ( ! in_array( wp_parse_url( $src, PHP_URL_SCHEME ), array( 'http', 'https' ), true ) ) {
			/* translators: %s: a card's values' term ids, e.g. "12" or "12,15". Why an AI assistant's change to the store was refused. */
			return new WP_Error( 'pixonaut_wc_ability_card_image', sprintf( __( 'The image of card %s is not a public URL.', 'pixonaut-for-woocommerce' ), $key ) );
		}
		return $src;
	}

	/**
	 * Copies an image into the media library, attached to the product.
	 * WordPress's `download_url()` downloads it, which refuses a URL of the
	 * store's own network and names the file for its type when its URL
	 * names none.
	 *
	 * @param string $url        The image's public URL.
	 * @param int    $product_id The product whose card shows it.
	 * @return int|WP_Error The attachment's id.
	 */
	private static function downloaded( string $url, int $product_id ): int|WP_Error {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$file = download_url( $url, self::DOWNLOAD_TIMEOUT_SECONDS );
		if ( is_wp_error( $file ) ) {
			return self::not_copied( $file->get_error_message() );
		}
		if ( (int) filesize( $file ) > wp_max_upload_size() ) {
			wp_delete_file( $file );
			/* translators: %s: the largest file the store takes, e.g. "64 MB". */
			return self::not_copied( sprintf( __( 'The file is larger than the store takes, at most %s.', 'pixonaut-for-woocommerce' ), size_format( wp_max_upload_size() ) ) );
		}
		$name = sanitize_file_name( wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
		if ( '' === pathinfo( $name, PATHINFO_EXTENSION ) ) {
			// `download_url()` gave the file the extension of the type it was answered with.
			$name = ( '' === $name ? 'card' : $name ) . '.' . pathinfo( $file, PATHINFO_EXTENSION );
		}
		$attachment_id = media_handle_sideload(
			array(
				'name'     => $name,
				'tmp_name' => $file,
			),
			$product_id
		);
		if ( is_wp_error( $attachment_id ) || $attachment_id < 1 ) {
			wp_delete_file( $file );
			return self::not_copied( is_wp_error( $attachment_id ) ? $attachment_id->get_error_message() : '' );
		}
		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			wp_delete_attachment( $attachment_id, true );
			return self::not_copied( '' );
		}
		return $attachment_id;
	}

	/**
	 * The refusal when a card's image could not be copied from its URL.
	 *
	 * @param string $reason WordPress's reason; empty when it gave none.
	 */
	private static function not_copied( string $reason ): WP_Error {
		$refusal = __( 'The card\'s image could not be copied from its URL.', 'pixonaut-for-woocommerce' );
		return new WP_Error( 'pixonaut_wc_ability_card_image_download', '' === $reason ? $refusal : $refusal . ' ' . $reason, array( 'status' => WP_Http::BAD_REQUEST ) );
	}
}
