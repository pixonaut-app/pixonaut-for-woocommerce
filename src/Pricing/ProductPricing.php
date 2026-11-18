<?php
/**
 * Prices a product's variations from their printshop costs.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Pricing;

use Closure;
use Pixonaut\WooCommerce\Api\VariantCosts;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ProductVariant;
use Pixonaut\WooCommerce\Schema;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use WC_Cache_Helper;
use WC_Product;
use WC_Product_Variation;

/**
 * A product personalised on a blank sells a blank variant per variation (a
 * simple product sells one), and Pixonaut knows what the printshop charges
 * for each. This reads those costs, and prices by the pricing rule: the
 * store's (`Settings`), with the parts the product changes.
 *
 * - **Apply** sets each price to the step nearest the rule's target, and
 *   from then on the price follows the rule.
 * - When a cost is read again, a price that follows the rule and whose
 *   margin is still within the tolerance is kept. One whose margin left it
 *   is set to the step nearest the target when its prices change
 *   automatically and its cost changed; otherwise the new price is only
 *   suggested, and the product is flagged on the products list. The store
 *   says whether prices change automatically, unless the product or the
 *   variation says so for itself (`PriceChanges`).
 * - A price edited by hand after the rule set it is kept, by Apply too,
 *   until it is given back to the rule.
 *
 * Only regular prices are set, and only on the rule's steps.
 */
final class ProductPricing {

	public const RULE_META_KEY = Schema::PRICE_RULE_META;

	public const WATCH_META_KEY = Schema::PRICE_WATCH_META;

	private const WATCH_SCHEMA_VERSION = 1;

	/**
	 * The costs read by the refresh that runs, by blank and region.
	 *
	 * @var array<string, array<string, VariantCost>>
	 */
	private array $read = array();

	/**
	 * @param VariantCosts    $costs     Reads the costs from Pixonaut.
	 * @param Settings        $settings  The store's rule, and whether prices change automatically.
	 * @param LoggerInterface $logger    Logs each price the rule sets.
	 * @param Closure         $clock     `fn(): int`, the time now.
	 * @param CostConverter   $converter Turns a cost into the store's currency.
	 * @phpstan-param Closure(): int $clock
	 */
	public function __construct(
		private readonly VariantCosts $costs,
		private readonly Settings $settings,
		private readonly LoggerInterface $logger,
		private readonly Closure $clock,
		private readonly CostConverter $converter,
	) {
	}

	/**
	 * Whether a product is one prices can follow costs on: a simple or
	 * variable product personalised on a blank.
	 *
	 * @param WC_Product $product The product.
	 */
	public static function prices( WC_Product $product ): bool {
		return ( $product->is_type( 'simple' ) || $product->is_type( 'variable' ) ) && null !== ProductConfig::stored_on( $product )->blank_id;
	}

	/**
	 * The parts of the rule a product changes, by part, as written; a part
	 * it leaves to the store is empty.
	 *
	 * @param WC_Product $product The product.
	 * @return array<string, string>
	 */
	public static function own_parts( WC_Product $product ): array {
		$raw    = $product->get_meta( self::RULE_META_KEY, true );
		$stored = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : null;
		$parts  = array();
		foreach ( PriceRule::PARTS as $part ) {
			$written        = is_array( $stored ) ? ( $stored[ $part ] ?? '' ) : '';
			$parts[ $part ] = PriceRule::is_part( $written ) ? PriceRule::part( $written ) : '';
		}
		return $parts;
	}

	/**
	 * Stores the parts of the rule a product changes; a part that is not a
	 * number is left to the store.
	 *
	 * @param WC_Product           $product The product.
	 * @param array<string, mixed> $parts   The parts as posted, by part.
	 */
	public function save_own_parts( WC_Product $product, array $parts ): void {
		$own = array();
		foreach ( PriceRule::PARTS as $part ) {
			$written = $parts[ $part ] ?? '';
			if ( PriceRule::is_part( $written ) && '' !== PriceRule::part( $written ) ) {
				$own[ $part ] = PriceRule::part( $written );
			}
		}
		$json = array() === $own ? '' : (string) wp_json_encode( $own );
		if ( $product->get_meta( self::RULE_META_KEY, true ) === $json ) {
			return;
		}
		if ( '' === $json ) {
			$product->delete_meta_data( self::RULE_META_KEY );
		} else {
			$product->update_meta_data( self::RULE_META_KEY, $json );
		}
		$product->save_meta_data();
	}

