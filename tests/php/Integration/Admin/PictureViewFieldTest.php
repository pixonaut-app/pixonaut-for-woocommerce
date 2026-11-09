<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Admin;

use Pixonaut\WooCommerce\Admin\LinkedBlank;
use Pixonaut\WooCommerce\Admin\PanelFields;
use Pixonaut\WooCommerce\Admin\ProductPanel;
use Pixonaut\WooCommerce\Admin\ShowcaseField;
use Pixonaut\WooCommerce\Api\Catalogue;
use Pixonaut\WooCommerce\Api\Deprecations;
use Pixonaut\WooCommerce\Api\HookListener;
use Pixonaut\WooCommerce\Api\Pixonaut;
use Pixonaut\WooCommerce\Api\WcLogger;
use Pixonaut\WooCommerce\Catalogue\CatalogueCache;
use Pixonaut\WooCommerce\Catalogue\PictureView;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ShowcaseImages;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Tests\Support\HttpRecorder;
use Pixonaut\WooCommerce\Tests\Support\OpenApiAssert;
use Pixonaut\WooCommerce\Tests\Support\ScriptedClient;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_UnitTestCase;

/**
 * The choice of the view and photo a product image is drawn on, in the
 * product images field and in a variation's options.
 */
final class PictureViewFieldTest extends WP_UnitTestCase {

	/**
	 * Loopback, so the test bootstrap's host guard lets it through to the recorder.
	 */
	private const BASE_URL = 'http://127.0.0.1:9';

	private const BLANK_ID = 'blank_tee';

	private const PLACEMENT_ID = 'front';

	/**
	 * The front placement's view and photo in the mockups example.
	 */
	private const VIEW = 'view-front-flat';

	private const PHOTO = 'mock-front';

	/**
	 * A scene photo added to the example's view, as Pixonaut lists a blank's scenes.
	 */
	private const SCENE_PHOTO = 'mock-front-scene';

	private const SCENE_URL = 'https://api.pixonaut.example/i/front-scene';

	private HttpRecorder $recorder;

	private ShowcaseField $field;

	public function set_up(): void {
		parent::set_up();
		$this->recorder = ( new HttpRecorder( self::BASE_URL ) )->start();
		$plugin         = Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' );
		$client         = ( new Pixonaut( ScriptedClient::credentials(), new WcLogger(), new HookListener( new Deprecations() ), null, self::BASE_URL ) )->sdk();
		$cache          = new CatalogueCache( $plugin->connection_store() );
		$cache->flush();
		$catalogue   = new Catalogue( $client );
		$this->field = new ShowcaseField( $catalogue, $cache, $plugin->templates(), $plugin->settings(), new LinkedBlank( $catalogue, $cache ) );
	}

	public function tear_down(): void {
		$this->recorder->stop();
		$_POST = array();
		delete_option( Settings::OPTION );
		parent::tear_down();
	}

	public function test_a_template_without_sets_offers_each_photo_of_the_side_for_the_product_image(): void {
		$this->answer_sets( array() );
		$this->answer_mockups();

		$html = $this->field->html( $this->product() );

		$this->assertSame( 1, substr_count( $html, 'role="radiogroup"' ) );
		$this->assertStringNotContainsString( 'pixonaut-showcase-images__list', $html );
		$this->assertMatchesRegularExpression( '#name="' . ShowcaseField::VIEW_FIELD . '" value="mockup"\s+checked#', $html );
		$this->assertStringContainsString( 'value="' . esc_attr( self::value( self::SCENE_PHOTO ) ) . '"', $html );
		$this->assertStringContainsString( 'src="' . self::SCENE_URL . '"', $html );
		$this->assertStringContainsString( 'Front, flat lay, photo 2', $html );
		$this->assertStringContainsString( 'value="preview"', $html );
		$this->assertStringContainsString( 'name="' . ShowcaseField::VIEW_LISTED_FIELD . '"', $html );
	}

	public function test_a_chosen_photo_is_checked_for_its_set(): void {
		$product = $this->product();
		ShowcaseImages::save( $product, array( self::set() + self::choice( self::SCENE_PHOTO ) ) );
		$this->answer_sets( array( self::set() ) );
		$this->answer_mockups();

		$html = $this->field->html( $this->reload( $product ) );

		$this->assertMatchesRegularExpression( '#name="pixonaut_showcase_images\[0\]\[kind\]" value="' . preg_quote( esc_attr( self::value( self::SCENE_PHOTO ) ), '#' ) . '"\s+checked#', $html );
		$this->assertStringNotContainsString( 'pixonaut-picture-view__gone', $html );
	}

