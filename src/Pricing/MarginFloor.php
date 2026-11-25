<?php
/**
 * Watches for prices whose margin falls below the store's lowest margin.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Pricing;

use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ProductVariant;
use Pixonaut\WooCommerce\Emails\MarginFloorEmail;
use Pixonaut\WooCommerce\Schema;
use Pixonaut\WooCommerce\Settings\Settings;
use WC_Product;

/**
 * The store can set a lowest acceptable margin (`Settings::PRICE_MARGIN_FLOOR`).
 * Whatever a price follows (the rule, a minimum and maximum that pin it, or
 * a hand), its margin is checked against the floor each time its cost is
 * read, its price changes, or the blank or variant it is printed on does:
 * a price below it is marked `NEW` in its `MARGIN_LOW_META` meta, and the
 * mark is taken away once the margin is back at the floor. While its cost
 * cannot be told in the store's currency for want of an exchange rate, its
 * mark is kept as it is.
 *
 * Once a day, every product on a blank is checked again from what is
 * stored, a page at a time, which catches a margin moved by no hook, such
 * as a cost moving with an exchange rate or the currency buffer. After the
 * last page, the prices marked `NEW` are named in one email to the store
 * (`MarginFloorEmail`), at most `MOST_NAMED` of them, and marked
 * `REPORTED`, so a price is named once per drop: again only after it
 * recovered and fell below once more. Those over the limit stay `NEW` and
 * are named the next day.
 */
final class MarginFloor {

	public const META_KEY = Schema::MARGIN_LOW_META;

	/**
	 * Below the floor, and not named in an email yet.
	 */
	public const NEW = 'new';

	/**
	 * Below the floor, and named in an email.
	 */
	public const REPORTED = 'reported';

	/**
	 * The daily job that checks every price against the floor, then emails
	 * the prices newly below it; takes the product id the page it checks
	 * starts after.
	 */
	public const REPORT_HOOK = 'pixonaut_wc_margin_floor_report';

	/**
	 * Checks a page of products against the floor; takes the product id the
	 * page starts after. Runs when the floor changes.
	 */
	public const SWEEP_HOOK = 'pixonaut_wc_margin_floor_sweep';

	public const GROUP = Schema::ACTION_GROUP;

	/**
	 * Products per sweep or report job: none is asked of Pixonaut.
	 */
	public const PAGE = 50;

	/**
	 * Prices one email names at most; the rest are named in the next.
	 */
	public const MOST_NAMED = 100;

	/**
	 * Arguments of `added_post_meta` and `updated_post_meta` read here: the
	 * meta's id, the post and the key.
	 */
	private const META_ARGS = 3;

	/**
	 * The prices below the floor, as read once a request: the product each
	 * is of and its mark, by product or variation id.
	 *
	 * @var array<int, array{product: int, mark: string}>|null
	 */
	private ?array $low = null;

	/**
	 * The prices below the floor by the product they are of, built from
	 * `$low` once a request.
	 *
	 * @var array<int, list<int>>|null
	 */
	private ?array $low_by_product = null;

	/**
	 * @param ProductPricing $pricing    Reads a price's cost and margin from what is stored.
	 * @param Settings       $settings   The floor.
	 * @param int            $most_named Prices one email names at most.
	 */
	public function __construct(
		private readonly ProductPricing $pricing,
		private readonly Settings $settings,
		private readonly int $most_named = self::MOST_NAMED,
	) {
	}

	public function register(): void {
		add_action( 'added_post_meta', array( $this, 'meta_changed' ), 10, self::META_ARGS );
		add_action( 'updated_post_meta', array( $this, 'meta_changed' ), 10, self::META_ARGS );
		add_action( 'deleted_post_meta', array( $this, 'meta_changed' ), 10, self::META_ARGS );
		add_action( 'woocommerce_update_product', array( $this, 'saved' ), 10, 1 );
		add_action( 'woocommerce_update_product_variation', array( $this, 'saved' ), 10, 1 );
		add_action( self::REPORT_HOOK, array( $this, 'report' ), 10, 1 );
		add_action( self::SWEEP_HOOK, array( $this, 'sweep' ), 10, 1 );
		add_action( 'action_scheduler_init', array( $this, 'schedule' ), 10, 0 );
		add_action( 'update_option_' . Settings::OPTION, array( $this, 'settings_changed' ), 10, 2 );
		add_action( 'add_option_' . Settings::OPTION, array( $this, 'settings_added' ), 10, 2 );
	}

	/**
	 * The lowest margin the store accepts, in percent of a price excluding
	 * tax; null while it sets none.
	 */
	public function floor(): ?float {
		return self::floor_in( $this->settings->get( Settings::PRICE_MARGIN_FLOOR ) );
	}

	/**
	 * A floor as written in the settings; null for none.
	 *
	 * @param mixed $written The setting's value.
	 */
	private static function floor_in( mixed $written ): ?float {
		return is_string( $written ) && PriceRule::is_part( $written ) && '' !== PriceRule::part( $written ) ? (float) PriceRule::part( $written ) : null;
	}

