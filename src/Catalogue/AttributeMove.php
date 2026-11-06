<?php
/**
 * Moves one product from a custom attribute to a global one.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages here are reasons and log text, never printed as they are: whoever shows one escapes it.

use Throwable;
use WC_Cache_Helper;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Variable;
use WP_Term;

/**
 * A product's custom attribute becoming a global one, worked out first and
 * written as one: the product's attribute and default choice, each
 * variation's value, and the form arrangements naming the attribute. No
 * request sees the product part-way, which saving the product and then its
 * variations cannot give: WooCommerce's variations route drops an attribute
 * the product lacks, so a variation would offer any value until its turn.
 *
 * Each variation keeps its id, price, image and Pixonaut setup. Pixonaut is
 * not asked, and no picture is drawn: a picture's source names no attribute.
 */
final class AttributeMove {

	/**
	 * Most variations a product may have to be moved: all are written in one
	 * transaction, which holds its locks until the last one is in.
	 */
	public const MAX_VARIATIONS = 500;

	/**
	 * The statuses of the variations moved: all of them, as a variation back
	 * from the trash with the old attribute's value would offer any value.
	 */
	private const VARIATION_STATUSES = array( 'publish', 'private', 'draft', 'pending', 'future', 'trash' );

	/**
	 * Whether a move is being written: its saves pass through states no
	 * customer sees, which those watching saves pay no attention to.
	 *
	 * @var bool
	 */
	private static bool $writing = false;

	/**
	 * @param WC_Product                               $product    The simple or variable product.
	 * @param string                                   $from       The custom attribute, as asked for.
	 * @param array<string, string>                    $term_map   The terms asked for, by the value.
	 * @param string                                   $slug       The custom attribute's slug.
	 * @param WC_Product_Attribute                     $old        The custom attribute.
	 * @param string                                   $taxonomy   The global attribute's taxonomy.
	 * @param array<string, WP_Term>                   $terms      The term each of the custom attribute's values becomes, by the value's slug.
	 * @param WC_Product_Attribute                     $moved      The product's new attribute.
	 * @param array<int, string|null>                  $variations Each variation's term slug, by its id; empty for "any", null for a variation not sold by this attribute.
	 * @param array<int, string>                       $spelled    The value of each variation that spells it otherwise than its option, by its id.
	 * @param list<array{id: int, attributes: object}> $held       Each variation's attributes as they stand.
	 * @param array<int, string>                       $documents  The stored setup of each product or variation whose arrangement the move renames, by its id.
	 */
	private function __construct(
		private readonly WC_Product $product,
		private readonly string $from,
		private readonly array $term_map,
		private readonly string $slug,
		private readonly WC_Product_Attribute $old,
		private readonly string $taxonomy,
		private readonly array $terms,
		private readonly WC_Product_Attribute $moved,
		private array $variations,
		private readonly array $spelled,
		private readonly array $held,
		private readonly array $documents,
	) {
	}

	/**
	 * Whether a move is being written now.
	 */
	public static function writing(): bool {
		return self::$writing;
	}

