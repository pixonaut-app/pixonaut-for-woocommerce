<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Abilities;

use Pixonaut\WooCommerce\Abilities\Abilities;
use Pixonaut\WooCommerce\Abilities\MoveProductAttribute;
use Pixonaut\WooCommerce\Api\Hosts;
use Pixonaut\WooCommerce\Catalogue\AttributeMove;
use Pixonaut\WooCommerce\Catalogue\AttributeMoveRefused;
use Pixonaut\WooCommerce\Catalogue\FormGroups;
use Pixonaut\WooCommerce\Catalogue\MovedAttributes;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ProductRecheck;
use Pixonaut\WooCommerce\Catalogue\ProductVariant;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Catalogue\Transaction;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Storefront\MovedAttributeLinks;
use Pixonaut\WooCommerce\Tests\Support\HttpRecorder;
use Pixonaut\WooCommerce\Tests\Support\RecordingLogger;
use RuntimeException;
use WC_Order_Item_Product;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_Ability;
use WP_Error;
use WP_UnitTestCase;

/**
 * Moving products from a custom attribute to a global one, as the Abilities
 * API (and so the MCP Adapter) runs it, and what names the old attribute
 * afterwards.
 */
final class MoveProductAttributeTest extends WP_UnitTestCase {

	public const MOVE = Abilities::NAMESPACE . '/move-product-attribute';

	private const CUSTOM = 'Storlek';

	private const CUSTOM_SLUG = 'storlek';

	private const TAXONOMY = 'pa_storlek';

	private const PRICE_SMALL = '100';

	private const PRICE_LARGE = '200';

	private const IMAGE_ID = 4242;

	private HttpRecorder $recorder;

	public function set_up(): void {
		parent::set_up();
		Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' );
		$this->recorder = ( new HttpRecorder( Hosts::server_api() ) )->start();
		wc_create_attribute(
			array(
				'name' => self::CUSTOM,
				'slug' => self::CUSTOM_SLUG,
			)
		);
		register_taxonomy( self::TAXONOMY, array( 'product', 'product_variation' ), array( 'update_count_callback' => '_update_post_term_count' ) );
		// What WooCommerce holds for an attribute from `init` on, which its product routes read unguarded before 11.2.
		$GLOBALS['wc_product_attributes'][ self::TAXONOMY ] = current( wp_list_filter( wc_get_attribute_taxonomies(), array( 'attribute_name' => self::CUSTOM_SLUG ) ) );
		wp_insert_term( 'A4', self::TAXONOMY, array( 'slug' => 'a4-210' ) );
		wp_insert_term( 'A3', self::TAXONOMY, array( 'slug' => 'a3-297' ) );
		wp_set_current_user( $this->user( 'shop_manager' ) );
	}

	public function tear_down(): void {
		$this->recorder->stop();
		unregister_taxonomy( self::TAXONOMY );
		unset( $GLOBALS['wc_product_attributes'][ self::TAXONOMY ] );
		delete_transient( 'wc_attribute_taxonomies' );
		parent::tear_down();
	}

