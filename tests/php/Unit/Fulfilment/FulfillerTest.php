<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Fulfilment;

use Brain\Monkey\Functions;
use Mockery;
use Mockery\MockInterface;
use Pixonaut\WooCommerce\Api\Deprecations;
use Pixonaut\WooCommerce\Approval\ProofLink;
use Pixonaut\WooCommerce\Approval\ProofRequest;
use Pixonaut\WooCommerce\Approval\ProofState;
use Pixonaut\WooCommerce\ApprovalMode;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use Pixonaut\WooCommerce\DesignerLanguage;
use Pixonaut\WooCommerce\Fulfilment\Fulfiller;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Fulfilment\NeedsAttention;
use Pixonaut\WooCommerce\Tests\Support\DesignFixture;
use Pixonaut\WooCommerce\Tests\Support\FulfilmentAnswers;
use Pixonaut\WooCommerce\Tests\Support\RecordingLogger;
use Pixonaut\WooCommerce\Tests\Support\ScriptedClient;
use Pixonaut\WooCommerce\Tests\Unit\FakeOptions;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;
use Pixonaut\WooCommerce\Vendor\Nyholm\Psr7\Response;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\ErrorCode;
use WC_Order;
use WC_Order_Item_Product;

final class FulfillerTest extends TestCase {

	use FakeOptions;


	private const SITE = '8e1f3c2a-5b4d-4e6f-9a0b-1c2d3e4f5a6b';

	/**
	 * The `<site>` of a later connection, after a reconnect.
	 */
	private const NEW_SITE = '1a2b3c4d-5e6f-4a7b-8c9d-0e1f2a3b4c5d';

	private const ORDER_ID = 7;

	private const ITEM_ID = 42;

	private const QUANTITY = 2;

	private const FRONT = 'front';

	private const BACK = 'back';

	private const DESIGN = 'd_front';

	private const BACK_DESIGN = 'd_back';

	private const REVISION = 3;

	private const CONSENT = array(
		'kind' => 'add_to_cart',
		'at'   => 1_800_000_000_000,
	);

	private const EXTERNAL_REF = 'wc:' . self::SITE . ':order:7:item:42';

	private const STATE_KEY = 'state-signing-key';

	private const DEADLINE_DAYS = 14;

	private const RETURN_URL = 'https://shop.acme.example/wp-json/pixonaut/v1/order-items/42/edit';

	private const PROOF_URL = 'https://app.pixonaut.example/h/proof/link_proof';

	private const CART_REF = 'wc:' . self::SITE . ':cart:4b2e';

	/**
	 * A free plan's orders a month.
	 */
	private const MONTHLY_LIMIT = 25;

	private const PERIOD_END = '2027-01-01T00:00:00Z';

	private const SHIPPING = array(
		'first_name' => 'Ada',
		'last_name'  => 'Lovelace',
		'company'    => 'Analytical Engines',
		'address_1'  => 'Storgatan 1',
		'address_2'  => '',
		'city'       => 'Stockholm',
		'state'      => '',
		'postcode'   => '111 22',
		'country'    => 'se',
		'phone'      => '',
	);

	private ScriptedClient $transport;

	/**
	 * Order item meta in the database, by key.
	 *
	 * @var array<string, string>
	 */
	private array $meta = array();

	/**
	 * Order item meta kept as several rows under one key, by key.
	 *
	 * @var array<string, list<string>>
	 */
	private array $meta_rows = array();

	/**
	 * @var list<string>
	 */
	private array $notes = array();

	private ?StoredConnection $connection = null;

