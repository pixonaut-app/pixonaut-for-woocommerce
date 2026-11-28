<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Abilities;

use Pixonaut\WooCommerce\Abilities\Abilities;
use Pixonaut\WooCommerce\Abilities\SetProductSetup;
use Pixonaut\WooCommerce\Abilities\SetupFields;
use Pixonaut\WooCommerce\Admin\Notices;
use Pixonaut\WooCommerce\Api\Catalogue;
use Pixonaut\WooCommerce\Api\Hosts;
use Pixonaut\WooCommerce\Catalogue\InvalidReason;
use Pixonaut\WooCommerce\Catalogue\PageTemplate;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Tests\Support\BlankFixture;
use Pixonaut\WooCommerce\Tests\Support\FreshRequest;
use Pixonaut\WooCommerce\Tests\Support\HttpRecorder;
use Pixonaut\WooCommerce\Tests\Support\OpenApiAssert;
use Pixonaut\WooCommerce\Tests\Support\VariantsAnswer;
use Pixonaut\WooCommerce\Tests\Support\VerdictAnswers;
use ReflectionProperty;
use WC_Product;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_Ability;
use WP_Error;
use WP_UnitTestCase;

/**
 * `set-product-setup` gives a side printed as pages its page layouts, on a
 * product and on a variation of its own, and refuses layouts that cannot be
 * offered rather than saving them; the setup read gives them back as
 * versions, as it gives the side's template.
 */
final class PageTemplatesAbilitiesTest extends WP_UnitTestCase {

	private const SET_SETUP = Abilities::NAMESPACE . '/set-product-setup';

	private const BLANK_ID = 'blank_tee';

	private const TEMPLATE_ID = 'mini-tee@2';

	private const TWO_PHOTOS = 'two-photos';

	private const FOUR_PHOTOS = 'four-photos';

	private const CONNECTED_AT = 1767225600;

	/**
	 * A page of the book, at the spec's example template's ratio (280 × 360), in millimetres.
	 */
	private const PAGE_WIDTH_MM = 280;

	private const PAGE_HEIGHT_MM = 360;

	/**
	 * The page counts the fixture book's pages are printed at.
	 */
	private const PAGES = array(
		'min'  => 24,
		'max'  => 48,
		'step' => 4,
	);

	private Plugin $plugin;

	private HttpRecorder $recorder;

	private VariantsAnswer $variants;

	public function set_up(): void {
		parent::set_up();
		$this->plugin   = Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' );
		$this->recorder = ( new HttpRecorder( Hosts::server_api() ) )->start();
		$this->variants = ( new VariantsAnswer() )->start();
		$this->plugin->connection_store()->save( self::connection() );
		BlankFixture::remember_printshops( $this->plugin->catalogue_cache() );
		FreshRequest::begin( $this->plugin );
		wp_set_current_user( $this->user( 'shop_manager' ) );
	}

	public function tear_down(): void {
		$this->forget_live_versions();
		$this->recorder->stop();
		$this->variants->stop();
		$this->plugin->connection_store()->clear();
		delete_option( Notices::OPTION );
		parent::tear_down();
	}

	public function test_a_side_printed_as_pages_is_given_its_page_layouts_a_layout_id_alone_on_its_live_version(): void {
		$product = $this->product();
		$this->recorder->json( 200, self::live_templates( array( self::FOUR_PHOTOS => 3 ) ) );
		$this->script_book( array( self::TWO_PHOTOS . '@2', self::FOUR_PHOTOS . '@3' ) );

		$answer = $this->succeed( $this->book_input( $product->get_id(), array( self::TWO_PHOTOS . '@2', self::FOUR_PHOTOS ) ) );

		$this->assertTrue( $answer['valid'], (string) $answer['invalid_reason'] );
		$this->assertSame( array( self::TWO_PHOTOS . '@2', self::FOUR_PHOTOS . '@3' ), $answer['sides'][0]['page_templates'] );
		$this->assertSame( array( self::TWO_PHOTOS, self::FOUR_PHOTOS ), array_map( static fn ( PageTemplate $layout ): string => $layout->base_id, ProductConfig::stored_on( $this->reload( $product->get_id() ) )->sides[0]->page_templates ) );
	}

	public function test_a_variation_of_its_own_is_given_page_layouts_too(): void {
		$product   = $this->variable_product();
		$variation = $this->variation( $product );
		$this->script_book( array( self::TWO_PHOTOS . '@2' ) );

		$answer = $this->succeed( $this->book_input( $variation->get_id(), array( self::TWO_PHOTOS . '@2' ) ) );

		$this->assertTrue( $answer['own_setup'] );
		$this->assertTrue( $answer['valid'], (string) $answer['invalid_reason'] );
		$this->assertSame( array( self::TWO_PHOTOS . '@2' ), $answer['sides'][0]['page_templates'] );
	}

