<?php
/**
 * Prices, in a product's Pixonaut tab.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Admin;

use Closure;
use Pixonaut\WooCommerce\Api\VariantCosts;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Pricing\Amount;
use Pixonaut\WooCommerce\Pricing\ItemRule;
use Pixonaut\WooCommerce\Pricing\PriceChanges;
use Pixonaut\WooCommerce\Pricing\PriceRule;
use Pixonaut\WooCommerce\Pricing\PriceRow;
use Pixonaut\WooCommerce\Pricing\PriceState;
use Pixonaut\WooCommerce\Pricing\ProductPricing;
use Pixonaut\WooCommerce\Pricing\RuleSource;
use Pixonaut\WooCommerce\Settings\SettingsPage;
use Pixonaut\WooCommerce\Templates;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use WC_Product;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * **Prices**, a section of the Pixonaut tab of a product personalised on a
 * blank: each variation (a simple product has one row) with what the
 * printshop charges for it, its price, its margin and its contribution, the parts of the
 * store's pricing rule the product changes, whether its prices change by
 * themselves, and the buttons that read the
 * costs again, apply the rule, apply one suggested price, and give a price
 * edited by hand back to the rule.
 *
 * The buttons post to `ROUTE`, which answers the section drawn again. The
 * rule's parts and the choice for automatic price changes are saved with
 * the product, and with Apply.
 */
final class PricingPanel {

	/**
	 * Acts on a product's prices.
	 */
	public const ROUTE = '/products/(?P<product>\d+)/prices';

	/**
	 * Reads the costs from Pixonaut again.
	 */
	public const REFRESH = 'refresh';

	/**
	 * Sets every price to the rule's, or the ones named.
	 */
	public const APPLY = 'apply';

	/**
	 * Gives the prices named, edited by hand, back to the rule.
	 */
	public const FOLLOW = 'follow';

	public const ACTIONS = array( self::REFRESH, self::APPLY, self::FOLLOW );

	/**
	 * The posted field holding the parts of the rule the product changes.
	 */
	public const FIELD = 'pixonaut_price_rule';

	/**
	 * The posted field holding whether the product's prices change by
	 * themselves: one of `PriceChanges::OF_PRODUCT`.
	 */
	public const CHANGES_FIELD = 'pixonaut_price_automatic';

	/**
	 * The disclosure's name among the tab's open ones.
	 */
	public const DISCLOSURE = 'prices';

	/**
	 * After WooCommerce saved the product's own fields, its price among them.
	 */
	private const SAVE_PRIORITY = 60;

	/**
	 * The status of an action on a product without a blank.
	 */
	private const NOT_FOUND = 404;

	/**
	 * The status of an action Pixonaut has no costs for.
	 */
	private const CONFLICT = 409;

	/**
	 * The status of an action Pixonaut could not be asked for.
	 */
	private const BAD_GATEWAY = 502;

	/**
	 * @param ProductPricing $pricing   Reads the costs and prices by the rule.
	 * @param Templates      $templates Renders the section.
	 * @param Closure        $connected `fn(): bool`, whether the store can ask Pixonaut.
	 * @phpstan-param Closure(): bool $connected
	 */
	public function __construct(
		private readonly ProductPricing $pricing,
		private readonly Templates $templates,
		private readonly Closure $connected,
	) {
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save' ), self::SAVE_PRIORITY );
	}

