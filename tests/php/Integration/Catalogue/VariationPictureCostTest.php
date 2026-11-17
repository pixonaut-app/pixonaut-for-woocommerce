<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Catalogue;

use Pixonaut\WooCommerce\Admin\VariationPicturesField;
use Pixonaut\WooCommerce\Catalogue\MediaSpace;
use Pixonaut\WooCommerce\Catalogue\PictureStorage;
use Pixonaut\WooCommerce\Catalogue\ProductVariant;
use Pixonaut\WooCommerce\Catalogue\SharedPictures;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Catalogue\ShowcaseImages;
use Pixonaut\WooCommerce\Catalogue\VariationPictureCost;
use Pixonaut\WooCommerce\Catalogue\VariationPictures;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Settings\SettingsPage;
use Pixonaut\WooCommerce\Tests\Support\BlankFixture;
use Pixonaut\WooCommerce\Tests\Support\DependentPosters;
use Pixonaut\WooCommerce\Tests\Support\DesignFixture;
use ReflectionProperty;
use WC_Admin_Settings;
use WC_Product_Variable;
use WP_UnitTestCase;

/**
 * What turning a picture for every variation on costs the shop's storage,
 * from the pictures it keeps and the free space where it keeps its media,
 * and the confirmation it needs past the warning.
 */
final class VariationPictureCostTest extends WP_UnitTestCase {

	use DependentPosters;
	use PicturedProducts;

	private const LEO = array(
		'id'   => 'showcase-leo',
		'name' => 'Leo',
		'kind' => ShowcaseImages::PREVIEW,
	);

	/**
	 * A kept picture's file, in bytes.
	 */
	private const FILE_BYTES = 400000;

	/**
	 * Each of its image sizes, in bytes.
	 */
	private const SIZE_BYTES = 50000;

	/**
	 * A kept picture with its two sizes.
	 */
	private const PICTURE_BYTES = self::FILE_BYTES + 2 * self::SIZE_BYTES;

	/**
	 * Free space a tenth of which the poster's seven new pictures pass or
	 * not: 3.5 MB of pictures is 5 % of 70 MB, 20 % of 17.5 MB.
	 */
	private const ROOMY = 70000000;

	private const TIGHT = 17500000;

	private WC_Product_Variable $poster;

	public function set_up(): void {
		parent::set_up();
		$this->set_up_pictures();
		$this->set_up_dependent_posters();
		$this->poster = $this->dependent_poster();
		self::config()->save_to( $this->poster );
		ShowcaseImages::save( $this->poster, array( self::LEO ) );
		foreach ( $this->poster_variations as $key => $variation ) {
			( new ProductVariant( DesignFixture::BLANK_ID, 'v_' . $key, 'v_' . $key, '', null, BlankFixture::VARIANT_VIEWS ) )->save_to( $variation );
		}
		as_unschedule_all_actions( '' );
	}

	public function tear_down(): void {
		$this->tear_down_dependent_posters();
		$this->tear_down_pictures();
		unset( $_POST[ Settings::OPTION ], $_POST[ VariationPicturesField::CONFIRM_FIELD ] );
		( new ReflectionProperty( WC_Admin_Settings::class, 'errors' ) )->setValue( null, array() );
		parent::tear_down();
	}

	public function test_the_estimate_is_one_picture_per_variant_times_the_average_picture_kept(): void {
		$this->keep_pictures( 2 );

		$cost = $this->cost( self::ROOMY )->turning_on( array( $this->poster ) );

		$this->assertSame( count( $this->poster_variations ), $cost['pictures'] );
		$this->assertSame( count( $this->poster_variations ) * self::PICTURE_BYTES, $cost['bytes'] );
		$this->assertSame( self::ROOMY, $cost['free'] );
		$this->assertSame( 5, $cost['percent'] );
		$this->assertFalse( $cost['warn'] );
		$this->assertNull( VariationPictureCost::warning( array( 'on' => true ) + $cost ) );
		$this->assertStringContainsString( sprintf( 'About %d new pictures, about %s.', count( $this->poster_variations ), size_format( $cost['bytes'] ) ), VariationPictureCost::summary( array( 'on' => true ) + $cost ) );
		$this->assertStringContainsString( 'not in Pixonaut', VariationPictureCost::summary( array( 'on' => true ) + $cost ) );
	}