	public function test_page_layouts_on_a_placement_not_printed_as_pages_are_refused_and_nothing_is_saved(): void {
		$product = $this->product();
		$this->recorder->json( 200, BlankFixture::answer( array( 'front' => 'Front' ) ) );
		$this->recorder->json( 200, array( 'verdicts' => array( VerdictAnswers::LEGAL ) ) );

		$refused = $this->refused( $this->book_input( $product->get_id(), array( self::TWO_PHOTOS . '@2' ) ) );

		$this->assertSame( InvalidReason::PAGE_TEMPLATES_NOT_PAGED, $refused->get_error_data()['reason'] ?? null );
		$this->assertNull( ProductConfig::document_on( $this->reload( $product->get_id() ) ) );
	}

	public function test_a_layout_named_twice_the_sides_own_template_or_too_many_layouts_are_refused(): void {
		$product = $this->product();
		$cases   = array(
			InvalidReason::DUPLICATE_PAGE_TEMPLATE => array( self::TWO_PHOTOS . '@2', self::TWO_PHOTOS . '@1' ),
			// The side's own template, which every page starts on.
			InvalidReason::DUPLICATE_PAGE_TEMPLATE . ' own' => array( self::TEMPLATE_ID ),
			InvalidReason::TOO_MANY_PAGE_TEMPLATES => array_map( static fn ( int $n ): string => 'layout-' . $n . '@1', range( 1, PageTemplate::MAX + 1 ) ),
		);
		foreach ( $cases as $case => $layouts ) {
			$this->recorder->json( 200, self::paged_blank() );
			$this->recorder->json( 200, array( 'verdicts' => array( VerdictAnswers::LEGAL ) ) );

			$refused = $this->refused( $this->book_input( $product->get_id(), $layouts ) );

			$this->assertSame( explode( ' ', $case )[0], $refused->get_error_data()['reason'] ?? null, $case );
		}
		$this->assertNull( ProductConfig::document_on( $this->reload( $product->get_id() ) ) );
	}

	public function test_a_layout_at_another_ratio_than_the_sides_template_is_refused(): void {
		$product = $this->product();
		$this->recorder->json( 200, self::paged_blank() );
		$this->recorder->json( 200, array( 'verdicts' => array( VerdictAnswers::LEGAL ) ) );
		$this->script_read( self::TEMPLATE_ID );
		$this->script_read(
			self::TWO_PHOTOS . '@2',
			array(
				'w' => 1,
				'h' => 1,
			)
		);

		$refused = $this->refused( $this->book_input( $product->get_id(), array( self::TWO_PHOTOS . '@2' ) ) );

		$this->assertSame( InvalidReason::PAGE_TEMPLATE_RATIO, $refused->get_error_data()['reason'] ?? null );
		$this->assertSame( SetProductSetup::PAGE_TEMPLATES_REFUSED, $refused->get_error_code() );
	}

	/**
	 * Forgets the live versions the ability read, which it keeps for the
	 * rest of the request: the ability lives for the whole PHPUnit run, and
	 * a later test's template would not be among them.
	 */
	private function forget_live_versions(): void {
		$callback = ( new ReflectionProperty( WP_Ability::class, 'execute_callback' ) )->getValue( $this->ability() );
		$ability  = is_array( $callback ) ? $callback[0] : null;
		if ( $ability instanceof SetProductSetup ) {
			$fields = ( new ReflectionProperty( SetProductSetup::class, 'fields' ) )->getValue( $ability );
			( new ReflectionProperty( SetupFields::class, 'live' ) )->setValue( $fields, null );
		}
	}

	/**
	 * Scripts a book's setup checked: the blank with its front printed as
	 * pages, the page size's verdict, and the side's template and each
	 * layout read, live, at the side's ratio.
	 *
	 * @param string[] $layouts Each layout's version, in order.
	 * @phpstan-param list<string> $layouts
	 */
	private function script_book( array $layouts ): void {
		$this->recorder->json( 200, self::paged_blank() );
		$this->recorder->json( 200, array( 'verdicts' => array( VerdictAnswers::LEGAL ) ) );
		$this->script_read( self::TEMPLATE_ID );
		foreach ( $layouts as $layout ) {
			$this->script_read( $layout );
		}
	}

	/**
	 * Scripts `GET /v1/templates/{id}` of a live version.
	 *
	 * @param string                     $id    `<baseId>@<version>`.
	 * @param array{w: int, h: int}|null $ratio Its ratio; null for the page's.
	 */
	private function script_read( string $id, ?array $ratio = null ): void {
		// Read as objects, so the example's empty objects stay objects.
		$read                  = json_decode( OpenApiAssert::example( 'GET', '/v1/templates/{id}', 200, array( 'status' => Catalogue::LIVE ) ) );
		$read->template->id    = $id;
		$read->template->ratio = (object) ( $ratio ?? array(
			'w' => self::PAGE_WIDTH_MM,
			'h' => self::PAGE_HEIGHT_MM,
		) );
		$this->recorder->raw( 200, (string) wp_json_encode( $read ), 'application/json' );
	}

