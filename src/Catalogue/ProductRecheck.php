<?php
/**
 * Checks products again when their templates or blanks change in Pixonaut.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use Closure;
use LogicException;
use Pixonaut\WooCommerce\Admin\InvalidProductNotice;
use Pixonaut\WooCommerce\Admin\LowResolutionWarnings;
use Pixonaut\WooCommerce\Admin\TrashedProducts;
use Pixonaut\WooCommerce\Api\Catalogue;
use Pixonaut\WooCommerce\Api\CheckSlowdown;
use Pixonaut\WooCommerce\Api\WcLogger;
use Pixonaut\WooCommerce\Schema;
use Pixonaut\WooCommerce\Storefront\KeptDesignerLinks;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\ErrorCode;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Webhooks\Event;
use Pixonaut\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Pixonaut\WooCommerce\Webhooks\Payload;
use RuntimeException;
use WC_Product;

/**
 * A template published, paused, resumed or deleted, or a blank updated,
 * deleted or restored, re-checks the products and variations that use it; setting the
 * event stream's first cursor re-checks every one. An event only says which
 * products to look at: the re-check reads what is true now from Pixonaut, so
 * an event arriving late or twice changes nothing.
 *
 * Products are found through `ProductIndex`, a page at a time by a job that
 * queues the next page after its own, and re-checked in batches, one Action
 * Scheduler job each. A product already waiting in a batch is left out of
 * the next one (`PendingRechecks`), so events arriving faster than the
 * re-checks run queue each product once. A product that cannot be re-checked because Pixonaut
 * did not answer keeps its configuration and is tried again later, up to
 * MAX_ATTEMPTS times in all; Pixonaut being away never makes a product
 * unsellable. One Pixonaut refused because the store sent too many requests
 * is not counted as an attempt: the batch stops there, and it and the
 * products after it are checked again once Pixonaut takes requests again;
 * a batch whose turn comes while Pixonaut asks the store to hold its
 * checks (`CheckSlowdown`) waits until then without asking. A product the re-check finds unsellable raises its notice, and
 * one sellable again clears it. The result is written only over the
 * configuration the re-check read: one saved meanwhile is newer, and kept.
 * A re-check also forgets the product's designer links Pixonaut no longer
 * lists, such as one withdrawn in the studio. A product in the trash is not
 * checked (`TrashedProducts`).
 */
final class ProductRecheck {

	public const TEMPLATE_PUBLISHED = 'template.published';

	public const TEMPLATE_LIVE_CHANGED = 'template.live_changed';

	public const TEMPLATE_DELETED = 'template.deleted';

	public const BLANK_UPDATED = 'blank.updated';

	public const BLANK_DELETED = 'blank.deleted';

	public const BLANK_RESTORED = 'blank.restored';

	/**
	 * Action Scheduler hook of the job queueing a page of re-checks, with
	 * what the products use (`by`, `key`) and the product id the page starts
	 * after as its arguments.
	 */
	public const QUEUE_ACTION = 'pixonaut_wc_catalogue_recheck_queue';

	/**
	 * Action Scheduler hook of a batch's re-check, with the product ids and
	 * the attempt as its arguments.
	 */
	public const ACTION = 'pixonaut_wc_catalogue_recheck';

	public const GROUP = Schema::ACTION_GROUP;

	/**
	 * Where the re-checks, and the jobs queueing them, stand in Action
	 * Scheduler's queue: behind orders, webhook events and fulfilment.
	 */
	public const PRIORITY = Schema::CATALOGUE_JOB_PRIORITY;

	/**
	 * Where the re-check of the variations a link could not move at once
	 * stands: ahead of the catalogue's bulk jobs, which a change in
	 * Pixonaut can queue by the thousand, and behind orders, webhook
	 * events and fulfilment, at Action Scheduler's default of 10. Someone
	 * waits on it: the product page serves the version they left until it
	 * runs.
	 */
	public const LINKED_PRIORITY = 15;

	/**
	 * Products using a template.
	 */
	public const BY_TEMPLATE = 'template';

