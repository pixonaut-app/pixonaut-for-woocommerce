<?php
/**
 * What a product page builds of a product, kept between requests.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use Closure;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Schema;
use ReflectionFunction;
use WC_Customer;
use WC_Product;
use WC_Tax;
use WP_Hook;
use WP_Post;

/**
 * A variable product's page builds its designer, its add-to-cart form's
 * variations and its delivery rows from every variation; none of it
 * changes from one shopper to the next until the product does. Each is
 * kept here in a transient (the object cache, where the site has a
 * persistent one) and taken as the next request's.
 *
 * Each kept read is found under a key made of what it depends on besides
 * the product: the plugin's version, a generation, the product's version,
 * the language, the currency, the signed-in shopper's roles, how prices
 * show taxes and, while the store charges taxes, the rates that apply at
 * the shopper's and the shop's location and the shopper's exemption, and
 * the callbacks on the filters that shape prices, stock and the designer.
 * A caller adds what else its read depends on.
 *
 * A product's version is the time it was last written: when the product
 * or one of its variations is written (a post, meta or term write, a save,
 * WooCommerce's prices and stock too, a deletion), when the image of one
 * of them is edited, and again at the end of that request. The generation
 * is the time everything was last dropped: when one of the plugin's or
 * WooCommerce's options is written (the plugin's bookkeeping excepted),
 * which includes an attribute's or one of its values' change through
 * `FilterIndex`'s generation, and when a plugin, the theme or a
 * translation changes. A request keeps a read only when the product's
 * version and the generation are older than the request, and the version
 * is unchanged once the read is built, so a read made while a change was
 * written is never kept. A read is kept a day at most.
 *
 * Nothing about a shopper's cart, session or account is kept: a caller
 * keeps only what is the same for every shopper the key matches. A store
 * whose extensions answer those filters per shopper turns keeping off
 * with `pixonaut_wc_storefront_keeps_product_pages`.
 */
final class ProductPageCache {

	/**
	 * How long a kept read and a product's version are kept at most: a
	 * day, in seconds. A change drops them sooner.
	 */
	private const LIFETIME = 86400;

	/**
	 * Start of the plugin's options whose writes are bookkeeping that no
	 * product page shows: locks, holds, cursors, logs and its own kept
	 * reads.
	 */
	private const UNSHOWN_OPTIONS = array(
		Schema::MIGRATION_LOCK_OPTION,
		Schema::API_DEPRECATIONS_OPTION,
		Schema::NOTICES_OPTION,
		Schema::CONNECTION_LOCK_OPTION,
		Schema::WEBHOOK_PING_OPTION,
		Schema::PICTURE_REPORT_SENT_OPTION,
		Schema::PICTURE_LOCK_OPTION_PREFIX,
		Schema::PICTURE_RUNNER_OPTION_PREFIX,
		Schema::PICTURES_BACKFILL_OPTION,
		Schema::VARIANTS_BACKFILL_OPTION,
		Schema::SPLIT_CARDS_BACKFILL_OPTION,
		Schema::CATALOGUE_CACHE_PREFIX,
		Schema::STAGE_POSTER_HOLD_OPTION_PREFIX,
		Schema::REQUEST_LOG_OPTION,
		Schema::PROOF_CACHE_PREFIX,
		Schema::ERASED_CART_DESIGN_PREFIX,
		Schema::PROOF_LOCK_OPTION_PREFIX,
		Schema::DESIGNER_LINK_LOCK_OPTION_PREFIX,
		Schema::PRODUCT_PAGE_CACHE_PREFIX,
		Schema::ARCHIVE_INDEXES_OPTION,
		Schema::ARCHIVE_INDEX_VISITS_OPTION,
		Schema::ARCHIVE_INDEX_BODY_PREFIX,
		Schema::EVENTS_CURSOR_OPTION,
		Schema::EVENTS_BASELINE_OPTION,
		Schema::REPORT_THROTTLE_OPTION_PREFIX,
		Schema::COMMIT_FAILURES_OPTION,
		Schema::COMMIT_FAILURES_LOCK_OPTION,
	);

	/**
	 * Start of the names of the options whose writes count.
	 */
	private const OPTION_PREFIXES = array( 'pixonaut_wc_', 'woocommerce_' );

	/**
	 * The option writes that count.
	 */
	private const OPTION_WRITES = array( 'added_option', 'updated_option', 'deleted_option' );

	/**
	 * Post meta written while a product is open in the editor, which no
	 * page shows.
	 */
	private const UNSHOWN_META = array( '_edit_lock', '_edit_last' );

