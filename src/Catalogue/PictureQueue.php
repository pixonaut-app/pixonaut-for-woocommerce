<?php
/**
 * Queues the drawing of a product's Pixonaut picture when it is due.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use ActionScheduler_Action;
use ActionScheduler_Store;
use Pixonaut\WooCommerce\Schema;
use Pixonaut\WooCommerce\Settings\Settings;
use WC_Cache_Helper;
use WC_Product;

/**
 * Queues `ProductPictures`' job for a product or variation whose picture
 * depends on something that changed: its configuration, its variant, its
 * image, its showcase sets, the view and photo chosen or its generation (each a post meta, written through the meta API or directly, which
 * clears the post's cache), a variable product's when one of its variations
 * changed, its picture deleted from the media library, and
 * every configured product when the setting is turned on or off, when every
 * picture is drawn again, and after the upgrade that made pictures product
 * images. A product is queued only when the job has something to do for it.
 */
final class PictureQueue {

	/**
	 * Action Scheduler hook of the job queueing a page of configured
	 * products, with the product id the page starts after.
	 */
	public const QUEUE_ACTION = 'pixonaut_wc_catalogue_pictures_queue';

	/**
	 * Products one queueing job pages through.
	 */
	public const QUEUE_PAGE = 100;

	/**
	 * Action Scheduler hook of the job checking a variable product's
	 * variations, which share its configuration, with the product id.
	 */
	public const VARIATIONS_ACTION = 'pixonaut_wc_catalogue_pictures_variations';

	/**
	 * Variations one variations job queues drawings of; the rest wait for
	 * the next page (`queue_variations()`).
	 */
	public const VARIATIONS_PAGE = 20;

	/**
	 * Variations one variations job reads at most, however few of them are
	 * due, so a product of many variations drawn already is read in pages too.
	 */
	public const VARIATIONS_EXAMINED = 5 * self::VARIATIONS_PAGE;

	/**
	 * How long the next page of a product's variations waits, so other
	 * products' drawings queued meanwhile run first.
	 */
	public const VARIATIONS_PAGE_SECONDS = 60;

	/**
	 * Arguments of the queueing job: where it starts, and the blank whose
	 * products it pages through when it is for one.
	 */
	private const QUEUE_ARGS = 2;

	/**
	 * Arguments of the variations job: the product, and where it starts.
	 */
	private const VARIATIONS_ARGS = 2;

	public const BACKFILL_OPTION = Schema::PICTURES_BACKFILL_OPTION;

	/**
	 * BACKFILL_OPTION's value while every product waits to be queued.
	 */
	public const BACKFILL_PENDING = '1';

	/**
	 * The post meta a product's picture depends on: its configuration, its
	 * variant, its image, its showcase sets, the view and photo chosen, its
	 * generation, and its card rows, with which its variations are drawn
	 * flat too.
	 */
	private const WATCHED_META = array( ProductConfig::META_KEY, ProductVariant::META_KEY, PictureImage::THUMBNAIL_META, ShowcaseImages::META_KEY, PictureView::META_KEY, ProductPictures::PRODUCT_GENERATION_META, SplitCards::META, CardPhotos::META_KEY, VariationPictures::META_KEY );

	/**
	 * The arguments the post meta actions pass.
	 */
	private const META_ARGS = 3;

	/**
	 * The arguments `update_option_{$option}` and `add_option_{$option}` pass.
	 */
	private const OPTION_ARGS = 2;

	/**
	 * Runs before the drawing job, so a change it makes can queue the product again.
	 */
	private const BEFORE_JOB_PRIORITY = 1;

	/**
	 * Products queued and not drawn since, in this request, by id.
	 *
	 * @var array<int, true>
	 */
	private array $queued = array();

	/**
	 * Variable products one of whose variations changed in this request, by id.
	 *
	 * @var array<int, true>
	 */
	private array $parents = array();

