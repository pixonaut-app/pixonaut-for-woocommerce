<?php
/**
 * A design one of the workspace's automations ordered before the store did.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Fulfilment;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\ErrorCode;
use Pixonaut\WooCommerce\Cart\StandingSide;
use Pixonaut\WooCommerce\Fulfilment\AdoptedOrder;
use Pixonaut\WooCommerce\Fulfilment\DeliveryChange;
use Pixonaut\WooCommerce\Fulfilment\FulfilmentJobs;
use Pixonaut\WooCommerce\Fulfilment\LineReader;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Fulfilment\OrderAddress;
use Pixonaut\WooCommerce\Tests\Support\ActionSchedulerRunner;
use Pixonaut\WooCommerce\Tests\Support\FulfilmentAnswers;
use Pixonaut\WooCommerce\Tests\Support\HttpRecorder;

final class MadeInPixonautTest extends FulfilmentTestCase {

	/**
	 * The order the automation placed for the line's design.
	 */
	private const AUTOMATION_ORDER = 'o_automation';

	/**
	 * The reference the automation places its orders under.
	 */
	private const AUTOMATION_REF = 'automation-ref';

	/**
	 * A standing back's design, and the revision its print was made at; the line holds no revision of it.
	 */
	private const STANDING_BACK = 'des_standing_back';

	private const STANDING_REVISION = 3;

	/**
	 * The automation, as an order's history names it.
	 */
	private const AUTOMATION = array(
		'id'   => 'auto_1',
		'name' => 'Order approved designs',
	);

	public function test_a_design_an_automation_ordered_takes_that_order_on(): void {
		$this->answer_up_to_the_refusal()->json( 200, $this->automation_order( 'submitted' ) );

		do_action( 'woocommerce_payment_complete', $this->order->get_id() );
		ActionSchedulerRunner::run_pending( FulfilmentJobs::ACTION );

		$line = $this->line();
		$this->assertSame( LineState::ORDERED, $line->state() );
		$this->assertSame( self::AUTOMATION_ORDER, $line->ref( LineState::REF_ORDER_ID ) );
		$this->assertSame( 'submitted', $line->ref( LineState::REF_ORDER_STATUS ) );
		$this->assertNull( $line->ref( LineState::REF_ORDER_REQUEST ) );
		$this->assertSame( self::AUTOMATION['name'], $line->ref( LineState::REF_ORDER_AUTOMATION ) );
		$this->assertSame( self::AUTOMATION_REF, $line->ref( LineState::REF_ORDER_EXTERNAL_REF ) );
		$this->assertSame( 'The Pixonaut automation "Order approved designs" placed the printshop order.', AdoptedOrder::label( $line ) );
		$this->assertSame( 1, $this->count_sent( 'POST', '/v1/orders' ) );
		$this->assertStringContainsString( 'a Pixonaut automation already ordered "' . $this->item->get_name() . '", as order ' . self::AUTOMATION_ORDER, $this->notes() );
		$this->assertFalse( as_has_scheduled_action( LineReader::ACTION ) );
		$this->assertFalse( as_has_scheduled_action( DeliveryChange::ACTION ) );
	}

	public function test_an_automation_order_to_another_address_is_sent_the_customers(): void {
		$order                            = $this->automation_order( 'submitted' );
		$order['shippingAddress']['city'] = 'Elsewhere';
		$this->answer_up_to_the_refusal()->json( 200, $order );

		do_action( 'woocommerce_payment_complete', $this->order->get_id() );
		ActionSchedulerRunner::run_pending( FulfilmentJobs::ACTION );

		$this->assertSame( LineState::ORDERED, $this->line()->state() );
		$this->assertTrue( as_has_scheduled_action( DeliveryChange::ACTION, array( 'item_id' => $this->item->get_id() ) ) );
	}

	public function test_an_automation_order_that_shipped_already_is_read_again(): void {
		$this->answer_up_to_the_refusal()->json( 200, $this->automation_order( 'shipped' ) );

		do_action( 'woocommerce_payment_complete', $this->order->get_id() );
		ActionSchedulerRunner::run_pending( FulfilmentJobs::ACTION );

		$this->assertSame( LineState::ORDERED, $this->line()->state() );
		$this->assertTrue(
			as_has_scheduled_action(
				LineReader::ACTION,
				array(
					'item_id' => $this->item->get_id(),
					'attempt' => 1,
				),
				LineReader::GROUP
			)
		);
	}

	/**
	 * @return iterable<string, array{0: callable(array<string, mixed>): array<string, mixed>}>
	 */
	public static function orders_not_taken_on(): iterable {
		yield 'placed by a member' => array( static fn ( array $order ): array => FulfilmentAnswers::placed_by( $order, 'member' ) );
		yield 'of fewer copies' => array( static fn ( array $order ): array => array( 'quantity' => 1 ) + $order );
		yield 'of another revision' => array(
			static function ( array $order ): array {
				$order['prints'][0]['designRevision'] = self::REVISION + 1;
				return $order;
			},
		);
		yield 'of another variant' => array( static fn ( array $order ): array => array( 'variantRef' => 'var_other' ) + $order );
	}

	/**
	 * @dataProvider orders_not_taken_on
	 *
	 * @param callable $change Makes the automation's order one the line does not take on.
	 * @phpstan-param callable(array<string, mixed>): array<string, mixed> $change
	 */
	public function test_an_order_the_line_does_not_take_on_needs_attention( callable $change ): void {
		$line = $this->line();
		$line->set_ref( LineState::REF_VARIANT, 'var_bought' );
		$line->save();
		$this->answer_up_to_the_refusal()->json( 200, $change( array( 'variantRef' => 'var_bought' ) + $this->automation_order( 'submitted' ) ) );

		do_action( 'woocommerce_payment_complete', $this->order->get_id() );
		ActionSchedulerRunner::run_pending( FulfilmentJobs::ACTION );

		$line = $this->line();
		$this->assertSame( LineState::NEEDS_ATTENTION, $line->state() );
		$this->assertSame( ErrorCode::DESIGN_ALREADY_ORDERED, $line->reason() );
		$this->assertNull( $line->ref( LineState::REF_ORDER_ID ) );
		$this->assertNull( $line->ref( LineState::REF_ORDER_AUTOMATION ) );
		$this->assertStringContainsString( self::AUTOMATION_ORDER, $this->notes() );
	}

	public function test_an_automation_order_at_another_printshop_than_the_line_chose_needs_attention(): void {
		$line = $this->line();
		$line->set_ref( LineState::REF_PRINTSHOP, 'ps_signs' );
		$line->save();
		$this->answer_up_to_the_refusal()->json( 200, array( 'printshopConnectionId' => 'ps_other' ) + $this->automation_order( 'submitted' ) );

		do_action( 'woocommerce_payment_complete', $this->order->get_id() );
		ActionSchedulerRunner::run_pending( FulfilmentJobs::ACTION );

		$line = $this->line();
		$this->assertSame( LineState::NEEDS_ATTENTION, $line->state() );
		$this->assertSame( ErrorCode::DESIGN_ALREADY_ORDERED, $line->reason() );
		$this->assertNull( $line->ref( LineState::REF_ORDER_ID ) );
	}

	public function test_an_automation_order_at_the_printshop_the_line_chose_is_taken_on(): void {
		$line = $this->line();
		$line->set_ref( LineState::REF_PRINTSHOP, 'ps_signs' );
		$line->save();
		$this->answer_up_to_the_refusal()->json( 200, array( 'printshopConnectionId' => 'ps_signs' ) + $this->automation_order( 'submitted' ) );

		do_action( 'woocommerce_payment_complete', $this->order->get_id() );
		ActionSchedulerRunner::run_pending( FulfilmentJobs::ACTION );

		$line = $this->line();
		$this->assertSame( LineState::ORDERED, $line->state() );
		$this->assertSame( self::AUTOMATION_ORDER, $line->ref( LineState::REF_ORDER_ID ) );
	}

	public function test_an_automation_order_without_the_standing_side_is_not_taken_on(): void {
		$this->stand_back();
		$this->answer_up_to_the_refusal()->json( 200, $this->automation_order( 'submitted' ) );

		do_action( 'woocommerce_payment_complete', $this->order->get_id() );
		ActionSchedulerRunner::run_pending( FulfilmentJobs::ACTION );

		$line = $this->line();
		$this->assertSame( LineState::NEEDS_ATTENTION, $line->state() );
		$this->assertSame( ErrorCode::DESIGN_ALREADY_ORDERED, $line->reason() );
		$this->assertNull( $line->ref( LineState::REF_ORDER_ID ) );
	}

	public function test_an_automation_order_of_every_side_standing_ones_included_is_taken_on(): void {
		$this->stand_back();
		$order             = $this->automation_order( 'submitted' );
		$order['prints'][] = array(
			'designId'       => self::STANDING_BACK,
			'designRevision' => self::STANDING_REVISION,
		) + $order['prints'][0];
		$this->answer_up_to_the_refusal()->json( 200, $order );

		do_action( 'woocommerce_payment_complete', $this->order->get_id() );
		ActionSchedulerRunner::run_pending( FulfilmentJobs::ACTION );

		$line = $this->line();
		$this->assertSame( LineState::ORDERED, $line->state() );
		$this->assertSame( self::AUTOMATION_ORDER, $line->ref( LineState::REF_ORDER_ID ) );
	}

	public function test_an_automation_order_with_a_fill_print_is_taken_on(): void {
		$order             = $this->automation_order( 'submitted' );
		$order['prints'][] = array(
			'printId'         => 'prt_fill',
			'placementId'     => 'sleeve',
			'printedAs'       => 'Sleeve',
			'spotOutput'      => null,
			'status'          => 'completed',
			'placementFields' => null,
			'designId'        => null,
			'fillColour'      => '#ffffff',
		);
		$this->answer_up_to_the_refusal()->json( 200, $order );

		do_action( 'woocommerce_payment_complete', $this->order->get_id() );
		ActionSchedulerRunner::run_pending( FulfilmentJobs::ACTION );

		$line = $this->line();
		$this->assertSame( LineState::ORDERED, $line->state() );
		$this->assertSame( self::AUTOMATION_ORDER, $line->ref( LineState::REF_ORDER_ID ) );
	}

	/**
	 * Prints the line's back from a standing design, after its personalised front.
	 */
	private function stand_back(): void {
		$line = $this->line();
		$line->set_ref( LineState::REF_STANDING, array( ( new StandingSide( 'back', self::STANDING_BACK, 1 ) )->to_array() ) );
		$this->assertTrue( $line->save() );
	}

	/**
	 * Scripts Pixonaut up to its refusal of the line's order, naming the
	 * automation's order.
	 */
	private function answer_up_to_the_refusal(): HttpRecorder {
		return $this->recorder
			->json( 200, FulfilmentAnswers::approved() )
			->json( 200, FulfilmentAnswers::orders( array() ) )
			->json( 200, FulfilmentAnswers::design_approved( self::DESIGN, self::REVISION ) )
			->json(
				409,
				array(
					'error'    => ErrorCode::DESIGN_ALREADY_ORDERED,
					'designId' => self::DESIGN,
					'orderId'  => self::AUTOMATION_ORDER,
				)
			);
	}

	/**
	 * `GET /v1/orders/{id}` of the order the automation placed: the line's
	 * design at its revision, its quantity, to the order's address.
	 *
	 * @param string $status Its status now.
	 *
	 * @return array<string, mixed>
	 */
	private function automation_order( string $status ): array {
		$order              = OrderAddress::of( $this->order, $this->item ) + FulfilmentAnswers::read_order(
			self::AUTOMATION_ORDER,
			self::AUTOMATION_REF,
			array(
				'status'   => $status,
				'quantity' => self::QUANTITY,
			)
		);
		$order['prints'][0] = array(
			'designId'       => self::DESIGN,
			'designRevision' => self::REVISION,
		) + $order['prints'][0];
		return FulfilmentAnswers::placed_by( $order, AdoptedOrder::VIA_AUTOMATION, self::AUTOMATION );
	}

	private function notes(): string {
		$notes = wc_get_order_notes( array( 'order_id' => $this->order->get_id() ) );
		return implode( "\n", array_map( static fn ( $note ): string => (string) $note->content, $notes ) );
	}
}
