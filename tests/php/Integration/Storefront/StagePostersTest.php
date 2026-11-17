<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Storefront;

use Pixonaut\WooCommerce\Api\Deprecations;
use Pixonaut\WooCommerce\Api\HookListener;
use Pixonaut\WooCommerce\Api\Pictures;
use Pixonaut\WooCommerce\Api\Pixonaut;
use Pixonaut\WooCommerce\Api\WcLogger;
use Pixonaut\WooCommerce\Connection\ConnectionStore;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Storefront\SideLayout;
use Pixonaut\WooCommerce\Storefront\StagePosters;
use Pixonaut\WooCommerce\Tests\Support\HttpRecorder;
use Pixonaut\WooCommerce\Tests\Support\PngFixture;
use WP_UnitTestCase;

/**
 * A product page's preview shows a picture of the designer's first drawing
 * from the first paint, which the store draws once the page is sent and
 * keeps as a file of its own.
 */
final class StagePostersTest extends WP_UnitTestCase {

	/**
	 * Loopback, so the test bootstrap's host guard lets it through to the recorder.
	 */
	private const BASE_URL = 'http://127.0.0.1:9';

	/**
	 * The size of `PngFixture::white()`.
	 */
	private const PNG_SIDE = 8;

	private const OPTIONS = array(
		'templateId'  => 'mini-tee@2',
		'blankId'     => 'blank_tee',
		'placementId' => 'front',
		'variants'    => array( 'v_white', 'v_black' ),
	);

	private HttpRecorder $recorder;

	private ConnectionStore $store;

	private StagePosters $posters;

	/**
	 * How many times the response was finished early.
	 *
	 * @var int
	 */
	private int $finished = 0;

	public function set_up(): void {
		parent::set_up();
		$this->recorder = ( new HttpRecorder( self::BASE_URL ) )->start();
		$this->store    = Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' )->connection_store();
		StorefrontTest::connect( $this->store );
		$this->posters = $this->posters();
		self::empty_directory();
	}

	public function tear_down(): void {
		$this->recorder->stop();
		$this->store->clear();
		remove_all_actions( 'shutdown' );
		self::empty_directory();
		parent::tear_down();
	}

	public function test_a_side_is_printed_without_a_poster_until_one_is_drawn_after_the_page(): void {
		$this->recorder->raw( 200, PngFixture::white(), 'image/png' );

		$this->assertNull( $this->posters->for_side( self::OPTIONS, self::layout(), 'v_black' ) );
		$this->assertCount( 0, $this->recorder->requests, 'the page does not wait for the picture' );

		$this->posters->draw_queued();

		$this->assertSame( 1, $this->finished );
		$this->assertCount( 1, $this->recorder->requests );
		$asked = $this->recorder->requests[0]['url'];
		$this->assertStringContainsString( '/t/mini-tee%402/mockup.png?', $asked );
		$this->assertStringContainsString( 'viewId=view_front', $asked );
		$this->assertStringContainsString( 'variant=v_black', $asked );
		$this->assertStringContainsString( 'size=' . StagePosters::SIZE, $asked );
		$poster = $this->posters()->for_side( self::OPTIONS, self::layout(), 'v_black' );
		$this->assertNotNull( $poster );
		$this->assertSame( self::PNG_SIDE, $poster['width'] );
		$this->assertSame( self::PNG_SIDE, $poster['height'] );
		$this->assertSame( 'mini-tee@2', $poster['template'] );
		$this->assertSame( 'v_black', $poster['variant'] );
		$this->assertStringStartsWith( wp_upload_dir()['baseurl'] . '/' . StagePosters::DIRECTORY . '/', $poster['url'] );
		$this->assertMatchesRegularExpression( '/-8x8\.(webp|jpg|png)$/', $poster['url'] );
		$this->assertCount( 1, $this->recorder->requests, 'a fresh picture is not drawn again' );
	}

	public function test_without_a_variant_the_side_shows_its_first(): void {
		$this->recorder->raw( 200, PngFixture::white(), 'image/png' );
		$this->posters->for_side( self::OPTIONS, self::layout(), null );

		$this->posters->draw_queued();

		$this->assertStringContainsString( 'variant=v_white', $this->recorder->requests[0]['url'] );
		$this->assertSame( 'v_white', $this->posters()->for_side( self::OPTIONS, self::layout(), null )['variant'] ?? null );
	}