	/**
	 * @param Settings      $settings Says whether pictures are product images.
	 * @param \Closure|null $clock    `fn(): int` Unix time; `time()` when null.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly ?\Closure $clock = null,
	) {
	}

	public function register(): void {
		add_action( ProductPictures::ACTION, array( $this, 'running' ), self::BEFORE_JOB_PRIORITY );
		add_action( self::QUEUE_ACTION, array( $this, 'queue_page' ), 10, self::QUEUE_ARGS );
		add_action( self::VARIATIONS_ACTION, array( $this, 'queue_variations' ), 10, self::VARIATIONS_ARGS );
		add_action( ProductPictures::RETRY_ACTION, array( $this, 'queue' ) );
		add_action( 'delete_attachment', array( $this, 'deleting_attachment' ) );
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $action ) {
			add_action( $action, array( $this, 'meta_changed' ), 10, self::META_ARGS );
		}
		// A direct write, as `ProductConfig::replace_on()`, clears the post's cache.
		add_action( 'clean_post_cache', array( $this, 'changed' ) );
		add_action( 'shutdown', array( $this, 'parents_changed' ) );
		add_action( 'update_option_' . Settings::OPTION, array( $this, 'settings_changed' ), 10, self::OPTION_ARGS );
		add_action( 'add_option_' . Settings::OPTION, array( $this, 'settings_added' ), 10, self::OPTION_ARGS );
		add_action( 'action_scheduler_init', array( $this, 'queue_backfill' ) );
	}

	/**
	 * Forgets that the product the job is about to draw was queued.
	 *
	 * @param mixed $product_id The product or variation.
	 */
	public function running( mixed $product_id ): void {
		unset( $this->queued[ (int) $product_id ] );
	}

	/**
	 * Queues the products whose picture depends on a meta that changed.
	 *
	 * @param mixed $meta_id   The meta row, or rows; unused.
	 * @param mixed $object_id The post.
	 * @param mixed $meta_key  The meta's key.
	 */
	public function meta_changed( mixed $meta_id, mixed $object_id, mixed $meta_key ): void {
		unset( $meta_id );
		if ( in_array( $meta_key, self::WATCHED_META, true ) ) {
			$this->changed( $object_id );
		}
	}

	/**
	 * Queues a product or variation whose picture is due, and the check of
	 * a configured variable product's variations, which share its
	 * configuration, in a job of its own.
	 *
	 * @param mixed $post_id The post that changed.
	 */
	public function changed( mixed $post_id ): void {
		// A move changes no picture, and is no time to try one that failed.
		if ( AttributeMove::writing() ) {
			return;
		}
		$post_id = (int) $post_id;
		if ( ! in_array( get_post_type( $post_id ), array( 'product', 'product_variation' ), true ) ) {
			return;
		}
		$product = self::fresh( $post_id );
		if ( null === $product ) {
			return;
		}
		$this->queue_if_due( $product );
		if ( $product->is_type( 'variation' ) ) {
			// Its product's picture may be drawn as it: checked once, after every change of the request.
			$this->parents[ $product->get_parent_id() ] = true;
		}
		if ( $product->is_type( 'variable' ) && null !== ProductConfig::document_on( $product ) && did_action( 'action_scheduler_init' ) && ! self::waiting( self::VARIATIONS_ACTION, $post_id ) ) {
			as_enqueue_async_action( self::VARIATIONS_ACTION, array( $post_id ), ProductPictures::GROUP, false, ProductPictures::PRIORITY );
		}
	}

	/**
	 * Queues the variable products whose picture is due after their
	 * variations changed: the one a product is drawn as (`PicturedVariation`)
	 * may be another now, or set up otherwise.
	 */
	public function parents_changed(): void {
		$parents       = array_keys( $this->parents );
		$this->parents = array();
		foreach ( $parents as $parent_id ) {
			$parent = self::fresh( $parent_id );
			if ( null !== $parent ) {
				$this->queue_if_due( $parent );
			}
		}
	}

	/**
	 * The variations job: queues each variation of the product whose picture
	 * is due, `VARIATIONS_PAGE` at a time. The rest are checked by the same
	 * job `VARIATIONS_PAGE_SECONDS` later, from where this one stopped, so
	 * a product of many variations (each drawn for every variation,
	 * `VariationPictures`) leaves room for other products' pictures between
	 * its pages rather than holding every runner.
	 *
	 * @param mixed $product_id A variable product.
	 * @param mixed $from       The position among its variations to start at; 0 for the first.
	 */
	public function queue_variations( mixed $product_id, mixed $from = 0 ): void {
		$product  = wc_get_product( (int) $product_id );
		$children = $product instanceof WC_Product ? array_values( $product->get_children() ) : array();
		$queued   = 0;
		$count    = count( $children );
		$first    = max( 0, (int) $from );
		for ( $index = $first; $index < $count; ++$index ) {
			if ( self::VARIATIONS_PAGE === $queued || self::VARIATIONS_EXAMINED === $index - $first ) {
				as_schedule_single_action( $this->now() + self::VARIATIONS_PAGE_SECONDS, self::VARIATIONS_ACTION, array( (int) $product_id, $index ), ProductPictures::GROUP, false, ProductPictures::PRIORITY );
				return;
			}
			$child = wc_get_product( $children[ $index ] );
			if ( $child instanceof WC_Product && $this->is_due( $child ) ) {
				$this->queue( $child->get_id() );
				++$queued;
			}
		}
	}

