<?php
/**
 * A product's prices, as the pricing abilities answer them.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use Pixonaut\WooCommerce\Admin\PricingPanel;
use Pixonaut\WooCommerce\Api\VariantCosts;
use Pixonaut\WooCommerce\Pricing\Amount;
use Pixonaut\WooCommerce\Pricing\PriceChanges;
use Pixonaut\WooCommerce\Pricing\PriceRow;
use Pixonaut\WooCommerce\Pricing\PriceRule;
use Pixonaut\WooCommerce\Pricing\PriceState;
use Pixonaut\WooCommerce\Pricing\ProductPricing;
use Pixonaut\WooCommerce\Pricing\RuleSource;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use WC_Product;
use WP_Error;

/**
 * What the abilities that read and set a product's prices share: which
 * product they take, a price as they answer it, and what they say when
 * Pixonaut cannot give the printshop's costs. Money is a decimal string
 * with a point, in the store's currency unless a currency is named beside
 * it; a time is ISO 8601 in UTC.
 */
final class ProductPrices {

	/**
	 * The code a product without a blank is refused with.
	 */
	public const NOT_PRICED = 'pixonaut_wc_not_priced';

	/**
	 * The code pricing is refused with while no target margin is set.
	 */
	public const NO_RULE = 'pixonaut_wc_no_price_rule';

	private const NOT_FOUND = 404;

	private const CONFLICT = 409;

	private const UNPROCESSABLE = 422;

	private const BAD_GATEWAY = 502;

	private const TIME_FORMAT = 'Y-m-d\TH:i:s\Z';

	/**
	 * How many prices an answer lists unless it is asked for another number.
	 */
	public const PER_PAGE = 25;

	/**
	 * The most prices an answer lists.
	 */
	public const PER_PAGE_MAX = 100;

	/**
	 * The product named in the input, when it is one prices follow costs
	 * on: a simple or variable product personalised on a blank.
	 *
	 * @param mixed $input The ability's input, with `product_id`.
	 */
	public static function product( mixed $input ): WC_Product|WP_Error {
		$product = wc_get_product( Abilities::integer( $input, 'product_id', 0 ) );
		if ( ! $product instanceof WC_Product || ! ProductPricing::prices( $product ) ) {
			return new WP_Error( self::NOT_PRICED, __( 'No simple or variable product printed on a blank has this id, so there is no printshop cost to price from. A variation is priced through its product.', 'pixonaut-for-woocommerce' ), array( 'status' => self::NOT_FOUND ) );
		}
		return $product;
	}

	/**
	 * The refusal of pricing without a target margin.
	 */
	public static function no_rule(): WP_Error {
		return new WP_Error( self::NO_RULE, __( 'No target margin is set, for the store or for this product, so there is no rule to price by. Set one first.', 'pixonaut-for-woocommerce' ), array( 'status' => self::UNPROCESSABLE ) );
	}

	/**
	 * What to answer when Pixonaut could not give the printshop's costs.
	 *
	 * @param ApiException $e Why.
	 */
	public static function unread( ApiException $e ): WP_Error {
		if ( VariantCosts::UNKNOWN_REGION === $e->errorCode ) {
			/* translators: %s: a country code, such as SE. */
			return new WP_Error( 'pixonaut_wc_costs_no_region', sprintf( __( 'Pixonaut has no printshop costs for the shop\'s country (%s).', 'pixonaut-for-woocommerce' ), (string) WC()->countries->get_base_country() ), array( 'status' => self::CONFLICT ) );
		}
		return new WP_Error( 'pixonaut_wc_costs_unread', __( 'Pixonaut could not be reached to read the printshop\'s costs. Try again in a moment.', 'pixonaut-for-woocommerce' ), array( 'status' => self::BAD_GATEWAY ) );
	}