	/**
	 * Works out the move, writing nothing.
	 *
	 * @param WC_Product            $product  The simple or variable product.
	 * @param string                $from     The custom attribute, by its name or slug.
	 * @param string                $taxonomy The global attribute's taxonomy.
	 * @param array<string, string> $term_map A term, by its name or slug, for values not named as one, by the value.
	 * @return self|null Null when the product has no such custom attribute.
	 * @throws AttributeMoveRefused When moving it would lose something.
	 */
	public static function plan( WC_Product $product, string $from, string $taxonomy, array $term_map = array() ): ?self {
		$slug       = sanitize_title( $from );
		$attributes = $product->get_attributes();
		$old        = null;
		foreach ( $attributes as $attribute ) {
			if ( $attribute instanceof WC_Product_Attribute && ! $attribute->is_taxonomy() && sanitize_title( $attribute->get_name() ) === $slug ) {
				$old = $attribute;
			}
		}
		if ( null === $old ) {
			return null;
		}
		if ( isset( $attributes[ $taxonomy ] ) ) {
			throw new AttributeMoveRefused(
				AttributeMoveRefused::TARGET_ON_PRODUCT,
				/* translators: 1: a custom product attribute's name; 2: a global product attribute's taxonomy, e.g. pa_size. */
				sprintf( __( 'The product has both %1$s and %2$s. Remove one, then move it.', 'pixonaut-for-woocommerce' ), $old->get_name(), $taxonomy )
			);
		}
		$children = $product instanceof WC_Product_Variable ? self::children( $product ) : array();
		if ( count( $children ) > self::MAX_VARIATIONS ) {
			throw new AttributeMoveRefused(
				AttributeMoveRefused::TOO_MANY_VARIATIONS,
				/* translators: 1: how many variations the product has; 2: the most a move can write. */
				sprintf( __( 'The product has %1$d variations, and a move can write at most %2$d.', 'pixonaut-for-woocommerce' ), count( $children ), self::MAX_VARIATIONS )
			);
		}
		$terms = self::terms( $old, $taxonomy, $term_map );
		$moved = new WC_Product_Attribute();
		$moved->set_id( wc_attribute_taxonomy_id_by_name( $taxonomy ) );
		$moved->set_name( $taxonomy );
		$moved->set_options( array_values( array_map( static fn ( WP_Term $term ): int => $term->term_id, $terms ) ) );
		$moved->set_position( $old->get_position() );
		$moved->set_visible( $old->get_visible() );
		$moved->set_variation( $old->get_variation() );

		$spellings = array();
		foreach ( $old->get_options() as $option ) {
			$spellings[ sanitize_title( (string) $option ) ] = (string) $option;
		}
		$variations = array();
		$spelled    = array();
		$held       = array();
		$documents  = array();
		self::note_renamed_document( $documents, $product, $slug, $taxonomy );
		foreach ( $children as $id ) {
			$variation = wc_get_product( $id );
			if ( ! $variation instanceof WC_Product ) {
				continue;
			}
			$held[] = array(
				'id'         => $variation->get_id(),
				'attributes' => (object) $variation->get_attributes(),
			);
			self::note_renamed_document( $documents, $variation, $slug, $taxonomy );
			$value = $variation->get_attributes()[ $slug ] ?? '';
			$value = is_string( $value ) ? $value : '';
			$key   = sanitize_title( $value );
			$term  = $terms[ $key ] ?? null;
			if ( '' !== $value && null === $term ) {
				throw new AttributeMoveRefused(
					AttributeMoveRefused::UNKNOWN_VALUE,
					/* translators: 1: a product variation's id; 2: the value it holds of a custom product attribute; 3: the attribute's name. */
					sprintf( __( 'Variation %1$d holds %2$s, which %3$s does not offer. Correct the variation, then move it.', 'pixonaut-for-woocommerce' ), $variation->get_id(), $value, $old->get_name() )
				);
			}
			$variations[ $variation->get_id() ] = $old->get_variation() ? ( $term->slug ?? '' ) : null;
			if ( $old->get_variation() ) {
				self::check_variant( $variation, $product, $slug, $taxonomy, $term->name ?? '' );
				if ( '' !== $value && $value !== $spellings[ $key ] ) {
					$spelled[ $variation->get_id() ] = $value;
				}
			}
		}
		if ( ! $product instanceof WC_Product_Variable ) {
			// A product's attribute counts towards its variant only with one value.
			$only = 1 === count( $terms ) ? reset( $terms ) : null;
			self::check_variant( $product, $product, $slug, $taxonomy, $only->name ?? null );
		}
		return new self( $product, $from, $term_map, $slug, $old, $taxonomy, $terms, $moved, $variations, $spelled, $held, $documents );
	}

