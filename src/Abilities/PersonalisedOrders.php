<?php
/**
 * The ability that lists personalised orders.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use Pixonaut\WooCommerce\Admin\OrderBox;
use Pixonaut\WooCommerce\Api\ApiError;
use Pixonaut\WooCommerce\Fulfilment\LineActions;
use Pixonaut\WooCommerce\Fulfilment\LineIndex;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use WC_Order;
use WC_Order_Item_Product;

/**
 * The store's orders with personalised lines, newest first, a page at a
 * time: each line's proofs and where it stands on its way to the printshop,
 * as the order screen's Pixonaut box shows them. Read from the lines as the
 * store keeps them, which Pixonaut's events keep in step; Pixonaut is asked
 * only for one order's lines, for the actions they offer. Whoever may manage
 * the store may list them.
 */
final class PersonalisedOrders implements Ability {

	private const DEFAULT_PER_PAGE = 20;

	/**
	 * Most orders on one page.
	 */
	private const MAX_PER_PAGE = 100;

	/**
	 * @param LineView    $view    Shows a line.
	 * @param LineActions $actions The actions a line offers.
	 */
	public function __construct(
		private readonly LineView $view,
		private readonly LineActions $actions,
	) {
	}

	public function name(): string {
		return 'list-personalised-orders';
	}

	public function definition(): array {
		return array(
			/* translators: Name of an ability an AI assistant or an MCP client can use on the store. */
			'label'               => __( 'List personalised orders', 'pixonaut-for-woocommerce' ),
			'description'         => __( 'Lists the store\'s orders with personalised lines, newest first, with each line\'s proofs and its production status in Pixonaut. Optionally only orders with a line in one state, such as `awaiting_customer` or `needs_attention`, or one order, whose lines then also give the actions `act-on-order-line` can run on them.', 'pixonaut-for-woocommerce' ),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'order_id' => array(
						'type'        => 'integer',
						'description' => __( 'Only this order, with the actions each of its lines offers; left out for all.', 'pixonaut-for-woocommerce' ),
						'minimum'     => 1,
					),
					'state'    => array(
						'type'        => 'string',
						'description' => __( 'Only lines in this state; left out for all.', 'pixonaut-for-woocommerce' ),
						'enum'        => LineState::STATES,
					),
					'before'   => array(
						'type'        => 'integer',
						'description' => __( 'Only orders older than this one: the `next_before` of the page before. Left out for the newest.', 'pixonaut-for-woocommerce' ),
						'minimum'     => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'default' => self::DEFAULT_PER_PAGE,
						'minimum' => 1,
						'maximum' => self::MAX_PER_PAGE,
					),
				),
				'additionalProperties' => false,
				'default'              => array(),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'orders'      => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'order_id'   => array( 'type' => 'integer' ),
								'number'     => array( 'type' => 'string' ),
								'status'     => array(
									'type'        => 'string',
									'description' => __( 'The WooCommerce order status, without `wc-`.', 'pixonaut-for-woocommerce' ),
								),
								'created_at' => array(
									'type'   => 'string',
									'format' => 'date-time',
								),
								'edit_url'   => array( 'type' => 'string' ),
								'lines'      => array(
									'type'  => 'array',
									'items' => LineView::schema(),
								),
							),
						),
					),
					'total'       => array(
						'type'        => 'integer',
						'description' => __( 'How many orders match, on every page.', 'pixonaut-for-woocommerce' ),
					),
					'more'        => array(
						'type'        => 'boolean',
						'description' => __( 'Whether a later page has more.', 'pixonaut-for-woocommerce' ),
					),
					'next_before' => array(
						'type'        => array( 'integer', 'null' ),
						'description' => __( 'The `before` that asks for the next page; null when there is none.', 'pixonaut-for-woocommerce' ),
					),
				),
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => static fn (): bool => current_user_can( OrderBox::CAPABILITY ),
			'annotations'         => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
		);
	}

	/**
	 * A page of the orders the index lists, newest first. The index finds the
	 * orders with a line that reads as the state asked for (`LineIndex`), so
	 * a page is short only at the end.
	 *
	 * @param mixed $input `order_id`, `state`, `before` and `per_page`.
	 * @return array{orders: list<array<string, mixed>>, total: int, more: bool, next_before: int|null}
	 */
	public function execute( mixed $input = null ): array {
		$input    = is_array( $input ) ? $input : array();
		$state    = is_string( $input['state'] ?? null ) && in_array( $input['state'], LineState::STATES, true ) ? $input['state'] : null;
		$order_id = max( 0, Abilities::integer( $input, 'order_id', 0 ) );
		if ( $order_id > 0 ) {
			$order = $this->order( $order_id, $state, true );
			return array(
				'orders'      => null === $order ? array() : array( $order ),
				'total'       => null === $order ? 0 : 1,
				'more'        => false,
				'next_before' => null,
			);
		}
		$before   = max( 0, Abilities::integer( $input, 'before', 0 ) );
		$per_page = min( self::MAX_PER_PAGE, max( 1, Abilities::integer( $input, 'per_page', self::DEFAULT_PER_PAGE ) ) );
		$orders   = array();
		$ids      = LineIndex::order_ids( $state, $before, $per_page );
		while ( array() !== $ids ) {
			foreach ( $ids as $id ) {
				$before = $id;
				$order  = $this->order( $id, $state, false );
				if ( null !== $order ) {
					$orders[] = $order;
				}
			}
			$wanted = $per_page - count( $orders );
			// An order the index lists that cannot be read (deleted meanwhile) is made up for from the orders after it.
			$ids = $wanted > 0 ? LineIndex::order_ids( $state, $before, $wanted ) : array();
		}
		$more = count( $orders ) === $per_page && array() !== LineIndex::order_ids( $state, $before, 1 );
		return array(
			'orders'      => $orders,
			'total'       => LineIndex::count( $state ),
			'more'        => $more,
			'next_before' => $more ? $before : null,
		);
	}

	/**
	 * An order as the ability lists it; null when it has no line in $state.
	 *
	 * @param int         $id      The order.
	 * @param string|null $state   Only lines in this state; null for all.
	 * @param bool        $actions Whether to read the actions each line offers, which may ask Pixonaut.
	 * @return array<string, mixed>|null
	 */
	private function order( int $id, ?string $state, bool $actions ): ?array {
		$order = wc_get_order( $id );
		if ( ! $order instanceof WC_Order ) {
			return null;
		}
		$lines = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product || ! LineState::is_tracked( $item ) ) {
				continue;
			}
			$line = LineState::of( $item );
			if ( null !== $state && $line->state() !== $state ) {
				continue;
			}
			$offered = null;
			if ( $actions ) {
				try {
					list( $line, $offered ) = $this->actions->offered( $line );
				} catch ( ApiError $e ) {
					// Not known while Pixonaut cannot be asked; the line is listed as the store keeps it.
					$offered = null;
				}
			}
			$lines[] = $this->view->of( $line, $offered );
		}
		if ( array() === $lines ) {
			return null;
		}
		return array(
			'order_id'   => $order->get_id(),
			'number'     => (string) $order->get_order_number(),
			'status'     => $order->get_status(),
			'created_at' => (string) $order->get_date_created()?->format( DATE_ATOM ),
			'edit_url'   => $order->get_edit_order_url(),
			'lines'      => $lines,
		);
	}
}
