<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Abilities;

use Pixonaut\WooCommerce\Abilities\Abilities;
use Pixonaut\WooCommerce\Api\Hosts;
use Pixonaut\WooCommerce\Catalogue\CatalogueCache;
use Pixonaut\WooCommerce\Catalogue\PictureView;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ShowcaseImages;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Tests\Support\BlankFixture;
use Pixonaut\WooCommerce\Tests\Support\HttpRecorder;
use Pixonaut\WooCommerce\Tests\Support\OpenApiAssert;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_Ability;
use WP_Error;
use WP_UnitTestCase;

/**
 * The abilities that read and set what a product's images are drawn on,
 * run as the Abilities API runs them, against a recorder that answers as
 * Pixonaut.
 */
final class PictureViewAbilitiesTest extends WP_UnitTestCase {

	public const GET = Abilities::NAMESPACE . '/get-product-picture-views';

	public const SET = Abilities::NAMESPACE . '/set-product-picture-view';

	public const SHOWCASE = Abilities::NAMESPACE . '/set-product-showcase';

	private const BLANK_ID = 'blank_tee';

	/**
	 * When the fixture's connection was made, as Unix time.
	 */
	private const CONNECTED_AT = 1767225600;

	private const PLACEMENT_ID = 'front';

	/**
	 * The view `BlankFixture::mockups()` lists for the placement, and its photo.
	 */
	private const VIEW = 'view-' . self::PLACEMENT_ID;

	private const PHOTO = 'mock-' . self::PLACEMENT_ID;

	/**
	 * A second view of the placement, as a bound scene lists, and its photo.
	 */
	private const SCENE_VIEW = 'sv_breakfast';

	private const SCENE_PHOTO = 'mock-breakfast';

	private const ANNA = array(
		'id'   => 'showcase-anna',
		'name' => 'Anna',
		'kind' => ShowcaseImages::MOCKUP,
	);

	private Plugin $plugin;

	private HttpRecorder $recorder;

	public function set_up(): void {
		parent::set_up();
		$this->plugin   = Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' );
		$this->recorder = ( new HttpRecorder( Hosts::server_api() ) )->start();
		$this->plugin->connection_store()->save( self::connection() );
		( new CatalogueCache( $this->plugin->connection_store() ) )->flush();
		wp_set_current_user( $this->user( 'shop_manager' ) );
	}

	public function tear_down(): void {
		$this->recorder->stop();
		$this->plugin->connection_store()->clear();
		parent::tear_down();
	}

	public function test_it_reads_the_choices_and_the_views_and_photos_to_choose_from(): void {
		$product = $this->product();
		ShowcaseImages::save( $product, array( self::ANNA + array( 'view' => self::VIEW ) ) );
		$this->answer_mockups();
		$this->recorder->raw( 200, self::template_with( array( self::ANNA ) ), 'application/json' );

		$answer = $this->succeed( self::GET, array( 'product_id' => $product->get_id() ) );

		$this->assertNull( $answer['picture_view'] );
		$this->assertSame(
			array(
				array(
					'id'   => self::ANNA['id'],
					'name' => self::ANNA['name'],
				),
			),
			$answer['available_showcases']
		);
		$this->assertSame(
			array(
				array(
					'id'    => 'showcase-anna',
					'name'  => 'Anna',
					'kind'  => ShowcaseImages::MOCKUP,
					'view'  => self::VIEW,
					'photo' => null,
					'gone'  => false,
				),
			),
			$answer['showcase_images']
		);
		$this->assertSame( self::VIEW, $answer['views'][0]['id'] );
		$this->assertSame( self::PHOTO, $answer['views'][0]['photos'][0]['id'] );
		$this->assertCount( 1, $answer['views'][0]['photos'], 'the photo still uploading is left out' );
	}

	public function test_it_sets_the_photo_of_the_image_without_a_set_and_finds_its_view(): void {
		$product = $this->product();
		$this->answer_mockups();

		$answer = $this->succeed(
			self::SET,
			array(
				'product_id' => $product->get_id(),
				'photo'      => self::PHOTO,
			)
		);

		$this->assertSame( self::choice(), PictureView::on( $this->reload( $product ) ) );
		$this->assertSame( self::PHOTO, $answer['picture_view']['photo'] );
	}

