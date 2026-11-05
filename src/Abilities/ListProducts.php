<?php
/**
 * The ability that lists products with their Pixonaut setup.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use Pixonaut\WooCommerce\Admin\CatalogueSearch;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ProductIndex;
use Pixonaut\WooCommerce\Catalogue\TemplateId;
use WC_Product;
use WC_Product_Variation;
use WP_Post;
use WP_Query;

/**
 * The store's simple and variable products, in id order, a page at a time,
 * each with its Pixonaut setup (`ProductSetup`) and, for a variable
 * product, each enabled variation's: what WooCommerce's own product
 * abilities leave out. Optionally one product, those linked to a template,
 * or those linked to one template. Read from the store; Pixonaut is not
 * asked. Whoever may search the catalogue on the product screen may list them.
 */
final class ListProducts implements Ability {

	/**
	 * The product types a product is linked on, as on the product screen.
	 */
	private const PRODUCT_TYPES = array( 'simple', 'variable' );

	/**
	 * The statuses listed: every product but those in the trash, as
	 * `wc_get_products()` lists by default.
	 */
	private const STATUSES = array( 'draft', 'pending', 'private', 'future', 'publish' );

	private const DEFAULT_PER_PAGE = 20;

	/**
	 * Most products on one page.
	 */
	private const MAX_PER_PAGE = 100;

	/**
	 * Most variations given for one product at once: each is loaded.
	 */
	private const VARIATIONS_PER_PAGE = 50;

	/**
	 * @param ProductSetup $setup Reads each product's setup.
	 */
	public function __construct( private readonly ProductSetup $setup ) {
	}

	public const NAME = 'list-products';

	public function name(): string {
		return self::NAME;
	}