	public function test_a_photo_the_blank_no_longer_has_is_shown_as_gone(): void {
		$product = $this->product();
		PictureView::save( $product, self::choice( 'mock-gone' ) );
		$this->answer_sets( array() );
		$this->answer_mockups();

		$html = $this->field->html( $this->reload( $product ) );

		$this->assertStringContainsString( 'No longer on the blank', $html );
		$this->assertMatchesRegularExpression( '#value="' . preg_quote( esc_attr( self::value( 'mock-gone' ) ), '#' ) . '"\s+checked#', $html );
		$this->assertStringContainsString( 'pixonaut-picture-view__gone', $html );
	}

	public function test_saving_keeps_the_photo_chosen_for_the_image_and_for_each_set(): void {
		$product = $this->product();

		$this->post(
			$product,
			array(
				ShowcaseField::VIEW_LISTED_FIELD => '1',
				ShowcaseField::VIEW_FIELD        => wp_slash( self::value( self::SCENE_PHOTO ) ),
				ShowcaseField::LISTED_FIELD      => '1',
				ShowcaseField::FIELD             => array(
					array(
						'use'  => 'yes',
						'kind' => wp_slash( self::value( self::PHOTO ) ),
					) + self::set(),
				),
			)
		);

		$product = $this->reload( $product );
		$this->assertSame( self::choice( self::SCENE_PHOTO ), PictureView::on( $product ) );
		$this->assertSame( array( self::set() + self::choice( self::PHOTO ) ), ShowcaseImages::on( $product ) );
	}

	public function test_saving_the_photo_the_product_page_starts_on_forgets_the_choice(): void {
		$product = $this->product();
		PictureView::save( $product, self::choice( self::PHOTO ) );

		$this->post(
			$product,
			array(
				ShowcaseField::VIEW_LISTED_FIELD => '1',
				ShowcaseField::VIEW_FIELD        => PictureView::MOCKUP,
			)
		);

		$this->assertNull( PictureView::on( $this->reload( $product ) ) );
	}

	public function test_a_form_without_the_choice_keeps_it(): void {
		$product = $this->product();
		PictureView::save( $product, self::choice( self::PHOTO ) );

		$this->post( $product, array( ShowcaseField::VIEW_FIELD => PictureView::MOCKUP ) );

		$this->assertSame( self::choice( self::PHOTO ), PictureView::on( $this->reload( $product ) ) );
	}

	public function test_a_variation_offers_its_products_choice_and_each_photo(): void {
		$variation = $this->variation();
		PictureView::save( $variation, self::choice( self::SCENE_PHOTO ) );
		$this->answer_mockups();

		$html = $this->field->variation_html( $this->reload( $variation ), PanelFields::variation( 3 ) );

		$name = PanelFields::variation( 3 )->name( PanelFields::PICTURE_VIEW );
		$this->assertStringContainsString( 'As the product image', $html );
		$this->assertStringContainsString( 'name="' . $name . '" value="mockup"', $html );
		$this->assertMatchesRegularExpression( '#name="' . preg_quote( $name, '#' ) . '" value="' . preg_quote( esc_attr( self::value( self::SCENE_PHOTO ) ), '#' ) . '"\s+checked#', $html );
		$this->assertStringNotContainsString( 'value="preview"', $html );
	}

	public function test_a_variation_saves_its_choice_and_following_its_product_forgets_it(): void {
		$variation = $this->variation();

		ShowcaseField::save_variation( $variation, self::value( self::PHOTO ) );
		$this->assertSame( self::choice( self::PHOTO ), PictureView::on( $this->reload( $variation ) ) );

		ShowcaseField::save_variation( $this->reload( $variation ), PictureView::MOCKUP );
		$this->assertNull( PictureView::on( $this->reload( $variation ) ) );
	}

	public function test_a_variation_not_on_a_blank_has_no_field(): void {
		$product = new WC_Product_Variable();
		$product->save();
		( new ProductConfig( personalise: true, blank_id: null, sides: array( new Side( 'front', 'Front', null, null, 'mini-tee', 2, null, 'Mini tee', array() ) ) ) )->save_to( $product );
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $product->get_id() );
		$variation->save();

