<?php
/**
 * The variations a product's plan offers, against the ones it has.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use Closure;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use WC_Product;
use WC_Product_Attribute;
use WP_Error;
use WP_Http;
use WP_Term;

/**
 * Works out, for a variable product and its plan, every variation the plan
 * offers and how the product's own variations stand against them.
 *
 * Each entry of the plan multiplies the product's variation attributes:
 *
 * - an attribute the entry picks its blank by holds the value it names;
 * - a global attribute linked to a blank option (`BlankOptionLinks`) the
 *   blank has takes each of its values, which name the option's values;
 *   one whose option the blank lacks is "Any", as is an attribute another
 *   entry picks its blank by;
 * - any other attribute, a template choice such as with or without a
 *   photo, takes each of its values, the same blank variant for each.
 *
 * Each combination is sold as the one variant on sale its option values
 * name, with the entry's fixed options, or the one value on sale of an
 * option no attribute offers. A combination naming none is skipped and
 * listed. A variation is matched to a combination by its attribute values
 * alone, so its id, history and own settings are kept: one the plan does
 * not offer is disabled, never deleted; one the plan disabled and offers
 * again is published again; one selling no variant now is given the
 * plan's. A variation the store manager disabled, priced or set up is left
 * as it is.
 *
 * @phpstan-import-type Entry from VariationPlan
 * @phpstan-import-type Added from VariationChanges
 * @phpstan-import-type Changed from VariationChanges
 * @phpstan-import-type Disabled from VariationChanges
 * @phpstan-import-type Skipped from VariationChanges
 * @phpstan-type Value array{value: string, label: string, term: WP_Term|null}
 */
final class VariationCombinations {

	/**
	 * The most combinations one plan may offer: beyond it a product page
	 * lists more variations than WooCommerce shows well.
	 */
	public const MAX_COMBINATIONS = 1000;

	/**
	 * @param Closure $variants A blank's variants, retired ones included, each with its options as written in Pixonaut too, as `ProductVariants::named()` reads them.
	 * @phpstan-param Closure(string): list<BlankVariant> $variants
	 */
	public function __construct( private readonly Closure $variants ) {
	}

	/**
	 * What generating the product's variations from the plan does, or why
	 * the plan cannot be used.
	 *
	 * @param WC_Product    $product A variable product.
	 * @param VariationPlan $plan    Its plan.
	 * @throws ApiException When a blank's variants could not be read.
	 */
	public function of( WC_Product $product, VariationPlan $plan ): VariationChanges|WP_Error {
		$offered = self::offered( $product );
		if ( array() === $offered ) {
			return self::refused( __( 'The product has no attribute used for variations: add the attributes and values it offers first.', 'pixonaut-for-woocommerce' ) );
		}
		if ( array() === $plan->entries ) {
			return self::refused( __( 'The plan names no blank.', 'pixonaut-for-woocommerce' ) );
		}
		$apart = self::apart( $plan );
		if ( $apart instanceof WP_Error ) {
			return $apart;
		}
		$selectors = $plan->selectors();
		$wanted    = array();
		$skipped   = array();
		foreach ( $plan->entries as $index => $entry ) {
			$made = $this->entry( $product, $offered, $selectors, $index, $entry );
			if ( $made instanceof WP_Error ) {
				return $made;
			}
			foreach ( $made['wanted'] as $key => $one ) {
				if ( isset( $wanted[ $key ] ) ) {
					return self::refused(
						/* translators: 1: a variation's values, e.g. "Size: M, Frame: Black". 2 and 3: two blanks' ids. */
						sprintf( __( 'Both %2$s and %3$s would sell %1$s: give each blank values of its own to pick it by.', 'pixonaut-for-woocommerce' ), $one['label'], $wanted[ $key ]['blank_id'], $one['blank_id'] )
					);
				}
				$wanted[ $key ] = $one;
			}
			$skipped += $made['skipped'];
			if ( count( $wanted ) > self::MAX_COMBINATIONS ) {
				return self::too_many();
			}
		}
		return $this->against( $product, $offered, $wanted, $skipped );
	}