	/**
	 * Writes the move, all of it or none of it, as the product stands once it
	 * is locked: another call or an edit since `plan()` is not overwritten.
	 *
	 * Reads made meanwhile add nothing to the shared object cache, as another
	 * request would take what they find for committed.
	 *
	 * @throws AttributeMoveRefused When it can no longer be made, or did not come out as planned; nothing is written then.
	 * @throws Throwable What a save threw; nothing is written then.
	 */
	public function apply(): void {
		$written  = $this;
		$caching  = wp_suspend_cache_addition();
		$deferred = self::deferred_syncs();
		wp_suspend_cache_addition( true );
		self::$writing = true;
		try {
			Transaction::run(
				function () use ( &$written ): void {
					$written = $this->planned_afresh();
					$written->write();
				}
			);
		} finally {
			wp_suspend_cache_addition( $caching );
			$this->forget_deferred_sync( $deferred );
			try {
				$written->clean_caches();
			} finally {
				self::$writing = false;
			}
			$this->variations = $written->variations;
		}
	}

	/**
	 * A record of the product as it stands: what the move changes. No ability
	 * reads it back.
	 *
	 * @return array{attributes: list<array<string, mixed>>, default_attributes: object, variations: list<array{id: int, attributes: object}>, setup_documents: object, moved_attributes: object}
	 */
	public function before(): array {
		return array(
			'attributes'         => array_values(
				array_map(
					static fn ( WC_Product_Attribute $attribute ): array => array(
						'id'        => $attribute->get_id(),
						'name'      => $attribute->get_name(),
						'position'  => $attribute->get_position(),
						'visible'   => $attribute->get_visible(),
						'variation' => $attribute->get_variation(),
						'options'   => $attribute->get_options(),
					),
					array_filter( $this->product->get_attributes(), static fn ( mixed $attribute ): bool => $attribute instanceof WC_Product_Attribute )
				)
			),
			'default_attributes' => (object) $this->product->get_default_attributes(),
			'variations'         => $this->held,
			'setup_documents'    => (object) $this->documents,
			'moved_attributes'   => (object) MovedAttributes::of( $this->product->get_id() ),
		);
	}

	/**
	 * The ids of the variations the move rewrites.
	 *
	 * @return list<int>
	 */
	public function variation_ids(): array {
		return array_keys( $this->variations );
	}

	/**
	 * The move as the product stands now, its row locked until the
	 * transaction ends. The lock is the transaction's first statement, so its
	 * plain reads see no older state than the lock's.
	 *
	 * @throws AttributeMoveRefused When the product has changed so it can no longer be moved.
	 */
	private function planned_afresh(): self {
		global $wpdb;
		$id = $this->product->get_id();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a row lock, which is not a read to cache.
		$locked = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID = %d FOR UPDATE", $id ) );
		self::forget( array_merge( array( $id ), $this->variation_ids() ) );
		$product = null === $locked ? null : wc_get_product( $id );
		$fresh   = $product instanceof WC_Product ? self::plan( $product, $this->from, $this->taxonomy, $this->term_map ) : null;
		if ( null === $fresh ) {
			throw new AttributeMoveRefused( AttributeMoveRefused::NOT_SAVED, __( 'The product changed meanwhile and no longer has this attribute. Nothing was changed.', 'pixonaut-for-woocommerce' ) );
		}
		return $fresh;
	}