	public function test_past_a_tenth_of_the_free_space_it_warns_with_the_share(): void {
		$this->keep_pictures( 2 );

		$cost = array( 'on' => true ) + $this->cost( self::TIGHT )->turning_on( array( $this->poster ) );

		$this->assertSame( 20, $cost['percent'] );
		$this->assertTrue( $cost['warn'] );
		$this->assertSame( 'This will use about 20% of your shop\'s remaining storage for product pictures.', VariationPictureCost::warning( $cost ) );
	}

	public function test_free_space_that_cannot_be_read_warns_that_it_is_unknown(): void {
		$this->keep_pictures( 1 );

		$cost = array( 'on' => true ) + ( new VariationPictureCost( $this->plugin->settings(), new PictureStorage(), new MediaSpace( static fn (): bool => false ) ) )->turning_on( array( $this->poster ) );

		$this->assertNull( $cost['free'] );
		$this->assertNull( $cost['percent'] );
		$this->assertTrue( $cost['warn'] );
		$this->assertStringContainsString( 'could not be read', (string) VariationPictureCost::warning( $cost ) );
	}

	public function test_a_shop_keeping_no_pictures_yet_counts_on_the_fallback_size(): void {
		$cost = $this->cost( self::ROOMY )->turning_on( array( $this->poster ) );

		$this->assertSame( count( $this->poster_variations ) * VariationPictureCost::FALLBACK_BYTES, $cost['bytes'] );
	}

	public function test_variations_drawn_on_their_photo_already_need_no_new_picture(): void {
		ShowcaseImages::save( $this->poster, array( array( 'kind' => ShowcaseImages::MOCKUP ) + self::LEO ) );

		$cost = $this->cost( self::TIGHT )->turning_on( array( $this->reload( $this->poster ) ) );

		$this->assertSame( 0, $cost['pictures'] );
		$this->assertFalse( $cost['warn'] );
	}