	/**
	 * The post meta writes that count, and the arguments read of them:
	 * the meta row, the post and the key.
	 */
	private const META_WRITES      = array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' );
	private const META_ACTION_ARGS = 3;

	/**
	 * WooCommerce's stock changes, which pass the product or variation.
	 */
	private const STOCK_ACTIONS = array( 'woocommerce_product_set_stock', 'woocommerce_variation_set_stock', 'woocommerce_product_set_stock_status', 'woocommerce_variation_set_stock_status' );

	/**
	 * Changes to the code or the translations every read is built by.
	 */
	private const CODE_CHANGES = array( 'activated_plugin', 'deactivated_plugin', 'switch_theme', 'upgrader_process_complete' );

	/**
	 * The arguments of `clean_post_cache`: the post id and the post.
	 */
	private const POST_ACTION_ARGS = 2;

	/**
	 * The filters on prices, stock, variations and the designer that a
	 * kept read goes through: a request whose callbacks on them differ
	 * reads another key.
	 */
	private const FILTERS = array(
		'woocommerce_available_variation',
		'woocommerce_hide_invisible_variations',
		'woocommerce_variation_is_visible',
		'woocommerce_variation_is_active',
		'woocommerce_variation_is_purchasable',
		'woocommerce_is_purchasable',
		'woocommerce_get_price_html',
		'woocommerce_variation_prices',
		'woocommerce_product_variation_get_price',
		'woocommerce_product_variation_get_regular_price',
		'woocommerce_product_variation_get_sale_price',
		'woocommerce_get_price_suffix',
		'woocommerce_get_availability',
		'woocommerce_get_stock_html',
		'woocommerce_currency',
		'wc_price',
		'woocommerce_attribute_label',
		'pixonaut_wc_catalogue_is_personalisable',
		'pixonaut_wc_catalogue_layout',
		'pixonaut_wc_catalogue_approval_mode',
		'pixonaut_wc_catalogue_side_config',
		'pixonaut_wc_storefront_mount_options',
	);

	/**
	 * Post meta of an image that a page shows of it: its sizes, file and
	 * alternative text.
	 */
	private const IMAGE_META = array( '_wp_attachment_metadata', '_wp_attached_file', '_wp_attachment_image_alt' );

	/**
	 * Products this request wrote, by id: their version is set again once
	 * the request is done, so a request that started while they were
	 * being written keeps nothing of them.
	 *
	 * @var array<int, true>
	 */
	private static array $written = array();

	/**
	 * Each product's version as this request read it, by id.
	 *
	 * @var array<int, string>
	 */
	private static array $versions = array();

	public static function register(): void {
		foreach ( self::META_WRITES as $action ) {
			add_action( $action, array( self::class, 'meta_written' ), 10, self::META_ACTION_ARGS );
		}
		add_action( 'clean_post_cache', array( self::class, 'post_cleaned' ), 10, self::POST_ACTION_ARGS );
		add_action( 'set_object_terms', array( self::class, 'post_written' ) );
		add_action( 'woocommerce_delete_product_transients', array( self::class, 'product_transients_deleted' ) );
		foreach ( self::STOCK_ACTIONS as $action ) {
			add_action( $action, array( self::class, 'stock_set' ) );
		}
		foreach ( self::OPTION_WRITES as $action ) {
			add_action( $action, array( self::class, 'option_written' ) );
		}
		foreach ( self::CODE_CHANGES as $action ) {
			add_action( $action, array( self::class, 'forget_all' ) );
		}
		add_action( 'attachment_updated', array( self::class, 'image_edited' ) );
		add_action( 'delete_attachment', array( self::class, 'image_edited' ) );
		add_action( 'shutdown', array( self::class, 'drop_again' ) );
	}

