<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Fulfilment;

use Brain\Monkey\Functions;
use Mockery;
use Mockery\MockInterface;
use Pixonaut\WooCommerce\Api\Designs;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use Pixonaut\WooCommerce\Fulfilment\LineCancellation;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Privacy\LineErasure;
use Pixonaut\WooCommerce\Tests\Support\FulfilmentAnswers;
use Pixonaut\WooCommerce\Tests\Support\RecordingLogger;
use Pixonaut\WooCommerce\Tests\Support\ScriptedClient;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;
use Pixonaut\WooCommerce\Vendor\Nyholm\Psr7\Response;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Pixonaut as Sdk;
use WC_Order;
use WC_Order_Item_Product;

/**
 * Cancelling and erasing a line look its printshop orders up under its ref.
 * An order Pixonaut lists without a field the store does not read is still
 * found.
 */
final class OrderListReadsTest extends TestCase {

	private const SITE = '8e1f3c2a-5b4d-4e6f-9a0b-1c2d3e4f5a6b';

	private const ORDER_ID = 7;

	private const ITEM_ID = 42;

	private const EXTERNAL_REF = 'wc:' . self::SITE . ':order:7:item:42';

	/**
	 * When Pixonaut erased the order's personal data, epoch milliseconds.
	 */
	private const ERASED_AT = 1_800_000_000_000;

	private ScriptedClient $transport;

	/**
	 * Order item meta in the database, by key.
	 *
	 * @var array<string, string>
	 */
	private array $meta = array();

	/**
	 * @var list<string>
	 */
	private array $notes = array();

