<?php
/**
 * Which attribute each dependent attribute applies under, for a theme's filters.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use Pixonaut\WooCommerce\Catalogue\ApplicableAttributes;
use Pixonaut\WooCommerce\Catalogue\FilterDataRefresh;
use Pixonaut\WooCommerce\Catalogue\FilterIndex;
use Pixonaut\WooCommerce\Catalogue\SplitCards;
use WC_Cache_Helper;
use WC_Product_Attribute;
use WC_Product_Variable;

/**
 * A theme's filter panel can nest an attribute that applies only under some
 * values of another (a frame colour under "framed" and "hanger") beneath
 * those values. The plugin reads that from the variations the merchant set
 * up, by the same rule that hides an attribute from the product's form
 * (`ApplicableAttributes::parents()`), never from attribute or value names,
 * across every published variable product, and serves it on the
 * `pixonaut_wc_storefront_attribute_dependencies` filter:
 *
 *     $dependencies = apply_filters( 'pixonaut_wc_storefront_attribute_dependencies', array() );
 *     // array( 'pa_ramfarg' => array( 'pa_inramning' => array( 'ram', 'hangare' ) ) )
 *
 * Each dependent attribute's taxonomy maps to the taxonomy of each attribute
 * it applies under, and the slugs of the values it applies under. Only
 * global attributes take part. One product's reading adds to another's.
 * The shop's filters read the same answer (`store()`), so a dependent
 * attribute's filter leaves alone what it does not apply to
 * (`FilterMatches::exempt()`).
 *
 * The answer is kept beside WooCommerce's product cache version. Once a
 * product changes, a shopper is served it as it was kept, and it is noted
 * for the job that reads the shop's filter data again
 * (`FilterDataRefresh`), which works it out anew; only a store with
 * nothing kept is read while the page waits.
 */
final class AttributeDependencies {

	public const FILTER = 'pixonaut_wc_storefront_attribute_dependencies';

	/**
	 * The transient the store's dependencies are cached in, beside WooCommerce's product cache version.
	 */
	private const TRANSIENT = 'pixonaut_wc_attribute_dependencies';

	/**
	 * How many products are read at a time.
	 */
	private const BATCH = 100;

	public function register(): void {
		add_filter( self::FILTER, array( $this, 'serve' ) );
	}

	/**
	 * The store's dependencies, added to what the filter was given.
	 *
	 * @param mixed $dependencies What another callback answered, or the caller's default.
	 * @return array<string, array<string, list<string>>>
	 */
	public function serve( mixed $dependencies ): array {
		return self::merge( is_array( $dependencies ) ? self::shaped( $dependencies ) : array(), self::store() );
	}

	/**
	 * The store's dependencies as kept: after a product changed, as they
	 * were, until the job has worked them out again.
	 *
	 * @return array<string, array<string, list<string>>>
	 */
	public static function store(): array {
		$cached = get_transient( self::TRANSIENT );
		if ( is_array( $cached ) && is_array( $cached['dependencies'] ?? null ) ) {
			if ( ( $cached['version'] ?? null ) !== self::version() ) {
				FilterDataRefresh::dependencies();
			}
			return self::shaped( $cached['dependencies'] );
		}
		return self::current();
	}

	/**
	 * The store's dependencies as its products are now, read again unless
	 * kept since the last change, and kept: for work that does not keep a
	 * shopper waiting.
	 *
	 * @return array<string, array<string, list<string>>>
	 */
	public static function current(): array {
		$version = self::version();
		$cached  = get_transient( self::TRANSIENT );
		if ( is_array( $cached ) && ( $cached['version'] ?? null ) === $version && is_array( $cached['dependencies'] ?? null ) ) {
			return self::shaped( $cached['dependencies'] );
		}
		return self::read_and_keep( $version )['dependencies'];
	}

	/**
	 * Reads the store's dependencies again and keeps them, as the job does.
	 *
	 * @return bool Whether they were kept: not when a database query failed.
	 */
	public static function rebuild(): bool {
		return self::read_and_keep( self::version() )['kept'];
	}

	/**
	 * Reads the store's dependencies and keeps them under the version read
	 * before them, so a change made meanwhile is read again. Nothing is
	 * kept when a database query failed, which may have read a product's
	 * variations as none.
	 *
	 * @param string $version WooCommerce's product cache version before the read.
	 * @return array{dependencies: array<string, array<string, list<string>>>, kept: bool}
	 */
	private static function read_and_keep( string $version ): array {
		$errors       = FilterIndex::database_errors();
		$dependencies = self::read();
		$kept         = FilterIndex::database_errors() === $errors;
		if ( $kept ) {
			set_transient(
				self::TRANSIENT,
				array(
					'version'      => $version,
					'dependencies' => $dependencies,
				)
			);
		}
		return array(
			'dependencies' => $dependencies,
			'kept'         => $kept,
		);
	}

