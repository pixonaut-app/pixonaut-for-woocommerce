<?php
/**
 * A personalised product's Pixonaut picture, in place of its own image.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Api\Catalogue;
use Pixonaut\WooCommerce\Api\WpHttpTransport;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\ErrorCode;
use Pixonaut\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Pixonaut\WooCommerce\Api\Pictures;
use Pixonaut\WooCommerce\Schema;
use Pixonaut\WooCommerce\Settings\Settings;
use WC_Product;

/**
 * While the store uses Pixonaut pictures as product images, a personalised
 * product's or variation's image is Pixonaut's picture of it, drawn from
 * what `PictureSources` says. The picture is a media-library attachment set
 * as the product's own image (`PictureImage`), so everything that reads the
 * image shows it: product blocks, the classic loop, the featured image, a
 * variation's image, the Store API, feeds, emails and the admin. A product
 * with several showcase sets chosen has a picture per set: the first is its
 * image, the others lead its gallery (`PictureGallery`). A set Pixonaut no
 * longer has is drawn with the template's defaults, and recorded as gone.
 * A picture is drawn once per source: a variation drawn from what another
 * of its product's family already has a picture of shows that picture
 * (`SharedPictures`).
 *
 * It is drawn in a job, never while a page waits on Pixonaut. A product is
 * queued when what its picture depends on changes (its configuration, its
 * variant, its image, its template's version as a re-check rolls it on),
 * when the store manager asks for its pictures to be drawn again
 * (`regenerate()`), and every product when the setting changes or every
 * picture is to be drawn again; it keeps the image it has until the job
 * ran. An image the store manager sets after the picture is kept, and no
 * picture is drawn for it while it is. A product no longer personalised, or
 * every product once the setting is off, gets back the image it had, and
 * its gallery without the pictures.
 */
final class ProductPictures {

	public const ACTION = 'pixonaut_wc_catalogue_picture';

	public const GROUP = Schema::ACTION_GROUP;

	public const META_KEY = Schema::PRODUCT_PICTURE_META;

	public const PICTURE_OF_META = Schema::PICTURE_OF_META;

	public const GENERATION_OPTION = Schema::PICTURE_GENERATION_OPTION;

	public const PRODUCT_GENERATION_META = Schema::PRODUCT_PICTURE_GENERATION_META;

	public const LOCK_OPTION_PREFIX = Schema::PICTURE_LOCK_OPTION_PREFIX;

	/**
	 * Requests a drawing makes at most: the mockups read, then for each
	 * showcase set its picture and, when Pixonaut no longer has the set, the
	 * picture again without it.
	 */
	private const MAX_REQUESTS = 1 + 2 * ShowcaseImages::MAX_IMAGES;

	/**
	 * How long a drawing holds its product's lock before another may take it
	 * over: each of its requests within the client's timeout.
	 */
	public const LOCK_SECONDS = self::MAX_REQUESTS * WpHttpTransport::TIMEOUT_SECONDS;

	/**
	 * The picture's long edge in pixels: WordPress draws the smaller sizes from it.
	 */
	public const SIZE = 1200;

	/**
	 * How long a picture Pixonaut failed to draw waits before it is tried again.
	 */
	public const RETRY_SECONDS = 60 * 60;

	/**
	 * Action Scheduler hook of a product's one retry after its drawing
	 * failed, with the product id; it queues the drawing.
	 */
	public const RETRY_ACTION = 'pixonaut_wc_catalogue_picture_retry';

	private readonly PictureSources $sources;

	/**
	 * The sources this job holds the lock of while it draws them, for its
	 * product's family, each lock's value by the source's key.
	 *
	 * @var array<string, string>
	 */
	private array $claims = array();

	/**
	 * @param Pictures        $pictures  Draws the picture.
	 * @param Catalogue       $catalogue Reads the blank's views.
	 * @param CatalogueCache  $cache    Keeps the blank's views between jobs.
	 * @param LoggerInterface $logger    Logs a picture Pixonaut could not draw.
	 * @param Settings        $settings  Says whether pictures are product images.
	 * @param \Closure|null   $clock     `fn(): int` Unix time; `time()` when null.
	 * @param \Closure|null   $sleep     `fn(int $seconds): void`, while another job draws the same source; `sleep()` when null.
	 */
	public function __construct(
		private readonly Pictures $pictures,
		Catalogue $catalogue,
		CatalogueCache $cache,
		private readonly LoggerInterface $logger,
		private readonly Settings $settings,
		private readonly ?\Closure $clock = null,
		private readonly ?\Closure $sleep = null,
	) {
		$this->sources = new PictureSources( $catalogue, $cache );
	}

