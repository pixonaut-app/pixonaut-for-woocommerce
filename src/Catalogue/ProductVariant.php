<?php
/**
 * The blank variant a product or variation sells.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use Pixonaut\WooCommerce\Schema;
use WC_Cache_Helper;
use WC_Product;

/**
 * Which of its blank's variants a product or variation personalised on a
 * blank is, stored as JSON in its `_pixonaut_variant` meta. The store
 * manager may pick one; otherwise the variant whose option labels match its
 * attributes is used (a product's attributes with one value each, a
 * variation's own). What Pixonaut said of the variant when it was last read
 * is kept with it (its options, whether it is retired, the views it has a
 * photo in), so the storefront never waits on Pixonaut to tell whether it
 * can be bought.
 *
 * A match keeps the attribute labels it was made from: once they change,
 * by whatever means the product was saved, it sells nothing until its
 * variant is resolved again, rather than the variant of its old options.
 *
 * One with no variant, or a retired one, cannot be designed or bought:
 * Pixonaut refuses such an order, and the storefront says so first.
 */
final class ProductVariant {

	public const META_KEY = Schema::PRODUCT_VARIANT_META;

	public const SCHEMA_VERSION = 2;

	/**
	 * The first version stored. It named the views with a photo by label
	 * alone; those read as none until the variant is resolved again.
	 */
	private const FIRST_SCHEMA_VERSION = 1;

	/**
	 * No variant of the blank is the product's.
	 */
	public const UNMAPPED = 'unmapped';

	/**
	 * The product's variant is retired.
	 */
	public const RETIRED = 'retired';

	/**
	 * @param string                     $blank_id The blank the variant was resolved on.
	 * @param string|null                $pick     The variant the store manager picked; null to match by options.
	 * @param string|null                $ref      The variant used: the pick while the blank has it, else the match; null for none.
	 * @param string                     $label    Its options as the store manager reads them; empty without a variant.
	 * @param string|null                $retired  Why it is retired; null while it is sold.
	 * @param array[]                    $views    The views it has a photo in, as `BlankVariant::$views` lists them.
	 * @param array<string, string>|null $matched_on The attribute labels a match was made from; null for a pick.
	 * @phpstan-param list<array{id: string, placement: string, label: string}> $views
	 */
	public function __construct(
		public readonly string $blank_id,
		public readonly ?string $pick = null,
		public readonly ?string $ref = null,
		public readonly string $label = '',
		public readonly ?string $retired = null,
		public readonly array $views = array(),
		public readonly ?array $matched_on = null,
	) {
	}

	/**
	 * The variant of `$variants` a product or variation is: `$pick` when the
	 * blank has it, retired or not, else, without a pick, the one variant
	 * whose every option matches one of its attributes by label, case and
	 * spacing aside. Only the variants on sale are matched while one of them
	 * matches: a printshop's retired sizes often share the labels of those on
	 * sale. A retired one matched alone is kept, so it reads as retired
	 * rather than as no variant. Several matches are no match. A picked
	 * variant the blank no longer has is no variant, rather than a guess.
	 *
	 * @param string                $blank_id   The blank.
	 * @param string|null           $pick       The store manager's pick.
	 * @param array<string, string> $attributes Its attribute labels by attribute name, `Color => Red`.
	 * @param BlankVariant[]        $variants   The blank's variants, retired ones included.
	 * @phpstan-param list<BlankVariant> $variants
	 */
	public static function resolve( string $blank_id, ?string $pick, array $attributes, array $variants ): self {
		$found      = null;
		$matched_on = null === $pick ? $attributes : null;
		if ( null !== $pick ) {
			foreach ( $variants as $variant ) {
				if ( $variant->ref === $pick ) {
					$found = $variant;
				}
			}
		} else {
			$matches = array_values( array_filter( $variants, static fn ( BlankVariant $variant ): bool => self::matches( $variant, $attributes ) ) );
			$on_sale = array_values( array_filter( $matches, static fn ( BlankVariant $variant ): bool => null === $variant->retired ) );
			$matches = array() === $on_sale ? $matches : $on_sale;
			$found   = 1 === count( $matches ) ? $matches[0] : null;
		}
		return null === $found
			? new self( $blank_id, $pick, matched_on: $matched_on )
			: new self( $blank_id, $pick, $found->ref, $found->label(), $found->retired, $found->views, $matched_on );
	}