	/**
	 * Null when every two entries pick their blanks by different values of
	 * an attribute both name; else why not: a customer's choice would
	 * match both.
	 *
	 * @param VariationPlan $plan The plan.
	 */
	private static function apart( VariationPlan $plan ): ?WP_Error {
		foreach ( $plan->entries as $index => $entry ) {
			foreach ( array_slice( $plan->entries, $index + 1 ) as $other ) {
				$differ = false;
				foreach ( array_intersect_key( $entry['when'], $other['when'] ) as $name => $value ) {
					$differ = $differ || ProductVariant::normalise( $value ) !== ProductVariant::normalise( $other['when'][ $name ] );
				}
				if ( ! $differ ) {
					return self::refused(
						/* translators: 1 and 2: two blanks' ids. */
						sprintf( __( 'Both %1$s and %2$s would sell the same values: give each blank a value of its own, of an attribute both name, to pick it by.', 'pixonaut-for-woocommerce' ), $entry['blank_id'], $other['blank_id'] )
					);
				}
			}
		}
		return null;
	}

	/**
	 * The product's variation attributes, in its order, each with the
	 * values it offers.
	 *
	 * @param WC_Product $product A variable product.
	 * @return array<string, list<Value>>
	 */
	public static function offered( WC_Product $product ): array {
		$offered = array();
		foreach ( $product->get_attributes() as $name => $attribute ) {
			if ( ! is_string( $name ) || ! $attribute instanceof WC_Product_Attribute || ! $attribute->get_variation() ) {
				continue;
			}
			$values = array();
			if ( $attribute->is_taxonomy() ) {
				foreach ( $attribute->get_terms() ?? array() as $term ) {
					$values[] = array(
						'value' => $term->slug,
						'label' => $term->name,
						'term'  => $term,
					);
				}
			} else {
				foreach ( $attribute->get_options() as $option ) {
					$values[] = array(
						'value' => (string) $option,
						'label' => (string) $option,
						'term'  => null,
					);
				}
			}
			if ( array() !== $values ) {
				$offered[ $name ] = $values;
			}
		}
		return $offered;
	}

	/**
	 * The value of an attribute a plan names, by its slug, text or name,
	 * case and spacing aside; null for none.
	 *
	 * @param array  $values The attribute's values.
	 * @param string $named  How the plan names one.
	 * @phpstan-param list<Value> $values
	 * @return Value|null
	 */
	public static function value_named( array $values, string $named ): ?array {
		foreach ( $values as $value ) {
			if ( $value['value'] === $named ) {
				return $value;
			}
		}
		foreach ( $values as $value ) {
			if ( ProductVariant::normalise( $value['label'] ) === ProductVariant::normalise( $named ) ) {
				return $value;
			}
		}
		return null;
	}

	/**
	 * A variation's values as the store manager reads them, `Frame: Black, Size: M`.
	 *
	 * @param WC_Product            $product    The variable product.
	 * @param array<string, string> $attributes Values by attribute name, `''` for "Any".
	 */
	public static function label( WC_Product $product, array $attributes ): string {
		$parts = array();
		foreach ( $attributes as $name => $value ) {
			$term    = '' !== $value && taxonomy_exists( $name ) ? get_term_by( 'slug', $value, $name ) : null;
			$shown   = $term instanceof WP_Term ? $term->name : ( '' === $value ? __( 'Any', 'pixonaut-for-woocommerce' ) : $value );
			$parts[] = ProductVariants::attribute_label( $name, $product ) . ': ' . $shown;
		}
		return implode( ', ', $parts );
	}

