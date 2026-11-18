<?php
/**
 * The ability that prices a product by the pricing rule.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use Closure;
use Pixonaut\WooCommerce\Pricing\PriceRow;
use Pixonaut\WooCommerce\Pricing\PriceRule;
use Pixonaut\WooCommerce\Pricing\ProductPricing;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use WC_Product;
use WP_Error;

/**
 * Apply, in the Prices section of a product's Pixonaut tab: reads the
 * printshop's costs from Pixonaut again and sets each price to the rule's,
 * which follows the rule from then on. It prices every variation, or only
 * the ones without a price, or the ones named; a price edited by hand is
 * kept unless it is given back to the rule. A dry run answers the prices it
 * would set and stores nothing on the product. Whoever may edit the product
 * may price it.
 */
final class PriceProduct implements Ability {

	public const NAME = 'price-product';

	/**
	 * Every price.
	 */
	public const ALL = 'all';

	/**
	 * Only a product or variation without a regular price.
	 */
	public const UNPRICED = 'unpriced';

	private const UNPROCESSABLE = 422;

	/**
	 * @param Closure $pricing Gives what reads the costs and prices by the rule, once a call needs it.
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
			'label'               => __( 'Price a product by the pricing rule', 'pixonaut-for-woocommerce' ),
			'description'         => __( 'Reads the printshop\'s costs of a product printed on a blank again, and sets the regular price of each variation (a simple product has one) to the rule\'s: the price step nearest the target margin, within the lowest and highest price. A price it sets follows the rule from then on. `only` unpriced leaves every variation that has a price alone; `variation_ids` names the ones to price. A price edited by hand after the rule set it is kept unless `release_held`. `rule` stores the parts of the rule this product changes, first. With `dry_run` nothing is stored on the product: the answer gives the prices it would set. Each price answers `before`, `price` and `changed`; one the rule cannot set keeps its price and says why in `flag`. No other price is set, whatever changes by itself. `changed` counts every price that changed; `prices` is a page of them, `page` and `per_page` choosing which. Refused without a target margin.', 'pixonaut-for-woocommerce' ),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'product_id'    => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'A simple or variable product; never a variation.', 'pixonaut-for-woocommerce' ),
					),
					'only'          => array(
						'type'    => 'string',
						'enum'    => array( self::ALL, self::UNPRICED ),
						'default' => self::ALL,
					),
					'variation_ids' => array(
						'type'        => 'array',
						'description' => __( 'The variations to price, or the product\'s own id for a simple product; left out for all.', 'pixonaut-for-woocommerce' ),
						'items'       => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'minItems'    => 1,
					),
					'release_held'  => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'Whether a price edited by hand after the rule set it is given back to the rule.', 'pixonaut-for-woocommerce' ),
					),
					'rule'          => ProductPrices::parts_schema( __( 'The parts of the rule this product changes, replacing the ones it has: margin and tolerance in percent, fixed (added to the price excluding tax on top of the margin), step, ending, min and max as amounts. A part left out or empty is left to the store.', 'pixonaut-for-woocommerce' ) ),
					'dry_run'       => array(
						'type'    => 'boolean',
						'default' => false,
					),
				) + ProductPrices::paging_schema(),
				'required'             => array( 'product_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => GetProductPrices::product_schema() + array(
					'dry_run' => array( 'type' => 'boolean' ),
					'changed' => array(
						'type'        => 'integer',
						'description' => __( 'How many prices changed, or would.', 'pixonaut-for-woocommerce' ),
					),
				) + ProductPrices::page_schema(
					array(
						'before'  => array(
							'type'        => array( 'string', 'null' ),
							'description' => __( 'The regular price it had; null for none.', 'pixonaut-for-woocommerce' ),
						),
						'changed' => array( 'type' => 'boolean' ),
					)
				),
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
	 * @param mixed $input `product_id`, and optionally `only`, `variation_ids`, `release_held`, `rule`, `dry_run`, `page` and `per_page`.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute( mixed $input = null ): array|WP_Error {
		$input   = is_array( $input ) ? $input : array();
		$product = ProductPrices::product( $input );
		if ( $product instanceof WP_Error ) {
			return $product;
		}
		$pricing = ( $this->pricing )();
		$own     = self::own_parts( $input['rule'] ?? null, $product );
		if ( $own instanceof WP_Error ) {
			return $own;
		}
		if ( ! $pricing->rule_with( $own )->is_set() ) {
			return ProductPrices::no_rule();
		}
		$named = self::named( $input['variation_ids'] ?? null, $product );
		if ( $named instanceof WP_Error ) {
			return $named;
		}
		$unpriced = self::UNPRICED === ( $input['only'] ?? self::ALL );
		$release  = true === ( $input['release_held'] ?? false );
		$dry_run  = true === ( $input['dry_run'] ?? false );
		$before   = array();
		foreach ( $pricing->rows( $product ) as $row ) {
			$before[ $row->id ] = $row->price;
		}
		$ids = self::chosen( $before, $named, $unpriced );
		try {
			if ( $dry_run ) {
				$rows = $pricing->preview( $product, $ids, $release, $own );
			} else {
				// Every price is set from what the printshop charges now. Read first: a read that fails stores no rule.
				$pricing->read_costs( $product );
				if ( isset( $input['rule'] ) ) {
					$pricing->save_own_parts( self::reread( $product ), $own );
				}
				$pricing->apply( self::reread( $product ), $ids, $release );
				// The prices not chosen are only flagged: none of them is set, whatever the store changes by itself.
				$pricing->settle( self::reread( $product ) );
				$rows = $pricing->rows( self::reread( $product ) );
			}
		} catch ( ApiException $e ) {
			return ProductPrices::unread( $e );
		}
		$prices = array_map(
			static function ( PriceRow $row ) use ( $before ): array {
				$was = $before[ $row->id ] ?? null;
				return ProductPrices::row( $row ) + array(
					'before'  => ProductPrices::money( $was ),
					'changed' => $was !== $row->price,
				);
			},
			$rows
		);
		return GetProductPrices::product_values( $pricing, $product, $own, true ) + array(
			'dry_run' => $dry_run,
			'changed' => count( array_filter( array_column( $prices, 'changed' ) ) ),
		) + ProductPrices::page( $prices, $input );
	}

	/**
	 * The parts of the rule the product changes: the ones given, or the
	 * ones it has when none are.
	 *
	 * @param mixed      $rule    The call's `rule`.
	 * @param WC_Product $product The product.
	 * @return array<string, string>|WP_Error
	 */
	private static function own_parts( mixed $rule, WC_Product $product ): array|WP_Error {
		if ( ! is_array( $rule ) ) {
			return ProductPricing::own_parts( $product );
		}
		$own = array();
		foreach ( PriceRule::PARTS as $part ) {
			$written = $rule[ $part ] ?? '';
			if ( ! PriceRule::is_part( $written ) ) {
				/* translators: %s: a part of the pricing rule, e.g. margin. */
				return new WP_Error( 'pixonaut_wc_ability_rule_part', sprintf( __( 'The rule\'s %s is not a number.', 'pixonaut-for-woocommerce' ), $part ), array( 'status' => self::UNPROCESSABLE ) );
			}
			$own[ $part ] = PriceRule::part( $written );
		}
		return $own;
	}

