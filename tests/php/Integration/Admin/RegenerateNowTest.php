<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Admin;

use Pixonaut\WooCommerce\Admin\RegenerateNow;
use Pixonaut\WooCommerce\Admin\RegeneratePictures;
use Pixonaut\WooCommerce\Catalogue\PictureQueue;
use Pixonaut\WooCommerce\Catalogue\ProductPictures;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Tests\Integration\Catalogue\PicturedProducts;
use Pixonaut\WooCommerce\Tests\Support\ActionSchedulerRunner;
use WC_Product;
use WC_Product_Simple;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * Regenerate now queues the products' pictures, then draws each in a
 * request of its own, without Action Scheduler or WP-Cron.
 */
final class RegenerateNowTest extends WP_UnitTestCase {

	use PicturedProducts;

	private const REDIRECT = 'http://example.org/wp-admin/edit.php?post_type=product';

	private const FORBIDDEN = 403;

	private const CONFLICT = 409;

	/**
	 * Requests sent to start the picture runners.
	 *
	 * @var list<string>
	 */
	private array $runners = array();

	public function set_up(): void {
		parent::set_up();
		$this->set_up_pictures();
		wp_set_current_user( self::user( 'shop_manager' ) );
		$GLOBALS['wp_rest_server'] = new WP_REST_Server();
		do_action( 'rest_api_init', $GLOBALS['wp_rest_server'] );
		$this->runners = array();
		add_filter( 'pre_http_request', array( $this, 'record_runner' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'record_runner' ), 10 );
		$GLOBALS['wp_rest_server'] = null;
		$this->tear_down_pictures();
		parent::tear_down();
	}

	/**
	 * Records, and answers, a request starting the picture runners.
	 *
	 * @param mixed  $preempt The answer so far.
	 * @param mixed  $args    The request's arguments.
	 * @param string $url     Where it goes.
	 * @return mixed
	 */
	public function record_runner( mixed $preempt, mixed $args, string $url ): mixed {
		unset( $args );
		if ( ! str_contains( $url, 'action=pixonaut_wc_pictures' ) ) {
			return $preempt;
		}
		$this->runners[] = $url;
		return array(
			'headers'  => array(),
			'body'     => '',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
		);
	}

	public function test_starting_queues_the_pictures_and_lists_those_to_draw_without_starting_the_runners(): void {
		$picture = $this->drawn( $this->product( self::config() ) );
		$id      = $picture['product']->get_id();
		$plain   = new WC_Product_Simple();
		$plain->save();

		$started = $this->start( array( $id, $plain->get_id() ) );

		$this->assertSame(
			array(
				array(
					'id'    => $id,
					'name'  => 'Personalised tee',
					'items' => array( $id ),
					'state' => RegeneratePictures::DRAWING,
				),
			),
			$started->get_data()['products']
		);
		$this->assertCount( 1, ActionSchedulerRunner::pending( ProductPictures::ACTION ) );
		$this->assertSame( array(), $this->runners );
	}

	public function test_drawing_draws_the_picture_while_the_request_runs_and_drops_its_queued_job(): void {
		$picture = $this->drawn( $this->product( self::config() ) );
		$id      = $picture['product']->get_id();
		$this->start( array( $id ) );
		// Action Scheduler is never run below: the request draws it.
		add_filter( 'pre_option_cron', static fn (): array => array() );
		$this->recorder->raw( 200, self::png(), 'image/png' );

		$drawn = $this->draw( $id );

		$this->assertSame( array( 'state' => RegeneratePictures::DRAWN ), $drawn->get_data() );
		$redrawn = (int) $this->reload( $picture['product'] )->get_image_id();
		$this->assertNotSame( $picture['id'], $redrawn );
		$this->assertNull( get_post( $picture['id'] ) );
		$this->assertSame( array(), ActionSchedulerRunner::pending( ProductPictures::ACTION ) );
		$this->assertSame( array(), $this->runners );
	}

	public function test_drawing_says_when_pixonaut_could_not_draw_it(): void {
		$picture = $this->drawn( $this->product( self::config() ) );
		$id      = $picture['product']->get_id();
		$this->start( array( $id ) );
		$this->recorder->fail();

		$this->assertSame( array( 'state' => RegeneratePictures::FAILED ), $this->draw( $id )->get_data() );
		$this->assertSame( $picture['id'], (int) $this->reload( $picture['product'] )->get_image_id() );
	}

	public function test_a_picture_another_job_is_drawing_is_left_to_it(): void {
		$picture = $this->drawn( $this->product( self::config() ) );
		$id      = $picture['product']->get_id();
		$this->start( array( $id ) );
		add_option( ProductPictures::LOCK_OPTION_PREFIX . $id, (string) time(), '', false );
		$sent = count( $this->recorder->requests );

		$this->assertSame( array( 'state' => RegeneratePictures::DRAWING ), $this->draw( $id )->get_data() );
		$this->assertCount( $sent, $this->recorder->requests );
		$this->assertNotSame( array(), ActionSchedulerRunner::pending( ProductPictures::ACTION ) );
	}

	public function test_a_sign_in_three_sizes_is_drawn_once_one_request_per_picture(): void {
		$product = $this->sign();
		$this->recorder->raw( 200, self::png(), 'image/png' );
		ActionSchedulerRunner::run_pending( PictureQueue::VARIATIONS_ACTION );
		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );
		$this->assertCount( 1, $this->recorder->requests );

