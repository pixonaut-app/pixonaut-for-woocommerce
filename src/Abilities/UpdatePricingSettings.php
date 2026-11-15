<?php
/**
 * The ability that changes the store's pricing settings.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use Pixonaut\WooCommerce\Pricing\Amount;
use Pixonaut\WooCommerce\Settings\Settings;
use WP_Error;

/**
 * Changes the pricing settings it is given, as saving the Prices tab of the
 * settings page does: the others keep their values, and an ending that is
 * not below the step is refused. No price changes by it: a rule that
 * changed suggests other prices when the costs are next read, or when a
 * product is priced.
 */
final class UpdatePricingSettings implements Ability {

	public const NAME = 'update-pricing-settings';

	private const UNPROCESSABLE = 422;

	/**
	 * @param Settings $settings The settings.
	 */
	public function __construct( private readonly Settings $settings ) {
	}

	public function name(): string {
		return self::NAME;
	}

	public function definition(): array {
		return array(
			/* translators: Name of an ability an AI assistant or an MCP client can use on the store. */
			'label'               => __( 'Change the pricing settings', 'pixonaut-for-woocommerce' ),
			'description'         => sprintf(
				/* translators: 1: the name of the ability that reads the pricing settings, 2: the name of the ability that prices a product. */
				__( 'Changes the pricing settings given, by their keys as %1$s reads them; the others keep their values. `price_automatic` switches automatic price changes on or off: on, a price that follows the rule is set to the rule\'s price when its cost changes and its margin left the tolerance; off, the new price is only suggested; a product or variation set to always or never keeps to that, whatever the switch says. `price_ending` must be below `price_step`. Changing the rule changes no price by itself: price a product with %2$s. Answers with every pricing setting.', 'pixonaut-for-woocommerce' ),
				Abilities::tool( ReadPricingSettings::NAME ),
				Abilities::tool( PriceProduct::NAME )
			),
			'input_schema'        => ReadPricingSettings::schema( $this->settings ) + array(
				'minProperties'        => 1,
				'additionalProperties' => false,
			),
			'output_schema'       => ReadPricingSettings::output_schema( $this->settings ),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( ReadPluginSettings::class, 'can_manage' ),
			'annotations'         => array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => true,
			),
		);
	}

	/**
	 * @param mixed $input The pricing settings to change, by key.
	 * @return array<string, mixed>|WP_Error Every pricing setting, or why nothing changed.
	 */
	public function execute( mixed $input = null ): array|WP_Error {
		$input    = is_array( $input ) ? $input : array();
		$settings = ReadPricingSettings::settings( $this->settings );
		foreach ( $input as $key => $value ) {
			if ( ! isset( $settings[ $key ] ) ) {
				/* translators: %s: the key of a setting, e.g. price_margin. */
				return new WP_Error( 'pixonaut_wc_ability_setting_unknown', sprintf( __( 'The pricing settings have no setting %s.', 'pixonaut-for-woocommerce' ), $key ), array( 'status' => self::UNPROCESSABLE ) );
			}
			if ( ! $settings[ $key ]->accepts( $value ) ) {
				/* translators: %s: the key of a setting, e.g. price_margin. */
				return new WP_Error( 'pixonaut_wc_ability_setting_invalid', sprintf( __( 'The setting %s does not take that value.', 'pixonaut-for-woocommerce' ), $key ), array( 'status' => self::UNPROCESSABLE ) );
			}
		}
		$step   = Amount::parse( $input[ Settings::PRICE_STEP ] ?? $this->settings->get( Settings::PRICE_STEP ) );
		$ending = Amount::parse( $input[ Settings::PRICE_ENDING ] ?? $this->settings->get( Settings::PRICE_ENDING ) ) ?? 0;
		// A step of nothing counts as one unit, as the rule reads it.
		if ( $ending >= ( null === $step || 0 === $step ? Amount::PER_UNIT : $step ) ) {
			return new WP_Error( 'pixonaut_wc_ability_price_ending', __( 'Prices end in an amount below the price step: with a step of 10, anything from 0 up to 9.99.', 'pixonaut-for-woocommerce' ), array( 'status' => self::UNPROCESSABLE ) );
		}
		$this->settings->set_many( $input );
		return ReadPricingSettings::values( $this->settings );
	}
}