	/**
	 * A cost was read for a product or variation, or the rule settled it;
	 * or the blank a product is printed on, or the variant a price sells,
	 * was set, changed or taken away.
	 *
	 * @param mixed $meta_id The meta's id, or ids when it was deleted.
	 * @param mixed $post_id The product or variation.
	 * @param mixed $key     The meta's key.
	 */
	public function meta_changed( mixed $meta_id, mixed $post_id, mixed $key ): void {
		if ( PriceState::META_KEY === $key || ProductVariant::META_KEY === $key ) {
			$this->check( (int) $post_id );
		} elseif ( ProductConfig::META_KEY === $key ) {
			$this->saved( $post_id );
		}
	}

	/**
	 * A product or variation was saved, its price perhaps changed; a
	 * variable product's prices are its variations'.
	 *
	 * @param mixed $id The product or variation.
	 */
	public function saved( mixed $id ): void {
		$product = wc_get_product( (int) $id );
		if ( $product instanceof WC_Product && $product->is_type( 'variable' ) ) {
			$this->check_product( $product );
			return;
		}
		$this->check( (int) $id );
	}

	/**
	 * Checks every price of a product against the floor.
	 *
	 * @param WC_Product $product A simple or variable product.
	 */
	public function check_product( WC_Product $product ): void {
		foreach ( ProductPricing::items( $product ) as $item ) {
			$this->check( $item->get_id() );
		}
	}

	/**
	 * Checks one price against the floor, as it is stored now, and marks it
	 * below the floor or takes the mark away.
	 *
	 * @param int $id A simple product or a variation.
	 */
	public function check( int $id ): void {
		$floor = $this->floor();
		if ( null === $floor ) {
			// Taking the floor away took every mark away with it.
			return;
		}
		$item = ProductPricing::fresh( $id );
		if ( null === $item || $item->is_type( 'variable' ) ) {
			return;
		}
		$owner = $item->is_type( 'variation' ) ? wc_get_product( $item->get_parent_id() ) : $item;
		if ( ! $owner instanceof WC_Product || ! ProductPricing::prices( $owner ) ) {
			$this->mark( $item, false );
			return;
		}
		$margin = $this->pricing->margin_now( $item );
		if ( null === $margin && $this->pricing->cost_unknown_for_now( $item ) ) {
			// Not known for now rather than recovered: an unsent warning is kept, a sent one not sent again.
			return;
		}
		$this->mark( $item, null !== $margin && $margin['margin'] < $floor );
	}

	/**
	 * Marks a price below the floor, keeping whether it was reported, or
	 * takes the mark away.
	 *
	 * @param WC_Product $item  The product or variation.
	 * @param bool       $below Whether its margin is below the floor.
	 */
	private function mark( WC_Product $item, bool $below ): void {
		$marked = '' !== $item->get_meta( self::META_KEY, true );
		if ( $below === $marked ) {
			return;
		}
		if ( $below ) {
			$item->update_meta_data( self::META_KEY, self::NEW );
		} else {
			$item->delete_meta_data( self::META_KEY );
		}
		$item->save_meta_data();
		$this->forget();
	}

	/**
	 * Forgets the prices below the floor as read this request.
	 */
	private function forget(): void {
		$this->low            = null;
		$this->low_by_product = null;
	}

