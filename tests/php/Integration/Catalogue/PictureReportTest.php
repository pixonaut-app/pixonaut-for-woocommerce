<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Catalogue;

use Pixonaut\WooCommerce\Catalogue\MediaSpace;
use Pixonaut\WooCommerce\Catalogue\PictureReport;
use Pixonaut\WooCommerce\Catalogue\PictureStorage;
use Pixonaut\WooCommerce\Catalogue\ProductPictures;
use Pixonaut\WooCommerce\Catalogue\SharedPictures;
use Pixonaut\WooCommerce\Catalogue\VariationPictures;
use Pixonaut\WooCommerce\Schema;
use Pixonaut\WooCommerce\Tests\Support\ActionSchedulerRunner;
use Pixonaut\WooCommerce\Tests\Support\RecordingLogger;
use WC_Product;
use WP_UnitTestCase;

/**
 * The report of the pictures the shop keeps, sent to Pixonaut in the shape
 * its api takes (checked against the spec and its overlay): totals, free
 * space, links to the setting, and each product largest first.
 */
final class PictureReportTest extends WP_UnitTestCase {

	use PicturedProducts;

	/**
	 * Bytes of each kept picture's file; it has no image sizes.
	 */
	private const PICTURE_BYTES = 1000;

	/**
	 * Free bytes where the shop keeps its media.
	 */
	private const FREE = 5 * GB_IN_BYTES;

	/**
	 * Seconds Pixonaut asks the store to wait.
	 */
	private const RETRY_AFTER = 120;

	private const NOW = 1800000000;

	public function set_up(): void {
		parent::set_up();
		$this->set_up_pictures();
	}

	public function tear_down(): void {
		$this->tear_down_pictures();
		delete_option( Schema::PICTURE_REPORT_SENT_OPTION );
		parent::tear_down();
	}

	public function test_the_report_holds_the_totals_and_each_product_largest_first(): void {
		$small = $this->sign();
		$large = $this->sign();
		VariationPictures::choose( $large, VariationPictures::ON );
		$children = $large->get_children();
		$this->keep( array( $small->get_id(), $small->get_children()[0] ) );
		$shared = $this->keep( array( $large->get_id() ) );
		$this->keep( array( $children[0] ) );
		$this->keep( array( $children[1], $children[2] ) );
		add_post_meta( $shared, SharedPictures::PICTURE_OF_META, $children[0] );

		$this->report()->send();

		$this->assertCount( 1, $this->recorder->requests );
		$request = $this->recorder->requests[0];
		$this->assertSame( 'PUT', $request['method'] );
		$this->assertStringEndsWith( '/v1/connect/picture-storage', $request['url'] );
		$body = json_decode( (string) $request['body'], true );
		$this->assertSame(
			array( 'pictureCount', 'pictureBytes', 'variationPictureCount', 'variationPictureBytes', 'freeBytes', 'variationPicturesOn', 'links', 'productCount', 'products' ),
			array_keys( $body ),
			'only the fields the api takes'
		);
		$this->assertSame( 4, $body['pictureCount'] );
		$this->assertSame( 2, $body['productCount'] );
		$this->assertSame( 4 * self::PICTURE_BYTES, $body['pictureBytes'] );
		$this->assertSame( 2, $body['variationPictureCount'], 'only variations show them' );
		$this->assertSame( 2 * self::PICTURE_BYTES, $body['variationPictureBytes'] );
		$this->assertSame( self::FREE, $body['freeBytes'] );
		$this->assertFalse( $body['variationPicturesOn'] );
		$this->assertStringContainsString( '#' . PictureReport::SETTING_ANCHOR, $body['links']['variationSetting'] );
		$this->assertSame( $body['links']['variationSetting'], $body['links']['cleanUp'], 'turning it off is the clean-up' );
		$this->assertSame(
			array(
				array(
					'storeProductId'        => (string) $large->get_id(),
					'name'                  => 'Sign',
					'pictureCount'          => 3,
					'pictureBytes'          => 3 * self::PICTURE_BYTES,
					'variationPictureCount' => 2,
					'variationPictureBytes' => 2 * self::PICTURE_BYTES,
					'variationPicturesOn'   => true,
					'editUrl'               => PictureReport::product_url( $large->get_id() ),
				),
				array(
					'storeProductId'        => (string) $small->get_id(),
					'name'                  => 'Sign',
					'pictureCount'          => 1,
					'pictureBytes'          => self::PICTURE_BYTES,
					'variationPictureCount' => 0,
					'variationPictureBytes' => 0,
					'variationPicturesOn'   => false,
					'editUrl'               => PictureReport::product_url( $small->get_id() ),
				),
			),
			$body['products']
		);
		$this->assertSame( self::NOW, get_option( Schema::PICTURE_REPORT_SENT_OPTION ) );
	}

	public function test_a_long_product_name_is_cut_to_what_pixonaut_takes(): void {
		$sign = $this->sign();
		$sign->set_name( str_repeat( 'Å', PictureReport::MAX_NAME_LENGTH + 1 ) );
		$sign->save();
		$this->keep( array( $sign->get_id() ) );

		$this->report()->send();

		$body = json_decode( (string) $this->recorder->requests[0]['body'], true );
		$this->assertSame( PictureReport::MAX_NAME_LENGTH, mb_strlen( $body['products'][0]['name'] ) );
	}

	public function test_admin_links_off_the_shops_host_are_left_out(): void {
		$this->keep( array( $this->sign()->get_id() ) );
		$admin = static fn (): string => 'https://admin.example.net';
		add_filter( 'option_siteurl', $admin );

		$this->report()->send();

		remove_filter( 'option_siteurl', $admin );
		$body = json_decode( (string) $this->recorder->requests[0]['body'], true );
		$this->assertNull( $body['links']['variationSetting'] );
		$this->assertNull( $body['links']['cleanUp'] );
		$this->assertNull( $body['products'][0]['editUrl'] );
	}