	/**
	 * The ids the call names, when each is one the product prices; null
	 * when it names none.
	 *
	 * @param mixed      $ids     The call's `variation_ids`.
	 * @param WC_Product $product The product.
	 * @return list<int>|WP_Error|null
	 */
	private static function named( mixed $ids, WC_Product $product ): array|WP_Error|null {
		if ( ! is_array( $ids ) ) {
			return null;
		}
		$named = array_values( array_unique( array_map( 'intval', $ids ) ) );
		$own   = array_map( static fn ( WC_Product $item ): int => $item->get_id(), ProductPricing::items( $product ) );
		$other = array_diff( $named, $own );
		if ( array() !== $other ) {
			/* translators: %s: one or more ids, e.g. 12, 15. */
			return new WP_Error( 'pixonaut_wc_ability_unknown_variation', sprintf( __( 'The product has no variation with this id: %s.', 'pixonaut-for-woocommerce' ), implode( ', ', $other ) ), array( 'status' => self::UNPROCESSABLE ) );
		}
		return $named;
	}

	/**
	 * The products or variations to price; null for all.
	 *
	 * @param array<int, int|null> $before   Each one's regular price before, by id.
	 * @param list<int>|null       $named    The ones the call names; null for none.
	 * @param bool                 $unpriced Whether only the ones without a price are priced.
	 * @return list<int>|null
	 */
	private static function chosen( array $before, ?array $named, bool $unpriced ): ?array {
		if ( ! $unpriced ) {
			return $named;
		}
		$without = array_keys( array_filter( $before, static fn ( ?int $price ): bool => null === $price ) );
		return null === $named ? $without : array_values( array_intersect( $named, $without ) );
	}

	/**
	 * The product as stored after a step changed it.
	 *
	 * @param WC_Product $product The product as read before.
	 */
	private static function reread( WC_Product $product ): WC_Product {
		$fresh = wc_get_product( $product->get_id() );
		return $fresh instanceof WC_Product ? $fresh : $product;
	}
}