	public function test_a_picture_pixonaut_refused_is_not_asked_for_again_for_a_minute(): void {
		$this->recorder->json( 404, array( 'error' => 'not_found' ) );
		$this->posters->for_side( self::OPTIONS, self::layout(), null );
		$this->posters->draw_queued();

		$again = $this->posters();
		$this->assertNull( $again->for_side( self::OPTIONS, self::layout(), null ) );
		$again->draw_queued();

		$this->assertCount( 1, $this->recorder->requests );
		$this->assertSame( array(), self::kept() );
	}

	public function test_an_old_picture_is_printed_and_drawn_again(): void {
		$this->recorder->raw( 200, PngFixture::white(), 'image/png' )->raw( 200, PngFixture::white(), 'image/png' );
		$this->posters->for_side( self::OPTIONS, self::layout(), null );
		$this->posters->draw_queued();
		foreach ( self::kept() as $file ) {
			touch( $file, time() - StagePosters::FRESH_SECONDS ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- ages a test's file.
		}

		$page = $this->posters();
		$this->assertNotNull( $page->for_side( self::OPTIONS, self::layout(), null ) );
		$page->draw_queued();

		$this->assertCount( 2, $this->recorder->requests );
		$this->assertCount( 1, self::kept() );
	}

	public function test_a_picture_not_drawn_again_for_a_month_goes_when_another_is_drawn(): void {
		$this->recorder->raw( 200, PngFixture::white(), 'image/png' );
		$directory = (string) StagePosters::directory();
		wp_mkdir_p( $directory );
		$forgotten = $directory . '/forgotten-8x8.png';
		file_put_contents( $forgotten, PngFixture::white() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a test's fixture file.
		touch( $forgotten, time() - StagePosters::KEPT_SECONDS ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- ages a test's file.

		$this->posters->for_side( self::OPTIONS, self::layout(), null );
		$this->posters->draw_queued();

		$this->assertFileDoesNotExist( $forgotten );
		$this->assertCount( 1, self::kept() );
	}

	public function test_a_side_on_the_design_view_or_on_no_blank_has_no_poster(): void {
		$design = new SideLayout(
			array( 1, 1 ),
			array(
				array(
					'id'        => 'design',
					'label'     => '',
					'thumbnail' => '',
				),
			),
			array(),
			array()
		);

		$this->assertNull( $this->posters->for_side( self::OPTIONS, $design, null ) );
		$this->assertNull( $this->posters->for_side( array( 'templateId' => 'mini-tee@2' ), self::layout(), null ) );
		$this->posters->draw_queued();

		$this->assertSame( 0, $this->finished );
		$this->assertCount( 0, $this->recorder->requests );
	}

	public function test_a_server_that_cannot_finish_the_response_early_draws_nothing(): void {
		$client  = ( new Pixonaut( $this->store, new WcLogger(), new HookListener( new Deprecations() ), null, self::BASE_URL ) );
		$posters = new StagePosters( new Pictures( $client ), null );
		$posters->for_side( self::OPTIONS, self::layout(), null );

		$this->assertFalse( has_action( 'shutdown', array( $posters, 'draw_queued' ) ) );
		$this->assertCount( 0, $this->recorder->requests );
	}

	/**
	 * A page view's posters, whose response finishing early is counted.
	 */
	private function posters(): StagePosters {
		$client = new Pixonaut( $this->store, new WcLogger(), new HookListener( new Deprecations() ), null, self::BASE_URL );
		return new StagePosters(
			new Pictures( $client ),
			function (): bool {
				++$this->finished;
				return true;
			}
		);
	}

	/**
	 * A side opening on the front view of its blank.
	 */
	private static function layout(): SideLayout {
		return new SideLayout(
			array( 3, 4 ),
			array(
				array(
					'id'        => 'view_front',
					'label'     => 'Front',
					'thumbnail' => '',
				),
			),
			array(),
			array()
		);
	}

	/**
	 * The files kept in the pictures' directory.
	 *
	 * @return list<string>
	 */
	private static function kept(): array {
		$files = glob( (string) StagePosters::directory() . '/*' );
		return false === $files ? array() : $files;
	}

	private static function empty_directory(): void {
		foreach ( self::kept() as $file ) {
			wp_delete_file( $file );
		}
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( StagePosters::HOLD_OPTION_PREFIX ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_cache_flush();
	}
}
