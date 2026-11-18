<?php
/**
 * The blank option a global attribute stands for, value by value.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use Pixonaut\WooCommerce\Schema;
use WC_Product;
use WC_Product_Attribute;
use WP_Term;

/**
 * A global attribute can stand for one of the blank's options, such as a
 * store's Ramfärg for a frame's Color, and each of its values for one of the
 * option's values, Svart for Black. The store manager links them once per
 * attribute, on its edit screen or by tool. A linked attribute names its
 * blank variant by the option, and its swatches take the option values'
 * looks. A value with no value of its own stands for the option's value of
 * its name.
 *
 * Both are kept as Pixonaut writes them, so a link holds whatever language
 * the designer reads the blank in.
 *
 * @phpstan-type Link array{option: string, values: array<int, string>}
 */
final class BlankOptionLinks {

	/**
	 * Each linked global attribute's link, by attribute id.
	 */
	public const OPTION = Schema::BLANK_OPTION_LINKS_OPTION;

	/**
	 * Longest option or value name kept; Pixonaut's are far shorter.
	 */
	public const MAX_LENGTH = 200;

	/**
	 * How many products a changed link re-checks at once.
	 */
	private const RECHECK_BATCH = 100;

	/**
	 * Arguments of `delete_term`.
	 */
	private const DELETE_TERM_ARGS = 3;

	/**
	 * Forgets a deleted attribute's link and a deleted value's, however it
	 * was deleted.
	 */
	public static function register(): void {
		add_action( 'woocommerce_attribute_deleted', array( self::class, 'forget_attribute' ) );
		add_action( 'delete_term', array( self::class, 'forget_term' ), 10, self::DELETE_TERM_ARGS );
	}

	/**
	 * @param int $attribute_id The deleted attribute.
	 */
	public static function forget_attribute( int $attribute_id ): void {
		self::write( $attribute_id, null );
	}

	/**
	 * @param int    $term_id  The deleted term.
	 * @param int    $tt_id    Its term taxonomy id.
	 * @param string $taxonomy Its taxonomy.
	 */
	public static function forget_term( int $term_id, int $tt_id, string $taxonomy ): void {
		unset( $tt_id );
		$attribute_id = self::attribute_id( $taxonomy );
		$link         = $attribute_id > 0 ? self::of( $attribute_id ) : null;
		if ( null === $link || ! isset( $link['values'][ $term_id ] ) ) {
			return;
		}
		unset( $link['values'][ $term_id ] );
		self::write( $attribute_id, $link );
	}

	/**
	 * A global attribute's link; null when it stands for no option.
	 *
	 * @param int $attribute_id A global attribute's id.
	 * @return Link|null
	 */
	public static function of( int $attribute_id ): ?array {
		return self::stored()[ $attribute_id ] ?? null;
	}

	/**
	 * The option an attribute stands for, as Pixonaut writes it; null when
	 * it is not linked or not a global attribute.
	 *
	 * @param string $taxonomy The attribute's name, `pa_frame` for a global one.
	 */
	public static function option_of( string $taxonomy ): ?string {
		$attribute_id = self::attribute_id( $taxonomy );
		return $attribute_id > 0 ? self::of( $attribute_id )['option'] ?? null : null;
	}

	/**
	 * The option value a value of a linked attribute was linked to; null
	 * when it was not given one.
	 *
	 * @param string $taxonomy The attribute's taxonomy.
	 * @param int    $term_id  One of its terms.
	 */
	public static function value_of( string $taxonomy, int $term_id ): ?string {
		$attribute_id = self::attribute_id( $taxonomy );
		return $attribute_id > 0 ? self::of( $attribute_id )['values'][ $term_id ] ?? null : null;
	}

	/**
	 * The option value a value of a linked attribute stands for: the one it
	 * was linked to, else the value of its name.
	 *
	 * @param string  $taxonomy The attribute's taxonomy.
	 * @param WP_Term $term     One of its terms.
	 */
	public static function stands_for( string $taxonomy, WP_Term $term ): string {
		return self::value_of( $taxonomy, $term->term_id ) ?? $term->name;
	}

	/**
	 * Links a global attribute to an option, its values to the option's
	 * values given, and re-checks the products using it, whose variants it
	 * may name otherwise now. An empty option unlinks the attribute; an
	 * empty value leaves a value to stand for the option's value of its name.
	 *
	 * @param int                $attribute_id A global attribute's id.
	 * @param string             $option       The option, as Pixonaut writes it; empty to unlink.
	 * @param array<int, string> $values       Option values by term id; every other value is left unlinked.
	 * @return bool False when the link changed but its products could not be queued for a re-check (`ProductRecheck` logs why); they keep their variants until they are re-checked.
	 */
	public static function set( int $attribute_id, string $option, array $values ): bool {
		$option = self::clean( $option );
		$link   = null;
		if ( '' !== $option ) {
			$link = array(
				'option' => $option,
				'values' => array(),
			);
			foreach ( $values as $term_id => $value ) {
				$value = self::clean( $value );
				if ( $term_id > 0 && '' !== $value ) {
					$link['values'][ $term_id ] = $value;
				}
			}
			ksort( $link['values'] );
		}
		if ( self::of( $attribute_id ) === $link ) {
			return true;
		}
		self::write( $attribute_id, $link );
		$taxonomy = wc_attribute_taxonomy_name_by_id( $attribute_id );
		$queued   = true;
		// The products' variants are matched with the link from now on.
		foreach ( array_chunk( '' === $taxonomy ? array() : ProductIndex::products_with_attribute( $taxonomy ), self::RECHECK_BATCH ) as $batch ) {
			$queued = ProductRecheck::queue_soon( $batch ) && $queued;
		}
		return $queued;
	}