	/**
	 * The blank with its front printed as pages.
	 *
	 * @return array<string, mixed>
	 */
	private static function paged_blank(): array {
		$blank                           = BlankFixture::answer( array( 'front' => 'Front' ) );
		$blank['placements'][0]['pages'] = self::PAGES;
		return $blank;
	}

	/**
	 * A book's setup as `set-product-setup` takes it.
	 *
	 * @param int      $product_id The product or variation.
	 * @param string[] $layouts    Its page layouts.
	 * @phpstan-param list<string> $layouts
	 * @return array<string, mixed>
	 */
	private function book_input( int $product_id, array $layouts ): array {
		return array(
			'product_id' => $product_id,
			'blank_id'   => self::BLANK_ID,
			'sides'      => array(
				array(
					'key'            => 'pages',
					'template_id'    => self::TEMPLATE_ID,
					'placement_id'   => 'front',
					'print_sizes'    => array(
						array(
							'width_mm'  => self::PAGE_WIDTH_MM,
							'height_mm' => self::PAGE_HEIGHT_MM,
						),
					),
					'page_templates' => $layouts,
				),
			),
		);
	}

	/**
	 * A `GET /v1/templates?status=live` answer listing templates at their latest versions.
	 *
	 * @param array<string, int> $versions Latest versions, by base id.
	 * @return array<string, mixed>
	 */
	private static function live_templates( array $versions ): array {
		$items = array();
		foreach ( $versions as $base_id => $version ) {
			$items[] = array(
				'id'                   => $base_id . '@' . $version,
				'baseId'               => $base_id,
				'name'                 => $base_id,
				'latestVersion'        => $version,
				'ratio'                => array(
					'w' => self::PAGE_WIDTH_MM,
					'h' => self::PAGE_HEIGHT_MM,
				),
				'ratioTolerance'       => 0,
				'fieldKeys'            => array(),
				'previewUrl'           => '',
				'offersProperties'     => true,
				'backgroundSeeThrough' => null,
			);
		}
		return array(
			'items'    => $items,
			'total'    => count( $items ),
			'page'     => 1,
			'pageSize' => Catalogue::LIST_PAGE_SIZE,
		);
	}

	/**
	 * @param array<mixed> $input The ability's input.
	 * @return array<string, mixed>
	 */
	private function succeed( array $input ): array {
		$answer = $this->ability()->execute( $input );
		$this->assertIsArray( $answer, $answer instanceof WP_Error ? $answer->get_error_message() : '' );
		return $answer;
	}

	/**
	 * @param array<mixed> $input The ability's input.
	 */
	private function refused( array $input ): WP_Error {
		$answer = $this->ability()->execute( $input );
		$this->assertInstanceOf( WP_Error::class, $answer );
		return $answer;
	}

	private function ability(): WP_Ability {
		$ability = wp_get_ability( self::SET_SETUP );
		$this->assertInstanceOf( WP_Ability::class, $ability );
		return $ability;
	}

	private function product(): WC_Product_Simple {
		$product = new WC_Product_Simple();
		$product->set_name( 'Photo book' );
		$product->set_regular_price( '25' );
		$product->save();
		return $product;
	}

	private function variable_product(): WC_Product_Variable {
		$this->variants->answer = BlankFixture::variants(
			array(
				array(
					'ref'     => 'v_s',
					'options' => array( 'Size' => 'S' ),
				),
			),
			array( 'Size' )
		);
		$product                = new WC_Product_Variable();
		$attribute              = new \WC_Product_Attribute();
		$attribute->set_name( 'Size' );
		$attribute->set_options( array( 'S' ) );
		$attribute->set_variation( true );
		$product->set_name( 'Photo book' );
		$product->set_attributes( array( $attribute ) );
		$product->save();
		( new ProductConfig( personalise: true, blank_id: self::BLANK_ID, sides: array( new Side( 'pages', 'Pages', 'front', 'Front', 'mini-tee', 2, null, 'Mini tee' ) ) ) )->save_to( $product );
		return $product;
	}

	private function variation( WC_Product_Variable $product ): WC_Product_Variation {
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $product->get_id() );
		$variation->set_attributes( array( 'size' => 'S' ) );
		$variation->set_regular_price( '25' );
		$variation->save();
		return $variation;
	}

	private function reload( int $product_id ): WC_Product {
		wp_cache_delete( (string) $product_id, 'post_meta' );
		$product = wc_get_product( $product_id );
		$this->assertInstanceOf( WC_Product::class, $product );
		return $product;
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
