<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Abilities;

use Pixonaut\WooCommerce\Abilities\GetProductSplitListing;
use Pixonaut\WooCommerce\Abilities\SetProductSplitListing;
use Pixonaut\WooCommerce\Catalogue\SplitCardImageCopies;
use Pixonaut\WooCommerce\Catalogue\SplitCardLooks;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Tests\Support\ActionSchedulerRunner;
use Pixonaut\WooCommerce\Tests\Support\SplitPosters;
use WC_Product;
use WC_Product_Variable;
use WP_UnitTestCase;

/**
 * The images `set-product-split-listing` is given by their URLs, copied into
 * the media library a job per card, from a picture host that answers slowly:
 * the call downloads none itself.
 */
final class SplitCardImageCopiesTest extends WP_UnitTestCase {

	use SplitPosters;

	/**
	 * The attribute whose values are listed as their own products.
	 */
	private const PICTURE = 'Bild';

	private const SIZE = 'Storlek';

	/**
	 * An attribute with a value per card of a product listed many times over.
	 */
	private const MOTIF = 'Motiv';

	/**
	 * As many cards as the product that showed the store's time running out had.
	 */
	private const MANY_CARDS = 22;

	private const HOST = 'https://pictures.example/cards/';

	/**
	 * A card picture's URL that answers nothing.
	 */
	private const GONE_URL = self::HOST . 'gone.png';

	private const GONE = 404;

	private const OK = 200;

	/**
	 * How long the picture host takes to answer one picture, in seconds: a
	 * few of them are more than a call to the store may take.
	 */
	private const SECONDS_PER_PICTURE = 7;

	/**
	 * A one-pixel PNG, as a card's picture's URL answers it.
	 */
	private const PIXEL_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

	/**
	 * Before any other answer to a request, so no picture is asked of a real host.
	 */
	private const FIRST = 1;

	/**
	 * The URLs the picture host was asked for, in order.
	 *
	 * @var list<string>
	 */
	private array $downloads = array();

	/**
	 * Run while the host answers a picture, as another request reaching the
	 * store during the download; null for none.
	 *
	 * @var (callable(): void)|null
	 */
	private $meanwhile = null;

