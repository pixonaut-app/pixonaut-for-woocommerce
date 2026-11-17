<?php
/**
 * Tells Pixonaut how much of the shop's storage its pictures take.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use Closure;
use Pixonaut\WooCommerce\Connection\ConnectionStore;
use Pixonaut\WooCommerce\Schema;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Settings\SettingsPage;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\ErrorCode;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Pixonaut as Sdk;
use Pixonaut\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use WC_Product;

/**
 * Pixonaut cannot see the shop's media library, so the plugin tells it
 * what its pictures take there (`PictureStorage`): the totals, the free
 * space (`MediaSpace`), and per product its pictures, how many of them
 * only its variations show, and whether it draws one for every variation
 * (`VariationPictures`). The studio shows it beside the workspace's own
 * storage, never in its quota. Each report replaces the last one.
 *
 * It is sent `DELAY_SECONDS` after a picture job, however many run
 * meanwhile, and once a day, while the store is connected.
 */
final class PictureReport {

	/**
	 * Action Scheduler hook sending the report: once after picture jobs,
	 * with no arguments, and daily, with `DAILY`.
	 */
	public const ACTION = 'pixonaut_wc_catalogue_picture_report';

	public const GROUP = Schema::ACTION_GROUP;

	/**
	 * The daily report's argument, so it is never mistaken for the one
	 * after picture jobs.
	 */
	public const DAILY = 'daily';

	/**
	 * How long after a picture job the report is sent, so a run of jobs
	 * sends one: five minutes (`MINUTE_IN_SECONDS` is not loaded where a
	 * class constant is read in the unit suite).
	 */
	public const DELAY_SECONDS = 5 * 60;

	/**
	 * Products the report lists at most, the largest first, as Pixonaut
	 * takes them.
	 */
	public const MAX_PRODUCTS = 1000;

	/**
	 * The longest product name Pixonaut takes.
	 */
	public const MAX_NAME_LENGTH = 200;

	private const OPERATION = 'connectedStore.reportPictureStorage';

	/**
	 * The status of a Pixonaut that has no route for the report yet, or no
	 * connection for the store's key: the next report tries again.
	 */
	private const NOT_TAKEN = 404;

	/**
	 * The admin anchor of the setting on the settings page and in a product's Pixonaut tab.
	 */
	public const SETTING_ANCHOR = 'pixonaut-variation-pictures';

	/**
	 * Arguments of the picture job this follows: the product id.
	 */
	private const JOB_ARGS = 1;

	/**
	 * After the picture job's own work.
	 */
	private const AFTER_JOB_PRIORITY = 20;

	private readonly Closure $clock;

	/**
	 * @param ConnectionStore $store    Whether the store is connected.
	 * @param Sdk             $api      Sends the report.
	 * @param Settings        $settings The store-wide setting.
	 * @param PictureStorage  $storage  Counts the pictures.
	 * @param MediaSpace      $space    Reads the free space.
	 * @param LoggerInterface $logger   Logs a report Pixonaut refused.
	 * @param Closure|null    $clock    `fn(): int` Unix time; `time()` when null.
	 */
	public function __construct(
		private readonly ConnectionStore $store,
		private readonly Sdk $api,
		private readonly Settings $settings,
		private readonly PictureStorage $storage,
		private readonly MediaSpace $space,
		private readonly LoggerInterface $logger,
		?Closure $clock = null,
	) {
		$this->clock = $clock ?? static fn (): int => time();
	}

	public function register(): void {
		add_action( self::ACTION, array( $this, 'send' ), 10, 0 );
		add_action( ProductPictures::ACTION, array( $this, 'soon' ), self::AFTER_JOB_PRIORITY, self::JOB_ARGS );
		add_action( 'action_scheduler_init', array( $this, 'schedule' ), 10, 0 );
		add_action( 'pixonaut_wc_connection_disconnected', array( $this, 'forget' ), 10, 0 );
	}

	/**
	 * Keeps the daily report scheduled while the store is connected.
	 */
	public function schedule(): void {
		if ( null !== $this->store->load() && ! as_has_scheduled_action( self::ACTION, array( self::DAILY ), self::GROUP ) ) {
			as_schedule_recurring_action( ( $this->clock )() + DAY_IN_SECONDS, DAY_IN_SECONDS, self::ACTION, array( self::DAILY ), self::GROUP );
		}
	}

	/**
	 * Sends the report `DELAY_SECONDS` on, unless one is already waiting:
	 * a picture job ran.
	 */
	public function soon(): void {
		if ( null === $this->store->load() || ! did_action( 'action_scheduler_init' ) || as_has_scheduled_action( self::ACTION, array(), self::GROUP ) ) {
			return;
		}
		as_schedule_single_action( ( $this->clock )() + self::DELAY_SECONDS, self::ACTION, array(), self::GROUP );
	}