	public function test_a_variable_product_moves_as_a_whole_keeping_each_variations_id_price_image_and_setup(): void {
		$product = $this->product();
		$small   = $this->variation( $product, 'A4', self::PRICE_SMALL );
		$large   = $this->variation( $product, 'A3', self::PRICE_LARGE );
		$large->set_image_id( self::IMAGE_ID );
		$large->save();
		$own = new ProductConfig( personalise: true, override: true, sides: array( new Side( 'front', 'Front', null, null, 'mini-poster', 1, null, 'Mini poster' ) ) );
		$own->save_to( $small );
		$product->set_default_attributes( array( self::CUSTOM_SLUG => 'A3' ) );
		$product->save();
		$this->arranged()->save_to( $product );
		$small_setup = (string) get_post_meta( $small->get_id(), ProductConfig::META_KEY, true );

		$answer = $this->move( array( 'product_ids' => array( $product->get_id() ) ) );

		$this->assertSame( MoveProductAttribute::MOVED, $answer['products'][0]['status'] );
		$this->assertSame( 2, $answer['products'][0]['variations'] );
		$moved      = $this->reload( $product->get_id() );
		$attributes = $moved->get_attributes();
		$this->assertArrayNotHasKey( self::CUSTOM_SLUG, $attributes );
		$attribute = $attributes[ self::TAXONOMY ];
		$this->assertInstanceOf( WC_Product_Attribute::class, $attribute );
		$this->assertTrue( $attribute->is_taxonomy() );
		$this->assertTrue( $attribute->get_variation() );
		$this->assertFalse( $attribute->get_visible() );
		$this->assertSame( 3, $attribute->get_position() );
		$this->assertSame( array( self::TAXONOMY => 'a3-297' ), $moved->get_default_attributes() );
		$this->assertEqualsCanonicalizing( array( 'a4-210', 'a3-297' ), wp_get_object_terms( $product->get_id(), self::TAXONOMY, array( 'fields' => 'slugs' ) ) );

		$small_after = $this->reload( $small->get_id() );
		$large_after = $this->reload( $large->get_id() );
		$this->assertSame( array( self::TAXONOMY => 'a4-210' ), $small_after->get_attributes() );
		$this->assertSame( array( self::TAXONOMY => 'a3-297' ), $large_after->get_attributes() );
		$this->assertSame( self::PRICE_SMALL, $small_after->get_regular_price() );
		$this->assertSame( self::PRICE_LARGE, $large_after->get_regular_price() );
		$this->assertSame( self::IMAGE_ID, $large_after->get_image_id() );
		$this->assertSame( $small_setup, get_post_meta( $small->get_id(), ProductConfig::META_KEY, true ) );
		$this->assertSame( '', get_post_meta( $small->get_id(), 'attribute_storlek', true ) );
		$this->assertTrue( $small_after->is_purchasable() );

		$config = ProductConfig::stored_on( $moved );
		$this->assertNotNull( $config->sides[0]->form_groups );
		$this->assertSame( array( self::TAXONOMY ), $config->sides[0]->form_groups->attributes() );
		$this->assertTrue( $config->valid );

		$offered = $this->reload( $product->get_id() );
		$this->assertInstanceOf( WC_Product_Variable::class, $offered );
		$this->assertSame( array( 'a4-210', 'a3-297' ), array_values( $offered->get_variation_attributes()[ self::TAXONOMY ] ) );

		$term = get_term_by( 'slug', 'a4-210', self::TAXONOMY );
		$this->assertInstanceOf( \WP_Term::class, $term );
		$this->assertSame( 1, $term->count );
		$this->assertSame( array(), $this->recorder->requests, 'Pixonaut is not asked.' );
	}

	public function test_a_dry_run_says_what_would_happen_and_records_the_state_it_found(): void {
		$product = $this->product();
		$small   = $this->variation( $product, 'A4', self::PRICE_SMALL );
		$this->arranged()->save_to( $product );
		MovedAttributes::record( $product->get_id(), 'format', 'pa_format', array( 'A5' => 'a5' ) );
		$document = (string) get_post_meta( $product->get_id(), ProductConfig::META_KEY, true );
		$before   = get_post_meta( $product->get_id(), '_product_attributes', true );

		$answer = $this->move(
			array(
				'product_ids' => array( $product->get_id() ),
				'dry_run'     => true,
			)
		);

		$row = $answer['products'][0];
		$this->assertTrue( $answer['dry_run'] );
		$this->assertSame( MoveProductAttribute::WOULD_MOVE, $row['status'] );
		$this->assertSame( self::CUSTOM, $row['before']['attributes'][0]['name'] );
		$this->assertSame( array( 'A4', 'A3' ), $row['before']['attributes'][0]['options'] );
		$this->assertSame( $small->get_id(), $row['before']['variations'][0]['id'] );
		$this->assertSame( 'A4', ( (array) $row['before']['variations'][0]['attributes'] )[ self::CUSTOM_SLUG ] );
		$this->assertSame( $before, get_post_meta( $product->get_id(), '_product_attributes', true ) );
		$this->assertSame( 'A4', get_post_meta( $small->get_id(), 'attribute_storlek', true ) );
		$this->assertSame( array( $product->get_id() => $document ), (array) $row['before']['setup_documents'], 'Only a setup whose arrangement the move renames.' );
		$this->assertSame( array( 'format' ), array_keys( (array) $row['before']['moved_attributes'] ) );
		$this->assertArrayNotHasKey( self::CUSTOM_SLUG, MovedAttributes::of( $product->get_id() ) );
	}

