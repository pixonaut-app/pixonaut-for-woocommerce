<?php
/**
 * Pixonaut's answers to the fulfilment calls, as the spec shapes them.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Support;

final class FulfilmentAnswers {

	public const TENANT_ID = '0b1c2d3e-4f50-4a6b-8c7d-000000000003';

	/**
	 * Epoch milliseconds, as Pixonaut writes times.
	 */
	private const CREATED_AT_MS = 1_800_000_000_000;

	/**
	 * Pixonaut's default page size for lists.
	 */
	private const PAGE_SIZE = 50;

	/**
	 * The statuses `GET /v1/orders` counts its orders by.
	 */
	private const ORDER_STATUSES = array( 'held', 'open', 'partly_shipped', 'shipped', 'needs_attention', 'cancelled', 'done' );

	/**
	 * Milliseconds in a second.
	 */
	private const MS_PER_SECOND = 1000;

	/**
	 * The fields of an order read that are its item's, before orders held items the order's own.
	 */
	private const ITEM_FIELDS = array( 'status', 'quantity', 'variantRef', 'variant', 'printshopConnectionId', 'printshopName', 'lastError', 'failureKind', 'canRetry', 'retryable', 'statusMoves', 'blankId', 'blank', 'prints', 'printIds', 'designId', 'submissions', 'printshopAsPlaced' );

	/**
	 * The fields of an order read a row of `GET /v1/orders` has too.
	 */
	private const ROW_FIELDS = array( 'id', 'tenantId', 'externalRef', 'status', 'hold', 'recipient', 'shippingAddress', 'shipTo', 'personalDataErasedAt', 'createdAt', 'updatedAt' );

	/**
	 * The fields of an order read's item a row's item has too.
	 */
	private const ROW_ITEM_FIELDS = array( 'id', 'externalRef', 'status', 'quantity', 'shippedQuantity', 'designId', 'printIds', 'blankId', 'variantRef', 'variant', 'blank', 'printshopConnectionId', 'printshopName', 'lastError', 'failureKind' );

	/**
	 * Answer of `POST /v1/designs/{id}/approve`.
	 *
	 * @return array<string, mixed>
	 */
	public static function approved(): array {
		return array( 'status' => 'approved' );
	}

	/**
	 * `200` of `GET /v1/designs/{id}`: the design approved at a revision.
	 *
	 * @param string $design_id The design.
	 * @param int    $revision  Its revision.
	 *
	 * @return array<string, mixed>
	 */
	public static function design_approved( string $design_id, int $revision ): array {
		return DesignFixture::design(
			array(
				'id'       => $design_id,
				'status'   => 'approved',
				'revision' => $revision,
			)
		);
	}

	/**
	 * `201` of `POST /v1/orders`: an order of one item, the line's, at the
	 * fields an order of one line had before orders held items. Overrides of
	 * an item's fields (`status`, `quantity`, `lastError`, `canRetry`, …) go
	 * to the item; `tracking` puts the item in a shipped parcel with it; any
	 * other replaces the order's field.
	 *
	 * @param string               $order_id     The order.
	 * @param string[]             $print_ids    Its item's prints, lead first.
	 * @param string               $external_ref The item's ref, and the order's unless `externalRef` is overridden.
	 * @param array<string, mixed> $overrides    Fields to replace, e.g. `status`.
	 * @phpstan-param list<string> $print_ids
	 *
	 * @return array<string, mixed>
	 */
	public static function order( string $order_id, array $print_ids, string $external_ref, array $overrides = array() ): array {
		$item_overrides = array_intersect_key( $overrides, array_flip( self::ITEM_FIELDS ) );
		$tracking       = $overrides['tracking'] ?? null;
		$order_status   = $overrides['orderStatus'] ?? null;
		$overrides      = array_diff_key( $overrides, array_flip( self::ITEM_FIELDS ), array_flip( array( 'tracking', 'orderStatus' ) ) );
		$item           = self::item( self::item_id( $order_id ), $print_ids, $external_ref, $item_overrides );
		$parcels        = null === $tracking ? array() : array( self::parcel( $order_id, $item['id'], $tracking ) );
		return self::order_of_items( $order_id, $external_ref, array( $item ), $overrides + array( 'parcels' => $parcels ) + ( null === $order_status ? array() : array( 'status' => $order_status ) ) );
	}

	/**
	 * The id the fixtures give the item of the order of one item.
	 *
	 * @param string $order_id The order.
	 */
	public static function item_id( string $order_id ): string {
		return 'oi_' . $order_id;
	}

	/**
	 * An order read of several items, from item().
	 *
	 * @param string                     $order_id     The order.
	 * @param string                     $external_ref Its ref.
	 * @param list<array<string, mixed>> $items        Its items.
	 * @param array<string, mixed>       $overrides    Fields of the order to replace.
	 *
	 * @return array<string, mixed>
	 */
	public static function order_of_items( string $order_id, string $external_ref, array $items, array $overrides = array() ): array {
		$printshop_orders = array();
		foreach ( $items as $item ) {
			foreach ( $item['submissions'] as $submission ) {
				$printshop_orders[ $submission['printshopOrderId'] ] = array(
					'id'                    => $submission['printshopOrderId'],
					'printshopConnectionId' => $item['printshopConnectionId'],
					'printshopName'         => $item['printshopName'],
					'adapter'               => 'fake',
					'cancelsItems'          => false,
					'externalOrderRef'      => null,
					'parts'                 => array(),
					'itemIds'               => array_merge( $printshop_orders[ $submission['printshopOrderId'] ]['itemIds'] ?? array(), array( $item['id'] ) ),
					'submittedAt'           => $submission['submittedAt'],
					'lastCheckedAt'         => null,
				);
			}
		}
		return $overrides + array(
			'id'                    => $order_id,
			'tenantId'              => self::TENANT_ID,
			'externalRef'           => $external_ref,
			'status'                => self::order_status( array_column( $items, 'status' ) ),
			'hold'                  => null,
			'recipient'             => null,
			'recipientEmailBounced' => false,
			'shippingAddress'       => null,
			'shipTo'                => null,
			'personalDataErasedAt'  => null,
			'delivery'              => null,
			'deliveryChanges'       => array(),
			'deliveryChangeRefusal' => null,
			'erasable'              => false,
			'canErase'              => false,
			'createdAt'             => self::CREATED_AT_MS,
			'updatedAt'             => self::CREATED_AT_MS,
			'history'               => array(),
			'items'                 => $items,
			'printshopOrders'       => array_values( $printshop_orders ),
			'parcels'               => array(),
		);
	}

	/**
	 * An item of an order read.
	 *
	 * @param string               $item_id      The item.
	 * @param string[]             $print_ids    Its prints, lead first.
	 * @param string               $external_ref The line's ref it was placed under.
	 * @param array<string, mixed> $overrides    Fields to replace, e.g. `status`.
	 * @phpstan-param list<string> $print_ids
	 *
	 * @return array<string, mixed>
	 */
	public static function item( string $item_id, array $print_ids, string $external_ref, array $overrides = array() ): array {
		$prints = array();
		foreach ( $print_ids as $print_id ) {
			$prints[] = array(
				'printId'         => $print_id,
				'designId'        => 'd_of_' . $print_id,
				'placementId'     => null,
				'printedAs'       => null,
				'placementFields' => null,
				'spotOutput'      => 'process',
				'designRevision'  => 1,
				'status'          => 'completed',
			);
		}
		$status = $overrides['status'] ?? 'submitted';
		// An item handed to its printshop was submitted in a printshop order.
		$sent = ! in_array( $status, array( 'held', 'awaiting_prints' ), true );
		return $overrides + array(
			'id'                    => $item_id,
			'externalRef'           => $external_ref,
			'status'                => $status,
			'quantity'              => 1,
			'shippedQuantity'       => null,
			'designId'              => 'd_of_' . $print_ids[0],
			'printIds'              => $print_ids,
			'prints'                => $prints,
			'blankId'               => null,
			'blank'                 => null,
			'variantRef'            => null,
			'variant'               => null,
			'printshopConnectionId' => null,
			'printshopName'         => null,
			'printshopAsPlaced'     => null,
			'spotOutput'            => 'process',
			'fallbackSpots'         => array(),
			'varnishLeftOut'        => false,
			'hold'                  => null,
			'approvalHold'          => null,
			'embroideryFee'         => null,
			'lastError'             => null,
			'failureKind'           => null,
			'retryable'             => false,
			'canRetry'              => false,
			'statusMoves'           => array(),
			'reroutes'              => array(),
			'parcels'               => array(),
			'submissions'           => $sent ? array(
				array(
					'id'               => 'sub_' . $item_id,
					'printshopOrderId' => 'po_' . $item_id,
					'status'           => $status,
					'externalLineRef'  => null,
					'submittedAt'      => self::CREATED_AT_MS,
					'struck'           => false,
				),
			) : array(),
		);
	}

	/**
	 * A shipped parcel holding one item, with its tracking.
	 *
	 * @param string                                                $order_id The order.
	 * @param string                                                $item_id  The item.
	 * @param array{carrier?: string, number: string, url?: string} $tracking Its tracking.
	 *
	 * @return array<string, mixed>
	 */
	public static function parcel( string $order_id, string $item_id, array $tracking ): array {
		return array(
			'id'               => 'parcel_' . $order_id,
			'printshopOrderId' => 'po_' . $item_id,
			'status'           => 'shipped',
			'tracking'         => $tracking,
			'shippedAt'        => gmdate( 'Y-m-d\TH:i:s\Z', intdiv( self::CREATED_AT_MS, self::MS_PER_SECOND ) ),
			'deliveredAt'      => null,
			'items'            => array(
				array(
					'itemId'      => $item_id,
					'externalRef' => null,
					'quantity'    => null,
					'ofQuantity'  => 1,
					'whole'       => true,
				),
			),
		);
	}

	/**
	 * The whole order's status, from its items'.
	 *
	 * @param string[] $statuses Its items' statuses.
	 * @phpstan-param list<string> $statuses
	 */
	private static function order_status( array $statuses ): string {
		$distinct = array_values( array_unique( $statuses ) );
		if ( 1 === count( $distinct ) && in_array( $distinct[0], array( 'held', 'shipped', 'cancelled' ), true ) ) {
			return $distinct[0];
		}
		if ( array() !== array_intersect( $statuses, array( 'failed', 'outcome_unknown' ) ) ) {
			return 'needs_attention';
		}
		return in_array( 'shipped', $statuses, true ) ? 'partly_shipped' : 'open';
	}

	/**
	 * `200` of `GET /v1/orders/{id}`: the order, its item at the last of
	 * $statuses, having moved through each of them.
	 *
	 * @param string                                                     $order_id     The order.
	 * @param string                                                     $print_id     Its item's print.
	 * @param string                                                     $external_ref Its ref.
	 * @param string[]                                                   $statuses     Its item's statuses so far, oldest first.
	 * @param array{carrier?: string, number: string, url?: string}|null $tracking     Its shipment's tracking.
	 * @phpstan-param non-empty-list<string> $statuses
	 *
	 * @return array<string, mixed>
	 */
	public static function order_read( string $order_id, string $print_id, string $external_ref, array $statuses, ?array $tracking = null ): array {
		$history = array();
		foreach ( $statuses as $seq => $status ) {
			$history[] = self::history_entry( $seq + 1, 0 === $seq ? 'item.submitted' : 'item.status_changed', $status, self::item_id( $order_id ) );
		}
		return array( 'history' => $history ) + self::order(
			$order_id,
			array( $print_id ),
			$external_ref,
			array(
				'status'   => $statuses[ count( $statuses ) - 1 ],
				'tracking' => $tracking,
			)
		);
	}

	/**
	 * An entry of an order's `history`.
	 *
	 * @param int                                  $seq        Its place, from 1.
	 * @param string                               $type       Its event's type.
	 * @param string                               $status     The item's status after it.
	 * @param string                               $item_id    The item.
	 * @param string|null                          $via        Who made it.
	 * @param array{id: string, name: string}|null $automation The automation, for `automation`.
	 *
	 * @return array<string, mixed>
	 */
	public static function history_entry( int $seq, string $type, string $status, string $item_id, ?string $via = null, ?array $automation = null ): array {
		return array(
			'seq'        => $seq,
			'at'         => self::CREATED_AT_MS + $seq - 1,
			'type'       => $type,
			'status'     => $status,
			'itemId'     => $item_id,
			'reason'     => null,
			'via'        => $via,
			'automation' => $automation,
			'releasedBy' => null,
		);
	}

	/**
	 * An order read as one someone else placed: its history starts with its
	 * creation by `$via`.
	 *
	 * @param array<string, mixed>                 $order      `GET /v1/orders/{id}`'s answer, from read_order().
	 * @param string                               $via        Who placed it, e.g. `automation` or `member`.
	 * @param array{id: string, name: string}|null $automation The automation, for `automation`.
	 *
	 * @return array<string, mixed>
	 */
	public static function placed_by( array $order, string $via, ?array $automation = null ): array {
		$order['history'] = array( self::history_entry( 1, 'order.created', 'submitted', $order['items'][0]['id'], $via, $automation ) );
		return $order;
	}

	/**
	 * `200` of `GET /v1/orders/{id}`: the order with what the caller may do with it.
	 *
	 * @param string               $order_id     The order.
	 * @param string               $external_ref Its ref.
	 * @param array<string, mixed> $overrides    Fields to replace, e.g. `status`, `canRetry` or `statusMoves`.
	 *
	 * @return array<string, mixed>
	 */
	public static function read_order( string $order_id, string $external_ref, array $overrides = array() ): array {
		return self::order( $order_id, array( 'p_front' ), $external_ref, $overrides );
	}

	/**
	 * `200` of `GET /v1/orders`, its rows made from order reads.
	 *
	 * @param list<array<string, mixed>> $orders From `order()`.
	 *
	 * @return array<string, mixed>
	 */
	public static function orders( array $orders ): array {
		$counts = array_fill_keys( self::ORDER_STATUSES, 0 );
		$rows   = array();
		foreach ( $orders as $order ) {
			++$counts[ $order['status'] ];
			$rows[] = self::row( $order );
		}
		return self::page( 'orders', $rows ) + array( 'counts' => $counts );
	}

	/**
	 * A row of `GET /v1/orders`, from an order read.
	 *
	 * @param array<string, mixed> $order The order read.
	 *
	 * @return array<string, mixed>
	 */
	private static function row( array $order ): array {
		$items   = array();
		$preview = array();
		foreach ( $order['items'] as $item ) {
			$items[]   = array_intersect_key( $item, array_flip( self::ROW_ITEM_FIELDS ) );
			$preview[] = array(
				'itemId'   => $item['id'],
				'designId' => $item['designId'],
				'printId'  => $item['printIds'][0] ?? null,
				'quantity' => $item['quantity'],
				'status'   => $item['status'],
			);
		}
		return array_intersect_key( $order, array_flip( self::ROW_FIELDS ) ) + array(
			'items'          => $items,
			'itemsPreview'   => $preview,
			'itemCount'      => count( $items ),
			'copies'         => array_sum( array_column( $items, 'quantity' ) ),
			'parcelCount'    => count( $order['parcels'] ),
			'parcels'        => array(
				'preparing' => 0,
				'shipped'   => count( $order['parcels'] ),
				'delivered' => 0,
			),
			'printshopNames' => array(),
		);
	}

	/**
	 * @param string             $key   The list's field.
	 * @param list<array<mixed>> $items Its items.
	 *
	 * @return array<string, mixed>
	 */
	private static function page( string $key, array $items ): array {
		return array(
			$key       => $items,
			'total'    => count( $items ),
			'page'     => 1,
			'pageSize' => self::PAGE_SIZE,
		);
	}
}
