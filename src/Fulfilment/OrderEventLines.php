<?php
/**
 * The lines an `order.*` event is about.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Fulfilment;

use Pixonaut\WooCommerce\Connection\Connection;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Webhooks\Event;
use Pixonaut\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Pixonaut\WooCommerce\Webhooks\Payload;

/**
 * Finds a Pixonaut order's lines by the order id they stored, keeping only
 * those of the connected workspace whose `externalRef` agrees with the
 * event's when it carries one. An `item.*` event is about one item, so only
 * the line holding it is kept.
 */
final class OrderEventLines {

	/**
	 * @param Connection      $connection The store's connection; a line of another workspace is never acted on.
	 * @param LoggerInterface $logger     Logs lines left out.
	 */
	public function __construct(
		private readonly Connection $connection,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * @param Event $event An `order.*`, `item.*` or `parcel.*` event.
	 *
	 * @return list<LineState> None when the event carries no `orderId` or the store is not connected.
	 */
	public function of( Event $event ): array {
		$order_id = Payload::string( $event, 'orderId' );
		return null === $order_id ? array() : $this->holding( $order_id, $event );
	}

	/**
	 * The lines holding $order_id that an event about it may change.
	 *
	 * @param string $order_id A Pixonaut order id the event names.
	 * @param Event  $event    An `order.*` event.
	 *
	 * @return list<LineState> None when the store is not connected.
	 */
	public function holding( string $order_id, Event $event ): array {
		return $this->kept( LineState::for_order_id( $order_id ), $event );
	}

	/**
	 * The lines a parcel of the event's order holds, each with its item in the
	 * parcel.
	 *
	 * @param Parcel $parcel The parcel.
	 * @param Event  $event  A `parcel.*` event.
	 *
	 * @return list<array{0: LineState, 1: ParcelItem}> None when the store is not connected.
	 */
	public function in_parcel( Parcel $parcel, Event $event ): array {
		$lines = $this->of( $event );
		$held  = array();
		foreach ( $parcel->items as $item ) {
			foreach ( $lines as $line ) {
				if ( self::holds_item( $line, $item->item_id ) ) {
					$held[] = array( $line, $item );
					break;
				}
			}
		}
		return $held;
	}

	/**
	 * @param LineState[] $lines Lines holding an order the event names.
	 * @param Event       $event An `order.*` event.
	 * @phpstan-param list<LineState> $lines
	 *
	 * @return list<LineState> Those the event may change.
	 */
	private function kept( array $lines, Event $event ): array {
		$item_id = Payload::string( $event, 'itemId' );
		return array_values(
			array_filter(
				$lines,
				fn ( LineState $line ): bool => self::holds_item( $line, $item_id ) && $this->holds( $line, Payload::string( $event, 'externalRef' ), $event->type )
			)
		);
	}

	/**
	 * Whether the line holds the item an event is about.
	 *
	 * @param LineState   $line    A line holding the event's order.
	 * @param string|null $item_id The event's `itemId`; null for an event about the whole order.
	 */
	private static function holds_item( LineState $line, ?string $item_id ): bool {
		$held = $line->ref( LineState::REF_ORDER_ITEM_ID );
		// A line keeping no item id holds an order placed for it alone, whose one item is the line's.
		return null === $item_id || ! is_string( $held ) || $held === $item_id;
	}

	/**
	 * Whether a line holding a Pixonaut order's id is the store's to change:
	 * it belongs to the connected workspace, and its `externalRef`, or that
	 * of the order it took on, agrees with the one Pixonaut reported for the
	 * order, when both are known.
	 *
	 * @param LineState   $line         A line holding the order's id.
	 * @param string|null $external_ref The `externalRef` Pixonaut reported for the order.
	 * @param string      $source       What reported it, an event type or an operation, for the log.
	 */
	public function holds( LineState $line, ?string $external_ref, string $source ): bool {
		$connection = $this->connection->current();
		if ( null === $connection ) {
			return false;
		}
		$tenant = $line->ref( LineState::REF_TENANT_ID );
		if ( null !== $tenant && $tenant !== $connection->tenant_id ) {
			$this->logger->warning( 'Order event ignored: the line belongs to another workspace.', self::context( $line, $source ) );
			return false;
		}
		// An order the line took on may have been placed under another ref than the line's.
		$ref = $line->ref( LineState::REF_ORDER_EXTERNAL_REF ) ?? $line->ref( LineState::REF_EXTERNAL_REF );
		if ( null !== $ref && null !== $external_ref && $ref !== $external_ref ) {
			$this->logger->warning( 'Order event ignored: its externalRef is not the line\'s.', self::context( $line, $source ) );
			return false;
		}
		return true;
	}

	/**
	 * What a log entry about a line left out says.
	 *
	 * @param LineState $line   The line.
	 * @param string    $source What reported the change.
	 *
	 * @return array{item: int, type: string}
	 */
	private static function context( LineState $line, string $source ): array {
		return array(
			'item' => $line->item()->get_id(),
			'type' => $source,
		);
	}
}
