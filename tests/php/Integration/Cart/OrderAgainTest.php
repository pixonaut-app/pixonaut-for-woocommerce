<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Cart;

use Pixonaut\WooCommerce\Api\Hosts;
use Pixonaut\WooCommerce\Cart\CartCleanup;
use Pixonaut\WooCommerce\Cart\CartLine;
use Pixonaut\WooCommerce\Cart\OrderAgain;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Labels;
use Pixonaut\WooCommerce\Refs;
use Pixonaut\WooCommerce\Tests\Support\ActionSchedulerRunner;
use Pixonaut\WooCommerce\Tests\Support\DesignFixture;
use ReflectionMethod;
use WC_Cart_Session;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product_Simple;

final class OrderAgainTest extends PersonalisedCartTestCase {

	/**
	 * The design Pixonaut makes when the order line's design is duplicated.
	 */
	private const COPY_ID = '0b9e8d7c-6a5f-4e3d-8c2b-1a0f9e8d7c6b';

	/**
	 * The design Pixonaut makes when it is duplicated again.
	 */
	private const SECOND_COPY_ID = '1c0f9e8d-7b6a-4f5e-9d3c-2b1a0f9e8d7c';

	private WC_Order $order;

	private int $plain_id;

	public function set_up(): void {
		parent::set_up();
		$customer = $this->customer();
		wp_set_current_user( $customer );

		$plain = new WC_Product_Simple();
		$plain->set_name( 'Plain mug' );
		$plain->set_regular_price( '10' );
		$plain->save();
		$this->plain_id = $plain->get_id();

		$this->script_commit();
		$this->assertTrue( $this->post_add_to_cart() );
		WC()->cart->add_to_cart( $this->plain_id );
		add_action(
			'woocommerce_checkout_order_created',
			function ( WC_Order $order ): void {
				$this->recorder->json(
					200,
					array(
						'designId'    => DesignFixture::ID,
						'externalRef' => Refs::order_item_ref( self::SITE, $order->get_id(), self::personalised_item( $order )->get_id() ),
					)
				);
			},
			1
		);
		$order_id = WC()->checkout()->create_order(
			array(
				'payment_method'     => 'cod',
				'billing_first_name' => 'Alexandra',
				'billing_last_name'  => 'Lind',
				'billing_email'      => 'alexandra@example.org',
				'billing_country'    => 'SE',
			)
		);
		$this->assertIsInt( $order_id );
		$order = wc_get_order( $order_id );
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->set_customer_id( $customer );
		$order->set_status( 'completed' );
		$order->save();
		$this->order = $order;

		WC()->cart->empty_cart();
		wc_clear_notices();
		$this->recorder->requests = array();
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function customer(): int {
		$id = self::factory()->user->create( array( 'role' => 'customer' ) );
		if ( ! is_int( $id ) ) {
			$this->fail( 'Could not create a customer.' );
		}
		return $id;
	}

	private static function personalised_item( WC_Order $order ): WC_Order_Item_Product {
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof WC_Order_Item_Product && LineState::is_tracked( $item ) ) {
				return $item;
			}
		}
		self::fail( 'The order has no personalised line.' );
	}

	/**
	 * Runs WooCommerce's "Order again" on the order.
	 *
	 * @return array<string, array<string, mixed>> The cart it rebuilds.
	 */
	private function order_again(): array {
		$session  = new WC_Cart_Session( WC()->cart );
		$populate = new ReflectionMethod( $session, 'populate_cart_from_order' );
		$populate->setAccessible( true );
		$cart = $populate->invoke( $session, $this->order->get_id(), array() );
		$this->assertIsArray( $cart );
		return $cart;
	}

	private function script_copy(): void {
		$this->recorder
			->json( 201, array( 'designId' => self::COPY_ID ) )
			->json(
				200,
				DesignFixture::design(
					array(
						'id'             => self::COPY_ID,
						'duplicatedFrom' => DesignFixture::ID,
					)
				)
			);
	}

	public function test_order_again_puts_the_personalised_line_back_with_a_copy_of_its_design(): void {
		$this->script_copy();
		$owner    = (string) WC()->session->get_customer_id();
		$line_key = Refs::line_key( array( 'front' => self::COPY_ID ), $owner );
		$ref      = Refs::cart_ref( self::SITE, $line_key );
		$this->recorder->json(
			200,
			array(
				'designId'    => self::COPY_ID,
				'externalRef' => $ref,
			)
		);

		$cart = $this->order_again();

		$this->assertCount( 2, $cart );
		$lines = array_values( array_filter( array_map( static fn ( array $item ): ?CartLine => CartLine::of( $item ), $cart ) ) );
		$this->assertCount( 1, $lines );
		$this->assertSame( self::COPY_ID, $lines[0]->sides[0]->design_id );
		$this->assertSame( $ref, $lines[0]->external_ref );
		$this->assertSame( self::TENANT, $lines[0]->tenant_id );
		$this->assertContains( $this->plain_id, array_map( static fn ( array $item ): int => (int) $item['product_id'], $cart ) );
		foreach ( $cart as $item ) {
			$this->assertArrayNotHasKey( OrderAgain::ITEM_KEY, $item );
		}

		$sent = $this->recorder->requests;
		$this->assertCount( 3, $sent );
		$this->assertSame( array( 'POST', Hosts::server_api() . '/v1/designs/' . DesignFixture::ID . '/duplicate' ), array( $sent[0]['method'], $sent[0]['url'] ) );
		$this->assertSame( Hosts::server_api() . '/v1/designs/' . self::COPY_ID, $sent[1]['url'] );
		$this->assertSame( array( 'PUT', Hosts::server_api() . '/v1/designs/' . self::COPY_ID . '/external-ref' ), array( $sent[2]['method'], $sent[2]['url'] ) );
		// The earlier order's line still holds its own design.
		$this->assertSame( DesignFixture::ID, LineState::of( self::personalised_item( $this->order ) )->side_ref( 'front', LineState::SIDE_DESIGN_ID ) );
	}

	public function test_a_line_whose_design_is_gone_is_left_out_and_the_plain_items_come_back(): void {
		$this->recorder->json( 404, array( 'error' => 'not_found' ) );

		$cart = $this->order_again();

		$this->assertCount( 1, $cart );
		$this->assertSame( $this->plain_id, (int) array_values( $cart )[0]['product_id'] );
		$this->assertContains( $this->plugin->labels()->get( Labels::DESIGN_REQUIRED ), self::errors() );
	}

	public function test_a_copy_that_does_not_match_the_product_now_is_deleted(): void {
		$this->recorder
			->json( 201, array( 'designId' => self::COPY_ID ) )
			->json(
				200,
				DesignFixture::design(
					array(
						'id'          => self::COPY_ID,
						'placementId' => 'back',
					)
				)
			)
			->json( 204, array() );

		$cart = $this->order_again();

		$this->assertCount( 1, $cart );
		$this->assertContains( $this->plugin->labels()->get( Labels::DESIGN_MISMATCH ), self::errors() );
		$this->assertSame( 1, ActionSchedulerRunner::run_pending( CartCleanup::ACTION ) );
		$sent = $this->recorder->requests;
		$this->assertSame( array( 'DELETE', Hosts::server_api() . '/v1/designs/' . self::COPY_ID ), array( $sent[2]['method'], $sent[2]['url'] ) );
	}

	public function test_a_copy_left_by_an_outage_is_deleted_by_a_retried_job(): void {
		$this->script_copy();
		$this->recorder->fail();

		$cart = $this->order_again();

		$this->assertCount( 1, $cart );
		$this->assertContains( $this->plugin->labels()->get( Labels::DESIGN_NOT_SAVED ), self::errors() );
		$this->recorder->requests = array();
		$this->recorder->fail()->json( 204, array() );
		ActionSchedulerRunner::run_pending( CartCleanup::ACTION );

		$deletes = array_map( static fn ( array $sent ): string => $sent['method'] . ' ' . $sent['url'], $this->recorder->requests );
		$this->assertSame( array_fill( 0, 2, 'DELETE ' . Hosts::server_api() . '/v1/designs/' . self::COPY_ID ), $deletes, 'The failed deletion is retried.' );
		$this->assertFalse( self::deletion_pending() );
	}

	public function test_ordering_again_deletes_the_copies_of_the_cart_it_replaces(): void {
		$this->script_copy();
		$this->recorder->json(
			200,
			array(
				'designId'    => self::COPY_ID,
				'externalRef' => 'any',
			)
		);
		WC()->session->set( 'cart', $this->order_again() );
		$this->recorder->requests = array();
		$this->recorder
			->json( 201, array( 'designId' => self::SECOND_COPY_ID ) )
			->json( 200, DesignFixture::design( array( 'id' => self::SECOND_COPY_ID ) ) )
			->json(
				200,
				array(
					'designId'    => self::SECOND_COPY_ID,
					'externalRef' => 'any',
				)
			);

		$cart = $this->order_again();

		$this->assertCount( 2, $cart );
		$this->assertSame( array( self::COPY_ID ), self::scheduled_deletions() );
	}

	/**
	 * Whether a deletion job is pending.
	 */
	private static function deletion_pending(): bool {
		return array() !== as_get_scheduled_actions(
			array(
				'hook'   => CartCleanup::ACTION,
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			),
			'ids'
		);
	}

	/**
	 * @return list<string> The designs whose deletion is pending.
	 */
	private static function scheduled_deletions(): array {
		$ids = array();
		foreach ( as_get_scheduled_actions(
			array(
				'hook'   => CartCleanup::ACTION,
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			)
		) as $action ) {
			$ids[] = (string) ( $action->get_args()['design_id'] ?? '' );
		}
		return $ids;
	}

	public function test_another_customer_cannot_order_the_line_again(): void {
		$item = self::personalised_item( $this->order );
		wp_set_current_user( $this->customer() );

		$this->assertNull( OrderAgain::item_in( array( OrderAgain::ITEM_KEY => $item->get_id() ) ) );
		$this->assertFalse( apply_filters( 'woocommerce_add_to_cart_validation', true, $this->product_id, 1, 0, array(), array( OrderAgain::ITEM_KEY => $item->get_id() ) ) );
		$this->assertSame( array(), $this->recorder->requests );
	}
}
