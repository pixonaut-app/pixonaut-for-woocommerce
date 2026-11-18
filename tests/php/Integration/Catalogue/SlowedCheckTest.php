<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Catalogue;

use Pixonaut\WooCommerce\Admin\PanelFields;
use Pixonaut\WooCommerce\Admin\ProductPanel;
use Pixonaut\WooCommerce\Admin\VariationPanel;
use Pixonaut\WooCommerce\Api\CheckSlowdown;
use Pixonaut\WooCommerce\Catalogue\InvalidReason;
use Pixonaut\WooCommerce\Catalogue\PostedSides;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ProductRecheck;
use Pixonaut\WooCommerce\Tests\Support\ActionSchedulerRunner;
use Pixonaut\WooCommerce\Tests\Support\VerdictAnswers;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\ErrorCode;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Variable;
use WC_Product_Variation;

/**
 * Saves Pixonaut refuses to check because the store sent too many
 * requests: what a product keeps, when it is checked again, and what its
 * notice says.
 */
final class SlowedCheckTest extends ProductPanelTestCase {

	private const TOO_MANY_REQUESTS = 429;

	/**
	 * The wait Pixonaut names in its refusals here.
	 */
	private const RETRY_AFTER_SECONDS = 30;

	/**
	 * Variations saved in a row, as a bulk edit or the shop tools save them.
	 */
	private const VARIATIONS_IN_A_ROW = 100;

	private const PRINT_SIZE_PATH = '/print-size';

	public function set_up(): void {
		parent::set_up();
		delete_option( CheckSlowdown::OPTION );
		as_unschedule_all_actions( ProductRecheck::ACTION );
	}

	public function tear_down(): void {
		delete_option( CheckSlowdown::OPTION );
		as_unschedule_all_actions( ProductRecheck::ACTION );
		parent::tear_down();
	}

	public function test_a_changed_setup_refused_for_too_many_requests_is_checked_again_after_the_wait_pixonaut_names(): void {
		$product = $this->linked_product();
		$this->script_blank( $this->blank() );
		$this->slow_down();

		$saved_at = time();
		$this->save( $product->get_id(), array(), $this->a4_side() );

		$config = ProductConfig::stored_on( $this->reload( $product->get_id() ) );
		$this->assertSame( array( false, InvalidReason::CHECK_FAILED, ErrorCode::RATE_LIMITED ), array( $config->valid, $config->invalid_reason, $config->invalid_detail?->code ) );
		$this->assertRecheckedAfter( $saved_at + self::RETRY_AFTER_SECONDS, $product->get_id() );
		$notice = $this->plugin->notices()->all()[ ProductPanel::notice_key( $product->get_id() ) ]['message'] ?? '';
		$this->assertStringContainsString( (string) wp_date( (string) get_option( 'time_format' ), (int) get_option( CheckSlowdown::OPTION ) ), $notice, 'The notice names when it is checked again.' );
		$this->assertStringNotContainsString( 'update the product', $notice );
	}

	public function test_an_unchanged_setup_refused_for_too_many_requests_stays_sellable(): void {
		$product = $this->linked_product();
		$before  = ProductConfig::stored_on( $this->reload( $product->get_id() ) );
		$this->script_blank( $this->blank() );
		$this->recorder->json( 200, $this->constraints( self::PLACEMENT_ID ) );
		$this->slow_down();

		$saved_at = time();
		$this->save( $product->get_id() );

		$fresh = $this->reload( $product->get_id() );
		$this->assertEquals( $before, ProductConfig::stored_on( $fresh ) );
		$this->assertTrue( $fresh->is_purchasable() );
		$this->assertFalse( $this->plugin->notices()->has( ProductPanel::notice_key( $product->get_id() ) ) );
		$this->assertRecheckedAfter( $saved_at + self::RETRY_AFTER_SECONDS, $product->get_id() );
	}

	public function test_a_save_while_pixonaut_holds_checks_sends_no_print_size_check(): void {
		$product = $this->linked_product();
		update_option( CheckSlowdown::OPTION, time() + self::RETRY_AFTER_SECONDS, false );
		$requests = count( $this->recorder->requests );
		$this->script_blank( $this->blank() );

		$this->save( $product->get_id(), array(), $this->a4_side() );

		$this->assertSame( array( 'GET /v1/blanks/' . self::BLANK_ID ), $this->requested_since( $requests ) );
		$this->assertSame( ErrorCode::RATE_LIMITED, ProductConfig::stored_on( $this->reload( $product->get_id() ) )->invalid_detail?->code );
		$this->assertRecheckedAfter( (int) get_option( CheckSlowdown::OPTION ), $product->get_id() );
	}

