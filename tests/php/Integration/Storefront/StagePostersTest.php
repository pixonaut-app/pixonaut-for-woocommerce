<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Storefront;

use Pixonaut\WooCommerce\Api\Answers\ConnectedStoreExchange;
use Pixonaut\WooCommerce\Api\Deprecations;
use Pixonaut\WooCommerce\Api\HookListener;
use Pixonaut\WooCommerce\Api\Pictures;
use Pixonaut\WooCommerce\Api\Pixonaut;
use Pixonaut\WooCommerce\Api\WcLogger;
use Pixonaut\WooCommerce\Connection\ConnectionStore;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Storefront\SideLayout;
use Pixonaut\WooCommerce\Storefront\StagePosters;
use Pixonaut\WooCommerce\Tests\Support\HttpRecorder;
use Pixonaut\WooCommerce\Tests\Support\PngFixture;
use Pixonaut\WooCommerce\Vendor\Psr\Log\AbstractLogger;
use Stringable;
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

	/**
	 * A grey that is not `PngFixture::white()`'s.
	 */
	private const GREY = 0x808080;

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

	/**
	 * Keeps what the posters logged, in `$messages`.
	 *
	 * @var AbstractLogger&object{messages: list<string>}
	 */
	private AbstractLogger $logger;

	public function set_up(): void {
		parent::set_up();
		$this->recorder = ( new HttpRecorder( self::BASE_URL ) )->start();
		$this->store    = Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' )->connection_store();
		StorefrontTest::connect( $this->store );
		$this->logger  = new class() extends AbstractLogger {
			/**
			 * The messages logged.
			 *
			 * @var list<string>
			 */
			public array $messages = array();

			/**
			 * @param mixed                $level   Ignored.
			 * @param string|Stringable    $message The message.
			 * @param array<string, mixed> $context Ignored.
			 */
			public function log( $level, string|Stringable $message, array $context = array() ): void {
				unset( $level, $context );
				$this->messages[] = (string) $message;
			}
		};
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
		$this->assertSame(
			array(
				'template'  => 'mini-tee@2',
				'blank'     => 'blank_tee',
				'placement' => 'front',
				'view'      => 'view_front',
				'variant'   => 'v_black',
			),
			array_intersect_key( $poster, array_flip( array( 'template', 'blank', 'placement', 'view', 'variant' ) ) )
		);
		$this->assertStringStartsWith( wp_upload_dir()['baseurl'] . '/' . StagePosters::DIRECTORY . '/', $poster['url'] );
		$this->assertMatchesRegularExpression( '/-8x8-[0-9a-f]+\.(webp|jpg|png)$/', $poster['url'] );
		$this->assertCount( 1, $this->recorder->requests, 'a fresh picture is not drawn again' );
	}

	public function test_without_a_variant_the_side_shows_its_first(): void {
		$this->recorder->raw( 200, PngFixture::white(), 'image/png' );
		$this->posters->for_side( self::OPTIONS, self::layout(), null );

		$this->posters->draw_queued();

		$this->assertStringContainsString( 'variant=v_white', $this->recorder->requests[0]['url'] );
		$this->assertSame( 'v_white', $this->posters()->for_side( self::OPTIONS, self::layout(), null )['variant'] ?? null );
	}

	public function test_the_poster_shows_the_view_the_side_opens_on(): void {
		$this->recorder->raw( 200, PngFixture::white(), 'image/png' );

		$this->posters->for_side( self::OPTIONS, self::layout( 'view_back' ), null );
		$this->posters->draw_queued();

		$this->assertStringContainsString( 'viewId=view_back', $this->recorder->requests[0]['url'] );
		$this->assertSame( 'view_back', $this->posters()->for_side( self::OPTIONS, self::layout( 'view_back' ), null )['view'] ?? null );
	}

	public function test_a_picture_pixonaut_refused_is_logged_and_not_asked_for_again_for_a_minute(): void {
		$this->recorder->json( 404, array( 'error' => 'not_found' ) );
		$this->posters->for_side( self::OPTIONS, self::layout(), null );
		$this->posters->draw_queued();

		$again = $this->posters();
		$this->assertNull( $again->for_side( self::OPTIONS, self::layout(), null ) );
		$again->draw_queued();

		$this->assertCount( 1, $this->recorder->requests );
		$this->assertSame( array(), self::kept() );
		$this->assertCount( 1, $this->logger->messages );
		$this->assertStringContainsString( 'could not be drawn', $this->logger->messages[0] );
	}

	public function test_an_old_picture_is_printed_and_drawn_again_under_a_new_link(): void {
		$this->recorder->raw( 200, PngFixture::white(), 'image/png' )->raw( 200, self::png( self::GREY ), 'image/png' )->raw( 200, PngFixture::white(), 'image/png' );
		$this->posters->for_side( self::OPTIONS, self::layout(), null );
		$this->posters->draw_queued();

		$first = $this->aged_poster();
		$this->posters->draw_queued();
		$second = $this->aged_poster();
		$this->posters->draw_queued();
		$third = $this->posters()->for_side( self::OPTIONS, self::layout(), null );

		$this->assertCount( 3, $this->recorder->requests );
		$this->assertNotSame( $first, $second, 'a cache that keeps uploads asks for the new drawing' );
		$this->assertNotNull( $third );
		$this->assertNotSame( $second, $third['url'] );
		// The drawing before stays for pages cached with its link.
		$this->assertCount( 2, self::kept() );
	}

	public function test_a_picture_not_drawn_again_for_a_month_goes_when_another_is_drawn(): void {
		$this->recorder->raw( 200, PngFixture::white(), 'image/png' );
		$directory = (string) StagePosters::directory();
		wp_mkdir_p( $directory );
		$forgotten = $directory . '/forgotten-8x8-0a1b2c3d.png';
		file_put_contents( $forgotten, PngFixture::white() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a test's fixture file.
		touch( $forgotten, time() - StagePosters::KEPT_SECONDS ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- ages a test's file.

		$this->posters->for_side( self::OPTIONS, self::layout(), null );
		$this->posters->draw_queued();

		$this->assertFileDoesNotExist( $forgotten );
		$this->assertCount( 1, self::kept() );
	}

	public function test_a_picture_drawn_for_another_connection_is_not_printed(): void {
		$this->recorder->raw( 200, PngFixture::white(), 'image/png' );
		$this->posters->for_side( self::OPTIONS, self::layout(), null );
		$this->posters->draw_queued();

		$this->store->save( StoredConnection::from_exchange( ConnectedStoreExchange::from_answer( array( 'connectionId' => 'sc_another' ) + StorefrontTest::EXCHANGED ), wp_generate_uuid4(), home_url(), time() ) );

		$this->assertNull( $this->posters()->for_side( self::OPTIONS, self::layout(), null ) );
	}

	public function test_a_side_on_the_design_view_or_on_no_blank_has_no_poster(): void {
		$this->assertNull( $this->posters->for_side( self::OPTIONS, self::layout( 'design' ), null ) );
		$this->assertNull( $this->posters->for_side( array( 'templateId' => 'mini-tee@2' ), self::layout(), null ) );
		$this->posters->draw_queued();

		$this->assertSame( 0, $this->finished );
		$this->assertCount( 0, $this->recorder->requests );
	}

	public function test_a_server_that_cannot_finish_the_response_early_draws_in_a_job(): void {
		$this->recorder->raw( 200, PngFixture::white(), 'image/png' );
		$posters = $this->posters_without_finishing();
		$posters->register();

		$posters->for_side( self::OPTIONS, self::layout(), 'v_black' );
		$posters->for_side( self::OPTIONS, self::layout(), 'v_black' );
		$this->posters()->for_side( self::OPTIONS, self::layout(), 'v_black' );

		$this->assertFalse( has_action( 'shutdown', array( $posters, 'draw_queued' ) ) );
		$this->assertCount( 0, $this->recorder->requests );
		$args = array( 'mini-tee@2', 'blank_tee', 'front', 'view_front', 'v_black', StorefrontTest::EXCHANGED['connectionId'] );
		$this->assertCount( 1, as_get_scheduled_actions( self::job( $args ), 'ids' ) );
		do_action( StagePosters::ACTION, ...$args );
		$this->assertCount( 1, $this->recorder->requests );
		$this->assertNotNull( $this->posters()->for_side( self::OPTIONS, self::layout(), 'v_black' ) );
		remove_action( StagePosters::ACTION, array( $posters, 'draw_scheduled' ) );
		as_unschedule_all_actions( StagePosters::ACTION, $args, StagePosters::GROUP );
	}

	public function test_a_job_queued_under_another_connection_draws_nothing(): void {
		$this->posters_without_finishing()->draw_scheduled( 'mini-tee@2', 'blank_tee', 'front', 'view_front', 'v_black', 'sc_another' );

		$this->assertCount( 0, $this->recorder->requests );
		$this->assertSame( array(), self::kept() );
	}

	/**
	 * Ages the kept pictures by a day and has a new page view print
	 * the poster, which queues it to be drawn again; its link.
	 */
	private function aged_poster(): string {
		clearstatcache();
		foreach ( self::kept() as $file ) {
			// Each a day older, so the newest stays the newest.
			touch( $file, (int) filemtime( $file ) - StagePosters::FRESH_SECONDS ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- ages a test's file.
		}
		clearstatcache();
		$this->posters = $this->posters();
		$poster        = $this->posters->for_side( self::OPTIONS, self::layout(), null );
		$this->assertNotNull( $poster, 'an old picture is printed while it is drawn again' );
		return $poster['url'];
	}

	/**
	 * A page view's posters, whose response finishing early is counted and
	 * whose log is kept.
	 */
	private function posters(): StagePosters {
		$client = new Pixonaut( $this->store, new WcLogger(), new HookListener( new Deprecations() ), null, self::BASE_URL );
		return new StagePosters(
			new Pictures( $client ),
			$this->store,
			$this->logger,
			function (): bool {
				++$this->finished;
				return true;
			}
		);
	}

	/**
	 * Posters on a server that cannot finish a response early.
	 */
	private function posters_without_finishing(): StagePosters {
		$client = new Pixonaut( $this->store, new WcLogger(), new HookListener( new Deprecations() ), null, self::BASE_URL );
		return new StagePosters( new Pictures( $client ), $this->store, new WcLogger(), null );
	}

	/**
	 * A side opening on `$view` of its blank, its front first in the strip.
	 *
	 * @param string $view The view it opens on.
	 */
	private static function layout( string $view = 'view_front' ): SideLayout {
		return new SideLayout(
			array( 3, 4 ),
			array(
				array(
					'id'        => 'view_front',
					'label'     => 'Front',
					'thumbnail' => '',
				),
				array(
					'id'        => 'view_back',
					'label'     => 'Back',
					'thumbnail' => '',
				),
				array(
					'id'        => 'design',
					'label'     => '',
					'thumbnail' => '',
				),
			),
			array(),
			array(),
			opening_view: $view
		);
	}

	/**
	 * An 8×8 PNG of one colour.
	 *
	 * @param int $rgb The colour, as `0xRRGGBB`.
	 */
	private static function png( int $rgb ): string {
		$image = imagecreatetruecolor( self::PNG_SIDE, self::PNG_SIDE );
		imagefill( $image, 0, 0, $rgb );
		ob_start();
		imagepng( $image );
		return (string) ob_get_clean();
	}

	/**
	 * The query for a picture's pending job.
	 *
	 * @param array<int, string> $args The job's arguments.
	 * @phpstan-param list<string> $args
	 * @return array<string, mixed>
	 */
	private static function job( array $args ): array {
		return array(
			'hook'   => StagePosters::ACTION,
			'args'   => $args,
			'group'  => StagePosters::GROUP,
			'status' => \ActionScheduler_Store::STATUS_PENDING,
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