	/**
	 * The store's rule, as its settings write it, by part.
	 *
	 * @return array<string, string>
	 */
	public function store_parts(): array {
		$parts = array();
		foreach ( Settings::PRICE_RULE as $part => $setting ) {
			$written        = $this->settings->get( $setting );
			$parts[ $part ] = is_string( $written ) ? $written : '';
		}
		return $parts;
	}

	/**
	 * The rule a product is priced by: the store's, with the parts the
	 * product changes.
	 *
	 * @param WC_Product $product The product.
	 */
	public function rule_for( WC_Product $product ): PriceRule {
		return $this->rule_with( self::own_parts( $product ) );
	}

	/**
	 * The rule a product that changed these parts would be priced by: the
	 * store's, with them. A part that is not a number is left to the store.
	 *
	 * @param array<string, mixed> $own The parts a product changes, by part, as written.
	 */
	public function rule_with( array $own ): PriceRule {
		$parts = array();
		foreach ( PriceRule::PARTS as $part ) {
			$written = $own[ $part ] ?? '';
			if ( PriceRule::is_part( $written ) && '' !== PriceRule::part( $written ) ) {
				$parts[ $part ] = PriceRule::part( $written );
			}
		}
		return PriceRule::from_parts( $parts + $this->store_parts() );
	}

	/**
	 * Whether the store changes prices by itself when a cost changes.
	 */
	public function store_automatic(): bool {
		return true === $this->settings->get( Settings::PRICE_AUTOMATIC );
	}

	/**
	 * Whether a product's prices change by themselves when a cost changes:
	 * what the product says for itself, else the store's switch.
	 *
	 * @param WC_Product $product A simple or variable product.
	 */
	public function automatic_for( WC_Product $product ): bool {
		return PriceChanges::automatic( $product, $this->store_automatic() );
	}

	/**
	 * What a product prices: itself, or each of a variable product's
	 * variations.
	 *
	 * @param WC_Product $product A simple or variable product.
	 * @return list<WC_Product>
	 */
	public static function items( WC_Product $product ): array {
		if ( ! $product->is_type( 'variable' ) ) {
			return array( $product );
		}
		return array_values( array_filter( array_map( 'wc_get_product', $product->get_children() ), static fn ( mixed $child ): bool => $child instanceof WC_Product ) );
	}

	/**
	 * Each of a product's prices as it stands now, from what is stored:
	 * nothing is read from Pixonaut.
	 *
	 * @param WC_Product $product A simple or variable product.
	 * @return list<PriceRow>
	 */
	public function rows( WC_Product $product ): array {
		$rule      = $this->rule_for( $product );
		$automatic = $this->automatic_for( $product );
		return array_map(
			function ( WC_Product $item ) use ( $rule, $automatic ): PriceRow {
				$state    = PriceState::stored_on( $item );
				$price    = Amount::parse( $item->get_regular_price( 'edit' ) );
				$standing = $state->standing( $price );
				$known    = self::known_cost( $item, $state );
				$store    = $this->converter->in_store( $known );
				$cost     = $store->total;
				$tax      = PriceTax::of( $item );
				$wants    = null === $cost ? null : $rule->price_for( $cost, $tax );
				return new PriceRow(
					$item->get_id(),
					self::label( $item ),
					$known,
					$cost,
					$price,
					null === $cost || null === $price ? null : PriceRule::margin_of( $price, $cost, $tax ),
					// A price that follows the rule is suggested another only once its margin left the tolerance.
					PriceState::FOLLOWS === $standing ? $state->suggested : ( $wants === $price ? null : $wants ),
					$standing,
					match ( true ) {
						null === $state->checked_at || PriceState::BY_HAND === $standing => null,
						PriceState::FOLLOWS === $standing => $state->flag,
						// Never applied: what would stop the rule from setting it.
						default => self::blocker( $item, $store, $wants ),
					},
					$state->rule_at,
					$state->checked_at,
					$store->rate_date,
					$store->buffer,
					self::automatic_of( $item, $automatic ),
					PriceChanges::setting( $item ),
					null === $cost || null === $price ? null : PriceRule::contribution_of( $price, $cost, $tax )
				);
			},
			self::items( $product )
		);
	}

