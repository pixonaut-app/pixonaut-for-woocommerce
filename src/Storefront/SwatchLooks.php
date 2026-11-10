<?php
/**
 * How each value of a product's variation attributes looks as a swatch.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use Pixonaut\WooCommerce\Catalogue\ProductVariant;
use Pixonaut\WooCommerce\Settings\Settings;
use WC_Product;
use WC_Product_Variable;
use WP_Term;

/**
 * The look Pixonaut gives each value of the blank's options (a colour, two,
 * or a picture; none for a value drawn as its name), carried to the values
 * of the product's variation attributes, for the swatches the storefront
 * draws for them. An attribute names an option, and its value one of the
 * option's values, by label, as a product's attributes name its blank
 * variant (`ProductVariant`): in the language the designer reads the blank
 * in, or as written in Pixonaut.
 *
 * @phpstan-type Look array{kind: 'fill', colours: list<string>}|array{kind: 'image', url: string}
 * @phpstan-type AttributeLooks array{names: bool, looks: array<string, Look>}
 */
final class SwatchLooks {

	/**
	 * Most colours a fill is drawn in: two for a heather or two-tone.
	 */
	private const MAX_FILL_COLOURS = 2;

	/**
	 * A colour as Pixonaut writes a fill's: `#rrggbb`.
	 */
	private const HEX_COLOUR = '/^#[0-9a-f]{6}$/i';

	/**
	 * The schemes a picture's link may have.
	 */
	private const IMAGE_SCHEMES = array( 'https', 'http' );

	/**
	 * Each product's looks, worked out once per request, by product id.
	 *
	 * @var array<int, array<string, AttributeLooks>>
	 */
	private array $looks = array();

	/**
	 * @param ServerReads $reads    Reads the blank's options.
	 * @param Settings    $settings The store's swatch names setting.
	 */
	public function __construct(
		private readonly ServerReads $reads,
		private readonly Settings $settings,
	) {
	}

	/**
	 * The looks of the designer's product's variation attributes, by the
	 * field each attribute is posted as (`attribute_pa_frame`): whether its
	 * swatches show their names, and the look of each value that has one, by
	 * the value the field posts. An attribute none of whose values has a
	 * look is left out, as is every attribute of a product with more than
	 * one side or no blank.
	 *
	 * @param InlineDesigner $designer The product's designer.
	 * @return array<string, AttributeLooks>
	 */
	public function of( InlineDesigner $designer ): array {
		return $this->looks[ $designer->product->get_id() ] ??= $this->read( $designer );
	}

	/**
	 * @param InlineDesigner $designer The product's designer.
	 * @return array<string, AttributeLooks>
	 */
	private function read( InlineDesigner $designer ): array {
		$product = $designer->product;
		$side    = array_key_first( $designer->mounts );
		$blank   = null === $side ? null : ( $designer->mounts[ $side ]['blankId'] ?? null );
		if ( ! $product instanceof WC_Product_Variable || 1 !== count( $designer->mounts ) || ! is_string( $blank ) ) {
			return array();
		}
		$named = ServerReads::decoded( $this->reads->for_mounts( $designer->mounts )[ (string) $side ] ?? array() );
		return self::for_product(
			$product,
			array(
				ServerReads::options_of( $named['blank']['variants'] ?? null ),
				$this->reads->written_options( $blank ),
			),
			$this->settings
		);
	}

	/**
	 * The looks of a variable product's attributes, as `of()` answers them,
	 * from its blank's options.
	 *
	 * @param WC_Product_Variable      $product  The product.
	 * @param list<list<array<mixed>>> $options  The blank's options as the designer names them, then as written: each with its `name` and its values' `appearances`.
	 * @param Settings                 $settings The store's swatch names setting.
	 * @return array<string, AttributeLooks>
	 */
	public static function for_product( WC_Product_Variable $product, array $options, Settings $settings ): array {
		$looks = array();
		foreach ( $product->get_variation_attributes() as $name => $values ) {
			$attribute = self::attribute( $product, (string) $name, is_array( $values ) ? $values : array(), $options, $settings );
			if ( array() !== $attribute['looks'] ) {
				$looks[ wc_variation_attribute_name( (string) $name ) ] = $attribute;
			}
		}
		return $looks;
	}