	/**
	 * The writes, inside the transaction.
	 *
	 * @throws AttributeMoveRefused When something did not land.
	 */
	private function write(): void {
		$product    = $this->product;
		$attributes = array();
		foreach ( $product->get_attributes() as $attribute ) {
			if ( $attribute instanceof WC_Product_Attribute ) {
				$attributes[] = sanitize_title( $attribute->get_name() ) === $this->slug ? $this->moved : $attribute;
			}
		}
		$defaults = array();
		foreach ( $product->get_default_attributes() as $key => $value ) {
			if ( (string) $key !== $this->slug ) {
				$defaults[ $key ] = $value;
			} elseif ( isset( $this->terms[ sanitize_title( (string) $value ) ] ) ) {
				$defaults[ $this->taxonomy ] = $this->terms[ sanitize_title( (string) $value ) ]->slug;
			}
		}
		$values = array();
		foreach ( $this->old->get_options() as $option ) {
			$values[ (string) $option ] = $this->terms[ sanitize_title( (string) $option ) ]->slug;
		}

		$product->set_attributes( self::in_order( $attributes ) );
		$product->set_default_attributes( $defaults );
		$product->save();
		$bases = $this->picture_bases();
		foreach ( $this->variations as $id => $slug ) {
			$this->write_variation( $id, $slug );
		}
		self::rename_in_config( $product, $this->slug, $this->taxonomy );
		MovedAttributes::record( $product->get_id(), $this->slug, $this->taxonomy, $values, $this->spelled );
		$this->keep_pictures_current( $bases );
		if ( $product instanceof WC_Product_Variable && array() !== array_filter( $this->variations, static fn ( ?string $slug ): bool => null !== $slug ) ) {
			// What the variations' saves leave for the end of the request, where it would save the product with no move under way.
			WC_Product_Variable::sync( $product->get_id() );
		}

		$this->check_written( $defaults, $values );
	}

	/**
	 * What the pictures of the product and its variations depend on now, when
	 * the move renames an arrangement, which is part of that and changes no
	 * picture.
	 *
	 * @return array<int, string|null> Each one's basis, by its id.
	 */
	private function picture_bases(): array {
		if ( array() === $this->documents ) {
			return array();
		}
		$bases = array();
		foreach ( array_merge( array( $this->product->get_id() ), $this->variation_ids() ) as $id ) {
			$one          = PictureQueue::fresh( $id );
			$bases[ $id ] = null === $one ? null : ProductPictures::basis( $one );
		}
		return $bases;
	}

	/**
	 * Keeps each picture that was current so.
	 *
	 * @param array<int, string|null> $bases Each one's basis before the move, by its id.
	 */
	private function keep_pictures_current( array $bases ): void {
		foreach ( $bases as $id => $basis ) {
			$one = PictureQueue::fresh( $id );
			if ( null !== $one && null !== $basis ) {
				ProductPictures::rebase( $one, $basis );
			}
		}
	}

	/**
	 * How many parent syncs WooCommerce has deferred to the end of the request.
	 */
	private static function deferred_syncs(): int {
		global $wc_deferred_product_sync;
		return is_array( $wc_deferred_product_sync ) ? count( $wc_deferred_product_sync ) : 0;
	}

