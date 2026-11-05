<?php
/**
 * Adds Pixonaut's references to a personal data export.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Privacy;

use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Labels;

/**
 * One export item per personalised order line of the person (Tools > Export
 * Personal Data): the order, the product, the Pixonaut design of each side,
 * the values the line keeps of what the customer chose for each design's
 * fields, and the printshop order placed for it. The design itself lives in
 * Pixonaut, under these ids.
 */
final class PersonalDataExporter {

	public const ID = 'pixonaut-for-woocommerce';

	private const GROUP_ID = 'pixonaut-designs';

	/**
	 * Prefix of an export item's id, followed by the order item id.
	 */
	private const ITEM_PREFIX = 'pixonaut-line-';

	/**
	 * @param Labels $labels Names a field its author gave no label, and a picture's value.
	 */
	public function __construct( private readonly Labels $labels ) {
	}

	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'add' ) );
	}

	/**
	 * @param mixed $exporters The registered exporters.
	 *
	 * @return array<string, mixed>
	 */
	public function add( mixed $exporters ): array {
		$exporters             = is_array( $exporters ) ? $exporters : array();
		$exporters[ self::ID ] = array(
			/* translators: Name of this exporter in WordPress Tools > Export Personal Data, which the merchant reads. */
			'exporter_friendly_name' => __( 'Personalised designs', 'pixonaut-for-woocommerce' ),
			'callback'               => array( $this, 'export' ),
		);
		return $exporters;
	}

	/**
	 * @param string $email The person's email address.
	 * @param int    $page  The page, from 1.
	 *
	 * @return array{data: list<array{group_id: string, group_label: string, group_description: string, item_id: string, data: list<array{name: string, value: string}>}>, done: bool}
	 */
	public function export( string $email, int $page = 1 ): array {
		$orders = CustomerOrders::page( $email, $page );
		$data   = array();
		foreach ( $orders as $order ) {
			foreach ( CustomerOrders::lines( $order ) as $line ) {
				$data[] = array(
					'group_id'          => self::GROUP_ID,
					/* translators: Heading of this group of data in the personal data export the customer receives. */
					'group_label'       => __( 'Personalised designs', 'pixonaut-for-woocommerce' ),
					'group_description' => __( 'The personalised items of your orders, and the references under which their designs and print orders are kept by our print service.', 'pixonaut-for-woocommerce' ),
					'item_id'           => self::ITEM_PREFIX . $line->item()->get_id(),
					'data'              => $this->fields( $order->get_order_number(), $line ),
				);
			}
		}
		return array(
			'data' => $data,
			'done' => CustomerOrders::is_last( $orders ),
		);
	}

	/**
	 * @param string    $order_number The order's number.
	 * @param LineState $line         The line.
	 *
	 * @return list<array{name: string, value: string}>
	 */
	private function fields( string $order_number, LineState $line ): array {
		$fields  = array(
			array(
				/* translators: Label of a row in the personal data export the customer receives; the value is the order number. */
				'name'  => __( 'Order number', 'pixonaut-for-woocommerce' ),
				'value' => $order_number,
			),
			array(
				/* translators: Label of a row in the personal data export the customer receives; the value is the product name. */
				'name'  => __( 'Product', 'pixonaut-for-woocommerce' ),
				'value' => $line->item()->get_name(),
			),
		);
		$designs = array();
		foreach ( $line->sides() as $side ) {
			$design_id = $line->side_ref( $side, LineState::SIDE_DESIGN_ID );
			if ( is_string( $design_id ) && '' !== $design_id ) {
				$designs[] = $design_id;
			}
		}
		if ( array() !== $designs ) {
			$fields[] = array(
				/* translators: Label of a row in the personal data export the customer receives; the value is the ids of their designs in Pixonaut. */
				'name'  => __( 'Design IDs', 'pixonaut-for-woocommerce' ),
				'value' => implode( ', ', $designs ),
			);
		}
		$context = array( 'item_id' => $line->item()->get_id() );
		foreach ( $line->sides() as $side ) {
			foreach ( $line->side_fields( $side ) as $field ) {
				$fields[] = array(
					'name'  => $field->shown_label( $this->labels, $context ),
					'value' => $field->shown_value( $this->labels, $context ),
				);
			}
		}
		$order_id = $line->ref( LineState::REF_ORDER_ID );
		if ( is_string( $order_id ) && '' !== $order_id ) {
			$fields[] = array(
				/* translators: Label of a row in the personal data export the customer receives; the value is the id of the order at the printshop. */
				'name'  => __( 'Print order ID', 'pixonaut-for-woocommerce' ),
				'value' => $order_id,
			);
		}
		return $fields;
	}
}