	public function test_it_sets_a_chosen_sets_photo_and_set_product_showcase_keeps_it(): void {
		$product = $this->product();
		ShowcaseImages::save( $product, array( self::ANNA ) );
		$this->answer_mockups();

		$this->succeed(
			self::SET,
			array(
				'product_id' => $product->get_id(),
				'showcase'   => 'showcase-anna',
				'view'       => self::VIEW,
				'photo'      => self::PHOTO,
			)
		);
		$this->assertSame(
			array(
				self::ANNA + array(
					'view'  => self::VIEW,
					'photo' => self::PHOTO,
				),
			),
			ShowcaseImages::on( $this->reload( $product ) )
		);

		$this->recorder->raw( 200, self::template_with( array( self::ANNA ) ), 'application/json' );
		$this->succeed(
			Abilities::NAMESPACE . '/set-product-showcase',
			array(
				'product_id' => $product->get_id(),
				'images'     => array( array( 'id' => 'showcase-anna' ) ),
			)
		);
		$this->assertSame(
			array(
				self::ANNA + array(
					'view'  => self::VIEW,
					'photo' => self::PHOTO,
				),
			),
			ShowcaseImages::parse( ShowcaseImages::document_on( $this->reload( $product ) ) ),
			'kept by set-product-showcase'
		);
	}

	public function test_set_product_showcase_shows_one_set_on_several_views_in_the_order_named(): void {
		$product = $this->product();
		$this->recorder->raw( 200, self::template_with( array( self::ANNA ) ), 'application/json' );
		$this->answer_mockups_with_scene();

		$this->succeed(
			self::SHOWCASE,
			array(
				'product_id' => $product->get_id(),
				'images'     => array(
					array(
						'id'   => self::ANNA['id'],
						'view' => null,
					),
					array(
						'id'   => self::ANNA['id'],
						'view' => self::VIEW,
					),
					array(
						'id'    => self::ANNA['id'],
						'photo' => self::SCENE_PHOTO,
					),
					array(
						'id'   => self::ANNA['id'],
						'kind' => ShowcaseImages::PREVIEW,
					),
				),
			)
		);

		$this->assertSame(
			array(
				self::ANNA,
				self::ANNA + array( 'view' => self::VIEW ),
				self::ANNA + array(
					'view'  => self::SCENE_VIEW,
					'photo' => self::SCENE_PHOTO,
				),
				array_replace( self::ANNA, array( 'kind' => ShowcaseImages::PREVIEW ) ),
			),
			ShowcaseImages::on( $this->reload( $product ) )
		);

		$read = $this->succeed( self::GET, array( 'product_id' => $product->get_id() ) );
		$this->assertSame( array( null, self::VIEW, self::SCENE_VIEW, null ), array_column( $read['showcase_images'], 'view' ) );
		$this->assertSame( array( self::VIEW, self::SCENE_VIEW ), array_column( $read['views'], 'id' ) );
	}

