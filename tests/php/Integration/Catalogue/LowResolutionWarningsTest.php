<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Catalogue;

use Pixonaut\WooCommerce\Admin\LowResolutionWarnings;
use Pixonaut\WooCommerce\Admin\PanelFields;
use Pixonaut\WooCommerce\Api\Answers\LowResolutionImage;
use Pixonaut\WooCommerce\Catalogue\InvalidReason;
use Pixonaut\WooCommerce\Catalogue\LowResolutionSize;
use Pixonaut\WooCommerce\Catalogue\PrintSize;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Settings\SettingsPage;
use Pixonaut\WooCommerce\Tests\Support\VerdictAnswers;

/**
 * A print size that makes one of the template's images print below its
 * recommended resolution is warned of in the product's panel, which image,
 * at what resolution and size; the store manager can accept it there, stop
 * the warnings, or open the picture in the studio. A size Pixonaut refuses
 * for it says why, with nothing to accept.
 */
final class LowResolutionWarningsTest extends ProductPanelTestCase {

	private const ASSET_ID = 'ast_photo';

	private const LAYER_ID = 'photo';

	/**
	 * The image as the merchant knows it: its layer's label.
	 */
	private const LABEL = 'Photo';

	/**
	 * What Pixonaut answers of the image at the placement's size.
	 */
	private const SOFT = array(
		'layerId'        => self::LAYER_ID,
		'assetId'        => self::ASSET_ID,
		'effectiveDpi'   => 121.9,
		'recommendedDpi' => 300,
		'label'          => self::LABEL,
	);

	/**
	 * The same picture in a second layer, where it prints softer.
	 */
	private const SOFTER_LAYER = array(
		'layerId'      => 'backdrop',
		'label'        => 'Backdrop',
		'effectiveDpi' => 90.4,
	) + self::SOFT;

	/**
	 * An image whose label says nothing, on the artwork background's
	 * internal key as its layer.
	 */
	private const UNNAMED = array(
		'layerId'        => '$artwork',
		'assetId'        => self::ASSET_ID,
		'effectiveDpi'   => 121.9,
		'recommendedDpi' => 300,
		'label'          => ' ',
	);

	/**
	 * The posted name of the warnings' choices on a product.
	 */
	private const CHOICES = PanelFields::PRODUCT_PREFIX . PanelFields::LOW_RESOLUTION;

	public function tear_down(): void {
		$this->plugin->settings()->set( Settings::LOW_RESOLUTION_WARNINGS, true );
		$this->plugin->settings()->set( Settings::LOW_RESOLUTION_QUIET_IMAGES, array() );
		parent::tear_down();
	}

	public function test_a_size_printing_an_image_too_soft_is_kept_with_it_and_warned_of(): void {
		$product = $this->product();
		$this->link( $product->get_id(), array( self::SOFT ) );

		$side = ProductConfig::stored_on( $this->reload( $product->get_id() ) )->sides[0];
		$this->assertTrue( ProductConfig::stored_on( $this->reload( $product->get_id() ) )->valid, 'a size Pixonaut warns of is sold' );
		$this->assertEquals( array( new LowResolutionSize( new PrintSize( self::PRINT_WIDTH_MM, self::PRINT_HEIGHT_MM, '280 × 360 mm', true ), array( new LowResolutionImage( self::LAYER_ID, self::ASSET_ID, 121.9, 300.0, self::LABEL ) ) ) ), $side->low_resolution );

		$html = $this->html( $product->get_id() );
		$this->assertStringContainsString( 'The image in “Photo” prints at 122 dpi at 280 × 360 mm, below the 300 dpi recommended', $html );
		$this->assertStringContainsString( 'assets?asset=' . self::ASSET_ID, $html, 'Upscale opens the picture in the studio' );
		$this->assertStringContainsString( 'name="' . self::CHOICES . '[' . self::key() . ']"', $html );
	}

	public function test_an_answer_with_a_blank_label_names_no_internal_key(): void {
		$product = $this->product();
		$this->link( $product->get_id(), array( self::UNNAMED ) );

		$html = $this->html( $product->get_id() );
		$this->assertStringContainsString( 'The image prints at 122 dpi at 280 × 360 mm, below the 300 dpi recommended, so it may look soft.', $html );
		$this->assertStringNotContainsString( '$artwork', $html );
	}