	/**
	 * The combinations one entry offers, and those it skips.
	 *
	 * @param WC_Product                 $product   The variable product.
	 * @param array<string, list<Value>> $offered   Its attributes' values.
	 * @param string[]                   $selectors The attributes some entry picks its blank by.
	 * @param int                        $index     The entry's place in the plan.
	 * @param array                      $entry     The entry.
	 * @phpstan-param list<string> $selectors
	 * @phpstan-param Entry $entry
	 * @return array{wanted: array<string, Added>, skipped: array<string, Skipped>}|WP_Error
	 * @throws ApiException When the blank's variants could not be read.
	 */
	private function entry( WC_Product $product, array $offered, array $selectors, int $index, array $entry ): array|WP_Error {
		$blank_id = $entry['blank_id'];
		$picked   = array();
		foreach ( $entry['when'] as $name => $named ) {
			if ( ! isset( $offered[ $name ] ) ) {
				return self::refused(
					/* translators: 1: an attribute's name, e.g. pa_frame. 2: a blank's id. */
					sprintf( __( 'The product has no attribute %1$s used for variations to pick %2$s by.', 'pixonaut-for-woocommerce' ), $name, $blank_id )
				);
			}
			$value = self::value_named( $offered[ $name ], $named );
			if ( null === $value ) {
				return self::refused(
					/* translators: 1: a value. 2: an attribute's name. */
					sprintf( __( 'The product does not offer %1$s for %2$s.', 'pixonaut-for-woocommerce' ), $named, $name )
				);
			}
			$picked[ $name ] = $value;
		}
		$variants = ( $this->variants )( $blank_id );
		$axes     = self::axes( $variants );
		$linked   = array();
		$columns  = array();
		foreach ( $offered as $name => $values ) {
			$option = BlankOptionLinks::option_of( $name );
			$axis   = null === $option ? null : self::axis_named( $axes, $option );
			if ( null !== $axis ) {
				$linked[ $name ] = $axis;
			}
			if ( isset( $picked[ $name ] ) ) {
				$columns[ $name ] = array( $picked[ $name ] );
			} elseif ( null !== $axis || ( null === $option && ! in_array( $name, $selectors, true ) ) ) {
				$columns[ $name ] = $values;
			} else {
				$columns[ $name ] = array( null );
			}
		}
		if ( array_product( array_map( 'count', $columns ) ) > self::MAX_COMBINATIONS ) {
			return self::too_many();
		}
		$fixed = self::fixed( $blank_id, $axes, array_values( $linked ), $entry['fixed'], $variants );
		if ( $fixed instanceof WP_Error ) {
			return $fixed;
		}
		$wanted  = array();
		$skipped = array();
		foreach ( self::product_of( $columns ) as $row ) {
			$attributes = array();
			$options    = $fixed;
			foreach ( $row as $name => $value ) {
				$attributes[ $name ] = null === $value ? '' : $value['value'];
				if ( null !== $value && isset( $linked[ $name ] ) ) {
					$options[ $linked[ $name ] ] = null === $value['term'] ? $value['label'] : BlankOptionLinks::stands_for( $name, $value['term'] );
				}
			}
			$key   = self::key( $attributes );
			$label = self::label( $product, $attributes );
			$found = self::variant_of( $options, $variants );
			if ( $found instanceof BlankVariant ) {
				$wanted[ $key ] = array(
					'attributes' => $attributes,
					'label'      => $label,
					'entry'      => $index,
					'blank_id'   => $blank_id,
					'ref'        => $found->ref,
					'variant'    => $found->label(),
				);
			} else {
				$skipped[ $key ] = array(
					'attributes' => $attributes,
					'label'      => $label,
					'entry'      => $index,
					'blank_id'   => $blank_id,
					'why'        => $found,
				);
			}
		}
		return array(
			'wanted'  => $wanted,
			'skipped' => $skipped,
		);
	}