	public function test_set_product_showcase_refuses_a_view_the_blank_does_not_have_and_changes_nothing(): void {
		$product = $this->product();
		ShowcaseImages::save( $product, array( self::ANNA ) );
		$this->recorder->raw( 200, self::template_with( array( self::ANNA ) ), 'application/json' );
		$this->answer_mockups_with_scene();

		$answer = $this->ability( self::SHOWCASE )->execute(
			array(
				'product_id' => $product->get_id(),
				'images'     => array(
					array(
						'id'   => self::ANNA['id'],
						'view' => 'view-elsewhere',
					),
				),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $answer );
		$this->assertSame( 'pixonaut_wc_ability_unknown_picture_view', $answer->get_error_code() );
		$this->assertStringContainsString( self::SCENE_VIEW . ' (' . self::SCENE_PHOTO . ')', $answer->get_error_message() );
		$this->assertSame( array( self::ANNA ), ShowcaseImages::on( $this->reload( $product ) ) );
	}

	public function test_a_set_shown_on_two_views_is_not_changed_one_image_at_a_time(): void {
		$product = $this->product();
		$both    = array( self::ANNA, self::ANNA + array( 'view' => self::VIEW ) );
		ShowcaseImages::save( $product, $both );

		$answer = $this->ability( self::SET )->execute(
			array(
				'product_id' => $product->get_id(),
				'showcase'   => self::ANNA['id'],
				'kind'       => PictureView::PREVIEW,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $answer );
		$this->assertSame( 'pixonaut_wc_ability_showcase_shown_twice', $answer->get_error_code() );
		$this->assertStringContainsString( 'set-product-showcase', $answer->get_error_message() );
		$this->assertSame( $both, ShowcaseImages::on( $this->reload( $product ) ) );
	}

	public function test_a_flat_choice_is_not_checked_against_the_views(): void {
		$product = $this->product();
		$this->answer_mockups();

		$this->succeed(
			self::SET,
			array(
				'product_id' => $product->get_id(),
				'kind'       => PictureView::PREVIEW,
			)
		);

		$this->assertSame( array( 'kind' => PictureView::PREVIEW ), PictureView::on( $this->reload( $product ) ) );
		$this->assertCount( 1, $this->recorder->requests, 'only the answer reads the views' );
	}

	public function test_a_photo_the_blank_does_not_have_is_refused_naming_those_it_has(): void {
		$product = $this->product();
		$this->answer_mockups();

		$answer = $this->ability( self::SET )->execute(
			array(
				'product_id' => $product->get_id(),
				'photo'      => 'mock-elsewhere',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $answer );
		$this->assertSame( 'pixonaut_wc_ability_unknown_picture_view', $answer->get_error_code() );
		$this->assertStringContainsString( self::VIEW . ' (' . self::PHOTO . ')', $answer->get_error_message() );
		$this->assertNull( PictureView::on( $this->reload( $product ) ) );
	}

	public function test_a_set_the_product_does_not_show_is_refused(): void {
		$product = $this->product();

		$answer = $this->ability( self::SET )->execute(
			array(
				'product_id' => $product->get_id(),
				'showcase'   => 'showcase-anna',
				'kind'       => PictureView::PREVIEW,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $answer );
		$this->assertSame( 'pixonaut_wc_ability_unknown_showcase', $answer->get_error_code() );
	}

	public function test_a_variation_sets_its_own_photo_and_follows_its_product_again_without_one(): void {
		$variation = $this->variation();
		$this->answer_mockups();

		$this->succeed(
			self::SET,
			array(
				'product_id' => $variation->get_id(),
				'view'       => self::VIEW,
				'photo'      => self::PHOTO,
			)
		);
		$this->assertSame( self::choice(), PictureView::on( $this->reload( $variation ) ) );

		$this->succeed( self::SET, array( 'product_id' => $variation->get_id() ) );
		$this->assertNull( PictureView::on( $this->reload( $variation ) ) );
	}

	public function test_a_variation_is_not_drawn_flat(): void {
		$variation = $this->variation();

		$answer = $this->ability( self::SET )->execute(
			array(
				'product_id' => $variation->get_id(),
				'kind'       => PictureView::PREVIEW,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $answer );
		$this->assertSame( 'pixonaut_wc_ability_variation_picture_view', $answer->get_error_code() );
	}

	public function test_both_are_refused_to_a_user_who_may_not_edit_the_product(): void {
		$product = $this->product();
		wp_set_current_user( $this->user( 'customer' ) );

		foreach ( array( self::GET, self::SET ) as $name ) {
			$answer = $this->ability( $name )->execute( array( 'product_id' => $product->get_id() ) );
			$this->assertInstanceOf( WP_Error::class, $answer, $name );
			$this->assertSame( 'ability_invalid_permissions', $answer->get_error_code(), $name );
		}
		$this->assertSame( array(), $this->recorder->requests );
	}

	public function test_the_read_is_marked_read_only_and_the_set_as_changing_the_product(): void {
		$this->assertTrue( $this->ability( self::GET )->get_meta_item( 'annotations' )['readonly'] );
		$this->assertFalse( $this->ability( self::SET )->get_meta_item( 'annotations' )['readonly'] );
		$this->assertTrue( $this->ability( self::SET )->get_meta_item( 'annotations' )['destructive'] );
	}

	private function answer_mockups(): void {
		$this->recorder->json( 200, BlankFixture::mockups( Hosts::server_api() ) );
	}

	/**
	 * Answers the blank's views with a second one, a scene's, after the fixture's.
	 */
	private function answer_mockups_with_scene(): void {
		$mockups = BlankFixture::mockups( Hosts::server_api() );
		$front   = $mockups['placements'][0]['views'][0];

		$mockups['placements'][0]['views'][] = array(
			'id'     => self::SCENE_VIEW,
			'label'  => 'Breakfast table',
			'photos' => array( array( 'id' => self::SCENE_PHOTO ) + $front['photos'][0] ),
		) + $front;
		$this->recorder->json( 200, $mockups );
	}

	/**
	 * A `GET /v1/templates/{id}` answer offering the sets.
	 *
	 * @param list<array{id: string, name: string}> $sets The sets.
	 */
	private static function template_with( array $sets ): string {
		return OpenApiAssert::example(
			'GET',
			'/v1/templates/{id}',
			200,
			array(
				'showcases' => array_map(
					static fn ( array $set ): array => array(
						'id'         => $set['id'],
						'name'       => $set['name'],
						'previewUrl' => Hosts::server_api() . '/i/' . $set['id'],
					),
					$sets
				),
			)
		);
	}

	/**
	 * @return array{kind: string, view: string, photo: string}
	 */
	private static function choice(): array {
		return array(
			'kind'  => PictureView::MOCKUP,
			'view'  => self::VIEW,
			'photo' => self::PHOTO,
		);
	}

	/**
	 * Runs an ability that is to succeed.
	 *
	 * @param string       $name  The ability.
	 * @param array<mixed> $input Its input.
	 * @return array<string, mixed>
	 */
	private function succeed( string $name, array $input ): array {
		$answer = $this->ability( $name )->execute( $input );
		$this->assertIsArray( $answer, $answer instanceof WP_Error ? $answer->get_error_message() : $name );
		return $answer;
	}

	private function ability( string $name ): WP_Ability {
		$ability = wp_get_ability( $name );
		$this->assertInstanceOf( WP_Ability::class, $ability, $name );
		return $ability;
	}

	private function product(): WC_Product_Simple {
		$product = new WC_Product_Simple();
		$product->set_name( 'Personalised tee' );
		$product->save();
		self::config()->save_to( $product );
		$read = $this->reload( $product );
		$this->assertInstanceOf( WC_Product_Simple::class, $read );
		return $read;
	}

	private function variation(): WC_Product {
		$product   = new WC_Product_Variable();
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'Colour' );
		$attribute->set_options( array( 'Red' ) );
		$attribute->set_variation( true );
		$product->set_attributes( array( $attribute ) );
		$product->save();
		self::config()->save_to( $product );
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $product->get_id() );
		$variation->set_attributes( array( 'colour' => 'Red' ) );
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

	private function user( string $role ): int {
		$id = self::factory()->user->create( array( 'role' => $role ) );
		return is_int( $id ) ? $id : 0;
	}

	private static function connection(): StoredConnection {
		return new StoredConnection(
			'site_fixture',
			home_url(),
			self::CONNECTED_AT,
			'tenant_fixture',
			'Fixture Prints',
			StoredConnection::LIVE,
			'conn_main',
			'ik_live_fixture_main',
			'ik_id',
			'WooCommerce · example.org',
			'pk_live_fixture_main',
			'pk_id',
			array( 'http://example.org' ),
			'wh_fixture_main',
			'http://example.org/wp-json/pixonaut/v1/webhook',
			'whsec_fixture_main'
		);
	}
}
