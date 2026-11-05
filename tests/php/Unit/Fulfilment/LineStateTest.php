<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Fulfilment;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use LogicException;
use Mockery;
use Mockery\MockInterface;
use Pixonaut\WooCommerce\Cart\FieldValue;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;
use RuntimeException;
use WC_Order_Item_Product;

final class LineStateTest extends TestCase {

	private const ITEM_ID = 42;

	/**
	 * Id of an order item not yet in the database.
	 */
	private const UNSAVED_ITEM_ID = 0;

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

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_slash' )->returnArg();
		Functions\when( 'get_metadata' )->alias(
			function ( string $type, int $id, string $key, bool $single = false ): string|array {
				if ( $single ) {
					return $this->meta[ $key ] ?? '';
				}
				return $this->meta_rows[ $key ] ?? array();
			}
		);
		// A unique add is refused when the key is stored already; any other adds a row.
		Functions\when( 'add_metadata' )->alias(
			function ( string $type, int $id, string $key, string $value, bool $unique = false ): int|false {
				if ( ! $unique ) {
					$this->meta_rows[ $key ][] = $value;
					return self::ITEM_ID;
				}
				if ( isset( $this->meta[ $key ] ) ) {
					return false;
				}
				$this->meta[ $key ] = $value;
				return self::ITEM_ID;
			}
		);
		// Compare and swap when given the previous value: refused when it is not the stored one.
		Functions\when( 'update_metadata' )->alias(
			function ( string $type, int $id, string $key, string $value, string $previous = '' ): bool {
				if ( '' !== $previous && ( $this->meta[ $key ] ?? null ) !== $previous ) {
					return false;
				}
				$this->meta[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'delete_metadata' )->alias(
			function ( string $type, int $id, string $key ): bool {
				unset( $this->meta[ $key ], $this->meta_rows[ $key ] );
				return true;
			}
		);
	}

	/**
	 * @return iterable<string, array{string, string, bool}>
	 */
	public static function moves(): iterable {
		yield 'forward one step' => array( LineState::COMMITTED, LineState::AWAITING_CUSTOMER, true );
		yield 'forward skipping a step' => array( LineState::COMMITTED, LineState::APPROVED, true );
		yield 'ordered to shipped' => array( LineState::ORDERED, LineState::SHIPPED, true );
		yield 'backwards' => array( LineState::PRINTED, LineState::APPROVED, false );
		yield 'ordered back to committed' => array( LineState::ORDERED, LineState::COMMITTED, false );
		yield 'same state' => array( LineState::APPROVED, LineState::APPROVED, false );
		yield 'anything to needs attention' => array( LineState::PRINTED, LineState::NEEDS_ATTENTION, true );
		yield 'anything to failed' => array( LineState::ORDERED, LineState::FAILED, true );
		yield 'anything to cancelled' => array( LineState::COMMITTED, LineState::CANCELLED, true );
		yield 'shipped is final' => array( LineState::SHIPPED, LineState::NEEDS_ATTENTION, false );
		yield 'cancelled is final' => array( LineState::CANCELLED, LineState::ORDERED, false );
		yield 'failed only to cancelled' => array( LineState::FAILED, LineState::ORDERED, false );
		yield 'failed to cancelled' => array( LineState::FAILED, LineState::CANCELLED, true );
		yield 'unknown target' => array( LineState::COMMITTED, 'teleported', false );
	}

	/**
	 * @dataProvider moves
	 *
	 * @param string $from    Current state.
	 * @param string $to      Requested state.
	 * @param bool   $allowed Whether the guard allows it.
	 */
	public function test_guard( string $from, string $to, bool $allowed ): void {
		$this->assertSame( $allowed, LineState::can_move( $from, $to ) );
	}

	public function test_erasing_a_side_drops_the_values_the_customer_chose(): void {
		Functions\when( 'wp_check_invalid_utf8' )->returnArg();
		$state = LineState::start( $this->item(), array( 'front', 'back' ) );
		$kept  = array(
			array(
				'label' => 'Name',
				'type'  => FieldValue::TEXT,
				'value' => 'Anna',
			),
		);
		$state->set_side_ref( 'front', LineState::SIDE_FIELDS, $kept );
		$state->set_side_ref( 'back', LineState::SIDE_FIELDS, $kept );

		$state->set_side_ref( 'front', LineState::SIDE_ERASED_AT, 1 );

		$this->assertSame( array(), $state->side_fields( 'front' ) );
		$this->assertEquals( FieldValue::list_from( $kept ), $state->side_fields( 'back' ) );
	}

	public function test_needs_attention_resumes_at_the_state_before_or_later(): void {
		$this->assertTrue( LineState::can_move( LineState::NEEDS_ATTENTION, LineState::APPROVED, LineState::APPROVED ) );
		$this->assertTrue( LineState::can_move( LineState::NEEDS_ATTENTION, LineState::ORDERED, LineState::APPROVED ) );
		$this->assertFalse( LineState::can_move( LineState::NEEDS_ATTENTION, LineState::COMMITTED, LineState::APPROVED ) );
	}

	public function test_transition_saves_then_fires_the_actions(): void {
		$item  = $this->item();
		$state = LineState::start( $item, array( 'front' ) );
		$state->save();

		Actions\expectDone( 'pixonaut_wc_fulfilment_transition' )
			->once()
			->with( LineState::APPROVED, LineState::COMMITTED, $item, $state )
			->whenHappen(
				function (): void {
					$this->assertSame( LineState::APPROVED, LineState::of( $this->item() )->state(), 'saved before the action fires' );
				}
			);
		Actions\expectDone( 'pixonaut_wc_fulfilment_approved' )->once()->with( $item, LineState::COMMITTED, $state );

		$this->assertTrue( $state->transition( LineState::APPROVED ) );
		$this->assertSame( LineState::APPROVED, $state->state() );
	}

	public function test_backwards_transition_is_refused_without_saving_or_firing(): void {
		$state = LineState::start( $this->item(), array( 'front' ) );
		$state->transition( LineState::PRINTED );
		$saved = $this->meta[ LineState::META_KEY ];

		Actions\expectDone( 'pixonaut_wc_fulfilment_transition' )->never();

		$this->assertFalse( $state->transition( LineState::APPROVED ) );
		$this->assertSame( LineState::PRINTED, $state->state() );
		$this->assertSame( $saved, $this->meta[ LineState::META_KEY ] );
	}

	public function test_unknown_state_moves_the_line_to_needs_attention(): void {
		$state = LineState::start( $this->item(), array( 'front' ) );
		$state->transition( LineState::ORDERED );

		$this->assertTrue( $state->transition( 'teleported' ) );

		$this->assertSame( LineState::NEEDS_ATTENTION, $state->state() );
		$this->assertSame( LineState::ORDERED, $state->resume_from() );
		$this->assertStringContainsString( 'teleported', (string) $state->reason() );
	}

	public function test_unknown_stored_state_reads_as_needs_attention(): void {
		$this->meta[ LineState::META_KEY ] = (string) wp_json_encode(
			array(
				'v'     => 1,
				'state' => 'from_the_future',
			)
		);

		$this->assertSame( LineState::NEEDS_ATTENTION, LineState::of( $this->item() )->state() );
	}

	/**
	 * @return iterable<string, array{string|null, bool}>
	 */
	public static function version_1_refs(): iterable {
		yield 'an order item ref' => array( 'wc:site:order:7:item:42', true );
		yield 'a cart ref' => array( 'wc:site:cart:4b2e', false );
		yield 'no ref' => array( null, false );
	}

	/**
	 * @dataProvider version_1_refs
	 *
	 * @param string|null $external_ref The stored `externalRef`.
	 * @param bool        $moved        Whether the line reads as moved.
	 */
	public function test_a_version_1_line_reads_as_moved_only_under_an_order_item_ref( ?string $external_ref, bool $moved ): void {
		$this->meta[ LineState::META_KEY ] = (string) wp_json_encode(
			array(
				'v'     => 1,
				'state' => LineState::COMMITTED,
				'refs'  => array_filter( array( LineState::REF_EXTERNAL_REF => $external_ref ) ),
				'sides' => array( array( 'key' => 'front' ) ),
			)
		);

		$line = LineState::of( $this->item() );

		$this->assertSame( $moved ? true : null, $line->ref( LineState::REF_EXTERNAL_REF_MOVED ) );
		$this->assertSame( $external_ref, $line->ref( LineState::REF_EXTERNAL_REF ) );
	}

	public function test_refs_round_trip_per_line_and_side(): void {
		$state = LineState::start( $this->item(), array( 'front', 'back' ) );
		$state->set_ref( LineState::REF_ORDER_KEY, 'wc:site:item:42' );
		$state->set_side_ref( 'back', LineState::SIDE_DESIGN_ID, 'des_2' );
		$state->set_side_ref( 'back', LineState::SIDE_REVISION, 3 );
		$state->save();

		$read = LineState::of( $this->item() );

		$this->assertSame( array( 'front', 'back' ), $read->sides() );
		$this->assertSame( 'wc:site:item:42', $read->ref( LineState::REF_ORDER_KEY ) );
		$this->assertSame( 'des_2', $read->side_ref( 'back', LineState::SIDE_DESIGN_ID ) );
		$this->assertSame( 3, $read->side_ref( 'back', LineState::SIDE_REVISION ) );
		$this->assertNull( $read->side_ref( 'front', LineState::SIDE_DESIGN_ID ) );
	}

	public function test_unknown_ref_name_is_refused(): void {
		$state = LineState::start( $this->item(), array( 'front' ) );

		$this->expectException( LogicException::class );
		$state->set_ref( 'orderID', 'typo' );
	}

	public function test_a_stale_instance_cannot_move_the_line_backwards(): void {
		$this->store_at( LineState::APPROVED );
		$first  = LineState::of( $this->item() );
		$second = LineState::of( $this->item() );

		$first->set_ref( LineState::REF_TRACKING, 'TRACK1' );
		$this->assertTrue( $first->transition( LineState::SHIPPED ) );

		Actions\expectDone( 'pixonaut_wc_fulfilment_transition' )->never();
		Actions\expectDone( 'pixonaut_wc_fulfilment_printed' )->never();

		$this->assertFalse( $second->transition( LineState::PRINTED ) );
		$this->assertSame( LineState::APPROVED, $second->state(), 'the refused move is undone in memory' );

		$stored = LineState::of( $this->item() );
		$this->assertSame( LineState::SHIPPED, $stored->state() );
		$this->assertSame( 'TRACK1', $stored->ref( LineState::REF_TRACKING ) );
	}

	public function test_a_stale_ref_save_does_not_overwrite(): void {
		$this->store_at( LineState::APPROVED );
		$first  = LineState::of( $this->item() );
		$second = LineState::of( $this->item() );

		$first->set_ref( LineState::REF_TRACKING, 'TRACK1' );
		$this->assertTrue( $first->save() );
		$second->set_ref( LineState::REF_ORDER_ID, 'ord_1' );

		$this->assertFalse( $second->save() );
		$this->assertSame( 'TRACK1', LineState::of( $this->item() )->ref( LineState::REF_TRACKING ) );
		$this->assertNull( LineState::of( $this->item() )->ref( LineState::REF_ORDER_ID ) );
	}

	public function test_starting_a_line_twice_is_refused_by_the_unique_write(): void {
		$first  = LineState::start( $this->item(), array( 'front' ) );
		$second = LineState::start( $this->item(), array( 'back' ) );

		$this->assertTrue( $first->save() );
		$this->assertFalse( $second->save() );
		$this->assertSame( array( 'front' ), LineState::of( $this->item() )->sides() );
	}

	public function test_document_from_a_newer_version_is_read_only(): void {
		$newer                             = (string) wp_json_encode(
			array(
				'v'     => LineState::SCHEMA_VERSION + 1,
				'state' => LineState::COMMITTED,
			)
		);
		$this->meta[ LineState::META_KEY ] = $newer;
		$state                             = LineState::of( $this->item() );

		Actions\expectDone( 'pixonaut_wc_fulfilment_transition' )->never();

		$this->assertSame( LineState::NEEDS_ATTENTION, $state->state() );
		$this->assertFalse( $state->transition( LineState::APPROVED ) );
		$this->assertSame( $newer, $this->meta[ LineState::META_KEY ] );

		$this->expectException( LogicException::class );
		$state->save();
	}

	public function test_failed_encoding_keeps_the_stored_state_and_fires_nothing(): void {
		$this->store_at( LineState::APPROVED );
		$saved = $this->meta[ LineState::META_KEY ];
		$state = LineState::of( $this->item() );
		Functions\when( 'wp_json_encode' )->justReturn( false );

		Actions\expectDone( 'pixonaut_wc_fulfilment_transition' )->never();

		try {
			$state->transition( LineState::PRINTED );
			$this->fail( 'Expected the transition to throw.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( LineState::APPROVED, $state->state() );
			$this->assertSame( $saved, $this->meta[ LineState::META_KEY ] );
		}
	}

	public function test_an_unsaved_item_keeps_the_state_in_its_meta(): void {
		$item = $this->item( self::UNSAVED_ITEM_ID );
		$item->expects( 'update_meta_data' )->with( LineState::META_KEY, Mockery::type( 'string' ) );
		$item->expects( 'delete_meta_data' )->with( LineState::DESIGN_ID_META_KEY );

		$this->assertTrue( LineState::start( $item, array( 'front' ) )->save() );
		$this->assertSame( array(), $this->meta, 'written with the order, not at once' );
	}

	public function test_each_design_id_is_copied_to_its_own_meta_and_follows_the_sides(): void {
		$state = LineState::start( $this->item(), array( 'front', 'back' ) );
		$state->save();
		$this->assertArrayNotHasKey( LineState::DESIGN_ID_META_KEY, $this->meta_rows );

		$state->set_side_ref( 'front', LineState::SIDE_DESIGN_ID, 'des_front' );
		$state->set_side_ref( 'back', LineState::SIDE_DESIGN_ID, 'des_back' );
		$state->save();
		$this->assertEqualsCanonicalizing( array( 'des_front', 'des_back' ), $this->meta_rows[ LineState::DESIGN_ID_META_KEY ] );

		$state->set_side_ref( 'back', LineState::SIDE_DESIGN_ID, null );
		$state->save();
		$this->assertSame( array( 'des_front' ), $this->meta_rows[ LineState::DESIGN_ID_META_KEY ] );
	}

	public function test_the_side_holding_a_design_is_found_by_its_id(): void {
		$state = LineState::start( $this->item(), array( 'front', 'back' ) );
		$state->set_side_ref( 'front', LineState::SIDE_DESIGN_ID, 'des_front' );
		$state->set_side_ref( 'back', LineState::SIDE_DESIGN_ID, 'des_back' );

		$this->assertSame( 'back', $state->side_of_design( 'des_back' ) );
		$this->assertNull( $state->side_of_design( 'des_other' ) );
	}

	public function test_the_order_id_is_copied_to_its_own_meta_and_follows_the_ref(): void {
		$state = LineState::start( $this->item(), array( 'front' ) );
		$state->save();
		$this->assertArrayNotHasKey( LineState::ORDER_ID_META_KEY, $this->meta );

		$state->set_ref( LineState::REF_ORDER_ID, 'ord_1' );
		$state->save();
		$this->assertSame( 'ord_1', $this->meta[ LineState::ORDER_ID_META_KEY ] );

		$state->set_ref( LineState::REF_ORDER_ID, 'ord_2' );
		$state->save();
		$this->assertSame( 'ord_2', $this->meta[ LineState::ORDER_ID_META_KEY ] );

		$state->set_ref( LineState::REF_ORDER_ID, null );
		$state->save();
		$this->assertArrayNotHasKey( LineState::ORDER_ID_META_KEY, $this->meta );
	}

	public function test_a_replacement_order_keeps_every_id_the_line_held_before(): void {
		$state = LineState::start( $this->item(), array( 'front' ) );
		$state->set_ref( LineState::REF_ORDER_ID, 'ord_1' );
		$state->set_ref( LineState::REF_ORDER_STATUS, 'failed' );

		$state->replace_order( 'ord_2' );
		$state->replace_order( 'ord_2' );
		$state->replace_order( 'ord_3' );
		$state->save();

		$line = LineState::of( $this->item() );
		$this->assertSame( 'ord_3', $line->ref( LineState::REF_ORDER_ID ) );
		$this->assertSame( array( 'ord_1', 'ord_2' ), $line->replaced_order_ids() );
		$this->assertNull( $line->ref( LineState::REF_ORDER_STATUS ), 'no status of the new order is applied yet' );
		$this->assertSame( 'ord_3', $this->meta[ LineState::ORDER_ID_META_KEY ] );
	}

	public function test_a_replacement_order_drops_the_hold_and_release_of_the_order_it_replaced(): void {
		$state = LineState::start( $this->item(), array( 'front' ) );
		$state->set_ref( LineState::REF_ORDER_ID, 'ord_1' );
		$state->set_ref( LineState::REF_HOLD, array( 'reason' => 'monthly_order_limit' ) );
		$state->set_ref( LineState::REF_RELEASED_BY, 'upgrade' );

		$state->replace_order( 'ord_2' );

		$this->assertNull( $state->ref( LineState::REF_HOLD ) );
		$this->assertNull( $state->ref( LineState::REF_RELEASED_BY ), 'the new order can be held and released in its turn' );
	}

	public function test_a_line_without_an_order_takes_one_on_with_no_history(): void {
		$state = LineState::start( $this->item(), array( 'front' ) );

		$state->replace_order( 'ord_1' );

		$this->assertSame( 'ord_1', $state->ref( LineState::REF_ORDER_ID ) );
		$this->assertSame( array(), $state->replaced_order_ids() );
	}

	public function test_a_refused_write_leaves_the_order_id_meta_alone(): void {
		$this->store_at( LineState::ORDERED );
		$stale = LineState::of( $this->item() );
		$fresh = LineState::of( $this->item() );
		$fresh->transition( LineState::SHIPPED );

		$stale->set_ref( LineState::REF_ORDER_ID, 'ord_1' );

		$this->assertFalse( $stale->save() );
		$this->assertArrayNotHasKey( LineState::ORDER_ID_META_KEY, $this->meta );
	}

	public function test_a_failed_index_write_throws(): void {
		$state = LineState::start( $this->item(), array( 'front' ) );
		$state->save();
		$this->refuse_writes_to( LineState::ORDER_ID_META_KEY );
		$state->set_ref( LineState::REF_ORDER_ID, 'ord_1' );

		$this->expectException( RuntimeException::class );
		$state->save();
	}

	public function test_a_later_save_repairs_a_missing_index(): void {
		$state = LineState::start( $this->item(), array( 'front' ) );
		$state->set_ref( LineState::REF_ORDER_ID, 'ord_1' );
		$state->save();
		unset( $this->meta[ LineState::ORDER_ID_META_KEY ] );

		$this->assertTrue( LineState::of( $this->item() )->save() );

		$this->assertSame( 'ord_1', $this->meta[ LineState::ORDER_ID_META_KEY ] );
	}

	public function test_a_conflicting_write_drops_the_cached_meta(): void {
		$this->store_at( LineState::APPROVED );
		$stale = LineState::of( $this->item() );
		LineState::of( $this->item() )->transition( LineState::ORDERED );
		$dropped = array();
		Functions\when( 'wp_cache_delete' )->alias(
			static function ( string $id, string $group ) use ( &$dropped ): bool {
				$dropped[] = array( $id, $group );
				return true;
			}
		);

		$this->assertFalse( $stale->transition( LineState::PRINTED ) );
		$this->assertSame( array( array( (string) self::ITEM_ID, 'order_item_meta' ) ), $dropped );
	}

	public function test_untracked_item(): void {
		$this->assertFalse( LineState::is_tracked( $this->item() ) );

		$this->expectException( LogicException::class );
		LineState::of( $this->item() );
	}

	/**
	 * Makes the metadata API refuse to write one key.
	 *
	 * @param string $refused Meta key.
	 */
	private function refuse_writes_to( string $refused ): void {
		Functions\when( 'update_metadata' )->alias(
			function ( string $type, int $id, string $key, string $value, string $previous = '' ) use ( $refused ): bool {
				if ( $key === $refused || ( '' !== $previous && ( $this->meta[ $key ] ?? null ) !== $previous ) ) {
					return false;
				}
				$this->meta[ $key ] = $value;
				return true;
			}
		);
	}

	private function store_at( string $state ): void {
		$this->meta[ LineState::META_KEY ] = (string) wp_json_encode(
			array(
				'v'     => LineState::SCHEMA_VERSION,
				'state' => $state,
				'sides' => array( array( 'key' => 'front' ) ),
			)
		);
	}

	/**
	 * @param int $id The item id; UNSAVED_ITEM_ID for one not in the database.
	 * @return WC_Order_Item_Product&MockInterface
	 */
	private function item( int $id = self::ITEM_ID ): WC_Order_Item_Product {
		/** @var WC_Order_Item_Product&MockInterface $item */
		$item = Mockery::mock( WC_Order_Item_Product::class );
		$item->allows( 'get_id' )->andReturn( $id );
		$item->allows( 'get_meta' )->andReturn( '' );
		return $item;
	}
}