	/**
	 * One price, as the abilities answer it.
	 *
	 * @param PriceRow $row The price.
	 * @return array<string, mixed>
	 */
	public static function row( PriceRow $row ): array {
		$cost = $row->cost;
		return array(
			'id'              => $row->id,
			'label'           => $row->label,
			'price'           => self::money( $row->price ),
			'cost'            => null === $cost ? null : array(
				'product'           => self::money( $cost->product ),
				'currency'          => $cost->currency,
				'shipping'          => self::money( $cost->shipping ),
				'shipping_currency' => $cost->shipping_currency,
				'read_at'           => $cost->read_at,
				'pages'             => $cost->pages,
			),
			'cost_total'      => self::money( $row->cost_total ),
			'rate_date'       => '' === $row->rate_date ? null : $row->rate_date,
			'currency_buffer' => '' === $row->buffer ? null : $row->buffer,
			'margin'          => null === $row->margin ? null : round( $row->margin, 1 ),
			'contribution'    => self::money( $row->contribution ),
			'suggested'       => self::money( $row->suggested ),
			'standing'        => $row->standing,
			'flag'            => $row->flag,
			'flag_message'    => null === $row->flag ? null : ( PricingPanel::flag_texts()[ $row->flag ] ?? null ),
			'rule_at'         => self::time( $row->rule_at ),
			'checked_at'      => self::time( $row->checked_at ),
			'automatic'       => $row->automatic,
			'price_changes'   => $row->changes,
		) + self::rule_of( $row );
	}

	/**
	 * The rule a price follows, as the abilities answer it: each part in
	 * force, where it comes from, the parts the variation changes itself,
	 * and the parts two of its attribute values both set.
	 *
	 * @param PriceRow $row The price.
	 * @return array{rule: array<string, string>, rule_sources: array<string, array<string, mixed>|null>, own_rule: array<string, string>, rule_conflicts: list<array<string, mixed>>}
	 */
	private static function rule_of( PriceRow $row ): array {
		$rule    = $row->rule;
		$parts   = array();
		$sources = array();
		$own     = array();
		foreach ( PriceRule::PARTS as $part ) {
			$source           = $rule?->sources[ $part ] ?? null;
			$parts[ $part ]   = $rule?->parts[ $part ] ?? '';
			$sources[ $part ] = $source?->to_array();
			$own[ $part ]     = RuleSource::VARIATION === $source?->level ? $parts[ $part ] : '';
		}
		return array(
			'rule'           => $parts,
			'rule_sources'   => $sources,
			'own_rule'       => $own,
			'rule_conflicts' => null === $rule ? array() : array_map(
				static fn ( array $conflict ): array => array(
					'part'   => $conflict['part'],
					'winner' => $conflict['winner']->to_array(),
					'loser'  => $conflict['loser']->to_array(),
				),
				$rule->conflicts
			),
		);
	}

	/**
	 * The schema of a level the rule's parts come from.
	 *
	 * @return array<string, mixed>
	 */
	public static function source_schema(): array {
		$nullable = static fn ( string $type, string $description ): array => array(
			'type'        => array( $type, 'null' ),
			'description' => $description,
		);
		return array(
			'type'       => 'object',
			'properties' => array(
				'level'     => array(
					'type'        => 'string',
					'enum'        => RuleSource::LEVELS,
					'description' => __( 'variation: the variation\'s own rule. product: the product\'s. attribute: a value of a global attribute the variation has. store: the store\'s pricing settings.', 'pixonaut-for-woocommerce' ),
				),
				'attribute' => $nullable( 'string', __( 'The attribute\'s name, for an attribute value; else null.', 'pixonaut-for-woocommerce' ) ),
				'taxonomy'  => $nullable( 'string', __( 'The attribute\'s taxonomy, such as pa_size, for an attribute value; else null.', 'pixonaut-for-woocommerce' ) ),
				'term_id'   => $nullable( 'integer', __( 'The attribute value, by term id; else null.', 'pixonaut-for-woocommerce' ) ),
				'value'     => $nullable( 'string', __( 'The attribute value\'s name; else null.', 'pixonaut-for-woocommerce' ) ),
			),
		);
	}