	public function test_variations_saved_in_a_row_queue_their_checks_after_the_wait(): void {
		$variations = $this->variations( self::VARIATIONS_IN_A_ROW );
		$requests   = count( $this->recorder->requests );
		$this->script_blank( $this->blank() );
		$this->slow_down();
		foreach ( array_slice( $variations, 1 ) as $ignored ) {
			$this->script_blank( $this->blank() );
		}

		$saved_at = time();
		foreach ( $variations as $loop => $variation ) {
			$this->save_variation( $variation, $loop );
		}

		$checks = array_filter( $this->requested_since( $requests ), static fn ( string $request ): bool => str_ends_with( $request, self::PRINT_SIZE_PATH ) );
		$this->assertCount( 1, $checks, 'Pixonaut is asked once; the rest wait for the wait it named.' );
		$due = ActionSchedulerRunner::due_at( ProductRecheck::ACTION );
		$this->assertCount( self::VARIATIONS_IN_A_ROW, $due );
		$this->assertGreaterThanOrEqual( $saved_at + self::RETRY_AFTER_SECONDS, array() === $due ? 0 : min( $due ) );

		// A re-check whose turn comes while Pixonaut still holds checks waits again, asking nothing.
		$requests = count( $this->recorder->requests );
		update_option( CheckSlowdown::OPTION, time() + self::RETRY_AFTER_SECONDS, false );
		$job = ActionSchedulerRunner::pending( ProductRecheck::ACTION )[0];
		\ActionScheduler::runner()->process_action( $job );

		$this->assertSame( array(), $this->requested_since( $requests ) );
		$this->assertCount( self::VARIATIONS_IN_A_ROW, ActionSchedulerRunner::pending( ProductRecheck::ACTION ) );
	}

	/**
	 * Asserts a re-check of the product waits until at least a time.
	 *
	 * @param int $at         The earliest time it may run.
	 * @param int $product_id The product.
	 */
	private function assertRecheckedAfter( int $at, int $product_id ): void {
		$found = false;
		foreach ( ActionSchedulerRunner::pending( ProductRecheck::ACTION ) as $id ) {
			$action = \ActionScheduler::store()->fetch_action( (string) $id );
			if ( in_array( $product_id, $action->get_args()['product_ids'] ?? array(), true ) ) {
				$found = true;
				$this->assertGreaterThanOrEqual( $at, \ActionScheduler::store()->get_date( (string) $id )->getTimestamp() );
			}
		}
		$this->assertTrue( $found, 'The product is checked again.' );
	}

	/**
	 * Scripts Pixonaut refusing the next request because the store sent too many.
	 */
	private function slow_down(): void {
		$this->recorder->json( self::TOO_MANY_REQUESTS, array( 'error' => 'rate_limited' ), array( 'Retry-After' => (string) self::RETRY_AFTER_SECONDS ) );
	}

	/**
	 * A product linked in the placement's default size, sellable.
	 */
	private function linked_product(): WC_Product {
		$product = $this->product();
		$this->script_link( VerdictAnswers::LEGAL );
		$this->save( $product->get_id() );
		$this->assertTrue( ProductConfig::stored_on( $this->reload( $product->get_id() ) )->valid );
		return $product;
	}

	/**
	 * A front sold in A4 alone.
	 *
	 * @return array<string, mixed>
	 */
	private function a4_side(): array {
		return array(
			'sizes' => array(
				array(
					'kind'   => PostedSides::SIZE_CUSTOM,
					'width'  => '210',
					'height' => '297',
					'label'  => 'A4',
				),
			),
		);
	}

	/**
	 * A variable product's variations, none linked.
	 *
	 * @param int $count How many.
	 * @return list<WC_Product_Variation>
	 */
	private function variations( int $count ): array {
		$values = array_map( static fn ( int $i ): string => 'V' . $i, range( 1, $count ) );
		$parent = new WC_Product_Variable();
		$parent->set_name( 'Personalised poster' );
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'motif' );
		$attribute->set_options( $values );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$parent->set_attributes( array( $attribute ) );
		$parent->save();
		$variations = array();
		foreach ( $values as $value ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $parent->get_id() );
			$variation->set_attributes( array( 'motif' => $value ) );
			$variation->set_regular_price( '30' );
			$variation->save();
			$variations[] = $variation;
		}
		return $variations;
	}

	/**
	 * Saves a variation's panel with its own setup, the front in A4, as
	 * WooCommerce's variations save posts it.
	 *
	 * @param WC_Product $variation The variation.
	 * @param int        $loop      Its place in the variations list.
	 */
	private function save_variation( WC_Product $variation, int $loop ): void {
		wp_set_current_user( $this->user( 'administrator' ) );
		$side  = array(
			'key'           => '',
			'label'         => '',
			'placement'     => self::PLACEMENT_ID,
			'template'      => self::TEMPLATE_ID,
			'template_name' => 'Mini tee',
		) + $this->a4_side();
		$_POST = array(
			PanelFields::VARIATION_FIELD => array(
				$loop => wp_slash(
					array(
						PanelFields::NONCE       => wp_create_nonce( VariationPanel::NONCE_ACTION ),
						PanelFields::OVERRIDE    => 'yes',
						PanelFields::PERSONALISE => 'yes',
						PanelFields::BLANK       => self::BLANK_ID,
						PanelFields::SIDES       => array( $side ),
					)
				),
			),
		);
		( new VariationPanel( $this->panel, $this->plugin->templates(), $this->variant_field() ) )->save( $variation->get_id(), $loop );
		$_POST = array();
	}

	/**
	 * The requests made after the first few, each as `<METHOD> <path>`.
	 *
	 * @param int $skip How many requests came before.
	 * @return list<string>
	 */
	private function requested_since( int $skip ): array {
		return array_map(
			static fn ( array $request ): string => $request['method'] . ' ' . rawurldecode( (string) wp_parse_url( $request['url'], PHP_URL_PATH ) ),
			array_slice( $this->recorder->requests, $skip )
		);
	}
}