	public function test_a_failure_part_way_leaves_the_product_and_its_variations_as_they_were(): void {
		$product = $this->product();
		$small   = $this->variation( $product, 'A4', self::PRICE_SMALL );
		$large   = $this->variation( $product, 'A3', self::PRICE_LARGE );
		$before  = get_post_meta( $product->get_id(), '_product_attributes', true );
		$break   = static function ( int $id ) use ( $large ): void {
			if ( $id === $large->get_id() ) {
				throw new RuntimeException( 'The save failed.' );
			}
		};
		add_action( 'woocommerce_update_product_variation', $break );

		$answer = $this->move( array( 'product_ids' => array( $product->get_id() ) ) );
		remove_action( 'woocommerce_update_product_variation', $break );

		$this->assertSame( MoveProductAttribute::FAILED, $answer['products'][0]['status'] );
		$this->assertSame( 'save_failed', $answer['products'][0]['reason'] );
		$this->assertStringNotContainsString( 'The save failed.', $answer['products'][0]['message'] );
		$this->assertSame( $before, get_post_meta( $product->get_id(), '_product_attributes', true ) );
		$this->assertSame( 'A4', get_post_meta( $small->get_id(), 'attribute_storlek', true ) );
		$this->assertSame( '', get_post_meta( $small->get_id(), 'attribute_pa_storlek', true ) );
		$this->assertSame( array( self::CUSTOM_SLUG => 'A4' ), $this->reload( $small->get_id() )->get_attributes() );
		$this->assertSame( array(), wp_get_object_terms( $product->get_id(), self::TAXONOMY, array( 'fields' => 'slugs' ) ) );
		$this->assertSame( array(), MovedAttributes::of( $product->get_id() ) );
	}

	public function test_an_unexpected_failure_is_logged_and_the_answer_does_not_repeat_it(): void {
		$product = $this->product();
		$this->variation( $product, 'A4', self::PRICE_SMALL );
		$break = static function (): void {
			throw new RuntimeException( 'The save failed.' );
		};
		add_action( 'woocommerce_update_product_variation', $break );
		$logger = new RecordingLogger();

		$answer = ( new MoveProductAttribute( $logger ) )->execute(
			array(
				'from'        => self::CUSTOM,
				'to'          => self::TAXONOMY,
				'product_ids' => array( $product->get_id() ),
			)
		);
		remove_action( 'woocommerce_update_product_variation', $break );

		$this->assertIsArray( $answer );
		$this->assertStringNotContainsString( 'The save failed.', $answer['products'][0]['message'] );
		$this->assertCount( 1, $logger->entries );
		$this->assertSame( 'error', $logger->entries[0]['level'] );
		foreach ( array( (string) $product->get_id(), self::CUSTOM, self::TAXONOMY, RuntimeException::class, 'The save failed.' ) as $told ) {
			$this->assertStringContainsString( $told, $logger->entries[0]['message'] );
		}
	}

	public function test_a_write_failing_without_throwing_undoes_the_move(): void {
		$product = $this->product();
		$small   = $this->variation( $product, 'A4', self::PRICE_SMALL );
		$before  = get_post_meta( $product->get_id(), '_product_attributes', true );
		$refuse  = static fn ( mixed $check, int $id, string $key ): mixed => MovedAttributes::META_KEY === $key ? false : $check;
		add_filter( 'update_post_metadata', $refuse, 10, 3 );

		$answer = $this->move( array( 'product_ids' => array( $product->get_id() ) ) );
		remove_filter( 'update_post_metadata', $refuse );

		$this->assertSame( MoveProductAttribute::FAILED, $answer['products'][0]['status'] );
		$this->assertSame( AttributeMoveRefused::NOT_SAVED, $answer['products'][0]['reason'] );
		$this->assertSame( $before, get_post_meta( $product->get_id(), '_product_attributes', true ) );
		$this->assertSame( array( self::CUSTOM_SLUG => 'A4' ), $this->reload( $small->get_id() )->get_attributes() );
		$this->assertSame( array(), wp_get_object_terms( $product->get_id(), self::TAXONOMY, array( 'fields' => 'slugs' ) ) );
	}

	public function test_reads_during_the_move_leave_the_shared_cache_empty(): void {
		$product = $this->product();
		$this->variation( $product, 'A4', self::PRICE_SMALL );
		$cached    = array();
		$suspended = array();
		$look      = static function () use ( $product, &$cached, &$suspended ): void {
			$cached[]    = wp_cache_get( (string) $product->get_id(), 'post_meta' );
			$suspended[] = wp_suspend_cache_addition();
		};
		add_action( 'woocommerce_update_product_variation', $look );

		$answer = $this->move( array( 'product_ids' => array( $product->get_id() ) ) );
		remove_action( 'woocommerce_update_product_variation', $look );

		$this->assertSame( MoveProductAttribute::MOVED, $answer['products'][0]['status'] );
		$this->assertSame( array( false ), $cached );
		$this->assertSame( array( true ), $suspended );
		$this->assertFalse( wp_suspend_cache_addition() );
	}