	/**
	 * WooCommerce's product cache version, which moves whenever a product changes.
	 */
	private static function version(): string {
		return class_exists( WC_Cache_Helper::class ) ? (string) WC_Cache_Helper::get_transient_version( 'product' ) : '';
	}

	/**
	 * Reads every published variable product's dependencies.
	 *
	 * @return array<string, array<string, list<string>>>
	 */
	private static function read(): array {
		$dependencies = array();
		$page         = 1;
		do {
			$ids = wc_get_products(
				array(
					'type'    => 'variable',
					'status'  => 'publish',
					'limit'   => self::BATCH,
					'page'    => $page,
					'return'  => 'ids',
					'orderby' => 'ID',
					'order'   => 'ASC',
				)
			);
			$ids = is_array( $ids ) ? array_values( array_map( 'intval', $ids ) ) : array();
			// The values kept of each product's variations since it last changed: only a changed product's are read again.
			$kept = FilterIndex::kept_values( $ids );
			_prime_post_caches( $ids, true, true );
			foreach ( $ids as $id ) {
				$product = wc_get_product( $id );
				if ( $product instanceof WC_Product_Variable ) {
					$dependencies = self::merge( $dependencies, self::of( $product, $kept[ $id ] ?? null ) );
				}
			}
			++$page;
			$more = count( $ids ) === self::BATCH;
		} while ( $more );
		return $dependencies;
	}

	/**
	 * One product's dependencies, by taxonomy.
	 *
	 * @param WC_Product_Variable                    $product A variable product.
	 * @param array<int, array<string, string>>|null $values  Its variations' attribute values, when already read (`SplitCards::variation_values()`).
	 * @return array<string, array<string, list<string>>>
	 */
	public static function of( WC_Product_Variable $product, ?array $values = null ): array {
		$taxonomies = array();
		foreach ( $product->get_attributes() as $attribute ) {
			if ( $attribute instanceof WC_Product_Attribute && $attribute->is_taxonomy() ) {
				$taxonomies[ 'attribute_' . sanitize_title( $attribute->get_name() ) ] = $attribute->get_name();
			}
		}
		$parents      = ApplicableAttributes::parents( ApplicableAttributes::fields( $product ), array_values( $values ?? SplitCards::variation_values( $product->get_id() ) ) );
		$dependencies = array();
		foreach ( $parents as $field => $parent ) {
			if ( isset( $taxonomies[ $field ], $taxonomies[ $parent['field'] ] ) ) {
				$dependencies[ $taxonomies[ $field ] ][ $taxonomies[ $parent['field'] ] ] = $parent['values'];
			}
		}
		return $dependencies;
	}

	/**
	 * Two sets of dependencies as one: the values each attribute applies
	 * under, of both.
	 *
	 * @param array<string, array<string, list<string>>> $first  Dependencies.
	 * @param array<string, array<string, list<string>>> $second More.
	 * @return array<string, array<string, list<string>>>
	 */
	private static function merge( array $first, array $second ): array {
		foreach ( $second as $taxonomy => $parents ) {
			foreach ( $parents as $parent => $values ) {
				$first[ $taxonomy ][ $parent ] = array_values( array_unique( array_merge( $first[ $taxonomy ][ $parent ] ?? array(), $values ) ) );
			}
		}
		return $first;
	}

	/**
	 * Dependencies in their shape, from a value of unknown shape: anything
	 * else is dropped.
	 *
	 * @param array<array-key, mixed> $value A value.
	 * @return array<string, array<string, list<string>>>
	 */
	private static function shaped( array $value ): array {
		$dependencies = array();
		foreach ( $value as $taxonomy => $parents ) {
			if ( ! is_string( $taxonomy ) || ! is_array( $parents ) ) {
				continue;
			}
			foreach ( $parents as $parent => $values ) {
				if ( is_string( $parent ) && is_array( $values ) ) {
					$dependencies[ $taxonomy ][ $parent ] = array_values( array_map( 'strval', array_filter( $values, 'is_scalar' ) ) );
				}
			}
		}
		return $dependencies;
	}
}