	/**
	 * The product's variations against the combinations offered.
	 *
	 * @param WC_Product                 $product The variable product.
	 * @param array<string, list<Value>> $offered Its attributes' values.
	 * @param array<string, Added>       $wanted  The combinations offered, by key.
	 * @param array<string, Skipped>     $skipped The combinations skipped, by key.
	 */
	private function against( WC_Product $product, array $offered, array $wanted, array $skipped ): VariationChanges {
		$change  = array();
		$disable = array();
		$kept    = array();
		$matched = array();
		foreach ( $product->get_children() as $child_id ) {
			$variation = wc_get_product( $child_id );
			if ( ! $variation instanceof WC_Product ) {
				continue;
			}
			$held       = $variation->get_attributes( 'edit' );
			$attributes = array();
			foreach ( array_keys( $offered ) as $name ) {
				$attributes[ $name ] = is_string( $held[ $name ] ?? null ) ? $held[ $name ] : '';
			}
			$key    = self::key( $attributes );
			$one    = $wanted[ $key ] ?? null;
			$public = ProductConfig::PUBLIC_STATUS === $variation->get_status( 'edit' );
			if ( null === $one || isset( $matched[ $key ] ) ) {
				if ( $public ) {
					$disable[] = array(
						'variation_id' => $variation->get_id(),
						'attributes'   => $attributes,
						'label'        => self::label( $product, $attributes ),
						'why'          => VariationChanges::RETIRED === ( $skipped[ $key ]['why'] ?? null ) ? VariationChanges::RETIRED : VariationChanges::NOT_OFFERED,
					);
				} else {
					$kept[] = $variation->get_id();
				}
				continue;
			}
			$matched[ $key ] = true;
			$why             = self::why_changed( $variation, $public );
			if ( null === $why ) {
				$kept[] = $variation->get_id();
				continue;
			}
			$change[] = array( 'variation_id' => $variation->get_id() ) + $one + array( 'why' => $why );
		}
		return new VariationChanges(
			array_values( array_diff_key( $wanted, $matched ) ),
			$change,
			$disable,
			array_values( $skipped ),
			$kept
		);
	}

	/**
	 * Why a variation the plan offers is changed; null when it is kept as it is.
	 *
	 * @param WC_Product $variation The variation.
	 * @param bool       $shown     Whether customers can choose it.
	 */
	private static function why_changed( WC_Product $variation, bool $shown ): ?string {
		if ( ! $shown ) {
			// One the store manager disabled stays disabled.
			return GeneratedVariation::disabled( $variation ) ? VariationChanges::ENABLE : null;
		}
		$variant = ProductVariant::stored_on( $variation );
		return null === $variant || null !== $variant->problem() ? VariationChanges::VARIANT : null;
	}

	/**
	 * The options a blank's variants have, as written in Pixonaut.
	 *
	 * @param BlankVariant[] $variants The blank's variants.
	 * @phpstan-param list<BlankVariant> $variants
	 * @return list<string>
	 */
	private static function axes( array $variants ): array {
		$axes = array();
		foreach ( $variants as $variant ) {
			foreach ( array_keys( array() === $variant->written ? $variant->options : $variant->written ) as $axis ) {
				if ( ! in_array( (string) $axis, $axes, true ) ) {
					$axes[] = (string) $axis;
				}
			}
		}
		return $axes;
	}

	/**
	 * One of the blank's options by name, case and spacing aside.
	 *
	 * @param string[] $axes The blank's options.
	 * @param string   $name An option's name.
	 * @phpstan-param list<string> $axes
	 */
	private static function axis_named( array $axes, string $name ): ?string {
		foreach ( $axes as $axis ) {
			if ( ProductVariant::normalise( $axis ) === ProductVariant::normalise( $name ) ) {
				return $axis;
			}
		}
		return null;
	}