	public function test_the_settings_page_saves_it_on_past_the_warning_only_when_confirmed(): void {
		$this->keep_pictures( 2 );
		$page = new SettingsPage( $this->plugin->settings(), '', $this->cost( self::TIGHT ) );

		$_POST[ Settings::OPTION ] = self::both_on(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$page->save( SettingsPage::STOREFRONT );

		$this->assertFalse( $this->plugin->settings()->get( Settings::VARIATION_PICTURES ), 'kept off' );
		$errors = array_values( ( new ReflectionProperty( WC_Admin_Settings::class, 'errors' ) )->getValue() );
		$this->assertCount( 1, $errors );
		$this->assertStringContainsString( 'about 20%', $errors[0] );

		$_POST[ Settings::OPTION ]                      = array( Settings::VARIATION_PICTURES => '1' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$_POST[ VariationPicturesField::CONFIRM_FIELD ] = VariationPicturesField::CONFIRMED; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$page->save( SettingsPage::STOREFRONT );

		$this->assertTrue( $this->plugin->settings()->get( Settings::VARIATION_PICTURES ), 'on once confirmed' );
	}

	public function test_the_settings_page_saves_it_on_below_the_warning_without_a_confirmation(): void {
		$this->keep_pictures( 2 );
		$page = new SettingsPage( $this->plugin->settings(), '', $this->cost( self::ROOMY ) );

		$_POST[ Settings::OPTION ] = self::both_on(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$page->save( SettingsPage::STOREFRONT );

		$this->assertTrue( $this->plugin->settings()->get( Settings::VARIATION_PICTURES ) );
	}

	public function test_a_product_turned_on_past_the_warning_is_saved_only_when_confirmed(): void {
		$this->keep_pictures( 2 );
		$field = new VariationPicturesField( $this->plugin->templates(), $this->plugin->settings(), $this->cost( self::TIGHT ) );

		$_POST[ VariationPicturesField::FIELD ] = VariationPictures::ON; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$field->save( $this->poster->get_id() );
		$this->assertSame( VariationPictures::DEFAULT, VariationPictures::choice( $this->reload( $this->poster ) ), 'kept' );

		$_POST[ VariationPicturesField::CONFIRM_FIELD ] = VariationPicturesField::CONFIRMED; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$field->save( $this->poster->get_id() );
		$this->assertSame( VariationPictures::ON, VariationPictures::choice( $this->reload( $this->poster ) ), 'on once confirmed' );
		unset( $_POST[ VariationPicturesField::FIELD ] );
	}

	public function test_turning_product_pictures_on_with_variation_pictures_needs_the_confirmation_too(): void {
		$this->keep_pictures( 2 );
		$this->plugin->settings()->set( Settings::PRODUCT_PICTURES, false );
		$page = new SettingsPage( $this->plugin->settings(), '', $this->cost( self::TIGHT ) );

		$_POST[ Settings::OPTION ] = array( // phpcs:ignore WordPress.Security.NonceVerification.Missing
			Settings::PRODUCT_PICTURES   => '1',
			Settings::VARIATION_PICTURES => '1',
		);
		$page->save( SettingsPage::STOREFRONT );

		$this->assertFalse( $this->plugin->settings()->get( Settings::PRODUCT_PICTURES ), 'pictures kept off' );
		$errors = array_values( ( new ReflectionProperty( WC_Admin_Settings::class, 'errors' ) )->getValue() );
		$this->assertStringContainsString( 'about 20%', $errors[0] ?? '' );
	}

	public function test_a_product_with_it_on_counts_when_product_pictures_are_turned_on(): void {
		$this->keep_pictures( 2 );
		VariationPictures::choose( $this->poster, VariationPictures::ON );

		$this->assertSame( array( $this->poster->get_id() ), array_map( static fn ( $product ): int => $product->get_id(), $this->cost( self::TIGHT )->resolving_on( false ) ) );
	}

	public function test_variations_of_one_variant_on_another_template_count_as_another_picture(): void {
		$variations = array_values( $this->poster_variations );
		foreach ( $variations as $variation ) {
			( new ProductVariant( DesignFixture::BLANK_ID, 'v_same', 'v_same', '', null, BlankFixture::VARIANT_VIEWS ) )->save_to( $variation );
		}
		self::config()->with_override( true )->with_sides( array( new Side( 'front', 'Front', DesignFixture::PLACEMENT_ID, null, 'photo-tee', 4, null, 'Photo tee' ) ) )->save_to( $variations[0] );

		$cost = $this->cost( self::ROOMY )->turning_on( array( $this->reload( $this->poster ) ) );

		$this->assertSame( 2, $cost['pictures'], 'one per template' );
	}

	public function test_turning_it_off_on_a_product_drawn_on_its_photo_frees_nothing(): void {
		ShowcaseImages::save( $this->poster, array( array( 'kind' => ShowcaseImages::MOCKUP ) + self::LEO ) );
		VariationPictures::choose( $this->poster, VariationPictures::ON );

		$this->assertSame( 0, $this->cost( self::ROOMY )->turning_off( array( $this->reload( $this->poster ) ) )['pictures'] );
	}

	public function test_a_product_following_the_store_wide_setting_is_counted_with_it_and_one_of_its_own_is_not(): void {
		$cost = $this->cost( self::ROOMY );
		$this->assertSame( array( $this->poster->get_id() ), array_map( static fn ( $product ): int => $product->get_id(), $cost->following_store_wide() ) );

		VariationPictures::choose( $this->poster, VariationPictures::OFF );

		$this->assertSame( array(), $cost->following_store_wide() );
	}

	/**
	 * The settings form posted with product pictures and variation pictures ticked.
	 *
	 * @return array<string, string>
	 */
	private static function both_on(): array {
		return array(
			Settings::PRODUCT_PICTURES   => '1',
			Settings::VARIATION_PICTURES => '1',
		);
	}

	/**
	 * A cost estimate with the free space given.
	 *
	 * @param int $free Free bytes.
	 */
	private function cost( int $free ): VariationPictureCost {
		return new VariationPictureCost( $this->plugin->settings(), new PictureStorage(), new MediaSpace( static fn (): int => $free ) );
	}

	/**
	 * Pictures the shop keeps of another product, each `PICTURE_BYTES` with its sizes.
	 *
	 * @param int $count How many.
	 */
	private function keep_pictures( int $count ): void {
		$product = $this->product( self::config() );
		for ( $picture = 0; $picture < $count; ++$picture ) {
			$attachment = $this->image();
			add_post_meta( $attachment, SharedPictures::PICTURE_OF_META, $product->get_id() );
			wp_update_attachment_metadata(
				$attachment,
				array(
					'width'    => 1200,
					'height'   => 1200,
					'file'     => 'picture-' . $picture . '.png',
					'filesize' => self::FILE_BYTES,
					'sizes'    => array(
						'thumbnail' => array(
							'file'     => 'picture-' . $picture . '-150x150.png',
							'filesize' => self::SIZE_BYTES,
						),
						'medium'    => array(
							'file'     => 'picture-' . $picture . '-300x300.png',
							'filesize' => self::SIZE_BYTES,
						),
					),
				)
			);
		}
	}
}