	/**
	 * The options a product or variation is by its linked attributes, as
	 * labels, `Color => Black`, beside `ProductVariants::attributes()`: a
	 * variation's value, or a product's one value, of each linked attribute.
	 *
	 * @param WC_Product $product A product or variation.
	 * @return array<string, string>
	 */
	public static function labels( WC_Product $product ): array {
		if ( array() === self::stored() ) {
			return array();
		}
		$labels = array();
		foreach ( $product->get_attributes() as $name => $value ) {
			$option = is_string( $name ) ? self::option_of( $name ) : null;
			$term   = null === $option ? null : self::term( (string) $name, $value );
			if ( null !== $option && null !== $term ) {
				$labels[ $option ] = self::stands_for( (string) $name, $term );
			}
		}
		return $labels;
	}

	/**
	 * The linked attributes a product has, by taxonomy.
	 *
	 * @param WC_Product $product A product.
	 * @return list<string>
	 */
	public static function linked_in( WC_Product $product ): array {
		if ( array() === self::stored() ) {
			return array();
		}
		$linked = array();
		foreach ( array_keys( $product->get_attributes() ) as $name ) {
			if ( is_string( $name ) && null !== self::option_of( $name ) ) {
				$linked[] = $name;
			}
		}
		return $linked;
	}

	/**
	 * The option value of a term's name, case and spacing aside; null for none.
	 *
	 * @param string   $name    The term's name.
	 * @param string[] $offered The option's values.
	 * @phpstan-param list<string> $offered
	 */
	public static function same_name( string $name, array $offered ): ?string {
		foreach ( $offered as $value ) {
			if ( ProductVariant::normalise( $value ) === ProductVariant::normalise( $name ) ) {
				return $value;
			}
		}
		return null;
	}

	/**
	 * An attribute's terms in the store's order.
	 *
	 * @param string $taxonomy The attribute's taxonomy.
	 * @return list<WP_Term>
	 */
	public static function terms( string $taxonomy ): array {
		if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			)
		);
		return is_array( $terms ) ? array_values( $terms ) : array();
	}

	/**
	 * A global attribute's id by its taxonomy; 0 for none.
	 *
	 * @param string $taxonomy An attribute's name.
	 */
	public static function attribute_id( string $taxonomy ): int {
		return str_starts_with( $taxonomy, 'pa_' ) ? wc_attribute_taxonomy_id_by_name( $taxonomy ) : 0;
	}

	/**
	 * A variation's term of an attribute, or a product's one term.
	 *
	 * @param string $taxonomy The attribute's taxonomy.
	 * @param mixed  $value    A variation's slug, or a product's `WC_Product_Attribute`.
	 */
	private static function term( string $taxonomy, mixed $value ): ?WP_Term {
		if ( $value instanceof WC_Product_Attribute ) {
			$options = $value->get_options();
			$term    = 1 === count( $options ) && $value->is_taxonomy() ? get_term( (int) $options[0], $taxonomy ) : null;
		} else {
			$term = is_string( $value ) && '' !== $value ? get_term_by( 'slug', $value, $taxonomy ) : null;
		}
		return $term instanceof WP_Term ? $term : null;
	}

	/**
	 * @param int       $attribute_id A global attribute's id.
	 * @param Link|null $link         Its link; null for none.
	 */
	private static function write( int $attribute_id, ?array $link ): void {
		$links = self::stored();
		unset( $links[ $attribute_id ] );
		if ( null !== $link ) {
			$links[ $attribute_id ] = $link;
			ksort( $links );
		}
		if ( array() === $links ) {
			delete_option( self::OPTION );
			return;
		}
		update_option( self::OPTION, $links, true );
	}

	/**
	 * A name as kept: trimmed, at most `MAX_LENGTH` characters.
	 *
	 * @param string $name An option's or value's name.
	 */
	private static function clean( string $name ): string {
		return mb_substr( trim( sanitize_text_field( $name ) ), 0, self::MAX_LENGTH );
	}

	/**
	 * @return array<int, Link>
	 */
	private static function stored(): array {
		$stored = get_option( self::OPTION, array() );
		$links  = array();
		foreach ( is_array( $stored ) ? $stored : array() as $attribute_id => $link ) {
			if ( ! is_int( $attribute_id ) || ! is_array( $link ) || ! is_string( $link['option'] ?? null ) || '' === $link['option'] ) {
				continue;
			}
			$values = array();
			foreach ( is_array( $link['values'] ?? null ) ? $link['values'] : array() as $term_id => $value ) {
				if ( is_int( $term_id ) && is_string( $value ) && '' !== $value ) {
					$values[ $term_id ] = $value;
				}
			}
			$links[ $attribute_id ] = array(
				'option' => $link['option'],
				'values' => $values,
			);
		}
		return $links;
	}
}