	public function register_routes(): void {
		register_rest_route(
			Plugin::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'act' ),
				'permission_callback' => static fn ( WP_REST_Request $request ): bool => current_user_can( 'edit_post', (int) $request['product'] ),
				'args'                => array(
					'action'  => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => self::ACTIONS,
					),
					'ids'     => array(
						'type'  => 'array',
						'items' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
					'rule'    => array(
						'type'                 => 'object',
						'additionalProperties' => array( 'type' => 'string' ),
					),
					'changes' => array(
						'type' => 'string',
						'enum' => PriceChanges::OF_PRODUCT,
					),
				),
			)
		);
	}

	/**
	 * POST ROUTE: reads the costs again (`refresh`), sets every price or the
	 * ones in `ids` to the rule's (`apply`, which first stores the parts of
	 * the rule sent as `rule` and the choice for automatic price changes sent
	 * as `changes`), or gives the prices in `ids` back to the
	 * rule (`follow`). Answers `{html, message, changed, prices}`: the section
	 * drawn again, what to say happened, how many prices changed, and each regular price by product or
	 * variation id, as the product form shows it.
	 *
	 * @param WP_REST_Request $request The request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function act( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$product = wc_get_product( (int) $request['product'] );
		if ( ! $product instanceof WC_Product || ! ProductPricing::prices( $product ) ) {
			return new WP_Error( 'pixonaut_wc_not_priced', __( 'This product is not printed on a blank, so it has no printshop cost to price from.', 'pixonaut-for-woocommerce' ), array( 'status' => self::NOT_FOUND ) );
		}
		$ids     = is_array( $request['ids'] ) ? array_values( array_map( 'intval', $request['ids'] ) ) : null;
		$changed = 0;
		$message = '';
		try {
			switch ( $request['action'] ) {
				case self::REFRESH:
					// With automatic price changes on for the product, reading the costs may set prices.
					$changed = $this->pricing->refresh( $product );
					$message = 0 === $changed ? __( 'The costs are read.', 'pixonaut-for-woocommerce' ) : '';
					break;
				case self::APPLY:
					// Every price is set from what the printshop charges now. Read first: a read that fails stores no rule and no choice.
					$moved = null === $ids ? $this->pricing->read_costs( $product ) : null;
					$ruled = is_array( $request['rule'] ) && $this->pricing->save_own_parts( self::reread( $product ), $request['rule'] );
					if ( is_string( $request['changes'] ) ) {
						PriceChanges::save( self::reread( $product ), $request['changes'] );
					}
					if ( null !== $moved ) {
						$changed = $this->pricing->settle( self::reread( $product ), $moved );
					}
					$changed += $this->pricing->apply( self::reread( $product ), $ids );
					if ( $ruled && null !== $ids ) {
						// The prices the rule now has others for, and that were not chosen, are flagged with them.
						$others = array_diff( array_map( static fn ( WC_Product $item ): int => $item->get_id(), ProductPricing::items( $product ) ), $ids );
						$this->pricing->reprice( self::reread( $product ), array_values( $others ), true );
					}
					break;
				default:
					$changed = $this->pricing->apply( $product, $ids ?? array(), true );
			}
		} catch ( ApiException $e ) {
			if ( VariantCosts::UNKNOWN_REGION === $e->errorCode ) {
				/* translators: %s: a country code, such as SE. Said in the Prices section of the product edit page. */
				return new WP_Error( 'pixonaut_wc_costs_no_region', sprintf( __( 'Pixonaut has no printshop costs for your shop’s country (%s).', 'pixonaut-for-woocommerce' ), (string) WC()->countries->get_base_country() ), array( 'status' => self::CONFLICT ) );
			}
			return new WP_Error( 'pixonaut_wc_costs_unread', __( 'Pixonaut could not be reached to read the printshop’s costs. Try again in a moment.', 'pixonaut-for-woocommerce' ), array( 'status' => self::BAD_GATEWAY ) );
		}
		$fresh  = self::reread( $product );
		$prices = array();
		$read   = 0;
		foreach ( $this->pricing->rows( $fresh ) as $row ) {
			$prices[ $row->id ] = null === $row->price ? '' : wc_format_localized_price( Amount::decimal( $row->price ) );
			$read              += null === $row->cost ? 0 : 1;
		}
		if ( 0 === $read ) {
			// Pixonaut reads a country's costs in the background the first time it is asked.
			$message = __( 'Pixonaut is reading the printshop’s costs. Refresh the costs in a moment.', 'pixonaut-for-woocommerce' );
		}
		return new WP_REST_Response(
			array(
				'html'    => $this->html( $fresh, true ),
				'message' => '' !== $message ? $message : ( 0 === $changed
					? __( 'No price changed.', 'pixonaut-for-woocommerce' )
					/* translators: %d: how many prices. Said in the Prices section of the product edit page after the pricing rule set prices. */
					: sprintf( _n( '%d price set.', '%d prices set.', $changed, 'pixonaut-for-woocommerce' ), $changed ) ),
				'changed' => $changed,
				'prices'  => $prices,
			)
		);
	}

	/**
	 * The product as stored after an action changed it.
	 *
	 * @param WC_Product $product The product as read before.
	 */
	private static function reread( WC_Product $product ): WC_Product {
		$fresh = wc_get_product( $product->get_id() );
		return $fresh instanceof WC_Product ? $fresh : $product;
	}

	/**
	 * Stores the parts of the rule the product changes and its choice for
	 * automatic price changes, as its form posted them, and settles its prices: one edited in the form is kept by hand.
	 *
	 * @param int $product_id The product saved.
	 */
	public function save( int $product_id ): void {
		// The panel's nonce shows the section was on the form.
		if ( ! isset( $_POST[ ProductPanel::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ ProductPanel::NONCE_FIELD ] ) ), ProductPanel::NONCE_ACTION ) ) {
			return;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product || ! ProductPricing::prices( $product ) ) {
			return;
		}
		$posted  = isset( $_POST[ self::FIELD ] ) ? wp_unslash( $_POST[ self::FIELD ] ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each part is checked as a number.
		$ruled   = is_array( $posted ) && $this->pricing->save_own_parts( $product, array_filter( $posted, 'is_string' ) );
		$changes = isset( $_POST[ self::CHANGES_FIELD ] ) ? sanitize_key( wp_unslash( $_POST[ self::CHANGES_FIELD ] ) ) : '';
		if ( PriceChanges::allows( $product, $changes ) ) {
			PriceChanges::save( self::reread( $product ), $changes );
		}
		// A price changed in the form is one edited by hand; a rule changed sets or suggests the prices that follow it.
		if ( '' !== $product->get_meta( ProductPricing::WATCH_META_KEY, true ) ) {
			if ( $ruled ) {
				$this->pricing->reprice( self::reread( $product ) );
			} else {
				$this->pricing->settle( $product );
			}
		}
	}

	/**
	 * The section, as `admin/product-prices.php` prints it; empty for a
	 * product without a blank, or while the store is not connected.
	 *
	 * @param WC_Product $product The product.
	 * @param bool       $open    Whether the section is open.
	 */
	public function html( WC_Product $product, bool $open ): string {
		if ( ! ProductPricing::prices( $product ) || ! ( $this->connected )() ) {
			return '';
		}
		$rows = $this->pricing->rows( $product );
		return $this->templates->html(
			'admin/product-prices.php',
			array(
				'url'             => rest_url( Plugin::REST_NAMESPACE . '/products/' . $product->get_id() . '/prices' ),
				'field'           => self::FIELD,
				'changes_field'   => self::CHANGES_FIELD,
				'changes'         => PriceChanges::setting( $product ),
				'changes_options' => self::changes_options( $this->pricing->store_automatic() ),
				'open'            => $open,
				'rule_set'        => $this->pricing->rule_set_for( $product ),
				'own_parts'       => ProductPricing::own_parts( $product ),
				'store'           => $this->pricing->store_parts(),
				'rows'            => array_map( self::row( ... ), $rows ),
				'unread'          => array() !== $rows && array() === array_filter( $rows, static fn ( PriceRow $row ): bool => null !== $row->checked_at ),
				'flagged'         => count( array_filter( $rows, static fn ( PriceRow $row ): bool => PriceState::FOLLOWS === $row->standing && null !== $row->flag ) ),
				'following'       => count( array_filter( $rows, static fn ( PriceRow $row ): bool => PriceState::OWN !== $row->standing ) ),
				'variable'        => $product->is_type( 'variable' ),
				'settings'        => SettingsPage::url( SettingsPage::PRICES ),
				'tax_note'        => wc_tax_enabled() && wc_prices_include_tax(),
				'parts'           => self::part_labels(),
				'flag_texts'      => self::flag_texts(),
			)
		);
	}

	/**
	 * What each flag says to the store manager, by flag.
	 *
	 * @return array<string, string>
	 */
	public static function flag_texts(): array {
		return array(
			PriceState::FLAG_MARGIN     => __( 'The margin left the tolerance.', 'pixonaut-for-woocommerce' ),
			PriceState::FLAG_NO_COST    => __( 'The printshop publishes no price for it, so the rule cannot price it.', 'pixonaut-for-woocommerce' ),
			PriceState::FLAG_UNREAD     => __( 'No cost is read for it yet. Refresh the costs in a moment; if it stays so, pair it with a blank variant.', 'pixonaut-for-woocommerce' ),
			PriceState::FLAG_CURRENCY   => __( 'The cost is in a currency with no exchange rate to your store’s, so the rule cannot price it.', 'pixonaut-for-woocommerce' ),
			PriceState::FLAG_RATE_STALE => __( 'The exchange rate is over a week old and could not be updated, so the rule does not price it.', 'pixonaut-for-woocommerce' ),
			PriceState::FLAG_NO_STEP    => __( 'No price step lies between the lowest and the highest price.', 'pixonaut-for-woocommerce' ),
			PriceState::FLAG_SALE       => __( 'The rule’s price is not above the sale price. Change or remove the sale price first.', 'pixonaut-for-woocommerce' ),
			PriceState::FLAG_RULE       => __( 'The pricing rule changed.', 'pixonaut-for-woocommerce' ),
		);
	}

	/**
	 * What a product's automatic price changes can be set to, each with
	 * what the store manager reads, by value.
	 *
	 * @param bool $store Whether the store changes prices by itself.
	 * @return array<string, string>
	 */
	private static function changes_options( bool $store ): array {
		return array(
			PriceChanges::STORE  => $store
				/* translators: Option of the Automatic price changes field in the Prices section of the product edit page: the product does as the store's setting says, which is on. */
				? __( 'As the store: on', 'pixonaut-for-woocommerce' )
				/* translators: Option of the Automatic price changes field in the Prices section of the product edit page: the product does as the store's setting says, which is off. */
				: __( 'As the store: off', 'pixonaut-for-woocommerce' ),
			/* translators: Option of the Automatic price changes field in the Prices section of the product edit page: this product's prices change by themselves, whatever the store's setting says. */
			PriceChanges::ALWAYS => _x( 'Always', 'automatic price changes', 'pixonaut-for-woocommerce' ),
			/* translators: Option of the Automatic price changes field in the Prices section of the product edit page: this product's prices never change by themselves, whatever the store's setting says. */
			PriceChanges::NEVER  => _x( 'Never', 'automatic price changes', 'pixonaut-for-woocommerce' ),
		);
	}

	/**
	 * The label of each part of the rule, by part.
	 *
	 * @return array<string, string>
	 */
	public static function part_labels(): array {
		return array(
			/* translators: Label of a field in the Prices section of the product edit page; the value is a percentage. */
			PriceRule::MARGIN    => __( 'Target margin (%)', 'pixonaut-for-woocommerce' ),
			/* translators: Label of a field in the Prices section of the product edit page; the value is an amount of money excluding VAT added to the price on top of the target margin. */
			PriceRule::FIXED     => __( 'Fixed amount per item', 'pixonaut-for-woocommerce' ),
			/* translators: Label of a field in the Prices section of the product edit page; the value is a number of percentage points either side of the target margin. */
			PriceRule::TOLERANCE => __( 'Tolerance (points)', 'pixonaut-for-woocommerce' ),
			/* translators: Label of a field in the Prices section of the product edit page; the value is an amount of money: the distance between two prices the rule may set. */
			PriceRule::STEP      => __( 'Price step', 'pixonaut-for-woocommerce' ),
			/* translators: Label of a field in the Prices section of the product edit page; the value is an amount of money below the price step. */
			PriceRule::ENDING    => __( 'Prices end in', 'pixonaut-for-woocommerce' ),
			/* translators: Label of a field in the Prices section of the product edit page; the value is an amount of money. */
			PriceRule::MIN       => __( 'Lowest price', 'pixonaut-for-woocommerce' ),
			/* translators: Label of a field in the Prices section of the product edit page; the value is an amount of money. */
			PriceRule::MAX       => __( 'Highest price', 'pixonaut-for-woocommerce' ),
		);
	}

	/**
	 * One row as the template prints it: its money formatted as the store
	 * shows prices.
	 *
	 * @param PriceRow $row The row.
	 * @return array{id: int, label: string, cost: string, cost_parts: string, price: string, margin: string, contribution: string, suggested: string, standing: string, flag: string|null, rule_at: string, changes: string, source: string, source_parts: string, conflicts: list<string>}
	 */
	public static function row( PriceRow $row ): array {
		$cost  = $row->cost;
		$parts = '';
		if ( null !== $cost && null !== $cost->product && null !== $cost->shipping ) {
			$parts = sprintf(
				/* translators: 1: what the printshop charges for the product, 2: what it charges for shipping; both amounts of money. Under a cost in the Prices table of the product edit page. */
				__( '%1$s + %2$s shipping', 'pixonaut-for-woocommerce' ),
				self::money( $cost->product, $cost->currency ),
				self::money( $cost->shipping, $cost->shipping_currency )
			);
		} elseif ( null !== $cost && null !== $cost->product && '' !== $row->rate_date ) {
			$parts = self::money( $cost->product, $cost->currency );
		}
		if ( '' !== $row->rate_date ) {
			$parts = sprintf(
				/* translators: 1: a cost as the printshop charges it, in its own currency, such as "€16.71 + €3.00 shipping"; 2: the day of the exchange rate, such as 2026-11-14; 3: the currency buffer in percent, such as 3. Under a converted cost in the Prices table of the product edit page. */
				__( '%1$s (rate %2$s, +%3$s %%)', 'pixonaut-for-woocommerce' ),
				$parts,
				$row->rate_date,
				$row->buffer
			);
		}
		return array(
			'id'           => $row->id,
			'label'        => $row->label,
			'cost'         => match ( true ) {
				null === $row->cost_total && null !== $cost && null !== $cost->product => '' === $parts ? self::money( $cost->product, $cost->currency ) : $parts,
				null === $row->cost_total => '',
				/* translators: %s: a cost converted to the store's currency, such as 192 kr. In the Prices table of the product edit page: the amount is approximate. */
				'' !== $row->rate_date => sprintf( __( '≈ %s', 'pixonaut-for-woocommerce' ), self::money( $row->cost_total ) ),
				default => self::money( $row->cost_total ),
			},
			'cost_parts'   => null === $row->cost_total ? '' : $parts,
			'price'        => null === $row->price ? '' : self::money( $row->price ),
			'margin'       => null === $row->margin ? '' : self::percent( $row->margin ),
			'contribution' => null === $row->contribution ? '' : self::money( $row->contribution ),
			'suggested'    => null === $row->suggested ? '' : self::money( $row->suggested ),
			'standing'     => $row->standing,
			'flag'         => $row->flag,
			'rule_at'      => null === $row->rule_at ? '' : (string) wp_date( (string) get_option( 'date_format' ), $row->rule_at ),
			'changes'      => $row->changes,
			'source'       => null === $row->rule ? '' : self::sources_text( $row->rule ),
			'source_parts' => null === $row->rule ? '' : self::source_parts_text( $row->rule ),
			'conflicts'    => null === $row->rule ? array() : self::conflict_texts( $row->rule ),
		);
	}

	/**
	 * What a level the rule's parts come from is called: Store, Product,
	 * Variation, or an attribute value with its attribute.
	 *
	 * @param RuleSource $source The level.
	 */
	public static function source_label( RuleSource $source ): string {
		return match ( $source->level ) {
			/* translators: 1: a value of a product attribute, e.g. 70×100 cm; 2: the attribute, e.g. Size. Where a part of a price's pricing rule comes from, in the Prices table of the product edit page. */
			RuleSource::ATTRIBUTE => sprintf( __( '%1$s (%2$s)', 'pixonaut-for-woocommerce' ), $source->value, $source->attribute ),
			/* translators: Where a part of a price's pricing rule comes from: the product's own rule. In the Prices table of the product edit page. */
			RuleSource::PRODUCT   => _x( 'Product', 'source of a pricing rule part', 'pixonaut-for-woocommerce' ),
			/* translators: Where a part of a price's pricing rule comes from: the variation's own rule. In the Prices table of the product edit page. */
			RuleSource::VARIATION => _x( 'Variation', 'source of a pricing rule part', 'pixonaut-for-woocommerce' ),
			/* translators: Where a part of a price's pricing rule comes from: the store's pricing rule. In the Prices table of the product edit page. */
			default               => _x( 'Store', 'source of a pricing rule part', 'pixonaut-for-woocommerce' ),
		};
	}

	/**
	 * The levels a price's rule comes from, as one line: "Ram (Frame), Store".
	 *
	 * @param ItemRule $rule The price's rule.
	 */
	public static function sources_text( ItemRule $rule ): string {
		$levels = $rule->levels();
		if ( array() === $levels ) {
			return '';
		}
		/* translators: Separates the places a price's pricing rule comes from, e.g. "Ram (Frame), Store". */
		return implode( _x( ', ', 'list of pricing rule sources', 'pixonaut-for-woocommerce' ), array_map( self::source_label( ... ), $levels ) );
	}

	/**
	 * Where each part of a price's rule comes from, one per line; empty
	 * when they all come from one level.
	 *
	 * @param ItemRule $rule The price's rule.
	 */
	public static function source_parts_text( ItemRule $rule ): string {
		if ( count( $rule->levels() ) < 2 ) {
			return '';
		}
		$labels = self::part_labels();
		$lines  = array();
		foreach ( $rule->sources as $part => $source ) {
			/* translators: 1: a part of the pricing rule, e.g. Target margin (%); 2: its value, e.g. 50; 3: where it comes from, e.g. Store. One line of a tooltip in the Prices table of the product edit page. */
			$lines[] = sprintf( __( '%1$s: %2$s from %3$s', 'pixonaut-for-woocommerce' ), $labels[ $part ] ?? $part, $rule->parts[ $part ] ?? '', self::source_label( $source ) );
		}
		return implode( "\n", $lines );
	}

	/**
	 * What to say of each part two of a variation's attribute values set.
	 *
	 * @param ItemRule $rule The price's rule.
	 * @return list<string>
	 */
	public static function conflict_texts( ItemRule $rule ): array {
		$labels = self::part_labels();
		return array_map(
			static fn ( array $conflict ): string => sprintf(
				/* translators: 1: a part of the pricing rule, e.g. Fixed amount per item; 2: the attribute value that sets it, e.g. Ram (Frame); 3: another value that sets it too, e.g. 70×100 cm (Size). In the Prices table of the product edit page. */
				__( '%1$s is set by both %2$s and %3$s; %2$s wins, being first in the store’s attribute order.', 'pixonaut-for-woocommerce' ),
				$labels[ $conflict['part'] ] ?? $conflict['part'],
				self::source_label( $conflict['winner'] ),
				self::source_label( $conflict['loser'] )
			),
			$rule->conflicts
		);
	}

	/**
	 * A margin as text, to a tenth: 50.2 %.
	 *
	 * @param float $margin A margin, in percent.
	 */
	public static function percent( float $margin ): string {
		return sprintf(
			/* translators: %s: a margin as a number, such as 50.2. In the Prices table of the product edit page. */
			__( '%s %%', 'pixonaut-for-woocommerce' ),
			number_format_i18n( $margin, 1 )
		);
	}

	/**
	 * An amount as the store shows prices, as text.
	 *
	 * @param int    $amount   Thousandths.
	 * @param string $currency Its currency; the store's when empty.
	 */
	public static function money( int $amount, string $currency = '' ): string {
		return html_entity_decode( wp_strip_all_tags( wc_price( Amount::units( $amount ), '' === $currency ? array() : array( 'currency' => $currency ) ) ), ENT_QUOTES, 'UTF-8' );
	}
}