	public function definition(): array {
		$setup = ProductSetup::schema();
		return array(
			/* translators: Name of an ability an AI assistant or an MCP client can use on the store. */
			'label'               => __( 'List products with their Pixonaut setup', 'pixonaut-for-woocommerce' ),
			'description'         => sprintf(
				/* translators: %d: how many of a product's variations one call gives at most, e.g. 50. */
				__( 'Lists the store\'s simple and variable products with how each is set up for Pixonaut: per side the template version, the print sizes in millimetres and the placement; the blank and its variant; whether Pixonaut found a problem and whether customers can buy it; and its picture. A variable product also gives its enabled variations\', %d at a time. To make a product like an existing one, read the existing one\'s setup here and reuse its template, size and blank rather than asking.', 'pixonaut-for-woocommerce' ),
				self::VARIATIONS_PER_PAGE
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'product_id'      => array(
						'type'        => 'integer',
						'description' => __( 'Only this product; left out for all.', 'pixonaut-for-woocommerce' ),
						'minimum'     => 1,
					),
					'template_id'     => array(
						'type'        => 'string',
						'description' => __( 'Only products linked to this template, by its id with or without the version, e.g. `mini-tee` or `mini-tee@2`; any version matches.', 'pixonaut-for-woocommerce' ),
					),
					'linked_only'     => array(
						'type'        => 'boolean',
						'description' => __( 'Only products linked to a template, personalised or not.', 'pixonaut-for-woocommerce' ),
						'default'     => false,
					),
					'page'            => array(
						'type'        => 'integer',
						'description' => __( 'The page, from 1.', 'pixonaut-for-woocommerce' ),
						'default'     => 1,
						'minimum'     => 1,
					),
					'per_page'        => array(
						'type'    => 'integer',
						'default' => self::DEFAULT_PER_PAGE,
						'minimum' => 1,
						'maximum' => self::MAX_PER_PAGE,
					),
					'variations_page' => array(
						'type'        => 'integer',
						/* translators: %d: how many of a product's variations are on one page, e.g. 50. */
						'description' => sprintf( __( 'The page of each variable product\'s variations, from 1, %d to a page.', 'pixonaut-for-woocommerce' ), self::VARIATIONS_PER_PAGE ),
						'default'     => 1,
						'minimum'     => 1,
					),
				),
				'additionalProperties' => false,
				'default'              => array(),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'products' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'product_id'       => array( 'type' => 'integer' ),
								'name'             => array( 'type' => 'string' ),
								'type'             => array(
									'type' => 'string',
									'enum' => self::PRODUCT_TYPES,
								),
								'status'           => array( 'type' => 'string' ),
								'purchasable'      => array(
									'type'        => 'boolean',
									'description' => __( 'Whether customers can buy it now, its blank variants and WooCommerce\'s own checks included.', 'pixonaut-for-woocommerce' ),
								),
								'variations_total' => array(
									'type'        => 'integer',
									'description' => __( 'How many enabled variations the product has; 0 for a simple product.', 'pixonaut-for-woocommerce' ),
								),
								'more_variations'  => array(
									'type'        => 'boolean',
									'description' => __( 'Whether a later `variations_page` has more of its variations.', 'pixonaut-for-woocommerce' ),
								),
								'variations'       => array(
									'type'        => 'array',
									'description' => __( 'A page of a variable product\'s enabled variations, in the order customers see them; empty for a simple product.', 'pixonaut-for-woocommerce' ),
									'items'       => array(
										'type'       => 'object',
										'properties' => array(
											'variation_id' => array( 'type' => 'integer' ),
											'attributes'   => array( 'type' => 'string' ),
											'purchasable'  => array( 'type' => 'boolean' ),
											'own_setup'    => array(
												'type' => 'boolean',
												'description' => __( 'Whether it has a setup of its own rather than following its product\'s, whose setup it then gives.', 'pixonaut-for-woocommerce' ),
											),
										) + self::shop_schema() + $setup,
									),
								),
							) + self::shop_schema() + $setup,
						),
					),
					'total'    => array( 'type' => 'integer' ),
					'more'     => array(
						'type'        => 'boolean',
						'description' => __( 'Whether a later page has more.', 'pixonaut-for-woocommerce' ),
					),
				),
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => static fn (): bool => current_user_can( CatalogueSearch::CAPABILITY ),
			'annotations'         => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
		);
	}

	/**
	 * @param mixed $input `product_id`, `template_id`, `linked_only`, `page`, `per_page` and `variations_page`.
	 * @return array{products: list<array<string, mixed>>, total: int, more: bool}
	 */
	public function execute( mixed $input = null ): array {
		$input      = is_array( $input ) ? $input : array();
		$page       = max( 1, Abilities::integer( $input, 'page', 1 ) );
		$per_page   = min( self::MAX_PER_PAGE, max( 1, Abilities::integer( $input, 'per_page', self::DEFAULT_PER_PAGE ) ) );
		$found      = self::page( $input, $page, $per_page );
		$variations = max( 1, Abilities::integer( $input, 'variations_page', 1 ) );
		$products   = array();
		foreach ( $found['products'] as $product ) {
			$products[] = $this->product( $product, $variations );
		}
		return array(
			'products' => $products,
			'total'    => $found['total'],
			'more'     => $page * $per_page < $found['total'],
		);
	}

	/**
	 * The page of products the filters allow, and how many they allow in all.
	 *
	 * @param array<mixed> $input    The ability's input.
	 * @param int          $page     The page, from 1.
	 * @param int          $per_page Products on a page.
	 * @return array{products: list<WC_Product>, total: int}
	 */
	private static function page( array $input, int $page, int $per_page ): array {
		$product_id = Abilities::integer( $input, 'product_id', 0 );
		$template   = is_string( $input['template_id'] ?? null ) ? trim( $input['template_id'] ) : '';
		$base_id    = '' === $template ? null : ( TemplateId::parse( $template )->base_id ?? $template );
		if ( null !== $base_id || true === ( $input['linked_only'] ?? false ) ) {
			$linked = ProductIndex::linked_products( $base_id, $product_id, self::PRODUCT_TYPES, self::STATUSES, $per_page, ( $page - 1 ) * $per_page );
			return array(
				'products' => array_values( array_filter( array_map( 'wc_get_product', $linked['ids'] ), static fn ( $product ): bool => $product instanceof WC_Product ) ),
				'total'    => $linked['total'],
			);
		}
		$found = wc_get_products(
			array(
				'type'     => self::PRODUCT_TYPES,
				'status'   => self::STATUSES,
				'include'  => $product_id > 0 ? array( $product_id ) : array(),
				'orderby'  => 'ID',
				'order'    => 'ASC',
				'limit'    => $per_page,
				'page'     => $page,
				'paginate' => true,
			)
		);
		if ( ! is_object( $found ) ) {
			return array(
				'products' => array(),
				'total'    => 0,
			);
		}
		return array(
			'products' => array_values( array_filter( $found->products, static fn ( $product ): bool => $product instanceof WC_Product ) ),
			'total'    => (int) $found->total,
		);
	}

	/**
	 * A page of a variable product's enabled variations, in the order
	 * customers see them, and how many it has: only the page is loaded.
	 *
	 * @param WC_Product $product A variable product.
	 * @param int        $page    The page, from 1.
	 * @return array{ids: list<int>, total: int}
	 */
	private static function variation_ids( WC_Product $product, int $page ): array {
		$query = new WP_Query(
			array(
				'post_type'      => 'product_variation',
				'post_parent'    => $product->get_id(),
				// A disabled variation is stored as private.
				'post_status'    => ProductConfig::PUBLIC_STATUS,
				'orderby'        => array(
					'menu_order' => 'ASC',
					'ID'         => 'ASC',
				),
				'fields'         => 'ids',
				'posts_per_page' => self::VARIATIONS_PER_PAGE,
				'paged'          => $page,
			)
		);
		return array(
			'ids'   => array_values( array_map( static fn ( $post ): int => $post instanceof WP_Post ? $post->ID : (int) $post, (array) $query->posts ) ),
			'total' => (int) $query->found_posts,
		);
	}

	/**
	 * @param WC_Product $product         A simple or variable product.
	 * @param int        $variations_page The page of its variations, from 1.
	 * @return array<string, mixed>
	 */
	private function product( WC_Product $product, int $variations_page ): array {
		$variations = array();
		$found      = array(
			'ids'   => array(),
			'total' => 0,
		);
		if ( $product->is_type( 'variable' ) ) {
			$shared = ProductConfig::stored_on( $product );
			$found  = self::variation_ids( $product, $variations_page );
			foreach ( $found['ids'] as $variation_id ) {
				$variation = wc_get_product( $variation_id );
				if ( ! $variation instanceof WC_Product ) {
					continue;
				}
				$own          = ProductConfig::stored_on( $variation );
				$variations[] = array(
					'variation_id' => $variation->get_id(),
					'attributes'   => $variation instanceof WC_Product_Variation ? wc_get_formatted_variation( $variation, true, false, false ) : '',
					'purchasable'  => $variation->is_purchasable(),
					'own_setup'    => $own->override,
				) + self::shop( $variation ) + $this->setup->of( $variation, $own->override ? $own : $shared );
			}
		}
		return array(
			'product_id'       => $product->get_id(),
			'name'             => $product->get_name(),
			'type'             => $product->get_type(),
			'status'           => $product->get_status(),
			'purchasable'      => $product->is_purchasable(),
			'variations_total' => $found['total'],
			'more_variations'  => $variations_page * self::VARIATIONS_PER_PAGE < $found['total'],
			'variations'       => $variations,
		) + self::shop( $product ) + $this->setup->of( $product );
	}

	/**
	 * What the shop shows of a product or variation: its price, SKU, image
	 * and page.
	 *
	 * @param WC_Product $product A product or variation.
	 * @return array{price: string, sku: string, image: string|null, permalink: string}
	 */
	private static function shop( WC_Product $product ): array {
		$image = (int) $product->get_image_id();
		$url   = $image > 0 ? wp_get_attachment_image_url( $image, 'full' ) : false;
		return array(
			'price'     => (string) $product->get_price(),
			'sku'       => $product->get_sku(),
			'image'     => is_string( $url ) ? $url : null,
			'permalink' => (string) $product->get_permalink(),
		);
	}

	/**
	 * The schema of `shop()`'s answer.
	 *
	 * @return array<string, mixed>
	 */
	private static function shop_schema(): array {
		return array(
			'price'     => array(
				'type'        => 'string',
				'description' => __( 'The price customers pay now, in the store\'s currency; empty when none is set. A variable product gives its cheapest variation\'s.', 'pixonaut-for-woocommerce' ),
			),
			'sku'       => array( 'type' => 'string' ),
			'image'     => array(
				'type'        => array( 'string', 'null' ),
				'description' => __( 'The URL of its image; null without one.', 'pixonaut-for-woocommerce' ),
			),
			'permalink' => array( 'type' => 'string' ),
		);
	}
}