	public function register(): void {
		add_action( self::ACTION, array( $this, 'refresh' ) );
		add_action( 'before_delete_post', array( $this, 'deleting' ) );
	}

	/**
	 * Whether Pixonaut pictures are the product images.
	 *
	 * @param Settings $settings The store's settings.
	 */
	public static function enabled( Settings $settings ): bool {
		return true === $settings->get( Settings::PRODUCT_PICTURES );
	}

	/**
	 * Whether the product's image is to be its picture: pictures are product
	 * images, and the product or variation is personalised.
	 *
	 * @param Settings   $settings The store's settings.
	 * @param WC_Product $product  A product or variation.
	 */
	public static function applies( Settings $settings, WC_Product $product ): bool {
		return self::enabled( $settings ) && null !== self::basis( $product ) && ProductConfig::config_for( $product )->personalise;
	}

	/**
	 * Draws every picture again: a blank's photo or print area can change
	 * under the same ids.
	 */
	public static function redraw_all(): void {
		update_option( self::GENERATION_OPTION, self::generation() + 1, false );
		PictureQueue::queue_all();
	}

	/**
	 * Draws the pictures of a product and of its variations again, from the
	 * template version and settings they are drawn with now, as when the
	 * store manager asks: moves the product's own generation, which the
	 * meta write queues (`PictureQueue`). The pictures it shows stay until
	 * the new ones are drawn.
	 *
	 * @param WC_Product $product A simple or variable product.
	 */
	public static function regenerate( WC_Product $product ): void {
		$product->update_meta_data( self::PRODUCT_GENERATION_META, (string) ( self::product_generation( $product ) + 1 ) );
		$product->save_meta_data();
	}

	/**
	 * Draws the product's picture again when what it is drawn from changed,
	 * makes it the product's image, and records it; puts back the image it
	 * had once it is no longer personalised or the setting is off. When
	 * Pixonaut cannot draw it, the product keeps the image it has, and it is
	 * tried again after `RETRY_SECONDS`.
	 *
	 * @param mixed $product_id The product or variation.
	 */
	public function refresh( mixed $product_id ): void {
		$product = wc_get_product( (int) $product_id );
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$held = $this->lock( $product->get_id() );
		if ( null === $held ) {
			// Another job is drawing it from what the product was before; this one runs after it.
			PictureQueue::schedule( $product->get_id(), $this->now() + self::LOCK_SECONDS );
			return;
		}
		try {
			$this->draw( $product );
		} finally {
			foreach ( $this->claims as $key => $claim ) {
				SharedPictures::unclaim( $product, (string) $key, $claim );
			}
			$this->claims = array();
			self::unlock( $product->get_id(), $held );
		}
	}

