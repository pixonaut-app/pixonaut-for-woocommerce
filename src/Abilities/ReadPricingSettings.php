<?php
/**
 * The ability that reads the store's pricing settings.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use Pixonaut\WooCommerce\Settings\Setting;
use Pixonaut\WooCommerce\Settings\Settings;

/**
 * The settings on the Prices tab of the Pixonaut settings page, by their
 * keys: the store's pricing rule, the currency buffer, and whether prices
 * change by themselves; with the currency prices are in and whether they
 * are entered including tax, which the rule counts with. Whoever may change
 * the settings may read them.
 */
final class ReadPricingSettings implements Ability {

	public const NAME = 'get-pricing-settings';

	/**
	 * The pricing settings, in the order the Prices tab shows them.
	 */
	public const KEYS = array(
		Settings::PRICE_MARGIN,
		Settings::PRICE_TOLERANCE,
		Settings::PRICE_STEP,
		Settings::PRICE_ENDING,
		Settings::PRICE_MIN,
		Settings::PRICE_MAX,
		Settings::PRICE_CURRENCY_BUFFER,
		Settings::PRICE_AUTOMATIC,
	);

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
			'label'               => __( 'Read the pricing settings', 'pixonaut-for-woocommerce' ),
			'description'         => __( 'Gives the store\'s pricing rule, by which prices follow the printshop\'s costs: the target margin, the tolerance around it, the price step and what prices end in, the lowest and highest price, the currency buffer added to a cost converted from another currency, and whether prices change by themselves when a cost changes. Amounts and percentages are decimal strings, empty for none. Without a target margin the store has no rule. Also the store\'s currency, and whether prices are entered including tax.', 'pixonaut-for-woocommerce' ),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(),
				'additionalProperties' => false,
				'default'              => array(),
			),
			'output_schema'       => self::output_schema( $this->settings ),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( ReadPluginSettings::class, 'can_manage' ),
			'annotations'         => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function execute(): array {
		return self::values( $this->settings );
	}

	/**
	 * The pricing settings, by key.
	 *
	 * @param Settings $settings The settings.
	 * @return array<string, Setting>
	 */
	public static function settings( Settings $settings ): array {
		return array_intersect_key( $settings->all(), array_flip( self::KEYS ) );
	}

	/**
	 * Each pricing setting's value now, by its key, and what the rule
	 * counts with that is WooCommerce's own.
	 *
	 * @param Settings $settings The settings.
	 * @return array<string, mixed>
	 */
	public static function values( Settings $settings ): array {
		$values = array();
		foreach ( array_keys( self::settings( $settings ) ) as $key ) {
			$values[ $key ] = $settings->get( $key );
		}
		return $values + array(
			'currency'           => get_woocommerce_currency(),
			'prices_include_tax' => wc_tax_enabled() && wc_prices_include_tax(),
		);
	}

	/**
	 * The pricing settings as an object's schema, one property per setting.
	 *
	 * @param Settings $settings The settings.
	 * @return array{type: string, properties: array<string, array<string, mixed>>}
	 */
	public static function schema( Settings $settings ): array {
		return array(
			'type'       => 'object',
			'properties' => array_map( static fn ( Setting $setting ): array => $setting->schema(), self::settings( $settings ) ),
		);
	}

	/**
	 * What reading or changing the pricing settings answers.
	 *
	 * @param Settings $settings The settings.
	 * @return array<string, mixed>
	 */
	public static function output_schema( Settings $settings ): array {
		$schema                                     = self::schema( $settings );
		$schema['properties']['currency']           = array(
			'type'        => 'string',
			'description' => __( 'The currency prices are in, as its code.', 'pixonaut-for-woocommerce' ),
		);
		$schema['properties']['prices_include_tax'] = array(
			'type'        => 'boolean',
			'description' => __( 'Whether prices are entered including tax. The price step, ending, lowest and highest price count on the price as entered; the margin counts on it excluding tax.', 'pixonaut-for-woocommerce' ),
		);
		return $schema;
	}
}
