<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Admin;

use Pixonaut\WooCommerce\Admin\LinkedBlank;
use Pixonaut\WooCommerce\Admin\ProductPanel;
use Pixonaut\WooCommerce\Admin\ShowcaseField;
use Pixonaut\WooCommerce\Api\Catalogue;
use Pixonaut\WooCommerce\Api\Deprecations;
use Pixonaut\WooCommerce\Api\HookListener;
use Pixonaut\WooCommerce\Api\Pixonaut;
use Pixonaut\WooCommerce\Api\WcLogger;
use Pixonaut\WooCommerce\Catalogue\CatalogueCache;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ProductPictures;
use Pixonaut\WooCommerce\Catalogue\ProductVariant;
use Pixonaut\WooCommerce\Catalogue\ShowcaseImages;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Tests\Support\HttpRecorder;
use Pixonaut\WooCommerce\Tests\Support\OpenApiAssert;
use Pixonaut\WooCommerce\Tests\Support\ScriptedClient;
use WC_Product;
use WC_Product_Simple;
use WP_UnitTestCase;

final class ShowcaseFieldTest extends WP_UnitTestCase {

	/**
	 * Loopback, so the test bootstrap's host guard lets it through to the recorder.
	 */
	private const BASE_URL = 'http://127.0.0.1:9';

	private const SETS = array(
		array(
			'id'   => 'showcase-anna',
			'name' => 'Anna, class of 2026',
		),
		array(
			'id'   => 'showcase-team',
			'name' => 'Team photo',
		),
	);

	/**
	 * The template's preview, as its read links it.
	 */
	private const PREVIEW_URL = 'https://api.pixonaut.example/i/template-preview';

	/**
	 * The blank's front photo, as the mockups example links it.
	 */
	private const PHOTO_URL = 'https://api.pixonaut.example/i/eyJhdWQiOiJjYXRhbG9ndWUtaW1hZ2UifQ.c2lnbmF0dXJl';

	private const BLANK_ID = 'blank_tee';

	private const PLACEMENT_ID = 'front';

	/**
	 * The front placement's view in the mockups example.
	 */
	private const FRONT_VIEW = 'view-front-flat';

	private const WHITE = 'v_white_s';

	private const WHITE_PHOTO_URL = 'https://api.pixonaut.example/i/white-front';

	private const BLACK_PHOTO_URL = 'https://api.pixonaut.example/i/black-front';

	private HttpRecorder $recorder;

	private Plugin $plugin;

	private ShowcaseField $field;

	public function set_up(): void {
		parent::set_up();
		$this->recorder = ( new HttpRecorder( self::BASE_URL ) )->start();
		$this->plugin   = Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' );
		$client         = ( new Pixonaut( ScriptedClient::credentials(), new WcLogger(), new HookListener( new Deprecations() ), null, self::BASE_URL ) )->sdk();
		$cache          = new CatalogueCache( $this->plugin->connection_store() );
		$cache->flush();
		$catalogue   = new Catalogue( $client );
		$this->field = new ShowcaseField( $catalogue, $cache, $this->plugin->templates(), $this->plugin->settings(), new LinkedBlank( $catalogue, $cache ) );
	}

	public function tear_down(): void {
		$this->recorder->stop();
		$_POST = array();
		delete_option( Settings::OPTION );
		parent::tear_down();
	}

	public function test_it_lists_the_templates_sets_with_those_chosen_ticked(): void {
		$product = $this->product();
		ShowcaseImages::save( $product, array( self::SETS[1] + array( 'kind' => ShowcaseImages::PREVIEW ) ) );
		$this->answer_sets( self::SETS );

		$html = $this->field->html( $this->reload( $product ) );

		$this->assertStringContainsString( '/v1/templates/mini-tee%402', $this->recorder->requests[0]['url'] );
		$this->assertStringContainsString( 'Anna, class of 2026', $html );
		$this->assertMatchesRegularExpression( '#name="pixonaut_showcase_images\[1\]\[use\]" value="yes"\s+checked#', $html );
		$this->assertDoesNotMatchRegularExpression( '#name="pixonaut_showcase_images\[0\]\[use\]" value="yes"\s+checked#', $html );
		$this->assertStringNotContainsString( 'no longer in the template', $html );
	}

