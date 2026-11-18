<?php
/**
 * What the abilities on the store's shipping read and answer.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use WC_Shipping_Method;
use WC_Shipping_Zone;
use WC_Tax;

/**
 * A shipping zone and its methods, as `get-shipping` and
 * `set-shipping-method` give them: each method with the settings a merchant
 * sets on WooCommerce's shipping screen (a flat rate's or pickup's cost and
 * tax status, free shipping's minimum order amount and what it requires),
 * and a cost also as shoppers pay it, including tax.
 *
 * WooCommerce keeps a cost excluding tax and adds the shipping tax rates of
 * the shopper's address on top. A cost including tax is counted here with
 * the rates of the store's own address, where the store's own country's
 * shoppers pay the same.
 */
final class ShippingMethods {

	/**
	 * What WooCommerce's shipping screens ask of whoever changes shipping.
	 */
	public const CAPABILITY = 'manage_woocommerce';

	public const FLAT_RATE = 'flat_rate';

	public const FREE_SHIPPING = 'free_shipping';

	public const LOCAL_PICKUP = 'local_pickup';

	/**
	 * The kinds of method an ability adds to a zone.
	 */
	public const ADDABLE = array( self::FLAT_RATE, self::FREE_SHIPPING );

	/**
	 * The methods that charge a cost, which the settings `cost` and `tax_status` belong to.
	 */
	public const CHARGING = array( self::FLAT_RATE, self::LOCAL_PICKUP );

	public const TAXABLE = 'taxable';

	public const NOT_TAXED = 'none';

	public const TAX_STATUSES = array( self::TAXABLE, self::NOT_TAXED );

	/**
	 * What free shipping requires, as WooCommerce's setting says it: nothing,
	 * a free-shipping coupon, the minimum order amount, either or both.
	 */
	public const REQUIRES = array( '', 'coupon', self::MIN_AMOUNT, 'either', 'both' );

	public const MIN_AMOUNT = 'min_amount';

	/**
	 * What free shipping's minimum counts the subtotal with when the setting
	 * is on: the subtotal before coupon discounts.
	 */
	public const IGNORE_DISCOUNTS = 'ignore_discounts';

	/**
	 * A plain amount, as the abilities take a cost or a minimum: digits,
	 * with a point before the decimals.
	 */
	public const AMOUNT_PATTERN = '^[0-9]+(\.[0-9]+)?$';

	/**
	 * The shipping tax class that follows the cart's items, which is the
	 * standard rate for a cart of standard-rated items.
	 */
	private const INHERIT = 'inherit';

	private const YES = 'yes';

	/**
	 * A variation's tax class when it takes its product's.
	 */
	private const FROM_PRODUCT = 'parent';

	/**
	 * Whether the current user may change the store's shipping.
	 */
	public static function can_manage(): bool {
		return current_user_can( self::CAPABILITY );
	}

	/**
	 * One zone with its locations and every method in it, enabled or not.
	 *
	 * @param WC_Shipping_Zone $zone A zone; zone 0 is the one for the locations no other zone covers.
	 * @return array<string, mixed>
	 */
	public static function zone( WC_Shipping_Zone $zone ): array {
		$locations = array();
		foreach ( $zone->get_zone_locations() as $location ) {
			$locations[] = array(
				'code' => (string) $location->code,
				'type' => (string) $location->type,
			);
		}
		$methods = array();
		foreach ( $zone->get_shipping_methods( false, 'admin' ) as $method ) {
			$methods[] = self::method( $method );
		}
		return array(
			'zone_id'   => (int) $zone->get_id(),
			'name'      => $zone->get_zone_name(),
			'order'     => (int) $zone->get_zone_order(),
			'locations' => $locations,
			'methods'   => $methods,
		);
	}

	/**
	 * One method's row. A setting the method does not have is null.
	 *
	 * @param WC_Shipping_Method $method A method in a zone.
	 * @return array<string, mixed>
	 */
	public static function method( WC_Shipping_Method $method ): array {
		$charging   = in_array( $method->id, self::CHARGING, true );
		$free       = self::FREE_SHIPPING === $method->id;
		$cost       = $charging ? (string) $method->get_instance_option( 'cost' ) : null;
		$tax_status = $charging ? (string) $method->get_instance_option( 'tax_status', self::TAXABLE ) : null;
		return array(
			'instance_id'        => (int) $method->get_instance_id(),
			'kind'               => (string) $method->id,
			'title'              => (string) $method->get_title(),
			'enabled'            => $method->is_enabled(),
			'order'              => (int) $method->method_order,
			'cost'               => $cost,
			'cost_including_tax' => null === $cost ? null : self::including_tax( $cost, $tax_status ),
			'tax_status'         => $tax_status,
			'min_amount'         => $free ? (string) $method->get_instance_option( self::MIN_AMOUNT ) : null,
			'requires'           => $free ? (string) $method->get_instance_option( 'requires' ) : null,
			'ignore_discounts'   => $free ? self::YES === $method->get_instance_option( self::IGNORE_DISCOUNTS ) : null,
		);
	}