	/**
	 * @param WC_Product $product The product or variation, its lock held.
	 */
	private function draw( WC_Product $product ): void {
		$basis  = self::basis( $product );
		$stored = self::stored( $product );
		if ( ! self::applies( $this->settings, $product ) || null === $basis ) {
			// Forgotten, so personalising it or turning the setting on again draws it.
			self::release( $product, null, $stored );
			return;
		}
		$own = PictureImage::is_own( $product );
		if ( $own && ! self::has_gallery_work( $product, $stored ) ) {
			// The store manager's image is kept; no picture is drawn for it.
			return;
		}
		try {
			$sources = $this->sources->of( $product );
			if ( array() === $sources ) {
				self::release( $product, $basis, $stored );
				return;
			}
			$drawings = $this->drawings( $product, $sources, $stored, $own );
		} catch ( ApiException $e ) {
			$this->logger->warning(
				sprintf( 'Pixonaut could not draw the picture of product #%d: %s', $product->get_id(), $e->getMessage() ),
				array(
					'product_id' => $product->get_id(),
					'status'     => $e->status,
					'code'       => $e->errorCode,
				)
			);
			$this->failed( $product, $basis, $stored );
			return;
		}
		$fresh = array_filter( $drawings, static fn ( array $drawing ): bool => null !== $drawing['png'] || $drawing['shared'] );
		if ( array() !== $fresh && ! $this->still( $product, $basis, $own ) ) {
			// Changed while Pixonaut drew it: the job queued by that change draws it again.
			return;
		}
		$slots = array();
		foreach ( $drawings as $drawing ) {
			if ( $drawing['shared'] && ! SharedPictures::share( $drawing['attachment'], $product ) ) {
				// Its last product let it go meanwhile: drawn again at once, rather than shown missing.
				self::unattach( $product, $slots, $stored );
				PictureQueue::schedule( $product->get_id() );
				return;
			}
			$attachment = null === $drawing['png'] ? $drawing['attachment'] : SharedPictures::attach( $product, $drawing['png'], (string) $drawing['key'] );
			if ( 0 === $attachment && null !== $drawing['png'] ) {
				$this->logger->warning( sprintf( 'The picture of product #%d could not be saved to the media library.', $product->get_id() ), array( 'product_id' => $product->get_id() ) );
				self::unattach( $product, $slots, $stored );
				$this->failed( $product, $basis, $stored );
				return;
			}
			$slots[] = array(
				'source'     => $drawing['key'],
				'attachment' => $attachment,
			);
		}
		// `$sources` is never empty, so neither is `$slots`.
		$main    = array_shift( $slots ) ?? array(
			'source'     => null,
			'attachment' => 0,
		);
		$gone    = array_values( array_unique( array_filter( array_column( $drawings, 'gone' ) ) ) );
		$current = array(
			'basis'      => $basis,
			'source'     => $main['source'],
			'attachment' => $main['attachment'],
			'failed_at'  => null,
			'gallery'    => $slots,
			'gone'       => $gone,
		);
		self::store( $product, $current );
		if ( ! $own ) {
			PictureImage::show( $product, $main['attachment'] );
		}
		if ( ! $product->is_type( 'variation' ) ) {
			PictureGallery::show( $product, array_column( $slots, 'attachment' ) );
		}
		foreach ( array_diff( self::attachments( $stored ), self::attachments( $current ) ) as $earlier ) {
			SharedPictures::release( $earlier, $product );
		}
	}

	/**
	 * Lets go the pictures a drawing attached or shared before it stopped,
	 * keeping those the product had.
	 *
	 * @param WC_Product                                        $product A product or variation.
	 * @param list<array{source: string|null, attachment: int}> $slots   The pictures so far.
	 * @param array                                             $stored  Its recorded pictures.
	 * @phpstan-param array{basis: string|null, source: string|null, attachment: int, failed_at: int|null, gallery: list<array{source: string|null, attachment: int}>, gone: list<string>} $stored
	 */
	private static function unattach( WC_Product $product, array $slots, array $stored ): void {
		foreach ( array_diff( array_column( $slots, 'attachment' ), self::attachments( $stored ) ) as $attached ) {
			SharedPictures::release( $attached, $product );
		}
	}