		$items = $this->start( array( $product->get_id() ) )->get_data()['products'][0]['items'];
		$this->recorder->raw( 200, self::png(), 'image/png' );
		$states = array_map( fn ( int $item ): mixed => $this->draw( $item )->get_data()['state'], $items );

		$this->assertCount( count( self::sign_sizes() ) + 1, $items );
		$this->assertSame( array_fill( 0, count( $items ), RegeneratePictures::DRAWN ), $states );
		$this->assertCount( 2, $this->recorder->requests );
		$pictures = array_unique( array_map( static fn ( int $item ): int => (int) get_post_thumbnail_id( $item ), $items ) );
		$this->assertCount( 1, $pictures );
		$this->assertSame( array(), ActionSchedulerRunner::pending( ProductPictures::ACTION ) );
	}

	public function test_the_products_not_drawn_are_left_to_the_background_queue(): void {
		$first  = $this->drawn( $this->product( self::config() ) );
		$second = $this->drawn( $this->product( self::config() ) );
		$this->start( array( $first['product']->get_id(), $second['product']->get_id() ) );
		$this->recorder->raw( 200, self::png(), 'image/png' );
		$this->draw( $first['product']->get_id() );

		// The tab closed: the background draws the rest.
		$this->recorder->raw( 200, self::png(), 'image/png' );
		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		$this->assertNotSame( $second['id'], (int) $this->reload( $second['product'] )->get_image_id() );
		$this->assertNull( get_post( $second['id'] ) );
	}

	public function test_drawing_refuses_a_user_who_cannot_edit_the_product_and_a_product_without_a_picture(): void {
		$picture = $this->drawn( $this->product( self::config() ) );
		$plain   = new WC_Product_Simple();
		$plain->save();

		$this->assertSame( self::CONFLICT, $this->draw( $plain->get_id() )->get_status() );
		wp_set_current_user( self::user( 'customer' ) );
		$this->assertSame( self::FORBIDDEN, $this->draw( $picture['product']->get_id() )->get_status() );
		$this->assertSame( self::FORBIDDEN, $this->start( array( $picture['product']->get_id() ) )->get_status() );
	}

	public function test_both_ways_are_bulk_actions_and_row_actions_and_now_without_the_script_queues(): void {
		$product    = $this->drawn( $this->product( self::config() ) )['product'];
		$regenerate = $this->plugin->regenerate_pictures();
		$plain      = new WC_Product_Simple();
		$plain->save();

		$this->assertSame(
			array(
				RegeneratePictures::BULK_ACTION => 'Regenerate in background',
				RegenerateNow::BULK_ACTION      => 'Regenerate now',
			),
			$regenerate->bulk_actions( array() )
		);
		$row = $regenerate->row_actions( array(), get_post( $product->get_id() ) );
		$this->assertIsArray( $row );
		$this->assertSame( array( RegeneratePictures::BULK_ACTION, RegenerateNow::BULK_ACTION ), array_keys( $row ) );
		$this->assertStringContainsString( 'action=' . RegenerateNow::BULK_ACTION, html_entity_decode( (string) $row[ RegenerateNow::BULK_ACTION ] ) );
		$this->assertStringContainsString( '_wpnonce=' . wp_create_nonce( 'bulk-posts' ), html_entity_decode( (string) $row[ RegenerateNow::BULK_ACTION ] ) );
		$this->assertSame( array(), $regenerate->row_actions( array(), get_post( $plain->get_id() ) ) );

		$redirect = $regenerate->handle_bulk( self::REDIRECT, RegenerateNow::BULK_ACTION, array( $product->get_id() ) );

		$this->assertSame( add_query_arg( RegeneratePictures::DONE_ARG, 1, self::REDIRECT ), $redirect );
		$this->assertCount( 1, ActionSchedulerRunner::pending( ProductPictures::ACTION ) );
	}

	public function test_the_dialog_is_printed_on_the_products_list_while_pictures_are_product_images(): void {
		set_current_screen( 'edit-product' );
		$now = $this->plugin->regenerate_now();

		ob_start();
		$now->root();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="' . RegenerateNow::ROOT_ID . '"', $html );
		$this->assertStringContainsString( 'Closing it or pressing Stop leaves the rest to the background queue.', $html );
		$this->assertStringContainsString( 'data-draw-url="' . esc_url( rest_url( Plugin::REST_NAMESPACE . RegenerateNow::DRAW_ROUTE ) ) . '"', $html );

		update_option( Settings::OPTION, array( Settings::PRODUCT_PICTURES => false ) );
		ob_start();
		$now->root();
		$this->assertSame( '', ob_get_clean() );
		set_current_screen( 'front' );
	}

	/**
	 * @param int[] $ids Products.
	 */
	private function start( array $ids ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/' . Plugin::REST_NAMESPACE . RegenerateNow::START_ROUTE );
		$request->set_param( 'product_ids', $ids );
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * @param int $item A product or variation.
	 */
	private function draw( int $item ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/' . Plugin::REST_NAMESPACE . RegenerateNow::DRAW_ROUTE );
		$request->set_param( 'item', $item );
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * A new user with the role.
	 *
	 * @param string $role A role.
	 */
	private static function user( string $role ): int {
		$user = self::factory()->user->create( array( 'role' => $role ) );
		self::assertIsInt( $user );
		return $user;
	}
}