	/**
	 * Drops the sync of this product deferred since: the move made it, or
	 * undid what asked for it.
	 *
	 * @param int $earlier How many were deferred before the move.
	 */
	private function forget_deferred_sync( int $earlier ): void {
		global $wc_deferred_product_sync;
		if ( is_array( $wc_deferred_product_sync ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WooCommerce's own list, which has no other way to take a product off it.
			$wc_deferred_product_sync = array_merge(
				array_slice( $wc_deferred_product_sync, 0, $earlier ),
				array_values( array_diff( array_slice( $wc_deferred_product_sync, $earlier ), array( $this->product->get_id() ) ) )
			);
		}
	}

	/**
	 * Reads back what was written: some writes fail by answering false, or
	 * by doing nothing, rather than by throwing.
	 *
	 * @param array<string, string> $wanted The default choice written.
	 * @param array<string, string> $values Each old value as entered, and the term slug it became.
	 * @throws AttributeMoveRefused When something did not land.
	 */
	private function check_written( array $wanted, array $values ): void {
		$id = $this->product->get_id();
		wp_cache_delete( (string) $id, 'post_meta' );
		$stored   = get_post_meta( $id, '_product_attributes', true );
		$defaults = get_post_meta( $id, '_default_attributes', true );
		$defaults = is_array( $defaults ) ? $defaults : array();
		ksort( $defaults );
		ksort( $wanted );
		$move = array(
			'to'      => $this->taxonomy,
			'values'  => $values,
			'spelled' => $this->spelled,
		);
		if (
			! is_array( $stored )
			|| ! isset( $stored[ $this->taxonomy ] )
			|| isset( $stored[ $this->slug ] )
			|| ! $this->terms_landed()
			|| $defaults !== $wanted
			|| ( MovedAttributes::of( $id )[ $this->slug ] ?? null ) !== $move
		) {
			throw new AttributeMoveRefused( AttributeMoveRefused::NOT_SAVED, __( 'The product\'s attribute could not be saved. Nothing was changed.', 'pixonaut-for-woocommerce' ) );
		}
		foreach ( $this->variations as $variation => $slug ) {
			wp_cache_delete( (string) $variation, 'post_meta' );
			if (
				metadata_exists( 'post', $variation, MovedAttributes::PREFIX . $this->slug )
				|| ( null !== $slug && get_post_meta( $variation, MovedAttributes::PREFIX . $this->taxonomy, true ) !== $slug )
			) {
				throw new AttributeMoveRefused(
					AttributeMoveRefused::NOT_SAVED,
					/* translators: %d: a product variation's id. */
					sprintf( __( 'Variation %d could not be saved. Nothing was changed.', 'pixonaut-for-woocommerce' ), $variation )
				);
			}
		}
	}

	/**
	 * Whether the product holds exactly the terms planned.
	 */
	private function terms_landed(): bool {
		$planned = $this->term_ids();
		if ( array() === $planned ) {
			return true;
		}
		$held = wp_get_object_terms(
			$this->product->get_id(),
			$this->taxonomy,
			array(
				'fields'                 => 'ids',
				'cache_results'          => false,
				'update_term_meta_cache' => false,
			)
		);
		if ( ! is_array( $held ) ) {
			return false;
		}
		$held = array_map( 'intval', $held );
		sort( $planned );
		sort( $held );
		return $planned === $held;
	}

	/**
	 * The ids of the terms the attribute's values become.
	 *
	 * @return list<int>
	 */
	private function term_ids(): array {
		return array_values( array_map( static fn ( WP_Term $term ): int => $term->term_id, $this->terms ) );
	}

	/**
	 * The attributes numbered as they stand when two share a position:
	 * WooCommerce sorts by position, and puts a new attribute after those of
	 * the same position, which would change the order customers see.
	 *
	 * @param WC_Product_Attribute[] $attributes The attributes, in order.
	 * @phpstan-param list<WC_Product_Attribute> $attributes
	 * @return list<WC_Product_Attribute>
	 */
	private static function in_order( array $attributes ): array {
		$positions = array_map( static fn ( WC_Product_Attribute $attribute ): int => $attribute->get_position(), $attributes );
		if ( count( array_unique( $positions ) ) !== count( $positions ) ) {
			foreach ( $attributes as $position => $attribute ) {
				$attribute->set_position( $position );
			}
		}
		return $attributes;
	}

	/**
	 * One variation's value, under the new attribute, and its arrangement.
	 *
	 * @param int         $id   The variation.
	 * @param string|null $slug Its term's slug; empty for "any", null for a variation not sold by this attribute.
	 * @throws AttributeMoveRefused When another document was stored meanwhile.
	 */
	private function write_variation( int $id, ?string $slug ): void {
		// Loaded after the product's save: the attribute it reads is the new one, so the old value's meta goes with this save.
		$variation = wc_get_product( $id );
		if ( ! $variation instanceof WC_Product ) {
			return;
		}
		if ( null !== $slug ) {
			$attributes                    = $variation->get_attributes();
			$attributes[ $this->taxonomy ] = $slug;
			$variation->set_attributes( $attributes );
			$variation->save();
		}
		// Not among the changes a save sees when the value stays empty.
		delete_post_meta( $id, MovedAttributes::PREFIX . $this->slug );
		self::rename_in_config( $variation, $this->slug, $this->taxonomy );
	}

	/**
	 * Clears what the writes, or their rollback, left stale in the caches.
	 */
	private function clean_caches(): void {
		$product_id = $this->product->get_id();
		self::forget( array_merge( array( $product_id ), $this->variation_ids() ) );
		clean_term_cache( $this->term_ids(), $this->taxonomy );
		wc_delete_product_transients( $product_id );
	}

	/**
	 * Empties the caches WordPress and WooCommerce keep of products.
	 *
	 * @param int[] $ids The products and variations.
	 * @phpstan-param list<int> $ids
	 */
	private static function forget( array $ids ): void {
		foreach ( $ids as $id ) {
			clean_post_cache( $id );
			WC_Cache_Helper::invalidate_cache_group( 'object_' . $id );
		}
	}

	/**
	 * Every variation of a product, whatever its status; WooCommerce's own
	 * list leaves out those in the trash.
	 *
	 * @param WC_Product_Variable $product The product.
	 * @return list<int>
	 */
	private static function children( WC_Product_Variable $product ): array {
		return array_values(
			array_map(
				'intval',
				get_posts(
					array(
						'post_type'     => 'product_variation',
						'post_parent'   => $product->get_id(),
						'post_status'   => self::VARIATION_STATUSES,
						'nopaging'      => true,
						'orderby'       => array(
							'menu_order' => 'ASC',
							'ID'         => 'ASC',
						),
						'fields'        => 'ids',
						'cache_results' => false,
					)
				)
			)
		);
	}

	/**
	 * The term each of the attribute's values becomes.
	 *
	 * @param WC_Product_Attribute  $attribute The custom attribute.
	 * @param string                $taxonomy  The global attribute's taxonomy.
	 * @param array<string, string> $term_map  A term for values not named as one, by the value.
	 * @return array<string, WP_Term> By the value's slug.
	 * @throws AttributeMoveRefused When two values share a slug, a value has no term, or two have the same.
	 */
	private static function terms( WC_Product_Attribute $attribute, string $taxonomy, array $term_map ): array {
		$seen = array();
		foreach ( $attribute->get_options() as $option ) {
			$value = (string) $option;
			$key   = sanitize_title( $value );
			if ( isset( $seen[ $key ] ) ) {
				throw new AttributeMoveRefused(
					AttributeMoveRefused::AMBIGUOUS_VALUES,
					/* translators: 1, 2: two values of a custom product attribute. */
					sprintf( __( '%1$s and %2$s have the same slug, so the move could not tell which a variation holds. Rename one, then move it.', 'pixonaut-for-woocommerce' ), $seen[ $key ], $value )
				);
			}
			$seen[ $key ] = $value;
		}
		$terms   = array();
		$missing = array();
		foreach ( $attribute->get_options() as $option ) {
			$value = (string) $option;
			$named = $term_map[ $value ] ?? $value;
			$term  = get_term_by( 'name', $named, $taxonomy );
			$term  = $term instanceof WP_Term ? $term : get_term_by( 'slug', sanitize_title( $named ), $taxonomy );
			if ( $term instanceof WP_Term ) {
				$terms[ sanitize_title( $value ) ] = $term;
			} else {
				$missing[] = $value;
			}
		}
		if ( array() !== $missing ) {
			throw new AttributeMoveRefused(
				AttributeMoveRefused::MISSING_TERMS,
				/* translators: 1: a global product attribute's taxonomy, e.g. pa_size; 2: values of a custom product attribute, separated by commas. */
				sprintf( __( '%1$s has no term for: %2$s. Create the terms or name them in term_map, then move it.', 'pixonaut-for-woocommerce' ), $taxonomy, implode( ', ', $missing ) )
			);
		}
		$ids = array_map( static fn ( WP_Term $term ): int => $term->term_id, $terms );
		if ( count( array_unique( $ids ) ) !== count( $ids ) ) {
			throw new AttributeMoveRefused(
				AttributeMoveRefused::DUPLICATE_TERMS,
				/* translators: %s: a global product attribute's taxonomy, e.g. pa_size. */
				sprintf( __( 'Two of the product\'s values would become one term of %s, and customers could no longer tell its variations apart.', 'pixonaut-for-woocommerce' ), $taxonomy )
			);
		}
		return $terms;
	}

	/**
	 * Refuses a move that would stop a variant matched by options selling:
	 * it was matched on the attribute's name and value, as words.
	 *
	 * @param WC_Product  $one      The product or variation sold.
	 * @param WC_Product  $product  The product.
	 * @param string      $slug     The custom attribute's slug.
	 * @param string      $taxonomy The global attribute's taxonomy.
	 * @param string|null $words    The term's name it would hold; empty for "any", null for an attribute that does not count.
	 * @throws AttributeMoveRefused When the variant would stop selling.
	 */
	private static function check_variant( WC_Product $one, WC_Product $product, string $slug, string $taxonomy, ?string $words ): void {
		$stored = ProductVariant::stored_on( $one );
		if ( null === $stored || ! $stored->is_current( $one ) ) {
			return;
		}
		$labels = ProductVariants::attributes( $one );
		unset( $labels[ wc_attribute_label( $slug, $product ) ] );
		if ( null !== $words ) {
			$labels[ wc_attribute_label( $taxonomy ) ] = $words;
		}
		if ( ! $stored->is_current_on( $labels ) ) {
			throw new AttributeMoveRefused(
				AttributeMoveRefused::BLANK_VARIANT,
				/* translators: %d: a product or variation's id. */
				sprintf( __( '%d is matched to its blank\'s variant by the attribute\'s words, which the move would change, so it would stop selling. Pick its variant with set-product-setup, then move it.', 'pixonaut-for-woocommerce' ), $one->get_id() )
			);
		}
	}

	/**
	 * Keeps the stored setup of a product or variation whose form arrangements
	 * the move renames.
	 *
	 * @param array<int, string> $documents The setups so far, by id.
	 * @param WC_Product         $one       The product or variation.
	 * @param string             $slug      The custom attribute's slug.
	 * @param string             $taxonomy  The global attribute's taxonomy.
	 */
	private static function note_renamed_document( array &$documents, WC_Product $one, string $slug, string $taxonomy ): void {
		$document = ProductConfig::document_on( $one );
		if ( null === $document ) {
			return;
		}
		$config = ProductConfig::from_json( $document );
		if ( $config->with_attribute_renamed( $slug, $taxonomy ) !== $config ) {
			$documents[ $one->get_id() ] = $document;
		}
	}

	/**
	 * Renames the attribute in a product's or variation's own form
	 * arrangements, leaving its stored setup otherwise as it is.
	 *
	 * @param WC_Product $one      The product or variation.
	 * @param string     $slug     The custom attribute's slug.
	 * @param string     $taxonomy The global attribute's taxonomy.
	 * @throws AttributeMoveRefused When another document was stored meanwhile.
	 */
	private static function rename_in_config( WC_Product $one, string $slug, string $taxonomy ): void {
		$document = ProductConfig::document_on( $one );
		if ( null === $document ) {
			return;
		}
		$config  = ProductConfig::from_json( $document );
		$renamed = $config->with_attribute_renamed( $slug, $taxonomy );
		if ( $renamed !== $config && ! $renamed->replace_on( $one, $document ) ) {
			throw new AttributeMoveRefused( AttributeMoveRefused::NOT_SAVED, __( 'The product\'s Pixonaut setup changed meanwhile. Nothing was changed; try again.', 'pixonaut-for-woocommerce' ) );
		}
	}
}