	/**
	 * One attribute's looks.
	 *
	 * @param WC_Product               $product The variable product.
	 * @param string                   $name    The attribute's name: `pa_frame` for a global one, else as entered.
	 * @param array<mixed>             $values  The values its variations use: a global attribute's term slugs, else the values as entered.
	 * @param list<list<array<mixed>>> $options The blank's options as the designer names them, then as written.
	 * @param Settings                 $settings The store's swatch names setting.
	 * @return AttributeLooks
	 */
	private static function attribute( WC_Product $product, string $name, array $values, array $options, Settings $settings ): array {
		$label   = ProductVariant::normalise( wc_attribute_label( $name, $product ) );
		$matched = array();
		foreach ( $options as $list ) {
			foreach ( $list as $option ) {
				if ( is_string( $option['name'] ?? null ) && ProductVariant::normalise( $option['name'] ) === $label && is_array( $option['appearances'] ?? null ) ) {
					$matched[] = $option['appearances'];
				}
			}
		}
		$looks = array();
		foreach ( $values as $value ) {
			$value = (string) $value;
			$look  = null;
			foreach ( $matched as $appearances ) {
				$look ??= self::look( self::appearance_of( $appearances, self::value_label( $name, $value ) ) );
			}
			if ( null !== $look ) {
				$looks[ $value ] = $look;
			}
		}
		return array(
			'names' => SwatchNames::shown( $settings, $name ),
			'looks' => $looks,
		);
	}

	/**
	 * A value's words: a global attribute's term name, else the value as entered.
	 *
	 * @param string $name  The attribute's name.
	 * @param string $value The value the field posts.
	 */
	private static function value_label( string $name, string $value ): string {
		$term = taxonomy_exists( $name ) ? get_term_by( 'slug', $value, $name ) : null;
		return $term instanceof WP_Term ? $term->name : $value;
	}

	/**
	 * The appearance the option gives a value, named by label.
	 *
	 * @param array<mixed> $appearances The option's appearances, by value.
	 * @param string       $label       The value's words.
	 */
	private static function appearance_of( array $appearances, string $label ): mixed {
		$wanted = ProductVariant::normalise( $label );
		foreach ( $appearances as $value => $appearance ) {
			if ( ProductVariant::normalise( (string) $value ) === $wanted ) {
				return $appearance;
			}
		}
		return null;
	}

	/**
	 * An appearance as a swatch draws it; null for a value drawn as its name,
	 * or an appearance it cannot draw safely.
	 *
	 * @param mixed $appearance The appearance as Pixonaut answered it.
	 * @return Look|null
	 */
	private static function look( mixed $appearance ): ?array {
		if ( ! is_array( $appearance ) ) {
			return null;
		}
		if ( 'fill' === ( $appearance['kind'] ?? null ) ) {
			$colours = is_array( $appearance['colours'] ?? null ) ? array_values( $appearance['colours'] ) : array();
			foreach ( $colours as $colour ) {
				if ( ! is_string( $colour ) || 1 !== preg_match( self::HEX_COLOUR, $colour ) ) {
					return null;
				}
			}
			/** @var list<string> $colours */
			return array() === $colours || count( $colours ) > self::MAX_FILL_COLOURS ? null : array(
				'kind'    => 'fill',
				'colours' => $colours,
			);
		}
		if ( 'image' === ( $appearance['kind'] ?? null ) && is_string( $appearance['url'] ?? null ) ) {
			$url = esc_url_raw( $appearance['url'], self::IMAGE_SCHEMES );
			return '' === $url ? null : array(
				'kind' => 'image',
				'url'  => $url,
			);
		}
		return null;
	}
}