	/**
	 * Products using a blank.
	 */
	public const BY_BLANK = 'blank';

	/**
	 * Every product with a configuration.
	 */
	public const BY_ANY = 'any';

	/**
	 * Products one queueing job pages through.
	 */
	public const QUEUE_PAGE = 100;

	/**
	 * Products one re-check job checks: each costs a blank read and a check
	 * per side.
	 */
	public const BATCH = 10;

	/**
	 * Attempts at re-checking a product, the first one included, before it is given up on.
	 */
	public const MAX_ATTEMPTS = 5;

	/**
	 * Seconds a link spends moving its variations with a setup of their
	 * own before it leaves the rest to a re-check. Pixonaut waits 30
	 * seconds for the store's answer to a link, but each check costs a busy
	 * store seconds and holds a PHP worker other calls wait for, so a link
	 * answers soon and the queued re-check, which runs at once, moves the
	 * rest.
	 */
	public const FOLLOW_BUDGET_SECONDS = 3;

	/**
	 * Variations a link checks at most before it answers; the rest are left
	 * to the re-check it queues.
	 */
	public const FOLLOW_INLINE_MAX = 2;

	/**
	 * Wait before the second attempt; it doubles with every attempt after.
	 */
	public const RETRY_BASE_SECONDS = 60;

	/**
	 * What re-checking a product came to: checked, and what changed stored.
	 */
	private const CHECKED = 'checked';

	/**
	 * What re-checking a product came to: Pixonaut could not be asked, so nothing was stored.
	 */
	private const NOT_ASKED = 'not_asked';

	/**
	 * What re-checking a product came to: Pixonaut refused it because the
	 * store sent too many requests, so nothing was stored.
	 */
	private const SLOWED_DOWN = 'slowed_down';

	/**
	 * Option holding whether every product's variant waits to be resolved.
	 */
	public const BACKFILL_OPTION = Schema::VARIANTS_BACKFILL_OPTION;

	/**
	 * BACKFILL_OPTION's value while the re-check of every product waits to be queued.
	 */
	public const BACKFILL_PENDING = '1';

	/**
	 * BACKFILL_OPTION's value while the re-check of every product waits to
	 * be queued in place of every re-check queued before.
	 */
	public const BACKFILL_REPLACING = 'replace';

	/**
	 * @param ProductLinker        $linker  Checks a configuration with Pixonaut.
	 * @param Catalogue            $catalogue Reads which templates are live.
	 * @param InvalidProductNotice $notice  Raises or clears a product's notice.
	 * @param LoggerInterface      $logger  Logs a re-check that failed and a job that could not be queued.
	 * @param ProductVariants      $variants Resolves each variation's blank variant again.
	 * @param KeptDesignerLinks    $links   Forgets the designer links Pixonaut no longer lists.
	 * @param Closure|null         $rechecked `fn( WC_Product ): void`, told each product or variation checked again; null tells nobody.
	 * @param LayoutValueSets|null $layout_sets Makes the page layouts' sets a save left pending; null makes none.
	 * @phpstan-param (Closure(WC_Product): void)|null $rechecked
	 */
	/**
	 * Seconds Pixonaut asked the store to wait, when it refused the
	 * product being re-checked for too many requests and said how long.
	 *
	 * @var int|null
	 */
	private ?int $retry_after = null;

	/**
	 * The priority the re-check running was queued at, when not
	 * `PRIORITY`: a link's, which its retries keep so they do not fall
	 * behind the bulk queue. Null for a bulk re-check.
	 *
	 * @var int|null
	 */
	private ?int $priority = null;

	public function __construct(
		private readonly ProductLinker $linker,
		private readonly Catalogue $catalogue,
		private readonly InvalidProductNotice $notice,
		private readonly LoggerInterface $logger,
		private readonly ProductVariants $variants,
		private readonly KeptDesignerLinks $links,
		private readonly ?Closure $rechecked = null,
		private readonly ?LayoutValueSets $layout_sets = null,
	) {
	}