	/**
	 * Queues each variation of the product whose picture is due now, as the
	 * store manager waits for them, and drops the variations job that would
	 * have.
	 *
	 * @param int $product_id A variable product.
	 */
	public function queue_variations_now( int $product_id ): void {
		$this->queue_variations( $product_id );
		as_unschedule_all_actions( self::VARIATIONS_ACTION, array( $product_id ), ProductPictures::GROUP );
	}

	/**
	 * The product or variation as stored now. A direct write, as
	 * `replace_on()`, may not have cleared WooCommerce's copy of its meta
	 * yet, nor the request's copy that an earlier read left.
	 *
	 * @param int $product_id A product or variation.
	 */
	public static function fresh( int $product_id ): ?WC_Product {
		$parent_id = wp_get_post_parent_id( $product_id );
		foreach ( array_filter( array( $product_id, (int) $parent_id ) ) as $id ) {
			wp_cache_delete( (string) $id, 'post_meta' );
			WC_Cache_Helper::invalidate_cache_group( 'object_' . $id );
		}
		$product = wc_get_product( $product_id );
		return $product instanceof WC_Product ? $product : null;
	}

	/**
	 * Queues the drawing of the product's picture, at a time or at once,
	 * unless a drawing waits to start. One that is running may have read
	 * the product before it changed, so it does not count.
	 *
	 * @param int $product_id A product or variation.
	 * @param int $at         When, as Unix time; 0 for at once.
	 */
	public static function schedule( int $product_id, int $at = 0 ): void {
		if ( self::waiting( ProductPictures::ACTION, $product_id ) ) {
			return;
		}
		if ( 0 === $at ) {
			as_enqueue_async_action( ProductPictures::ACTION, array( $product_id ), ProductPictures::GROUP, false, ProductPictures::PRIORITY );
		} else {
			as_schedule_single_action( $at, ProductPictures::ACTION, array( $product_id ), ProductPictures::GROUP, false, ProductPictures::PRIORITY );
		}
	}

	/**
	 * Moves a drawing of the product that waits for a time, as one does
	 * while another holds its lock, to start at once: the drawing that held
	 * the lock is done, and the product may have changed after it read it.
	 *
	 * @param int $product_id A product or variation.
	 * @param int $now        The time, as Unix time.
	 */
	public static function resume( int $product_id, int $now ): void {
		$later = as_get_scheduled_actions(
			array(
				'hook'         => ProductPictures::ACTION,
				'args'         => array( $product_id ),
				'group'        => ProductPictures::GROUP,
				'status'       => ActionScheduler_Store::STATUS_PENDING,
				'date'         => gmdate( 'Y-m-d H:i:s', $now ),
				'date_compare' => '>',
				'per_page'     => 1,
			),
			'ids'
		);
		if ( array() === $later ) {
			return;
		}
		as_unschedule_all_actions( ProductPictures::ACTION, array( $product_id ), ProductPictures::GROUP );
		self::schedule( $product_id );
	}

	/**
	 * Queues each product and variation whose picture is being deleted from
	 * the media library, so it is drawn again.
	 *
	 * @param mixed $attachment_id The attachment being deleted.
	 */
	public function deleting_attachment( mixed $attachment_id ): void {
		$attachment_id = (int) $attachment_id;
		foreach ( SharedPictures::owners( $attachment_id ) as $product_id ) {
			$product = wc_get_product( $product_id );
			// The job deletes a picture only once the product's record has moved on from it.
			if ( $product instanceof WC_Product && in_array( $attachment_id, ProductPictures::attachments( ProductPictures::stored( $product ) ), true ) ) {
				$this->queue( $product_id );
			}
		}
	}

	/**
	 * Checks every product when pictures as product images, or a picture
	 * for every variation, is turned on or off.
	 *
	 * @param mixed $old_value The settings before.
	 * @param mixed $value     The settings now.
	 */
	public function settings_changed( mixed $old_value, mixed $value ): void {
		if ( $this->enabled_in( $old_value, Settings::PRODUCT_PICTURES ) !== $this->enabled_in( $value, Settings::PRODUCT_PICTURES ) || $this->enabled_in( $old_value, Settings::VARIATION_PICTURES ) !== $this->enabled_in( $value, Settings::VARIATION_PICTURES ) ) {
			self::queue_all();
		}
	}