	/**
	 * Why it cannot be bought: `UNMAPPED`, `RETIRED`, or null when it can.
	 */
	public function problem(): ?string {
		if ( null === $this->ref ) {
			return self::UNMAPPED;
		}
		return null === $this->retired ? null : self::RETIRED;
	}

	/**
	 * The sides of a configuration the variant has no photo of in a view the
	 * configuration shows: the designer cannot show the variant on them.
	 *
	 * @param ProductConfig $config The configuration it is sold under.
	 * @return list<Side>
	 */
	public function sides_without_photo( ProductConfig $config ): array {
		$missing = array();
		foreach ( $config->sides as $side ) {
			$shown = array_filter(
				$this->views,
				static fn ( array $view ): bool => $view['placement'] === $side->placement_id
					&& ( array() === $config->views || in_array( $view['id'], $config->views, true ) )
			);
			if ( null !== $side->placement_id && array() === $shown ) {
				$missing[] = $side;
			}
		}
		return $missing;
	}

	/**
	 * The first of a placement's views the variant has a photo in and the
	 * configuration shows, in the blank's order; null for none. Pixonaut
	 * draws a variant only on a photo of it.
	 *
	 * @param string        $placement_id One of the blank's placements.
	 * @param ProductConfig $config       The configuration it is sold under.
	 */
	public function first_view( string $placement_id, ProductConfig $config ): ?string {
		foreach ( $this->views as $view ) {
			if ( $view['placement'] === $placement_id && ( array() === $config->views || in_array( $view['id'], $config->views, true ) ) ) {
				return $view['id'];
			}
		}
		return null;
	}

	/**
	 * The variant a product or variation sells under its configuration: its
	 * ref, or null when it sells none (unmapped, retired, resolved on
	 * another blank than the configuration's, or matched on attributes it
	 * no longer has).
	 *
	 * @param WC_Product    $product The product or variation.
	 * @param ProductConfig $config  The configuration it is sold under.
	 */
	public static function sold_by( WC_Product $product, ProductConfig $config ): ?string {
		return self::sold_on( $product, $config )?->ref;
	}

	/**
	 * The variant a product or variation sells under its configuration, as
	 * `sold_by()` finds it; null when it sells none.
	 *
	 * @param WC_Product    $product The product or variation.
	 * @param ProductConfig $config  The configuration it is sold under.
	 */
	public static function sold_on( WC_Product $product, ProductConfig $config ): ?self {
		$stored = self::stored_on( $product );
		if ( null === $stored || $stored->blank_id !== $config->blank_id || null !== $stored->problem() ) {
			return null;
		}
		return $stored->is_current( $product ) ? $stored : null;
	}

	/**
	 * Whether it was resolved on the attributes the product or variation
	 * has now: a pick always is; a match, while its attributes are the
	 * ones it was made from, case and spacing aside.
	 *
	 * @param WC_Product $product The product or variation it is stored on.
	 */
	public function is_current( WC_Product $product ): bool {
		return $this->is_current_on( ProductVariants::attributes( $product ) );
	}

	/**
	 * Whether it would be current on attributes given as labels: a pick
	 * always is.
	 *
	 * @param array<string, string> $labels Attribute labels by attribute name, as `ProductVariants::attributes()`.
	 */
	public function is_current_on( array $labels ): bool {
		if ( null !== $this->pick ) {
			return true;
		}
		return null !== $this->matched_on && self::normalised( $this->matched_on ) === self::normalised( $labels );
	}

