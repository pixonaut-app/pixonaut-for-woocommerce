<?php
/**
 * The ability that changes a shipping method in a zone, or adds one.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use Pixonaut\WooCommerce\Abilities\Store\ResourceAbility;
use WC_Cache_Helper;
use WC_Shipping_Method;
use WC_Shipping_Zones;
use WP_Error;
use WP_Http;
use WP_REST_Request;

/**
 * Changes a method's settings in a shipping zone, or adds a flat rate or
 * free shipping to one, through WooCommerce's own shipping zone route, so
 * WooCommerce checks and keeps them as its shipping screen does. Only the
 * settings given change.
 */
final class SetShippingMethod implements Ability {

	public const NAME = 'set-shipping-method';

	/**
	 * WooCommerce's route for a zone's methods.
	 */
	private const ROUTE = '/wc/v3/shipping/zones/%d/methods';

	private const ZONE_ID = 'zone_id';

	private const INSTANCE_ID = 'instance_id';

	private const KIND = 'kind';

	private const TITLE = 'title';

	private const ENABLED = 'enabled';

	private const COST = 'cost';

	private const COST_INCLUDES_TAX = 'cost_includes_tax';

	private const TAX_STATUS = 'tax_status';

	private const REQUIRES = 'requires';

	/**
	 * The method's place among the zone's, which WooCommerce keeps as `method_order`.
	 */
	private const ORDER = 'order';

	private const YES = 'yes';

	private const NO = 'no';

	/**
	 * The settings only a method that charges a cost has.
	 */
	private const COST_SETTINGS = array( self::COST, self::COST_INCLUDES_TAX, self::TAX_STATUS );

	/**
	 * The settings only free shipping has.
	 */
	private const FREE_SHIPPING_SETTINGS = array( ShippingMethods::MIN_AMOUNT, self::REQUIRES, ShippingMethods::IGNORE_DISCOUNTS );

	public function name(): string {
		return self::NAME;
	}

