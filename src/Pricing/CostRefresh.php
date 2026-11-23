<?php
/**
 * Reads the printshop costs of priced products again.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Pricing;

use Closure;
use Pixonaut\WooCommerce\Schema;
use Pixonaut\WooCommerce\Settings\Setting;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use WC_Product;

/**
 * Pixonaut keeps each blank variant's cost current, and sends no event when
 * one changes. So the costs of every product with a price that follows the
 * pricing rule, and of every product printed on a blank while the store
 * shows delivery times, are read again once a day, a page of products per Action
 * Scheduler job, and whenever Pixonaut says the product's blank changed and
 * the product is checked again. A product Pixonaut could not be asked about
 * keeps what it has until the next day.
 */
final class CostRefresh {

	/**
	 * The daily job, which starts at the first page.
	 */
	public const HOOK = 'pixonaut_wc_pricing_refresh';

	/**
	 * Reads the costs of one page of products; takes the product id the
	 * page starts after.
	 */
	public const PAGE_HOOK = 'pixonaut_wc_pricing_refresh_page';

	public const GROUP = Schema::ACTION_GROUP;

	/**
	 * Products per job: one request to Pixonaut each, at most.
	 */
	public const PAGE = 20;

	/**
	 * How often the costs are read: a day.
	 */
	private const INTERVAL_SECONDS = 86400;

	private readonly Closure $clock;

	private readonly Closure $every;

	/**
	 * The products read in this request, so one checked again with several
	 * of its variations is read once.
	 *
	 * @var array<int, true>
	 */
	private array $read = array();

	/**
	 * @param ProductPricing  $pricing    Reads the costs and settles the prices.
	 * @param Closure         $connected  `fn(): bool`, whether the store can ask Pixonaut: false while it is not connected, or paused.
	 * @param LoggerInterface $logger     Logs a product whose costs could not be read.
	 * @param Closure|null    $clock      `fn(): int` Unix time; `time()` when null.
	 * @param Closure|null    $every      `fn(): bool`, whether every product printed on a blank is read, as while the store shows delivery times; null reads only the ones with a price that follows the rule.
	 * @phpstan-param Closure(): bool $connected
	 * @phpstan-param (Closure(): int)|null $clock
	 * @phpstan-param (Closure(): bool)|null $every
	 */
	public function __construct(
		private readonly ProductPricing $pricing,
		private readonly Closure $connected,
		private readonly LoggerInterface $logger,
		?Closure $clock = null,
		?Closure $every = null,
	) {
		$this->clock = $clock ?? static fn (): int => time();
		$this->every = $every ?? static fn (): bool => false;
	}

	public function register(): void {
		add_action( self::HOOK, array( $this, 'start' ), 10, 0 );
		add_action( self::PAGE_HOOK, array( $this, 'page' ), 10, 1 );
		add_action( 'action_scheduler_init', array( $this, 'schedule' ), 10, 0 );
		add_action( 'update_option_' . Settings::OPTION, array( $this, 'settings_changed' ), 10, 2 );
		add_action( 'add_option_' . Settings::OPTION, array( $this, 'settings_added' ), 10, 2 );
	}

	/**
	 * The store's first saved settings: before them every setting had its
	 * default, with delivery times off.
	 *
	 * @param mixed $option The option's name.
	 * @param mixed $after  The settings now.
	 */
	public function settings_added( mixed $option, mixed $after ): void {
		$this->settings_changed( array(), $after );
	}

	/**
	 * Reads the costs at once when the store starts showing delivery times:
	 * a product whose prices follow no rule has none read until then.
	 *
	 * @param mixed $before The settings before.
	 * @param mixed $after  The settings now.
	 */
	public function settings_changed( mixed $before, mixed $after ): void {
		$shown = static fn ( mixed $stored ): bool => is_array( $stored ) && in_array( $stored[ Settings::DELIVERY_TIME_SHOW ] ?? null, array( true, Setting::CHECKED ), true );
		if ( $shown( $after ) && ! $shown( $before ) ) {
			$this->restart();
		}
	}

	/**
	 * Starts a read at the first page whatever page a read under way has
	 * reached: the pages before it were read for the products with a price
	 * that follows the rule alone.
	 */
	private function restart(): void {
		// By the hook alone: with a group, Action Scheduler takes away only the actions with no arguments.
		as_unschedule_all_actions( self::PAGE_HOOK );
		as_enqueue_async_action( self::PAGE_HOOK, array( 0 ), self::GROUP );
	}

	public function schedule(): void {
		if ( ! as_has_scheduled_action( self::HOOK, null, self::GROUP ) ) {
			as_schedule_recurring_action( ( $this->clock )() + self::INTERVAL_SECONDS, self::INTERVAL_SECONDS, self::HOOK, array(), self::GROUP );
		}
	}

	/**
	 * Starts the day's reading at its first page, unless the last one
	 * still runs.
	 */
	public function start(): void {
		if ( ! as_has_scheduled_action( self::PAGE_HOOK, null, self::GROUP ) ) {
			as_enqueue_async_action( self::PAGE_HOOK, array( 0 ), self::GROUP );
		}
	}

	/**
	 * The job: reads the costs of a page of products, then queues the page
	 * after it when this one was full.
	 *
	 * @param int|string $after The product id the page starts after; 0 for the first.
	 */
	public function page( int|string $after = 0 ): void {
		if ( ! ( $this->connected )() ) {
			return;
		}
		// A delivery time is told of every product on a blank, a price only of the ones that follow the rule.
		$ids = ( $this->every )() ? ProductPricing::on_blanks( (int) $after, self::PAGE ) : ProductPricing::watched( (int) $after, self::PAGE );
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( $product instanceof WC_Product ) {
				$this->refresh( $product );
			}
		}
		if ( self::PAGE === count( $ids ) ) {
			as_enqueue_async_action( self::PAGE_HOOK, array( $ids[ self::PAGE - 1 ] ), self::GROUP );
		}
	}

	/**
	 * Reads the costs of a product that was just checked again with
	 * Pixonaut, when one of its prices follows the rule or it sells a book
	 * at a page count.
	 *
	 * @param WC_Product $product The product, or one of its variations.
	 */
	public function rechecked( WC_Product $product ): void {
		$owner = $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : $product;
		// A book sold at a page count is bought only once its cost at that count is read.
		if ( $owner instanceof WC_Product && ( ( $this->every )() || '' !== $owner->get_meta( ProductPricing::WATCH_META_KEY, true ) || ProductPricing::sells_pages( $owner ) ) ) {
			$this->refresh( $owner );
		}
	}

	/**
	 * @param WC_Product $product A product with a price that follows the rule.
	 */
	private function refresh( WC_Product $product ): void {
		if ( isset( $this->read[ $product->get_id() ] ) || ! ProductPricing::prices( $product ) ) {
			return;
		}
		$this->read[ $product->get_id() ] = true;
		try {
			$this->pricing->refresh( $product );
		} catch ( ApiException $e ) {
			$this->logger->warning( 'The printshop costs of a product could not be read; its prices are kept.', array( 'product_id' => $product->get_id() ) + array( 'error' => $e->getMessage() ) );
		}
	}
}