	/**
	 * A cost as WooCommerce keeps it, excluding tax, as shoppers at the
	 * store's address pay it. Null for a cost that is not a plain amount,
	 * such as one counted by quantity (`10 + [qty] * 2`).
	 *
	 * @param string      $cost       The cost as kept.
	 * @param string|null $tax_status The method's tax status.
	 */
	public static function including_tax( string $cost, ?string $tax_status ): ?string {
		if ( '' === $cost ) {
			return '';
		}
		if ( 1 !== preg_match( '/' . self::AMOUNT_PATTERN . '/', $cost ) ) {
			return null;
		}
		if ( ! self::taxed( $tax_status ) ) {
			return $cost;
		}
		$rates = self::rates();
		if ( null === $rates ) {
			return null;
		}
		$amount = (float) $cost;
		return wc_format_decimal( $amount + array_sum( WC_Tax::calc_exclusive_tax( $amount, $rates ) ), wc_get_price_decimals() );
	}

	/**
	 * A cost shoppers at the store's address pay, including tax, as
	 * WooCommerce keeps it, excluding tax.
	 *
	 * Null when shipping is taxed as the cart's items are and the store
	 * sells items whose rates differ, so the tax depends on the cart.
	 *
	 * @param string $cost       A plain amount, including tax.
	 * @param string $tax_status The method's tax status.
	 */
	public static function excluding_tax( string $cost, string $tax_status ): ?string {
		if ( ! self::taxed( $tax_status ) ) {
			return $cost;
		}
		$rates = self::rates();
		if ( null === $rates ) {
			return null;
		}
		$amount = (float) $cost;
		return wc_format_decimal( $amount - array_sum( WC_Tax::calc_inclusive_tax( $amount, $rates ) ), wc_get_price_decimals() );
	}

