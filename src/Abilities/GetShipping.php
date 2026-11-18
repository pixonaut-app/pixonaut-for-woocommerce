<?php
/**
 * The ability that reads the store's shipping zones and their methods.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use WC_Shipping_Zone;
use WC_Shipping_Zones;

/**
 * Reads every shipping zone in the order WooCommerce matches a shopper's
 * address against them, then the zone for the locations no other zone
 * covers, each with its methods and their settings.
 */
final class GetShipping implements Ability {

	public const NAME = 'get-shipping';

	public function name(): string {
		return self::NAME;
	}

	public function definition(): array {
		return array(
			/* translators: Name of an ability an AI assistant or an MCP client can use on the store. */
			'label'               => __( 'Read the shipping zones and methods', 'pixonaut-for-woocommerce' ),
			'description'         => sprintf(
				/* translators: %s: the name of the ability that changes or adds a shipping method. */
				__( 'Gives every shipping zone in the order WooCommerce matches a shopper\'s address against them, then zone 0 for the locations no other zone covers: its locations and its methods, enabled or not. A flat rate or pickup gives its cost excluding tax, as WooCommerce keeps it, and including tax, as shoppers at the store\'s address pay it; free shipping gives its minimum order amount and what it requires. WooCommerce checks the minimum against the cart\'s subtotal as the cart shows it, after coupon discounts unless `ignore_discounts`. Change or add a method with %s.', 'pixonaut-for-woocommerce' ),
				Abilities::tool( SetShippingMethod::NAME )
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(),
				'additionalProperties' => false,
				'default'              => array(),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'currency'           => array(
						'type'        => 'string',
						'description' => __( 'The currency amounts are in, as its code.', 'pixonaut-for-woocommerce' ),
					),
					'prices_include_tax' => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the store\'s prices are entered including tax. A shipping cost is kept excluding tax either way.', 'pixonaut-for-woocommerce' ),
					),
					'zones'              => array(
						'type'  => 'array',
						'items' => ShippingMethods::zone_schema(),
					),
				),
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( ShippingMethods::class, 'can_manage' ),
			'annotations'         => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
		);
	}

	/**
	 * @return array{currency: string, prices_include_tax: bool, zones: list<array<string, mixed>>}
	 */
	public function execute(): array {
		$zones = array();
		foreach ( WC_Shipping_Zones::get_zones( 'admin' ) as $zone ) {
			$zones[] = ShippingMethods::zone( new WC_Shipping_Zone( (int) $zone['zone_id'] ) );
		}
		$zones[] = ShippingMethods::zone( new WC_Shipping_Zone( 0 ) );
		return array(
			'currency'           => get_woocommerce_currency(),
			'prices_include_tax' => wc_tax_enabled() && wc_prices_include_tax(),
			'zones'              => $zones,
		);
	}
}