	/**
	 * What `$build` answers for a product, kept for the next request:
	 * taken as it was kept while nothing it depends on has changed.
	 *
	 * @template T
	 * @param WC_Product    $product The product the page shows.
	 * @param string        $what    Which read, e.g. `designer`.
	 * @param string        $context What else the read depends on.
	 * @param callable(): T $build   Builds it; the same for every shopper the key matches.
	 * @return T
	 */
	public static function remember( WC_Product $product, string $what, string $context, callable $build ): mixed {
		$id = $product->get_id();
		// Another site of a network has its own products under the same ids.
		if ( $id <= 0 || ( is_multisite() && ms_is_switched() ) || ! self::keeps( $product ) ) {
			return $build();
		}
		$version    = self::version( $id );
		$generation = (string) get_option( Schema::PRODUCT_PAGE_GENERATION_OPTION, '' );
		$key        = Schema::PRODUCT_PAGE_CACHE_PREFIX . md5( implode( "\n", array( $version, $generation, $what, $context, self::shared_context() ) ) );
		$kept       = get_transient( $key );
		if ( is_array( $kept ) && array_key_exists( 'read', $kept ) ) {
			/** @var T */
			return $kept['read'];
		}
		$errors = self::errors();
		$read   = $build();
		// A failed query may have read the product as less than it is; a read
		// made while the product or the store changed may hold some of it from before.
		if ( self::errors() === $errors && self::before_start( $version ) && self::before_start( $generation ) && self::stored_version( $id ) === $version ) {
			set_transient( $key, array( 'read' => $read ), self::LIFETIME );
		}
		return $read;
	}

	/**
	 * Drops what is kept of a product: its next reads are built again.
	 *
	 * @param int $product_id A product (not a variation).
	 */
	public static function forget( int $product_id ): void {
		if ( $product_id <= 0 ) {
			return;
		}
		// A write to another site of a network is that site's, and this request's state is not.
		if ( is_multisite() && ms_is_switched() ) {
			self::stamp( $product_id );
			return;
		}
		unset( self::$versions[ $product_id ] );
		if ( ! isset( self::$written[ $product_id ] ) ) {
			self::$written[ $product_id ] = true;
			self::stamp( $product_id );
		}
	}

	/**
	 * Drops everything kept, by starting a new generation.
	 */
	public static function forget_all(): void {
		self::$versions = array();
		update_option( Schema::PRODUCT_PAGE_GENERATION_OPTION, self::now(), true );
	}

	/**
	 * Sets the version of the products this request wrote again once it is
	 * done: a request that started while they were being written keeps
	 * nothing of them.
	 */
	public static function drop_again(): void {
		$written       = array_keys( self::$written );
		self::$written = array();
		foreach ( $written as $product_id ) {
			self::stamp( $product_id );
		}
	}