	/**
	 * Each picture as it is to be: the one recorded when it was drawn from
	 * the same, else one its family has of the same (`shared`), else
	 * Pixonaut's PNG. A set Pixonaut no longer has is drawn with the
	 * template's defaults instead, and named in `gone`.
	 *
	 * @param WC_Product                                                                                                                                                           $product A product or variation.
	 * @param list<array{template: string, blank?: string, placement?: string, view?: string, variant?: string|null, photo?: string, showcase?: string}>                                           $sources What each is drawn from, its image's first.
	 * @param array{basis: string|null, source: string|null, attachment: int, failed_at: int|null, gallery: list<array{source: string|null, attachment: int}>, gone: list<string>} $stored  Its recorded pictures.
	 * @param bool                                                                                                                                                                 $own     Whether its image is the store manager's own, which is kept.
	 *
	 * @return list<array{key: string|null, attachment: int, png: string|null, gone: string|null, shared: bool}>
	 *
	 * @throws ApiException When Pixonaut could not draw one.
	 */
	private function drawings( WC_Product $product, array $sources, array $stored, bool $own ): array {
		$recorded = array_merge(
			array(
				array(
					'source'     => $stored['source'],
					'attachment' => $stored['attachment'],
				),
			),
			$stored['gallery']
		);
		$drawings = array();
		$wanted   = array();
		foreach ( $sources as $index => $source ) {
			$showcase = $source['showcase'] ?? null;
			$key      = md5( (string) wp_json_encode( self::drawn_under( $product, array( $source, self::generation() ) ) ) );
			$earlier  = $recorded[ $index ] ?? array(
				'source'     => null,
				'attachment' => 0,
			);
			if ( 0 === $index && $own ) {
				// The store manager's image stays; its record is kept as it was.
				$key = $earlier['source'];
			}
			$gone = null !== $showcase && in_array( $showcase, $stored['gone'], true ) ? $showcase : null;
			if ( ( 0 === $index && $own ) || ( $earlier['source'] === $key && PictureImage::is_picture( $earlier['attachment'], $product ) ) ) {
				$drawings[ $index ] = array(
					'key'        => $key,
					'attachment' => $earlier['attachment'],
					'png'        => null,
					'gone'       => $gone,
					'shared'     => false,
				);
				continue;
			}
			$shared             = $this->shared( $product, (string) $key );
			$drawings[ $index ] = array(
				'key'        => $key,
				'attachment' => $shared,
				'png'        => null,
				'gone'       => 0 === $shared ? null : $gone,
				'shared'     => 0 !== $shared,
			);
			if ( 0 === $shared ) {
				$wanted[ $index ] = $source;
			}
		}
		// Every picture to draw at once; a set Pixonaut no longer has, again without it.
		$pngs  = array();
		$again = array();
		foreach ( $this->pngs( $wanted, true ) as $index => $png ) {
			if ( is_string( $png ) ) {
				$pngs[ $index ] = $png;
				continue;
			}
			if ( null === ( $wanted[ $index ]['showcase'] ?? null ) || ErrorCode::SHOWCASE_NOT_FOUND !== $png->errorCode ) {
				throw $png;
			}
			$again[ $index ] = $wanted[ $index ];
		}
		foreach ( $this->pngs( $again, false ) as $index => $png ) {
			if ( ! is_string( $png ) ) {
				throw $png;
			}
			$pngs[ $index ] = $png;
		}
		$drawn = array();
		foreach ( $drawings as $index => $drawing ) {
			$drawn[] = isset( $pngs[ $index ] )
				? array(
					'key'        => $drawing['key'],
					'attachment' => 0,
					'png'        => $pngs[ $index ],
					'gone'       => $again[ $index ]['showcase'] ?? null,
					'shared'     => false,
				)
				: $drawing;
		}
		return $drawn;
	}

	/**
	 * A picture the product's family has of the source; 0 when none, and
	 * then this job holds the source's lock until it is done, so another
	 * job of the family wanting the same waits for its picture.
	 *
	 * @param WC_Product $product A product or variation.
	 * @param string     $key     What the picture is drawn from.
	 */
	private function shared( WC_Product $product, string $key ): int {
		$found = SharedPictures::find( $product, $key );
		if ( 0 !== $found || isset( $this->claims[ $key ] ) ) {
			return $found;
		}
		[ $found, $claim ] = SharedPictures::claim( $product, $key, fn (): int => $this->now(), $this->sleep ?? static fn ( int $seconds ): int => sleep( $seconds ), self::LOCK_SECONDS );
		if ( null !== $claim ) {
			$this->claims[ $key ] = $claim;
		}
		return $found;
	}

	/**
	 * Pixonaut's PNGs of the sources, drawn at once.
	 *
	 * @param array<int, array{template: string, blank?: string, placement?: string, view?: string, variant?: string|null, photo?: string, showcase?: string}> $sources       What each is drawn from.
	 * @param bool                                                                                                                             $with_showcase Whether each is drawn with its showcase set; else with the template's defaults.
	 *
	 * @return array<int, string|ApiException> Each source's PNG, or why Pixonaut did not draw it, under the source's key.
	 */
	private function pngs( array $sources, bool $with_showcase ): array {
		if ( array() === $sources ) {
			return array();
		}
		$calls = array();
		foreach ( $sources as $source ) {
			$showcase = $with_showcase ? $source['showcase'] ?? null : null;
			$calls[]  = isset( $source['blank'], $source['placement'], $source['view'] )
				? $this->pictures->mockup_of( $source['template'], $source['blank'], $source['placement'], $source['view'], $source['variant'] ?? null, self::SIZE, $showcase, $source['photo'] ?? null )
				: $this->pictures->preview_of( $source['template'], self::SIZE, $showcase );
		}
		return array_combine( array_keys( $sources ), $this->pictures->all( $calls ) );
	}

