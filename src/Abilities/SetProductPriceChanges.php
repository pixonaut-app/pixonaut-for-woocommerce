<?php
/**
 * The ability that says whether a product's prices change by themselves.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use Closure;
use Pixonaut\WooCommerce\Pricing\PriceChanges;
use Pixonaut\WooCommerce\Pricing\ProductPricing;
use WC_Product;
use WP_Error;

/**
 * **Automatic price changes**, in the Prices section of a product's
 * Pixonaut tab: whether the product follows the store's switch, or its
 * prices always or never change by themselves when a cost changes; and the
 * same for one variation against its product. It sets no price. Whoever may
 * edit the product may set it.
 */
final class SetProductPriceChanges implements Ability {

	public const NAME = 'set-product-price-changes';

	private const UNPROCESSABLE = 422;

	/**
	 * @param Closure $pricing Gives what reads the prices, once a call needs it.
	 * @phpstan-param Closure(): ProductPricing $pricing
	 */
	public function __construct( private readonly Closure $pricing ) {
	}

	public function name(): string {
		return self::NAME;
	}

	public function definition(): array {
		return array(
			/* translators: Name of an ability an AI assistant or an MCP client can use on the store. */
			'label'               => __( 'Set a product\'s automatic price changes', 'pixonaut-for-woocommerce' ),
			'description'         => sprintf(
				/* translators: 1: the name of the ability that changes the pricing settings, 2: the name of the ability that reads a product's prices. */
				__( 'Says whether the prices of a product printed on a blank change by themselves when a cost changes, whatever the store\'s switch (`price_automatic`, %1$s) says. `price_changes`: `store` follows the store\'s switch, `always` has the product\'s prices that follow the rule set to the rule\'s price when their cost changes and their margin left the tolerance, also with the store\'s switch off; `never` only has the new price suggested, also with the switch on. `variations` does the same for single variations, each against its product: `product` follows the product, `always` and `never` answer it. What is left out keeps what it has. It sets no price and reads no cost. Answers what %2$s does: `automatic` and `price_changes` of the product, and of each price.', 'pixonaut-for-woocommerce' ),
				Abilities::tool( UpdatePricingSettings::NAME ),
				Abilities::tool( GetProductPrices::NAME )
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'product_id'    => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'A simple or variable product; never a variation.', 'pixonaut-for-woocommerce' ),
					),
					'price_changes' => array(
						'type' => 'string',
						'enum' => PriceChanges::OF_PRODUCT,
					),
					'variations'    => array(
						'type'     => 'array',
						'minItems' => 1,
						'items'    => array(
							'type'                 => 'object',
							'properties'           => array(
								'id'            => array(
									'type'    => 'integer',
									'minimum' => 1,
								),
								'price_changes' => array(
									'type' => 'string',
									'enum' => PriceChanges::OF_VARIATION,
								),
							),
							'required'             => array( 'id', 'price_changes' ),
							'additionalProperties' => false,
						),
					),
				) + ProductPrices::paging_schema(),
				'required'             => array( 'product_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => GetProductPrices::product_schema() + ProductPrices::page_schema(),
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( ProductLinks::class, 'can_edit' ),
			'annotations'         => array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => true,
			),
		);
	}

	/**
	 * @param mixed $input `product_id`, and optionally `price_changes`, `variations`, `page` and `per_page`.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute( mixed $input = null ): array|WP_Error {
		$input   = is_array( $input ) ? $input : array();
		$product = ProductPrices::product( $input );
		if ( $product instanceof WP_Error ) {
			return $product;
		}
		$settings = self::settings( $input, $product );
		if ( $settings instanceof WP_Error ) {
			return $settings;
		}
		foreach ( $settings as list( $item, $setting ) ) {
			PriceChanges::save( $item, $setting );
		}
		$fresh = wc_get_product( $product->get_id() );
		$fresh = $fresh instanceof WC_Product ? $fresh : $product;
		return GetProductPrices::answer( ( $this->pricing )(), $fresh, $input );
	}

	/**
	 * What the call sets, each checked before any is stored: the product or
	 * variation, and what it is set to.
	 *
	 * @param array<mixed, mixed> $input   The call's input.
	 * @param WC_Product          $product The product.
	 * @return list<array{0: WC_Product, 1: string}>|WP_Error
	 */
	private static function settings( array $input, WC_Product $product ): array|WP_Error {
		$settings = array();
		if ( isset( $input['price_changes'] ) ) {
			if ( ! PriceChanges::allows( $product, $input['price_changes'] ) ) {
				return self::refused( $product );
			}
			$settings[] = array( $product, (string) $input['price_changes'] );
		}
		$variations = array();
		foreach ( $product->is_type( 'variable' ) ? ProductPricing::items( $product ) : array() as $variation ) {
			$variations[ $variation->get_id() ] = $variation;
		}
		foreach ( is_array( $input['variations'] ?? null ) ? $input['variations'] : array() as $named ) {
			$id        = Abilities::integer( $named, 'id', 0 );
			$variation = $variations[ $id ] ?? null;
			if ( null === $variation ) {
				/* translators: %s: an id, e.g. 12. */
				return new WP_Error( 'pixonaut_wc_ability_unknown_variation', sprintf( __( 'The product has no variation with this id: %s.', 'pixonaut-for-woocommerce' ), (string) $id ), array( 'status' => self::UNPROCESSABLE ) );
			}
			$setting = is_array( $named ) ? ( $named['price_changes'] ?? null ) : null;
			if ( ! PriceChanges::allows( $variation, $setting ) ) {
				return self::refused( $variation );
			}
			$settings[] = array( $variation, (string) $setting );
		}
		return $settings;
	}

	/**
	 * The refusal of a value a product or variation cannot be set to.
	 *
	 * @param WC_Product $item The product or variation.
	 */
	private static function refused( WC_Product $item ): WP_Error {
		return new WP_Error(
			'pixonaut_wc_ability_price_changes',
			sprintf(
				/* translators: 1: the values to choose from, e.g. store, always, never; 2: a product or variation id. */
				__( '`price_changes` is one of %1$s for #%2$d.', 'pixonaut-for-woocommerce' ),
				implode( ', ', $item->is_type( 'variation' ) ? PriceChanges::OF_VARIATION : PriceChanges::OF_PRODUCT ),
				$item->get_id()
			),
			array( 'status' => self::UNPROCESSABLE )
		);
	}
}