	public function test_a_verdict_stored_before_images_were_named_names_no_internal_key(): void {
		$product = $this->product();
		$this->link( $product->get_id(), array( self::SOFT ) );
		$stored = $this->reload( $product->get_id() );
		$config = json_decode( (string) $stored->get_meta( ProductConfig::META_KEY, true ), true );
		$this->assertIsArray( $config );
		$this->assertSame( self::LABEL, $config['sides'][0]['lowResolution'][0]['images'][0]['label'] ?? null, 'the label is kept with the verdict' );
		$stored->update_meta_data( ProductConfig::META_KEY, (string) wp_json_encode( self::without_labels( $config ) ) );
		$stored->save_meta_data();

		$html = $this->html( $product->get_id() );
		$this->assertStringContainsString( 'The image prints at 122 dpi at 280 × 360 mm', $html );
		$this->assertStringNotContainsString( '“' . self::LAYER_ID . '”', $html );
	}

	public function test_accepting_an_image_hides_it_at_that_size_until_it_is_no_longer_warned_of(): void {
		$product = $this->product();
		$this->link( $product->get_id(), array( self::SOFT ) );

		$this->link( $product->get_id(), array( self::SOFT ), array( self::key() => LowResolutionWarnings::ACCEPT ) );

		$this->assertSame( array( self::key() ), LowResolutionWarnings::accepted_on( $this->reload( $product->get_id() ) ) );
		$this->assertStringNotContainsString( 'pixonaut-low-resolution', $this->html( $product->get_id() ) );

		$this->link( $product->get_id(), array() );

		$this->assertSame( array(), LowResolutionWarnings::accepted_on( $this->reload( $product->get_id() ) ), 'an accept of what is no longer warned of is dropped' );
	}

	public function test_an_image_can_be_quieted_everywhere_and_warnings_turned_off(): void {
		$product = $this->product();
		$this->link( $product->get_id(), array( self::SOFT ) );

		$this->link( $product->get_id(), array( self::SOFT ), array( self::key() => LowResolutionWarnings::QUIET_IMAGE ) );

		$this->assertSame( array( self::ASSET_ID ), $this->plugin->settings()->quiet_images() );
		$this->assertStringNotContainsString( 'pixonaut-low-resolution', $this->html( $product->get_id() ) );

		$this->plugin->settings()->set( Settings::LOW_RESOLUTION_QUIET_IMAGES, array() );
		$this->assertStringContainsString( 'pixonaut-low-resolution', $this->html( $product->get_id() ), 'removing it from the list warns again' );

		$this->link( $product->get_id(), array( self::SOFT ), array( self::key() => LowResolutionWarnings::QUIET_ALL ) );

		$this->assertFalse( $this->plugin->settings()->get( Settings::LOW_RESOLUTION_WARNINGS ) );
		$this->assertStringNotContainsString( 'pixonaut-low-resolution', $this->html( $product->get_id() ) );
	}

	public function test_only_a_store_manager_can_stop_warnings(): void {
		$product = $this->product();
		$this->link( $product->get_id(), array( self::SOFT ) );
		$no_settings = static function ( array $allcaps ): array {
			$allcaps[ SettingsPage::CAPABILITY ] = false;
			return $allcaps;
		};
		add_filter( 'user_has_cap', $no_settings );

		$this->link( $product->get_id(), array( self::SOFT ), array( self::key() => LowResolutionWarnings::QUIET_IMAGE ) );
		$this->link( $product->get_id(), array( self::SOFT ), array( self::key() => LowResolutionWarnings::QUIET_ALL ) );

		$this->assertSame( array(), $this->plugin->settings()->quiet_images() );
		$this->assertTrue( $this->plugin->settings()->get( Settings::LOW_RESOLUTION_WARNINGS ) );
		$html = $this->html( $product->get_id() );
		$this->assertStringContainsString( 'value="' . LowResolutionWarnings::ACCEPT . '"', $html, 'a product editor can still accept' );
		$this->assertStringNotContainsString( 'value="' . LowResolutionWarnings::QUIET_IMAGE . '"', $html );
		$this->assertStringNotContainsString( 'value="' . LowResolutionWarnings::QUIET_ALL . '"', $html );

		$this->link( $product->get_id(), array( self::SOFT ), array( self::key() => LowResolutionWarnings::ACCEPT ) );

		$this->assertSame( array( self::key() ), LowResolutionWarnings::accepted_on( $this->reload( $product->get_id() ) ) );
		remove_filter( 'user_has_cap', $no_settings );
	}