	/**
	 * Records a drawing that failed, and tries it again after `RETRY_SECONDS`.
	 *
	 * @param WC_Product $product A product or variation.
	 * @param string     $basis   What its picture depends on now.
	 * @param array      $stored  Its recorded pictures.
	 * @phpstan-param array{basis: string|null, source: string|null, attachment: int, failed_at: int|null, gallery: list<array{source: string|null, attachment: int}>, gone: list<string>} $stored
	 */
	private function failed( WC_Product $product, string $basis, array $stored ): void {
		self::store(
			$product,
			array(
				'basis'     => $basis,
				'failed_at' => $this->now(),
			) + $stored
		);
		// One retry per product: a hook of its own, so it never holds back the drawing a change queues.
		if ( ! as_has_scheduled_action( self::RETRY_ACTION, array( $product->get_id() ), self::GROUP ) ) {
			as_schedule_single_action( $this->now() + self::RETRY_SECONDS, self::RETRY_ACTION, array( $product->get_id() ), self::GROUP );
		}
	}

	/**
	 * Whether the product is still what its picture was drawn for, read
	 * again from the database: it may have changed while Pixonaut drew it,
	 * the store manager's own image included.
	 *
	 * @param WC_Product $product A product or variation, as the job read it.
	 * @param string     $basis   What its picture was drawn for.
	 * @param bool       $own     Whether its image was the store manager's own then.
	 */
	private function still( WC_Product $product, string $basis, bool $own ): bool {
		$fresh = PictureQueue::fresh( $product->get_id() );
		return null !== $fresh && self::applies( $this->settings, $fresh ) && self::basis( $fresh ) === $basis && PictureImage::is_own( $fresh ) === $own;
	}

	/**
	 * Whether a product showing the store manager's own image still has
	 * gallery pictures to draw or take out: a product, with showcase sets
	 * chosen or gallery pictures recorded.
	 *
	 * @param WC_Product $product A product or variation.
	 * @param array      $stored  Its recorded pictures.
	 * @phpstan-param array{basis: string|null, source: string|null, attachment: int, failed_at: int|null, gallery: list<array{source: string|null, attachment: int}>, gone: list<string>} $stored
	 */
	public static function has_gallery_work( WC_Product $product, array $stored ): bool {
		return ! $product->is_type( 'variation' ) && ( array() !== ShowcaseImages::on( $product ) || array() !== $stored['gallery'] );
	}

	/**
	 * Puts back the image the product had before its picture, takes the
	 * pictures out of its gallery, and deletes them.
	 *
	 * @param WC_Product  $product A product or variation.
	 * @param string|null $basis   What its picture depends on now, recorded as drawn without a picture; null forgets the record.
	 * @param array       $stored  Its recorded pictures.
	 * @phpstan-param array{basis: string|null, source: string|null, attachment: int, failed_at: int|null, gallery: list<array{source: string|null, attachment: int}>, gone: list<string>} $stored
	 */
	private static function release( WC_Product $product, ?string $basis, array $stored ): void {
		// Recorded first: the image put back then queues nothing more.
		if ( null === $basis ) {
			$product->delete_meta_data( self::META_KEY );
			$product->save_meta_data();
		} else {
			self::store( $product, array( 'basis' => $basis ) + self::stored_from( array() ) );
		}
		PictureImage::restore( $product );
		if ( array() !== $stored['gallery'] ) {
			PictureGallery::show( $product, array() );
		}
		foreach ( self::attachments( $stored ) as $attachment ) {
			SharedPictures::release( $attachment, $product );
		}
	}

