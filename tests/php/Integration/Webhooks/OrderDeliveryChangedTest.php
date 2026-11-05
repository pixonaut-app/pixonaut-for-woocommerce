<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Webhooks;

use Pixonaut\WooCommerce\Admin\OrderBox;
use Pixonaut\WooCommerce\Fulfilment\DeliveryChange;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Fulfilment\OrderAddress;
use WC_Order_Item_Product;

/**
 * `order.delivery_changed` keeps what the printshop made of an address
 * change on the line, and notes it unless the store noted it already.
 */
final class OrderDeliveryChangedTest extends OrderEventsTestCase {

	/**
	 * A fingerprint of the address the printshop order has.
	 */
	private const ADDRESS = 'a1b2c3';

	/**
	 * A fingerprint of an address Pixonaut is telling the printshop.
	 */
	private const AWAITED = 'd4e5f6';

	private int $sequence = 0;

	public function test_the_answer_to_the_stores_own_change_still_being_told_is_kept_and_noted(): void {
		$item = $this->line_with( array( DeliveryChange::FIELD_STATE => DeliveryChange::SENDING ) );

		$this->deliver( $this->event( 'store_key', DeliveryChange::REFUSED, 'We have packed it already.' ) );
		$this->run_jobs();

		$this->assertSame( 'New delivery address: refused by the printshop ("We have packed it already."). The order ships to the address it had.', OrderBox::delivery_label( LineState::of( $item ) ) );
		$this->assertStringContainsString( 'the printshop refused the new address of "Personalised T-shirt": "We have packed it already."', $this->notes( $item ) );
	}

	public function test_the_stores_own_change_answered_already_is_not_noted_again(): void {
		$item = $this->line_with( array( DeliveryChange::FIELD_STATE => DeliveryChange::ACCEPTED ) );

		$this->deliver( $this->event( 'store_key', DeliveryChange::ACCEPTED ) );
		$this->run_jobs();

		$this->assertStringNotContainsString( 'Pixonaut', $this->notes( $item ) );
		$this->assertSame( self::ADDRESS, DeliveryChange::of( LineState::of( $item ) )[ DeliveryChange::FIELD_ADDRESS ] ?? null );
	}

	public function test_a_change_made_in_pixonaut_is_noted_and_the_stores_address_is_the_one_to_compare_with(): void {
		$item = $this->line_with( array( DeliveryChange::FIELD_AWAITING => self::AWAITED ) );

		$this->deliver( $this->event( 'member', DeliveryChange::ACCEPTED ) );
		$this->run_jobs();

		$notes = $this->notes( $item );
		$this->assertStringContainsString( 'was changed in Pixonaut. The address of this order was not changed.', $notes );
		$this->assertStringContainsString( 'the printshop took the new address', $notes );
		$delivery = DeliveryChange::of( LineState::of( $item ) );
		$this->assertSame( OrderAddress::fingerprint( OrderAddress::of( $this->order_of( $item ), $item ) ), $delivery[ DeliveryChange::FIELD_ADDRESS ] ?? null );
		$this->assertArrayNotHasKey( DeliveryChange::FIELD_AWAITING, $delivery );
		$this->assertSame( DeliveryChange::ACCEPTED, $delivery[ DeliveryChange::FIELD_STATE ] ?? null );
	}

	public function test_the_printshop_taking_the_address_pixonaut_was_telling_it_makes_it_the_printshop_orders(): void {
		$item = $this->line_with(
			array(
				DeliveryChange::FIELD_STATE    => DeliveryChange::SENDING,
				DeliveryChange::FIELD_AWAITING => self::AWAITED,
			)
		);

		$this->deliver( $this->event( 'store_key', DeliveryChange::ACCEPTED ) );
		$this->run_jobs();

		$delivery = DeliveryChange::of( LineState::of( $item ) );
		$this->assertSame( self::AWAITED, $delivery[ DeliveryChange::FIELD_ADDRESS ] ?? null );
		$this->assertArrayNotHasKey( DeliveryChange::FIELD_AWAITING, $delivery );
	}