	/**
	 * As `settings_changed()`, when the settings are first stored.
	 *
	 * @param mixed $option The option's name; unused.
	 * @param mixed $value  The settings.
	 */
	public function settings_added( mixed $option, mixed $value ): void {
		unset( $option );
		$this->settings_changed( array(), $value );
	}

	/**
	 * Queues the check of every product asked for before Action Scheduler
	 * was set up, and only then forgets the ask.
	 */
	public function queue_backfill(): void {
		if ( self::BACKFILL_PENDING === get_option( self::BACKFILL_OPTION ) && self::queue_all() ) {
			delete_option( self::BACKFILL_OPTION );
		}
	}

	/**
	 * Queues the job checking every configured product, unless one waits to
	 * start; one already running may have passed a product. Asked before
	 * Action Scheduler is set up, it is asked again once it is.
	 *
	 * @return bool False when it could not be queued yet.
	 */
	public static function queue_all(): bool {
		if ( ! did_action( 'action_scheduler_init' ) ) {
			update_option( self::BACKFILL_OPTION, self::BACKFILL_PENDING, false );
			return false;
		}
		return self::waiting( self::QUEUE_ACTION, 0 ) || 0 !== as_enqueue_async_action( self::QUEUE_ACTION, array( 0 ), ProductPictures::GROUP, false, ProductPictures::PRIORITY );
	}

	/**
	 * Queues the job checking the products and variations using a blank,
	 * and the products of those variations, unless one waits to start.
	 *
	 * @param string $blank_id The blank.
	 */
	public static function queue_blank( string $blank_id ): void {
		if ( ! did_action( 'action_scheduler_init' ) ) {
			// Before Action Scheduler is set up, every product is checked once it is.
			self::queue_all();
			return;
		}
		if ( array() === self::waiting_jobs( self::QUEUE_ACTION, array( 0, $blank_id ) ) ) {
			as_enqueue_async_action( self::QUEUE_ACTION, array( 0, $blank_id ), ProductPictures::GROUP, false, ProductPictures::PRIORITY );
		}
	}