	public function register(): void {
		add_action( 'pixonaut_wc_events_baseline_set', array( $this, 'recheck_all' ), 10, 0 );
		add_action( self::QUEUE_ACTION, array( $this, 'queue_page' ), 10, 3 );
		add_action( self::ACTION, array( $this, 'recheck' ), 10, 3 );
		add_action( 'action_scheduler_init', array( $this, 'queue_backfill' ) );
	}

	/**
	 * Queues the re-check of every product an upgrade asked for, and only
	 * then forgets the ask: one that could not be queued is logged and
	 * tried again on the next request.
	 */
	public function queue_backfill(): void {
		$backfill = get_option( self::BACKFILL_OPTION );
		if ( self::BACKFILL_PENDING !== $backfill && self::BACKFILL_REPLACING !== $backfill ) {
			return;
		}
		if ( self::BACKFILL_REPLACING === $backfill ) {
			// The re-check of every product covers each one these would have checked. By hook alone, whatever their arguments.
			as_unschedule_all_actions( self::ACTION );
			as_unschedule_all_actions( self::QUEUE_ACTION );
		}
		if ( $this->queue_after( self::BY_ANY, '', 0 ) ) {
			delete_option( self::BACKFILL_OPTION );
		}
	}

	/**
	 * The handlers of the events that re-check products, by event type.
	 *
	 * @return array<string, callable(Event): void>
	 */
	public function handlers(): array {
		return array_fill_keys(
			array( self::TEMPLATE_PUBLISHED, self::TEMPLATE_LIVE_CHANGED, self::TEMPLATE_DELETED, self::BLANK_UPDATED, self::BLANK_DELETED, self::BLANK_RESTORED ),
			array( $this, 'handle' )
		);
	}

	/**
	 * Queues the re-check of the products the event's template or blank is used by.
	 *
	 * @param Event $event A template or blank event.
	 * @throws RuntimeException When the job could not be queued; the event is handled again later.
	 */
	public function handle( Event $event ): void {
		$blank_id = Payload::string( $event, 'blankId' );
		// A blank's photos and print areas can change under the same ids.
		if ( null !== $blank_id && in_array( $event->type, array( self::BLANK_UPDATED, self::BLANK_RESTORED ), true ) ) {
			if ( ! ProductPictures::redraw_blank( $blank_id ) ) {
				// Handled again, the blank moves once more: its pictures are drawn again either way.
				throw new RuntimeException( 'The redraw of the pictures on a blank could not be queued.' );
			}
		}
		if ( null !== $blank_id ) {
			$this->queue_first( self::BY_BLANK, $blank_id );
			return;
		}
		$base_id   = Payload::string( $event, 'baseId' );
		$published = Payload::string( $event, 'templateId' );
		if ( null === $base_id && null !== $published ) {
			// `template.published` names the version it published; the index holds base ids.
			$base_id = TemplateId::parse( $published )->base_id ?? $published;
		}
		if ( null !== $base_id ) {
			$this->queue_first( self::BY_TEMPLATE, $base_id );
		}
	}

	/**
	 * Queues the re-check of some products, as a job of its own per batch:
	 * one whose check with Pixonaut could not be finished when it was saved.
	 *
	 * @param int[] $product_ids Products or variations.
	 * @phpstan-param list<int> $product_ids
	 */
	public function queue( array $product_ids ): void {
		foreach ( array_chunk( $product_ids, self::BATCH ) as $batch ) {
			$this->queue_batch( $batch, 1, 0, null );
		}
	}

	/**
	 * Queues the re-check of every product with a configuration. A job that
	 * could not be queued is logged; the next baseline queues it again.
	 */
	public function recheck_all(): void {
		$this->queue_recheck_all();
	}

	/**
	 * As `recheck_all()`, saying whether the job was queued.
	 *
	 * @return bool False when it could not be queued.
	 */
	public function queue_recheck_all(): bool {
		return $this->queue_after( self::BY_ANY, '', 0 );
	}

