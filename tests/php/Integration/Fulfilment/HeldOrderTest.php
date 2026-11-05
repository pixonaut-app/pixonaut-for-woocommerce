<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Fulfilment;

use Pixonaut\WooCommerce\Admin\Notices;
use Pixonaut\WooCommerce\Admin\OrderBox;
use Pixonaut\WooCommerce\Fulfilment\AttentionAlerts;
use Pixonaut\WooCommerce\Fulfilment\FulfilmentJobs;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Fulfilment\OrderHold;
use Pixonaut\WooCommerce\Fulfilment\OrderStatus;
use Pixonaut\WooCommerce\Tests\Support\ActionSchedulerRunner;
use Pixonaut\WooCommerce\Tests\Support\FulfilmentAnswers;
use Pixonaut\WooCommerce\Tests\Support\SentMail;

/**
 * A free workspace past its monthly order limit: Pixonaut takes the order
 * and holds it. The line is ordered and shows as held, never as a failure.
 */
final class HeldOrderTest extends FulfilmentTestCase {

	private const PERIOD_END = '2026-11-01T00:00:00.000Z';

	public function set_up(): void {
		parent::set_up();
		reset_phpmailer_instance();
	}

	public function test_an_order_placed_held_keeps_its_id_and_shows_as_held_with_one_note(): void {
		$this->place_held(
			array(
				'reason'    => 'monthly_order_limit',
				'limit'     => 25,
				'periodEnd' => self::PERIOD_END,
			)
		);

		$line = $this->line();
		$this->assertSame( LineState::ORDERED, $line->state() );
		$this->assertSame( 'o_held', $line->ref( LineState::REF_ORDER_ID ) );
		$this->assertSame( OrderStatus::HELD, $line->ref( LineState::REF_ORDER_STATUS ) );
		$this->assertNull( $line->ref( LineState::REF_RETRY ) );
		$this->assertSame( array(), ActionSchedulerRunner::failed( FulfilmentJobs::ACTION ) );
		$this->assertSame( 0, ActionSchedulerRunner::run_pending( FulfilmentJobs::ACTION ) );
		$this->assertSame( array(), SentMail::all() );
		$this->assertFalse( $this->has_notice() );
		$this->assertSame( 'Held by Pixonaut', OrderBox::state_label( $line->state(), OrderStatus::HELD ) );

		$explained = OrderHold::explain( $line );
		$this->assertStringContainsString( 'free plan', $explained );
		$this->assertStringContainsString( '25', $explained );
		$this->assertStringContainsString( (string) wp_date( (string) get_option( 'date_format' ), (int) strtotime( self::PERIOD_END ) ), $explained );

		$notes = $this->notes();
		$this->assertSame( 1, substr_count( $notes, 'is held' ) );
		$this->assertStringContainsString( 'free plan', $notes );
		$this->assertStringNotContainsString( 'printshop order for "Personalised T-shirt" failed', $notes );
	}

	public function test_an_unknown_hold_reason_shows_as_held_without_a_reason(): void {
		$this->place_held(
			array(
				'reason'    => 'cosmic_rays',
				'limit'     => 25,
				'periodEnd' => self::PERIOD_END,
			)
		);

		$line = $this->line();
		$this->assertSame( LineState::ORDERED, $line->state() );
		$this->assertSame( OrderStatus::HELD, $line->ref( LineState::REF_ORDER_STATUS ) );
		$this->assertStringNotContainsString( 'free plan', OrderHold::explain( $line ) );
		$this->assertStringNotContainsString( 'cosmic_rays', $this->notes() );
		$this->assertStringContainsString( 'is held', $this->notes() );
		$this->assertSame( array(), SentMail::all() );
	}

	/**
	 * Pays the order and runs its job against Pixonaut placing the order held.
	 *
	 * @param array<string, mixed> $hold The order's `hold`.
	 */
	private function place_held( array $hold ): void {
		$this->recorder
			->json( 200, FulfilmentAnswers::approved() )
			->json( 200, FulfilmentAnswers::orders( array() ) )
			->json( 200, FulfilmentAnswers::design_approved( self::DESIGN, self::REVISION ) )
			->json(
				201,
				FulfilmentAnswers::order(
					'o_held',
					array( 'p_front' ),
					$this->external_ref(),
					array(
						'status' => OrderStatus::HELD,
						'hold'   => $hold,
					)
				)
			);

		do_action( 'woocommerce_payment_complete', $this->order->get_id() );
		$this->assertSame( 1, ActionSchedulerRunner::run_pending( FulfilmentJobs::ACTION ) );
	}

	private function external_ref(): string {
		return 'wc:' . self::SITE . ':order:' . $this->order->get_id() . ':item:' . $this->item->get_id();
	}

	private function has_notice(): bool {
		return array_key_exists( AttentionAlerts::key( $this->order->get_id() ), (array) get_option( Notices::OPTION, array() ) );
	}

	/**
	 * The order's notes as one string.
	 */
	private function notes(): string {
		$notes = wc_get_order_notes( array( 'order_id' => $this->order->get_id() ) );
		return implode( "\n", array_map( static fn ( $note ): string => (string) $note->content, $notes ) );
	}
}