	public function test_the_printshop_refusing_the_address_pixonaut_was_telling_it_keeps_the_one_it_had(): void {
		$item = $this->line_with(
			array(
				DeliveryChange::FIELD_STATE    => DeliveryChange::SENDING,
				DeliveryChange::FIELD_AWAITING => self::AWAITED,
			)
		);

		$this->deliver( $this->event( 'store_key', DeliveryChange::REFUSED ) );
		$this->run_jobs();

		$delivery = DeliveryChange::of( LineState::of( $item ) );
		$this->assertSame( self::ADDRESS, $delivery[ DeliveryChange::FIELD_ADDRESS ] ?? null );
		$this->assertSame( self::AWAITED, $delivery[ DeliveryChange::FIELD_REFUSED ] ?? null );
		$this->assertArrayNotHasKey( DeliveryChange::FIELD_AWAITING, $delivery );
	}

	public function test_an_event_about_a_line_whose_personal_data_was_erased_is_left_out(): void {
		$item = $this->line_with( array() );
		$line = LineState::of( $item );
		$line->set_ref( LineState::REF_ORDER_ERASED_AT, time() );
		$line->set_ref( LineState::REF_DELIVERY, null );
		$this->assertTrue( $line->save() );

		$this->deliver( $this->event( 'member', DeliveryChange::ACCEPTED ) );
		$this->run_jobs();

		$this->assertNull( LineState::of( $item )->ref( LineState::REF_DELIVERY ) );
	}

	public function test_an_event_older_than_the_outcome_the_line_holds_is_left_out(): void {
		$item = $this->line_with(
			array(
				DeliveryChange::FIELD_STATE => DeliveryChange::ACCEPTED,
				DeliveryChange::FIELD_AT    => PHP_INT_MAX,
			)
		);

		$this->deliver( $this->event( 'store_key', DeliveryChange::SENDING ) );
		$this->run_jobs();

		$this->assertSame( DeliveryChange::ACCEPTED, DeliveryChange::of( LineState::of( $item ) )[ DeliveryChange::FIELD_STATE ] ?? null );
	}

	/**
	 * A line whose `delivery` ref holds ADDRESS and $fields.
	 *
	 * @param array<string, mixed> $fields Further fields of the ref.
	 */
	private function line_with( array $fields ): WC_Order_Item_Product {
		$item = $this->pixonaut_line();
		$line = LineState::of( $item );
		$line->set_ref( LineState::REF_DELIVERY, array( DeliveryChange::FIELD_ADDRESS => self::ADDRESS ) + $fields );
		$this->assertTrue( $line->save() );
		return $item;
	}

	/**
	 * An `order.delivery_changed` envelope for ORDER_ID.
	 *
	 * @param string      $by      Who made the change.
	 * @param string      $outcome What the printshop made of it.
	 * @param string|null $words   The printshop's own answer to a refused change.
	 * @return array{id: string, type: string, payload: array<string, mixed>, createdAt: int}
	 */
	private function event( string $by, string $outcome, ?string $words = null ): array {
		++$this->sequence;
		$created = time() * 1000 + $this->sequence;
		return array(
			'id'        => "{$created}-3c4d5e6f-7a8b-4c9d-8e0f-1a2b3c4d5e" . str_pad( (string) $this->sequence, 2, '0', STR_PAD_LEFT ),
			'type'      => 'order.delivery_changed',
			'payload'   => array(
				'orderId'     => self::ORDER_ID,
				'printId'     => self::PRINT_ID,
				'printIds'    => array( self::PRINT_ID ),
				'externalRef' => self::EXTERNAL_REF,
				'added'       => false,
				'by'          => $by,
				'outcome'     => $outcome,
				'words'       => $words,
			),
			'createdAt' => $created,
		);
	}
}