	/**
	 * The schema of one zone.
	 *
	 * @return array<string, mixed>
	 */
	public static function zone_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'zone_id'   => array(
					'type'        => 'integer',
					'description' => __( 'The zone; 0 is the zone for the locations no other zone covers.', 'pixonaut-for-woocommerce' ),
				),
				'name'      => array( 'type' => 'string' ),
				'order'     => array( 'type' => 'integer' ),
				'locations' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'code' => array( 'type' => 'string' ),
							'type' => array(
								'type'        => 'string',
								'description' => __( 'country, state, postcode or continent.', 'pixonaut-for-woocommerce' ),
							),
						),
					),
				),
				'methods'   => array(
					'type'  => 'array',
					'items' => self::method_schema(),
				),
			),
		);
	}

	/**
	 * The schema of one method's row.
	 *
	 * @return array<string, mixed>
	 */
	public static function method_schema(): array {
		$string_or_null = array( 'string', 'null' );
		return array(
			'type'       => 'object',
			'properties' => array(
				'instance_id'        => array( 'type' => 'integer' ),
				'kind'               => array(
					'type'        => 'string',
					'description' => __( 'flat_rate, free_shipping, local_pickup, or another plugin\'s method.', 'pixonaut-for-woocommerce' ),
				),
				'title'              => array(
					'type'        => 'string',
					'description' => __( 'What shoppers see the method as at checkout.', 'pixonaut-for-woocommerce' ),
				),
				'enabled'            => array( 'type' => 'boolean' ),
				'order'              => array( 'type' => 'integer' ),
				'cost'               => array(
					'type'        => $string_or_null,
					'description' => __( 'A flat rate\'s or pickup\'s cost as WooCommerce keeps it, excluding tax; it may be a formula such as `10 + [qty] * 2`. Empty for nothing, null for a method without a cost.', 'pixonaut-for-woocommerce' ),
				),
				'cost_including_tax' => array(
					'type'        => $string_or_null,
					'description' => __( 'The cost as shoppers at the store\'s address pay it, with the shipping tax when the method is taxable. Null for a formula, a method without a cost, or when shipping is taxed as the cart\'s items are and the store\'s taxable products have different rates, so the tax depends on the cart.', 'pixonaut-for-woocommerce' ),
				),
				'tax_status'         => array(
					'type'        => $string_or_null,
					'description' => __( 'taxable or none; null for a method without a cost.', 'pixonaut-for-woocommerce' ),
				),
				'min_amount'         => array(
					'type'        => $string_or_null,
					'description' => __( 'Free shipping\'s minimum order amount, empty for none; null for another method.', 'pixonaut-for-woocommerce' ),
				),
				'requires'           => array(
					'type'        => $string_or_null,
					'description' => __( 'What free shipping requires: empty for nothing, coupon, min_amount, either (a coupon or the minimum) or both; null for another method.', 'pixonaut-for-woocommerce' ),
				),
				'ignore_discounts'   => array(
					'type'        => array( 'boolean', 'null' ),
					'description' => __( 'Whether free shipping\'s minimum counts the subtotal before coupon discounts; null for another method.', 'pixonaut-for-woocommerce' ),
				),
			),
		);
	}

	/**
	 * Whether WooCommerce adds tax to a method with this tax status.
	 *
	 * @param string|null $tax_status The method's tax status.
	 */
	private static function taxed( ?string $tax_status ): bool {
		return wc_tax_enabled() && self::TAXABLE === $tax_status;
	}

	/**
	 * The shipping tax rates at the store's address. A shipping tax class
	 * set on the store gives its rates; one that follows the cart's items
	 * gives the rates every taxable product's class has there, and null when
	 * they differ, as when some products are reduced rate.
	 *
	 * @return array<int, array<string, mixed>>|null
	 */
	private static function rates(): ?array {
		$tax_class = (string) get_option( 'woocommerce_shipping_tax_class' );
		if ( self::INHERIT !== $tax_class ) {
			return self::rates_of( $tax_class );
		}
		$rates = null;
		foreach ( self::product_tax_classes() as $class ) {
			$of_class = self::rates_of( $class );
			if ( null !== $rates && self::shape( $rates ) !== self::shape( $of_class ) ) {
				return null;
			}
			$rates = $of_class;
		}
		// With no taxable product, the standard rate is the one a cart would meet first.
		return $rates ?? self::rates_of( '' );
	}

	/**
	 * The shipping rates of one tax class at the store's address.
	 *
	 * @param string $tax_class A tax class's slug, empty for standard.
	 * @return array<int, array<string, mixed>>
	 */
	private static function rates_of( string $tax_class ): array {
		$countries = WC()->countries;
		return WC_Tax::find_shipping_rates(
			array(
				'country'   => $countries->get_base_country(),
				'state'     => $countries->get_base_state(),
				'postcode'  => $countries->get_base_postcode(),
				'city'      => $countries->get_base_city(),
				'tax_class' => $tax_class,
			)
		);
	}

	/**
	 * What a set of rates charges, whatever their ids and names.
	 *
	 * @param array<int, array<string, mixed>> $rates Tax rates.
	 * @return list<string>
	 */
	private static function shape( array $rates ): array {
		$shape = array();
		foreach ( $rates as $rate ) {
			$shape[] = wc_format_decimal( $rate['rate'] ?? 0 ) . ( self::YES === ( $rate['compound'] ?? '' ) ? '+' : '' );
		}
		sort( $shape );
		return $shape;
	}

	/**
	 * The tax classes of the store's taxable products and variations, empty
	 * for standard; a variation that takes its product's class is counted as
	 * its product.
	 *
	 * @return list<string>
	 */
	private static function product_tax_classes(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one read for an ability's answer.
		$classes = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT COALESCE( class.meta_value, '' ) FROM {$wpdb->posts} AS p
				LEFT JOIN {$wpdb->postmeta} AS class ON class.post_id = p.ID AND class.meta_key = '_tax_class'
				LEFT JOIN {$wpdb->postmeta} AS status ON status.post_id = p.ID AND status.meta_key = '_tax_status'
				WHERE p.post_type IN ( 'product', 'product_variation' ) AND p.post_status NOT IN ( 'trash', 'auto-draft' )
				AND COALESCE( status.meta_value, %s ) <> %s",
				self::TAXABLE,
				self::NOT_TAXED
			)
		);
		return array_values( array_diff( array_map( 'strval', $classes ), array( self::FROM_PRODUCT ) ) );
	}
}
