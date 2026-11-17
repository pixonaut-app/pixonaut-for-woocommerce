<?php
/**
 * What the variation pictures abilities read and answer.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use Pixonaut\WooCommerce\Admin\VariationPicturesField;
use Pixonaut\WooCommerce\Catalogue\VariationPictureCost;
use Pixonaut\WooCommerce\Catalogue\VariationPictures;
use Pixonaut\WooCommerce\Settings\Settings;
use WC_Product;
use WP_Error;

/**
 * The store-wide setting or a variable product's choice of "Generate a
 * picture for every variation", and what a change costs the shop's
 * storage, as the settings page and the product's Pixonaut tab show it.
 */
final class VariationPicturesState {

	/**
	 * @param Settings               $settings The store-wide setting.
	 * @param VariationPicturesField $field    What a product's change costs.
	 * @param VariationPictureCost   $cost     What the store-wide change costs.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly VariationPicturesField $field,
		private readonly VariationPictureCost $cost,
	) {
	}

	/**
	 * The `product_id` input's schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function product_input(): array {
		return array(
			'type'        => 'integer',
			'minimum'     => 1,
			'description' => __( 'A variable product, for its own choice; leave out for the store-wide setting.', 'pixonaut-for-woocommerce' ),
		);
	}

	/**
	 * The answer's schema.
	 *
	 * @param bool $changed Whether it says what a change did, as setting it answers.
	 * @return array<string, mixed>
	 */
	public static function output_schema( bool $changed = false ): array {
		$cost = array(
			'type'       => 'object',
			'properties' => array(
				'on'       => array( 'type' => 'boolean' ),
				'pictures' => array( 'type' => 'integer' ),
				'bytes'    => array( 'type' => 'integer' ),
				'free'     => array( 'type' => array( 'integer', 'null' ) ),
				'percent'  => array( 'type' => array( 'integer', 'null' ) ),
				'warn'     => array( 'type' => 'boolean' ),
				'summary'  => array( 'type' => 'string' ),
				'warning'  => array( 'type' => array( 'string', 'null' ) ),
			),
		);
		return array(
			'type'       => 'object',
			'properties' => array(
				'store_wide'  => array( 'type' => 'boolean' ),
				'product_id'  => array( 'type' => 'integer' ),
				'choice'      => array(
					'type' => 'string',
					'enum' => VariationPictures::CHOICES,
				),
				'on'          => array( 'type' => 'boolean' ),
				'turning_on'  => $cost,
				'turning_off' => $cost,
			) + ( $changed ? array( 'changed' => $cost ) : array() ),
		);
	}

	/**
	 * The variable product the input names; an error when it names another,
	 * or none it may read. Null for the store-wide setting.
	 *
	 * @param mixed $input The ability's input.
	 */
	public static function product( mixed $input ): WC_Product|WP_Error|null {
		$product_id = Abilities::integer( $input, 'product_id', 0 );
		if ( 0 === $product_id ) {
			return null;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product || ! $product->is_type( 'variable' ) ) {
			/* translators: %d: a product's id. */
			return new WP_Error( 'pixonaut_wc_ability_not_variable', sprintf( __( 'Product %d is not a variable product: only a variable product\'s variations get pictures of their own.', 'pixonaut-for-woocommerce' ), $product_id ) );
		}
		return $product;
	}

	/**
	 * How it stands, with what turning it on and off would do: for a product
	 * when one is given, else store-wide.
	 *
	 * @param WC_Product|null $product A variable product; null for store-wide.
	 * @return array<string, mixed>
	 */
	public function read( ?WC_Product $product ): array {
		$store_wide = VariationPictures::store_wide( $this->settings );
		if ( null === $product ) {
			$following = $this->cost->following_store_wide();
			return array(
				'store_wide'  => $store_wide,
				'on'          => $store_wide,
				'turning_on'  => self::described( $this->cost->changing_to( $following, true ) ),
				'turning_off' => self::described( $this->cost->changing_to( $following, false ) ),
			);
		}
		return array(
			'store_wide'  => $store_wide,
			'product_id'  => $product->get_id(),
			'choice'      => VariationPictures::choice( $product ),
			'on'          => VariationPictures::on_for( $product, $this->settings ),
			'turning_on'  => self::described( $this->field->product_cost( $product, VariationPictures::ON ) ),
			'turning_off' => self::described( $this->field->product_cost( $product, VariationPictures::OFF ) ),
		);
	}

	/**
	 * What changing to the choice costs.
	 *
	 * @param WC_Product|null $product A variable product; null for store-wide.
	 * @param string          $choice  DEFAULT, ON or OFF; DEFAULT is a product's only.
	 *
	 * @return array{on: bool, pictures: int, bytes: int, free: int|null, percent: int|null, warn: bool}
	 */
	public function cost_of( ?WC_Product $product, string $choice ): array {
		if ( null !== $product ) {
			return $this->field->product_cost( $product, $choice );
		}
		return $this->cost->changing_to( $this->cost->following_store_wide(), VariationPictures::ON === $choice );
	}

	/**
	 * A cost with the text the store manager reads.
	 *
	 * @param array{on: bool, pictures: int, bytes: int, free: int|null, percent: int|null, warn: bool} $cost What a change costs.
	 * @return array<string, mixed>
	 */
	public static function described( array $cost ): array {
		return $cost + array(
			'summary' => VariationPictureCost::summary( $cost ),
			'warning' => VariationPictureCost::warning( $cost ),
		);
	}
}
