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
	private const ORDER_STATUSES = array( 'awaiting_prints', 'queued', 'submitted', 'outcome_unknown', 'printed', 'shipped', 'failed', 'cancelled' );

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
	 * `201` of `POST /v1/orders`, and an item of `GET /v1/orders`.
	 *
	 * @param string               $order_id     The order.
	 * @param string[]             $print_ids    Its prints, lead first.
	 * @param string               $external_ref Its ref.
	 * @param array<string, mixed> $overrides    Fields to replace, e.g. `status`.
	 * @phpstan-param list<string> $print_ids
	 *
	 * @return array<string, mixed>
	 */
	public static function order( string $order_id, array $print_ids, string $external_ref, array $overrides = array() ): array {
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
		return $overrides + array(
			'id'                    => $order_id,
			'tenantId'              => self::TENANT_ID,
			'printId'               => $print_ids[0],
			'designId'              => 'd_of_' . $print_ids[0],
			'printIds'              => $print_ids,
			'prints'                => $prints,
			'adapter'               => 'fake',
			'quantity'              => 1,
			'externalRef'           => $external_ref,
			'recipient'             => null,
			'recipientEmailBounced' => false,
			'shippingAddress'       => null,
			'printshopConnectionId' => null,
			'printshopName'         => null,
			'spotOutput'            => 'process',
			'externalOrderRef'      => null,
			'status'                => 'submitted',
			'lastError'             => null,
			'failureKind'           => null,
			'hold'                  => null,
			'tracking'              => null,
			'submittedAt'           => self::CREATED_AT_MS,
			'lastCheckedAt'         => null,
			'updatedAt'             => self::CREATED_AT_MS,
			'replacesOrderId'       => null,
			'replacedByOrderId'     => null,
			'personalDataErasedAt'  => null,
			'blankId'               => null,
			'blank'                 => null,
			'variantRef'            => null,
			'variant'               => null,
			'printshopAsPlaced'     => null,
			'delivery'              => null,
			'shipTo'                => null,
		);
	}

	/**
	 * `200` of `GET /v1/orders/{id}`: the order at the last of $statuses,
	 * having moved through each of them.
	 *
	 * @param string                                                     $order_id     The order.
	 * @param string                                                     $print_id     Its print.
	 * @param string                                                     $external_ref Its ref.
	 * @param string[]                                                   $statuses     Its statuses so far, oldest first.
	 * @param array{carrier?: string, number: string, url?: string}|null $tracking     Its shipment's tracking.
	 * @phpstan-param non-empty-list<string> $statuses
	 *
	 * @return array<string, mixed>
	 */
	public static function order_read( string $order_id, string $print_id, string $external_ref, array $statuses, ?array $tracking = null ): array {
		$history = array();
		foreach ( $statuses as $seq => $status ) {
			$history[] = array(
				'seq'        => $seq + 1,
				'at'         => self::CREATED_AT_MS + $seq,
				'type'       => 0 === $seq ? 'order.submitted' : 'order.status_changed',
				'status'     => $status,
				'reason'     => null,
				'via'        => null,
				'automation' => null,
				'releasedBy' => null,
			);
		}
		return array(
			'status'                => $statuses[ count( $statuses ) - 1 ],
			'tracking'              => $tracking,
			'history'               => $history,
			'retryable'             => false,
			'canRetry'              => false,
			'erasable'              => false,
			'canErase'              => false,
			'statusMoves'           => array(),
			'fallbackSpots'         => array(),
			'varnishLeftOut'        => false,
			'reroutes'              => array(),
			'deliveryChanges'       => array(),
			'deliveryChangeRefusal' => null,
			'approvalHold'          => null,
			'embroideryFee'         => null,
		) + self::order( $order_id, array( $print_id ), $external_ref );
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
		$order['history'] = array(
			array(
				'seq'        => 1,
				'at'         => self::CREATED_AT_MS,
				'type'       => 'order.created',
				'status'     => 'submitted',
				'reason'     => null,
				'via'        => $via,
				'automation' => $automation,
				'releasedBy' => null,
			),
		);
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
		return $overrides + self::order( $order_id, array( 'p_front' ), $external_ref ) + array(
			'history'               => array(),
			'retryable'             => false,
			'canRetry'              => false,
			'erasable'              => false,
			'canErase'              => false,
			'statusMoves'           => array(),
			'fallbackSpots'         => array(),
			'varnishLeftOut'        => false,
			'reroutes'              => array(),
			'deliveryChanges'       => array(),
			'deliveryChangeRefusal' => null,
			'approvalHold'          => null,
			'embroideryFee'         => null,
		);
	}

	/**
	 * `200` of `GET /v1/orders`.
	 *
	 * @param list<array<string, mixed>> $orders From `order()`.
	 *
	 * @return array<string, mixed>
	 */
	public static function orders( array $orders ): array {
		$counts = array_fill_keys( self::ORDER_STATUSES, 0 );
		foreach ( $orders as $order ) {
			++$counts[ $order['status'] ];
		}
		return self::page( 'orders', $orders ) + array( 'counts' => $counts );
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