	/**
	 * The prices below the floor, each with the product it is of.
	 *
	 * @param string|null $mark Only those marked so, `NEW` or `REPORTED`; null for both.
	 * @return array<int, int> The product, by the product or variation below the floor.
	 */
	public function low( ?string $mark = null ): array {
		if ( null === $this->low ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one read for the products list, its notice and the daily email; the marks change as prices do.
			$rows      = $wpdb->get_results( $wpdb->prepare( "SELECT p.ID AS id, IF(p.post_type = 'product_variation', p.post_parent, p.ID) AS product, m.meta_value AS mark FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = %s AND p.post_status <> 'trash' ORDER BY p.ID", self::META_KEY ), ARRAY_A );
			$this->low = array();
			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				$this->low[ (int) $row['id'] ] = array(
					'product' => (int) $row['product'],
					'mark'    => (string) $row['mark'],
				);
			}
		}
		$low = array();
		foreach ( $this->low as $id => $row ) {
			if ( null === $mark || $mark === $row['mark'] ) {
				$low[ $id ] = $row['product'];
			}
		}
		return $low;
	}

	/**
	 * The products with a price below the floor.
	 *
	 * @return list<int>
	 */
	public function products(): array {
		return array_values( array_unique( $this->low() ) );
	}

	/**
	 * A product's prices below the floor, however marked.
	 *
	 * @param int $product_id The product.
	 * @return list<int> The product itself or its variations.
	 */
	public function low_of_product( int $product_id ): array {
		if ( null === $this->low_by_product ) {
			$this->low_by_product = array();
			foreach ( $this->low() as $id => $product ) {
				$this->low_by_product[ $product ][] = $id;
			}
		}
		return $this->low_by_product[ $product_id ] ?? array();
	}

	/**
	 * The daily job: checks a page of products printed on a blank against
	 * the floor and queues the page after it; after the last page, emails
	 * the prices newly below the floor. Nothing is sent while the store
	 * sets no floor, or none is new.
	 *
	 * @param int|string $after The product id the page starts after; 0 for the first.
	 */
	public function report( int|string $after = 0 ): void {
		$floor = $this->floor();
		if ( null === $floor ) {
			return;
		}
		$next = $this->check_page( (int) $after );
		if ( null !== $next ) {
			as_enqueue_async_action( self::REPORT_HOOK, array( $next ), self::GROUP );
			return;
		}
		$this->send( $floor );
	}

	/**
	 * Emails the prices marked `NEW`, at most as many as one email names,
	 * and marks them reported once it went out.
	 *
	 * @param float $floor The lowest margin, in percent.
	 */
	private function send( float $floor ): void {
		$ids  = array_keys( $this->low( self::NEW ) );
		$rows = array();
		$left = count( $ids );
		foreach ( $ids as $id ) {
			if ( count( $rows ) >= $this->most_named ) {
				break;
			}
			--$left;
			$item   = ProductPricing::fresh( $id );
			$margin = null === $item ? null : $this->pricing->margin_now( $item );
			if ( null === $item || null === $margin ) {
				continue;
			}
			$product = $item->is_type( 'variation' ) ? wc_get_product( $item->get_parent_id() ) : $item;
			$rows[]  = array(
				'item'    => $item,
				'product' => $product instanceof WC_Product ? $product : $item,
			) + $margin;
		}
		if ( array() === $rows ) {
			return;
		}
		$email = WC()->mailer()->get_emails()[ MarginFloorEmail::class ] ?? null;
		if ( ! $email instanceof MarginFloorEmail || ! $email->trigger( $rows, $floor, $left ) ) {
			return;
		}
		foreach ( $rows as $row ) {
			// Read again: a price that recovered, or a floor taken away, while the email was sent is not marked again.
			$item = ProductPricing::fresh( $row['item']->get_id() );
			if ( null === $item || null === $this->floor() || self::NEW !== $item->get_meta( self::META_KEY, true ) ) {
				continue;
			}
			$item->update_meta_data( self::META_KEY, self::REPORTED );
			$item->save_meta_data();
		}
		$this->forget();
	}

	/**
	 * Keeps the daily email scheduled.
	 */
	public function schedule(): void {
		// With no arguments: the recurring action, not a page of a report under way.
		if ( ! as_has_scheduled_action( self::REPORT_HOOK, array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + DAY_IN_SECONDS, DAY_IN_SECONDS, self::REPORT_HOOK, array(), self::GROUP );
		}
	}

	/**
	 * The store's first saved settings: before them it set no floor.
	 *
	 * @param mixed $option The option's name.
	 * @param mixed $after  The settings now.
	 */
	public function settings_added( mixed $option, mixed $after ): void {
		$this->settings_changed( array(), $after );
	}

	/**
	 * A floor taken away takes every mark away; a floor set or moved checks
	 * every price against it, in the background.
	 *
	 * @param mixed $before The settings before.
	 * @param mixed $after  The settings now.
	 */
	public function settings_changed( mixed $before, mixed $after ): void {
		$floor_of = static fn ( mixed $stored ): ?float => is_array( $stored ) ? self::floor_in( $stored[ Settings::PRICE_MARGIN_FLOOR ] ?? null ) : null;
		$floor    = $floor_of( $after );
		if ( $floor === $floor_of( $before ) ) {
			return;
		}
		if ( null === $floor ) {
			delete_post_meta_by_key( self::META_KEY );
			$this->forget();
			return;
		}
		// By the hook alone: with a group, Action Scheduler takes away only the actions with no arguments.
		as_unschedule_all_actions( self::SWEEP_HOOK );
		as_enqueue_async_action( self::SWEEP_HOOK, array( 0 ), self::GROUP );
	}

	/**
	 * The sweep job: checks a page of products printed on a blank against
	 * the floor, then queues the page after it when this one was full.
	 *
	 * @param int|string $after The product id the page starts after; 0 for the first.
	 */
	public function sweep( int|string $after = 0 ): void {
		$next = $this->check_page( (int) $after );
		if ( null !== $next ) {
			as_enqueue_async_action( self::SWEEP_HOOK, array( $next ), self::GROUP );
		}
	}

	/**
	 * Checks a page of products printed on a blank against the floor.
	 *
	 * @param int $after The product id the page starts after; 0 for the first.
	 * @return int|null The product id the next page starts after; null when this was the last.
	 */
	private function check_page( int $after ): ?int {
		$ids = ProductPricing::on_blanks( $after, self::PAGE );
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( $product instanceof WC_Product ) {
				$this->check_product( $product );
			}
		}
		return self::PAGE === count( $ids ) ? $ids[ self::PAGE - 1 ] : null;
	}
}