	protected function setUp(): void {
		parent::setUp();
		$this->transport = new ScriptedClient();
		Functions\stubTranslationFunctions();
		Functions\when( 'wp_json_encode' )->alias( static fn ( $value ) => json_encode( $value ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		Functions\when( 'wp_slash' )->returnArg();
		Functions\when( 'get_metadata' )->alias(
			function ( string $type, int $id, string $key, bool $single = false ): string|array {
				if ( ! $single ) {
					return array();
				}
				return $this->meta[ $key ] ?? '';
			}
		);
		Functions\when( 'add_metadata' )->justReturn( 1 );
		Functions\when( 'delete_metadata' )->justReturn( true );
		Functions\when( 'update_metadata' )->alias(
			function ( string $type, int $id, string $key, string $value, string $previous = '' ): bool {
				if ( '' !== $previous && ( $this->meta[ $key ] ?? null ) !== $previous ) {
					return false;
				}
				$this->meta[ $key ] = $value;
				return true;
			}
		);
		$order = Mockery::mock( WC_Order::class );
		$order->allows( 'get_id' )->andReturn( self::ORDER_ID );
		$order->allows( 'add_order_note' )->andReturnUsing(
			function ( string $note ): int {
				$this->notes[] = $note;
				return 1;
			}
		);
		Functions\when( 'wc_get_order' )->justReturn( $order );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_line_whose_order_answer_was_lost_finds_and_cancels_the_order_listed_under_its_ref(): void {
		$this->store_line(
			LineState::APPROVED,
			array(
				LineState::REF_CANCEL        => array(
					'trigger'  => LineCancellation::ORDER_CANCELLED,
					'failures' => 0,
				),
				LineState::REF_ORDER_REQUEST => array( 'operation' => 'orders.create' ),
			)
		);
		$item = $this->item();
		Mockery::mock( 'alias:WC_Order_Factory' )->allows( 'get_order_item' )->with( self::ITEM_ID )->andReturn( $item );
		$this->transport
			->off_spec( self::answer( FulfilmentAnswers::orders( array( self::unread_fields_left_out( FulfilmentAnswers::order( 'o_1', array( 'p_front' ), self::EXTERNAL_REF ) ) ) ) ) )
			->json( 200, FulfilmentAnswers::order( 'o_1', array( 'p_front' ), self::EXTERNAL_REF, array( 'status' => 'cancelled' ) ) );

		( new LineCancellation( $this->sdk(), fn (): StoredConnection => self::connection(), new RecordingLogger() ) )->run( self::ITEM_ID );

		$this->assertSame( array( 'GET /v1/orders?externalRef=' . rawurlencode( self::EXTERNAL_REF ), 'POST /v1/orders/o_1/cancel' ), $this->sent() );
		$line = LineState::of( $item );
		$this->assertSame( LineState::CANCELLED, $line->state() );
		$this->assertSame( 'o_1', $line->ref( LineState::REF_ORDER_ID ) );
		$this->assertSame( 'cancelled', $line->ref( LineState::REF_ORDER_STATUS ) );
		$this->assertNull( $line->ref( LineState::REF_ORDER_REQUEST ) );
		$this->assertStringContainsString( 'was cancelled', implode( "\n", $this->notes ) );
	}

	public function test_an_erased_line_erases_every_order_listed_under_its_ref(): void {
		$this->store_line( LineState::SHIPPED, array( LineState::REF_ORDER_ID => 'o_2' ), self::ERASED_AT );
		$this->transport
			->off_spec(
				self::answer(
					FulfilmentAnswers::orders(
						array(
							self::unread_fields_left_out( FulfilmentAnswers::order( 'o_1', array( 'p_front' ), self::EXTERNAL_REF, array( 'status' => 'failed' ) ) ),
							self::unread_fields_left_out( FulfilmentAnswers::order( 'o_2', array( 'p_front' ), self::EXTERNAL_REF, array( 'status' => 'shipped' ) ) ),
						)
					)
				)
			)
			->off_spec( self::answer( self::erased( 'o_2' ) ) )
			->off_spec( self::answer( self::erased( 'o_1' ) ) );

		$erasure = new LineErasure( new Designs( $this->sdk() ), $this->sdk(), fn (): StoredConnection => self::connection(), new RecordingLogger() );

		$this->assertSame( LineErasure::ERASED, $erasure->request( LineState::of( $this->item() ) ) );
		$this->assertSame(
			array(
				'GET /v1/orders?externalRef=' . rawurlencode( self::EXTERNAL_REF ),
				'POST /v1/orders/o_2/erase-personal-data',
				'POST /v1/orders/o_1/erase-personal-data',
			),
			$this->sent()
		);
		$this->assertSame( self::ERASED_AT, LineState::of( $this->item() )->ref( LineState::REF_ORDER_ERASED_AT ) );
		$this->assertStringContainsString( 'was erased in Pixonaut', implode( "\n", $this->notes ) );
	}

	/**
	 * @param array<string, mixed> $order An order as Pixonaut lists it.
	 *
	 * @return array<string, mixed> The order without fields the store does not read.
	 */
	private static function unread_fields_left_out( array $order ): array {
		unset( $order['adapter'], $order['printshopAsPlaced'], $order['blank'], $order['submittedAt'] );
		return $order;
	}

	/**
	 * @param string $order_id The order erased.
	 *
	 * @return array<string, mixed> `POST /v1/orders/{id}/erase-personal-data`'s answer.
	 */
	private static function erased( string $order_id ): array {
		$order = FulfilmentAnswers::order( $order_id, array( 'p_front' ), self::EXTERNAL_REF, array( 'personalDataErasedAt' => self::ERASED_AT ) );
		unset( $order['recipientEmailBounced'] );
		return $order;
	}

	/**
	 * Stores the line, its one design erased already when $erased_at is given.
	 *
	 * @param string               $state     The line's state.
	 * @param array<string, mixed> $refs      Refs besides the line's tenant and ref.
	 * @param int|null             $erased_at When its design was erased.
	 */
	private function store_line( string $state, array $refs, ?int $erased_at = null ): void {
		$this->meta[ LineState::META_KEY ] = (string) json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			array(
				'v'          => LineState::SCHEMA_VERSION,
				'state'      => $state,
				'resumeFrom' => null,
				'reason'     => null,
				'changedAt'  => 0,
				'refs'       => $refs + array(
					LineState::REF_TENANT_ID    => FulfilmentAnswers::TENANT_ID,
					LineState::REF_EXTERNAL_REF => self::EXTERNAL_REF,
				),
				'sides'      => array(
					array_filter(
						array(
							'key'                     => 'front',
							LineState::SIDE_DESIGN_ID => 'd_front',
							LineState::SIDE_REVISION  => 1,
							LineState::SIDE_APPROVED  => true,
							LineState::SIDE_ERASED_AT => $erased_at,
						),
						static fn ( mixed $value ): bool => null !== $value
					),
				),
			)
		);
	}

	/**
	 * @return WC_Order_Item_Product&MockInterface
	 */
	private function item(): WC_Order_Item_Product {
		/** @var WC_Order_Item_Product&MockInterface $item */
		$item = Mockery::mock( WC_Order_Item_Product::class );
		$item->allows( 'get_id' )->andReturn( self::ITEM_ID );
		$item->allows( 'get_order_id' )->andReturn( self::ORDER_ID );
		$item->allows( 'get_quantity' )->andReturn( 1 );
		$item->allows( 'get_name' )->andReturn( 'Personalised tee' );
		$item->allows( 'get_meta' )->andReturn( '' );
		return $item;
	}

	private function sdk(): Sdk {
		return $this->transport->sdk( ScriptedClient::credentials(), new RecordingLogger() );
	}

	/**
	 * @return list<string> Each request as `METHOD /path?query`.
	 */
	private function sent(): array {
		return array_map(
			static fn ( array $sent ): string => $sent['method'] . ' ' . ScriptedClient::target( $sent['url'] ),
			$this->transport->sent
		);
	}

	/**
	 * @param array<string, mixed> $body An answer the spec may not declare.
	 */
	private static function answer( array $body ): Response {
		return new Response( 200, array( 'content-type' => 'application/json' ), (string) json_encode( $body ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	private static function connection(): StoredConnection {
		return new StoredConnection(
			self::SITE,
			'https://shop.acme.example',
			0,
			FulfilmentAnswers::TENANT_ID,
			'Acme',
			StoredConnection::LIVE,
			'sc_1',
			ScriptedClient::INTEGRATION_KEY,
			'ik_id',
			'WooCommerce',
			'pk_test',
			'pk_id',
			array(),
			'wh_1',
			'https://shop.acme.example/wp-json/pixonaut/v1/webhook',
			'whsec_test'
		);
	}
}
