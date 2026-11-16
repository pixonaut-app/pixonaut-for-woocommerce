<?php
/**
 * Reads kept for the rest of the request.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

/**
 * Keeps what a page reads many times, such as each variation's
 * configuration or a product's designer, for the rest of the request.
 *
 * A product page asks for them from the gallery, the form, the button, the
 * swatches and every price, and each answer walks every variation. Nothing
 * kept is used after a post, meta or term write, or a write to one of the
 * plugin's or WooCommerce's options; a caller's key names everything else
 * its read depends on.
 */
final class RequestCache {

	/**
	 * The writes after which nothing kept is used.
	 */
	private const WRITES = array(
		'added_post_meta',
		'updated_post_meta',
		'deleted_post_meta',
		'clean_post_cache',
		'clean_term_cache',
		'woocommerce_attribute_updated',
		'woocommerce_attribute_deleted',
	);

	/**
	 * The option writes after which nothing kept is used, when the option
	 * is the plugin's or WooCommerce's.
	 */
	private const OPTION_WRITES = array( 'added_option', 'updated_option', 'deleted_option' );

	/**
	 * Start of the names of the options whose writes count.
	 */
	private const OPTION_PREFIXES = array( 'pixonaut_wc_', 'woocommerce_' );

	/**
	 * How many reads are kept before starting over, so a request walking
	 * the whole catalogue does not hold every one.
	 */
	private const MAX_KEPT = 200;

	/**
	 * The reads kept, by group and key.
	 *
	 * @var array<string, mixed>
	 */
	private static array $kept = array();

	/**
	 * Forgets what is kept on every write that can change it.
	 */
	public static function register(): void {
		foreach ( self::WRITES as $action ) {
			add_action( $action, array( self::class, 'forget' ) );
		}
		foreach ( self::OPTION_WRITES as $action ) {
			add_action( $action, array( self::class, 'forget_on_option' ) );
		}
	}

	/**
	 * Forgets every read kept.
	 */
	public static function forget(): void {
		self::$kept = array();
	}

	/**
	 * Forgets every read kept when one of the options that count is written.
	 *
	 * @param mixed $option The option's name.
	 */
	public static function forget_on_option( mixed $option ): void {
		foreach ( self::OPTION_PREFIXES as $prefix ) {
			if ( is_string( $option ) && str_starts_with( $option, $prefix ) ) {
				self::forget();
				return;
			}
		}
	}

	/**
	 * What `$read` answers, read once for the key.
	 *
	 * @template T
	 * @param string        $key  Everything the read depends on, besides the writes that forget it.
	 * @param callable(): T $read The read.
	 * @return T
	 */
	public static function remember( string $key, callable $read ): mixed {
		if ( ! array_key_exists( $key, self::$kept ) ) {
			if ( count( self::$kept ) >= self::MAX_KEPT ) {
				self::$kept = array();
			}
			self::$kept[ $key ] = $read();
		}
		return self::$kept[ $key ];
	}

	/**
	 * The callbacks on some filters, as part of a key: a read through them
	 * is read again once one is added or removed.
	 *
	 * @param string[] $filters The filters' names.
	 * @phpstan-param list<string> $filters
	 */
	public static function filters_key( array $filters ): string {
		global $wp_filter;
		$parts = array();
		foreach ( $filters as $filter ) {
			$hook = $wp_filter[ $filter ] ?? null;
			if ( ! $hook instanceof \WP_Hook ) {
				continue;
			}
			foreach ( $hook->callbacks as $priority => $callbacks ) {
				$parts[] = $filter . '@' . $priority . ':' . implode( ',', array_keys( $callbacks ) );
			}
		}
		return implode( ';', $parts );
	}
}
