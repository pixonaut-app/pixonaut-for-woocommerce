<?php
/**
 * The blank variant field of the product screen.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Admin;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Pixonaut\WooCommerce\Catalogue\BlankVariant;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ProductRecheck;
use Pixonaut\WooCommerce\Catalogue\ProductVariant;
use Pixonaut\WooCommerce\Catalogue\ProductVariants;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Templates;
use WC_Product;

/**
 * A product or variation personalised on a blank is one of the blank's
 * variants: the one its options match, or the one the store manager picks
 * here. The field says which, and warns when there is none, when it is
 * retired, and when no view the product is shown in has a photo of it.
 * It lists the variants on sale, and the retired ones too once the store
 * manager asks; a retired variant picked is listed either way.
 */
final class VariantField {

	/**
	 * The field's value that matches by options rather than a pick.
	 */
	public const MATCH_BY_OPTIONS = '';

	/**
	 * @param ProductVariants $variants  Lists a blank's variants and stores a product's.
	 * @param ProductRecheck  $recheck   Resolves a variant later when Pixonaut could not be asked.
	 * @param Templates       $templates Renders the field.
	 * @param LoggerInterface $logger    Where a variant that could not be resolved is reported.
	 */
	public function __construct(
		private readonly ProductVariants $variants,
		private readonly ProductRecheck $recheck,
		private readonly Templates $templates,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * The field, as `admin/product-variant.php` prints it; empty for a
	 * product or variation not personalised on a blank. Given a blank other
	 * than the one saved, it shows that blank's variants and the one the
	 * product's attributes find, before the product is updated with it.
	 *
	 * @param WC_Product  $product     A product or variation, not a variable product.
	 * @param PanelFields $fields      Names the field.
	 * @param string      $integration The print partner whose catalogue the blank is from, such as Gelato, which the variant is called after; empty for a blank of the store's own.
	 * @param string|null $blank_id    The blank shown; null for the one saved.
	 */
	public function html( WC_Product $product, PanelFields $fields, string $integration = '', ?string $blank_id = null ): string {
		$config = ProductConfig::config_for( $product );
		if ( null === $blank_id && ( ! $config->personalise || null === $config->blank_id ) ) {
			return '';
		}
		$blank_id ??= (string) $config->blank_id;
		$stored     = ProductVariant::stored_on( $product );
		$stored     = null !== $stored && $stored->blank_id === $blank_id ? $stored : null;
		$choices    = array();
		$retired    = array();
		$warnings   = array();
		$listed     = null;
		try {
			$listed = $this->variants->variants( $blank_id );
			foreach ( $listed as $variant ) {
				$choices[ $variant->ref ] = self::choice( $variant );
				if ( null !== $variant->retired ) {
					$retired[] = $variant->ref;
				}
			}
		} catch ( ApiException $e ) {
			$warnings[] = __( 'Pixonaut could not be reached to list the blank’s variants. Try again later.', 'pixonaut-for-woocommerce' );
		}
		if ( null === $stored && null !== $listed ) {
			// Not saved on this blank yet: the variant the product's attributes find.
			$stored = ProductVariant::resolve( $blank_id, null, ProductVariants::attributes( $product ), $listed );
		}
		if ( null !== $stored?->pick && ! isset( $choices[ $stored->pick ] ) ) {
			// A pick the list does not hold, or could not be read, stays selected.
			$choices[ $stored->pick ] = '' === $stored->label ? $stored->pick : $stored->label;
		}
		return $this->templates->html(
			'admin/product-variant.php',
			array(
				'name'      => $fields->name( PanelFields::VARIANT ),
				'id'        => $fields->id( PanelFields::VARIANT ),
				'picked'    => $stored->pick ?? self::MATCH_BY_OPTIONS,
				'matched'   => null !== $stored && null === $stored->pick && null !== $stored->ref
					? self::found( $integration, $stored->label )
					/* translators: Option in the blank variant picker on the product edit page, shown to the merchant: find the variant from the product's own attributes. */
					: __( 'Find automatically', 'pixonaut-for-woocommerce' ),
				'choices'   => $choices,
				'retired'   => $retired,
				'warnings'  => array_merge( $warnings, null === $listed && null === $stored ? array() : self::warnings( $stored, $config->blank_id === $blank_id ? $config : null, $integration ) ),
				'explained' => $product->is_type( 'variation' )
					? __( 'Found from this variation’s attributes; it does not fall back to the product’s.', 'pixonaut-for-woocommerce' )
					: __( 'Found from this product’s attributes.', 'pixonaut-for-woocommerce' ),
			)
		);
	}

	/**
	 * The automatic choice once it found a variant: "Gelato variant: 28x40
	 * (found automatically)".
	 *
	 * @param string $integration The print partner the blank is from; empty for the store's own blank.
	 * @param string $label       The variant's options.
	 */
	private static function found( string $integration, string $label ): string {
		return '' === $integration
			/* translators: %s: the variant's options, e.g. "Color: Red, Size: M". Option in the blank variant picker on the product edit page: the variant found from the product's attributes. */
			? sprintf( __( 'Blank variant: %s (found automatically)', 'pixonaut-for-woocommerce' ), $label )
			/* translators: 1: a print partner, such as Gelato, whose product the blank is; 2: the variant's options, e.g. "28x40\" / 70x100cm". Option in the blank variant picker on the product edit page: the variant found from the product's attributes. */
			: sprintf( __( '%1$s variant: %2$s (found automatically)', 'pixonaut-for-woocommerce' ), $integration, $label );
	}

	/**
	 * Saves the field as posted for a product or variation, and resolves its
	 * variant. A product's notice is noted here; a variation's product is
	 * noted once its variations are all saved (`note()`).
	 *
	 * @param WC_Product $product A product or variation, not a variable product.
	 * @param mixed      $posted  The field's value as posted, unslashed; anything but a ref matches by options.
	 */
	public function save( WC_Product $product, mixed $posted ): void {
		$pick = is_string( $posted ) ? sanitize_text_field( $posted ) : self::MATCH_BY_OPTIONS;
		try {
			$this->variants->pick( $product, self::MATCH_BY_OPTIONS === $pick ? null : $pick );
		} catch ( ApiException $e ) {
			$this->unresolved( $product, $e );
		}
		if ( ! $product->is_type( 'variation' ) ) {
			$this->variants->note( $product );
		}
	}

	/**
	 * Raises or clears a product's notice as its variants are stored now.
	 *
	 * @param WC_Product $product A product, not a variation.
	 */
	public function note( WC_Product $product ): void {
		$this->variants->note( $product );
	}

	/**
	 * Resolves the variants of a saved variable product's variations on the
	 * blank it was saved with.
	 *
	 * @param WC_Product $product A variable product.
	 */
	public function refresh( WC_Product $product ): void {
		try {
			$this->variants->refresh( $product );
		} catch ( ApiException $e ) {
			$this->unresolved( $product, $e );
		}
	}

	/**
	 * Reports a variant that could not be resolved, and resolves it in a job
	 * instead; until then the product keeps the variant it had.
	 *
	 * @param WC_Product   $product The product or variation.
	 * @param ApiException $error   Why Pixonaut could not be asked.
	 */
	private function unresolved( WC_Product $product, ApiException $error ): void {
		$this->logger->warning(
			sprintf( 'Could not read the blank variants for product %d: %s', $product->get_id(), $error->getMessage() ),
			array(
				'product_id' => $product->get_id(),
				'code'       => $error->errorCode,
			)
		);
		$this->recheck->queue( array( $product->get_id() ) );
	}

	/**
	 * A variant as the field lists it, its retirement named.
	 *
	 * @param BlankVariant $variant The variant.
	 */
	private static function choice( BlankVariant $variant ): string {
		return null === $variant->retired
			? $variant->label()
			/* translators: %s: the variant's options, e.g. "Color: Red, Size: M". */
			: sprintf( __( '%s (retired)', 'pixonaut-for-woocommerce' ), $variant->label() );
	}

	/**
	 * What is wrong with the variant used, as the store manager reads it.
	 *
	 * @param ProductVariant|null $stored      The variant stored for the configuration's blank; null for none.
	 * @param ProductConfig|null  $config      The configuration it is sold under; null while it is another blank's.
	 * @param string              $integration The print partner the blank is from; empty for the store's own blank.
	 * @return list<string>
	 */
	private static function warnings( ?ProductVariant $stored, ?ProductConfig $config, string $integration ): array {
		$problem = null === $stored ? ProductVariant::UNMAPPED : $stored->problem();
		if ( ProductVariant::UNMAPPED === $problem ) {
			return array(
				'' === $integration
					? __( 'No blank variant found: pick one.', 'pixonaut-for-woocommerce' )
					/* translators: %s: a print partner, such as Gelato, whose product the blank is. Shown under the blank variant picker on the product edit page. */
					: sprintf( __( 'No %s variant found: pick one.', 'pixonaut-for-woocommerce' ), $integration ),
			);
		}
		if ( ProductVariant::RETIRED === $problem ) {
			return array( __( 'This variant is retired, so customers cannot buy this. Pick another variant.', 'pixonaut-for-woocommerce' ) );
		}
		$missing = null === $stored || null === $config ? array() : $stored->sides_without_photo( $config );
		if ( array() === $missing ) {
			return array();
		}
		return array(
			wp_sprintf(
				/* translators: %l: the sides with no photo, e.g. "Front and Back". */
				__( 'No view the product is shown in has a photo of this variant on %l, so customers cannot design it.', 'pixonaut-for-woocommerce' ),
				array_map( static fn ( Side $side ): string => $side->label, $missing )
			),
		);
	}
}