	public function test_a_size_refused_for_a_soft_image_says_why_and_offers_only_the_studio(): void {
		$this->plugin->settings()->set( Settings::LOW_RESOLUTION_WARNINGS, false );
		$product = $this->product();
		$this->link(
			$product->get_id(),
			array( self::SOFT ),
			array(),
			array(
				'legal'  => false,
				'reason' => InvalidReason::LOW_RESOLUTION,
			)
		);

		$config = ProductConfig::stored_on( $this->reload( $product->get_id() ) );
		$this->assertFalse( $config->valid );
		$this->assertSame( InvalidReason::LOW_RESOLUTION, $config->invalid_reason );
		$this->assertTrue( $config->sides[0]->low_resolution[0]->refused );

		$html = $this->html( $product->get_id() );
		$this->assertStringContainsString( 'Upscale the image in the Pixonaut studio, or choose a smaller print size', $html );
		$this->assertStringContainsString( 'so Pixonaut does not sell this size', $html, 'shown with warnings off' );
		$this->assertStringContainsString( 'Accept in Pixonaut', $html );
		$this->assertStringNotContainsString( 'name="' . self::CHOICES, $html, 'nothing to accept in the store' );
	}

	public function test_a_picture_in_two_layers_is_one_warning_named_by_its_softest_layer(): void {
		$product = $this->product();
		$this->link( $product->get_id(), array( self::SOFT, self::SOFTER_LAYER ) );

		$html = $this->html( $product->get_id() );
		$this->assertSame( 1, substr_count( $html, 'name="' . self::CHOICES . '[' . self::key() . ']"' ), 'one choice for the picture' );
		$this->assertStringContainsString( 'The image in “Backdrop” prints at 90 dpi', $html );
		$this->assertStringNotContainsString( 'The image in “Photo”', $html );

		$this->link( $product->get_id(), array( self::SOFT, self::SOFTER_LAYER ), array( self::key() => LowResolutionWarnings::ACCEPT ) );

		$this->assertSame( array( self::key() ), LowResolutionWarnings::accepted_on( $this->reload( $product->get_id() ) ) );
		$this->assertStringNotContainsString( 'pixonaut-low-resolution', $this->html( $product->get_id() ) );
	}

	public function test_a_size_refused_for_another_reason_warns_of_its_soft_images_as_any_other(): void {
		$product = $this->product();
		$this->link(
			$product->get_id(),
			array( self::SOFT ),
			array(),
			array(
				'legal'  => false,
				'reason' => InvalidReason::WRONG_RATIO,
			)
		);

		$this->assertFalse( ProductConfig::stored_on( $this->reload( $product->get_id() ) )->sides[0]->low_resolution[0]->refused );

		$html = $this->html( $product->get_id() );
		$this->assertStringContainsString( 'so it may look soft', $html );
		$this->assertStringNotContainsString( 'so Pixonaut does not sell this size', $html );
		$this->assertStringContainsString( 'name="' . self::CHOICES . '[' . self::key() . ']"', $html, 'the warning can be accepted' );

		$this->plugin->settings()->set( Settings::LOW_RESOLUTION_WARNINGS, false );
		$this->assertStringNotContainsString( 'pixonaut-low-resolution', $this->html( $product->get_id() ), 'not shown with warnings off' );
	}

	/**
	 * Saves the product linked at the placement's size, Pixonaut answering
	 * the soft images given.
	 *
	 * @param int                                 $product_id The product.
	 * @param list<array<string, mixed>>          $soft       What Pixonaut answers in `lowResolution`.
	 * @param array<string, string>               $choices    What was chosen on the warnings, by key.
	 * @param array{legal: bool, reason?: string} $verdict  The verdict besides them.
	 */
	private function link( int $product_id, array $soft, array $choices = array(), array $verdict = VerdictAnswers::LEGAL ): void {
		$this->script_link( array( 'lowResolution' => $soft ) + $verdict );
		$this->save( $product_id, array( self::CHOICES => $choices ) );
	}

	/**
	 * Stored data with every image's `label` removed, as a verdict stored
	 * before Pixonaut named images was kept.
	 *
	 * @param array<mixed> $data Stored data.
	 * @return array<mixed>
	 */
	private static function without_labels( array $data ): array {
		if ( isset( $data['assetId'] ) ) {
			unset( $data['label'] );
		}
		return array_map( static fn ( mixed $value ): mixed => is_array( $value ) ? self::without_labels( $value ) : $value, $data );
	}

	private function html( int $product_id ): string {
		$this->cache->flush();
		$this->recorder->json( 200, $this->blank() );
		$this->recorder->json( 200, $this->mockups() );
		$this->recorder->json( 200, self::printshops() );
		return $this->panel->fields_html( $this->panel->panel( $this->reload( $product_id ) ) );
	}

	/**
	 * The key of the image at the placement's size on the product's one side.
	 */
	private static function key(): string {
		return LowResolutionSize::key( 'front', new PrintSize( self::PRINT_WIDTH_MM, self::PRINT_HEIGHT_MM, '' ), LowResolutionImage::from_answer( self::SOFT ) );
	}
}