	public function definition(): array {
		return array(
			/* translators: Name of an ability an AI assistant or an MCP client can use on the store. */
			'label'               => __( 'Change or add a shipping method', 'pixonaut-for-woocommerce' ),
			'description'         => sprintf(
				/* translators: %s: the name of the ability that reads the shipping zones. */
				__( 'Changes a shipping method in a zone, by its `instance_id` as %s reads it, or without one adds a flat rate or free shipping (`kind`) to the zone. Only the settings given change. `cost` is a flat rate\'s or pickup\'s cost, kept excluding tax; with `cost_includes_tax` it is what shoppers at the store\'s address pay including the shipping tax, and the cost kept is counted from it. `min_amount` is free shipping\'s minimum order amount, which WooCommerce checks against the cart\'s subtotal as the cart shows it, after coupon discounts unless `ignore_discounts`; given a minimum, free shipping that required nothing comes to require it. `enabled` false takes a method away from checkout without deleting it. Answers the method as it is now.', 'pixonaut-for-woocommerce' ),
				Abilities::tool( GetShipping::NAME )
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					self::ZONE_ID                     => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => __( 'The zone; 0 is the zone for the locations no other zone covers.', 'pixonaut-for-woocommerce' ),
					),
					self::INSTANCE_ID                 => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The method to change; leave it out to add one.', 'pixonaut-for-woocommerce' ),
					),
					self::KIND                        => array(
						'type'        => 'string',
						'enum'        => ShippingMethods::ADDABLE,
						'description' => __( 'The kind of method to add.', 'pixonaut-for-woocommerce' ),
					),
					self::TITLE                       => array(
						'type'      => 'string',
						'minLength' => 1,
					),
					self::ENABLED                     => array( 'type' => 'boolean' ),
					self::COST                        => array(
						'type'    => 'string',
						'pattern' => ShippingMethods::AMOUNT_PATTERN,
					),
					self::COST_INCLUDES_TAX           => array(
						'type'    => 'boolean',
						'default' => false,
					),
					self::TAX_STATUS                  => array(
						'type' => 'string',
						'enum' => ShippingMethods::TAX_STATUSES,
					),
					ShippingMethods::MIN_AMOUNT       => array(
						'type'        => 'string',
						'pattern'     => '^$|' . ShippingMethods::AMOUNT_PATTERN,
						'description' => __( 'Empty for no minimum.', 'pixonaut-for-woocommerce' ),
					),
					self::REQUIRES                    => array(
						'type' => 'string',
						'enum' => ShippingMethods::REQUIRES,
					),
					ShippingMethods::IGNORE_DISCOUNTS => array( 'type' => 'boolean' ),
					self::ORDER                       => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => __( 'The method\'s place among the zone\'s methods, lowest first; the other methods keep theirs. A cart that starts qualifying for another rate gets the first one it can have, so free shipping that is to be chosen once a cart reaches its minimum goes before a flat rate.', 'pixonaut-for-woocommerce' ),
					),
				),
				'required'             => array( self::ZONE_ID ),
				'additionalProperties' => false,
			),
			'output_schema'       => ShippingMethods::method_schema(),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( ShippingMethods::class, 'can_manage' ),
			'annotations'         => array(
				'readonly'    => false,
				// What shoppers pay for shipping changes.
				'destructive' => true,
				'idempotent'  => false,
			),
		);
	}

	/**
	 * @param mixed $input The zone, the method or the kind to add, and the settings to change.
	 * @return array<string, mixed>|WP_Error The method as it is now, or why nothing changed.
	 */
	public function execute( mixed $input = null ): array|WP_Error {
		$input       = is_array( $input ) ? $input : array();
		$zone_id     = Abilities::integer( $input, self::ZONE_ID, -1 );
		$instance_id = Abilities::integer( $input, self::INSTANCE_ID, 0 );
		$found       = $instance_id > 0 ? WC_Shipping_Zones::get_shipping_method( $instance_id ) : null;
		$method      = $found instanceof WC_Shipping_Method ? $found : null;
		if ( $instance_id > 0 && null === $method ) {
			return self::refusal( 'pixonaut_wc_ability_unknown_shipping_method', __( 'No shipping method has this instance id.', 'pixonaut-for-woocommerce' ), WP_Http::NOT_FOUND );
		}
		$kind = $method instanceof WC_Shipping_Method ? (string) $method->id : ( is_string( $input[ self::KIND ] ?? null ) ? $input[ self::KIND ] : '' );
		if ( '' === $kind ) {
			return self::refusal( 'pixonaut_wc_ability_shipping_kind_missing', __( 'Give the method\'s instance_id to change it, or a kind to add one.', 'pixonaut-for-woocommerce' ), WP_Http::BAD_REQUEST );
		}
		if ( isset( $input[ self::KIND ] ) && $input[ self::KIND ] !== $kind ) {
			return self::refusal( 'pixonaut_wc_ability_shipping_kind_differs', __( 'A method\'s kind cannot change: add a method of the other kind instead.', 'pixonaut-for-woocommerce' ), WP_Http::UNPROCESSABLE_ENTITY );
		}
		$foreign = self::foreign_setting( $kind, $input );
		if ( null !== $foreign ) {
			/* translators: 1: a setting's key, e.g. min_amount; 2: a kind of shipping method, e.g. flat_rate. */
			return self::refusal( 'pixonaut_wc_ability_shipping_setting_foreign', sprintf( __( 'A %2$s method has no setting %1$s.', 'pixonaut-for-woocommerce' ), $foreign, $kind ), WP_Http::UNPROCESSABLE_ENTITY );
		}

		$request = $method instanceof WC_Shipping_Method
			? new WP_REST_Request( 'PUT', sprintf( self::ROUTE, $zone_id ) . '/' . $instance_id )
			: new WP_REST_Request( 'POST', sprintf( self::ROUTE, $zone_id ) );
		if ( ! $method instanceof WC_Shipping_Method ) {
			$request->set_param( 'method_id', $kind );
		}
		$settings = self::settings( $input, $method );
		if ( $settings instanceof WP_Error ) {
			return $settings;
		}
		$unkept = null === $method ? array() : array_diff( array_keys( $settings ), array_keys( $method->get_instance_form_fields() ) );
		if ( array() !== $unkept ) {
			// WooCommerce's route would skip a setting the method's form does not have and answer as if it were kept.
			/* translators: 1: a setting's key, e.g. title; 2: a kind of shipping method, e.g. flat_rate. */
			return self::refusal( 'pixonaut_wc_ability_shipping_setting_foreign', sprintf( __( 'A %2$s method has no setting %1$s.', 'pixonaut-for-woocommerce' ), reset( $unkept ), $kind ), WP_Http::UNPROCESSABLE_ENTITY );
		}
		if ( array() !== $settings ) {
			$request->set_param( 'settings', $settings );
		}
		if ( is_bool( $input[ self::ENABLED ] ?? null ) ) {
			$request->set_param( self::ENABLED, $input[ self::ENABLED ] );
		}
		if ( is_int( $input[ self::ORDER ] ?? null ) ) {
			$request->set_param( self::ORDER, $input[ self::ORDER ] );
		}
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return ResourceAbility::refusal( $response );
		}
		$data  = $response->get_data();
		$saved = is_array( $data ) && is_numeric( $data['instance_id'] ?? null ) ? WC_Shipping_Zones::get_shipping_method( (int) $data['instance_id'] ) : false;
		if ( ! $saved instanceof WC_Shipping_Method ) {
			return self::refusal( 'pixonaut_wc_ability_shipping_not_saved', __( 'The store did not keep the shipping method.', 'pixonaut-for-woocommerce' ), WP_Http::INTERNAL_SERVER_ERROR );
		}
		// Carts keep the rates they were quoted until the shipping version changes, as WooCommerce's shipping screen changes it.
		WC_Cache_Helper::get_transient_version( 'shipping', true );
		return ShippingMethods::method( $saved );
	}

	/**
	 * The first setting given that a method of this kind does not have, or null.
	 *
	 * @param string               $kind  The method's kind.
	 * @param array<string, mixed> $input The ability's input.
	 */
	private static function foreign_setting( string $kind, array $input ): ?string {
		$own = array_merge(
			in_array( $kind, ShippingMethods::CHARGING, true ) ? self::COST_SETTINGS : array(),
			ShippingMethods::FREE_SHIPPING === $kind ? self::FREE_SHIPPING_SETTINGS : array()
		);
		foreach ( array_merge( self::COST_SETTINGS, self::FREE_SHIPPING_SETTINGS ) as $setting ) {
			// `cost_includes_tax` false is the schema's default, given or not.
			$given = self::COST_INCLUDES_TAX === $setting ? true === ( $input[ $setting ] ?? false ) : array_key_exists( $setting, $input );
			if ( $given && ! in_array( $setting, $own, true ) ) {
				return $setting;
			}
		}
		return null;
	}

	/**
	 * The settings to change, as WooCommerce's route takes them.
	 *
	 * @param array<string, mixed>    $input  The ability's input, which its schema has checked.
	 * @param WC_Shipping_Method|null $method The method to change, null for a new one.
	 * @return array<string, string>|WP_Error The settings, or why a cost including tax cannot be counted.
	 */
	private static function settings( array $input, ?WC_Shipping_Method $method ): array|WP_Error {
		$settings = array();
		foreach ( array( self::TITLE, self::TAX_STATUS, ShippingMethods::MIN_AMOUNT, self::REQUIRES ) as $key ) {
			if ( is_string( $input[ $key ] ?? null ) ) {
				$settings[ $key ] = $input[ $key ];
			}
		}
		if ( is_bool( $input[ ShippingMethods::IGNORE_DISCOUNTS ] ?? null ) ) {
			$settings[ ShippingMethods::IGNORE_DISCOUNTS ] = $input[ ShippingMethods::IGNORE_DISCOUNTS ] ? self::YES : self::NO;
		}
		if ( is_string( $input[ self::COST ] ?? null ) ) {
			$tax_status = $settings[ self::TAX_STATUS ] ?? (string) ( null === $method ? ShippingMethods::TAXABLE : $method->get_instance_option( self::TAX_STATUS, ShippingMethods::TAXABLE ) );
			$cost       = true === ( $input[ self::COST_INCLUDES_TAX ] ?? false ) ? ShippingMethods::excluding_tax( $input[ self::COST ], $tax_status ) : $input[ self::COST ];
			if ( null === $cost ) {
				return self::refusal( 'pixonaut_wc_ability_shipping_tax_depends_on_cart', __( 'Shipping is taxed as the cart\'s items are, and the store\'s taxable products have different rates, so a cost including tax depends on the cart. Give the cost excluding tax, or set a shipping tax class in WooCommerce\'s tax settings.', 'pixonaut-for-woocommerce' ), WP_Http::UNPROCESSABLE_ENTITY );
			}
			$settings[ self::COST ] = $cost;
		}
		$minimum = $settings[ ShippingMethods::MIN_AMOUNT ] ?? '';
		if ( '' === $minimum || isset( $settings[ self::REQUIRES ] ) ) {
			return $settings;
		}
		// Only free shipping takes a minimum, which the caller checked.
		$current = null === $method ? '' : (string) $method->get_instance_option( self::REQUIRES );
		if ( '' === $current ) {
			// A minimum free shipping does not require would let every cart through.
			$settings[ self::REQUIRES ] = ShippingMethods::MIN_AMOUNT;
		}
		return $settings;
	}

	/**
	 * @param string $code    The error's code.
	 * @param string $message Why.
	 * @param int    $status  The HTTP status.
	 */
	private static function refusal( string $code, string $message, int $status ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
