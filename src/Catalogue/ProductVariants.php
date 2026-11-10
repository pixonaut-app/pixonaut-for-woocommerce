<?php
/**
 * Keeps the blank variant of each product and variation in step with Pixonaut.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use Closure;
use Pixonaut\WooCommerce\Admin\VariantNotice;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Api\Catalogue;
use Pixonaut\WooCommerce\DesignerLanguage;
use WC_Product;
use WC_Product_Attribute;
use WP_Term;

/**
 * Resolves and stores `ProductVariant` for a product, or each variation of
 * a variable product, when it is saved and when its blank changes in
 * Pixonaut, then raises or clears the product's notice. Each blank's
 * variants are read once per refresh, and once per request otherwise; a
 * read that failed is not tried again in the same request either, so an
 * outage costs one timeout per blank rather than one per variation.
 *
 * Variants are named in the site's language where the blank has names in
 * it. A product's attributes match a variant's options in that language or
 * as written in Pixonaut, so attributes named before the blank had names in
 * the site's language still match; that costs a second read per blank.
 *
 * A resolved variant is written only over the document it was resolved
 * from: a pick saved while Pixonaut was being asked is newer, and kept.
 */
final class ProductVariants {

	/**
	 * Prefixes a blank id in `$read` and `$failed` for its variants read
	 * with their options as written as well.
	 */
	private const WRITTEN = 'written:';

	/**
	 * Each blank's variants read in this request, by blank id.
	 *
	 * @var array<string, list<BlankVariant>>
	 */
	private array $read = array();

	/**
	 * Each blank's failed read in this request, by blank id.
	 *
	 * @var array<string, ApiException>
	 */
	private array $failed = array();

	/**
	 * @param Catalogue     $catalogue Reads a blank's variants.
	 * @param VariantNotice $notice    Raises or clears a product's notice.
	 */
	public function __construct(
		private readonly Catalogue $catalogue,
		private readonly VariantNotice $notice,
	) {
		$this->sizes = new VariationSizes( $catalogue );
	}

	/**
	 * Judges the sizes variations have of their own again once their variant is resolved.
	 *
	 * @var VariationSizes
	 */
	private readonly VariationSizes $sizes;

	/**
	 * The blank's variants, retired ones included, named in the site's
	 * language where the blank has names in it.
	 *
	 * @param string $blank_id The blank.
	 * @return list<BlankVariant>
	 * @throws ApiException When Pixonaut refused or could not be reached.
	 */
	public function variants( string $blank_id ): array {
		return $this->once( $blank_id, fn (): array => BlankVariant::list_from( $this->catalogue->variants( $blank_id, DesignerLanguage::of_site() ) ) );
	}

	/**
	 * The blank's variants as `variants()` names them, each with its
	 * options as written in Pixonaut too when the site's language is not
	 * the one they are written in: either names a variant.
	 *
	 * @param string $blank_id The blank.
	 * @return list<BlankVariant>
	 * @throws ApiException When Pixonaut refused or could not be reached.
	 */
	public function named( string $blank_id ): array {
		return null === DesignerLanguage::of_site() ? $this->variants( $blank_id ) : $this->with_written( $blank_id );
	}

	/**
	 * The blank's variants as `variants()` names them, each with its
	 * options as written in Pixonaut too.
	 *
	 * @param string $blank_id The blank.
	 * @return list<BlankVariant>
	 * @throws ApiException When Pixonaut refused or could not be reached.
	 */
	private function with_written( string $blank_id ): array {
		return $this->once( self::WRITTEN . $blank_id, fn (): array => BlankVariant::with_written( $this->variants( $blank_id ), BlankVariant::list_from( $this->catalogue->variants( $blank_id ) ) ) );
	}

	/**
	 * A read made once per request, or once per refresh, failed or not.
	 *
	 * @param string  $key  What is read.
	 * @param Closure $read Reads it.
	 * @phpstan-param Closure(): list<BlankVariant> $read
	 * @return list<BlankVariant>
	 * @throws ApiException When Pixonaut refused or could not be reached.
	 */
	private function once( string $key, Closure $read ): array {
		if ( isset( $this->failed[ $key ] ) ) {
			throw $this->failed[ $key ];
		}
		try {
			return $this->read[ $key ] ??= $read();
		} catch ( ApiException $e ) {
			$this->failed[ $key ] = $e;
			throw $e;
		}
	}

	/**
	 * Resolves the variant of a product, of each variation of a variable
	 * product, or of one variation, as stored now, and notes the product.
	 * One not personalised on a blank has none. A variation's own print
	 * sizes are judged again for what it now sells.
	 *
	 * @param WC_Product $product A product or variation.
	 * @throws ApiException When a blank's variants could not be read, or a variation's own size not judged; what is stored is kept.
	 */
	public function refresh( WC_Product $product ): void {
		// A refresh follows a change in Pixonaut: what an earlier one read, in a queue runner working through several jobs, may be stale.
		$this->read   = array();
		$this->failed = array();
		$sold         = $product->is_type( 'variable' )
			? array_filter( array_map( 'wc_get_product', $product->get_children() ), static fn ( mixed $child ): bool => $child instanceof WC_Product )
			: array( $product );
		$unjudged     = null;
		try {
			foreach ( $sold as $one ) {
				$this->resolve( $one );
				try {
					// Its variant, or what its product sells, may have changed what an own size is judged for.
					$this->sizes->rejudge( $one );
				} catch ( ApiException $e ) {
					// The other variations are still resolved; the caller checks again later.
					$unjudged ??= $e;
				}
			}
		} finally {
			$this->note( $product );
		}
		if ( null !== $unjudged ) {
			throw $unjudged;
		}
	}