	/**
	 * Drops the reads of the products whose image, or variation's image,
	 * an image is, when it is edited or deleted.
	 *
	 * @param mixed $attachment_id The image.
	 */
	public static function image_edited( mixed $attachment_id ): void {
		if ( ! is_numeric( $attachment_id ) || (int) $attachment_id <= 0 ) {
			return;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the posts showing an image, read once when it is edited.
		$posts = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %s", (string) (int) $attachment_id ) );
		foreach ( is_array( $posts ) ? $posts : array() as $post_id ) {
			self::post_written( $post_id );
		}
	}

	/**
	 * Drops a product's reads when its or a variation's meta is written.
	 *
	 * @param mixed $meta_id  The meta row; unused.
	 * @param mixed $post_id  The post.
	 * @param mixed $meta_key The meta key.
	 */
	public static function meta_written( mixed $meta_id, mixed $post_id, mixed $meta_key ): void {
		unset( $meta_id );
		if ( in_array( $meta_key, self::IMAGE_META, true ) ) {
			self::image_edited( $post_id );
		} elseif ( ! in_array( $meta_key, self::UNSHOWN_META, true ) ) {
			self::post_written( $post_id );
		}
	}

	/**
	 * Drops a product's reads when it or one of its variations is written.
	 *
	 * @param mixed $post_id The post.
	 */
	public static function post_written( mixed $post_id ): void {
		if ( ! is_numeric( $post_id ) ) {
			return;
		}
		$post = get_post( (int) $post_id );
		if ( $post instanceof WP_Post ) {
			self::post_cleaned( $post->ID, $post );
		}
	}

	/**
	 * Drops a product's reads when its post's or a variation's cache is
	 * cleaned: on every save, status change and deletion.
	 *
	 * @param mixed $post_id The post; unused.
	 * @param mixed $post    The post.
	 */
	public static function post_cleaned( mixed $post_id, mixed $post ): void {
		unset( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		if ( 'product' === $post->post_type ) {
			self::forget( $post->ID );
		} elseif ( 'product_variation' === $post->post_type ) {
			self::forget( $post->post_parent );
		}
	}

	/**
	 * Drops a product's reads when WooCommerce drops its own after a
	 * change; everything when it drops every product's.
	 *
	 * @param mixed $post_id The product or variation; 0 for every product.
	 */
	public static function product_transients_deleted( mixed $post_id ): void {
		if ( ! is_numeric( $post_id ) || (int) $post_id <= 0 ) {
			self::forget_all();
			return;
		}
		self::post_written( $post_id );
	}

	/**
	 * Drops a product's reads when the stock of it or one of its
	 * variations is set.
	 *
	 * @param mixed $product The product or variation.
	 */
	public static function stock_set( mixed $product ): void {
		if ( $product instanceof WC_Product ) {
			self::forget( $product->get_parent_id() > 0 ? $product->get_parent_id() : $product->get_id() );
		}
	}

	/**
	 * Drops everything kept when one of the plugin's options a page
	 * shows, or one of WooCommerce's, is written.
	 *
	 * @param mixed $option The option's name.
	 */
	public static function option_written( mixed $option ): void {
		if ( ! is_string( $option ) ) {
			return;
		}
		foreach ( self::UNSHOWN_OPTIONS as $unshown ) {
			if ( str_starts_with( $option, $unshown ) ) {
				return;
			}
		}
		foreach ( self::OPTION_PREFIXES as $prefix ) {
			if ( str_starts_with( $option, $prefix ) ) {
				self::forget_all();
				return;
			}
		}
	}

	/**
	 * Whether a store's product pages keep what they build between
	 * requests.
	 *
	 * @param WC_Product $product The product the page shows.
	 */
	private static function keeps( WC_Product $product ): bool {
		/**
		 * Filters whether a product page keeps what it builds of the
		 * product between requests: its designer, what WooCommerce tells
		 * its form of each variation, its delivery rows and the defaults
		 * it opens on. A kept read serves every shopper whose language,
		 * currency, roles and taxes match, while the callbacks on the
		 * price, stock, variation and designer filters are the same:
		 * return false on a store whose extensions answer those filters
		 * for one shopper alone, such as with customer-specific prices.
		 *
		 * @since 1.22.0
		 *
		 * @param bool       $keeps   Whether the page keeps its reads; true.
		 * @param WC_Product $product The product the page shows.
		 *
		 * @example
		 * add_filter( 'pixonaut_wc_storefront_keeps_product_pages', '__return_false' );
		 */
		return (bool) apply_filters( 'pixonaut_wc_storefront_keeps_product_pages', true, $product );
	}

	/**
	 * A product's version, the time it was last written; this request's
	 * start when it has none kept.
	 *
	 * @param int $product_id A product.
	 */
	private static function version( int $product_id ): string {
		if ( isset( self::$versions[ $product_id ] ) ) {
			return self::$versions[ $product_id ];
		}
		$version = get_transient( self::version_name( $product_id ) );
		if ( ! is_string( $version ) || '' === $version ) {
			$version = self::format( self::started() );
			set_transient( self::version_name( $product_id ), $version, self::LIFETIME );
		}
		self::$versions[ $product_id ] = $version;
		return $version;
	}

	/**
	 * A product's version as it is kept now, read past this request's own
	 * copy of the options.
	 *
	 * @param int $product_id A product.
	 */
	private static function stored_version( int $product_id ): string {
		$name = self::version_name( $product_id );
		if ( ! wp_using_ext_object_cache() ) {
			wp_cache_delete( '_transient_' . $name, 'options' );
			wp_cache_delete( '_transient_timeout_' . $name, 'options' );
		}
		$version = get_transient( $name );
		return is_string( $version ) ? $version : '';
	}

	/**
	 * Sets a product's version to now.
	 *
	 * @param int $product_id A product.
	 */
	private static function stamp( int $product_id ): void {
		set_transient( self::version_name( $product_id ), self::now(), self::LIFETIME );
	}

	/**
	 * Whether a version or generation is older than this request; one
	 * never set is.
	 *
	 * @param string $stamp A time from `now()`, or empty.
	 */
	private static function before_start( string $stamp ): bool {
		return '' === $stamp || ! is_numeric( $stamp ) || (float) $stamp < self::started();
	}

	/**
	 * When this request started, in seconds.
	 */
	private static function started(): float {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- the server's own clock, read as a number.
		$started = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;
		return is_numeric( $started ) ? (float) $started : microtime( true );
	}

	/**
	 * Now, as a version or generation.
	 */
	private static function now(): string {
		return self::format( microtime( true ) );
	}

	/**
	 * A time as a version or generation, to the microsecond.
	 *
	 * @param float $time Seconds.
	 */
	private static function format( float $time ): string {
		return sprintf( '%.6F', $time );
	}

	/**
	 * The transient a product's version is kept in.
	 *
	 * @param int $product_id A product.
	 */
	private static function version_name( int $product_id ): string {
		return Schema::PRODUCT_PAGE_CACHE_PREFIX . 'version_' . $product_id;
	}

	/**
	 * What every kept read depends on besides its product: the plugin,
	 * the generation, the language, the currency, how prices show taxes
	 * and to which location, and the callbacks on the filters a read goes
	 * through.
	 */
	private static function shared_context(): string {
		return (string) wp_json_encode(
			array(
				Plugin::VERSION,
				determine_locale(),
				self::roles(),
				get_woocommerce_currency(),
				self::tax_context(),
				self::filters_signature(),
			)
		);
	}

	/**
	 * The signed-in shopper's roles, which a store's prices may follow;
	 * none for a guest.
	 *
	 * @return list<string>
	 */
	private static function roles(): array {
		$roles = array_values( array_map( 'strval', wp_get_current_user()->roles ) );
		sort( $roles );
		return $roles;
	}

	/**
	 * How prices show taxes to this shopper: nothing while the store
	 * charges none; else how the shop shows them, the shopper's exemption,
	 * and the rates of every tax class at the shopper's location and at
	 * the shop's, which the shown price follows. The rates, not the
	 * address, so every address under the same rates reads the same.
	 *
	 * @return array<int|string, mixed>
	 */
	private static function tax_context(): array {
		if ( ! wc_tax_enabled() ) {
			return array();
		}
		// Absent before WooCommerce loads its session, as in a REST or cron request.
		/** @var WC_Customer|null $customer */
		$customer = WC()->customer;
		$shop     = array( WC()->countries->get_base_country(), WC()->countries->get_base_state(), WC()->countries->get_base_postcode(), WC()->countries->get_base_city() );
		return array(
			get_option( 'woocommerce_tax_display_shop' ),
			get_option( 'woocommerce_prices_include_tax' ),
			$customer instanceof WC_Customer ? $customer->is_vat_exempt() : null,
			self::rates_at( $customer instanceof WC_Customer ? $customer->get_taxable_address() : $shop ),
			self::rates_at( $shop ),
		);
	}

	/**
	 * The rates of every tax class at a location, by class.
	 *
	 * @param array<int, mixed> $location Country, state, postcode and city.
	 * @return array<string, mixed>
	 */
	private static function rates_at( array $location ): array {
		$rates = array();
		foreach ( array_merge( array( '' ), WC_Tax::get_tax_class_slugs() ) as $class ) {
			$rates[ (string) $class ] = WC_Tax::find_rates(
				array(
					'country'   => (string) ( $location[0] ?? '' ),
					'state'     => (string) ( $location[1] ?? '' ),
					'postcode'  => (string) ( $location[2] ?? '' ),
					'city'      => (string) ( $location[3] ?? '' ),
					'tax_class' => (string) $class,
				)
			);
		}
		return $rates;
	}

	/**
	 * The callbacks on the filters a kept read goes through, by name
	 * rather than by object, so the same code gives the same signature
	 * in every request.
	 */
	private static function filters_signature(): string {
		global $wp_filter;
		$parts = array();
		foreach ( self::FILTERS as $filter ) {
			$hook = $wp_filter[ $filter ] ?? null;
			if ( ! $hook instanceof WP_Hook ) {
				continue;
			}
			foreach ( $hook->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$parts[] = $filter . '@' . $priority . ':' . self::callback_name( $callback['function'] );
				}
			}
		}
		return md5( implode( ';', $parts ) );
	}

	/**
	 * A callback's name: its function's, its class's and method's, or a
	 * closure's file and line.
	 *
	 * @param mixed $callback A callback.
	 */
	private static function callback_name( mixed $callback ): string {
		if ( is_string( $callback ) ) {
			return $callback;
		}
		if ( $callback instanceof Closure ) {
			$function = new ReflectionFunction( $callback );
			return 'closure:' . $function->getFileName() . ':' . $function->getStartLine();
		}
		if ( is_array( $callback ) && isset( $callback[0], $callback[1] ) && is_string( $callback[1] ) ) {
			return ( is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0] ) . '::' . $callback[1];
		}
		return is_object( $callback ) ? get_class( $callback ) : '';
	}

	/**
	 * How many database errors this request has had.
	 */
	private static function errors(): int {
		global $EZSQL_ERROR;
		return is_array( $EZSQL_ERROR ) ? count( $EZSQL_ERROR ) : 0;
	}
}