	/**
	 * The schema of `row()`, with the properties an ability adds to it.
	 *
	 * @param array<string, array<string, mixed>> $more Properties an ability adds.
	 * @return array<string, mixed>
	 */
	public static function row_schema( array $more = array() ): array {
		$money = static fn ( string $description ): array => array(
			'type'        => array( 'string', 'null' ),
			'description' => $description,
		);
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'              => array(
					'type'        => 'integer',
					'description' => __( 'The variation, or the product itself when it has none.', 'pixonaut-for-woocommerce' ),
				),
				'label'           => array( 'type' => 'string' ),
				'price'           => $money( __( 'Its regular price, as the store enters prices, including tax or not; null for none.', 'pixonaut-for-woocommerce' ) ),
				'cost'            => array(
					'type'        => array( 'object', 'null' ),
					'description' => __( 'What the printshop charges, in its own currency, as last read; null when no cost is known for the blank variant it sells.', 'pixonaut-for-woocommerce' ),
					'properties'  => array(
						'product'           => $money( __( 'The product; null when the printshop publishes no price.', 'pixonaut-for-woocommerce' ) ),
						'currency'          => array( 'type' => 'string' ),
						'shipping'          => $money( __( 'The cheapest shipping to the shop\'s country; null when none is known.', 'pixonaut-for-woocommerce' ) ),
						'shipping_currency' => array( 'type' => 'string' ),
						'read_at'           => array( 'type' => array( 'string', 'null' ) ),
						'pages'             => array(
							'type'        => array( 'integer', 'null' ),
							'description' => __( 'The page count a book is priced at, inner pages only, by the attribute standing for its page count; null for anything else. A book at a count its printshop gives no price for has no product price, and cannot be bought.', 'pixonaut-for-woocommerce' ),
						),
					),
				),
				'cost_total'      => $money( __( 'The product and its shipping in the store\'s currency, converted with the currency buffer added when charged in another; null when they price nothing.', 'pixonaut-for-woocommerce' ) ),
				'rate_date'       => array(
					'type'        => array( 'string', 'null' ),
					'description' => __( 'The day of the exchange rate the cost was converted with; null when it was not converted.', 'pixonaut-for-woocommerce' ),
				),
				'currency_buffer' => array(
					'type'        => array( 'string', 'null' ),
					'description' => __( 'The currency buffer added to what was converted, in percent; null when nothing was.', 'pixonaut-for-woocommerce' ),
				),
				'margin'          => array(
					'type'        => array( 'number', 'null' ),
					'description' => __( 'The share of the price excluding tax left after the cost, in percent.', 'pixonaut-for-woocommerce' ),
				),
				'contribution'    => $money( __( 'What the price leaves over the cost, excluding tax, in the store\'s currency.', 'pixonaut-for-woocommerce' ) ),
				'suggested'       => $money( __( 'The price the rule would set, when it is another than the price it has.', 'pixonaut-for-woocommerce' ) ),
				'standing'        => array(
					'type'        => 'string',
					'enum'        => array( PriceState::OWN, PriceState::FOLLOWS, PriceState::BY_HAND ),
					'description' => __( 'own: the rule was never applied to it. follows: its price is the one the rule set. by_hand: its price was edited after the rule set it, and is held until it is given back to the rule.', 'pixonaut-for-woocommerce' ),
				),
				'flag'            => array(
					'type'        => array( 'string', 'null' ),
					'description' => __( 'Why the rule cannot price it or suggests another price: margin, rule (a level of the rule changed), no_cost, no_shipping (a price but no shipping to the store’s country), unread, currency, rate_stale, no_step or sale; null when nothing does.', 'pixonaut-for-woocommerce' ),
				),
				'flag_message'    => array( 'type' => array( 'string', 'null' ) ),
				'rule_at'         => array(
					'type'        => array( 'string', 'null' ),
					'description' => __( 'When the rule last set its price.', 'pixonaut-for-woocommerce' ),
				),
				'checked_at'      => array(
					'type'        => array( 'string', 'null' ),
					'description' => __( 'When its cost was last asked for; null when it never was.', 'pixonaut-for-woocommerce' ),
				),
				'automatic'       => array(
					'type'        => 'boolean',
					'description' => __( 'Whether its price, once it follows the rule, is set to the rule\'s by itself when its cost changes and its margin left the tolerance.', 'pixonaut-for-woocommerce' ),
				),
				'price_changes'   => array(
					'type'        => 'string',
					'enum'        => array( PriceChanges::STORE, PriceChanges::PRODUCT, PriceChanges::ALWAYS, PriceChanges::NEVER ),
					'description' => __( 'What it is set to: a variation says product when it follows its product, always or never when it answers it; a simple product\'s one row says what the product does.', 'pixonaut-for-woocommerce' ),
				),
				'rule'            => self::parts_schema( __( 'The rule this price follows, each part from the most specific level that sets it: the variation, its product, the values of its global attributes, the store; empty for a part no level sets.', 'pixonaut-for-woocommerce' ) ),
				'rule_sources'    => array(
					'type'                 => 'object',
					'description'          => __( 'Where each part of `rule` comes from, by part; null for a part no level sets.', 'pixonaut-for-woocommerce' ),
					'properties'           => array_fill_keys( PriceRule::PARTS, array( 'type' => array( 'object', 'null' ) ) + self::source_schema() ),
					'additionalProperties' => false,
				),
				'own_rule'        => self::parts_schema( __( 'The parts of the rule the variation changes itself; empty for a part it leaves to the levels after it, and always empty for a simple product.', 'pixonaut-for-woocommerce' ) ),
				'rule_conflicts'  => array(
					'type'        => 'array',
					'description' => __( 'Each part two of the variation\'s attribute values both set: the one whose attribute comes first in the store\'s attribute order wins.', 'pixonaut-for-woocommerce' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'part'   => array(
								'type' => 'string',
								'enum' => PriceRule::PARTS,
							),
							'winner' => self::source_schema(),
							'loser'  => self::source_schema(),
						),
					),
				),
			) + $more,
		);
	}

	/**
	 * The input that chooses which prices an answer lists.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function paging_schema(): array {
		return array(
			'page'     => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'default'     => 1,
				'description' => __( 'Which page of the prices the answer lists; it changes nothing about which are priced.', 'pixonaut-for-woocommerce' ),
			),
			'per_page' => array(
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => self::PER_PAGE_MAX,
				'default' => self::PER_PAGE,
			),
		);
	}

	/**
	 * One page of an answer's prices, with how many there are in all.
	 *
	 * @param list<array<string, mixed>> $prices Every price.
	 * @param mixed                      $input  The ability's input, with `page` and `per_page`.
	 * @return array{prices: list<array<string, mixed>>, total: int, page: int, pages: int}
	 */
	public static function page( array $prices, mixed $input ): array {
		$per_page = min( self::PER_PAGE_MAX, max( 1, Abilities::integer( $input, 'per_page', self::PER_PAGE ) ) );
		$page     = max( 1, Abilities::integer( $input, 'page', 1 ) );
		return array(
			'prices' => array_slice( $prices, ( $page - 1 ) * $per_page, $per_page ),
			'total'  => count( $prices ),
			'page'   => $page,
			'pages'  => (int) ceil( count( $prices ) / $per_page ),
		);
	}

	/**
	 * The schema of `page()`.
	 *
	 * @param array<string, array<string, mixed>> $more Properties an ability adds to each price.
	 * @return array<string, array<string, mixed>>
	 */
	public static function page_schema( array $more = array() ): array {
		return array(
			'prices' => array(
				'type'  => 'array',
				'items' => self::row_schema( $more ),
			),
			'total'  => array(
				'type'        => 'integer',
				'description' => __( 'How many prices the product has, on every page.', 'pixonaut-for-woocommerce' ),
			),
			'page'   => array( 'type' => 'integer' ),
			'pages'  => array( 'type' => 'integer' ),
		);
	}

	/**
	 * The schema of a rule's parts, each a decimal string, empty for none.
	 *
	 * @param string $description What the parts are of.
	 * @return array<string, mixed>
	 */
	public static function parts_schema( string $description ): array {
		return array(
			'type'                 => 'object',
			'description'          => $description,
			'properties'           => array_fill_keys(
				PriceRule::PARTS,
				array(
					'type'    => 'string',
					'pattern' => '^(\\d+([.,]\\d+)?)?$',
				)
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * An amount as the abilities answer it; null for none.
	 *
	 * @param int|null $amount Thousandths.
	 */
	public static function money( ?int $amount ): ?string {
		return null === $amount ? null : Amount::decimal( $amount );
	}

	/**
	 * @param int|null $time A Unix time.
	 */
	private static function time( ?int $time ): ?string {
		return null === $time ? null : gmdate( self::TIME_FORMAT, $time );
	}
}