	public function test_a_plan_overtaken_by_another_move_is_refused_and_writes_nothing(): void {
		$product = $this->product();
		$this->variation( $product, 'A4', self::PRICE_SMALL );
		$first  = $this->plan( $product->get_id() );
		$second = $this->plan( $product->get_id() );
		$second->apply();
		$saves = 0;
		$count = static function () use ( &$saves ): void {
			++$saves;
		};
		add_action( 'woocommerce_update_product', $count );

		$reason = '';
		try {
			$first->apply();
		} catch ( AttributeMoveRefused $refused ) {
			$reason = $refused->reason;
		}
		remove_action( 'woocommerce_update_product', $count );

		$this->assertSame( AttributeMoveRefused::NOT_SAVED, $reason );
		$this->assertSame( 0, $saves );
	}

	public function test_a_plan_is_made_again_on_what_changed_since(): void {
		$product = $this->product();
		$this->variation( $product, 'A4', self::PRICE_SMALL );
		$move  = $this->plan( $product->get_id() );
		$added = $this->variation( $product, 'A3', self::PRICE_LARGE );

		$move->apply();

		$this->assertSame( array( self::TAXONOMY => 'a3-297' ), $this->reload( $added->get_id() )->get_attributes() );
		$this->assertContains( $added->get_id(), $move->variation_ids() );

		$other = $this->product();
		$move  = $this->plan( $other->get_id() );
		$term  = get_term_by( 'slug', 'a3-297', self::TAXONOMY );
		$this->assertInstanceOf( \WP_Term::class, $term );
		wp_delete_term( $term->term_id, self::TAXONOMY );

		$reason = '';
		try {
			$move->apply();
		} catch ( AttributeMoveRefused $refused ) {
			$reason = $refused->reason;
		}
		$this->assertSame( AttributeMoveRefused::MISSING_TERMS, $reason, 'A refusal keeps its reason.' );
		$this->assertArrayHasKey( self::CUSTOM_SLUG, $this->reload( $other->get_id() )->get_attributes() );
	}

	public function test_a_variation_in_the_trash_moves_and_stays_there(): void {
		$product = $this->product();
		$trashed = $this->variation( $product, 'A4', self::PRICE_SMALL );
		wp_trash_post( $trashed->get_id() );

		$answer = $this->move( array( 'product_ids' => array( $product->get_id() ) ) );

		$this->assertSame( 1, $answer['products'][0]['variations'] );
		$this->assertSame( 'trash', get_post_status( $trashed->get_id() ) );
		$this->assertSame( 'a4-210', get_post_meta( $trashed->get_id(), 'attribute_pa_storlek', true ) );
		$this->assertFalse( metadata_exists( 'post', $trashed->get_id(), 'attribute_storlek' ) );
	}

	public function test_a_product_with_too_many_variations_is_refused(): void {
		global $wpdb;
		$product = $this->product();
		for ( $count = 0; $count <= AttributeMove::MAX_VARIATIONS; $count++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- saving this many variations takes minutes, and the count comes before they are read.
			$wpdb->insert(
				$wpdb->posts,
				array(
					'post_parent'           => $product->get_id(),
					'post_type'             => 'product_variation',
					'post_status'           => 'publish',
					'post_content'          => '',
					'post_title'            => '',
					'post_excerpt'          => '',
					'to_ping'               => '',
					'pinged'                => '',
					'post_content_filtered' => '',
				)
			);
		}
		clean_post_cache( $product->get_id() );

		$answer = $this->move( array( 'product_ids' => array( $product->get_id() ) ) );

		$this->assertSame( AttributeMoveRefused::TOO_MANY_VARIATIONS, $answer['products'][0]['reason'] );
		$this->assertArrayHasKey( self::CUSTOM_SLUG, $this->reload( $product->get_id() )->get_attributes() );
	}

	public function test_a_transaction_inside_another_undoes_only_its_own_writes(): void {
		Transaction::run(
			function (): void {
				$this->write_option( 'pixonaut_test_outer' );
				try {
					Transaction::run(
						function (): void {
							$this->write_option( 'pixonaut_test_inner' );
							throw new RuntimeException( 'The inner work failed.' );
						}
					);
				} catch ( RuntimeException ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- the outer work goes on.
				}
				$this->write_option( 'pixonaut_test_after' );
			}
		);

		$this->assertTrue( $this->option_written( 'pixonaut_test_outer' ) );
		$this->assertTrue( $this->option_written( 'pixonaut_test_after' ) );
		$this->assertFalse( $this->option_written( 'pixonaut_test_inner' ) );
	}

