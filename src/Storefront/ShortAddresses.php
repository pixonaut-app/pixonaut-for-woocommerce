<?php
/**
 * The shop's addresses, naming each global attribute by its short name.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use WP;

/**
 * Reads an attribute's short name (`QueryNames`) in a request as WooCommerce
 * reads the attribute: on a product's page as its form field, which chooses
 * the option the form opens on, and anywhere else as its filter. The
 * Product Filters block reads its filters from the request's address
 * itself, and writes them back into the address it goes to: while it
 * renders, it is given the address with WooCommerce's names, and a script
 * puts the short names back in the browser's address once it has gone
 * there. The same script shortens an address opened with WooCommerce's
 * names. The classic filter widgets' links are shortened as they are
 * printed.
 */
final class ShortAddresses {

	public const SCRIPT_HANDLE = 'pixonaut-short-addresses';

	/**
	 * The global the script reads the names from.
	 */
	public const NAMES_GLOBAL = 'pixonautQueryNames';

	/**
	 * The blocks that read the filters from the request's address.
	 */
	private const ADDRESS_BLOCKS = array( 'woocommerce/product-filters', 'woocommerce/product-collection-no-results' );

	/**
	 * After WordPress parsed the request, and before WooCommerce's own
	 * `parse_request` reads its endpoints from it.
	 */
	private const PARSE_PRIORITY = 5;

	/**
	 * The request's address each block rendering now replaced, innermost last.
	 *
	 * @var list<string|null>
	 */
	private array $replaced = array();

	/**
	 * @param string $plugin_file The plugin's main file, which the script's address is relative to.
	 */
	public function __construct( private readonly string $plugin_file ) {
	}

	public function register(): void {
		add_action( 'parse_request', array( $this, 'read_request' ), self::PARSE_PRIORITY );
		add_filter( 'render_block_data', array( $this, 'lengthen_address' ) );
		foreach ( self::ADDRESS_BLOCKS as $block ) {
			add_filter( 'render_block_' . $block, array( $this, 'restore_address' ) );
		}
		add_filter( 'woocommerce_layered_nav_link', array( $this, 'shorten' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Reads each short name in the request as WooCommerce's name for it.
	 *
	 * @param mixed $wp The request.
	 */
	public function read_request( mixed $wp ): void {
		if ( ! $wp instanceof WP ) {
			return;
		}
		$product = QueryNames::is_product_request( $wp->query_vars );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a link choosing an option or a filter, which changes nothing; the values are copied as they came, to be read as WooCommerce reads its own.
		foreach ( QueryNames::lengthened( $_GET, $product ) as $key => $value ) {
			$_GET[ $key ]     = $value;
			$_REQUEST[ $key ] = $value;
			if ( ! $product && in_array( $key, $wp->public_query_vars, true ) ) {
				$wp->query_vars[ $key ] = is_string( $value ) ? wp_unslash( $value ) : $value;
			}
		}
	}

	/**
	 * Gives a block reading the filters from the request's address the
	 * address with WooCommerce's names, until it has rendered.
	 *
	 * @param mixed $parsed_block The block about to render.
	 * @return mixed
	 */
	public function lengthen_address( mixed $parsed_block ): mixed {
		if ( ! is_array( $parsed_block ) || ! in_array( $parsed_block['blockName'] ?? null, self::ADDRESS_BLOCKS, true ) ) {
			return $parsed_block;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- put back as it was.
		$uri              = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : null;
		$this->replaced[] = $uri;
		// The block writes WooCommerce's names into the address it goes to.
		self::enqueue_script( $this->plugin_file );
		if ( null !== $uri ) {
			$_SERVER['REQUEST_URI'] = self::lengthened_uri( $uri );
		}
		return $parsed_block;
	}

	/**
	 * Puts back the request's address once the block has rendered.
	 *
	 * @param mixed $content The block's markup.
	 * @return mixed
	 */
	public function restore_address( mixed $content ): mixed {
		if ( array() !== $this->replaced ) {
			$uri = array_pop( $this->replaced );
			if ( null === $uri ) {
				unset( $_SERVER['REQUEST_URI'] );
			} else {
				$_SERVER['REQUEST_URI'] = $uri;
			}
		}
		return $content;
	}

	/**
	 * @param mixed $url A link.
	 * @return mixed
	 */
	public function shorten( mixed $url ): mixed {
		return is_string( $url ) ? QueryNames::shorten( $url ) : $url;
	}

	/**
	 * The script on the pages whose address names attributes: a product's,
	 * and the shop's archives.
	 */
	public function enqueue(): void {
		if ( ! is_product() && ! is_shop() && ! is_product_taxonomy() ) {
			return;
		}
		self::enqueue_script( $this->plugin_file );
	}

	/**
	 * Enqueues the script with the store's names, once.
	 *
	 * @param string $plugin_file The plugin's main file.
	 */
	public static function enqueue_script( string $plugin_file ): void {
		if ( wp_script_is( self::SCRIPT_HANDLE, 'enqueued' ) ) {
			return;
		}
		$names = QueryNames::names();
		if ( array() === $names ) {
			return;
		}
		$asset_file = dirname( $plugin_file ) . '/assets/build/short-addresses.asset.php';
		$asset      = is_readable( $asset_file ) ? require $asset_file : array();
		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			plugins_url( 'assets/build/short-addresses.js', $plugin_file ),
			is_array( $asset ) && is_array( $asset['dependencies'] ?? null ) ? $asset['dependencies'] : array(),
			is_array( $asset ) && is_string( $asset['version'] ?? null ) ? $asset['version'] : false,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_add_inline_script( self::SCRIPT_HANDLE, self::names_script(), 'before' );
	}

	/**
	 * The script printing the names for `assets/src/storefront/query-names.ts`.
	 */
	public static function names_script(): string {
		return 'window.' . self::NAMES_GLOBAL . ' = ' . wp_json_encode( (object) QueryNames::names() ) . ';';
	}

	/**
	 * A request's address with its short names given as WooCommerce's
	 * filters, in their place.
	 *
	 * @param string $uri The address, from its path on.
	 */
	private static function lengthened_uri( string $uri ): string {
		$mark = strpos( $uri, '?' );
		if ( false === $mark ) {
			return $uri;
		}
		parse_str( substr( $uri, $mark + 1 ), $args );
		$added = QueryNames::lengthened( $args, false );
		if ( array() === $added ) {
			return $uri;
		}
		$short = array_intersect_key( array_flip( QueryNames::names() ), $args );
		$kept  = array();
		foreach ( explode( '&', substr( $uri, $mark + 1 ) ) as $pair ) {
			$key = urldecode( explode( '=', $pair, 2 )[0] );
			if ( '' !== $pair && ! isset( $short[ $key ] ) ) {
				$kept[] = $pair;
			}
		}
		$kept[] = http_build_query( $added, '', '&', PHP_QUERY_RFC3986 );
		return substr( $uri, 0, $mark ) . '?' . implode( '&', $kept );
	}
}
