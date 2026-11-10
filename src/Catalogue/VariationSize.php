<?php
/**
 * The print size a variation is sold at instead of its product's.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use Pixonaut\WooCommerce\Schema;
use WC_Product;

/**
 * A variation sells what its product's (or its own) configuration says,
 * with two things of its own: on a blank whose print areas change with an
 * option, a side sold at the placement's default prints at the variation's
 * blank variant's area (`RowSize`), and any personalised side can be given
 * a size of its own here, without overriding the rest of its setup. A side
 * with a size per attribute value (`ValueSizes`) prints at its value's size
 * unless the variation has one of its own. Each such size is kept with
 * Pixonaut's verdict and what it was judged for (the template version,
 * blank, placement and variant), stored as JSON in the variation's
 * `_pixonaut_print_size` meta. A refused size, or one judged for what the
 * variation no longer sells, stays as entered and makes the variation
 * unsellable, saying why; a value's size the product no longer gives is
 * not sold.
 */
final class VariationSize {

	public const META_KEY = Schema::VARIATION_PRINT_SIZE_META;

	public const SCHEMA_VERSION = 1;

	/**
	 * @param array<string, array{size: PrintSize, refused: string|null, basis: string, value?: bool}> $sizes The sizes by side key: the size, why Pixonaut refused it (null when it did not), what it was judged for (`basis()`), and whether it is the variation's attribute value's size rather than its own.
	 */
	public function __construct( public readonly array $sizes = array() ) {
	}

	/**
	 * The variation's own size of a side; null when it has none.
	 *
	 * @param string $key The side's key.
	 */
	public function size_of( string $key ): ?PrintSize {
		$entry = $this->sizes[ $key ] ?? null;
		return null === $entry || self::is_value( $entry ) ? null : $entry['size'];
	}

	/**
	 * The variation's own sizes, by side key, without its attribute values'.
	 *
	 * @return array<string, PrintSize>
	 */
	public function own(): array {
		$own = array();
		foreach ( $this->sizes as $key => $entry ) {
			if ( ! self::is_value( $entry ) ) {
				$own[ $key ] = $entry['size'];
			}
		}
		return $own;
	}

	/**
	 * Why Pixonaut refused the size a side prints at, the variation's own or
	 * its attribute value's; null when it did not, or there is none.
	 *
	 * @param string $key The side's key.
	 */
	public function refused( string $key ): ?string {
		return $this->sizes[ $key ]['refused'] ?? null;
	}

	/**
	 * Whether an entry is the size of the variation's attribute value.
	 *
	 * @param array{size: PrintSize, refused: string|null, basis: string, value?: bool} $entry An entry of `$sizes`.
	 */
	public static function is_value( array $entry ): bool {
		return true === ( $entry['value'] ?? false );
	}

	/**
	 * The size of a side a variation prints at by its attribute value, as
	 * its product gives it now; null for none.
	 *
	 * @param Side                 $side       The side.
	 * @param array<string, mixed> $attributes The variation's attributes (`ValueSizes::attributes_of()`).
	 */
	public static function value_size( Side $side, array $attributes ): ?PrintSize {
		return $side->is_standing() ? null : $side->value_sizes?->size_for( $attributes );
	}

	/**
	 * Whether a variable product's enabled variations, one at least, each
	 * override its setup or have a print size for every side the product
	 * gives none, their own or their attribute value's: a product without a
	 * size of its own then needs none.
	 *
	 * @param WC_Product    $product A variable product.
	 * @param ProductConfig $config  Its configuration.
	 */
	public static function covers_variations( WC_Product $product, ProductConfig $config ): bool {
		$unsized = array_filter( $config->sides, static fn ( Side $side ): bool => array() === $side->print_sizes && null === $side->pending_sizes );
		$enabled = 0;
		foreach ( $product->is_type( 'variable' ) ? $product->get_children() : array() as $child_id ) {
			$variation = wc_get_product( $child_id );
			// A disabled variation is stored as private, and is not sold.
			if ( ! $variation instanceof WC_Product || ProductConfig::PUBLIC_STATUS !== $variation->get_status() ) {
				continue;
			}
			++$enabled;
			if ( ProductConfig::stored_on( $variation )->override ) {
				continue;
			}
			$own        = self::stored_on( $variation );
			$attributes = ValueSizes::attributes_of( $variation );
			foreach ( $unsized as $side ) {
				if ( $side->is_standing() || ( null === $own->size_of( $side->key ) && null === self::value_size( $side, $attributes ) ) ) {
					return false;
				}
			}
		}
		// Variations that all override use none of the product's sizes; a product with none enabled sells nothing.
		return array() !== $unsized && $enabled > 0;
	}