	public function test_a_failed_commit_undoes_the_work_and_throws(): void {
		global $wpdb;
		$break  = static fn ( string $query ): string => str_starts_with( $query, 'RELEASE SAVEPOINT' ) ? 'RELEASE SAVEPOINT pixonaut_test_no_such' : $query;
		$errors = $wpdb->suppress_errors( true );
		add_filter( 'query', $break );

		$thrown = null;
		try {
			Transaction::run(
				function (): void {
					$this->write_option( 'pixonaut_test_uncommitted' );
				}
			);
		} catch ( RuntimeException $failure ) {
			$thrown = $failure;
		}
		remove_filter( 'query', $break );
		$wpdb->suppress_errors( $errors );

		$this->assertInstanceOf( RuntimeException::class, $thrown );
		$this->assertFalse( $this->option_written( 'pixonaut_test_uncommitted' ) );
	}

	public function test_a_value_without_a_term_refuses_that_product_and_the_others_go_ahead(): void {
		$refused = $this->product( array( 'A4', 'A9' ) );
		$other   = $this->product();

		$answer = $this->move( array( 'product_ids' => array( $refused->get_id(), $other->get_id() ) ) );

		$this->assertSame( MoveProductAttribute::FAILED, $answer['products'][0]['status'] );
		$this->assertSame( AttributeMoveRefused::MISSING_TERMS, $answer['products'][0]['reason'] );
		$this->assertStringContainsString( 'A9', $answer['products'][0]['message'] );
		$this->assertArrayHasKey( self::CUSTOM_SLUG, $this->reload( $refused->get_id() )->get_attributes() );
		$this->assertSame( MoveProductAttribute::MOVED, $answer['products'][1]['status'] );
	}

	public function test_a_value_named_otherwise_moves_by_the_term_map(): void {
		$product = $this->product( array( 'Liten', 'A3' ) );
		$small   = $this->variation( $product, 'Liten', self::PRICE_SMALL );

		$answer = $this->move(
			array(
				'product_ids' => array( $product->get_id() ),
				'term_map'    => array( 'Liten' => 'a4-210' ),
			)
		);

		$this->assertSame( MoveProductAttribute::MOVED, $answer['products'][0]['status'] );
		$this->assertSame( array( self::TAXONOMY => 'a4-210' ), $this->reload( $small->get_id() )->get_attributes() );
	}

	public function test_two_values_becoming_one_term_are_refused(): void {
		foreach ( array( array( 'A4', 'a4 ' ), array( 'A B', 'A-B' ) ) as $options ) {
			$product = $this->product( $options );
			$answer  = $this->move(
				array(
					'product_ids' => array( $product->get_id() ),
					'term_map'    => array_fill_keys( $options, 'a4-210' ),
				)
			);
			$this->assertSame( AttributeMoveRefused::AMBIGUOUS_VALUES, $answer['products'][0]['reason'], 'A variation\'s value could mean either.' );
			$this->assertArrayHasKey( self::CUSTOM_SLUG, $this->reload( $product->get_id() )->get_attributes() );
		}

		$other  = $this->product();
		$answer = $this->move(
			array(
				'product_ids' => array( $other->get_id() ),
				'term_map'    => array( 'A3' => 'a4-210' ),
			)
		);
		$this->assertSame( AttributeMoveRefused::DUPLICATE_TERMS, $answer['products'][0]['reason'] );
	}

	public function test_a_variation_on_any_value_stays_on_any_and_one_on_a_stale_value_refuses_the_move(): void {
		$product = $this->product();
		$any     = $this->variation( $product, '', self::PRICE_SMALL );
		$this->move( array( 'product_ids' => array( $product->get_id() ) ) );
		$this->assertSame( array( self::TAXONOMY => '' ), $this->reload( $any->get_id() )->get_attributes() );
		$this->assertFalse( metadata_exists( 'post', $any->get_id(), 'attribute_storlek' ) );

		$stale = $this->product();
		$this->variation( $stale, 'A2', self::PRICE_SMALL );
		$answer = $this->move( array( 'product_ids' => array( $stale->get_id() ) ) );
		$this->assertSame( AttributeMoveRefused::UNKNOWN_VALUE, $answer['products'][0]['reason'] );
	}

