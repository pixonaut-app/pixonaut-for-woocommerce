<?php
/**
 * The ability that reads the store's pricing settings.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use Pixonaut\WooCommerce\Pricing\AttributeRules;
use Pixonaut\WooCommerce\Settings\Setting;
use Pixonaut\WooCommerce\Settings\Settings;
use WP_Term;

/**
 * The settings on the Prices tab of the Pixonaut settings page, by their
 * keys: the store's pricing rule, the currency buffer, whether prices
 * change by themselves, and whether product pages show the printshop's
 * delivery time; with the currency prices are in and whether they
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
		Settings::PRICE_FIXED,
		Settings::PRICE_TOLERANCE,
		Settings::PRICE_STEP,
		Settings::PRICE_ENDING,
		Settings::PRICE_MIN,
		Settings::PRICE_MAX,
		Settings::PRICE_CURRENCY_BUFFER,
		Settings::PRICE_AUTOMATIC,
		Settings::DELIVERY_TIME_SHOW,
		Settings::DELIVERY_TIME_HANDLING_DAYS,
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
			'description'         => __( 'Gives the store\'s pricing rule, by which prices follow the printshop\'s costs: the target margin and the fixed amount per item on top of it (price excluding tax = cost ÷ (1 − margin) + fixed amount), the tolerance around the margin the rule\'s own price leaves at a cost, the price step and what prices end in, the lowest and highest price, the currency buffer added to a cost converted from another currency, and whether prices change by themselves when a cost changes. Amounts and percentages are decimal strings, empty for none. Without a target margin the store has no rule. `attribute_rules` lists each value of a global attribute that changes parts of the rule for every variation with it, in every product, in the store\'s attribute order, with the parts another attribute\'s value sets too on a variation with both (`conflicts`: the one whose attribute comes first wins); a product and a variation may change parts too, and each part comes from the most specific level that sets it. Also the store\'s currency, and whether prices are entered including tax. `delivery_time_show` says whether a product page tells how long the printshop says the chosen variation takes, which Pixonaut reads with its cost, and `delivery_time_handling_days` the days of its own the store adds to that.', 'pixonaut-for-woocommerce' ),
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
			'attribute_rules'    => self::attribute_rules(),
		);
	}

	/**
	 * Every attribute value with parts of the rule, as the abilities answer them.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function attribute_rules(): array {
		$clashes = AttributeRules::all_clashes();
		return array_map( static fn ( WP_Term $term ): array => self::attribute_rule( $term, $clashes[ $term->term_id ] ?? array() ), AttributeRules::all() );
	}

	/**
	 * One attribute value's parts, as the abilities answer them.
	 *
	 * @param WP_Term $term    The value.
	 * @param array[] $clashes The parts another attribute's value sets too, as `AttributeRules::clashes()` gives them.
	 * @phpstan-param list<array{part: string, term: WP_Term, wins: bool}> $clashes
	 * @return array<string, mixed>
	 */
	public static function attribute_rule( WP_Term $term, array $clashes ): array {
		return array(
			'attribute_id' => wc_attribute_taxonomy_id_by_name( $term->taxonomy ),
			'attribute'    => wc_attribute_label( $term->taxonomy ),
			'taxonomy'     => $term->taxonomy,
			'term_id'      => $term->term_id,
			'slug'         => $term->slug,
			'name'         => $term->name,
			'rule'         => AttributeRules::parts( $term->term_id ),
			'conflicts'    => array_map(
				static fn ( array $clash ): array => array(
					'part'      => $clash['part'],
					'term_id'   => $clash['term']->term_id,
					'attribute' => wc_attribute_label( $clash['term']->taxonomy ),
					'name'      => $clash['term']->name,
					'wins'      => $clash['wins'],
				),
				$clashes
			),
		);
	}

	/**
	 * The schema of `attribute_rule()`.
	 *
	 * @return array<string, mixed>
	 */
	public static function attribute_rule_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'attribute_id' => array(
					'type'        => 'integer',
					'description' => __( 'The global attribute, by id.', 'pixonaut-for-woocommerce' ),
				),
				'attribute'    => array( 'type' => 'string' ),
				'taxonomy'     => array( 'type' => 'string' ),
				'term_id'      => array(
					'type'        => 'integer',
					'description' => __( 'The value, by term id.', 'pixonaut-for-woocommerce' ),
				),
				'slug'         => array( 'type' => 'string' ),
				'name'         => array( 'type' => 'string' ),
				'rule'         => ProductPrices::parts_schema( __( 'The parts the value changes for every variation with it; empty for a part it leaves to the store.', 'pixonaut-for-woocommerce' ) ),
				'conflicts'    => array(
					'type'        => 'array',
					'description' => __( 'Each part a value of another attribute sets too, on some variation with both.', 'pixonaut-for-woocommerce' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'part'      => array( 'type' => 'string' ),
							'term_id'   => array( 'type' => 'integer' ),
							'attribute' => array( 'type' => 'string' ),
							'name'      => array( 'type' => 'string' ),
							'wins'      => array(
								'type'        => 'boolean',
								'description' => __( 'Whether this value wins the part: its attribute comes first in the store\'s attribute order.', 'pixonaut-for-woocommerce' ),
							),
						),
					),
				),
			),
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
			'description' => __( 'Whether prices are entered including tax. The price step, ending, lowest and highest price count on the price as entered; the margin and the fixed amount count on it excluding tax.', 'pixonaut-for-woocommerce' ),
		);
		$schema['properties']['attribute_rules']    = array(
			'type'        => 'array',
			'description' => __( 'Each value of a global attribute that changes parts of the rule, in the store\'s attribute order.', 'pixonaut-for-woocommerce' ),
			'items'       => self::attribute_rule_schema(),
		);
		return $schema;
	}
}
