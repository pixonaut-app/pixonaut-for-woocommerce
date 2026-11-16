<?php
/**
 * The ability that reads a product's prices against their printshop costs.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use Closure;
use Pixonaut\WooCommerce\Pricing\PriceChanges;
use Pixonaut\WooCommerce\Pricing\PriceRow;
use Pixonaut\WooCommerce\Pricing\ProductPricing;
use WC_Product;
use WP_Error;

/**
 * What the Prices section of a product's Pixonaut tab lists: each variation
 * (a simple product has one row) with what the printshop charges for it,
 * its price and margin, how its price stands to the pricing rule and what
 * the rule would change, and the rule the product is priced by. It reads
 * what is stored; nothing is asked of Pixonaut. Whoever may edit the
 * product may read them.
 */
final class GetProductPrices implements Ability {

	public const NAME = 'get-product-prices';

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
			'label'               => __( 'Read a product\'s prices and costs', 'pixonaut-for-woocommerce' ),
			'description'         => sprintf(
				/* translators: %s: the name of the ability that prices a product. */
				__( 'Lists each variation of a product printed on a blank (a simple product has one row) with what the printshop charges for it, that cost in the store\'s currency, its regular price and margin, how its price stands to the pricing rule (`standing`), the price the rule suggests when it is another, and what stops the rule (`flag`), and whether it changes by itself when its cost changes (`automatic`, and `price_changes` for what it is set to). Also the rule the product is priced by: the store\'s, with the parts the product changes (`own_rule`). The costs are the ones last read: `checked_at` null means never. %s reads them again, and with `dry_run` answers the prices it would set, changing nothing. `prices` is a page of the rows, `page` and `per_page` choosing which; `total` counts them all.', 'pixonaut-for-woocommerce' ),
				Abilities::tool( PriceProduct::NAME )
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'product_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'A simple or variable product; never a variation.', 'pixonaut-for-woocommerce' ),
					),
				) + ProductPrices::paging_schema(),
				'required'             => array( 'product_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => self::product_schema() + ProductPrices::page_schema(),
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( ProductLinks::class, 'can_edit' ),
			'annotations'         => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
		);
	}

	/**
	 * @param mixed $input `product_id`, and optionally `page` and `per_page`.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute( mixed $input = null ): array|WP_Error {
		$product = ProductPrices::product( $input );
		if ( $product instanceof WP_Error ) {
			return $product;
		}
		return self::answer( ( $this->pricing )(), $product, $input );
	}

	/**
	 * The ability's answer for a product, from what is stored.
	 *
	 * @param ProductPricing $pricing Reads the prices.
	 * @param WC_Product     $product The product.
	 * @param mixed          $input   The call's input, with `page` and `per_page`.
	 * @return array<string, mixed>
	 */
	public static function answer( ProductPricing $pricing, WC_Product $product, mixed $input ): array {
		return self::product_values( $pricing, $product, ProductPricing::own_parts( $product ), $pricing->rule_for( $product )->is_set() ) + ProductPrices::page( array_map( static fn ( PriceRow $row ): array => ProductPrices::row( $row ), $pricing->rows( $product ) ), $input );
	}

	/**
	 * What an answer says of the product besides its prices.
	 *
	 * @param ProductPricing        $pricing  Reads the store's rule, and whether the product's prices change by themselves.
	 * @param WC_Product            $product  The product.
	 * @param array<string, string> $own      The parts of the rule the product changes, by part.
	 * @param bool                  $rule_set Whether there is a rule to price it by.
	 * @return array<string, mixed>
	 */
	public static function product_values( ProductPricing $pricing, WC_Product $product, array $own, bool $rule_set ): array {
		return array(
			'product_id'         => $product->get_id(),
			'currency'           => get_woocommerce_currency(),
			'prices_include_tax' => wc_tax_enabled() && wc_prices_include_tax(),
			'rule_set'           => $rule_set,
			'rule'               => array_filter( $own, static fn ( string $part ): bool => '' !== $part ) + $pricing->store_parts(),
			'own_rule'           => $own,
			'automatic'          => $pricing->automatic_for( $product ),
			'price_changes'      => PriceChanges::setting( $product ),
		);
	}

	/**
	 * The schema of `product_values()`.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function product_schema(): array {
		return array(
			'product_id'         => array( 'type' => 'integer' ),
			'currency'           => array(
				'type'        => 'string',
				'description' => __( 'The store\'s currency, which prices and `cost_total` are in.', 'pixonaut-for-woocommerce' ),
			),
			'prices_include_tax' => array( 'type' => 'boolean' ),
			'rule_set'           => array(
				'type'        => 'boolean',
				'description' => __( 'Whether there is a rule to price by: false without a target margin, and nothing is then priced.', 'pixonaut-for-woocommerce' ),
			),
			'rule'               => ProductPrices::parts_schema( __( 'The rule the product is priced by: margin and tolerance in percent, step, ending, min and max as amounts; empty for none.', 'pixonaut-for-woocommerce' ) ),
			'own_rule'           => ProductPrices::parts_schema( __( 'The parts of the rule the product changes; a part it leaves to the store is empty.', 'pixonaut-for-woocommerce' ) ),
			'automatic'          => array(
				'type'        => 'boolean',
				'description' => __( 'Whether the product\'s prices that follow the rule change by themselves when a cost changes: what the product is set to, or the store\'s switch when it follows the store. A variation may answer otherwise: see `automatic` of each price.', 'pixonaut-for-woocommerce' ),
			),
			'price_changes'      => array(
				'type'        => 'string',
				'enum'        => PriceChanges::OF_PRODUCT,
				'description' => __( 'What the product is set to: store follows the store\'s switch for automatic price changes, always and never answer it for this product.', 'pixonaut-for-woocommerce' ),
			),
		);
	}
}