	public function test_a_variant_matched_by_options_keeps_selling_and_a_move_that_would_stop_it_is_refused(): void {
		$same      = $this->product();
		$variation = $this->variation( $same, 'A4', self::PRICE_SMALL );
		$matched   = new ProductVariant( 'blank_sign', ref: 'v_a4', matched_on: array( 'Storlek' => 'A4' ) );
		$matched->save_to( $variation );
		( new ProductConfig( personalise: true, blank_id: 'blank_sign', sides: array( new Side( 'front', 'Front', 'front', 'Front', 'mini-poster', 1, null, 'Mini poster' ) ) ) )->save_to( $same );
		$queued = count( as_get_scheduled_actions( array( 'hook' => ProductRecheck::ACTION ), 'ids' ) );
		$this->move( array( 'product_ids' => array( $same->get_id() ) ) );
		$stored = ProductVariant::stored_on( $this->reload( $variation->get_id() ) );
		$this->assertNotNull( $stored );
		$this->assertTrue( $stored->is_current( $this->reload( $variation->get_id() ) ) );
		$this->assertCount( $queued, as_get_scheduled_actions( array( 'hook' => ProductRecheck::ACTION ), 'ids' ), 'Nothing is checked again with Pixonaut.' );

		$other         = $this->product( array( 'Liten', 'A3' ) );
		$other_variant = $this->variation( $other, 'Liten', self::PRICE_SMALL );
		( new ProductVariant( 'blank_sign', ref: 'v_a4', matched_on: array( 'Storlek' => 'Liten' ) ) )->save_to( $other_variant );
		$answer = $this->move(
			array(
				'product_ids' => array( $other->get_id() ),
				'term_map'    => array( 'Liten' => 'a4-210' ),
			)
		);
		$this->assertSame( AttributeMoveRefused::BLANK_VARIANT, $answer['products'][0]['reason'] );
		$this->assertArrayHasKey( self::CUSTOM_SLUG, $this->reload( $other->get_id() )->get_attributes() );
	}

	public function test_it_walks_the_store_a_page_at_a_time_leaves_other_products_alone_and_can_run_again(): void {
		$first  = $this->product();
		$format = $this->product( array( 'A5', 'A6' ), 'Format' );
		$second = $this->product();

		$page_one = $this->move( array( 'per_page' => 2 ) );
		$this->assertTrue( $page_one['more'] );
		$this->assertSame( array( $first->get_id() ), array_column( $page_one['products'], 'product_id' ) );
		$page_two = $this->move(
			array(
				'per_page' => 2,
				'page'     => 2,
			)
		);
		$this->assertFalse( $page_two['more'] );
		$this->assertSame( array( $second->get_id() ), array_column( $page_two['products'], 'product_id' ) );
		$this->assertArrayHasKey( 'format', $this->reload( $format->get_id() )->get_attributes() );

		$again = $this->move( array( 'product_ids' => array( $first->get_id(), $format->get_id() ) ) );
		$this->assertSame( array( MoveProductAttribute::ALREADY_MOVED, MoveProductAttribute::NOT_ON_PRODUCT ), array_column( $again['products'], 'status' ) );
	}