	public function set_up(): void {
		parent::set_up();
		Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' );
		$this->set_up_posters();
		$this->attribute( self::MOTIF, array_map( static fn ( int $number ): string => "Motiv {$number}", range( 1, self::MANY_CARDS ) ) );
		$this->downloads = array();
		$this->meanwhile = null;
		add_filter( 'pre_http_request', array( $this, 'answer_picture' ), self::FIRST, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'answer_picture' ), self::FIRST );
		$this->tear_down_posters();
		parent::tear_down();
	}

	public function test_a_call_naming_many_card_images_downloads_none_and_each_is_copied_by_its_job(): void {
		$product = $this->product_of_many_cards();
		$terms   = $this->motifs( $product );
		$before  = $this->attachments();

		$set = $this->set(
			$product,
			array_map(
				static fn ( int $term_id ): array => array(
					'term_ids' => array( $term_id ),
					'image'    => array( 'src' => self::HOST . "{$term_id}.png" ),
				),
				$terms
			)
		);

		$this->assertSame( 0, $this->seconds_downloading(), 'the call waits for no picture, however slow its host' );
		$this->assertSame( array_fill( 0, self::MANY_CARDS, SplitCardImageCopies::WAITING ), array_column( array_column( $this->values( $set ), 'image_copy' ), 'state' ) );
		$this->assertSame( $before, $this->attachments() );

		$this->assertSame( self::MANY_CARDS, ActionSchedulerRunner::run_pending( SplitCardImageCopies::HOOK ) );

		$this->assertSame( self::MANY_CARDS * self::SECONDS_PER_PICTURE, $this->seconds_downloading() );
		$values = $this->values( $this->read( $product ) );
		$this->assertSame( array_fill( 0, self::MANY_CARDS, null ), array_column( $values, 'image_copy' ) );
		$images = array_column( array_column( $values, 'image' ), 'id' );
		$this->assertCount( self::MANY_CARDS, array_unique( $images ), 'each card has an image of its own' );
		$this->assertEqualsCanonicalizing( $images, array_diff( $this->attachments(), $before ), 'and the media library has no other new one' );
		$this->assertSame( array(), SplitCardImageCopies::stored_on( $product->get_id() ) );
	}

	public function test_a_copy_that_fails_leaves_nothing_in_the_media_library_and_says_why(): void {
		$product             = $this->product_of_many_cards();
		list( $kept, $gone ) = $this->motifs( $product );
		$before              = $this->attachments();
		SplitCardLooks::save( $product->get_id(), array( $gone => array( 'title' => 'Gone' ) ) );

		$this->set(
			$product,
			array(
				array(
					'term_ids' => array( $kept ),
					'image'    => array( 'src' => self::HOST . 'kept.png' ),
				),
				array(
					'term_ids' => array( $gone ),
					'image'    => array( 'src' => self::GONE_URL ),
				),
			)
		);
		ActionSchedulerRunner::run_pending( SplitCardImageCopies::HOOK );

		list( $copied, $failed ) = $this->values( $this->read( $product ) );
		$this->assertNull( $copied['image_copy'] );
		$this->assertSame( array( $copied['image']['id'] ), array_values( array_diff( $this->attachments(), $before ) ), 'only the picture that was copied is in the media library' );
		$this->assertNull( $failed['image'] );
		$this->assertSame( 'Gone', $failed['title'], 'its title is kept' );
		$this->assertSame( self::GONE_URL, $failed['image_copy']['src'] );
		$this->assertSame( SplitCardImageCopies::FAILED, $failed['image_copy']['state'] );
		$this->assertStringContainsString( 'Not Found', $failed['image_copy']['reason'] );
		$this->assertSame( array(), ActionSchedulerRunner::failed( SplitCardImageCopies::HOOK ), 'a picture that cannot be copied is no failed job' );

		$cleared = $this->set(
			$product,
			array(
				array(
					'term_ids' => array( $gone ),
					'image'    => null,
				),
			)
		);

		$this->assertNull( $this->values( $cleared )[1]['image_copy'], 'setting the image again forgets the failure' );
	}

	public function test_a_url_given_again_is_copied_once(): void {
		$product = $this->product_of_many_cards();
		$term    = $this->motifs( $product )[0];
		$before  = $this->attachments();
		$card    = array(
			array(
				'term_ids' => array( $term ),
				'image'    => array( 'src' => self::HOST . 'once.png' ),
			),
		);

		$this->set( $product, $card );
		$this->set( $product, $card );

		$this->assertCount( 1, ActionSchedulerRunner::pending( SplitCardImageCopies::HOOK ), 'a card waiting for the URL already gets no second job' );
		ActionSchedulerRunner::run_pending( SplitCardImageCopies::HOOK );
		$image = $this->values( $this->read( $product ) )[0]['image']['id'];

		$again = $this->set( $product, $card );

		$this->assertSame( $image, $this->values( $again )[0]['image']['id'] );
		$this->assertNull( $this->values( $again )[0]['image_copy'], 'the card shows the image copied from that URL already' );
		$this->assertSame( array(), ActionSchedulerRunner::pending( SplitCardImageCopies::HOOK ) );
		$this->assertSame( array( $image ), array_values( array_diff( $this->attachments(), $before ) ) );
	}

	public function test_a_card_given_an_image_of_the_library_while_it_waits_keeps_that_image(): void {
		$product = $this->product_of_many_cards();
		$term    = $this->motifs( $product )[0];
		$own     = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$this->assertIsInt( $own );
		$this->set(
			$product,
			array(
				array(
					'term_ids' => array( $term ),
					'image'    => array( 'src' => self::HOST . 'waited.png' ),
				),
			)
		);
		$before = $this->attachments();

		$set = $this->set(
			$product,
			array(
				array(
					'term_ids' => array( $term ),
					'image'    => array( 'id' => $own ),
				),
			)
		);
		ActionSchedulerRunner::run_pending( SplitCardImageCopies::HOOK );

		$this->assertNull( $this->values( $set )[0]['image_copy'] );
		$this->assertSame( array(), $this->downloads, 'the copy it no longer waits for is not downloaded' );
		$this->assertSame( $own, $this->values( $this->read( $product ) )[0]['image']['id'] );
		$this->assertSame( $before, $this->attachments() );
	}

	public function test_a_card_given_another_url_while_it_waits_gets_only_that_one(): void {
		$product = $this->product_of_many_cards();
		$term    = $this->motifs( $product )[0];
		$before  = $this->attachments();
		foreach ( array( 'first.png', 'second.png' ) as $file ) {
			$this->set(
				$product,
				array(
					array(
						'term_ids' => array( $term ),
						'image'    => array( 'src' => self::HOST . $file ),
					),
				)
			);
		}

		ActionSchedulerRunner::run_pending( SplitCardImageCopies::HOOK );

		$this->assertSame( array( self::HOST . 'second.png' ), $this->downloads );
		$image = $this->values( $this->read( $product ) )[0]['image']['id'];
		$this->assertSame( array( $image ), array_values( array_diff( $this->attachments(), $before ) ) );
	}

	public function test_a_copy_dropped_while_it_downloads_leaves_no_image(): void {
		$product = $this->product_of_many_cards();
		$term    = $this->motifs( $product )[0];
		$before  = $this->attachments();
		$this->set(
			$product,
			array(
				array(
					'term_ids' => array( $term ),
					'image'    => array( 'src' => self::HOST . 'late.png' ),
				),
			)
		);
		$this->meanwhile = function () use ( $product, $term ): void {
			$this->meanwhile = null;
			$this->set(
				$product,
				array(
					array(
						'term_ids' => array( $term ),
						'image'    => null,
					),
				)
			);
		};

		ActionSchedulerRunner::run_pending( SplitCardImageCopies::HOOK );

		$this->assertSame( array( self::HOST . 'late.png' ), $this->downloads );
		$this->assertNull( $this->values( $this->read( $product ) )[0]['image'] );
		$this->assertSame( $before, $this->attachments() );
	}

	public function test_a_copy_keeps_what_another_request_stored_since_this_one_read(): void {
		$product           = $this->product_of_many_cards();
		list( $one, $two ) = $this->motifs( $product );
		$this->set(
			$product,
			array(
				array(
					'term_ids' => array( $one ),
					'image'    => array( 'src' => self::HOST . 'one.png' ),
				),
			)
		);
		// Another request stores a second card's copy and title, as this one's cache does not know.
		$this->meanwhile = function () use ( $product, $one, $two ): void {
			$this->meanwhile = null;
			$copies          = SplitCardImageCopies::stored_on( $product->get_id() );
			$copies[ $two ]  = array( 'token' => 'other' ) + $copies[ $one ];
			$this->store_behind_the_cache( $product->get_id(), SplitCardImageCopies::META, $copies );
			$this->store_behind_the_cache( $product->get_id(), SplitCardLooks::META, array( $two => array( 'title' => 'Two' ) ) );
		};

		ActionSchedulerRunner::run_once( SplitCardImageCopies::HOOK );

		$this->assertSame( array( $two ), array_keys( SplitCardImageCopies::stored_on( $product->get_id() ) ), 'the other card still waits' );
		$looks = SplitCardLooks::stored_on( $product->get_id() );
		$this->assertSame( 'Two', $looks[ $two ]['title'] ?? null, 'and keeps its title' );
		$this->assertGreaterThan( 0, $looks[ $one ]['image'] ?? 0 );
	}

	public function test_a_copy_whose_job_cannot_be_queued_fails_and_is_queued_when_given_again(): void {
		$product = $this->product_of_many_cards();
		$term    = $this->motifs( $product )[0];
		$card    = array(
			array(
				'term_ids' => array( $term ),
				'image'    => array( 'src' => self::HOST . 'queued.png' ),
			),
		);
		$refuse  = static fn (): int => 0;
		add_filter( 'pre_as_enqueue_async_action', $refuse );

		$set = $this->set( $product, $card );

		remove_filter( 'pre_as_enqueue_async_action', $refuse );
		$this->assertSame( SplitCardImageCopies::FAILED, $this->values( $set )[0]['image_copy']['state'] );
		$this->assertNotSame( '', $this->values( $set )[0]['image_copy']['reason'] );

		$again = $this->set( $product, $card );

		$this->assertSame( SplitCardImageCopies::WAITING, $this->values( $again )[0]['image_copy']['state'] );
		$this->assertCount( 1, ActionSchedulerRunner::pending( SplitCardImageCopies::HOOK ) );
	}

	public function test_a_card_waiting_for_a_copy_whose_job_is_gone_gets_a_new_one(): void {
		$product = $this->product_of_many_cards();
		$term    = $this->motifs( $product )[0];
		$card    = array(
			array(
				'term_ids' => array( $term ),
				'image'    => array( 'src' => self::HOST . 'lost.png' ),
			),
		);
		$this->set( $product, $card );
		as_unschedule_all_actions( SplitCardImageCopies::HOOK );

		$this->set( $product, $card );

		$this->assertSame( 1, ActionSchedulerRunner::run_pending( SplitCardImageCopies::HOOK ) );
		$this->assertIsArray( $this->values( $this->read( $product ) )[0]['image'] );
	}

	/**
	 * @dataProvider when_the_value_is_removed
	 *
	 * @param bool $during Whether the product loses the value while the picture downloads, not before.
	 */
	public function test_a_card_of_a_value_the_product_lost_gets_no_image( bool $during ): void {
		$product = $this->product_of_many_cards();
		$term    = $this->motifs( $product )[0];
		$before  = $this->attachments();
		$this->set(
			$product,
			array(
				array(
					'term_ids' => array( $term ),
					'image'    => array( 'src' => self::HOST . 'removed.png' ),
				),
			)
		);
		$remove = function () use ( $product, $term ): void {
			$this->meanwhile = null;
			$attribute       = $this->product_attribute( self::MOTIF );
			$attribute->set_options( array_values( array_diff( array_map( 'intval', $attribute->get_options() ), array( $term ) ) ) );
			$fresh = wc_get_product( $product->get_id() );
			$this->assertInstanceOf( WC_Product::class, $fresh );
			$fresh->set_attributes( array( $attribute ) );
			$fresh->save();
		};
		if ( $during ) {
			$this->meanwhile = $remove;
		} else {
			$remove();
		}

		ActionSchedulerRunner::run_pending( SplitCardImageCopies::HOOK );

		$this->assertSame( array(), SplitCardLooks::stored_on( $product->get_id() ) );
		$this->assertSame( array(), SplitCardImageCopies::stored_on( $product->get_id() ) );
		$this->assertSame( $before, $this->attachments() );
	}

	/**
	 * @return array<string, array{bool}>
	 */
	public static function when_the_value_is_removed(): array {
		return array(
			'before its job runs'         => array( false ),
			'while its picture downloads' => array( true ),
		);
	}

	/**
	 * Stores a product's meta as another request would: this request's
	 * cache of it is left as it was.
	 *
	 * @param int          $product_id A product.
	 * @param string       $key        The meta key.
	 * @param array<mixed> $value      Its value.
	 */
	private function store_behind_the_cache( int $product_id, string $key, array $value ): void {
		$cached = wp_cache_get( (string) $product_id, 'post_meta' );
		update_post_meta( $product_id, $key, wp_slash( $value ) );
		wp_cache_set( (string) $product_id, $cached, 'post_meta' );
	}

	/**
	 * The picture host: a one-pixel PNG for any picture but the gone one.
	 *
	 * @param mixed        $response What an earlier filter answered.
	 * @param array<mixed> $args     The request.
	 * @param string       $url      Its URL.
	 * @return mixed
	 */
	public function answer_picture( $response, array $args, string $url ) {
		if ( ! str_starts_with( $url, self::HOST ) ) {
			return $response;
		}
		$this->downloads[] = $url;
		if ( null !== $this->meanwhile ) {
			( $this->meanwhile )();
		}
		$gone = self::GONE_URL === $url;
		$body = $gone ? '' : base64_decode( self::PIXEL_PNG ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- a fixture picture.
		// A streamed download is written to its file, as WordPress's transport writes it.
		if ( ! $gone && ! empty( $args['stream'] ) ) {
			file_put_contents( $args['filename'], $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- the download's temporary file.
			$body = '';
		}
		return array(
			'headers'  => $gone ? array() : array( 'content-type' => 'image/png' ),
			'body'     => $body,
			'response' => array(
				'code'    => $gone ? self::GONE : self::OK,
				'message' => $gone ? 'Not Found' : 'OK',
			),
			'cookies'  => array(),
			'filename' => $args['filename'] ?? null,
		);
	}

	/**
	 * How long the picture host has spent answering, in seconds.
	 */
	private function seconds_downloading(): int {
		return count( $this->downloads ) * self::SECONDS_PER_PICTURE;
	}

	/**
	 * A product with a value of the motif attribute per card.
	 */
	private function product_of_many_cards(): WC_Product {
		$product = new WC_Product_Variable();
		$product->set_name( 'Motifs' );
		$product->set_status( 'publish' );
		$product->set_attributes( array( $this->product_attribute( self::MOTIF ) ) );
		$product->save();
		$fresh = wc_get_product( $product->get_id() );
		$this->assertInstanceOf( WC_Product::class, $fresh );
		return $fresh;
	}

	/**
	 * The term ids of the product's motifs, in its order of values.
	 *
	 * @param WC_Product $product The product of many cards.
	 * @return list<int>
	 */
	private function motifs( WC_Product $product ): array {
		return array_column( $this->values( $this->read( $product ) ), 'term_id' );
	}

	/**
	 * The motif attribute's values, as an answer gives them.
	 *
	 * @param array<string, mixed> $answer What a read or a set answered.
	 * @return list<array<string, mixed>>
	 */
	private function values( array $answer ): array {
		return $answer['attributes'][0]['values'];
	}

	/**
	 * @param WC_Product $product A product.
	 * @return array<string, mixed>
	 */
	private function read( WC_Product $product ): array {
		$answer = ( new GetProductSplitListing() )->execute( array( 'product_id' => $product->get_id() ) );
		$this->assertIsArray( $answer );
		return $answer;
	}

	/**
	 * Sets cards of a product, as the ability's caller does.
	 *
	 * @param WC_Product         $product A product.
	 * @param list<array<mixed>> $cards   The input's `cards`.
	 * @return array<string, mixed>
	 */
	private function set( WC_Product $product, array $cards ): array {
		$answer = ( new SetProductSplitListing() )->execute(
			array(
				'product_id' => $product->get_id(),
				'cards'      => $cards,
			)
		);
		$this->assertIsArray( $answer );
		return $answer;
	}

	/**
	 * The ids of every attachment the media library has.
	 *
	 * @return list<int>
	 */
	private function attachments(): array {
		$ids = get_posts(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
				'orderby'     => 'ID',
				'order'       => 'ASC',
			)
		);
		return array_values( array_map( 'intval', $ids ) );
	}
}