	/**
	 * Whether a product or variation can be bought as far as its variant
	 * goes: one not personalised on a blank needs none.
	 *
	 * @param WC_Product    $product The product or variation.
	 * @param ProductConfig $config  The configuration it is sold under.
	 */
	public static function sellable( WC_Product $product, ProductConfig $config ): bool {
		return ! $config->personalise || null === $config->blank_id || null !== self::sold_by( $product, $config );
	}

	/**
	 * The variant stored on a product or variation; null when none is.
	 *
	 * @param WC_Product $product The product or variation.
	 */
	public static function stored_on( WC_Product $product ): ?self {
		return self::from_document( self::document_on( $product ) );
	}

	/**
	 * The stored document as it is, for `replace_on()` to write over only it.
	 *
	 * @param WC_Product $product The product or variation.
	 * @return string|null Null when none is stored.
	 */
	public static function document_on( WC_Product $product ): ?string {
		$raw = $product->get_meta( self::META_KEY, true );
		return is_string( $raw ) && '' !== $raw ? $raw : null;
	}

	/**
	 * The variant a stored document holds; null when it holds none, or is
	 * of a version this plugin does not know. One of an earlier version
	 * keeps its pick, ref and match; views it names without an id read as
	 * none.
	 *
	 * @param string|null $document The document, as `document_on()` read it.
	 */
	public static function from_document( ?string $document ): ?self {
		$data    = null === $document ? null : json_decode( $document, true );
		$version = is_array( $data ) ? ( $data['v'] ?? null ) : null;
		if ( ! is_array( $data ) || ! is_int( $version ) || $version < self::FIRST_SCHEMA_VERSION || $version > self::SCHEMA_VERSION || ! self::is_text( $data['blankId'] ?? null ) ) {
			return null;
		}
		$views = array();
		foreach ( is_array( $data['views'] ?? null ) ? $data['views'] : array() as $view ) {
			if ( is_array( $view ) && self::is_text( $view['id'] ?? null ) && self::is_text( $view['placement'] ?? null ) && is_string( $view['label'] ?? null ) ) {
				$views[] = array(
					'id'        => $view['id'],
					'placement' => $view['placement'],
					'label'     => $view['label'],
				);
			}
		}
		$ref  = self::is_text( $data['ref'] ?? null ) ? $data['ref'] : null;
		$pick = self::is_text( $data['pick'] ?? null ) ? $data['pick'] : null;
		return new self(
			$data['blankId'],
			$pick,
			$ref,
			null !== $ref && is_string( $data['label'] ?? null ) ? $data['label'] : '',
			null !== $ref && self::is_text( $data['retired'] ?? null ) ? $data['retired'] : null,
			null === $ref ? array() : $views,
			null === $pick ? self::labels( $data['matchedOn'] ?? null ) : null,
		);
	}

	/**
	 * Stores the variant on a product or variation, unless it is stored already.
	 *
	 * @param WC_Product $product The product or variation.
	 */
	public function save_to( WC_Product $product ): void {
		$json = (string) wp_json_encode( $this->to_array() );
		if ( $product->get_meta( self::META_KEY, true ) === $json ) {
			return;
		}
		$product->update_meta_data( self::META_KEY, $json );
		$product->save_meta_data();
	}

	/**
	 * Stores the variant on a product or variation only while the stored
	 * document is still $expected, so a resolve that waited on Pixonaut
	 * never overwrites a pick saved meanwhile. Writes the database
	 * directly; $product itself is left as it was read.
	 *
	 * @param WC_Product  $product  The product or variation.
	 * @param string|null $expected The document read before resolving, as `document_on()` read it; null for none.
	 * @return bool False when another document was stored meanwhile; nothing is written then.
	 */
	public function replace_on( WC_Product $product, ?string $expected ): bool {
		$json = (string) wp_json_encode( $this->to_array() );
		if ( $json === $expected ) {
			return true;
		}
		$product_id = $product->get_id();
		if ( null === $expected ) {
			// Unique: nothing is added once another request stored a document.
			if ( false === add_post_meta( $product_id, self::META_KEY, wp_slash( $json ), true ) ) {
				return false;
			}
		} else {
			global $wpdb;
			// One conditional UPDATE: no row matches once another document is stored, or none is.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- the caches are cleared below.
			$updated = $wpdb->update(
				$wpdb->postmeta,
				array( 'meta_value' => $json ),
				array(
					'post_id'    => $product_id,
					'meta_key'   => self::META_KEY,
					'meta_value' => $expected,
				)
			);
			// phpcs:enable
			if ( ! is_int( $updated ) || $updated < 1 ) {
				return false;
			}
		}
		clean_post_cache( $product_id );
		// WooCommerce caches a product's meta apart from the post's.
		WC_Cache_Helper::invalidate_cache_group( 'object_' . $product_id );
		wc_delete_product_transients( $product_id );
		return true;
	}