	public function test_an_unknown_global_attribute_is_refused_and_so_is_a_user_who_may_not_edit_products(): void {
		$ability = $this->ability();
		$unknown = $ability->execute(
			array(
				'from' => self::CUSTOM,
				'to'   => 'pa_nothing',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $unknown );
		$this->assertSame( 'pixonaut_wc_ability_unknown_attribute', $unknown->get_error_code() );

		wp_set_current_user( $this->user( 'subscriber' ) );
		$refused = $ability->execute(
			array(
				'from' => self::CUSTOM,
				'to'   => self::TAXONOMY,
			)
		);
		$this->assertInstanceOf( WP_Error::class, $refused );
	}

	public function test_a_link_naming_the_old_attribute_selects_the_same_term(): void {
		$product = $this->product();
		$this->move( array( 'product_ids' => array( $product->get_id() ) ) );

		$url = MovedAttributeLinks::moved_url(
			$this->reload( $product->get_id() ),
			'https://shop.test/product/sign/?attribute_storlek=A3&pixonaut_edit=abc',
			array(
				'attribute_storlek' => 'A3',
				'pixonaut_edit'     => 'abc',
			)
		);
		$this->assertSame( 'https://shop.test/product/sign/?pixonaut_edit=abc&attribute_pa_storlek=a3-297', $url );

		$this->assertNull( MovedAttributeLinks::moved_url( $product, 'https://shop.test/?attribute_pa_storlek=a4-210', array( 'attribute_pa_storlek' => 'a4-210' ) ) );
		$other = $this->product();
		$this->assertNull( MovedAttributeLinks::moved_url( $other, 'https://shop.test/?attribute_storlek=A3', array( 'attribute_storlek' => 'A3' ) ), 'A product not moved keeps its link.' );
	}

	public function test_a_cart_line_made_before_the_move_keeps_its_size_and_an_order_line_on_any_finds_it(): void {
		$product   = $this->product();
		$variation = $this->variation( $product, 'A3', self::PRICE_LARGE );
		$hash      = wc_get_cart_item_data_hash( $variation );
		$this->move( array( 'product_ids' => array( $product->get_id() ) ) );

		$line = apply_filters(
			'woocommerce_get_cart_item_from_session',
			array(
				'product_id'   => $product->get_id(),
				'variation_id' => $variation->get_id(),
				'variation'    => array( 'attribute_storlek' => 'A3' ),
			),
			array(),
			'key'
		);
		$this->assertSame( array( 'attribute_pa_storlek' => 'a3-297' ), $line['variation'] );
		$this->assertSame( $hash, wc_get_cart_item_data_hash( $this->reload( $variation->get_id() ) ), 'WooCommerce keeps the line.' );

		$item = new WC_Order_Item_Product();
		$item->add_meta_data( self::CUSTOM_SLUG, 'A3' );
		$this->assertSame( 'a3-297', MovedAttributes::bought( $product->get_id(), self::TAXONOMY, $item ) );
	}

	public function test_a_cart_line_keeps_its_hash_when_its_variation_spells_the_value_otherwise(): void {
		$product   = $this->product();
		$otherwise = $this->variation( $product, 'a3', self::PRICE_LARGE );
		$as_listed = $this->variation( $product, 'A4', self::PRICE_SMALL );
		$hashes    = array(
			wc_get_cart_item_data_hash( $otherwise ),
			wc_get_cart_item_data_hash( $as_listed ),
		);

		$this->move( array( 'product_ids' => array( $product->get_id() ) ) );

		$this->assertSame(
			$hashes,
			array(
				wc_get_cart_item_data_hash( $this->reload( $otherwise->get_id() ) ),
				wc_get_cart_item_data_hash( $this->reload( $as_listed->get_id() ) ),
			)
		);
		$this->assertSame( array( $otherwise->get_id() => 'a3' ), MovedAttributes::of( $product->get_id() )[ self::CUSTOM_SLUG ]['spelled'] );
	}

	public function test_the_attributes_order_customers_see_and_a_cart_lines_hash_survive_with_equal_positions(): void {
		$size = new WC_Product_Attribute();
		$size->set_name( self::CUSTOM );
		$size->set_options( array( 'A4', 'A3' ) );
		$size->set_variation( true );
		$colour = new WC_Product_Attribute();
		$colour->set_name( 'Colour' );
		$colour->set_options( array( 'Red' ) );
		$colour->set_variation( true );
		$product = new WC_Product_Variable();
		$product->set_attributes( array( $size, $colour ) );
		$product->save();
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $product->get_id() );
		$variation->set_attributes(
			array(
				self::CUSTOM_SLUG => 'A3',
				'colour'          => 'Red',
			)
		);
		$variation->save();
		$hash = wc_get_cart_item_data_hash( $this->reload( $variation->get_id() ) );

		$this->move( array( 'product_ids' => array( $product->get_id() ) ) );

		$this->assertSame( array( self::TAXONOMY, 'colour' ), array_keys( $this->reload( $product->get_id() )->get_attributes() ) );
		$this->assertSame( $hash, wc_get_cart_item_data_hash( $this->reload( $variation->get_id() ) ) );
	}

	public function test_a_product_gets_its_attributes_default_choice_and_type_over_the_store_abilities(): void {
		$simple = new \WC_Product_Simple();
		$simple->set_name( 'Sign' );
		$simple->save();
		$update = wp_get_ability( Abilities::NAMESPACE . '/update-product' );
		$get    = wp_get_ability( Abilities::NAMESPACE . '/get-product' );
		$this->assertInstanceOf( WP_Ability::class, $update );
		$this->assertInstanceOf( WP_Ability::class, $get );

		$answer = $update->execute(
			array(
				'id'     => $simple->get_id(),
				'fields' => array(
					'type'               => 'variable',
					'attributes'         => array(
						array(
							'id'        => wc_attribute_taxonomy_id_by_name( self::TAXONOMY ),
							'visible'   => true,
							'variation' => true,
							'options'   => array( 'A4', 'A3' ),
						),
						array(
							'name'      => 'Finish',
							'visible'   => false,
							'variation' => false,
							'options'   => array( 'Matt' ),
						),
					),
					'default_attributes' => array(
						array(
							'id'     => wc_attribute_taxonomy_id_by_name( self::TAXONOMY ),
							'option' => 'A3',
						),
					),
				),
			)
		);
		$this->assertIsArray( $answer );
		$this->assertSame( 'variable', $answer['type'] );

		$read = $get->execute( array( 'id' => $simple->get_id() ) );
		$this->assertIsArray( $read );
		$this->assertEqualsCanonicalizing( array( 'A4', 'A3' ), $read['attributes'][0]['options'] );
		$this->assertTrue( $read['attributes'][0]['variation'] );
		$this->assertSame( 'Finish', $read['attributes'][1]['name'] );
		$this->assertFalse( $read['attributes'][1]['visible'] );
		$this->assertSame( 'a3-297', $read['default_attributes'][0]['option'] );
		$this->assertInstanceOf( WC_Product_Variable::class, $this->reload( $simple->get_id() ) );
	}

