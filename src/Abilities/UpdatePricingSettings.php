<?php
/**
 * The ability that changes the store's pricing settings.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use Pixonaut\WooCommerce\Pricing\Amount;
use Pixonaut\WooCommerce\Pricing\AttributeRules;
use Pixonaut\WooCommerce\Pricing\PriceRule;
use Pixonaut\WooCommerce\Settings\Settings;
use WP_Error;
use WP_Term;

/**
 * Changes the pricing settings it is given, as saving the Prices tab of the
 * settings page does: the others keep their values, and an ending that is
 * not below the step is refused. It also sets the parts of the rule values
 * of global attributes change. A rule that changed settles the prices that
 * follow it in the background (`RuleChange`).
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
				__( 'Changes the pricing settings given, by their keys as %1$s reads them; the others keep their values. `price_automatic` switches automatic price changes on or off: on, a price that follows the rule is set to the rule\'s price when its cost changes and its margin left the tolerance; off, the new price is only suggested; a product or variation set to always or never keeps to that, whatever the switch says. `delivery_time_show` has a product page say how long the printshop says the chosen variation takes, as Pixonaut read it with the cost (only what the printshop tells: nothing is shown where it tells nothing), and `delivery_time_handling_days` adds days of the store\'s own to it. `price_margin_floor` sets the lowest margin the store accepts, in percent of a price excluding tax, empty for none: a price whose margin falls below it, whether it follows the rule, is pinned by a lowest and highest price or was set by hand, is listed on the products list, and a daily email to the store names each one once per drop. `price_ending` must be below `price_step`. `attribute_rules` sets the parts of the rule values of global attributes change for every variation with them, in every product, each entry replacing the value\'s parts (all empty to clear them); values not named keep theirs. Changing the store\'s rule or a value\'s settles the prices that follow the rule in the background: set to the rule\'s price where prices change automatically, else suggested. To set prices at once, price a product with %2$s. Answers with every pricing setting.', 'pixonaut-for-woocommerce' ),
				Abilities::tool( ReadPricingSettings::NAME ),
				Abilities::tool( PriceProduct::NAME )
			),
			'input_schema'        => self::input_schema( $this->settings ),
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
		$input  = is_array( $input ) ? $input : array();
		$values = self::values_of( $input['attribute_rules'] ?? array() );
		if ( $values instanceof WP_Error ) {
			return $values;
		}
		unset( $input['attribute_rules'] );
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
		$refused = self::rule_refusal( $this->settings, $input );
		if ( null !== $refused ) {
			return $refused;
		}
		if ( array() !== $input ) {
			$this->settings->set_many( $input );
		}
		foreach ( $values as $term_id => $parts ) {
			AttributeRules::save( $term_id, $parts );
		}
		return ReadPricingSettings::values( $this->settings );
	}

	/**
	 * The input: the pricing settings, and the attribute values' rules.
	 *
	 * @param Settings $settings The settings.
	 * @return array<string, mixed>
	 */
	private static function input_schema( Settings $settings ): array {
		$schema                                  = ReadPricingSettings::schema( $settings );
		$schema['properties']['attribute_rules'] = array(
			'type'        => 'array',
			'description' => __( 'Values of global attributes and the parts of the rule each changes, replacing the parts it has.', 'pixonaut-for-woocommerce' ),
			'items'       => array(
				'type'                 => 'object',
				'properties'           => array(
					'attribute_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'A global attribute, by id.', 'pixonaut-for-woocommerce' ),
					),
					'term_id'      => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'One of the attribute\'s values, by term id; or name it by `slug`.', 'pixonaut-for-woocommerce' ),
					),
					'slug'         => array( 'type' => 'string' ),
					'rule'         => ProductPrices::parts_schema( __( 'The parts the value changes: margin and tolerance in percent, fixed (added to the price excluding tax on top of the margin), step, ending, min and max as amounts. A part left out or empty follows the store.', 'pixonaut-for-woocommerce' ) ),
				),
				'required'             => array( 'attribute_id', 'rule' ),
				'additionalProperties' => false,
			),
		);
		return $schema + array(
			'minProperties'        => 1,
			'additionalProperties' => false,
		);
	}

	/**
	 * The parts the call gives attribute values, by term id, when each names
	 * a value of a global attribute and its parts are numbers.
	 *
	 * @param mixed $rules The call's `attribute_rules`.
	 * @return array<int, array<string, string>>|WP_Error
	 */
	private static function values_of( mixed $rules ): array|WP_Error {
		$values = array();
		foreach ( is_array( $rules ) ? $rules : array() as $entry ) {
			$entry     = is_array( $entry ) ? $entry : array();
			$attribute = wc_get_attribute( (int) ( $entry['attribute_id'] ?? 0 ) );
			$taxonomy  = null === $attribute ? '' : $attribute->slug;
			$term      = '' === $taxonomy ? null : ( isset( $entry['term_id'] ) ? get_term( (int) $entry['term_id'], $taxonomy ) : get_term_by( 'slug', (string) ( $entry['slug'] ?? '' ), $taxonomy ) );
			if ( ! $term instanceof WP_Term || $term->taxonomy !== $taxonomy ) {
				return new WP_Error( 'pixonaut_wc_ability_attribute_value_unknown', __( 'No value of a global attribute has this attribute id and term id or slug.', 'pixonaut-for-woocommerce' ), array( 'status' => self::UNPROCESSABLE ) );
			}
			$parts = array();
			foreach ( PriceRule::PARTS as $part ) {
				$written = is_array( $entry['rule'] ?? null ) ? ( $entry['rule'][ $part ] ?? '' ) : '';
				if ( ! PriceRule::is_part( $written ) ) {
					/* translators: %s: a part of the pricing rule, e.g. margin. */
					return new WP_Error( 'pixonaut_wc_ability_rule_part', sprintf( __( 'The rule\'s %s is not a number.', 'pixonaut-for-woocommerce' ), $part ), array( 'status' => self::UNPROCESSABLE ) );
				}
				$parts[ $part ] = PriceRule::part( $written );
			}
			$values[ $term->term_id ] = $parts;
		}
		return $values;
	}

	/**
	 * Why the pricing rule would not hold with the settings given beside
	 * the ones kept: prices must end below the step. Null when it holds.
	 * Whatever writes pricing settings asks this before it writes.
	 *
	 * @param Settings             $settings The settings as they are.
	 * @param array<string, mixed> $input    The settings to change, by key, each one its setting takes.
	 */
	public static function rule_refusal( Settings $settings, array $input ): ?WP_Error {
		$step   = Amount::parse( $input[ Settings::PRICE_STEP ] ?? $settings->get( Settings::PRICE_STEP ) );
		$ending = Amount::parse( $input[ Settings::PRICE_ENDING ] ?? $settings->get( Settings::PRICE_ENDING ) ) ?? 0;
		// A step of nothing counts as one unit, as the rule reads it.
		if ( $ending >= ( null === $step || 0 === $step ? Amount::PER_UNIT : $step ) ) {
			return new WP_Error( 'pixonaut_wc_ability_price_ending', __( 'Prices end in an amount below the price step: with a step of 10, anything from 0 up to 9.99.', 'pixonaut-for-woocommerce' ), array( 'status' => self::UNPROCESSABLE ) );
		}
		return null;
	}
}
