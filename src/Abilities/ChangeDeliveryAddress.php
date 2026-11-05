<?php
/**
 * The ability that changes where a personalised order ships.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use Pixonaut\WooCommerce\Admin\OrderBox;
use Pixonaut\WooCommerce\Fulfilment\DeliveryChange;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Fulfilment\OrderAddress;
use WC_Order;
use WC_Order_Item_Product;
use WP_Error;
use WP_Http;

/**
 * Changes an order's shipping address as saving it on the order screen
 * does: the order is saved with the new address, which schedules the
 * change for each line that has a printshop order (`DeliveryChange`), and
 * a change whose answer never came, or that the store could not send, is
 * sent again. What the printshop made of it shows on each line's
 * `delivery` once Pixonaut answered. A line whose change Action Scheduler
 * would not store is an error that says the address was saved. Whoever may
 * act on a line on the order screen may change it.
 */
final class ChangeDeliveryAddress implements Ability {

	/**
	 * The shipping address fields the ability takes, each with an
	 * `WC_Order::set_shipping_<field>()` setter.
	 */
	public const FIELDS = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone' );

	/**
	 * Prefix of an error's code.
	 */
	private const ERROR_PREFIX = 'pixonaut_wc_delivery_';

	/**
	 * @param DeliveryChange $delivery Sends a changed address to a line's printshop order.
	 * @param LineView       $view     Shows a line.
	 */
	public function __construct(
		private readonly DeliveryChange $delivery,
		private readonly LineView $view,
	) {
	}

	public function name(): string {
		return 'change-delivery-address';
	}

	public function definition(): array {
		$fields = array();
		foreach ( self::FIELDS as $field ) {
			$fields[ $field ] = array( 'type' => 'string' );
		}
		$fields['country']['description'] = __( 'The country\'s two-letter ISO code, e.g. `SE`.', 'pixonaut-for-woocommerce' );
		return array(
			/* translators: Name of an ability an AI assistant or an MCP client can use on the store. */
			'label'               => __( 'Change a personalised order\'s delivery address', 'pixonaut-for-woocommerce' ),
			'description'         => __( 'Changes the shipping address of an order with personalised lines and sends it to the printshop for each line already ordered, as saving the order on its screen does. Only the fields given change; with none, a change the printshop never answered is sent again. Each line\'s `delivery` then says where the change stands.', 'pixonaut-for-woocommerce' ),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'order_id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'address'  => array(
						'type'                 => 'object',
						'description'          => __( 'The shipping address fields to change.', 'pixonaut-for-woocommerce' ),
						'properties'           => $fields,
						'additionalProperties' => false,
						'default'              => array(),
					),
				),
				'required'             => array( 'order_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'order_id' => array( 'type' => 'integer' ),
					'sending'  => array(
						'type'        => 'array',
						'description' => __( 'The `item_id` of each line whose change is scheduled to go to the printshop. A line whose printshop order has the address already, or is being told it, is not listed.', 'pixonaut-for-woocommerce' ),
						'items'       => array( 'type' => 'integer' ),
					),
					'lines'    => array(
						'type'  => 'array',
						'items' => LineView::schema(),
					),
				),
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => static fn (): bool => current_user_can( OrderBox::CAPABILITY ),
			'annotations'         => array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => true,
			),
		);
	}

	/**
	 * @param mixed $input `order_id` and `address`.
	 * @return array{order_id: int, sending: list<int>, lines: list<array<string, mixed>>}|WP_Error
	 */
	public function execute( mixed $input = null ): array|WP_Error {
		$input    = is_array( $input ) ? $input : array();
		$order_id = Abilities::integer( $input, 'order_id', 0 );
		$order    = $order_id > 0 ? wc_get_order( $order_id ) : null;
		$sending  = $order instanceof WC_Order ? self::sendable( $order ) : array();
		if ( ! $order instanceof WC_Order || array() === $sending ) {
			return new WP_Error(
				self::ERROR_PREFIX . 'not_sendable',
				__( 'The order has no personalised line whose printshop order can take a new address: none is ordered yet, or each is finished, being cancelled or erased. A line not yet ordered is placed with the address the order has then; change it with the order itself.', 'pixonaut-for-woocommerce' ),
				array( 'status' => WP_Http::CONFLICT )
			);
		}
		$address = is_array( $input['address'] ?? null ) ? $input['address'] : array();
		foreach ( self::FIELDS as $field ) {
			if ( is_string( $address[ $field ] ?? null ) ) {
				$order->{'set_shipping_' . $field}( sanitize_text_field( $address[ $field ] ) );
			}
		}
		// Saving a changed address schedules the change for each line (DeliveryChange::after_save()).
		$order->save();
		// As Update on the order screen: a change left unanswered or unsent goes again.
		$this->delivery->resume( $order->get_id() );
		$saved    = wc_get_order( $order->get_id() );
		$saved    = $saved instanceof WC_Order ? $saved : $order;
		$queued   = array();
		$unqueued = array();
		$lines    = array();
		foreach ( $saved->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product || ! LineState::is_tracked( $item ) ) {
				continue;
			}
			$line    = LineState::of( $item );
			$lines[] = $this->view->of( $line );
			if ( ! in_array( $item->get_id(), $sending, true ) ) {
				continue;
			}
			if ( DeliveryChange::pending( $item->get_id() ) ) {
				$queued[] = $item->get_id();
			} elseif ( DeliveryChange::unsent( $line, OrderAddress::fingerprint( OrderAddress::of( $saved, $item ) ) ) ) {
				$unqueued[] = $item->get_id();
			}
		}
		if ( array() !== $unqueued ) {
			return new WP_Error(
				self::ERROR_PREFIX . 'not_scheduled',
				__( 'The new address was saved on the order, but it could not be scheduled for the printshop. Save the order on its screen, or run this again, to send it.', 'pixonaut-for-woocommerce' ),
				array(
					'saved'    => true,
					'item_ids' => $unqueued,
					'status'   => WP_Http::INTERNAL_SERVER_ERROR,
				)
			);
		}
		return array(
			'order_id' => $saved->get_id(),
			'sending'  => $queued,
			'lines'    => $lines,
		);
	}

	/**
	 * The order's lines whose printshop order can take a new address.
	 *
	 * @param WC_Order $order The order.
	 * @return list<int> Their item ids.
	 */
	private static function sendable( WC_Order $order ): array {
		$ids = array();
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof WC_Order_Item_Product && LineState::is_tracked( $item ) && DeliveryChange::sendable( LineState::of( $item ) ) ) {
				$ids[] = $item->get_id();
			}
		}
		return $ids;
	}
}