	/**
	 * Deletes a product's or variation's picture with it.
	 *
	 * @param mixed $post_id The post being deleted.
	 */
	public function deleting( mixed $post_id ): void {
		if ( ! in_array( get_post_type( (int) $post_id ), array( 'product', 'product_variation' ), true ) ) {
			return;
		}
		$product = wc_get_product( (int) $post_id );
		if ( $product instanceof WC_Product ) {
			foreach ( self::attachments( self::stored( $product ) ) as $attachment ) {
				SharedPictures::release( $attachment, $product );
			}
		}
	}

	/**
	 * What the picture depends on, as stored: the configuration of the
	 * product (and of a variation's product), which holds each side's
	 * template version, its variant, the showcase sets chosen when there are
	 * any, the view and photo chosen when there is one, the generation `redraw_all()` moves, and the product's own that
	 * `regenerate()` moves. Null when neither is configured.
	 *
	 * @param WC_Product $product A product or variation.
	 */
	public static function basis( WC_Product $product ): ?string {
		$own    = ProductConfig::document_on( $product );
		$parent = $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : null;
		$shared = $parent instanceof WC_Product ? ProductConfig::document_on( $parent ) : null;
		if ( null === $own && null === $shared ) {
			return null;
		}
		$parts    = array( $own, $shared, ProductVariant::document_on( $product ), self::generation() );
		$showcase = ShowcaseImages::document_on( $product );
		if ( null !== $showcase ) {
			// Only when set, so a product without showcase sets depends on what it did before them.
			$parts[] = $showcase;
		}
		$views = array_filter(
			array(
				'own'    => PictureView::document_on( $product ),
				'parent' => $parent instanceof WC_Product ? PictureView::document_on( $parent ) : null,
			)
		);
		if ( array() !== $views ) {
			// Likewise only when chosen.
			$parts[] = array( 'picture_view' => $views );
		}
		return md5( (string) wp_json_encode( self::drawn_under( $product, $parts ) ) );
	}

	/**
	 * Records that a change to what the picture depends on drew nothing new:
	 * a record current before it stays current. One that was not is left due.
	 *
	 * @param WC_Product $product A product or variation, read after the change.
	 * @param string     $before  Its basis before the change.
	 */
	public static function rebase( WC_Product $product, string $before ): void {
		$stored = self::stored( $product );
		$basis  = self::basis( $product );
		if ( null !== $basis && $before === $stored['basis'] && $before !== $basis ) {
			$stored['basis'] = $basis;
			self::store( $product, $stored );
		}
	}

	/**
	 * The generation `regenerate()` moves for a product, which its
	 * variations are drawn under too; 0 until it first moves.
	 *
	 * @param WC_Product $product A product or variation.
	 */
	public static function product_generation( WC_Product $product ): int {
		$owner = $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : $product;
		return $owner instanceof WC_Product ? (int) $owner->get_meta( self::PRODUCT_GENERATION_META ) : 0;
	}

	/**
	 * Parts of what a picture is drawn from, with the product's generation
	 * once it moved: only then, so a product never asked to be drawn again
	 * keeps the pictures it has.
	 *
	 * @param WC_Product   $product A product or variation.
	 * @param array<mixed> $parts   What the picture is drawn from.
	 *
	 * @return array<mixed>
	 */
	private static function drawn_under( WC_Product $product, array $parts ): array {
		$generation = self::product_generation( $product );
		if ( 0 !== $generation ) {
			$parts[] = array( 'product_generation' => $generation );
		}
		return $parts;
	}