	public function test_on_a_blank_with_a_photo_each_set_is_drawn_on_it_or_flat_each_with_a_thumbnail(): void {
		$product = $this->product( self::BLANK_ID );
		ShowcaseImages::save( $product, array( self::SETS[1] + array( 'kind' => ShowcaseImages::PREVIEW ) ) );
		$this->answer_sets( self::SETS );
		$this->recorder->raw( 200, OpenApiAssert::example( 'GET', '/v1/blanks/{id}/mockups', 200 ), 'application/json' );

		$html = $this->field->html( $this->reload( $product ) );

		$this->assertStringContainsString( '/v1/blanks/' . self::BLANK_ID . '/mockups', $this->recorder->requests[1]['url'] );
		$this->assertSame( 3, substr_count( $html, 'role="radiogroup"' ), 'the image without a set, and each set' );
		$this->assertMatchesRegularExpression( '#name="pixonaut_showcase_images\[0\]\[kind\]" value="mockup"\s+checked#', $html );
		$this->assertMatchesRegularExpression( '#name="pixonaut_showcase_images\[1\]\[kind\]" value="preview"\s+checked#', $html );
		$this->assertStringContainsString( 'On the product photo', $html );
		$this->assertStringContainsString( 'The design alone, without the product.', $html );
		$this->assertStringContainsString( 'src="' . self::PHOTO_URL . '"', $html );
		$this->assertStringContainsString( 'src="' . self::PREVIEW_URL . '"', $html );
		$this->assertStringNotContainsString( 'pixonaut-showcase-images__flat', $html );
	}

	public function test_on_a_blank_without_a_photo_for_the_side_every_set_is_flat(): void {
		$product = $this->product( self::BLANK_ID );
		ShowcaseImages::save( $product, array( self::SETS[0] + array( 'kind' => ShowcaseImages::MOCKUP ) ) );
		$this->answer_sets( self::SETS );
		$mockups = json_decode( OpenApiAssert::example( 'GET', '/v1/blanks/{id}/mockups', 200 ), true );
		$this->assertIsArray( $mockups );
		$mockups['placements'][0]['views'][0]['photos'] = array();
		$this->recorder->raw( 200, (string) wp_json_encode( $mockups ), 'application/json' );

		$html = $this->field->html( $this->reload( $product ) );

		$this->assertStringNotContainsString( 'On the product photo', $html );
		$this->assertStringNotContainsString( 'type="radio"', $html );
		$this->assertMatchesRegularExpression( '#type="hidden" name="pixonaut_showcase_images\[0\]\[kind\]" value="preview"#', $html );
		$this->assertStringContainsString( 'The blank has no product photo for this side', $html );
	}

	public function test_the_photo_offered_is_the_one_of_the_variant_the_product_sells(): void {
		$product = $this->product( self::BLANK_ID );
		( new ProductVariant( self::BLANK_ID, self::WHITE, self::WHITE, 'White', null, array( self::view( self::FRONT_VIEW, self::PLACEMENT_ID ) ) ) )->save_to( $product );
		$this->answer_sets( self::SETS );
		$mockups = json_decode( OpenApiAssert::example( 'GET', '/v1/blanks/{id}/mockups', 200 ), true );
		$this->assertIsArray( $mockups );
		$photo = $mockups['placements'][0]['views'][0]['photos'][0];
		$mockups['placements'][0]['views'][0]['photos'] = array(
			array(
				'variantRefs'  => array( 'v_black' ),
				'thumbnailUrl' => self::BLACK_PHOTO_URL,
			) + $photo,
			array(
				'variantRefs'  => array( self::WHITE ),
				'thumbnailUrl' => self::WHITE_PHOTO_URL,
			) + $photo,
		);
		$this->recorder->raw( 200, (string) wp_json_encode( $mockups ), 'application/json' );

		$html = $this->field->html( $this->reload( $product ) );

		$automatic = (string) preg_replace( '#^.*?value="mockup"(.*?)</label>.*$#s', '$1', $html );
		$this->assertStringContainsString( 'src="' . self::WHITE_PHOTO_URL . '"', $automatic );
		$this->assertStringNotContainsString( self::BLACK_PHOTO_URL, $automatic );
	}

