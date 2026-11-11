<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Catalogue;

use Pixonaut\WooCommerce\Admin\BlankFields;
use Pixonaut\WooCommerce\Admin\CatalogueProducts;
use Pixonaut\WooCommerce\Admin\LinkedBlank;
use Pixonaut\WooCommerce\Admin\ProductPanel;
use Pixonaut\WooCommerce\Admin\VariantNotice;
use Pixonaut\WooCommerce\Admin\VariantPairing;
use Pixonaut\WooCommerce\Api\Deprecations;
use Pixonaut\WooCommerce\Api\HookListener;
use Pixonaut\WooCommerce\Api\Pixonaut;
use Pixonaut\WooCommerce\Api\PrintshopCatalogue;
use Pixonaut\WooCommerce\Api\WcLogger;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ProductVariant;
use Pixonaut\WooCommerce\Catalogue\ProductVariants;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Tests\Support\BlankFixture;
use Pixonaut\WooCommerce\Tests\Support\ScriptedClient;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_REST_Request;
use WP_REST_Response;

/**
 * A product printed by an integrated printshop is linked by picking the
 * printshop's own product: the plugin finds the blank Pixonaut imported it
 * as, or imports it, and pairs each variation with the variant its size
 * names, however the store wrote the size.
 */
final class CatalogueProductLinkTest extends ProductPanelTestCase {

	private const LEGAL = array( 'legal' => true );

	private const PRODUCT_ID = 'gelato_poster';

	private const PRODUCT_NAME = 'Classic poster';

	private const IMPORTED_ID = 'blank_poster';

	private const AXIS = 'Size';

	private CatalogueProducts $products;

	public function set_up(): void {
		parent::set_up();
		$client         = ( new Pixonaut( ScriptedClient::credentials(), new WcLogger(), new HookListener( new Deprecations() ), null, self::BASE_URL ) )->sdk();
		$this->products = new CatalogueProducts( new PrintshopCatalogue( $client ) );
		remove_action( 'rest_api_init', array( $this->plugin->catalogue_products(), 'register_routes' ) );
		$this->products->register();
		$variant_field = $this->variant_field();
		$this->panel   = new ProductPanel( $this->linker, new LinkedBlank( $this->catalogue, $this->cache ), $this->plugin->notices(), $this->plugin->templates(), $this->plugin->settings(), $this->plugin->file(), $variant_field, null, null, $this->plugin->proof_urls(), null, new VariantPairing( new ProductVariants( $this->catalogue, new VariantNotice( $this->plugin->notices() ) ), new LinkedBlank( $this->catalogue, $this->cache ), $variant_field, $this->plugin->templates() ) );
		$GLOBALS['wp_rest_server'] = new \WP_REST_Server();
		do_action( 'rest_api_init', $GLOBALS['wp_rest_server'] );
		$this->variants->answer = self::poster_variants();
	}

	public function tear_down(): void {
		remove_action( 'rest_api_init', array( $this->products, 'register_routes' ) );
		add_action( 'rest_api_init', array( $this->plugin->catalogue_products(), 'register_routes' ) );
		parent::tear_down();
	}

	public function test_the_catalogue_search_lists_the_printshops_products_and_what_pixonaut_has(): void {
		wp_set_current_user( $this->user( 'shop_manager' ) );
		$this->recorder->json( 200, self::catalogue( self::IMPORTED_ID ) );

		$response = $this->request(
			'GET',
			CatalogueProducts::SEARCH_ROUTE,
			array(
				'connection' => self::INTEGRATED_ID,
				'q'          => 'poster',
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'results'    => array(
					array(
						'id'        => self::PRODUCT_ID,
						'text'      => self::PRODUCT_NAME,
						'thumbnail' => '',
						'note'      => '3 variants · Imported',
						'blank'     => self::IMPORTED_ID,
					),
				),
				'pagination' => array( 'more' => false ),
			),
			$response->get_data()
		);
		$this->assertStringContainsString( 'connectionId=' . self::INTEGRATED_ID, $this->recorder->requests[0]['url'] );
		$this->assertStringContainsString( 'search=poster', $this->recorder->requests[0]['url'] );
	}

