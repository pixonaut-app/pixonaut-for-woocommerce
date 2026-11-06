<?php
/**
 * Checks products again when their templates or blanks change in Pixonaut.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use LogicException;
use Pixonaut\WooCommerce\Admin\InvalidProductNotice;
use Pixonaut\WooCommerce\Admin\TrashedProducts;
use Pixonaut\WooCommerce\Api\Catalogue;
use Pixonaut\WooCommerce\Schema;
use Pixonaut\WooCommerce\Storefront\KeptDesignerLinks;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
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
 * Scheduler job each. A product that cannot be re-checked because Pixonaut
 * did not answer keeps its configuration and is tried again later, up to
 * MAX_ATTEMPTS times in all; Pixonaut being away never makes a product
 * unsellable. A product the re-check finds unsellable raises its notice, and
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
	 * Wait before the second attempt; it doubles with every attempt after.
	 */
	public const RETRY_BASE_SECONDS = 60;

	/**
	 * Option holding whether every product's variant waits to be resolved.
	 */
	public const BACKFILL_OPTION = Schema::VARIANTS_BACKFILL_OPTION;

	/**
	 * BACKFILL_OPTION's value while the re-check of every product waits to be queued.
	 */
	public const BACKFILL_PENDING = '1';

	/**
	 * @param ProductLinker        $linker  Checks a configuration with Pixonaut.
	 * @param Catalogue            $catalogue Reads which templates are live.
	 * @param InvalidProductNotice $notice  Raises or clears a product's notice.
	 * @param LoggerInterface      $logger  Logs a re-check that failed and a job that could not be queued.
	 * @param ProductVariants      $variants Resolves each variation's blank variant again.
	 * @param KeptDesignerLinks    $links   Forgets the designer links Pixonaut no longer lists.
	 */
	public function __construct(
		private readonly ProductLinker $linker,
		private readonly Catalogue $catalogue,
		private readonly InvalidProductNotice $notice,
		private readonly LoggerInterface $logger,
		private readonly ProductVariants $variants,
		private readonly KeptDesignerLinks $links,
	) {
	}

	public function register(): void {
		add_action( 'pixonaut_wc_events_baseline_set', array( $this, 'recheck_all' ), 10, 0 );
		add_action( self::QUEUE_ACTION, array( $this, 'queue_page' ), 10, 3 );
		add_action( self::ACTION, array( $this, 'recheck' ), 10, 2 );
		add_action( 'action_scheduler_init', array( $this, 'queue_backfill' ) );
	}

	/**
	 * Queues the re-check of every product an upgrade asked for, and only
	 * then forgets the ask: one that could not be queued is logged and
	 * tried again on the next request.
	 */
	public function queue_backfill(): void {
		if ( self::BACKFILL_PENDING !== get_option( self::BACKFILL_OPTION ) ) {
			return;
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
			ProductPictures::redraw_all();
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
			$this->queue_batch( $batch, 1, 0 );
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
			$queued = $this->queue_batch( $batch, 1, 0 ) && $queued;
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
	 * about are tried again later while they have attempts left.
	 *
	 * @param array<int|string> $product_ids Products and variations.
	 * @param int|string        $attempt     Which attempt this is, from 1.
	 * @throws ApiException When Pixonaut could not list the live templates; the batch is tried again later.
	 */
	public function recheck( array $product_ids, int|string $attempt = 1 ): void {
		$product_ids = array_values( array_map( 'intval', $product_ids ) );
		$attempt     = max( 1, (int) $attempt );
		try {
			$live = $this->catalogue->live_versions();
		} catch ( ApiException $e ) {
			$this->failed( $product_ids, $attempt, $e->getMessage() );
			throw $e;
		}

		$again = array();
		foreach ( $product_ids as $product_id ) {
			if ( ! $this->recheck_one( $product_id, $live ) ) {
				$again[] = $product_id;
			}
			$this->links->prune( $product_id );
		}
		if ( array() !== $again ) {
			$this->failed( $again, $attempt, 'Pixonaut could not check the configuration.' );
		}
	}

	/**
	 * @param int               $product_id A product or variation.
	 * @param array<string,int> $live       The live templates' versions, by base id.
	 * @return bool False when Pixonaut could not be asked, so nothing was stored.
	 */
	private function recheck_one( int $product_id, array $live ): bool {
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			return true;
		}
		if ( TrashedProducts::in_trash( $product ) ) {
			// Not checked while nobody can buy it: restoring it checks it again.
			$this->notice->forget( $product_id );
			return true;
		}
		$document = ProductConfig::document_on( $product );
		if ( null === $document ) {
			// A variation following its product's configuration still sells a variant of it.
			return $this->refresh_variants( $product );
		}
		$stored  = ProductConfig::from_json( $document );
		$checked = $this->linker->recheck( $stored, $live );
		if ( InvalidReason::CHECK_FAILED === $checked->invalid_reason ) {
			return false;
		}
		if ( $checked->to_array() !== $stored->to_array() ) {
			try {
				if ( ! $checked->replace_on( $product, $document ) ) {
					// Saved meanwhile: that configuration is newer, and its save raised its notice.
					return true;
				}
			} catch ( LogicException $e ) {
				// A newer plugin version's configuration is not this one's to change.
				return true;
			}
		}
		$this->notice->note( $product, $checked );
		return $this->refresh_variants( $product );
	}

	/**
	 * Resolves the blank variants of a product's variations, or of a
	 * variation, again: a variant retired or added in Pixonaut changes which
	 * variations can be bought.
	 *
	 * @param WC_Product $product A product or variation.
	 * @return bool False when Pixonaut could not be asked, so nothing was stored.
	 */
	private function refresh_variants( WC_Product $product ): bool {
		try {
			$this->variants->refresh( $product );
		} catch ( ApiException $e ) {
			return false;
		}
		return true;
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
			$this->queue_batch( $product_ids, $attempt + 1, self::RETRY_BASE_SECONDS * 2 ** ( $attempt - 1 ) );
		}
	}

	/**
	 * @param int[] $product_ids Products and variations.
	 * @param int   $attempt     Which attempt.
	 * @param int   $delay       Seconds to wait; 0 to run as soon as possible.
	 * @phpstan-param list<int> $product_ids
	 * @return bool False when the job could not be queued.
	 */
	private function queue_batch( array $product_ids, int $attempt, int $delay ): bool {
		$args = array(
			'product_ids' => $product_ids,
			'attempt'     => $attempt,
		);
		if ( self::is_pending( self::ACTION, $args ) ) {
			return true;
		}
		$queued = 0 === $delay
			? as_enqueue_async_action( self::ACTION, $args, self::GROUP )
			: as_schedule_single_action( time() + $delay, self::ACTION, $args, self::GROUP );
		if ( 0 === $queued ) {
			$this->logger->error( 'The re-check of products could not be queued.', array( 'products' => $product_ids ) );
			return false;
		}
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
		if ( self::is_pending( self::QUEUE_ACTION, $args ) ) {
			return true;
		}
		if ( 0 === as_enqueue_async_action( self::QUEUE_ACTION, $args, self::GROUP ) ) {
			$this->logger->error( 'The re-check of products could not be queued.', $args );
			return false;
		}
		return true;
	}

	/**
	 * Whether the same job waits to run. One already running is not enough:
	 * it may have read Pixonaut before the change that queues this one.
	 *
	 * @param string              $hook The job's hook.
	 * @param array<string,mixed> $args Its arguments.
	 */
	private static function is_pending( string $hook, array $args ): bool {
		return is_int( as_next_scheduled_action( $hook, $args, self::GROUP ) );
	}
}