	/**
	 * Reads a product's costs from Pixonaut again, and settles its prices
	 * that follow the rule: kept, changed or flagged. Every cost is read
	 * before any is stored, so a read that fails stores nothing.
	 *
	 * @param WC_Product $product A simple or variable product.
	 * @return int How many prices were changed, which only happens for prices that change automatically.
	 * @throws ApiException When Pixonaut could not be asked; what is stored is kept.
	 */
	public function refresh( WC_Product $product ): int {
		$changed = $this->read_costs( $product );
		return $this->settle( self::fresh( $product->get_id() ) ?? $product, $changed );
	}

	/**
	 * Reads a product's costs from Pixonaut again and stores them, setting
	 * and flagging no price: `settle()` does that. Every cost is read before
	 * any is stored, so a read that fails stores nothing.
	 *
	 * @param WC_Product $product A simple or variable product.
	 * @return list<int> The product or variations whose cost in the store's currency changed.
	 * @throws ApiException When Pixonaut could not be asked; what is stored is kept.
	 */
	public function read_costs( WC_Product $product ): array {
		// What an earlier refresh read, in a job working through several products, may be stale.
		$this->read = array();
		$region     = self::region();
		$read       = array();
		$converts   = array();
		foreach ( self::items( $product ) as $item ) {
			list( $blank_id, $ref )  = self::sold( $item );
			$listed                  = null === $blank_id || null === $ref ? null : ( $this->of_blank( $blank_id, $region )[ $ref ] ?? null );
			$read[ $item->get_id() ] = array( $blank_id, $ref, $listed );
			// A cost kept from an earlier read is converted again too.
			$converts[] = $listed ?? PriceState::stored_on( $item )->cost_of( $blank_id, $ref, $region );
		}
		// A cost in another currency is converted at the day's rate, read only when one is.
		$this->converter->refresh_for( $converts );
		$now     = ( $this->clock )();
		$changed = array();
		foreach ( $read as $id => list( $blank_id, $ref, $listed ) ) {
			// Read again: the store manager may have priced it while Pixonaut was asked.
			$item = self::fresh( $id );
			if ( null === $item ) {
				continue;
			}
			$state = PriceState::stored_on( $item );
			// A variant Pixonaut has not read yet, or no longer lists, keeps the cost last read for it.
			$cost  = $listed ?? $state->cost_of( $blank_id, $ref, $region );
			$total = $this->converter->in_store( $cost )->total;
			// Another cost in the store's currency: the printshop's price moved, or the exchange rate did.
			if ( null !== $total && $total !== $state->total ) {
				$changed[] = $id;
			}
			$state->with_cost( $cost, $now, $blank_id, $ref, $total )->save_to( $item );
		}
		return $changed;
	}

	/**
	 * Sets prices to the step nearest the rule's target, and has them
	 * follow the rule from now on. A price edited by hand is kept unless it
	 * is released. One the rule cannot set is left as it is, and flagged.
	 *
	 * @param WC_Product $product A simple or variable product.
	 * @param int[]|null $ids     The product or variations to price; null for all.
	 * @param bool       $release Whether a price edited by hand is given back to the rule.
	 * @phpstan-param list<int>|null $ids
	 * @return int How many prices changed.
	 */
	public function apply( WC_Product $product, ?array $ids = null, bool $release = false ): int {
		$rule    = $this->rule_for( $product );
		$changed = 0;
		if ( ! $rule->is_set() ) {
			return $changed;
		}
		foreach ( self::items( $product ) as $item ) {
			$state = PriceState::stored_on( $item );
			$price = Amount::parse( $item->get_regular_price( 'edit' ) );
			if ( ( null !== $ids && ! in_array( $item->get_id(), $ids, true ) ) || ( ! $release && PriceState::BY_HAND === $state->standing( $price ) ) ) {
				continue;
			}
			$changed += $this->price( $item, $state, $rule ) ? 1 : 0;
		}
		$this->watch( $product );
		return $changed;
	}

