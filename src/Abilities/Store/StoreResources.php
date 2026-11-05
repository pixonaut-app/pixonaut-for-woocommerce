<?php
/**
 * The kinds of things in the store the abilities manage.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities\Store;

use Pixonaut\WooCommerce\Abilities\Ability;

/**
 * Every resource the plugin offers abilities for, beyond WooCommerce's own
 * product and order abilities: a product's attributes and type, variations, the product taxonomies and
 * attributes, pages, posts, menus, media, coupons, shipping, what orders
 * WooCommerce's abilities leave out, and the store settings. Each
 * capability is the one WordPress or WooCommerce itself checks for that
 * kind of thing, so a caller may do what the current user may do there.
 */
final class StoreResources {

	private const WC_PRODUCT_READ   = 'read_private_products';
	private const WC_PRODUCT_CREATE = 'publish_products';
	private const WC_PRODUCT_EDIT   = 'edit_products';
	private const WC_PRODUCT_DELETE = 'delete_products';

	private const TERMS_MANAGE = 'manage_product_terms';
	private const TERMS_EDIT   = 'edit_product_terms';
	private const TERMS_DELETE = 'delete_product_terms';

	private const SHOP_MANAGER = 'manage_woocommerce';

	private const THEME_OPTIONS = 'edit_theme_options';

	/**
	 * A shipping zone's placeholder in its locations' and methods' routes.
	 */
	private const ZONE_ID = 'zone_id';