	/**
	 * What a size of a side is judged for: its template version, blank,
	 * placement and the variant sold. A size judged for anything else is
	 * judged again before it is sold.
	 *
	 * @param Side        $side     The side.
	 * @param string|null $blank_id The blank; null for none.
	 * @param string|null $variant  The variant sold; null for none.
	 */
	public static function basis( Side $side, ?string $blank_id, ?string $variant ): string {
		return (string) wp_json_encode( array( $side->template_base_id, $side->template_version, $blank_id, $side->placement_id, $variant ) );
	}

	/**
	 * The configuration a variation is sold under: each personalised side
	 * sold at the placement's default printing at the variant's row, and
	 * each side the variation has a size of its own for, or its attribute
	 * value has one for, selling that size alone. A size refused, judged for
	 * something else, or a variant in no row the template can be printed at
	 * makes it unsellable, saying why. A product sold without a size of its
	 * own sells a variation that has one for every side.
	 *
	 * @param ProductConfig        $config     What the variation inherits or overrides with.
	 * @param string|null          $variant    The blank variant it sells; null for none.
	 * @param array<string, mixed> $attributes The variation's attributes (`ValueSizes::attributes_of()`).
	 */
	public function applied_to( ProductConfig $config, ?string $variant, array $attributes = array() ): ProductConfig {
		$sides  = array();
		$reason = null;
		foreach ( $config->sides as $side ) {
			if ( $side->is_standing() ) {
				$sides[] = $side;
				continue;
			}
			$held  = $this->sizes[ $side->key ] ?? null;
			$value = self::value_size( $side, $attributes );
			if ( null !== $held && self::is_value( $held ) && ( null === $value || $value->stored() !== $held['size']->stored() ) ) {
				// The product's size for the value changed since it was judged.
				$held = null;
			}
			if ( null === $held && null !== $value ) {
				$reason ??= InvalidReason::VARIATION_SIZE_UNCHECKED;
				$sides[] = $side->with( $side->template_version, $side->previous_version, array( $value ), null, 0 );
				continue;
			}
			if ( null !== $held ) {
				if ( self::basis( $side, $config->blank_id, $variant ) !== $held['basis'] ) {
					$reason ??= InvalidReason::VARIATION_SIZE_UNCHECKED;
				} elseif ( null !== $held['refused'] ) {
					$reason ??= $held['refused'];
				}
				$sides[] = $side->with( $side->template_version, $side->previous_version, array( $held['size'] ), null, 0 );
				continue;
			}
			$sides[] = self::at_row( $side, $variant, $reason );
		}
		$applied = $config->with_sides( $sides );
		if ( ! $config->valid && ( InvalidReason::MISSING_PRINT_SIZE !== $config->invalid_reason || ! self::all_sized( $sides ) ) ) {
			return $applied;
		}
		return null === $reason ? $applied->with_validity( true ) : $applied->with_validity( false, $reason );
	}