	/**
	 * Stops reporting: the store disconnected, and Pixonaut dropped its report.
	 */
	public function forget(): void {
		as_unschedule_all_actions( self::ACTION, array(), self::GROUP );
		as_unschedule_all_actions( self::ACTION, array( self::DAILY ), self::GROUP );
		delete_option( Schema::PICTURE_REPORT_SENT_OPTION );
	}

	/**
	 * Sends the report. Pixonaut slowing the store down sends it again
	 * later; a `404` (an api without the route yet) is noted quietly; any
	 * other refusal is logged as a warning. The next report tries again.
	 */
	public function send(): void {
		if ( null === $this->store->load() ) {
			return;
		}
		try {
			$this->api->call( self::OPERATION, array(), array(), $this->body() );
			update_option( Schema::PICTURE_REPORT_SENT_OPTION, ( $this->clock )(), false );
		} catch ( ApiException $e ) {
			if ( ErrorCode::RATE_LIMITED === $e->errorCode ) {
				as_schedule_single_action( ( $this->clock )() + max( 1, $e->retryAfter ?? self::DELAY_SECONDS ), self::ACTION, array(), self::GROUP );
				return;
			}
			if ( self::NOT_TAKEN === $e->status ) {
				// A Pixonaut that does not take the report yet: the next one tries again, and nothing else needs to know.
				$this->logger->info( 'Pixonaut does not take the report of the pictures the shop keeps yet; it is sent again later.', array( 'status' => $e->status ) );
				return;
			}
			$this->logger->warning(
				sprintf( 'Pixonaut did not take the report of the pictures the shop keeps: %s', $e->getMessage() ),
				array(
					'status' => $e->status,
					'code'   => $e->errorCode,
				)
			);
		}
	}

	/**
	 * The report, as Pixonaut takes it.
	 *
	 * @return array{pictureCount: int, pictureBytes: int, variationPictureCount: int, variationPictureBytes: int, freeBytes: int|null, variationPicturesOn: bool, links: array{variationSetting: string, cleanUp: string}, productCount: int, products: list<array{storeProductId: string, name: string, pictureCount: int, pictureBytes: int, variationPictureCount: int, variationPictureBytes: int, variationPicturesOn: bool, editUrl: string}>}
	 */
	public function body(): array {
		$totals = $this->storage->totals();
		$sizes  = $totals['products'];
		// The largest first, so a cut keeps those that take the most.
		uksort( $sizes, static fn ( int $a, int $b ): int => array( $sizes[ $b ]['bytes'], $a ) <=> array( $sizes[ $a ]['bytes'], $b ) );
		$products = array();
		foreach ( $sizes as $product_id => $own ) {
			$product = wc_get_product( $product_id );
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			$products[] = array(
				'storeProductId'        => (string) $product_id,
				'name'                  => mb_substr( $product->get_name(), 0, self::MAX_NAME_LENGTH ),
				'pictureCount'          => $own['pictures'],
				'pictureBytes'          => $own['bytes'],
				'variationPictureCount' => $own['variation_pictures'],
				'variationPictureBytes' => $own['variation_bytes'],
				'variationPicturesOn'   => VariationPictures::on_for( $product, $this->settings ),
				'editUrl'               => self::product_url( $product_id ),
			);
			if ( self::MAX_PRODUCTS === count( $products ) ) {
				break;
			}
		}
		$settings = self::settings_url();
		return array(
			'pictureCount'          => $totals['pictures'],
			'pictureBytes'          => $totals['bytes'],
			'variationPictureCount' => $totals['variation_pictures'],
			'variationPictureBytes' => $totals['variation_bytes'],
			'freeBytes'             => $this->space->free(),
			'variationPicturesOn'   => VariationPictures::store_wide( $this->settings ),
			// Turning it off deletes the variation pictures nothing else uses: the clean-up is the setting.
			'links'                 => array(
				'variationSetting' => $settings,
				'cleanUp'          => $settings,
			),
			// Those past the cap included.
			'productCount'          => count( $totals['products'] ),
			'products'              => $products,
		);
	}

	/**
	 * The settings page, at the store-wide setting.
	 */
	public static function settings_url(): string {
		return SettingsPage::url( SettingsPage::STOREFRONT ) . '#' . self::SETTING_ANCHOR;
	}

	/**
	 * A product's edit screen, at its setting; built here, as a job runs as nobody who may edit it.
	 *
	 * @param int $product_id A product.
	 */
	public static function product_url( int $product_id ): string {
		return admin_url( sprintf( 'post.php?post=%d&action=edit', $product_id ) ) . '#' . self::SETTING_ANCHOR;
	}
}