	/**
	 * The resources, in the order their abilities are registered.
	 *
	 * @return list<StoreResource>
	 */
	public static function all(): array {
		$terms = array(
			StoreResource::LIST   => self::TERMS_MANAGE,
			StoreResource::GET    => self::TERMS_MANAGE,
			StoreResource::CREATE => self::TERMS_MANAGE,
			StoreResource::UPDATE => self::TERMS_EDIT,
			StoreResource::DELETE => self::TERMS_DELETE,
		);
		$shop  = array_fill_keys( StoreResource::OPERATIONS, self::SHOP_MANAGER );
		$theme = array_fill_keys( StoreResource::OPERATIONS, self::THEME_OPTIONS );
		return array(
			new StoreResource(
				'product',
				'products',
				/* translators: A kind of thing in the store, in the name of an ability such as "Update %s". */
				__( 'product', 'pixonaut-for-woocommerce' ),
				/* translators: A kind of thing in the store, plural. */
				__( 'products', 'pixonaut-for-woocommerce' ),
				'wc/v3/products',
				array(
					StoreResource::GET    => self::WC_PRODUCT_READ,
					StoreResource::UPDATE => self::WC_PRODUCT_EDIT,
				),
				'attributes, default_attributes, type'
			),
			new StoreResource(
				'product-variation',
				'product-variations',
				/* translators: A kind of thing in the store, in the name of an ability such as "Create %s". */
				__( 'product variation', 'pixonaut-for-woocommerce' ),
				/* translators: A kind of thing in the store, plural, in the name of an ability such as "List %s". */
				__( 'product variations', 'pixonaut-for-woocommerce' ),
				'wc/v3/products/{product_id}/variations',
				array(
					StoreResource::LIST   => self::WC_PRODUCT_READ,
					StoreResource::GET    => self::WC_PRODUCT_READ,
					StoreResource::CREATE => self::WC_PRODUCT_CREATE,
					StoreResource::UPDATE => self::WC_PRODUCT_EDIT,
					StoreResource::DELETE => self::WC_PRODUCT_DELETE,
				),
				'attributes, regular_price, sale_price, sku, stock_quantity, image'
			),
			new StoreResource(
				'product-category',
				'product-categories',
				/* translators: A kind of thing in the store, in the name of an ability such as "Create %s". */
				__( 'product category', 'pixonaut-for-woocommerce' ),
				/* translators: A kind of thing in the store, plural, in the name of an ability such as "List %s". */
				__( 'product categories', 'pixonaut-for-woocommerce' ),
				'wc/v3/products/categories',
				$terms,
				'name, slug, parent, description, image, display'
			),
			new StoreResource(
				'product-tag',
				'product-tags',
				/* translators: A kind of thing in the store, in the name of an ability such as "Create %s". */
				__( 'product tag', 'pixonaut-for-woocommerce' ),
				/* translators: A kind of thing in the store, plural, in the name of an ability such as "List %s". */
				__( 'product tags', 'pixonaut-for-woocommerce' ),
				'wc/v3/products/tags',
				$terms,
				'name, slug, description'
			),
			new StoreResource(
				'product-brand',
				'product-brands',
				/* translators: A kind of thing in the store, in the name of an ability such as "Create %s". */
				__( 'product brand', 'pixonaut-for-woocommerce' ),
				/* translators: A kind of thing in the store, plural, in the name of an ability such as "List %s". */
				__( 'product brands', 'pixonaut-for-woocommerce' ),
				'wc/v3/products/brands',
				$terms,
				'name, slug, parent, description, image'
			),
			new StoreResource(
				'product-attribute',
				'product-attributes',
				/* translators: A kind of thing in the store, in the name of an ability such as "Create %s"; a product attribute such as size or colour. */
				__( 'product attribute', 'pixonaut-for-woocommerce' ),
				/* translators: A kind of thing in the store, plural, in the name of an ability such as "List %s"; product attributes such as size or colour. */
				__( 'product attributes', 'pixonaut-for-woocommerce' ),
				'wc/v3/products/attributes',
				array_fill_keys( StoreResource::OPERATIONS, self::TERMS_MANAGE ),
				'name, slug, type, order_by, has_archives'
			),
			new StoreResource(
				'attribute-term',
				'attribute-terms',
				/* translators: A kind of thing in the store, in the name of an ability such as "Create %s"; one value of a product attribute, such as "Large" for size. */
				__( 'attribute term', 'pixonaut-for-woocommerce' ),
				/* translators: A kind of thing in the store, plural, in the name of an ability such as "List %s"; the values of a product attribute, such as "Large" for size. */
				__( 'attribute terms', 'pixonaut-for-woocommerce' ),
				'wc/v3/products/attributes/{attribute_id}/terms',
				$terms,
				'name, slug, description, menu_order'
			),
			new StoreResource(
				'page',
				'pages',
				/* translators: A kind of thing in the store, in the name of an ability such as "Create %s"; a WordPress page. */
				__( 'page', 'pixonaut-for-woocommerce' ),
				/* translators: A kind of thing in the store, plural, in the name of an ability such as "List %s"; WordPress pages. */
				__( 'pages', 'pixonaut-for-woocommerce' ),
				'wp/v2/pages',
				array(
					StoreResource::LIST   => 'edit_pages',
					StoreResource::GET    => 'edit_pages',
					StoreResource::CREATE => 'edit_pages',
					StoreResource::UPDATE => 'edit_pages',
					StoreResource::DELETE => 'delete_pages',
				),
				'title, content, status, parent, slug, template'
			),
			new StoreResource(
				'post',
				'posts',
				/* translators: A kind of thing in the store, in the name of an ability such as "Create %s"; a WordPress blog post. */
				__( 'post', 'pixonaut-for-woocommerce' ),
				/* translators: A kind of thing in the store, plural, in the name of an ability such as "List %s"; WordPress blog posts. */
				__( 'posts', 'pixonaut-for-woocommerce' ),
				'wp/v2/posts',
				array(
					StoreResource::LIST   => 'edit_posts',
					StoreResource::GET    => 'edit_posts',
					StoreResource::CREATE => 'edit_posts',
					StoreResource::UPDATE => 'edit_posts',
					StoreResource::DELETE => 'delete_posts',
				),
				'title, content, excerpt, status, categories, tags, featured_media'
			),
			new StoreResource(
				'menu',
				'menus',
				/* translators: A kind of thing in the store, in the name of an ability such as "Create %s"; a navigation menu. */
				__( 'menu', 'pixonaut-for-woocommerce' ),
				/* translators: A kind of thing in the store, plural, in the name of an ability such as "List %s"; navigation menus. */
				__( 'menus', 'pixonaut-for-woocommerce' ),
				'wp/v2/menus',
				$theme,
				'name, description, locations, auto_add'
			),
			new StoreResource(
				'menu-item',
				'menu-items',
				/* translators: A kind of thing in the store, in the name of an ability such as "Create %s"; a link in a navigation menu. */
				__( 'menu item', 'pixonaut-for-woocommerce' ),
				/* translators: A kind of thing in the store, plural, in the name of an ability such as "List %s"; the links in a navigation menu. */
				__( 'menu items', 'pixonaut-for-woocommerce' ),
				'wp/v2/menu-items',
				$theme,
				'title, menus, type, object, object_id, url, parent, menu_order'
			),
			new StoreResource(
				'media',
				'media',
				/* translators: A kind of thing in the store, in the name of an ability such as "Create %s"; one file in the media library. */
				__( 'media file', 'pixonaut-for-woocommerce' ),
				/* translators: A kind of thing in the store, plural, in the name of an ability such as "List %s"; files in the media library. */
				__( 'media files', 'pixonaut-for-woocommerce' ),
				'wp/v2/media',
				array_fill_keys( StoreResource::OPERATIONS, 'upload_files' ),
				'source_url, title, alt_text, caption, description, post'
			),
			new StoreResource(
				'coupon',
				'coupons',
				/* translators: A kind of thing in the store, in the name of an ability such as "Create %s". */
				__( 'coupon', 'pixonaut-for-woocommerce' ),
				/* translators: A kind of thing in the store, plural, in the name of an ability such as "List %s". */
				__( 'coupons', 'pixonaut-for-woocommerce' ),
				'wc/v3/coupons',
				array(
					StoreResource::LIST   => 'read_private_shop_coupons',
					StoreResource::GET    => 'read_private_shop_coupons',
					StoreResource::CREATE => 'publish_shop_coupons',
					StoreResource::UPDATE => 'edit_shop_coupons',
					StoreResource::DELETE => 'delete_shop_coupons',
				),
				'code, discount_type, amount, date_expires, usage_limit, product_ids',
				create_asks: true
			),
			new StoreResource(
				'shipping-zone',
				'shipping-zones',
				/* translators: A kind of thing in the store, in the name of an ability such as "Create %s". */
				__( 'shipping zone', 'pixonaut-for-woocommerce' ),
				/* translators: A kind of thing in the store, plural, in the name of an ability such as "List %s". */
				__( 'shipping zones', 'pixonaut-for-woocommerce' ),
				'wc/v3/shipping/zones',
				$shop,
				'name, order',
				built_in: array( StoreResource::ID )
			),
			new StoreResource(
				'shipping-zone-location',
				'shipping-zone-locations',
				/* translators: A kind of thing in the store, in the name of an ability such as "Create %s"; a country, state or postcode a shipping zone covers. */
				__( 'shipping zone location', 'pixonaut-for-woocommerce' ),
				/* translators: A kind of thing in the store, plural, in the name of an ability such as "List %s"; the countries, states or postcodes a shipping zone covers. */
				__( 'shipping zone locations', 'pixonaut-for-woocommerce' ),
				'wc/v3/shipping/zones/{zone_id}/locations',
				array(
					StoreResource::LIST   => self::SHOP_MANAGER,
					StoreResource::UPDATE => self::SHOP_MANAGER,
				),
				'code, type (country, state, postcode or continent)',
				replaces: true,
				built_in: array( self::ZONE_ID )
			),
			new StoreResource(
				'shipping-zone-method',
				'shipping-zone-methods',
				/* translators: A kind of thing in the store, in the name of an ability such as "Create %s"; a shipping method such as flat rate, in one shipping zone. */
				__( 'shipping method', 'pixonaut-for-woocommerce' ),
				/* translators: A kind of thing in the store, plural, in the name of an ability such as "List %s"; shipping methods such as flat rate, in one shipping zone. */
				__( 'shipping methods', 'pixonaut-for-woocommerce' ),
				'wc/v3/shipping/zones/{zone_id}/methods',
				$shop,
				'method_id (flat_rate, free_shipping or local_pickup), enabled, order, settings',
				built_in: array( self::ZONE_ID )
			),
			// Orders are listed, noted and moved between statuses by WooCommerce's own abilities.
			new StoreResource(
				'order',
				'orders',
				/* translators: A kind of thing in the store, in the name of an ability such as "Create %s". */
				__( 'order', 'pixonaut-for-woocommerce' ),
				/* translators: A kind of thing in the store, plural, in the name of an ability such as "List %s". */
				__( 'orders', 'pixonaut-for-woocommerce' ),
				'wc/v3/orders',
				array(
					StoreResource::GET    => 'read_private_shop_orders',
					StoreResource::CREATE => 'publish_shop_orders',
					StoreResource::UPDATE => 'edit_shop_orders',
					StoreResource::DELETE => 'delete_shop_orders',
				),
				'status, customer_id, billing, shipping, line_items, shipping_lines, customer_note',
				create_asks: true
			),
			new StoreResource(
				'settings-group',
				'settings-groups',
				/* translators: A kind of thing in the store, in the name of an ability such as "Get %s"; one page of WooCommerce's settings, such as General or Products. */
				__( 'settings group', 'pixonaut-for-woocommerce' ),
				/* translators: A kind of thing in the store, plural, in the name of an ability such as "List %s"; the pages of WooCommerce's settings, such as General or Products. */
				__( 'settings groups', 'pixonaut-for-woocommerce' ),
				'wc/v3/settings',
				array(
					StoreResource::LIST => self::SHOP_MANAGER,
					StoreResource::GET  => self::SHOP_MANAGER,
				),
				id_type: 'string'
			),
			new StoreResource(
				'store-setting',
				'store-settings',
				/* translators: A kind of thing in the store, in the name of an ability such as "Update %s"; one of WooCommerce's settings. */
				__( 'store setting', 'pixonaut-for-woocommerce' ),
				/* translators: A kind of thing in the store, plural, in the name of an ability such as "List %s"; WooCommerce's settings. */
				__( 'store settings', 'pixonaut-for-woocommerce' ),
				'wc/v3/settings/{group_id}',
				array(
					StoreResource::GET    => self::SHOP_MANAGER,
					StoreResource::UPDATE => self::SHOP_MANAGER,
				),
				'value',
				id_type: 'string'
			),
		);
	}

	/**
	 * An ability for each operation of each resource, and the one that
	 * describes a resource's fields.
	 *
	 * @return list<Ability>
	 */
	public static function abilities(): array {
		$resources = self::all();
		$abilities = array();
		foreach ( $resources as $resource ) {
			foreach ( $resource->operations() as $operation ) {
				$abilities[] = new ResourceAbility( $resource, $operation );
			}
		}
		$abilities[] = new DescribeResource( $resources );
		return $abilities;
	}

	/**
	 * The resource ability a full ability name names, or null for any other.
	 *
	 * @param string $name An ability's full name.
	 */
	public static function ability( string $name ): ?ResourceAbility {
		foreach ( self::abilities() as $ability ) {
			if ( $ability instanceof ResourceAbility && $ability->full_name() === $name ) {
				return $ability;
			}
		}
		return null;
	}
}