	/**
	 * The queueing job: queues the re-check of a page of products, in
	 * batches, then the job for the page after when this one was full.
	 *
	 * @param string     $by    BY_TEMPLATE, BY_BLANK or BY_ANY.
	 * @param string     $key   The template's base id or the blank's id; empty for BY_ANY.
	 * @param int|string $after The product id the page starts after; 0 for the first.
	 * @throws RuntimeException When a batch or the next page could not be queued, after trying every one.
	 */
	public function queue_page( string $by, string $key, int|string $after = 0 ): void {
		$ids = match ( $by ) {
			self::BY_TEMPLATE => ProductIndex::using_template( $key, (int) $after, self::QUEUE_PAGE ),
			self::BY_BLANK    => ProductIndex::using_blank( $key, (int) $after, self::QUEUE_PAGE ),
			default           => ProductIndex::configured( (int) $after, self::QUEUE_PAGE ),
		};
		$queued = true;
		foreach ( array_chunk( $ids, self::BATCH ) as $batch ) {
			$queued = $this->queue_batch( $batch, 1, 0, null ) && $queued;
		}
		if ( self::QUEUE_PAGE === count( $ids ) ) {
			$queued = $this->queue_after( $by, $key, $ids[ self::QUEUE_PAGE - 1 ] ) && $queued;
		}
		if ( ! $queued ) {
			throw new RuntimeException( 'The re-check of some products could not be queued.' );
		}
	}

	/**
	 * The job: re-checks each product of a batch against the templates live
	 * now, and stores what changed. Products Pixonaut could not be asked
	 * about are tried again later while they have attempts left; ones it
	 * refused for too many requests, once it takes requests again.
	 *
	 * @param array<int|string> $product_ids Products and variations.
	 * @param int|string        $attempt     Which attempt this is, from 1.
	 * @param int|string|null   $priority    The priority it was queued at when not `PRIORITY`, which its retries keep; null for a bulk re-check.
	 * @throws ApiException When Pixonaut could not list the live templates; the batch is tried again later.
	 */
	public function recheck( array $product_ids, int|string $attempt = 1, int|string|null $priority = null ): void {
		$product_ids    = array_values( array_map( 'intval', $product_ids ) );
		$attempt        = max( 1, (int) $attempt );
		$this->priority = null === $priority ? null : (int) $priority;
		// Started: a change from now on queues them again.
		PendingRechecks::forget( $product_ids );
		if ( null !== CheckSlowdown::until() ) {
			// Pixonaut would refuse every check until then: their turn comes after it.
			$this->later( $product_ids, $attempt, null );
			return;
		}
		try {
			$live = $this->catalogue->live_versions();
		} catch ( ApiException $e ) {
			$this->failed( $product_ids, $attempt, $e->getMessage() );
			throw $e;
		}

		$again = array();
		foreach ( $product_ids as $i => $product_id ) {
			$this->retry_after = null;
			$checked           = $this->recheck_one( $product_id, $live );
			if ( self::SLOWED_DOWN === $checked ) {
				// The products after it would be refused too, and each refusal spends the store's budget.
				$this->later( array_slice( $product_ids, $i ), $attempt, $this->retry_after );
				break;
			}
			if ( self::NOT_ASKED === $checked ) {
				$again[] = $product_id;
			}
			$this->links->prune( $product_id );
		}
		if ( array() !== $again ) {
			$this->failed( $again, $attempt, 'Pixonaut could not check the configuration.' );
		}
	}