	/**
	 * Removes the variant from a product or variation.
	 *
	 * @param WC_Product $product The product or variation.
	 */
	public static function delete_from( WC_Product $product ): void {
		if ( '' === $product->get_meta( self::META_KEY, true ) ) {
			return;
		}
		$product->delete_meta_data( self::META_KEY );
		$product->save_meta_data();
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'v'       => self::SCHEMA_VERSION,
			'blankId' => $this->blank_id,
			'pick'    => $this->pick,
			'ref'     => $this->ref,
			'label'   => $this->label,
			'retired' => $this->retired,
			'views'   => $this->views,
		) + ( null === $this->pick ? array( 'matchedOn' => $this->matched_on ) : array() );
	}

	/**
	 * Attribute labels as stored; null when they are not a map of labels.
	 *
	 * @param mixed $value The stored `matchedOn`.
	 * @return array<string, string>|null
	 */
	private static function labels( mixed $value ): ?array {
		if ( ! is_array( $value ) ) {
			return null;
		}
		$labels = array();
		foreach ( $value as $name => $label ) {
			if ( ! is_string( $label ) ) {
				return null;
			}
			$labels[ (string) $name ] = $label;
		}
		return $labels;
	}

	/**
	 * Attribute labels compared case, spacing and order aside.
	 *
	 * @param array<array-key, string> $attributes Attribute labels by attribute name.
	 * @return array<string, string>
	 */
	public static function normalised( array $attributes ): array {
		$normalised = array();
		foreach ( $attributes as $name => $value ) {
			// PHP keeps a numeric name, `0` or `12`, as an integer key.
			$normalised[ self::normalise( (string) $name ) ] = self::normalise( $value );
		}
		ksort( $normalised );
		return $normalised;
	}

	/**
	 * Whether each of the variant's options is one of the attributes, by
	 * label, in the language the variant was read in or as written in
	 * Pixonaut: attributes named before the blank had names in the store's
	 * language still match. A variant without options matches every product.
	 *
	 * @param BlankVariant          $variant    A variant.
	 * @param array<string, string> $attributes Attribute labels by attribute name.
	 */
	private static function matches( BlankVariant $variant, array $attributes ): bool {
		$normalised = self::normalised( $attributes );
		return self::all_in( $variant->options, $normalised )
			|| ( array() !== $variant->written && self::all_in( $variant->written, $normalised ) );
	}

	/**
	 * Whether each option is one of the attributes, by label.
	 *
	 * @param array<string, string> $options    Labels by axis.
	 * @param array<string, string> $normalised Attribute labels by attribute name, `normalised()`.
	 */
	private static function all_in( array $options, array $normalised ): bool {
		foreach ( $options as $axis => $label ) {
			$value = $normalised[ self::normalise( $axis ) ] ?? null;
			if ( null === $value || self::normalise( $label ) !== $value ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * A label compared case and spacing aside, as a product's attributes
	 * name a blank's options and their values.
	 *
	 * @param string $label A label.
	 */
	public static function normalise( string $label ): string {
		return mb_strtolower( (string) preg_replace( '/\s+/u', ' ', trim( $label ) ) );
	}

	/**
	 * @param mixed $value Any value.
	 * @phpstan-assert-if-true non-empty-string $value
	 */
	private static function is_text( mixed $value ): bool {
		return is_string( $value ) && '' !== $value;
	}
}