	/**
	 * Each of a product's prices as `apply()` would leave it, from the costs
	 * Pixonaut has now, with nothing stored on the product or its
	 * variations: a price the rule would set reads as set and following the
	 * rule, one it would leave reads as it stands, with what stops the rule
	 * as its flag.
	 *
	 * @param WC_Product                $product A simple or variable product.
	 * @param int[]|null                $ids     The product or variations to price; null for all.
	 * @param bool                      $release Whether a price edited by hand is given back to the rule.
	 * @param array<string, mixed>|null $own     The parts of the rule the product would change; null for the ones it has.
	 * @phpstan-param list<int>|null $ids
	 * @return list<PriceRow>
	 * @throws ApiException When Pixonaut could not be asked.
	 */
	public function preview( WC_Product $product, ?array $ids = null, bool $release = false, ?array $own = null ): array {
		$this->read = array();
		$region     = self::region();
		$rule       = null === $own ? $this->rule_for( $product ) : $this->rule_with( $own );
		$items      = self::items( $product );
		$automatic  = $this->automatic_for( $product );
		$costs      = array();
		foreach ( $items as $item ) {
			list( $blank_id, $ref ) = self::sold( $item );
			$listed                 = null === $blank_id || null === $ref ? null : ( $this->of_blank( $blank_id, $region )[ $ref ] ?? null );
			// A variant Pixonaut does not list keeps the cost last read for it, as a refresh keeps it.
			$costs[ $item->get_id() ] = $listed ?? PriceState::stored_on( $item )->cost_of( $blank_id, $ref, $region );
		}
		$this->converter->refresh_for( array_values( $costs ) );
		return array_map(
			function ( WC_Product $item ) use ( $rule, $costs, $ids, $release, $automatic ): PriceRow {
				$state    = PriceState::stored_on( $item );
				$price    = Amount::parse( $item->get_regular_price( 'edit' ) );
				$standing = $state->standing( $price );
				$known    = $costs[ $item->get_id() ];
				$store    = $this->converter->in_store( $known );
				$cost     = $store->total;
				$tax      = PriceTax::of( $item );
				$wants    = null === $cost ? null : $rule->price_for( $cost, $tax );
				$blocker  = self::blocker( $item, $store, $wants );
				$tried    = $rule->is_set() && ( null === $ids || in_array( $item->get_id(), $ids, true ) ) && ( $release || PriceState::BY_HAND !== $standing );
				$sets     = $tried && null !== $wants && null === $blocker;
				$after    = $sets ? $wants : $price;
				return new PriceRow(
					$item->get_id(),
					self::label( $item ),
					$known,
					$cost,
					$after,
					null === $cost || null === $after ? null : PriceRule::margin_of( $after, $cost, $tax ),
					$sets || $wants === $price ? null : $wants,
					$sets ? PriceState::FOLLOWS : $standing,
					$tried ? $blocker : null,
					$state->rule_at,
					$state->checked_at,
					$store->rate_date,
					$store->buffer,
					self::automatic_of( $item, $automatic ),
					PriceChanges::setting( $item ),
					null === $cost || null === $after ? null : PriceRule::contribution_of( $after, $cost, $tax )
				);
			},
			$items
		);
	}