	public function test_a_report_past_what_pixonaut_takes_drops_the_smallest_products(): void {
		$large = $this->sign();
		$large->set_name( str_repeat( '🖼', PictureReport::MAX_NAME_LENGTH ) );
		$large->save();
		$this->keep( array( $large->get_id() ) );
		$this->keep( array( $large->get_id() ) );
		$this->keep( array( $this->sign()->get_id() ) );
		$this->recorder->stop();
		$body  = $this->report()->body();
		$whole = strlen( (string) wp_json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

		$fitted = PictureReport::fitted( $body, $whole - 1 );

		$this->assertSame( array( (string) $large->get_id() ), array_column( $fitted['products'], 'storeProductId' ), 'the smallest dropped' );
		$this->assertSame( 2, $fitted['productCount'] );
		$this->assertSame( $body, PictureReport::fitted( $body, $whole ) );
	}

	public function test_a_shop_keeping_none_reports_nothing_held(): void {
		$this->report()->send();

		$body = json_decode( (string) $this->recorder->requests[0]['body'], true );
		$this->assertSame( 0, $body['pictureCount'] );
		$this->assertSame( array(), $body['products'] );
	}

	public function test_a_store_not_connected_sends_nothing(): void {
		$this->plugin->connection_store()->clear();

		$this->report()->send();

		$this->assertSame( array(), $this->recorder->requests );
	}

	public function test_slowed_down_it_is_sent_again_later(): void {
		$this->recorder->json( 429, array( 'error' => 'rate_limited' ), array( 'Retry-After' => (string) self::RETRY_AFTER ) );
		as_unschedule_all_actions( PictureReport::ACTION );

		$this->report()->send();

		$this->assertSame( array( self::NOW + self::RETRY_AFTER ), ActionSchedulerRunner::due_at( PictureReport::ACTION ) );
		$this->assertFalse( get_option( Schema::PICTURE_REPORT_SENT_OPTION ) );
	}

	public function test_a_pixonaut_without_the_route_yet_is_noted_quietly_and_waits_for_the_next_report(): void {
		$this->recorder->json( 404, array( 'error' => 'not_found' ) );
		as_unschedule_all_actions( PictureReport::ACTION );
		$logger = new RecordingLogger();

		$this->report( $logger )->send();

		$this->assertSame( array( 'info' ), array_column( $logger->entries, 'level' ) );
		$this->assertSame( array(), ActionSchedulerRunner::pending( PictureReport::ACTION ) );
		$this->assertFalse( get_option( Schema::PICTURE_REPORT_SENT_OPTION ) );
	}

	public function test_another_refusal_is_logged_and_waits_for_the_next_report(): void {
		$this->recorder->json( 400, array( 'error' => 'invalid_input' ) );
		as_unschedule_all_actions( PictureReport::ACTION );
		$logger = new RecordingLogger();

		$this->report( $logger )->send();

		$this->assertSame( array( 'warning' ), array_column( $logger->entries, 'level' ) );
		$this->assertSame( array(), ActionSchedulerRunner::pending( PictureReport::ACTION ) );
	}

	public function test_picture_jobs_send_one_report_a_while_after(): void {
		as_unschedule_all_actions( PictureReport::ACTION );
		$report = $this->report();

		$report->soon();
		$report->soon();

		$this->assertSame( array( self::NOW + PictureReport::DELAY_SECONDS ), ActionSchedulerRunner::due_at( PictureReport::ACTION ) );
	}

	public function test_a_connected_store_reports_daily_until_it_disconnects(): void {
		as_unschedule_all_actions( PictureReport::ACTION );
		$report = $this->report();

		$report->schedule();
		$report->schedule();
		$this->assertTrue( as_has_scheduled_action( PictureReport::ACTION, array( PictureReport::DAILY ) ) );
		$this->assertCount( 1, ActionSchedulerRunner::pending( PictureReport::ACTION ) );

		$report->forget();
		$this->assertSame( array(), ActionSchedulerRunner::pending( PictureReport::ACTION ) );
	}

	public function test_a_picture_job_queues_the_report(): void {
		as_unschedule_all_actions( PictureReport::ACTION );

		do_action( ProductPictures::ACTION, 0 );

		$this->assertCount( 1, ActionSchedulerRunner::pending( PictureReport::ACTION ) );
	}

	/**
	 * The report, as now, the shop's free space `FREE`.
	 *
	 * @param RecordingLogger|null $logger Where refusals are logged.
	 */
	private function report( ?RecordingLogger $logger = null ): PictureReport {
		$this->recorder->empty_body( 204 );
		return new PictureReport( $this->plugin->connection_store(), $this->plugin->sdk(), $this->plugin->settings(), new PictureStorage(), new MediaSpace( static fn (): int => self::FREE ), $logger ?? new RecordingLogger(), static fn (): int => self::NOW );
	}

	/**
	 * A picture the products and variations show, of `PICTURE_BYTES`.
	 *
	 * @param int[] $owners Products and variations.
	 * @phpstan-param list<int> $owners
	 * @return int The picture.
	 */
	private function keep( array $owners ): int {
		$picture = $this->image();
		foreach ( $owners as $owner ) {
			add_post_meta( $picture, SharedPictures::PICTURE_OF_META, $owner );
		}
		wp_update_attachment_metadata( $picture, array( 'filesize' => self::PICTURE_BYTES ) );
		return $picture;
	}
}
