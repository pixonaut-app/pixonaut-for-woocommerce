<?php
/**
 * Moves the store's references to a template whose id Pixonaut changed.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages here are reasons and log text, never printed as they are: whoever shows one escapes it.

use LogicException;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Schema;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Webhooks\Event;
use Pixonaut\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Pixonaut\WooCommerce\Webhooks\Payload;
use RuntimeException;
use WC_Product;

/**
 * `template.renamed`: Pixonaut changed a published template's base id, in
 * every version. It keeps answering the old id, and moves the designs made
 * under it to the new one, so a design a customer makes from now on names
 * the new id. The store notes the change (`TemplateRenames`) and names the
 * template's id now wherever it held the old: each product's and variation's
 * sides and the layout adjustments of their standing rows, with their index,
 * and each order line's sides. Versions, pins and everything else stay as
 * they were; Pixonaut is not asked.
 *
 * The event's job moves the first page of products and of order lines; a
 * full page queues a job for the pages after it. A product or line saved
 * while it was moved is moved by a job queued again later, from the start,
 * up to MAX_ATTEMPTS times in all. Every job moves to `TemplateRenames::current()`,
 * so one running after a later change moves straight to the last id.
 *
 * Cart lines are kept in customers' sessions, out of reach: each takes the
 * new id when its cart is next read (`Cart\CartTemplateRenames`).
 *
 * Delivered twice, late, or for an id no product uses, it changes nothing more.
 */
final class TemplateRename {

	public const TYPE = 'template.renamed';

	/**
	 * Products, variations and order lines read per query, and moved per job.
	 */
	public const PAGE = 100;

	/**
	 * Action Scheduler hook of the job moving the pages after the first, with
	 * the old id, the product and order item ids the pages start after, and
	 * the attempt as its arguments.
	 */
	public const ACTION = 'pixonaut_wc_template_rename';

	public const GROUP = Schema::ACTION_GROUP;

	/**
	 * A cursor's value once every page it pages through was moved.
	 */
	public const DONE = -1;

	/**
	 * Attempts at moving what was saved meanwhile, the first one included.
	 */
	public const MAX_ATTEMPTS = ProductRecheck::MAX_ATTEMPTS;

	/**
	 * Wait before the second attempt; it doubles with every attempt after.
	 */
	public const RETRY_BASE_SECONDS = ProductRecheck::RETRY_BASE_SECONDS;

	/**
	 * Arguments `move()` takes.
	 */
	private const MOVE_ARGS = 4;

	/**
	 * @param LoggerInterface $logger Logs an event naming no ids, a configuration from a newer plugin version, and a job that could not be queued or gave up.
	 */
	public function __construct( private readonly LoggerInterface $logger ) {
	}

	public function register(): void {
		add_action( self::ACTION, array( $this, 'move' ), 10, self::MOVE_ARGS );
	}

	/**
	 * @return array<string, callable(Event): void> The handler, by event type.
	 */
	public function handlers(): array {
		return array( self::TYPE => array( $this, 'handle' ) );
	}

	/**
	 * Notes the change, and moves the first page of what used the old id.
	 *
	 * @param Event $event `template.renamed`, with `oldId`, `newId` and `renamedAt`.
	 * @throws RuntimeException When the change could not be noted, or the pages after or a retry could not be queued; the event is handled again later.
	 */
	public function handle( Event $event ): void {
		$from = Payload::string( $event, 'oldId' );
		$to   = Payload::string( $event, 'newId' );
		if ( null === $from || null === $to || $from === $to ) {
			$this->logger->warning( 'A template.renamed event named no old and new template id; it was ignored.', array( 'event' => $event->id ) );
			return;
		}
		TemplateRenames::record( $from, $to, Payload::int( $event, 'renamedAt' ) ?? $event->createdAt );
		$this->move( $from, 0, 0, 1 );
	}

	/**
	 * The job: moves a page of products and a page of order lines from
	 * `$from` to the template's id now, then queues the pages after them,
	 * and a retry of what was saved meanwhile.
	 *
	 * @param string     $from           The template's old base id.
	 * @param int|string $products_after The product id the page starts after; 0 for the first, DONE when every page was moved.
	 * @param int|string $lines_after    The order item id the page starts after; 0 for the first, DONE when every page was moved.
	 * @param int|string $attempt        Which attempt this is, from 1.
	 * @throws RuntimeException When the pages after or a retry could not be queued.
	 */
	public function move( string $from, int|string $products_after, int|string $lines_after, int|string $attempt = 1 ): void {
		$to = TemplateRenames::current( $from );
		if ( $to === $from ) {
			// The template took its old id back: nothing still on it is out of date.
			return;
		}
		$products_after = (int) $products_after;
		$lines_after    = (int) $lines_after;
		$attempt        = max( 1, (int) $attempt );
		$missed         = 0;
		if ( self::DONE !== $products_after ) {
			$moved          = $this->rename_products( $from, $to, $products_after );
			$missed        += $moved['missed'];
			$products_after = $moved['next'];
		}
		if ( self::DONE !== $lines_after ) {
			$moved       = $this->rename_lines( $from, $to, $lines_after );
			$missed     += $moved['missed'];
			$lines_after = $moved['next'];
		}
		$queued = true;
		if ( self::DONE !== $products_after || self::DONE !== $lines_after ) {
			$queued = $this->queue( $from, $products_after, $lines_after, $attempt, 0 );
		}
		if ( $missed > 0 ) {
			$queued = $this->retry( $from, $to, $missed, $attempt ) && $queued;
		}
		if ( ! $queued ) {
			throw new RuntimeException( sprintf( 'Moving template %s to %s could not be queued.', $from, $to ) );
		}
	}