	public function test_a_variant_without_a_photo_for_the_side_has_every_set_flat(): void {
		$product = $this->product( self::BLANK_ID );
		( new ProductVariant( self::BLANK_ID, self::WHITE, self::WHITE, 'White', null, array( self::view( 'view-back-flat', 'back' ) ) ) )->save_to( $product );
		$this->answer_sets( self::SETS );

		$html = $this->field->html( $this->reload( $product ) );

		$this->assertCount( 1, $this->recorder->requests, 'the blank\'s photos are not read' );
		$this->assertStringNotContainsString( 'type="radio"', $html );
		$this->assertStringContainsString( 'The blank has no product photo for this side', $html );
	}

	public function test_without_a_blank_every_set_is_flat(): void {
		$this->answer_sets( self::SETS );

		$html = $this->field->html( $this->product() );

		$this->assertCount( 1, $this->recorder->requests, 'no blank to read' );
		$this->assertStringNotContainsString( 'type="radio"', $html );
		$this->assertMatchesRegularExpression( '#type="hidden" name="pixonaut_showcase_images\[1\]\[kind\]" value="preview"#', $html );
		$this->assertStringContainsString( 'The product has no blank', $html );
	}

	public function test_while_the_blanks_photos_cannot_be_read_both_are_offered(): void {
		$product = $this->product( self::BLANK_ID );
		$this->answer_sets( self::SETS );
		$this->recorder->fail();

		$html = $this->field->html( $product );

		$this->assertStringContainsString( 'On the product photo', $html );
		$this->assertStringNotContainsString( 'src="' . self::PHOTO_URL . '"', $html );
	}

	public function test_every_set_is_listed_however_many_the_template_has(): void {
		$product = $this->product();
		$sets    = array_map(
			static fn ( int $n ): array => array(
				'id'   => 'showcase-' . $n,
				'name' => 'Set ' . $n,
			),
			range( 1, ShowcaseImages::MAX_IMAGES + 1 )
		);
		$last    = end( $sets );
		ShowcaseImages::save( $product, array( $last + array( 'kind' => ShowcaseImages::MOCKUP ) ) );
		$this->answer_sets( $sets );

		$html = $this->field->html( $this->reload( $product ) );

		$this->assertStringContainsString( $last['name'], $html );
		$this->assertStringNotContainsString( 'no longer in the template', $html );
	}

	public function test_a_template_without_sets_has_no_field(): void {
		$this->answer_sets( array() );

		$this->assertSame( '', $this->field->html( $this->product() ) );
	}

	public function test_an_answer_that_does_not_list_sets_has_no_field(): void {
		$template = json_decode( OpenApiAssert::example( 'GET', '/v1/templates/{id}', 200 ), true );
		$this->assertIsArray( $template );
		unset( $template['showcases'] );
		$this->recorder->off_spec( 200, $template );

		$this->assertSame( '', $this->field->html( $this->product() ) );
	}

	public function test_a_template_without_sets_lists_those_chosen_as_gone(): void {
		$product = $this->product();
		ShowcaseImages::save( $product, array( self::SETS[0] + array( 'kind' => ShowcaseImages::MOCKUP ) ) );
		$this->answer_sets( array() );

		$html = $this->field->html( $this->reload( $product ) );

		$this->assertStringContainsString( 'Anna, class of 2026', $html );
		$this->assertStringContainsString( 'no longer in the template', $html );
	}

	public function test_without_pixonaut_and_nothing_chosen_there_is_no_field(): void {
		$this->recorder->fail();

		$this->assertSame( '', $this->field->html( $this->product() ) );
	}

	public function test_a_chosen_set_the_template_no_longer_has_is_listed_as_gone(): void {
		$product = $this->product();
		ShowcaseImages::save(
			$product,
			array(
				self::SETS[0] + array( 'kind' => ShowcaseImages::MOCKUP ),
				array(
					'id'   => 'showcase-old',
					'name' => 'Old sign',
					'kind' => ShowcaseImages::MOCKUP,
				),
			)
		);
		$this->answer_sets( array( self::SETS[0] ) );

		$html = $this->field->html( $this->reload( $product ) );

		$this->assertStringContainsString( 'Old sign', $html );
		$this->assertSame( 1, substr_count( $html, 'no longer in the template' ) );
		$this->assertStringContainsString( 'pixonaut-showcase-image--gone', $html );
	}