		$this->assertSame( '', $this->field->variation_html( $this->reload( $variation ), PanelFields::variation( 0 ) ) );
		$this->assertSame( array(), $this->recorder->requests );
	}

	/**
	 * Answers the blank's mockups read: the example, its front view holding a scene photo too.
	 */
	private function answer_mockups(): void {
		$mockups = json_decode( OpenApiAssert::example( 'GET', '/v1/blanks/{id}/mockups', 200 ), true );
		$this->assertIsArray( $mockups );
		$photo = $mockups['placements'][0]['views'][0]['photos'][0];
		$mockups['placements'][0]['views'][0]['photos'][] = array(
			'id'           => self::SCENE_PHOTO,
			'thumbnailUrl' => self::SCENE_URL,
			'variantRefs'  => array(),
		) + $photo;
		$this->recorder->raw( 200, (string) wp_json_encode( $mockups ), 'application/json' );
	}

	/**
	 * Answers the template read with its showcase sets.
	 *
	 * @param list<array{id: string, name: string}> $sets The sets.
	 */
	private function answer_sets( array $sets ): void {
		$this->recorder->raw(
			200,
			OpenApiAssert::example(
				'GET',
				'/v1/templates/{id}',
				200,
				array(
					'showcases'  => array_map( static fn ( array $set ): array => $set + array( 'previewUrl' => self::BASE_URL . '/i/' . $set['id'] ), $sets ),
					'previewUrl' => 'https://api.pixonaut.example/i/template-preview',
				)
			),
			'application/json'
		);
	}

	/**
	 * @return array{id: string, name: string}
	 */
	private static function set(): array {
		return array(
			'id'   => 'showcase-anna',
			'name' => 'Anna, class of 2026',
		);
	}

	/**
	 * @param string $photo A photo of the front view.
	 * @return array{kind: string, view: string, photo: string}
	 */
	private static function choice( string $photo ): array {
		return array(
			'kind'  => PictureView::MOCKUP,
			'view'  => self::VIEW,
			'photo' => $photo,
		);
	}

	/**
	 * The value the field posts for a photo of the front view.
	 *
	 * @param string $photo The photo.
	 */
	private static function value( string $photo ): string {
		return PictureView::field_value( self::choice( $photo ) );
	}

	/**
	 * Saves the product as the product screen posts it.
	 *
	 * @param WC_Product           $product The product.
	 * @param array<string, mixed> $fields  The posted fields beside the nonce.
	 */
	private function post( WC_Product $product, array $fields ): void {
		$_POST = array( ProductPanel::NONCE_FIELD => wp_create_nonce( ProductPanel::NONCE_ACTION ) ) + $fields;
		$this->field->save( $product->get_id() );
		$this->field->save_view( $product->get_id() );
		$_POST = array();
	}

	/**
	 * A product personalised on the mini tee template, printed on the blank's front.
	 */
	private function product(): WC_Product_Simple {
		$product = new WC_Product_Simple();
		$product->set_name( 'Student tee' );
		$product->save();
		self::config()->save_to( $product );
		$read = $this->reload( $product );
		$this->assertInstanceOf( WC_Product_Simple::class, $read );
		return $read;
	}

	/**
	 * A variation of a product personalised on the blank.
	 */
	private function variation(): WC_Product {
		$product   = new WC_Product_Variable();
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'Colour' );
		$attribute->set_options( array( 'White' ) );
		$attribute->set_variation( true );
		$product->set_attributes( array( $attribute ) );
		$product->save();
		self::config()->save_to( $product );
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $product->get_id() );
		$variation->set_attributes( array( 'colour' => 'White' ) );
		$variation->save();
		return $this->reload( $variation );
	}

	private static function config(): ProductConfig {
		return new ProductConfig( personalise: true, blank_id: self::BLANK_ID, sides: array( new Side( 'front', 'Front', self::PLACEMENT_ID, self::PLACEMENT_ID, 'mini-tee', 2, null, 'Mini tee', array() ) ) );
	}

	private function reload( WC_Product $product ): WC_Product {
		$read = wc_get_product( $product->get_id() );
		$this->assertInstanceOf( WC_Product::class, $read );
		return $read;
	}
}