	/**
	 * @param array<string, mixed> $input What to add to the move's input.
	 * @return array<string, mixed>
	 */
	private function move( array $input ): array {
		$answer = $this->ability()->execute(
			$input + array(
				'from' => self::CUSTOM,
				'to'   => self::TAXONOMY,
			)
		);
		$this->assertIsArray( $answer, $answer instanceof WP_Error ? $answer->get_error_message() : '' );
		return $answer;
	}

	/**
	 * The move of a product as it stands, nothing written yet.
	 *
	 * @param int $product_id The product.
	 */
	private function plan( int $product_id ): AttributeMove {
		$move = AttributeMove::plan( $this->reload( $product_id ), self::CUSTOM, self::TAXONOMY );
		$this->assertInstanceOf( AttributeMove::class, $move );
		return $move;
	}

	/**
	 * Writes a row by the database alone, for what a transaction keeps or undoes.
	 *
	 * @param string $name The option's name.
	 */
	private function write_option( string $name ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- the row itself is under test, with no cache in the way.
		$wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => $name,
				'option_value' => '1',
			)
		);
	}

	/**
	 * Whether the row is in the database.
	 *
	 * @param string $name The option's name.
	 */
	private function option_written( string $name ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the row itself is under test, with no cache in the way.
		return null !== $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $name ) );
	}

	private function ability(): WP_Ability {
		$ability = wp_get_ability( self::MOVE );
		$this->assertInstanceOf( WP_Ability::class, $ability );
		return $ability;
	}

	/**
	 * A variable product on a custom attribute, its form placing it.
	 *
	 * @param string[] $options The attribute's values.
	 * @param string   $name    The attribute's name.
	 */
	private function product( array $options = array( 'A4', 'A3' ), string $name = self::CUSTOM ): WC_Product_Variable {
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( $name );
		$attribute->set_options( $options );
		$attribute->set_position( 3 );
		$attribute->set_visible( false );
		$attribute->set_variation( true );
		$product = new WC_Product_Variable();
		$product->set_name( 'Sign' );
		$product->set_status( 'publish' );
		$product->set_attributes( array( $attribute ) );
		$product->save();
		return $product;
	}

	private function arranged(): ProductConfig {
		$groups = FormGroups::from_array(
			array(
				'groups' => array(
					array(
						'id'    => 'wc-size',
						'title' => 'Size',
						'items' => array( array( 'attribute' => self::CUSTOM_SLUG ) ),
					),
				),
			)
		);
		return new ProductConfig( personalise: true, sides: array( new Side( 'front', 'Front', null, null, 'mini-poster', 1, null, 'Mini poster', form_groups: $groups ) ) );
	}

	/**
	 * @param WC_Product_Variable $product The product.
	 * @param string              $value   Its attribute's value; empty for any.
	 * @param string              $price   Its price.
	 */
	private function variation( WC_Product_Variable $product, string $value, string $price ): WC_Product_Variation {
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $product->get_id() );
		$variation->set_attributes( array( self::CUSTOM_SLUG => $value ) );
		$variation->set_regular_price( $price );
		$variation->set_status( 'publish' );
		$variation->save();
		return $variation;
	}

	private function reload( int $product_id ): WC_Product {
		wp_cache_delete( (string) $product_id, 'post_meta' );
		$product = wc_get_product( $product_id );
		$this->assertInstanceOf( WC_Product::class, $product );
		return $product;
	}

	private function user( string $role ): int {
		$id = self::factory()->user->create( array( 'role' => $role ) );
		return is_int( $id ) ? $id : 0;
	}
}