	/**
	 * Stores the store manager's pick for a product or variation, or
	 * matching by options again for null, and resolves its variant. The
	 * product's notice is left to the caller, which notes it once after
	 * saving all of its variations (`note()`).
	 *
	 * @param WC_Product  $product A product or variation, not a variable product.
	 * @param string|null $pick    A variant ref of its blank; null to match by options.
	 * @throws ApiException When the blank's variants could not be read, or its own size not judged; the pick is stored all the same.
	 */
	public function pick( WC_Product $product, ?string $pick ): void {
		$config = ProductConfig::config_for( $product );
		$stored = ProductVariant::stored_on( $product );
		if ( null !== $config->blank_id && ( null === $stored || $stored->blank_id !== $config->blank_id || $stored->pick !== $pick ) ) {
			// Kept unresolved until the variants are read, so a pick survives Pixonaut being away.
			( new ProductVariant( $config->blank_id, $pick ) )->save_to( $product );
		}
		$this->resolve( $product );
		$this->sizes->rejudge( $product );
	}

	/**
	 * Raises or clears the notice of a product, or of a variation's product,
	 * as its variants are stored now.
	 *
	 * @param WC_Product $product A product or variation.
	 */
	public function note( WC_Product $product ): void {
		// Loaded again: a variant written by `replace_on()` is not on the object the caller holds.
		$noted = wc_get_product( $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id() );
		if ( $noted instanceof WC_Product ) {
			$this->notice->note( $noted );
		}
	}

	/**
	 * Resolves and stores the variant of one product or variation, keeping
	 * the stored pick when it is for the same blank.
	 *
	 * @param WC_Product $product A product or variation, not a variable product.
	 * @throws ApiException When the blank's variants could not be read.
	 */
	private function resolve( WC_Product $product ): void {
		$config = ProductConfig::config_for( $product );
		if ( ! $config->personalise || null === $config->blank_id ) {
			ProductVariant::delete_from( $product );
			return;
		}
		// Read before Pixonaut is asked, so only this document is written over.
		$document = ProductVariant::document_on( $product );
		$stored   = ProductVariant::from_document( $document );
		// A pick names a variant of the blank it was made on.
		$pick = null !== $stored && $stored->blank_id === $config->blank_id ? $stored->pick : null;
		// A match weighs both names at once: a name in the site's language equal to another variant's name as written is no match, not the other variant.
		$variants = null === $pick && null !== DesignerLanguage::of_site() ? $this->with_written( $config->blank_id ) : $this->variants( $config->blank_id );
		$resolved = ProductVariant::resolve( $config->blank_id, $pick, self::attributes( $product ), $variants );
		// False when a newer document was stored meanwhile: whoever stored it resolves it, so it is kept.
		$resolved->replace_on( $product, $document );
	}

	/**
	 * The attributes a product or variation is, as labels, `Color => Red`:
	 * a global attribute's term name, a custom attribute's value as entered.
	 * A variation's attribute left at "Any …" is an empty label, which
	 * matches no option; a product's attribute counts only with one value.
	 *
	 * @param WC_Product $product A product or variation.
	 * @return array<string, string>
	 */
	public static function attributes( WC_Product $product ): array {
		$parent = $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : $product;
		$parent = $parent instanceof WC_Product ? $parent : $product;
		$labels = array();
		foreach ( $product->get_attributes() as $name => $value ) {
			if ( ! is_string( $name ) ) {
				continue;
			}
			$label = self::value_label( $name, $value );
			if ( null !== $label ) {
				$labels[ wc_attribute_label( $name, $parent ) ] = $label;
			}
		}
		return $labels;
	}

	/**
	 * An attribute's value as its label: a variation's slug or text, or a
	 * product's one option; null for a product's attribute with several.
	 *
	 * @param string $name  The attribute's name, `pa_color` for a global one.
	 * @param mixed  $value A variation's value, or a product's `WC_Product_Attribute`.
	 */
	private static function value_label( string $name, mixed $value ): ?string {
		if ( $value instanceof WC_Product_Attribute ) {
			$options = $value->get_options();
			if ( 1 !== count( $options ) ) {
				return null;
			}
			$term = $value->is_taxonomy() ? get_term( (int) $options[0], $name ) : null;
			return $term instanceof WP_Term ? $term->name : (string) $options[0];
		}
		if ( ! is_string( $value ) ) {
			return null;
		}
		if ( '' !== $value && taxonomy_exists( $name ) ) {
			$term = get_term_by( 'slug', $value, $name );
			return $term instanceof WP_Term ? $term->name : $value;
		}
		return $value;
	}
}