	/**
	 * The value of each option of the blank no attribute offers: the
	 * entry's, else the one value on sale.
	 *
	 * @param string                $blank_id The blank.
	 * @param string[]              $axes     Its options.
	 * @param string[]              $offered  The options its attributes offer.
	 * @param array<string, string> $given    The entry's fixed options.
	 * @param BlankVariant[]        $variants Its variants.
	 * @phpstan-param list<BlankVariant> $variants
	 * @phpstan-param list<string> $axes
	 * @phpstan-param list<string> $offered
	 * @return array<string, string>|WP_Error
	 */
	private static function fixed( string $blank_id, array $axes, array $offered, array $given, array $variants ): array|WP_Error {
		$fixed = array();
		foreach ( $given as $name => $value ) {
			$axis = self::axis_named( $axes, $name );
			if ( null === $axis ) {
				return self::refused(
					/* translators: 1: an option's name. 2: a blank's id. */
					sprintf( __( 'The blank %2$s has no option %1$s.', 'pixonaut-for-woocommerce' ), $name, $blank_id )
				);
			}
			$fixed[ $axis ] = $value;
		}
		foreach ( array_diff( $axes, $offered, array_keys( $fixed ) ) as $axis ) {
			$on_sale = array();
			foreach ( $variants as $variant ) {
				$options = array() === $variant->written ? $variant->options : $variant->written;
				if ( null === $variant->retired && isset( $options[ $axis ] ) && ! in_array( $options[ $axis ], $on_sale, true ) ) {
					$on_sale[] = $options[ $axis ];
				}
			}
			if ( count( $on_sale ) > 1 ) {
				return self::refused(
					/* translators: 1: an option's name. 2: a blank's id. 3: its values, comma-separated. */
					sprintf( __( 'No attribute offers the option %1$s of %2$s, which has several values (%3$s): name the one to sell in the entry\'s fixed options, or link an attribute to it.', 'pixonaut-for-woocommerce' ), $axis, $blank_id, implode( ', ', $on_sale ) )
				);
			}
			if ( 1 === count( $on_sale ) ) {
				$fixed[ $axis ] = $on_sale[0];
			}
		}
		return $fixed;
	}

	/**
	 * The one variant on sale options name; else why there is none.
	 *
	 * @param array<string, string> $options  Option values by option.
	 * @param BlankVariant[]        $variants The blank's variants.
	 * @phpstan-param list<BlankVariant> $variants
	 */
	private static function variant_of( array $options, array $variants ): BlankVariant|string {
		$named   = array_values( array_filter( $variants, static fn ( BlankVariant $variant ): bool => $variant->is_named_by( $options ) ) );
		$on_sale = array_values( array_filter( $named, static fn ( BlankVariant $variant ): bool => null === $variant->retired ) );
		if ( 1 === count( $on_sale ) ) {
			return $on_sale[0];
		}
		if ( array() !== $on_sale ) {
			return VariationChanges::SEVERAL_VARIANTS;
		}
		return array() === $named ? VariationChanges::NO_VARIANT : VariationChanges::RETIRED;
	}

	/**
	 * Every combination of one value per column, in the columns' order.
	 *
	 * @param array<string, list<Value|null>> $columns Each attribute's values; null for "Any".
	 * @return list<array<string, Value|null>>
	 */
	private static function product_of( array $columns ): array {
		$rows = array( array() );
		foreach ( $columns as $name => $values ) {
			$next = array();
			foreach ( $rows as $row ) {
				foreach ( $values as $value ) {
					$next[] = $row + array( $name => $value );
				}
			}
			$rows = $next;
		}
		return $rows;
	}

	/**
	 * A combination's key: its values by attribute, in the product's order.
	 *
	 * @param array<string, string> $attributes Values by attribute name.
	 */
	private static function key( array $attributes ): string {
		return (string) wp_json_encode( $attributes );
	}

	private static function too_many(): WP_Error {
		return self::refused(
			/* translators: %d: the most variations a plan may make. */
			sprintf( __( 'The plan offers more than %d variations.', 'pixonaut-for-woocommerce' ), self::MAX_COMBINATIONS )
		);
	}

	/**
	 * @param string $message Why the plan cannot be used.
	 */
	private static function refused( string $message ): WP_Error {
		return new WP_Error( 'pixonaut_wc_invalid_variation_plan', $message, array( 'status' => WP_Http::BAD_REQUEST ) );
	}
}