	/**
	 * Settles the prices of a product that follow the rule, from the costs
	 * stored: one within the tolerance is kept; one outside it is set to
	 * the rule's price when its prices change automatically and its cost
	 * just changed, else the rule's price is suggested.
	 *
	 * @param WC_Product $product A simple or variable product.
	 * @param int[]      $changed The product or variations whose cost just changed.
	 * @phpstan-param list<int> $changed
	 * @return int How many prices were changed.
	 */
	public function settle( WC_Product $product, array $changed = array() ): int {
		$rule      = $this->rule_for( $product );
		$automatic = $this->automatic_for( $product );
		$set       = 0;
		foreach ( self::items( $product ) as $item ) {
			$state = PriceState::stored_on( $item );
			$price = Amount::parse( $item->get_regular_price( 'edit' ) );
			if ( PriceState::OWN === $state->standing( $price ) ) {
				continue;
			}
			if ( PriceState::BY_HAND === $state->standing( $price ) || ! $rule->is_set() ) {
				// Nothing is suggested for a price kept by hand, or without a rule.
				$state->with_flag()->save_to( $item );
				continue;
			}
			$store = $this->converter->in_store( self::known_cost( $item, $state ) );
			$cost  = $store->total;
			$tax   = PriceTax::of( $item );
			if ( null !== $cost && null !== $price && $rule->holds( $price, $cost, $tax ) ) {
				$state->with_flag()->save_to( $item );
				continue;
			}
			if ( self::automatic_of( $item, $automatic ) && in_array( $item->get_id(), $changed, true ) ) {
				$set += $this->price( $item, $state, $rule, true ) ? 1 : 0;
				continue;
			}
			$wants = null === $cost ? null : $rule->price_for( $cost, $tax );
			$flag  = self::blocker( $item, $store, $wants ) ?? PriceState::FLAG_MARGIN;
			// At the minimum or maximum already: the rule has no other price for it.
			( $wants === $price ? $state->with_flag() : $state->with_flag( $flag, $wants ) )->save_to( $item );
		}
		$this->watch( $product );
		return $set;
	}

	/**
	 * The products with a price that follows the rule, a page at a time.
	 *
	 * @param int $after Only products above this id.
	 * @param int $limit At most this many.
	 * @return list<int>
	 */
	public static function watched( int $after, int $limit ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- paged by id, which no meta query can do; read by a background job only.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id > %d ORDER BY post_id LIMIT %d", self::WATCH_META_KEY, $after, $limit ) );
		return array_values( array_map( 'intval', $ids ) );
	}

	/**
	 * How many of a product's prices the rule flags, as last settled; 0
	 * for a product none of whose prices follow the rule.
	 *
	 * @param WC_Product $product The product.
	 */
	public static function flagged( WC_Product $product ): int {
		$raw   = $product->get_meta( self::WATCH_META_KEY, true );
		$watch = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : null;
		return is_array( $watch ) && is_int( $watch['flagged'] ?? null ) ? $watch['flagged'] : 0;
	}

	/**
	 * Sets one price to the rule's, and has it follow the rule from now on;
	 * a price the rule has none for is left as it stands, and flagged. A
	 * price or state that is no longer what the caller read, because the
	 * store manager saved the product meanwhile, is left to them. So is a
	 * price that would change by itself once its product or variation was
	 * set, meanwhile, to never change that way.
	 *
	 * @param WC_Product $read      The product or variation, as the caller read it.
	 * @param PriceState $state     Its state, as the caller read it.
	 * @param PriceRule  $rule      The rule.
	 * @param bool       $by_itself Whether the price changes by itself, rather than because the store manager applied the rule.
	 * @return bool Whether the price changed.
	 */
	private function price( WC_Product $read, PriceState $state, PriceRule $rule, bool $by_itself = false ): bool {
		$item = self::fresh( $read->get_id() );
		if ( null === $item || $item->get_regular_price( 'edit' ) !== $read->get_regular_price( 'edit' ) || PriceState::stored_on( $item )->to_array() !== $state->to_array() ) {
			return false;
		}
		if ( $by_itself && ! $this->still_automatic( $item ) ) {
			return false;
		}
		$store   = $this->converter->in_store( self::known_cost( $item, $state ) );
		$cost    = $store->total;
		$before  = Amount::parse( $item->get_regular_price( 'edit' ) );
		$wants   = null === $cost ? null : $rule->price_for( $cost, PriceTax::of( $item ) );
		$blocker = self::blocker( $item, $store, $wants );
		if ( null === $cost || null === $wants || null !== $blocker ) {
			$state->with_flag( $blocker, $wants )->save_to( $item );
			return false;
		}
		if ( $wants !== $before ) {
			$item->set_regular_price( Amount::decimal( $wants ) );
			$item->save();
			$this->logger->info(
				sprintf(
					'The pricing rule set the price of %1$s (#%2$d) to %3$s, from %4$s: cost %5$s, margin %6$s %%.',
					self::label( $item ),
					$item->get_id(),
					Amount::decimal( $wants ),
					null === $before ? 'none' : Amount::decimal( $before ),
					Amount::decimal( $cost ),
					number_format( (float) PriceRule::margin_of( $wants, $cost, PriceTax::of( $item ) ), 1, '.', '' )
				),
				array(
					'product_id' => $item->get_id(),
					'price'      => Amount::decimal( $wants ),
					'before'     => null === $before ? null : Amount::decimal( $before ),
					'cost'       => Amount::decimal( $cost ),
				)
			);
		}
		$state->priced( $wants, ( $this->clock )() )->save_to( $item );
		return $wants !== $before;
	}