	public function test_a_product_not_imported_yet_is_imported_as_a_blank(): void {
		wp_set_current_user( $this->user( 'shop_manager' ) );
		$this->recorder->json( 202, self::imported( 'importing' ) );

		$response = $this->pick();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'id'        => self::IMPORTED_ID,
				'text'      => self::PRODUCT_NAME,
				'importing' => true,
			),
			$response->get_data()
		);
		$this->assertSame( array( 'POST' ), array_column( $this->recorder->requests, 'method' ) );
		$this->assertStringContainsString( '/v1/printshop-catalogue-products/' . self::PRODUCT_ID . '/blank?connectionId=' . self::INTEGRATED_ID, $this->recorder->requests[0]['url'] );
	}

	public function test_a_product_imported_before_reuses_its_blank(): void {
		wp_set_current_user( $this->user( 'shop_manager' ) );
		$this->recorder->json( 200, self::imported( 'ready' ) );

		$this->assertSame(
			array(
				'id'        => self::IMPORTED_ID,
				'text'      => self::PRODUCT_NAME,
				'importing' => false,
			),
			$this->pick()->get_data()
		);
	}

	public function test_a_product_imported_before_answered_without_a_body_is_found_in_the_catalogue(): void {
		wp_set_current_user( $this->user( 'shop_manager' ) );
		$this->recorder->empty_body( 200 );
		$this->recorder->json( 200, self::catalogue( self::IMPORTED_ID ) );

		$this->assertSame( self::IMPORTED_ID, $this->pick()->get_data()['id'] );
	}

	public function test_an_import_refused_for_its_name_says_which_blank_has_it(): void {
		wp_set_current_user( $this->user( 'shop_manager' ) );
		$this->recorder->json( 409, array( 'error' => 'duplicate_name' ) );

		$response = $this->pick();

		$this->assertSame( 422, $response->get_status() );
		$this->assertStringStartsWith( 'Pixonaut already has a blank called Classic poster.', $this->error_message( $response ) );
	}

	public function test_a_product_with_nothing_to_print_on_says_so(): void {
		wp_set_current_user( $this->user( 'shop_manager' ) );
		$this->recorder->json( 422, array( 'error' => 'no_printable_placement' ) );

		$response = $this->pick();

		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'Pixonaut cannot print on this product: it has no print position.', $this->error_message( $response ) );
	}

	public function test_only_someone_who_may_edit_products_picks_one(): void {
		wp_set_current_user( $this->user( 'customer' ) );

		$this->assertSame( 403, $this->pick()->get_status() );
		$this->assertSame( array(), $this->recorder->requests );
	}

	public function test_a_blank_still_importing_says_so_and_pairs_nothing_yet(): void {
		$parent = $this->poster_product( array( '50×70 cm' ) );
		wp_set_current_user( $this->user( 'shop_manager' ) );
		$this->recorder->json( 200, array( 'placements' => array() ) + self::poster_blank( 'importing' ) );
		$this->recorder->json( 200, $this->mockups( array() ) );
		$this->recorder->json( 200, self::printshops() );

		$parts = $this->blank_parts( $parent->get_id() );

		$this->assertStringContainsString( 'data-importing', $parts['blank_html'] );
		$this->assertStringContainsString( 'Importing Classic poster from Gelato into Pixonaut…', $parts['blank_html'] );
		$this->assertStringNotContainsString( 'pixonaut-variant-pairing', $parts['blank_html'] );
	}

	public function test_variations_pair_by_size_and_one_that_cannot_says_so_and_offers_the_variants(): void {
		$sizes  = array( '70×100 cm', '50 x 70 cm', 'A2' );
		$parent = $this->poster_product( $sizes );
		$by     = $this->variations_of( $parent );

		$this->assertTrue( $this->reload_any( $by['70×100 cm'] )->is_purchasable(), 'paired with nothing else entered' );
		$this->assertSame( 'v_70x100', ProductVariant::stored_on( $this->reload_any( $by['70×100 cm'] ) )?->ref );
		$this->assertSame( 'v_50x70', ProductVariant::stored_on( $this->reload_any( $by['50 x 70 cm'] ) )?->ref );
		$this->assertFalse( $this->reload_any( $by['A2'] )->is_purchasable(), 'no variant is A2' );

		wp_set_current_user( $this->user( 'shop_manager' ) );
		$this->recorder->json( 200, self::poster_blank() );
		$this->recorder->json( 200, $this->mockups() );
		$this->recorder->json( 200, self::printshops() );
		$this->recorder->json( 200, self::poster_blank() );
		$html = $this->blank_parts( $parent->get_id() )['blank_html'];

		$this->assertStringContainsString( '2 of 3 variations are paired with a Gelato variant.', $html );
		$this->assertMatchesRegularExpression( '/is-unpaired" data-variation="' . $by['A2'] . '".*No Gelato variant found: pick one\..*None until paired/s', $html, 'listed first, saying so' );
		$this->assertMatchesRegularExpression( '/<option value="v_30x40"\s*>30x40cm \/ 12x16&quot;<\/option>/', $html, 'offering the variants' );
		$this->assertStringContainsString( '28x40&quot; / 70x100cm (found automatically)', $html );
		$this->assertStringContainsString( '700 × 1000 mm', $html, 'each at its variant\'s print area' );
		$this->assertStringContainsString( '500 × 700 mm', $html );

		$this->script_link( self::LEGAL );
		$this->save(
			$parent->get_id(),
			array(
				VariantPairing::FIELD       => array(
					$by['A2']         => 'v_30x40',
					$by['70×100 cm'] => '',
				),
				VariantPairing::DRAWN_FIELD => array(
					$by['A2']         => '',
					$by['70×100 cm'] => '',
				),
				VariantPairing::BLANK_FIELD => self::BLANK_ID,
			)
		);

		$a2 = ProductVariant::stored_on( $this->reload_any( $by['A2'] ) );
		$this->assertSame( array( 'v_30x40', 'v_30x40' ), array( $a2?->pick, $a2?->ref ) );
		$this->assertTrue( $this->reload_any( $by['A2'] )->is_purchasable() );
		$this->assertNull( ProductVariant::stored_on( $this->reload_any( $by['70×100 cm'] ) )?->pick, 'a row left as it was is not saved as a pick' );
	}

	/**
	 * Asks the pick route for the poster.
	 */
	private function pick(): WP_REST_Response {
		return $this->request(
			'POST',
			CatalogueProducts::PICK_ROUTE,
			array(
				'connection' => self::INTEGRATED_ID,
				'product'    => self::PRODUCT_ID,
				'name'       => self::PRODUCT_NAME,
			)
		);
	}

	/**
	 * @param string               $method The method.
	 * @param string               $route  The route.
	 * @param array<string, mixed> $params Its parameters.
	 */
	private function request( string $method, string $route, array $params ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/' . Plugin::REST_NAMESPACE . $route );
		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_body_params( $params );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * The message of a route's error.
	 *
	 * @param WP_REST_Response $response The answer.
	 */
	private function error_message( WP_REST_Response $response ): string {
		$error = $response->as_error();
		$this->assertInstanceOf( \WP_Error::class, $error );
		return $error->get_error_message();
	}

	/**
	 * The blank fields route's parts for the product's saved blank.
	 *
	 * @param int $product_id The product.
	 * @return array{blank_html: string, views_html: string}
	 */
	private function blank_parts( int $product_id ): array {
		$response = $this->request(
			'GET',
			BlankFields::ROUTE,
			array(
				'product' => (string) $product_id,
				'blank'   => self::BLANK_ID,
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$parts = $response->get_data();
		$this->assertIsArray( $parts );
		$this->assertIsString( $parts['blank_html'] ?? null );
		$this->assertIsString( $parts['views_html'] ?? null );
		return array(
			'blank_html' => $parts['blank_html'],
			'views_html' => $parts['views_html'],
		);
	}

	/**
	 * A variable poster in the given sizes, linked to the poster's blank.
	 *
	 * @param string[] $sizes Its sizes as the store writes them.
	 */
	private function poster_product( array $sizes ): WC_Product_Variable {
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( self::AXIS );
		$attribute->set_options( $sizes );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$parent = new WC_Product_Variable();
		$parent->set_name( 'Name poster' );
		$parent->set_status( 'publish' );
		$parent->set_attributes( array( $attribute ) );
		$parent->save();
		foreach ( $sizes as $size ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $parent->get_id() );
			$variation->set_attributes( array( strtolower( self::AXIS ) => $size ) );
			$variation->set_regular_price( '30' );
			$variation->save();
		}
		WC_Product_Variable::sync( $parent->get_id() );
		$this->script_link( self::LEGAL );
		$this->save( $parent->get_id() );
		$saved = wc_get_product( $parent->get_id() );
		$this->assertInstanceOf( WC_Product_Variable::class, $saved );
		$this->assertSame( self::BLANK_ID, ProductConfig::stored_on( $saved )->blank_id );
		return $saved;
	}

	/**
	 * @param WC_Product $parent The variable product.
	 * @return array<string, int> Its variations' ids, by size.
	 */
	private function variations_of( WC_Product $parent ): array {
		$by = array();
		foreach ( $parent->get_children() as $child ) {
			$variation = wc_get_product( $child );
			$this->assertInstanceOf( WC_Product_Variation::class, $variation );
			$by[ (string) $variation->get_attribute( self::AXIS ) ] = $child;
		}
		return $by;
	}

	private function reload_any( int $product_id ): WC_Product {
		$product = wc_get_product( $product_id );
		$this->assertInstanceOf( WC_Product::class, $product );
		return $product;
	}

	/**
	 * A page of Gelato's catalogue holding the poster.
	 *
	 * @param string|null $imported The blank it was imported as; null for none.
	 * @return array<string, mixed>
	 */
	private static function catalogue( ?string $imported ): array {
		return array(
			'total'            => 1,
			'catalogueTotal'   => 2,
			'searchComplete'   => true,
			'searchedProducts' => null,
			'products'         => array(
				array(
					'productId'        => self::PRODUCT_ID,
					'name'             => self::PRODUCT_NAME,
					'brand'            => null,
					'imageUrl'         => null,
					'categoryId'       => null,
					'holds'            => array(
						'colours'  => null,
						'sizes'    => 3,
						'variants' => 3,
					),
					'hasImportedBlank' => null !== $imported,
					'importedBlankId'  => $imported,
				),
			),
		);
	}

	/**
	 * The import's answer.
	 *
	 * @param string $status The blank's status.
	 * @return array<string, mixed>
	 */
	private static function imported( string $status ): array {
		$blank = array(
			'id'         => self::IMPORTED_ID,
			'name'       => self::PRODUCT_NAME,
			'status'     => $status,
			'generation' => null,
		) + BlankFixture::answer( array(), self::INTEGRATED_ID );
		return array(
			'blank' => $blank,
			'job'   => array(
				'id'         => 'job_1',
				'kind'       => 'blank.import',
				'subjectId'  => self::IMPORTED_ID,
				'state'      => 'importing' === $status ? 'queued' : 'done',
				'attempts'   => 1,
				'runAt'      => 1,
				'parkReason' => null,
				'lastError'  => null,
				'lastRunAt'  => null,
				'createdAt'  => 1,
				'finishedAt' => null,
			),
		);
	}

	/**
	 * The poster's blank, on Gelato, its front's print area its size.
	 *
	 * @param string $status Its status.
	 * @return array<string, mixed>
	 */
	private static function poster_blank( string $status = 'ready' ): array {
		$blank                = BlankFixture::answer( array( self::PLACEMENT_ID => 'Front' ), self::INTEGRATED_ID );
		$blank['name']        = self::PRODUCT_NAME;
		$blank['status']      = $status;
		$blank['axes']        = array( self::AXIS );
		$blank['areasVaryBy'] = array( self::AXIS );
		$areas                = array();
		foreach ( self::POSTER_SIZES as $size => $mm ) {
			$areas[] = array(
				'row'           => array( self::AXIS => $size ),
				'widthMm'       => $mm[0],
				'heightMm'      => $mm[1],
				'usualWidthMm'  => $mm[0],
				'usualHeightMm' => $mm[1],
			);
		}
		$blank['placements'][0]['areas'] = $areas;
		return $blank;
	}

	/**
	 * The poster's sizes as Gelato writes them, with their print areas.
	 */
	private const POSTER_SIZES = array(
		'28x40" / 70x100cm' => array( 700, 1000 ),
		'20x30" / 50x70cm'  => array( 500, 700 ),
		'30x40cm / 12x16"'  => array( 300, 400 ),
	);

	/**
	 * The poster's variants.
	 *
	 * @return array<string, mixed>
	 */
	private static function poster_variants(): array {
		$refs = array( 'v_70x100', 'v_50x70', 'v_30x40' );
		$rows = array();
		foreach ( array_keys( self::POSTER_SIZES ) as $i => $size ) {
			$rows[] = array(
				'ref'     => $refs[ $i ],
				'options' => array( self::AXIS => $size ),
			);
		}
		$variants                = BlankFixture::variants( $rows, array( self::AXIS ) );
		$variants['areasVaryBy'] = array( self::AXIS );
		return $variants;
	}
}