	/**
	 * Moves the variations of a product just linked to a template version
	 * that have a setup of their own, with a side on that template that is
	 * not pinned, onto the version linked, checked with Pixonaut as a
	 * re-check does; their other sides follow their templates' live
	 * versions. Without it such a variation waits for the re-check the
	 * version's publishing queued, which a busy store can run long after,
	 * and its customers design on the version it left until then. A link
	 * checks at most `FOLLOW_INLINE_MAX` of them, and none once another
	 * check would take it past `FOLLOW_BUDGET_SECONDS`; the rest, and any
	 * Pixonaut could not check now, are queued ahead of the bulk re-checks
	 * and checked against the live versions then. A template that is not
	 * live moves nothing: the re-check its event queued stops those
	 * variations' sales.
	 *
	 * A product linked to another template takes along the variations
	 * whose setup of their own is its own but for the blank, variant or
	 * print size, from the template it left (`OwnSetupsOnLeftTemplate`):
	 * they are stored on the version linked, unchecked, and then checked as
	 * those following the template are, now or in their re-check later.
	 *
	 * @param WC_Product         $product  The product linked.
	 * @param TemplateId         $template The template version it is linked to.
	 * @param ProductConfig|null $before   What the product had before the link; null moves none across templates.
	 * @return array{moved: int, later: int} How many variations moved, and how many were queued to be re-checked later instead.
	 */
	public function follow_link( WC_Product $product, TemplateId $template, ?ProductConfig $before = null ): array {
		$started = microtime( true );
		$across  = null === $before ? array() : OwnSetupsOnLeftTemplate::move( $product, $before, $template );
		$ids     = array_values( array_unique( array_merge( $across, self::following_own_setups( $product, $template ) ) ) );
		$none    = array(
			'moved' => 0,
			'later' => 0,
		);
		if ( array() === $ids ) {
			return $none;
		}
		if ( null !== CheckSlowdown::until() ) {
			// Pixonaut would refuse every check until then.
			return array(
				'moved' => 0,
				'later' => $this->queue_later( $ids, self::slowed_delay( null ) ),
			);
		}
		try {
			$live = $this->catalogue->live_versions();
		} catch ( ApiException $e ) {
			$this->logger->error( 'Variations not moved onto the version linked: ' . $e->getMessage(), array( 'products' => $ids ) );
			return array(
				'moved' => 0,
				'later' => $this->queue_later( $ids, self::RETRY_BASE_SECONDS ),
			);
		}
		if ( ! isset( $live[ $template->base_id ] ) ) {
			// Those moved across are not sold until checked; their re-check finds the template not live.
			return array(
				'moved' => 0,
				'later' => array() === $across ? 0 : $this->queue_later( $across, 0 ),
			);
		}
		$live[ $template->base_id ] = $template->version;

		$moved   = 0;
		$again   = array();
		$left    = array();
		$slowed  = array();
		$waiting = null;
		$slowest = 0.0;
		foreach ( $ids as $i => $id ) {
			$elapsed = microtime( true ) - $started;
			if ( $i >= self::FOLLOW_INLINE_MAX || $elapsed + $slowest >= self::FOLLOW_BUDGET_SECONDS ) {
				// The link answers before another check would take it past its budget; the rest are checked at once, after it.
				$left = array_slice( $ids, $i );
				break;
			}
			$this->retry_after = null;
			$checked           = $this->recheck_one( $id, $live );
			$slowest           = max( $slowest, microtime( true ) - $started - $elapsed );
			if ( self::SLOWED_DOWN === $checked ) {
				// The variations after it would be refused too.
				$slowed  = array_slice( $ids, $i );
				$waiting = $this->retry_after;
				break;
			}
			$variation = wc_get_product( $id );
			if ( self::CHECKED === $checked && $variation instanceof WC_Product && array() === self::following_own_setups_of( array( $variation ), $template ) ) {
				++$moved;
				continue;
			}
			// Not asked, or saved meanwhile on the version it had.
			$again[] = $id;
		}
		$later = 0;
		if ( array() !== $left ) {
			$later += $this->queue_later( $left, 0 );
		}
		if ( array() !== $again ) {
			$this->logger->warning( 'Variations not moved onto the version linked yet: they are checked again later.', array( 'products' => $again ) );
			$later += $this->queue_later( $again, self::RETRY_BASE_SECONDS );
		}
		if ( array() !== $slowed ) {
			$later += $this->queue_later( $slowed, self::slowed_delay( $waiting ) );
		}
		return array(
			'moved' => $moved,
			'later' => $later,
		);
	}