	/**
	 * Whether a drawing of the product, or of one of its variations, waits
	 * to start or is running, or the job checking its variations does. One
	 * query for every drawing, so a product's variations are not read.
	 *
	 * @param WC_Product $product A product or variation.
	 */
	public static function drawing( WC_Product $product ): bool {
		$active   = array( ActionScheduler_Store::STATUS_PENDING, ActionScheduler_Store::STATUS_RUNNING );
		$checking = as_get_scheduled_actions(
			array(
				'hook'     => self::VARIATIONS_ACTION,
				'group'    => ProductPictures::GROUP,
				'status'   => $active,
				'per_page' => -1,
			)
		);
		foreach ( $checking as $check ) {
			// Its first page, or a later one (`queue_variations()`).
			$args = $check instanceof ActionScheduler_Action ? $check->get_args() : array();
			if ( (int) ( $args[0] ?? 0 ) === $product->get_id() ) {
				return true;
			}
		}
		$drawings = as_get_scheduled_actions(
			array(
				'hook'     => ProductPictures::ACTION,
				'group'    => ProductPictures::GROUP,
				'status'   => $active,
				'per_page' => -1,
			)
		);
		$ids      = array_merge( array( $product->get_id() ), $product->get_children() );
		foreach ( $drawings as $drawing ) {
			$args = $drawing instanceof ActionScheduler_Action ? $drawing->get_args() : array();
			if ( in_array( (int) ( $args[0] ?? 0 ), $ids, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a job of the hook for the id waits to start.
	 *
	 * @param string $hook An Action Scheduler hook of the plugin's.
	 * @param int    $id   Its one argument.
	 */
	private static function waiting( string $hook, int $id ): bool {
		return array() !== self::waiting_jobs( $hook, array( $id ) );
	}

	/**
	 * The job of the hook with the arguments that waits to start, if any.
	 *
	 * @param string       $hook An Action Scheduler hook of the plugin's.
	 * @param array<mixed> $args Its arguments.
	 * @return array<int|string, mixed>
	 */
	private static function waiting_jobs( string $hook, array $args ): array {
		return as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'args'     => $args,
				'group'    => ProductPictures::GROUP,
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
			),
			'ids'
		);
	}

	/**
	 * The queueing job: queues each product of a page of configured
	 * products, or of the products and variations using a blank, and a
	 * variable product's variations, whose picture is due; then the job for
	 * the page after when this one was full.
	 *
	 * @param int|string $after    The product id the page starts after; 0 for the first.
	 * @param string     $blank_id Only the products and variations using this blank; '' for every configured product.
	 */
	public function queue_page( int|string $after = 0, string $blank_id = '' ): void {
		$ids = '' === $blank_id ? ProductIndex::configured( (int) $after, self::QUEUE_PAGE ) : ProductIndex::using_blank( $blank_id, (int) $after, self::QUEUE_PAGE );
		foreach ( $ids as $product_id ) {
			$this->changed( $product_id );
		}
		// A variation's product is checked after it (`parents_changed()`), once per page.
		$this->parents_changed();
		if ( self::QUEUE_PAGE === count( $ids ) ) {
			$next = '' === $blank_id ? array( $ids[ self::QUEUE_PAGE - 1 ] ) : array( $ids[ self::QUEUE_PAGE - 1 ], $blank_id );
			as_enqueue_async_action( self::QUEUE_ACTION, $next, ProductPictures::GROUP, false, ProductPictures::PRIORITY );
		}
	}

	/**
	 * Whether the job has something to do for the product: put back its
	 * image once it is to show no picture; else, unless the store manager's
	 * image is kept and it has no gallery pictures to draw or take out, draw the
	 * pictures when what they depend on changed, or when a drawing that
	 * failed, or whose attachment is gone, is due to be tried again, and make
	 * a drawn picture its image again.
	 *
	 * @param WC_Product $product A product or variation.
	 */
	public function is_due( WC_Product $product ): bool {
		$basis  = ProductPictures::basis( $product );
		$stored = ProductPictures::stored( $product );
		if ( ! ProductPictures::applies( $this->settings, $product ) || null === $basis ) {
			return array() !== ProductPictures::attachments( $stored ) || PictureImage::remembers( $product );
		}
		$own = PictureImage::is_own( $product );
		if ( $own && ! ProductPictures::has_gallery_work( $product, $stored ) ) {
			return false;
		}
		if ( $stored['basis'] !== $basis ) {
			return true;
		}
		$drawn     = PictureImage::is_picture( $stored['attachment'], $product );
		$retry_due = null === $stored['failed_at'] || $stored['failed_at'] + ProductPictures::RETRY_SECONDS <= $this->now();
		$failed    = null !== $stored['failed_at'];
		$missing   = ( ! $own && null !== $stored['source'] && ! $drawn ) || array() !== array_filter( $stored['gallery'], static fn ( array $slot ): bool => ! PictureImage::is_picture( $slot['attachment'], $product ) );
		$hidden    = ! $own && $drawn && PictureImage::current( $product ) !== $stored['attachment'];
		return ( ( $failed || $missing ) && $retry_due ) || $hidden;
	}

	/**
	 * Queues the drawing of the product's picture when the job has something
	 * to do for it (`is_due()`).
	 *
	 * @param WC_Product $product A product or variation.
	 */
	public function queue_if_due( WC_Product $product ): void {
		if ( $this->is_due( $product ) ) {
			$this->queue( $product->get_id() );
		}
	}

	/**
	 * Whether a checkbox setting is on in the settings.
	 *
	 * @param mixed  $settings The settings option's value.
	 * @param string $key      The setting: PRODUCT_PICTURES or VARIATION_PICTURES.
	 */
	private function enabled_in( mixed $settings, string $key ): bool {
		$stored = is_array( $settings ) ? $settings : array();
		return true === $this->settings->all()[ $key ]->resolve( $stored[ $key ] ?? null );
	}

	/**
	 * Queues the product's drawing once in this request; also the retry
	 * job's handler.
	 *
	 * @param mixed $product_id A product or variation.
	 */
	public function queue( mixed $product_id ): void {
		$product_id = (int) $product_id;
		// Action Scheduler takes no action before it is set up.
		if ( isset( $this->queued[ $product_id ] ) || ! did_action( 'action_scheduler_init' ) ) {
			return;
		}
		$this->queued[ $product_id ] = true;
		self::schedule( $product_id );
	}

	private function now(): int {
		return null === $this->clock ? time() : ( $this->clock )();
	}
}