	/**
	 * Whether a price still changes by itself, as stored now: its product
	 * or variation may have been set to never since the caller read them.
	 *
	 * @param WC_Product $item The product or variation, as stored now.
	 */
	private function still_automatic( WC_Product $item ): bool {
		$product = $item->is_type( 'variation' ) ? self::fresh( $item->get_parent_id() ) : $item;
		return null !== $product && self::automatic_of( $item, $this->automatic_for( $product ) );
	}

	/**
	 * Whether the price of one of a product's items changes by itself: what
	 * a variation says for itself, else what its product does.
	 *
	 * @param WC_Product $item    The product itself, or one of its variations.
	 * @param bool       $product Whether the product's prices change by themselves.
	 */
	private static function automatic_of( WC_Product $item, bool $product ): bool {
		return $item->is_type( 'variation' ) ? PriceChanges::automatic( $item, $product ) : $product;
	}

	/**
	 * The blank and the blank variant a product or variation sells now;
	 * nulls when it sells none.
	 *
	 * @param WC_Product $item The product or variation.
	 * @return array{0: string|null, 1: string|null}
	 */
	private static function sold( WC_Product $item ): array {
		$variant = ProductVariant::sold_on( $item, ProductConfig::config_for( $item ) );
		return null === $variant ? array( null, null ) : array( $variant->blank_id, $variant->ref );
	}

	/**
	 * How long the printshop says a product or variation takes, from the
	 * cost stored for it, while that is the cost of the variant it sells
	 * now, shipped to the shop's country; no time for any other.
	 *
	 * @param WC_Product $item The product or variation.
	 */
	public static function times_of( WC_Product $item ): DeliveryTimes {
		$cost = self::known_cost( $item, PriceState::stored_on( $item ) );
		return null === $cost ? new DeliveryTimes() : $cost->times;
	}

	/**
	 * The products printed on a blank, after a product id, lowest first:
	 * every product whose printshop tells a cost and a delivery time,
	 * whether or not a price of it follows the rule.
	 *
	 * @param int $after The product id to start after.
	 * @param int $limit How many at most.
	 * @return list<int>
	 */
	public static function on_blanks( int $after, int $limit ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- paged by id, which no meta query can do; read by a background job only.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT m.post_id FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = %s AND p.post_type = 'product' AND m.post_id > %d ORDER BY m.post_id LIMIT %d", ProductConfig::META_KEY, $after, $limit ) );
		return array_values( array_map( 'intval', $ids ) );
	}

	/**
	 * The cost stored for a product or variation, while it is the cost of
	 * the variant it sells now, shipped to the shop's country.
	 *
	 * @param WC_Product $item  The product or variation.
	 * @param PriceState $state Its state.
	 */
	private static function known_cost( WC_Product $item, PriceState $state ): ?VariantCost {
		if ( null === $state->cost ) {
			return null;
		}
		list( $blank_id, $ref ) = self::sold( $item );
		return $state->cost_of( $blank_id, $ref, self::region() );
	}