	/**
	 * Queues the re-check of variations not moved now, in batches, as
	 * their first attempt, at `LINKED_PRIORITY`: even one already waiting
	 * in a bulk re-check, which may run long after. One that could not be
	 * queued is logged, and not counted; the template's next event
	 * re-checks it.
	 *
	 * @param int[] $ids   Variations.
	 * @param int   $delay Seconds to wait; 0 to run as soon as possible.
	 * @phpstan-param list<int> $ids
	 * @return int How many were queued.
	 */
	private function queue_later( array $ids, int $delay ): int {
		$queued = 0;
		foreach ( array_chunk( $ids, self::BATCH ) as $batch ) {
			$queued += self::enqueue( $batch, 1, $delay, $this->logger, self::LINKED_PRIORITY ) ? count( $batch ) : 0;
		}
		return $queued;
	}

	/**
	 * The product's variations with a setup of their own that has a side
	 * following the template on another version than the one given.
	 *
	 * @param WC_Product $product  A product.
	 * @param TemplateId $template The template version.
	 * @return list<int>
	 */
	private static function following_own_setups( WC_Product $product, TemplateId $template ): array {
		if ( ! $product->is_type( 'variable' ) ) {
			return array();
		}
		$variations = array();
		foreach ( $product->get_children() as $child_id ) {
			$child = wc_get_product( $child_id );
			if ( $child instanceof WC_Product ) {
				$variations[] = $child;
			}
		}
		return self::following_own_setups_of( $variations, $template );
	}

	/**
	 * Which of the variations given have a setup of their own with a side
	 * following the template on another version than the one given.
	 *
	 * @param WC_Product[] $variations Variations.
	 * @param TemplateId   $template   The template version.
	 * @phpstan-param list<WC_Product> $variations
	 * @return list<int>
	 */
	private static function following_own_setups_of( array $variations, TemplateId $template ): array {
		$ids = array();
		foreach ( $variations as $variation ) {
			if ( null === ProductConfig::document_on( $variation ) || TrashedProducts::in_trash( $variation ) ) {
				continue;
			}
			$config = ProductConfig::stored_on( $variation );
			if ( ! $config->override || ! $config->personalise ) {
				continue;
			}
			foreach ( $config->sides as $side ) {
				if ( $side->template_base_id === $template->base_id && ! $side->pinned && $side->template_version !== $template->version ) {
					$ids[] = $variation->get_id();
					break;
				}
			}
		}
		return $ids;
	}