	/**
	 * Names `$to` on a page of the products and variations on `$from`.
	 *
	 * @param string $from  The template's old base id.
	 * @param string $to    Its id now.
	 * @param int    $after The product id the page starts after.
	 * @return array{missed: int, next: int} How many changed while they were rewritten, and where the next page starts, DONE when none follows.
	 */
	private function rename_products( string $from, string $to, int $after ): array {
		$missed = 0;
		$ids    = ProductIndex::using_template( $from, $after, self::PAGE );
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( $product instanceof WC_Product && ! $this->rename_product( $product, $from, $to ) ) {
				++$missed;
			}
		}
		return array(
			'missed' => $missed,
			'next'   => self::PAGE === count( $ids ) ? $ids[ self::PAGE - 1 ] : self::DONE,
		);
	}

	/**
	 * @param WC_Product $product A product or variation indexed under `$from`.
	 * @param string     $from    The template's old base id.
	 * @param string     $to      Its new base id.
	 * @return bool False when it was saved meanwhile, and so not rewritten.
	 */
	private function rename_product( WC_Product $product, string $from, string $to ): bool {
		$document = ProductConfig::document_on( $product );
		if ( null === $document ) {
			// An index row with no configuration: the configuration is the source.
			ProductIndex::rewrite( $product->get_id(), null );
			return true;
		}
		try {
			return ProductConfig::from_json( $document )->with_template_renamed( $from, $to )->replace_on( $product, $document );
		} catch ( LogicException $e ) {
			$this->logger->warning(
				sprintf( 'Product %d keeps template %s: its Pixonaut configuration comes from a newer plugin version.', $product->get_id(), $from ),
				array( 'product' => $product->get_id() )
			);
			return true;
		}
	}

	/**
	 * Names `$to` on a page of the order lines one of whose sides is on `$from`.
	 *
	 * @param string $from  The template's old base id.
	 * @param string $to    Its id now.
	 * @param int    $after The order item id the page starts after.
	 * @return array{missed: int, next: int} How many changed while they were rewritten, and where the next page starts, DONE when none follows.
	 */
	private function rename_lines( string $from, string $to, int $after ): array {
		$missed = 0;
		$page   = LineState::for_template( $from, $after, self::PAGE );
		foreach ( $page['lines'] as $line ) {
			try {
				if ( $line->rename_template( $from, $to ) && ! $line->save() ) {
					++$missed;
				}
			} catch ( LogicException $e ) {
				$this->logger->warning(
					sprintf( 'Order item %d keeps template %s: its Pixonaut line comes from a newer plugin version.', $line->item()->get_id(), $from ),
					array( 'item' => $line->item()->get_id() )
				);
			}
		}
		return array(
			'missed' => $missed,
			'next'   => $page['more'] ? $page['last'] : self::DONE,
		);
	}

	/**
	 * Queues moving everything still on `$from` again later, while attempts are left.
	 *
	 * @param string $from    The template's old base id.
	 * @param string $to      Its id now.
	 * @param int    $missed  How many products and lines changed while they were rewritten.
	 * @param int    $attempt The attempt that missed them.
	 * @return bool False when the retry could not be queued.
	 */
	private function retry( string $from, string $to, int $missed, int $attempt ): bool {
		if ( $attempt >= self::MAX_ATTEMPTS ) {
			$this->logger->error(
				sprintf( '%d products or order lines keep template %s: they kept changing while its id was moved to %s.', $missed, $from, $to ),
				array( 'attempt' => $attempt )
			);
			return true;
		}
		return $this->queue( $from, 0, 0, $attempt + 1, self::RETRY_BASE_SECONDS * 2 ** ( $attempt - 1 ) );
	}

	/**
	 * @param string $from           The template's old base id.
	 * @param int    $products_after Where the products' page starts; DONE for none.
	 * @param int    $lines_after    Where the order lines' page starts; DONE for none.
	 * @param int    $attempt        Which attempt.
	 * @param int    $delay          Seconds to wait; 0 to run as soon as possible.
	 * @return bool False when the job could not be queued.
	 */
	private function queue( string $from, int $products_after, int $lines_after, int $attempt, int $delay ): bool {
		$args = array(
			'from'           => $from,
			'products_after' => $products_after,
			'lines_after'    => $lines_after,
			'attempt'        => $attempt,
		);
		if ( is_int( as_next_scheduled_action( self::ACTION, $args, self::GROUP ) ) ) {
			return true;
		}
		$queued = 0 === $delay
			? as_enqueue_async_action( self::ACTION, $args, self::GROUP )
			: as_schedule_single_action( time() + $delay, self::ACTION, $args, self::GROUP );
		if ( 0 === $queued ) {
			$this->logger->error( sprintf( 'Moving template %s could not be queued.', $from ), $args );
			return false;
		}
		return true;
	}
}