	/**
	 * The country shipping is priced to: the shop's.
	 */
	private static function region(): string {
		return (string) WC()->countries->get_base_country();
	}

	/**
	 * A product or variation as stored now, whatever this request read of
	 * it before.
	 *
	 * @param int $id The product or variation.
	 */
	private static function fresh( int $id ): ?WC_Product {
		wp_cache_delete( (string) $id, 'post_meta' );
		clean_post_cache( $id );
		WC_Cache_Helper::invalidate_cache_group( 'object_' . $id );
		$product = wc_get_product( $id );
		return $product instanceof WC_Product ? $product : null;
	}

	/**
	 * Notes on the product whether any of its prices follows the rule, and
	 * how many the rule flags; a product none of whose prices follow it is
	 * not watched.
	 *
	 * @param WC_Product $product A simple or variable product.
	 */
	private function watch( WC_Product $product ): void {
		// Read again: a price set above was written to the product as stored, not as the caller read it.
		$fresh = self::fresh( $product->get_id() );
		if ( null === $fresh ) {
			return;
		}
		$follow  = 0;
		$flagged = 0;
		foreach ( self::items( $fresh ) as $item ) {
			$state    = PriceState::stored_on( $item );
			$follows  = PriceState::FOLLOWS === $state->standing( Amount::parse( $item->get_regular_price( 'edit' ) ) );
			$follow  += $state->follows ? 1 : 0;
			$flagged += $follows && null !== $state->flag ? 1 : 0;
		}
		$json = 0 === $follow ? '' : (string) wp_json_encode(
			array(
				'v'       => self::WATCH_SCHEMA_VERSION,
				'flagged' => $flagged,
			)
		);
		if ( $fresh->get_meta( self::WATCH_META_KEY, true ) === $json ) {
			return;
		}
		if ( '' === $json ) {
			$fresh->delete_meta_data( self::WATCH_META_KEY );
		} else {
			$fresh->update_meta_data( self::WATCH_META_KEY, $json );
		}
		$fresh->save_meta_data();
	}

	/**
	 * A blank's costs, read once a refresh.
	 *
	 * @param string $blank_id The blank.
	 * @param string $region   The country shipping is priced to.
	 * @return array<string, VariantCost>
	 * @throws ApiException When Pixonaut could not be asked.
	 */
	private function of_blank( string $blank_id, string $region ): array {
		$key                = $blank_id . ' ' . $region;
		$this->read[ $key ] = $this->read[ $key ] ?? $this->costs->of_blank( $blank_id, $region );
		return $this->read[ $key ];
	}

	/**
	 * Why the rule cannot set a price, as a flag: its cost prices nothing,
	 * no step lies within the minimum and maximum, or the step is not above
	 * the sale price, which WooCommerce would then no longer take. Null
	 * when it can.
	 *
	 * @param WC_Product $item  The product or variation.
	 * @param StoreCost  $cost  Its cost in the store's currency.
	 * @param int|null   $wants The price the rule has for the cost.
	 */
	private static function blocker( WC_Product $item, StoreCost $cost, ?int $wants ): ?string {
		if ( null !== $cost->flag ) {
			return $cost->flag;
		}
		if ( null === $wants ) {
			return PriceState::FLAG_NO_STEP;
		}
		$sale = Amount::parse( $item->get_sale_price( 'edit' ) );
		return null !== $sale && $wants <= $sale ? PriceState::FLAG_SALE : null;
	}

	/**
	 * What a row is called: a variation's attribute values, a product's name.
	 *
	 * @param WC_Product $item The product or variation.
	 */
	private static function label( WC_Product $item ): string {
		$label = $item instanceof WC_Product_Variation ? wc_get_formatted_variation( $item, true, false, false ) : $item->get_name();
		return '' === $label ? '#' . $item->get_id() : $label;
	}
}