	/**
	 * @param int               $product_id A product or variation.
	 * @param array<string,int> $live       The live templates' versions, by base id.
	 * @return string CHECKED, NOT_ASKED or SLOWED_DOWN.
	 * @phpstan-return self::CHECKED|self::NOT_ASKED|self::SLOWED_DOWN
	 */
	private function recheck_one( int $product_id, array $live ): string {
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			return self::CHECKED;
		}
		if ( TrashedProducts::in_trash( $product ) ) {
			// Not checked while nobody can buy it: restoring it checks it again.
			$this->notice->forget( $product_id );
			return self::CHECKED;
		}
		$document = ProductConfig::document_on( $product );
		if ( null === $document ) {
			// A variation following its product's configuration still sells a variant of it.
			return $this->refresh_variants( $product );
		}
		$stored  = ProductConfig::from_json( $document );
		$checked = $this->linker->recheck( $stored, $live );
		if ( InvalidReason::CHECK_FAILED === $checked->invalid_reason ) {
			return ErrorCode::RATE_LIMITED === $checked->invalid_detail?->code ? self::SLOWED_DOWN : self::NOT_ASKED;
		}
		if ( $checked->to_array() !== $stored->to_array() ) {
			try {
				if ( ! $checked->replace_on( $product, $document ) ) {
					// Saved meanwhile: that configuration is newer, and its save raised its notice.
					return self::CHECKED;
				}
			} catch ( LogicException $e ) {
				// A newer plugin version's configuration is not this one's to change.
				return self::CHECKED;
			}
			// Read again as stored: an accept is kept only for what the new configuration warns of.
			$fresh = wc_get_product( $product_id );
			LowResolutionWarnings::prune( $fresh instanceof WC_Product ? $fresh : $product );
		}
		$this->notice->note( $product, $checked );
		if ( null !== $this->layout_sets ) {
			// A page layout a save left without its side's values is not sold until its set is made.
			$fresh = wc_get_product( $product_id );
			$this->layout_sets->retry( $fresh instanceof WC_Product ? $fresh : $product );
		}
		return $this->refresh_variants( $product );
	}

	/**
	 * Resolves the blank variants of a product's variations, or of a
	 * variation, again: a variant retired or added in Pixonaut changes which
	 * variations can be bought.
	 *
	 * @param WC_Product $product A product or variation.
	 * @return string CHECKED, NOT_ASKED or SLOWED_DOWN.
	 * @phpstan-return self::CHECKED|self::NOT_ASKED|self::SLOWED_DOWN
	 */
	private function refresh_variants( WC_Product $product ): string {
		try {
			$this->variants->refresh( $product );
		} catch ( ApiException $e ) {
			if ( ErrorCode::RATE_LIMITED !== $e->errorCode ) {
				return self::NOT_ASKED;
			}
			$this->retry_after = $e->retryAfter;
			return self::SLOWED_DOWN;
		}
		// What the printshop charges may have changed with the blank.
		if ( null !== $this->rechecked ) {
			( $this->rechecked )( $product );
		}
		return self::CHECKED;
	}

	/**
	 * Queues a re-check of products or variations Pixonaut could not check
	 * as they were saved, so their reason clears once it can: once Pixonaut
	 * takes checks again when it asked the store to hold them, else a
	 * minute on. One already waiting in a re-check is not queued again.
	 *
	 * @param int[] $product_ids Products and variations.
	 * @phpstan-param list<int> $product_ids
	 * @return bool Whether the re-check is queued.
	 */
	public static function queue_soon( array $product_ids ): bool {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return false;
		}
		return self::enqueue( $product_ids, 1, self::slowed_delay( null ), new WcLogger() );
	}

	/**
	 * Logs products not re-checked, and queues them again while they have attempts left.
	 *
	 * @param int[]  $product_ids Products and variations.
	 * @param int    $attempt     The attempt that failed.
	 * @param string $why         What went wrong.
	 * @phpstan-param list<int> $product_ids
	 */
	private function failed( array $product_ids, int $attempt, string $why ): void {
		$this->logger->error(
			'Products not re-checked: ' . $why,
			array(
				'products' => $product_ids,
				'attempt'  => $attempt,
			)
		);
		if ( $attempt < self::MAX_ATTEMPTS ) {
			$this->queue_batch( $product_ids, $attempt + 1, self::RETRY_BASE_SECONDS * 2 ** ( $attempt - 1 ), $this->priority );
		}
	}

	/**
	 * Queues products Pixonaut refused for too many requests again, at the
	 * same attempt, after the wait it asked for: for this request, or for
	 * every check (`CheckSlowdown`), whichever is longer; its window's
	 * length when it named none.
	 *
	 * @param int[]    $product_ids Products and variations not checked.
	 * @param int      $attempt     The attempt they were refused in, which they keep.
	 * @param int|null $retry_after Seconds Pixonaut asked the store to wait, when it said.
	 * @phpstan-param list<int> $product_ids
	 * @throws RuntimeException When they could not be queued: the job fails, so it is not taken as done.
	 */
	private function later( array $product_ids, int $attempt, ?int $retry_after ): void {
		$this->logger->warning(
			'Products re-checked later: Pixonaut asked the store to send fewer requests.',
			array(
				'products'    => $product_ids,
				'attempt'     => $attempt,
				'retry_after' => $retry_after,
			)
		);
		if ( ! $this->queue_batch( $product_ids, $attempt, self::slowed_delay( $retry_after ), $this->priority ) ) {
			throw new RuntimeException( 'The re-check of products refused for too many requests could not be queued again.' );
		}
	}

	/**
	 * Seconds to wait before checking again after Pixonaut refused for too
	 * many requests: the longer of the wait it named for the request and
	 * the one every check holds for, else its window's length. Never 0,
	 * which would run the check at once.
	 *
	 * @param int|null $retry_after Seconds Pixonaut asked to wait for the request, when it said.
	 */
	private static function slowed_delay( ?int $retry_after ): int {
		$delay = max( CheckSlowdown::seconds_left(), $retry_after ?? 0 );
		return $delay > 0 ? $delay : self::RETRY_BASE_SECONDS;
	}

	/**
	 * @param int[]    $product_ids Products and variations.
	 * @param int      $attempt     Which attempt.
	 * @param int      $delay       Seconds to wait; 0 to run as soon as possible.
	 * @param int|null $priority    A retry's priority: the one the re-check running was queued at. Null for a new re-check, whichever job ran before it in this request.
	 * @phpstan-param list<int> $product_ids
	 * @return bool False when the job could not be queued.
	 */
	private function queue_batch( array $product_ids, int $attempt, int $delay, ?int $priority ): bool {
		return self::enqueue( $product_ids, $attempt, $delay, $this->logger, $priority );
	}

	/**
	 * Queues the re-check of the products not already waiting in one.
	 *
	 * @param int[]           $product_ids Products and variations.
	 * @param int             $attempt     Which attempt.
	 * @param int             $delay       Seconds to wait; 0 to run as soon as possible.
	 * @param LoggerInterface $logger      Logs a job that could not be queued.
	 * @param int|null        $priority    Where the job stands in the queue, ahead of any waiting one that names the products; null queues only those not waiting already, at `PRIORITY`.
	 * @phpstan-param list<int> $product_ids
	 * @return bool False when the job could not be queued.
	 */
	private static function enqueue( array $product_ids, int $attempt, int $delay, LoggerInterface $logger, ?int $priority = null ): bool {
		if ( null === $priority ) {
			$product_ids = PendingRechecks::not_waiting( $product_ids );
		}
		if ( array() === $product_ids ) {
			return true;
		}
		$priority ??= self::PRIORITY;

		$args   = array(
			'product_ids' => $product_ids,
			'attempt'     => $attempt,
		) + ( self::PRIORITY === $priority ? array() : array( 'priority' => $priority ) );
		$queued = 0 === $delay
			? as_enqueue_async_action( self::ACTION, $args, self::GROUP, false, $priority )
			: as_schedule_single_action( time() + $delay, self::ACTION, $args, self::GROUP, false, $priority );
		if ( 0 === $queued ) {
			$logger->error( 'The re-check of products could not be queued.', array( 'products' => $product_ids ) );
			return false;
		}
		PendingRechecks::mark( $product_ids, $queued );
		return true;
	}

	/**
	 * Queues the queueing job for the first page of products.
	 *
	 * @param string $by  What the products use.
	 * @param string $key Which template or blank.
	 * @throws RuntimeException When the job could not be queued.
	 */
	private function queue_first( string $by, string $key ): void {
		if ( ! $this->queue_after( $by, $key, 0 ) ) {
			throw new RuntimeException( 'The re-check of products could not be queued.' );
		}
	}

	/**
	 * Queues the queueing job for the page of products after $after.
	 *
	 * @param string $by    What the products use.
	 * @param string $key   Which template or blank.
	 * @param int    $after The product id the page starts after.
	 * @return bool False when the job could not be queued.
	 */
	private function queue_after( string $by, string $key, int $after ): bool {
		$args = array(
			'by'    => $by,
			'key'   => $key,
			'after' => $after,
		);
		if ( WaitingJob::exists( self::QUEUE_ACTION, $args, self::GROUP ) ) {
			return true;
		}
		if ( 0 === as_enqueue_async_action( self::QUEUE_ACTION, $args, self::GROUP, false, self::PRIORITY ) ) {
			$this->logger->error( 'The re-check of products could not be queued.', $args );
			return false;
		}
		return true;
	}
}