	protected function setUp(): void {
		parent::setUp();
		$this->fake_options();
		$this->transport  = new ScriptedClient();
		$this->connection = self::connection( self::SITE, FulfilmentAnswers::TENANT_ID );
		Functions\stubTranslationFunctions();
		Functions\when( 'wp_json_encode' )->alias( static fn ( $value ) => json_encode( $value ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		Functions\when( 'wp_slash' )->returnArg();
		Functions\when( 'get_locale' )->justReturn( 'sv_SE' );
		Functions\when( 'rest_url' )->alias( static fn ( string $path ): string => 'https://shop.acme.example/wp-json/' . ltrim( $path, '/' ) );
		Functions\when( 'get_metadata' )->alias(
			function ( string $type, int $id, string $key, bool $single = false ): string|array {
				if ( $single ) {
					return $this->meta[ $key ] ?? '';
				}
				return $this->meta_rows[ $key ] ?? array();
			}
		);
		Functions\when( 'add_metadata' )->alias(
			function ( string $type, int $id, string $key, string $value ): int {
				$this->meta_rows[ $key ][] = $value;
				return $id;
			}
		);
		Functions\when( 'delete_metadata' )->alias(
			function ( string $type, int $id, string $key ): bool {
				unset( $this->meta_rows[ $key ] );
				return true;
			}
		);
		Functions\when( 'update_metadata' )->alias(
			function ( string $type, int $id, string $key, string $value, string $previous = '' ): bool {
				// An empty previous value writes unconditionally, as WordPress does.
				if ( '' !== $previous && ( $this->meta[ $key ] ?? null ) !== $previous ) {
					return false;
				}
				$this->meta[ $key ] = $value;
				return true;
			}
		);
		$order = Mockery::mock( WC_Order::class );
		$order->allows( 'get_id' )->andReturn( self::ORDER_ID );
		$order->allows( 'has_shipping_address' )->andReturn( true );
		$order->allows( 'get_address' )->with( 'shipping' )->andReturn( self::SHIPPING );
		$order->allows( 'get_billing_email' )->andReturn( 'ada@example.org' );
		$order->allows( 'get_billing_phone' )->andReturn( '+46 70 123 45 67' );
		$order->allows( 'add_order_note' )->andReturnUsing(
			function ( string $note ): int {
				$this->notes[] = $note;
				return 1;
			}
		);
		Functions\when( 'wc_get_order' )->justReturn( $order );
	}

	public function test_a_committed_line_is_approved_and_ordered_by_its_design_once(): void {
		$this->store_line( LineState::COMMITTED );
		$this->transport
			->json( 200, FulfilmentAnswers::approved() )
			->json( 200, FulfilmentAnswers::orders( array() ) )
			->json( 200, self::design( 'approved', self::REVISION ) )
			->json( 201, FulfilmentAnswers::order( 'o_1', array( 'p_front' ), self::EXTERNAL_REF ) );

		$this->fulfiller()->fulfil( $this->item() );

		$this->assertSame(
			array(
				'POST /v1/designs/' . self::DESIGN . '/approve',
				'GET /v1/orders?externalRef=' . rawurlencode( self::EXTERNAL_REF ),
				// The design is read again: it may have been edited since it was approved.
				'GET /v1/designs/' . self::DESIGN,
				'POST /v1/orders',
			),
			$this->sent()
		);
		$this->assertEquals(
			array(
				'consent'          => self::CONSENT + array( 'ref' => self::EXTERNAL_REF ),
				'expectedRevision' => self::REVISION,
			),
			$this->body( 0 )
		);
		$this->assertEquals(
			array(
				// Pixonaut renders the print and holds the order until it is done.
				'designs'         => array( array( 'designId' => self::DESIGN ) ),
				'quantity'        => self::QUANTITY,
				'externalRef'     => self::EXTERNAL_REF,
				'shipToAddressId' => null,
				'recipient'       => array(
					'name'  => 'Ada Lovelace',
					'email' => 'ada@example.org',
					'phone' => '+46 70 123 45 67',
				),
				'shippingAddress' => array(
					'company'    => 'Analytical Engines',
					'line1'      => 'Storgatan 1',
					'postalCode' => '111 22',
					'city'       => 'Stockholm',
					'country'    => 'SE',
				),
			),
			$this->body( 3 )
		);
		$this->assertSame( 'wc:' . self::SITE . ':item:42', $this->transport->sent[3]['headers']['Idempotency-Key'] );
		$this->assertArrayNotHasKey( 'Prefer', $this->transport->sent[3]['headers'] );

		$line = $this->line();
		$this->assertSame( LineState::ORDERED, $line->state() );
		$this->assertSame( 'o_1', $line->ref( LineState::REF_ORDER_ID ) );
		$this->assertSame( self::EXTERNAL_REF, $line->ref( LineState::REF_EXTERNAL_REF ) );
		$this->assertNull( $line->ref( LineState::REF_ORDER_REQUEST ), 'The order request, with the customer\'s address, is dropped once ordered.' );
	}

	public function test_a_line_of_two_sides_orders_its_designs_together_with_the_first_side_leading(): void {
		$this->store_line(
			LineState::COMMITTED,
			array(
				self::FRONT => self::DESIGN,
				self::BACK  => self::BACK_DESIGN,
			)
		);
		$this->transport
			->json( 200, FulfilmentAnswers::approved() )
			->json( 200, FulfilmentAnswers::approved() )
			->json( 200, FulfilmentAnswers::orders( array() ) )
			->json( 200, self::design( 'approved', self::REVISION ) )
			->json( 200, self::design( 'approved', self::REVISION, self::BACK_DESIGN ) )
			->json( 201, FulfilmentAnswers::order( 'o_1', array( 'p_front', 'p_back' ), self::EXTERNAL_REF ) );

		$this->fulfiller()->fulfil( $this->item() );

		$this->assertSame( 'POST /v1/designs/' . self::BACK_DESIGN . '/approve', $this->sent()[1] );
		$this->assertSame( 'GET /v1/designs/' . self::BACK_DESIGN, $this->sent()[4] );
		$this->assertSame( array( array( 'designId' => self::DESIGN ), array( 'designId' => self::BACK_DESIGN ) ), $this->body( 5 )['designs'] );
		$this->assertSame( LineState::ORDERED, $this->line()->state() );
	}

	public function test_the_order_names_the_variant_the_line_was_bought_as(): void {
		$this->store_line( LineState::COMMITTED );
		$this->sell_variant( 'v_tee_red_m' );
		$this->transport
			->json( 200, FulfilmentAnswers::approved() )
			->json( 200, FulfilmentAnswers::orders( array() ) )
			->json( 200, self::design( 'approved', self::REVISION ) )
			->json( 201, FulfilmentAnswers::order( 'o_1', array( 'p_front' ), self::EXTERNAL_REF ) );

		$this->fulfiller()->fulfil( $this->item() );

		$this->assertSame( 'v_tee_red_m', $this->body( 3 )['variant'] ?? null );
		$this->assertSame( LineState::ORDERED, $this->line()->state() );
	}

	public function test_an_order_refused_for_a_retired_variant_is_not_retried_and_says_why(): void {
		$this->store_line( LineState::COMMITTED );
		$this->sell_variant( 'v_tee_red_m' );
		$this->transport
			->json( 200, FulfilmentAnswers::approved() )
			->json( 200, FulfilmentAnswers::orders( array() ) )
			->json( 200, self::design( 'approved', self::REVISION ) )
			->json(
				422,
				array(
					'error'   => ErrorCode::VARIANT_RETIRED,
					'variant' => 'v_tee_red_m',
					'reason'  => 'The variant is retired.',
				)
			);

		try {
			$this->fulfiller()->fulfil( $this->item() );
			$this->fail( 'The refusal reaches the job, which flags the line.' );
		} catch ( ApiException $error ) {
			$this->assertSame( array( ErrorCode::VARIANT_RETIRED, false ), array( $error->errorCode, $error->retryable ) );
		}
		$this->assertStringContainsString( 'retired', NeedsAttention::explain( ErrorCode::VARIANT_RETIRED ) );
	}

	public function test_an_approved_line_goes_on_from_its_order(): void {
		$this->store_line( LineState::APPROVED );
		$this->transport
			->json( 200, FulfilmentAnswers::orders( array() ) )
			->json( 200, self::design( 'approved', self::REVISION ) )
			->json( 201, FulfilmentAnswers::order( 'o_1', array( 'p_front' ), self::EXTERNAL_REF ) );

		$this->fulfiller()->fulfil( $this->item() );

		$this->assertSame( array( 'GET /v1/orders?externalRef=' . rawurlencode( self::EXTERNAL_REF ), 'GET /v1/designs/' . self::DESIGN, 'POST /v1/orders' ), $this->sent() );
		$this->assertSame( LineState::ORDERED, $this->line()->state() );
	}

	public function test_an_approved_line_whose_design_was_edited_since_needs_attention_and_orders_nothing(): void {
		$this->store_line(
			LineState::APPROVED,
			order_request: array(
				'operation'       => 'orders.create',
				'target'          => '/v1/orders',
				'body'            => (string) json_encode( array( 'externalRef' => self::EXTERNAL_REF ) ), // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
				'idempotency_key' => 'wc:' . self::SITE . ':item:42',
			)
		);
		$this->transport
			->json( 200, FulfilmentAnswers::orders( array() ) )
			->json( 200, self::design( 'approved', self::REVISION + 1 ) );

		$this->fulfiller()->fulfil( $this->item() );

		$this->assertSame( array( 'GET /v1/orders?externalRef=' . rawurlencode( self::EXTERNAL_REF ), 'GET /v1/designs/' . self::DESIGN ), $this->sent() );
		$line = $this->line();
		$this->assertSame( LineState::NEEDS_ATTENTION, $line->state() );
		$this->assertSame( NeedsAttention::DESIGN_EDITED, $line->reason() );
		$this->assertNull( $line->ref( LineState::REF_ORDER_REQUEST ), 'Nothing was sent, so the request is dropped.' );
		$this->assertCount( 1, $this->notes );
		$this->assertStringContainsString( 'changed in Pixonaut after it was approved', $this->notes[0] );
	}

	public function test_a_line_an_earlier_version_printed_orders_its_prints(): void {
		$this->store_line( LineState::PRINTED, print_ids: array( self::FRONT => 'p_front' ) );
		$this->transport
			->json( 200, FulfilmentAnswers::orders( array() ) )
			->json( 201, FulfilmentAnswers::order( 'o_1', array( 'p_front' ), self::EXTERNAL_REF ) );

		$this->fulfiller()->fulfil( $this->item() );

		$this->assertSame( array( 'GET /v1/orders?externalRef=' . rawurlencode( self::EXTERNAL_REF ), 'POST /v1/orders' ), $this->sent() );
		$this->assertSame( array( array( 'printId' => 'p_front' ) ), $this->body( 1 )['prints'] ?? null );
		$this->assertArrayNotHasKey( 'designs', $this->body( 1 ) );
		$this->assertSame( LineState::ORDERED, $this->line()->state() );
	}

	public function test_a_design_with_a_live_order_the_line_did_not_place_stops_the_line_and_drops_the_request(): void {
		$this->store_line( LineState::APPROVED );
		$this->transport
			->json( 200, FulfilmentAnswers::orders( array() ) )
			->json( 200, self::design( 'approved', self::REVISION ) )
			->json(
				409,
				array(
					'error'    => ErrorCode::DESIGN_ALREADY_ORDERED,
					'designId' => self::DESIGN,
					'orderId'  => 'o_studio',
				)
			)
			->json( 200, FulfilmentAnswers::placed_by( FulfilmentAnswers::read_order( 'o_studio', 'studio-ref' ), 'member' ) );

		try {
			$this->fulfiller()->fulfil( $this->item() );
			$this->fail( 'The refusal reaches the job, which flags the line.' );
		} catch ( ApiException $error ) {
			$this->assertSame( array( ErrorCode::DESIGN_ALREADY_ORDERED, false ), array( $error->errorCode, $error->retryable ) );
			$this->assertSame( 'o_studio', $error->details['orderId'] ?? null );
		}
		$line = $this->line();
		$this->assertSame( LineState::APPROVED, $line->state() );
		$this->assertNull( $line->ref( LineState::REF_ORDER_REQUEST ), 'Nothing was written, so an order again is a new request.' );
		$this->assertStringContainsString( 'Order again anyway', NeedsAttention::explain( ErrorCode::DESIGN_ALREADY_ORDERED ) );
	}

	public function test_a_line_the_merchant_reorders_sends_reorder_under_a_key_of_its_own(): void {
		$this->store_line( LineState::APPROVED );
		$line = $this->line();
		$line->set_ref( LineState::REF_REORDER, true );
		$line->set_ref( LineState::REF_ORDER_KEY, null );
		$this->assertTrue( $line->save() );
		$this->transport
			->json( 200, FulfilmentAnswers::orders( array() ) )
			->json( 200, self::design( 'approved', self::REVISION ) )
			->json( 201, FulfilmentAnswers::order( 'o_again', array( 'p_front' ), self::EXTERNAL_REF ) );

		$this->fulfiller()->fulfil( $this->item() );

		$this->assertTrue( $this->body( 2 )['reorder'] ?? null );
		$this->assertSame( 'wc:' . self::SITE . ':item:42:reorder', $this->transport->sent[2]['headers']['Idempotency-Key'] );
		$this->assertSame( 'o_again', $this->line()->ref( LineState::REF_ORDER_ID ) );
	}

	public function test_an_order_placed_under_the_lines_ref_is_adopted_rather_than_placed_again(): void {
		$this->store_line( LineState::PRINTED, print_ids: array( self::FRONT => 'p_front' ) );
		$this->transport->json( 200, FulfilmentAnswers::orders( array( FulfilmentAnswers::order( 'o_placed', array( 'p_front' ), self::EXTERNAL_REF ) ) ) );

		$this->fulfiller()->fulfil( $this->item() );

		$this->assertCount( 1, $this->transport->sent );
		$this->assertSame( 'o_placed', $this->line()->ref( LineState::REF_ORDER_ID ) );
		$this->assertSame( LineState::ORDERED, $this->line()->state() );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function unreadable_order_lookups(): array {
		return array(
			'a null row'          => array( '{"orders":[null]}' ),
			'a string row'        => array( '{"orders":["o_1"]}' ),
			'an order without id' => array( '{"orders":[{}]}' ),
			'an order with no id' => array( '{"orders":[{"id":""}]}' ),
			'a map, not a list'   => array( '{"orders":{"o_1":{"id":"o_1"}}}' ),
			'a list row'          => array( '{"orders":[["o_1"]]}' ),
		);
	}

	/**
	 * @dataProvider unreadable_order_lookups
	 *
	 * @param string $body The lookup's answer.
	 */
	public function test_an_unreadable_order_lookup_fails_rather_than_placing_a_second_order( string $body ): void {
		$this->store_line( LineState::PRINTED, print_ids: array( self::FRONT => 'p_front' ) );
		$this->transport->off_spec( new Response( 200, array( 'content-type' => 'application/json' ), $body ) );

		try {
			$this->fulfiller()->fulfil( $this->item() );
			$this->fail( 'The unreadable lookup read as no order.' );
		} catch ( ApiException $e ) {
			$this->assertSame( ApiException::UNEXPECTED_RESPONSE, $e->errorCode );
		}

		$this->assertSame( array( 'GET /v1/orders?externalRef=' . rawurlencode( self::EXTERNAL_REF ) ), $this->sent() );
		$this->assertSame( LineState::PRINTED, $this->line()->state() );
	}

	public function test_a_line_whose_move_failed_at_checkout_is_moved_before_it_is_approved_and_ordered(): void {
		$this->store_line(
			LineState::COMMITTED,
			array(
				self::FRONT => self::DESIGN,
				self::BACK  => self::BACK_DESIGN,
			),
			external_ref: self::CART_REF,
			moved: false
		);
		$this->transport
			->json( 200, self::moved_to( self::DESIGN, self::EXTERNAL_REF ) )
			->json( 200, self::moved_to( self::BACK_DESIGN, self::EXTERNAL_REF ) )
			->json( 200, FulfilmentAnswers::approved() )
			->json( 200, FulfilmentAnswers::approved() )
			->json( 200, FulfilmentAnswers::orders( array() ) )
			->json( 200, self::design( 'approved', self::REVISION ) )
			->json( 200, self::design( 'approved', self::REVISION, self::BACK_DESIGN ) )
			->json( 201, FulfilmentAnswers::order( 'o_1', array( 'p_front', 'p_back' ), self::EXTERNAL_REF ) );

		$this->fulfiller()->fulfil( $this->item() );

		$this->assertSame(
			array(
				'PUT /v1/designs/' . self::DESIGN . '/external-ref',
				'PUT /v1/designs/' . self::BACK_DESIGN . '/external-ref',
			),
			array_slice( $this->sent(), 0, 2 )
		);
		$this->assertSame( array( 'externalRef' => self::EXTERNAL_REF ), $this->body( 0 ) );
		$this->assertSame( array( 'externalRef' => self::EXTERNAL_REF ), $this->body( 1 ) );
		$this->assertSame( self::EXTERNAL_REF, $this->body( 2 )['consent']['ref'] );
		$this->assertSame( 'GET /v1/orders?externalRef=' . rawurlencode( self::EXTERNAL_REF ), $this->sent()[4] );
		$this->assertSame( self::EXTERNAL_REF, $this->body( 7 )['externalRef'] );
		$line = $this->line();
		$this->assertSame( LineState::ORDERED, $line->state() );
		$this->assertSame( self::EXTERNAL_REF, $line->ref( LineState::REF_EXTERNAL_REF ) );
		$this->assertTrue( $line->ref( LineState::REF_EXTERNAL_REF_MOVED ) );
	}

	public function test_a_printed_line_still_under_its_cart_ref_is_moved_before_its_order(): void {
		$this->store_line( LineState::PRINTED, print_ids: array( self::FRONT => 'p_front' ), external_ref: self::CART_REF, moved: false );
		$this->transport
			->json( 200, self::moved_to( self::DESIGN, self::EXTERNAL_REF ) )
			->json( 200, FulfilmentAnswers::orders( array() ) )
			->json( 201, FulfilmentAnswers::order( 'o_1', array( 'p_front' ), self::EXTERNAL_REF ) );

		$this->fulfiller()->fulfil( $this->item() );

		$this->assertSame(
			array(
				'PUT /v1/designs/' . self::DESIGN . '/external-ref',
				'GET /v1/orders?externalRef=' . rawurlencode( self::EXTERNAL_REF ),
				'POST /v1/orders',
			),
			$this->sent()
		);
		$this->assertSame( self::EXTERNAL_REF, $this->body( 2 )['externalRef'] );
		$this->assertSame( LineState::ORDERED, $this->line()->state() );
	}

	public function test_a_move_that_fails_again_stops_the_line_before_anything_else_is_sent(): void {
		$this->store_line( LineState::COMMITTED, external_ref: self::CART_REF, moved: false );
		$this->transport->json( 500, array( 'error' => 'db_error' ) );

		try {
			$this->fulfiller()->fulfil( $this->item() );
			$this->fail( 'The line went on under its cart ref.' );
		} catch ( ApiException $e ) {
			$this->assertSame( 'db_error', $e->errorCode );
		}

		$this->assertSame( array( 'PUT /v1/designs/' . self::DESIGN . '/external-ref' ), $this->sent() );
		$line = $this->line();
		$this->assertSame( LineState::COMMITTED, $line->state() );
		$this->assertSame( self::CART_REF, $line->ref( LineState::REF_EXTERNAL_REF ) );
		$this->assertNull( $line->ref( LineState::REF_EXTERNAL_REF_MOVED ) );

		// The next run makes the move and goes on.
		$this->transport
			->json( 200, self::moved_to( self::DESIGN, self::EXTERNAL_REF ) )
			->json( 200, FulfilmentAnswers::approved() )
			->json( 200, FulfilmentAnswers::orders( array() ) )
			->json( 200, self::design( 'approved', self::REVISION ) )
			->json( 201, FulfilmentAnswers::order( 'o_1', array( 'p_front' ), self::EXTERNAL_REF ) );
		$this->fulfiller()->fulfil( $this->item() );

		$this->assertSame( self::EXTERNAL_REF, $this->body( 5 )['externalRef'] );
		$this->assertSame( LineState::ORDERED, $this->line()->state() );
	}

	public function test_a_line_moved_after_a_reconnect_keeps_the_order_line_ref_it_stored(): void {
		$this->store_line( LineState::COMMITTED, moved: false );
		$this->connection = self::connection( self::NEW_SITE, FulfilmentAnswers::TENANT_ID );
		$this->transport
			->json( 200, self::moved_to( self::DESIGN, self::EXTERNAL_REF ) )
			->json( 200, FulfilmentAnswers::approved() )
			->json( 200, FulfilmentAnswers::orders( array() ) )
			->json( 200, self::design( 'approved', self::REVISION ) )
			->json( 201, FulfilmentAnswers::order( 'o_1', array( 'p_front' ), self::EXTERNAL_REF ) );

		$this->fulfiller()->fulfil( $this->item() );

		$this->assertSame( 'PUT /v1/designs/' . self::DESIGN . '/external-ref', $this->sent()[0] );
		$this->assertSame( array( 'externalRef' => self::EXTERNAL_REF ), $this->body( 0 ) );
		$this->assertSame( self::EXTERNAL_REF, $this->body( 1 )['consent']['ref'] );
		$this->assertSame( self::EXTERNAL_REF, $this->body( 4 )['externalRef'] );
		$this->assertSame( self::EXTERNAL_REF, $this->line()->ref( LineState::REF_EXTERNAL_REF ) );
		$this->assertSame( LineState::ORDERED, $this->line()->state() );
	}

	public function test_a_line_whose_order_was_sent_under_its_cart_ref_needs_attention_and_sends_nothing(): void {
		$this->store_line(
			LineState::PRINTED,
			print_ids: array( self::FRONT => 'p_front' ),
			external_ref: self::CART_REF,
			moved: false,
			order_request: array(
				'operation'       => 'orders.create',
				'target'          => '/v1/orders',
				'body'            => (string) json_encode( array( 'externalRef' => self::CART_REF ) ), // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
				'idempotency_key' => 'wc:' . self::SITE . ':item:42',
			)
		);

		$this->fulfiller()->fulfil( $this->item() );

		$this->assertSame( array(), $this->transport->sent );
		$line = $this->line();
		$this->assertSame( LineState::NEEDS_ATTENTION, $line->state() );
		$this->assertSame( self::CART_REF, $line->ref( LineState::REF_EXTERNAL_REF ) );
		$this->assertCount( 1, $this->notes );
	}

	public function test_an_ordered_or_shipped_line_sends_nothing(): void {
		foreach ( array( LineState::ORDERED, LineState::SHIPPED, LineState::NEEDS_ATTENTION ) as $state ) {
			$this->store_line( $state, external_ref: self::CART_REF, moved: false );

			$this->fulfiller()->fulfil( $this->item() );

			$this->assertSame( array(), $this->transport->sent, $state );
		}
	}

	public function test_a_resumed_line_sends_its_stored_request_byte_for_byte_after_a_reconnect(): void {
		$this->store_line( LineState::APPROVED );
		$this->transport->json( 200, FulfilmentAnswers::orders( array() ) )
			->json( 200, self::design( 'approved', self::REVISION ) )
			->fail( 'cURL error 28: Operation timed out' );
		try {
			$this->fulfiller()->fulfil( $this->item() );
			$this->fail( 'The order went through.' );
		} catch ( ApiException $e ) {
			$this->assertSame( 'transport_failed', $e->errorCode );
		}
		$first_order = $this->transport->sent[2];

		// The store reconnected: a new `<site>`, which the stored line ignores.
		$this->connection = self::connection( self::NEW_SITE, FulfilmentAnswers::TENANT_ID );
		$this->transport->json( 200, FulfilmentAnswers::orders( array() ) )
			->json( 200, self::design( 'approved', self::REVISION ) )
			->json( 201, FulfilmentAnswers::order( 'o_1', array( 'p_front' ), self::EXTERNAL_REF ) );
		$this->fulfiller()->fulfil( $this->item() );

		$this->assertSame( 'GET /v1/orders?externalRef=' . rawurlencode( self::EXTERNAL_REF ), $this->sent()[3] );
		$this->assertSame( $first_order['body'], $this->transport->sent[5]['body'] );
		$this->assertSame( $first_order['headers']['Idempotency-Key'], $this->transport->sent[5]['headers']['Idempotency-Key'] );
		$this->assertSame( LineState::ORDERED, $this->line()->state() );
	}

	public function test_a_line_of_another_workspace_is_never_acted_on(): void {
		$this->store_line( LineState::COMMITTED );
		$this->connection = self::connection( self::SITE, '0b1c2d3e-4f50-4a6b-8c7d-0000000000ff' );

		$this->fulfiller()->fulfil( $this->item() );

		$this->assertSame( array(), $this->transport->sent );
		$this->assertSame( LineState::COMMITTED, $this->line()->state() );
		$this->assertCount( 1, $this->notes );
	}

	public function test_nothing_is_sent_while_the_store_is_not_connected(): void {
		$this->store_line( LineState::COMMITTED );
		$this->connection = null;

		$this->fulfiller()->fulfil( $this->item() );

		$this->assertSame( array(), $this->transport->sent );
		$this->assertSame( LineState::COMMITTED, $this->line()->state() );
	}

	public function test_a_proof_by_email_line_gets_a_proof_page_and_waits_for_the_customer(): void {
		$this->store_line( LineState::COMMITTED, mode: ApprovalMode::PROOF_EMAIL, consent: null );
		$this->transport
			->json( 200, DesignFixture::design( array( 'revision' => self::REVISION ) ) )
			->json( 201, self::proof_page() );

		$this->fulfiller()->fulfil( $this->item() );

		$this->assertSame(
			array(
				'GET /v1/designs/' . self::DESIGN,
				'POST /v1/share-links',
			),
			$this->sent()
		);
		$link = $this->body( 1 );
		$this->assertSame( 'proof-page', $link['kind'] );
		$this->assertSame( self::DESIGN, $link['subjectId'] );
		$this->assertTrue( $link['mayDecide'] );
		$this->assertSame( self::DEADLINE_DAYS + ProofLink::MARGIN_DAYS, $link['expiresInDays'] );
		$this->assertSame( 'sv', $link['locale'] );
		$this->assertSame( self::RETURN_URL, $link['returnUrl'] );
		$this->assertSame(
			array(
				'order_id'  => self::ORDER_ID,
				'item_id'   => self::ITEM_ID,
				'design_id' => self::DESIGN,
				'revision'  => self::REVISION,
			),
			( new ProofState( self::STATE_KEY ) )->verify( $link['state'] )
		);
		$line = $this->line();
		$this->assertSame( LineState::AWAITING_CUSTOMER, $line->state() );
		$this->assertSame( self::PROOF_URL, $line->side_ref( self::FRONT, LineState::SIDE_PROOF_URL ) );
		$this->assertCount( 1, $this->notes );
	}

	public function test_a_proof_by_email_line_whose_design_is_approved_already_goes_on_without_a_proof(): void {
		$this->store_line( LineState::COMMITTED, mode: ApprovalMode::PROOF_EMAIL, consent: null );
		$this->transport
			->json(
				200,
				DesignFixture::design(
					array(
						'revision' => self::REVISION,
						'status'   => 'approved',
					)
				)
			)
			->json( 200, FulfilmentAnswers::orders( array() ) )
			->json( 200, self::design( 'approved', self::REVISION ) )
			->json( 201, FulfilmentAnswers::order( 'o_1', array( 'p_front' ), self::EXTERNAL_REF ) );

		$this->fulfiller()->fulfil( $this->item() );

		$this->assertSame(
			array(
				'GET /v1/designs/' . self::DESIGN,
				'GET /v1/orders?externalRef=' . rawurlencode( self::EXTERNAL_REF ),
				'GET /v1/designs/' . self::DESIGN,
				'POST /v1/orders',
			),
			$this->sent()
		);
		$this->assertSame( LineState::ORDERED, $this->line()->state() );
	}

	public function test_a_refused_proof_page_needs_attention_with_the_code(): void {
		$this->store_line( LineState::COMMITTED, mode: ApprovalMode::PROOF_EMAIL, consent: null );
		$this->transport
			->json( 200, DesignFixture::design( array( 'revision' => self::REVISION ) ) )
			->json( 400, array( 'error' => 'return_url_off_store' ) );

		$this->fulfiller()->fulfil( $this->item() );

		$line = $this->line();
		$this->assertSame( LineState::NEEDS_ATTENTION, $line->state() );
		$this->assertSame( 'return_url_off_store', $line->reason() );
		$this->assertStringContainsString( 'return_url_off_store', $this->notes[0] );
	}

	public function test_a_proof_page_minted_before_is_not_minted_again(): void {
		$this->store_line(
			LineState::COMMITTED,
			array(
				self::FRONT => self::DESIGN,
				self::BACK  => self::BACK_DESIGN,
			),
			mode: ApprovalMode::PROOF_EMAIL,
			consent: null
		);
		$this->transport
			->json( 200, DesignFixture::design( array( 'revision' => self::REVISION ) ) )
			->json( 200, DesignFixture::design( array( 'revision' => self::REVISION ) ) )
			->json( 201, self::proof_page() )
			->off_spec( new Response( 503, array(), '' ) );

		try {
			$this->fulfiller()->fulfil( $this->item() );
			$this->fail( 'A proof page Pixonaut could not mint must fail the run.' );
		} catch ( ApiException $error ) {
			$this->assertTrue( $error->retryable );
		}
		$this->transport->sent = array();
		$this->transport
			->json( 200, DesignFixture::design( array( 'revision' => self::REVISION ) ) )
			->json( 200, DesignFixture::design( array( 'revision' => self::REVISION ) ) )
			->json( 201, self::proof_page() );

		$this->fulfiller()->fulfil( $this->item() );

		$this->assertSame( array( 'GET /v1/designs/' . self::DESIGN, 'GET /v1/designs/' . self::BACK_DESIGN, 'POST /v1/share-links' ), $this->sent() );
		$this->assertSame( self::BACK_DESIGN, $this->body( 2 )['subjectId'] );
		$this->assertSame( LineState::AWAITING_CUSTOMER, $this->line()->state() );
	}

	public function test_a_line_without_the_customers_consent_is_not_approved(): void {
		$this->store_line( LineState::COMMITTED, consent: null );

		$this->fulfiller()->fulfil( $this->item() );

		$this->assertSame( array(), $this->transport->sent );
		$this->assertCount( 1, $this->notes );
	}

	public function test_a_line_that_changed_elsewhere_stops_without_sending(): void {
		$this->store_line( LineState::APPROVED );
		$item = $this->item();
		// Another run stores its print request between this run's reads.
		Functions\when( 'update_metadata' )->justReturn( false );

		$this->fulfiller()->fulfil( $item );

		$this->assertSame( array(), $this->transport->sent );
	}

	public function test_an_approval_refused_as_not_committed_goes_on_when_the_design_is_approved_at_the_lines_revision(): void {
		$this->store_line( LineState::COMMITTED );
		$this->transport
			->json( 409, array( 'error' => 'not_committed' ) )
			->json( 200, self::design( 'approved', self::REVISION ) )
			->json( 200, FulfilmentAnswers::orders( array() ) )
			->json( 200, self::design( 'approved', self::REVISION ) )
			->json( 201, FulfilmentAnswers::order( 'o_1', array( 'p_front' ), self::EXTERNAL_REF ) );

		$this->fulfiller()->fulfil( $this->item() );

		$this->assertSame( 'GET /v1/designs/' . self::DESIGN, $this->sent()[1] );
		$this->assertSame( LineState::ORDERED, $this->line()->state() );
	}

	/**
	 * @return iterable<string, array{string, int}>
	 */
	public static function designs_not_approved_at_the_lines_revision(): iterable {
		yield 'approved at another revision' => array( 'approved', self::REVISION + 1 );
		yield 'rejected' => array( 'rejected', self::REVISION );
	}

	/**
	 * @dataProvider designs_not_approved_at_the_lines_revision
	 *
	 * @param string $status   The design's status.
	 * @param int    $revision Its revision.
	 */
	public function test_an_approval_refused_as_not_committed_stops_when_the_design_is_not_approved_at_the_lines_revision( string $status, int $revision ): void {
		$this->store_line( LineState::COMMITTED );
		$this->transport
			->json( 409, array( 'error' => 'not_committed' ) )
			->json( 200, self::design( $status, $revision ) );

		try {
			$this->fulfiller()->fulfil( $this->item() );
			$this->fail( 'The line went on.' );
		} catch ( ApiException $e ) {
			$this->assertSame( ErrorCode::NOT_COMMITTED, $e->errorCode );
		}
		$this->assertCount( 2, $this->transport->sent );
		$this->assertSame( LineState::COMMITTED, $this->line()->state() );
	}

	public function test_an_order_placed_as_failed_needs_attention_and_keeps_its_id(): void {
		$this->store_line( LineState::PRINTED, print_ids: array( self::FRONT => 'p_front' ) );
		$this->transport
			->json( 200, FulfilmentAnswers::orders( array() ) )
			->json(
				201,
				FulfilmentAnswers::order(
					'o_failed',
					array( 'p_front' ),
					self::EXTERNAL_REF,
					array(
						'status'    => 'failed',
						'lastError' => 'The printshop refused the order.',
					)
				)
			);

		$this->fulfiller()->fulfil( $this->item() );

		$line = $this->line();
		$this->assertSame( LineState::NEEDS_ATTENTION, $line->state() );
		$this->assertSame( LineState::PRINTED, $line->resume_from() );
		$this->assertSame( 'order_failed', $line->reason() );
		$this->assertSame( 'o_failed', $line->ref( LineState::REF_ORDER_ID ) );
		$this->assertSame( 'failed', $line->ref( LineState::REF_ORDER_STATUS ) );
		$this->assertNull( $line->ref( LineState::REF_ORDER_REQUEST ) );
		$this->assertCount( 1, $this->notes );
		$this->assertStringContainsString( 'The printshop refused the order.', $this->notes[0] );
	}

	public function test_an_order_placed_with_a_status_the_line_cannot_go_on_from_needs_attention(): void {
		$this->store_line( LineState::PRINTED, print_ids: array( self::FRONT => 'p_front' ) );
		$this->transport
			->json( 200, FulfilmentAnswers::orders( array() ) )
			->json( 201, FulfilmentAnswers::order( 'o_1', array( 'p_front' ), self::EXTERNAL_REF, array( 'status' => 'teleported' ) ) );

		$this->fulfiller()->fulfil( $this->item() );

		$line = $this->line();
		$this->assertSame( LineState::NEEDS_ATTENTION, $line->state() );
		$this->assertSame( 'order_status', $line->reason() );
		$this->assertSame( 'o_1', $line->ref( LineState::REF_ORDER_ID ) );
		$this->assertStringContainsString( 'teleported', $this->notes[0] );
	}

	public function test_an_order_whose_outcome_is_unknown_needs_the_merchant_to_ask_the_printshop(): void {
		$this->store_line( LineState::PRINTED, print_ids: array( self::FRONT => 'p_front' ) );
		$this->transport
			->json( 200, FulfilmentAnswers::orders( array() ) )
			->json( 201, FulfilmentAnswers::order( 'o_1', array( 'p_front' ), self::EXTERNAL_REF, array( 'status' => 'outcome_unknown' ) ) );

		$this->fulfiller()->fulfil( $this->item() );

		$line = $this->line();
		$this->assertSame( LineState::NEEDS_ATTENTION, $line->state() );
		$this->assertSame( NeedsAttention::OUTCOME_UNKNOWN, $line->reason() );
		$this->assertSame( 'o_1', $line->ref( LineState::REF_ORDER_ID ) );
		$this->assertSame( 'outcome_unknown', $line->ref( LineState::REF_ORDER_STATUS ) );
		$this->assertStringContainsString( 'Check with the printshop', $this->notes[0] );
	}

	public function test_an_adopted_order_that_failed_needs_attention(): void {
		$this->store_line( LineState::PRINTED, print_ids: array( self::FRONT => 'p_front' ) );
		$this->transport->json( 200, FulfilmentAnswers::orders( array( FulfilmentAnswers::order( 'o_placed', array( 'p_front' ), self::EXTERNAL_REF, array( 'status' => 'failed' ) ) ) ) );

		$this->fulfiller()->fulfil( $this->item() );

		$this->assertCount( 1, $this->transport->sent );
		$this->assertSame( LineState::NEEDS_ATTENTION, $this->line()->state() );
		$this->assertSame( 'o_placed', $this->line()->ref( LineState::REF_ORDER_ID ) );
	}

	public function test_an_order_listed_without_a_field_the_store_does_not_read_is_adopted(): void {
		$this->store_line( LineState::PRINTED, print_ids: array( self::FRONT => 'p_front' ) );
		$placed = FulfilmentAnswers::order( 'o_placed', array( 'p_front' ), self::EXTERNAL_REF );
		unset( $placed['adapter'], $placed['printshopAsPlaced'], $placed['submittedAt'] );
		$this->transport->off_spec( self::answer( FulfilmentAnswers::orders( array( $placed ) ) ) );

		$this->fulfiller()->fulfil( $this->item() );

		$this->assertSame( 'o_placed', $this->line()->ref( LineState::REF_ORDER_ID ) );
		$this->assertSame( LineState::ORDERED, $this->line()->state() );
	}

	public function test_a_design_read_without_a_field_the_store_does_not_read_still_tells_the_approval(): void {
		$this->store_line( LineState::COMMITTED );
		$design = self::design( 'approved', self::REVISION );
		unset( $design['garmentTone'], $design['canDecide'] );
		$this->transport
			->json( 409, array( 'error' => 'not_committed' ) )
			->off_spec( self::answer( $design ) )
			->json( 200, FulfilmentAnswers::orders( array() ) )
			->off_spec( self::answer( $design ) )
			->json( 201, FulfilmentAnswers::order( 'o_1', array( 'p_front' ), self::EXTERNAL_REF ) );

		$this->fulfiller()->fulfil( $this->item() );

		$this->assertSame( LineState::ORDERED, $this->line()->state() );
	}

	public function test_a_held_order_stores_its_hold_as_before(): void {
		Functions\when( 'wp_date' )->justReturn( '1 January 2027' );
		Functions\when( '_n' )->alias( static fn ( string $single, string $plural, int $count ): string => 1 === $count ? $single : $plural );
		$this->store_line( LineState::PRINTED, print_ids: array( self::FRONT => 'p_front' ) );
		$this->transport
			->json( 200, FulfilmentAnswers::orders( array() ) )
			->json(
				201,
				FulfilmentAnswers::order(
					'o_held',
					array( 'p_front' ),
					self::EXTERNAL_REF,
					array(
						'status' => 'held',
						'hold'   => array(
							'reason'    => 'monthly_order_limit',
							'limit'     => self::MONTHLY_LIMIT,
							'periodEnd' => self::PERIOD_END,
						),
					)
				)
			);

		$this->fulfiller()->fulfil( $this->item() );

		$line = $this->line();
		$this->assertSame( LineState::ORDERED, $line->state() );
		$this->assertSame( 'held', $line->ref( LineState::REF_ORDER_STATUS ) );
		$this->assertSame(
			array(
				'reason'    => 'monthly_order_limit',
				'limit'     => self::MONTHLY_LIMIT,
				'periodEnd' => strtotime( self::PERIOD_END ),
			),
			$line->ref( LineState::REF_HOLD )
		);
		$this->assertStringContainsString( 'is held', $this->notes[0] );
	}

	public function test_an_order_an_automation_placed_is_taken_on_with_its_automation_ref_and_address(): void {
		$this->store_line( LineState::APPROVED );
		$recipient = array(
			'name'  => 'Ada Lovelace',
			'email' => 'ada@example.org',
			'phone' => '+46 70 123 45 67',
		);
		$address   = array(
			'company'    => 'Analytical Engines',
			'line1'      => 'Storgatan 1',
			'postalCode' => '111 22',
			'city'       => 'Stockholm',
			'country'    => 'SE',
		);
		$print     = FulfilmentAnswers::order( 'o_auto', array( 'p_front' ), 'automation-ref' )['prints'][0];
		$read      = FulfilmentAnswers::read_order(
			'o_auto',
			'automation-ref',
			array(
				'quantity'        => self::QUANTITY,
				'prints'          => array(
					array(
						'designId'       => self::DESIGN,
						'designRevision' => self::REVISION,
					) + $print,
				),
				'recipient'       => $recipient,
				'shippingAddress' => $address,
			)
		);
		$this->transport
			->json( 200, FulfilmentAnswers::orders( array() ) )
			->json( 200, self::design( 'approved', self::REVISION ) )
			->json(
				409,
				array(
					'error'    => ErrorCode::DESIGN_ALREADY_ORDERED,
					'designId' => self::DESIGN,
					'orderId'  => 'o_auto',
				)
			)
			->json(
				200,
				FulfilmentAnswers::placed_by(
					$read,
					'automation',
					array(
						'id'   => 'au_1',
						'name' => 'Order approved designs',
					)
				)
			);

		$this->fulfiller()->fulfil( $this->item() );

		$line = $this->line();
		$this->assertSame( LineState::ORDERED, $line->state() );
		$this->assertSame( 'o_auto', $line->ref( LineState::REF_ORDER_ID ) );
		$this->assertSame( 'Order approved designs', $line->ref( LineState::REF_ORDER_AUTOMATION ) );
		$this->assertSame( 'automation-ref', $line->ref( LineState::REF_ORDER_EXTERNAL_REF ) );
		$sent = array(
			'recipient'       => self::sorted( $recipient ),
			'shippingAddress' => self::sorted( $address ),
		);
		$this->assertSame( array( 'address' => hash( 'sha256', (string) json_encode( $sent ) ) ), $line->ref( LineState::REF_DELIVERY ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		$this->assertStringContainsString( 'as order o_auto', $this->notes[0] );
	}

	private function fulfiller(): Fulfiller {
		$client = $this->transport->sdk( ScriptedClient::credentials(), new RecordingLogger() );
		return new Fulfiller(
			$client,
			fn (): ?StoredConnection => $this->connection,
			new ProofRequest( $client, new ProofState( self::STATE_KEY ), static fn (): int => self::DEADLINE_DAYS, static fn ( string $locale ): ?string => DesignerLanguage::for_locale( $locale ) )
		);
	}

	/**
	 * Stores a line as checkout leaves it, moved on to `$state`.
	 *
	 * @param string                $state        The line's state.
	 * @param array<string, string> $designs      Design id per side, in side order.
	 * @param array<string, string> $print_ids    Print id per side.
	 * @param string                $mode         Approval mode.
	 * @param array<string, mixed>  $consent      The customer's consent.
	 * @param string                $external_ref The ref the designs were last moved to.
	 * @param bool                  $moved        Whether checkout moved them to the order line ref.
	 * @param array<string, mixed>  $order_request A stored printshop order request whose outcome is unknown.
	 */
	private function store_line( string $state, array $designs = array( self::FRONT => self::DESIGN ), array $print_ids = array(), string $mode = ApprovalMode::ADD_TO_CART, ?array $consent = self::CONSENT, string $external_ref = self::EXTERNAL_REF, bool $moved = true, ?array $order_request = null ): void {
		$sides = array();
		foreach ( $designs as $key => $design_id ) {
			$sides[] = array_filter(
				array(
					'key'                     => $key,
					LineState::SIDE_DESIGN_ID => $design_id,
					LineState::SIDE_REVISION  => self::REVISION,
					LineState::SIDE_PRINT_ID  => $print_ids[ $key ] ?? null,
				)
			);
		}
		$this->meta[ LineState::META_KEY ] = (string) json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			array(
				'v'          => LineState::SCHEMA_VERSION,
				'state'      => $state,
				'resumeFrom' => LineState::NEEDS_ATTENTION === $state ? LineState::PRINTED : null,
				'reason'     => null,
				'changedAt'  => 0,
				'refs'       => array_filter(
					array(
						LineState::REF_TENANT_ID          => FulfilmentAnswers::TENANT_ID,
						LineState::REF_APPROVAL_MODE      => $mode,
						LineState::REF_CONSENT            => $consent,
						LineState::REF_EXTERNAL_REF       => $external_ref,
						LineState::REF_EXTERNAL_REF_MOVED => $moved,
						LineState::REF_ORDER_REQUEST      => $order_request,
					)
				),
				'sides'      => $sides,
			)
		);
	}

	/**
	 * Records on the stored line the blank variant its product sold.
	 *
	 * @param string $ref The variant's ref.
	 */
	private function sell_variant( string $ref ): void {
		$document                                   = json_decode( $this->meta[ LineState::META_KEY ], true );
		$document['refs'][ LineState::REF_VARIANT ] = $ref;
		$this->meta[ LineState::META_KEY ]          = (string) json_encode( $document ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	/**
	 * @return WC_Order_Item_Product&MockInterface
	 */
	private function item(): WC_Order_Item_Product {
		/** @var WC_Order_Item_Product&MockInterface $item */
		$item = Mockery::mock( WC_Order_Item_Product::class );
		$item->allows( 'get_id' )->andReturn( self::ITEM_ID );
		$item->allows( 'get_order_id' )->andReturn( self::ORDER_ID );
		$item->allows( 'get_quantity' )->andReturn( self::QUANTITY );
		$item->allows( 'get_name' )->andReturn( 'Personalised tee' );
		$item->allows( 'get_meta' )->andReturn( '' );
		return $item;
	}

	private function line(): LineState {
		return LineState::of( $this->item() );
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
	 * @param int $index A sent request.
	 * @return array<string, mixed> Its decoded body.
	 */
	private function body( int $index ): array {
		$body = json_decode( (string) $this->transport->sent[ $index ]['body'], true );
		$this->assertIsArray( $body );
		return $body;
	}

	/**
	 * @param array<string, mixed> $body An answer the spec may not declare.
	 */
	private static function answer( array $body ): Response {
		return new Response( 200, array( 'content-type' => 'application/json' ), (string) json_encode( $body ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	/**
	 * @param array<string, string> $fields An address part as sent.
	 * @return array<string, string> Sorted by key, as fingerprints are taken.
	 */
	private static function sorted( array $fields ): array {
		ksort( $fields );
		return $fields;
	}

	/**
	 * `200` of `GET /v1/designs/{id}`, for the line's front design unless named.
	 *
	 * @param string $status    Its status.
	 * @param int    $revision  Its revision.
	 * @param string $design_id The design.
	 *
	 * @return array<string, mixed>
	 */
	private static function design( string $status, int $revision, string $design_id = self::DESIGN ): array {
		return DesignFixture::design(
			array(
				'id'       => $design_id,
				'status'   => $status,
				'revision' => $revision,
			)
		);
	}

	/**
	 * `201` of `POST /v1/share-links` for a proof page.
	 *
	 * @return array<string, mixed>
	 */
	private static function proof_page(): array {
		return array(
			'id'             => 'sl_1',
			'kind'           => 'proof-page',
			'subjectId'      => self::DESIGN,
			'mayDecide'      => true,
			'returnUrl'      => self::RETURN_URL,
			'name'           => null,
			'brandId'        => null,
			'designLimit'    => null,
			'printAreaFrame' => false,
			'placementFrame' => false,
			'locale'         => null,
			'token'          => 'link_proof',
			'url'            => self::PROOF_URL,
			'createdAt'      => 1_800_000_000_000,
			'expiresAt'      => 1_801_814_400_000,
		);
	}

	/**
	 * The answer to `PUT /v1/designs/{id}/external-ref`.
	 *
	 * @param string $design_id    The design.
	 * @param string $external_ref Its new ref.
	 * @return array<string, string>
	 */
	private static function moved_to( string $design_id, string $external_ref ): array {
		return array(
			'designId'    => $design_id,
			'externalRef' => $external_ref,
		);
	}

	private static function connection( string $site, string $tenant_id ): StoredConnection {
		return new StoredConnection(
			$site,
			'https://shop.acme.example',
			0,
			$tenant_id,
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