	/**
	 * @param WC_Product $product A product or variation.
	 *
	 * @return array{basis: string|null, source: string|null, attachment: int, failed_at: int|null, gallery: list<array{source: string|null, attachment: int}>, gone: list<string>}
	 */
	public static function stored( WC_Product $product ): array {
		$raw    = $product->get_meta( self::META_KEY );
		$stored = is_string( $raw ) ? json_decode( $raw, true ) : null;
		return self::stored_from( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Every picture recorded: its image's, then its gallery's.
	 *
	 * @param array $stored A record, as `stored()` reads it.
	 * @phpstan-param array{basis: string|null, source: string|null, attachment: int, failed_at: int|null, gallery: list<array{source: string|null, attachment: int}>, gone: list<string>} $stored
	 *
	 * @return list<int>
	 */
	public static function attachments( array $stored ): array {
		return array_values( array_filter( array_merge( array( $stored['attachment'] ), array_column( $stored['gallery'], 'attachment' ) ) ) );
	}

	/**
	 * The record in a decoded meta value, each part checked.
	 *
	 * @param array<mixed> $stored The decoded meta.
	 *
	 * @return array{basis: string|null, source: string|null, attachment: int, failed_at: int|null, gallery: list<array{source: string|null, attachment: int}>, gone: list<string>}
	 */
	private static function stored_from( array $stored ): array {
		$gallery = array();
		foreach ( is_array( $stored['gallery'] ?? null ) ? $stored['gallery'] : array() as $slot ) {
			if ( is_array( $slot ) && is_int( $slot['attachment'] ?? null ) ) {
				$gallery[] = array(
					'source'     => is_string( $slot['source'] ?? null ) ? $slot['source'] : null,
					'attachment' => $slot['attachment'],
				);
			}
		}
		return array(
			'basis'      => is_string( $stored['basis'] ?? null ) ? $stored['basis'] : null,
			'source'     => is_string( $stored['source'] ?? null ) ? $stored['source'] : null,
			'attachment' => is_int( $stored['attachment'] ?? null ) ? $stored['attachment'] : 0,
			'failed_at'  => is_int( $stored['failed_at'] ?? null ) ? $stored['failed_at'] : null,
			'gallery'    => $gallery,
			'gone'       => array_values( array_filter( is_array( $stored['gone'] ?? null ) ? $stored['gone'] : array(), 'is_string' ) ),
		);
	}

	/**
	 * Records the product's pictures. Only its meta is saved, so no save hook runs.
	 *
	 * @param WC_Product $product A product or variation.
	 * @param array      $record  What it was drawn for (`basis`) and from (`source`, null for no picture), the picture (`attachment`, 0 for none), when Pixonaut last failed to draw it, its gallery's pictures, and the showcase sets that are gone.
	 * @phpstan-param array{basis: string|null, source: string|null, attachment: int, failed_at: int|null, gallery: list<array{source: string|null, attachment: int}>, gone: list<string>} $record
	 */
	private static function store( WC_Product $product, array $record ): void {
		$product->update_meta_data( self::META_KEY, (string) wp_json_encode( $record ) );
		$product->save_meta_data();
	}

	/**
	 * Takes the right to draw the product's picture. `add_option()` inserts
	 * or fails atomically, so two jobs never draw it at once; a lock older
	 * than `LOCK_SECONDS` is taken over.
	 *
	 * @param int $product_id The product or variation.
	 *
	 * @return string|null The lock's value, for `unlock()`; null when another job holds it.
	 */
	private function lock( int $product_id ): ?string {
		$option = self::LOCK_OPTION_PREFIX . $product_id;
		$now    = (string) $this->now();
		$held   = self::take( $option, $now );
		if ( null !== $held ) {
			return $held;
		}
		$held_since = get_option( $option );
		if ( is_numeric( $held_since ) && (int) $now - (int) $held_since < self::LOCK_SECONDS ) {
			return null;
		}
		delete_option( $option );
		return self::take( $option, $now );
	}

	/**
	 * Adds the lock's option, which fails when it is there already.
	 *
	 * @param string $option The lock's option.
	 * @param string $value  When it is taken, as Unix time.
	 *
	 * @return string|null The value; null when the option was there.
	 */
	private static function take( string $option, string $value ): ?string {
		return add_option( $option, $value, '', false ) ? $value : null;
	}

	/**
	 * Gives up the product's lock, unless another job took it over meanwhile.
	 *
	 * @param int    $product_id The product or variation.
	 * @param string $held       The lock's value when this job took it.
	 */
	private static function unlock( int $product_id, string $held ): void {
		$option = self::LOCK_OPTION_PREFIX . $product_id;
		wp_cache_delete( $option, 'options' );
		if ( get_option( $option ) === $held ) {
			delete_option( $option );
		}
	}

	public static function generation(): int {
		return (int) get_option( self::GENERATION_OPTION, 0 );
	}

	private function now(): int {
		return null === $this->clock ? time() : ( $this->clock )();
	}
}