	/**
	 * Whether every side sells at least one size.
	 *
	 * @param Side[] $sides The sides.
	 */
	private static function all_sized( array $sides ): bool {
		foreach ( $sides as $side ) {
			if ( array() === $side->print_sizes ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * A side with its placement's default replaced by what it prints at on
	 * the variant's row. A variant in no row, or in one the template cannot
	 * be printed at, sells nothing at the default.
	 *
	 * @param Side        $side    A personalised side.
	 * @param string|null $variant The variant sold.
	 * @param string|null $reason  Why the variation cannot be sold, set when this side cannot be.
	 */
	private static function at_row( Side $side, ?string $variant, ?string &$reason ): Side {
		if ( array() === $side->row_sizes || null !== $side->pending_sizes ) {
			return $side;
		}
		$sizes = array();
		$row   = $side->row_size_for( $variant );
		foreach ( $side->print_sizes as $size ) {
			if ( ! $size->placement_default ) {
				$sizes[] = $size;
				continue;
			}
			if ( null === $row?->size ) {
				$reason ??= InvalidReason::VARIANT_SIZE_UNFIT;
				continue;
			}
			$sizes[] = $row->size;
		}
		return $sizes === $side->print_sizes ? $side : $side->with( $side->template_version, $side->previous_version, $sizes, null );
	}

	/**
	 * The sizes stored on a variation; none when it has none or they cannot be read.
	 *
	 * @param WC_Product $variation The variation.
	 */
	public static function stored_on( WC_Product $variation ): self {
		return self::from_document( self::document_on( $variation ) );
	}

	/**
	 * The stored document as it is, for `replace_on()` to write over only it.
	 *
	 * @param WC_Product $variation The variation.
	 * @return string|null Null when none is stored.
	 */
	public static function document_on( WC_Product $variation ): ?string {
		$raw = $variation->get_meta( self::META_KEY, true );
		return is_string( $raw ) && '' !== $raw ? $raw : null;
	}

	/**
	 * The sizes a stored document holds; none for null or one that cannot be read.
	 *
	 * @param string|null $document A document as `document_on()` read it.
	 */
	public static function from_document( ?string $document ): self {
		$data = null === $document ? null : json_decode( $document, true );
		if ( ! is_array( $data ) || self::SCHEMA_VERSION !== ( $data['v'] ?? null ) || ! is_array( $data['sides'] ?? null ) ) {
			return new self();
		}
		$sizes = array();
		foreach ( $data['sides'] as $key => $entry ) {
			$size = is_array( $entry ) ? PrintSize::from_array( $entry['size'] ?? null ) : null;
			if ( null === $size || ! is_string( $entry['basis'] ?? null ) ) {
				continue;
			}
			$sizes[ (string) $key ] = array(
				'size'    => $size,
				'refused' => is_string( $entry['refused'] ?? null ) && '' !== $entry['refused'] ? $entry['refused'] : null,
				'basis'   => $entry['basis'],
			) + ( true === ( $entry['value'] ?? null ) ? array( 'value' => true ) : array() );
		}
		return new self( $sizes );
	}

	/**
	 * Stores the sizes on a variation only while the stored document is
	 * still $expected, so a check that took a while never overwrites a size
	 * the store manager saved meanwhile, nor restores one removed. Writes
	 * the database directly; $variation itself is left as it was read.
	 *
	 * @param WC_Product $variation The variation.
	 * @param string     $expected  The document the sizes were judged from, as `document_on()` read it.
	 * @return bool False when another document was stored meanwhile, or none is; nothing is written then.
	 */
	public function replace_on( WC_Product $variation, string $expected ): bool {
		global $wpdb;
		$variation_id = $variation->get_id();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- one conditional query on the variation's own row; its caches are cleared below.
		$where = array(
			'post_id'    => $variation_id,
			'meta_key'   => self::META_KEY,
			'meta_value' => $expected,
		);
		// No row matches once another document is stored, or none is.
		$changed = array() === $this->sizes
			? $wpdb->delete( $wpdb->postmeta, $where )
			: $wpdb->update( $wpdb->postmeta, array( 'meta_value' => (string) wp_json_encode( $this->to_array() ) ), $where );
		// phpcs:enable
		if ( ! is_int( $changed ) || $changed < 1 ) {
			return false;
		}
		clean_post_cache( $variation_id );
		wp_cache_delete( (string) $variation_id, 'post_meta' );
		wc_delete_product_transients( $variation_id );
		return true;
	}

	/**
	 * Stores the sizes on a variation only while it has none stored, so a
	 * check that took a while never overwrites a size the store manager
	 * saved meanwhile.
	 *
	 * @param WC_Product $variation The variation.
	 * @return bool False when sizes were stored meanwhile, or there are none to store.
	 */
	public function add_to( WC_Product $variation ): bool {
		if ( array() === $this->sizes ) {
			return false;
		}
		return false !== add_post_meta( $variation->get_id(), self::META_KEY, wp_slash( (string) wp_json_encode( $this->to_array() ) ), true );
	}

	/**
	 * The sizes as the variation stores them.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$sides = array();
		foreach ( $this->sizes as $key => $entry ) {
			$sides[ $key ] = array(
				'size'    => $entry['size']->stored(),
				'refused' => $entry['refused'],
				'basis'   => $entry['basis'],
			) + ( self::is_value( $entry ) ? array( 'value' => true ) : array() );
		}
		return array(
			'v'     => self::SCHEMA_VERSION,
			'sides' => $sides,
		);
	}

	/**
	 * Stores the sizes on a variation; with none, removes them.
	 *
	 * @param WC_Product $variation The variation.
	 */
	public function save_to( WC_Product $variation ): void {
		if ( array() === $this->sizes ) {
			$variation->delete_meta_data( self::META_KEY );
		} else {
			$variation->update_meta_data( self::META_KEY, (string) wp_json_encode( $this->to_array() ) );
		}
		$variation->save_meta_data();
	}
}
