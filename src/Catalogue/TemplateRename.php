<?php
/**
 * Moves the store's references to a template whose id Pixonaut changed.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use LogicException;
use Pixonaut\WooCommerce\Fulfilment\LineState;
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
 * new id wherever it held the old: each product's and variation's sides and
 * the layout adjustments of their standing rows, with their index, and each
 * order line's sides. Versions, pins and everything else stay as they were;
 * Pixonaut is not asked.
 *
 * Cart lines are kept in customers' sessions, out of reach: each takes the
 * new id when its cart is next read (`Cart\CartTemplateRenames`).
 *
 * Delivered twice, or for an id no product uses, it changes nothing more.
 */
final class TemplateRename {

	public const TYPE = 'template.renamed';

	/**
	 * Products, variations and order lines read per query.
	 */
	public const PAGE = 100;

	/**
	 * @param LoggerInterface $logger Logs an event naming no ids, and a configuration from a newer plugin version.
	 */
	public function __construct( private readonly LoggerInterface $logger ) {
	}

	/**
	 * @return array<string, callable(Event): void> The handler, by event type.
	 */
	public function handlers(): array {
		return array( self::TYPE => array( $this, 'handle' ) );
	}

	/**
	 * @param Event $event `template.renamed`, with `oldId` and `newId`.
	 * @throws RuntimeException When a product or line changed while it was rewritten; the event is handled again later.
	 */
	public function handle( Event $event ): void {
		$from = Payload::string( $event, 'oldId' );
		$to   = Payload::string( $event, 'newId' );
		if ( null === $from || null === $to || $from === $to ) {
			$this->logger->warning( 'A template.renamed event named no old and new template id; it was ignored.', array( 'event' => $event->id ) );
			return;
		}
		TemplateRenames::record( $from, $to );
		$missed = $this->rename_products( $from, $to ) + $this->rename_lines( $from, $to );
		if ( $missed > 0 ) {
			throw new RuntimeException( sprintf( '%d products or order lines on template %s changed while its id was moved to %s.', $missed, $from, $to ) );
		}
	}

	/**
	 * Names `$to` on every product and variation on `$from`.
	 *
	 * @param string $from The template's old base id.
	 * @param string $to   Its new base id.
	 * @return int How many changed while they were rewritten.
	 */
	private function rename_products( string $from, string $to ): int {
		$missed = 0;
		$after  = 0;
		do {
			$ids  = ProductIndex::using_template( $from, $after, self::PAGE );
			$read = count( $ids );
			foreach ( $ids as $id ) {
				$product = wc_get_product( $id );
				if ( $product instanceof WC_Product && ! $this->rename_product( $product, $from, $to ) ) {
					++$missed;
				}
			}
			$after = 0 === $read ? $after : $ids[ $read - 1 ];
		} while ( self::PAGE === $read );
		return $missed;
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
	 * Names `$to` on every order line's sides on `$from`.
	 *
	 * @param string $from The template's old base id.
	 * @param string $to   Its new base id.
	 * @return int How many changed while they were rewritten.
	 */
	private function rename_lines( string $from, string $to ): int {
		$missed = 0;
		$after  = 0;
		do {
			$page = LineState::for_template( $from, $after, self::PAGE );
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
			$after = $page['last'];
		} while ( $after > 0 );
		return $missed;
	}
}