	public function test_it_says_when_pixonaut_pictures_are_not_product_images(): void {
		$this->plugin->settings()->set( Settings::PRODUCT_PICTURES, false );
		$this->answer_sets( self::SETS );

		$this->assertStringContainsString( 'pixonaut-showcase-images__off', $this->field->html( $this->product() ) );
	}

	public function test_a_product_without_a_template_has_no_field(): void {
		$product = new WC_Product_Simple();
		$product->save();

		$this->assertSame( '', $this->field->html( $product ) );
		$this->assertSame( array(), $this->recorder->requests );
	}

	public function test_saving_keeps_the_ticked_sets_in_the_order_listed(): void {
		$product = $this->product();

		$this->post(
			$product,
			array(
				ShowcaseField::LISTED_FIELD => '1',
				ShowcaseField::FIELD        => array(
					array(
						'use'  => 'yes',
						'id'   => 'showcase-anna',
						'name' => 'Anna, class of 2026',
						'kind' => 'mockup',
					),
					array(
						'id'   => 'showcase-skipped',
						'name' => 'Not ticked',
						'kind' => 'mockup',
					),
					array(
						'use'  => 'yes',
						'id'   => 'showcase-team',
						'name' => 'Team photo',
						'kind' => 'preview',
					),
				),
			)
		);

		$this->assertSame(
			array(
				self::SETS[0] + array( 'kind' => ShowcaseImages::MOCKUP ),
				self::SETS[1] + array( 'kind' => ShowcaseImages::PREVIEW ),
			),
			ShowcaseImages::on( $this->reload( $product ) )
		);
	}

	public function test_a_form_that_could_not_list_the_sets_keeps_the_choices(): void {
		$product = $this->product();
		ShowcaseImages::save( $product, array( self::SETS[0] + array( 'kind' => ShowcaseImages::MOCKUP ) ) );

		$this->post( $product, array() );

		$this->assertCount( 1, ShowcaseImages::on( $this->reload( $product ) ) );
	}

	public function test_nothing_ticked_forgets_the_choices(): void {
		$product = $this->product();
		ShowcaseImages::save( $product, array( self::SETS[0] + array( 'kind' => ShowcaseImages::MOCKUP ) ) );

		$this->post( $product, array( ShowcaseField::LISTED_FIELD => '1' ) );

		$this->assertSame( '', $this->reload( $product )->get_meta( ShowcaseImages::META_KEY ) );
		$this->assertSame( array(), ProductPictures::stored( $this->reload( $product ) )['gone'] );
	}

	/**
	 * Answers the template's read with its sets, each with a link to its preview.
	 *
	 * @param list<array{id: string, name: string}> $sets Its sets.
	 */
	private function answer_sets( array $sets ): void {
		$listed = array_map(
			fn ( array $set ): array => $set + array( 'previewUrl' => self::BASE_URL . '/i/' . $set['id'] ),
			$sets
		);
		$this->recorder->raw(
			200,
			OpenApiAssert::example(
				'GET',
				'/v1/templates/{id}',
				200,
				array(
					'showcases'  => $listed,
					'previewUrl' => self::PREVIEW_URL,
				)
			),
			'application/json'
		);
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
		$_POST = array();
	}

	/**
	 * A product personalised on the mini tee template.
	 *
	 * @param string|null $blank_id Its blank, printed on the front; null for none.
	 */
	private function product( ?string $blank_id = null ): WC_Product_Simple {
		$product = new WC_Product_Simple();
		$product->set_name( 'Student sign' );
		$product->save();
		$placement = null === $blank_id ? null : self::PLACEMENT_ID;
		( new ProductConfig( personalise: true, blank_id: $blank_id, sides: array( new Side( 'front', 'Front', $placement, $placement, 'mini-tee', 2, null, 'Mini tee', array() ) ) ) )->save_to( $product );
		return $this->reload( $product );
	}

	/**
	 * A view a stored variant has a photo in.
	 *
	 * @param string $id        The view.
	 * @param string $placement Its placement.
	 * @return array{id: string, placement: string, label: string}
	 */
	private static function view( string $id, string $placement ): array {
		return array(
			'id'        => $id,
			'placement' => $placement,
			'label'     => $id,
		);
	}

	private function reload( WC_Product $product ): WC_Product_Simple {
		$read = wc_get_product( $product->get_id() );
		$this->assertInstanceOf( WC_Product_Simple::class, $read );
		return $read;
	}
}
