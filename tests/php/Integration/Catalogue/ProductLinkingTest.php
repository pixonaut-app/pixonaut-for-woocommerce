<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Catalogue;

use Pixonaut\WooCommerce\Admin\BlankFields;
use Pixonaut\WooCommerce\Admin\CatalogueSearch;
use Pixonaut\WooCommerce\Admin\LinkedBlank;
use Pixonaut\WooCommerce\Admin\PanelFields;
use Pixonaut\WooCommerce\Admin\ProductPanel;
use Pixonaut\WooCommerce\Api\Catalogue;
use Pixonaut\WooCommerce\ApprovalMode;
use Pixonaut\WooCommerce\Catalogue\InvalidReason;
use Pixonaut\WooCommerce\Catalogue\PostedSides;
use Pixonaut\WooCommerce\Catalogue\PrintSize;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\FormLayout;
use Pixonaut\WooCommerce\Layout;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Schema;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Tests\Support\BlankFixture;
use Pixonaut\WooCommerce\Tests\Support\OpenApiAssert;
use Pixonaut\WooCommerce\Tests\Support\ScriptedClient;
use WP_REST_Request;

final class ProductLinkingTest extends ProductPanelTestCase {

	/**
	 * When a member's archive says its template last changed, in epoch
	 * milliseconds; any time will do.
	 */
	private const ARCHIVE_UPDATED_AT = 1760486400000;

	/**
	 * @return array<string, array{string, array<string, string>}>
	 */
	public static function routes(): array {
		return array(
			'templates'         => array( CatalogueSearch::TEMPLATES_ROUTE, array() ),
			'blanks'            => array( CatalogueSearch::BLANKS_ROUTE, array() ),
			'fitting templates' => array(
				CatalogueSearch::FITTING_TEMPLATES_ROUTE,
				array(
					'blank'     => self::BLANK_ID,
					'placement' => self::PLACEMENT_ID,
				),
			),
			'placements'        => array( CatalogueSearch::PLACEMENTS_ROUTE, array( 'blank' => self::BLANK_ID ) ),
			'template'          => array( CatalogueSearch::TEMPLATE_ROUTE, array( 'id' => self::TEMPLATE_ID ) ),
		);
	}

	/**
	 * @dataProvider routes
	 *
	 * @param string                $route  Route below the namespace.
	 * @param array<string, string> $params Query parameters.
	 */
	public function test_searches_are_refused_to_visitors_and_customers( string $route, array $params ): void {
		$this->assertSame( 401, $this->get( $route, $params )->get_status() );

		wp_set_current_user( $this->user( 'customer' ) );
		$this->assertSame( 403, $this->get( $route, $params )->get_status() );

		$this->assertSame( array(), $this->recorder->requests );
	}

	public function test_template_search_lists_live_templates_with_their_previews_without_the_key(): void {
		wp_set_current_user( $this->user( 'shop_manager' ) );
		$this->recorder->json(
			200,
			array(
				'items'    => array(
					array(
						'id'                   => 'mini-tee@2',
						'baseId'               => 'mini-tee',
						'name'                 => 'Mini tee',
						'latestVersion'        => 2,
						'ratio'                => array(
							'w' => 280,
							'h' => 360,
						),
						'ratioTolerance'       => 0,
						'fieldKeys'            => array(),
						'previewUrl'           => self::BASE_URL . '/i/preview',
						'offersProperties'     => true,
						'backgroundSeeThrough' => null,
					),
				),
				'total'    => 1,
				'page'     => 1,
				'pageSize' => Catalogue::SEARCH_PAGE_SIZE,
			)
		);

		$response = $this->get( CatalogueSearch::TEMPLATES_ROUTE, array( 'q' => 'tee' ) );
		// The second search is answered from the cache: nothing more is scripted.
		$again = $this->get( CatalogueSearch::TEMPLATES_ROUTE, array( 'q' => 'tee' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'results'    => array(
					array(
						'id'               => self::TEMPLATE_ID,
						'text'             => 'Mini tee',
						'thumbnail'        => self::BASE_URL . '/i/preview',
						'offersProperties' => true,
					),
				),
				'pagination' => array( 'more' => false ),
			),
			$response->get_data()
		);
		$this->assertSame( $response->get_data(), $again->get_data() );
		$this->assertCount( 1, $this->recorder->requests );
		$this->assertStringContainsString( '/v1/templates?status=live&q=tee', $this->recorder->requests[0]['url'] );
		$this->assertStringNotContainsString( ScriptedClient::INTEGRATION_KEY, (string) wp_json_encode( $response->get_data() ) );
	}

	public function test_a_filter_changes_the_search_results_also_from_the_cache(): void {
		wp_set_current_user( $this->user( 'shop_manager' ) );
		$this->recorder->json(
			200,
			array(
				'items'    => array(
					array(
						'id'                   => 'mini-tee@2',
						'baseId'               => 'mini-tee',
						'name'                 => 'Mini tee',
						'latestVersion'        => 2,
						'ratio'                => array(
							'w' => 280,
							'h' => 360,
						),
						'ratioTolerance'       => 0,
						'fieldKeys'            => array(),
						'previewUrl'           => self::BASE_URL . '/i/preview',
						'offersProperties'     => true,
						'backgroundSeeThrough' => null,
					),
				),
				'total'    => 1,
				'page'     => 1,
				'pageSize' => Catalogue::SEARCH_PAGE_SIZE,
			)
		);
		$seen = array();
		add_filter(
			'pixonaut_wc_catalogue_search_results',
			static function ( array $results, string $what, array $args ) use ( &$seen ): array {
				$seen[] = array( $what, $args['q'] );
				return array_map( static fn ( array $option ): array => array( 'text' => strtoupper( $option['text'] ) ) + $option, $results );
			},
			10,
			3
		);

		$response = $this->get( CatalogueSearch::TEMPLATES_ROUTE, array( 'q' => 'tee' ) );
		$again    = $this->get( CatalogueSearch::TEMPLATES_ROUTE, array( 'q' => 'tee' ) );

		$this->assertSame( 'MINI TEE', $response->get_data()['results'][0]['text'] ?? null );
		$this->assertSame( $response->get_data(), $again->get_data() );
		$this->assertSame( array( array( 'templates', 'tee' ), array( 'templates', 'tee' ) ), $seen );
		$this->assertCount( 1, $this->recorder->requests );
	}

	public function test_a_failed_search_is_a_502_and_not_cached(): void {
		wp_set_current_user( $this->user( 'shop_manager' ) );
		$this->recorder->json( 500, array( 'error' => 'internal_error' ) );

		$this->assertSame( 502, $this->get( CatalogueSearch::BLANKS_ROUTE, array( 'q' => 'tee' ) )->get_status() );

		$this->recorder->json( 200, $this->blanks_answer() );
		$response = $this->get( CatalogueSearch::BLANKS_ROUTE, array( 'q' => 'tee' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				array(
					'id'          => self::PLACEMENT_ID,
					'text'        => 'Front',
					'maxWidthMm'  => 330.0,
					'maxHeightMm' => 420.0,
				),
			),
			$response->get_data()['results'][0]['placements']
		);
	}

	public function test_an_answer_without_its_list_is_a_502_and_not_cached(): void {
		wp_set_current_user( $this->user( 'shop_manager' ) );
		$this->recorder->off_spec( 200, array( 'total' => 0 ) );

		$this->assertSame( 502, $this->get( CatalogueSearch::BLANKS_ROUTE, array( 'q' => 'tee' ) )->get_status() );

		$this->recorder->json( 200, $this->blanks_answer() );
		$this->assertSame( 200, $this->get( CatalogueSearch::BLANKS_ROUTE, array( 'q' => 'tee' ) )->get_status() );
		$this->assertCount( 2, $this->recorder->requests );
	}

	/**
	 * The two shapes `GET /v1/templates` answers a studio member, which a key
	 * never gets: the whole archive, and one page of it.
	 *
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function member_archives(): array {
		$template = array(
			'baseId'               => 'mini-tee',
			'name'                 => 'Mini tee',
			'status'               => 'live',
			'latestVersion'        => 2,
			'versions'             => array( 1, 2 ),
			'hasDraft'             => false,
			'updatedAt'            => self::ARCHIVE_UPDATED_AT,
			'categoryId'           => null,
			'tags'                 => array(),
			'layerCount'           => 1,
			'ratio'                => array(
				'w' => 5,
				'h' => 6,
			),
			'ratioTolerance'       => 0,
			'offersProperties'     => true,
			'previewUrl'           => self::BASE_URL . '/i/preview',
			'backgroundSeeThrough' => null,
		);
		$counts   = array(
			'statuses'      => array( 'live' => 1 ),
			'categories'    => array(),
			'uncategorised' => 1,
			'tags'          => array(),
		);
		return array(
			'whole archive' => array(
				array(
					'templates' => array( $template ),
					'counts'    => $counts,
				),
			),
			'archive page'  => array(
				array(
					'templates' => array( $template + array( 'group' => null ) ),
					'counts'    => $counts,
					'facets'    => array(
						'statuses'   => array( 'all' => 1 ),
						'categories' => array(),
					),
					'groups'    => array(),
					'matched'   => 1,
					'total'     => 1,
					'page'      => 1,
					'pageSize'  => Catalogue::SEARCH_PAGE_SIZE,
				),
			),
		);
	}

	/**
	 * @dataProvider member_archives
	 *
	 * @param array<string, mixed> $archive A member's answer.
	 */
	public function test_a_members_template_archive_is_a_502_and_not_cached( array $archive ): void {
		wp_set_current_user( $this->user( 'shop_manager' ) );
		$this->recorder->json( 200, $archive );

		$this->assertSame( 502, $this->get( CatalogueSearch::TEMPLATES_ROUTE, array( 'q' => 'tee' ) )->get_status() );

		$this->recorder->json(
			200,
			array(
				'items'    => array(),
				'total'    => 0,
				'page'     => 1,
				'pageSize' => Catalogue::SEARCH_PAGE_SIZE,
			)
		);
		$this->assertSame( 200, $this->get( CatalogueSearch::TEMPLATES_ROUTE, array( 'q' => 'tee' ) )->get_status() );
		$this->assertCount( 2, $this->recorder->requests );
	}

	public function test_fitting_templates_page_through_pixonaut(): void {
		wp_set_current_user( $this->user( 'shop_manager' ) );
		// A page of only illegal templates, with more after it.
		$this->recorder->json(
			200,
			array(
				'items'    => array(
					array(
						'templateId'     => 'poster@1',
						'name'           => 'Poster',
						'status'         => 'live',
						'ratio'          => array(
							'w' => 420,
							'h' => 297,
						),
						'ratioTolerance' => 0,
						'printWidthMm'   => 300,
						'printHeightMm'  => 212,
						'verdict'        => array(
							'legal'  => false,
							'reason' => 'too_large',
						),
						'sizes'          => array(
							'fits'    => array(),
							'misfits' => array(),
						),
						'previewUrl'     => null,
					),
				),
				'total'    => Catalogue::SEARCH_PAGE_SIZE * 2 + 1,
				'page'     => 2,
				'pageSize' => Catalogue::SEARCH_PAGE_SIZE,
			)
		);

		$response = $this->get(
			CatalogueSearch::FITTING_TEMPLATES_ROUTE,
			array(
				'blank'     => self::BLANK_ID,
				'placement' => self::PLACEMENT_ID,
				'page'      => '2',
			)
		);

		$this->assertSame(
			array(
				'results'    => array(),
				'pagination' => array( 'more' => true ),
			),
			$response->get_data()
		);
		$this->assertStringContainsString( 'page=2&pageSize=' . Catalogue::SEARCH_PAGE_SIZE, $this->recorder->requests[0]['url'] );
	}

	public function test_a_failed_blank_read_keeps_the_choice_for_the_next_update(): void {
		$product = $this->product();
		$this->script_link( array( 'legal' => true ) );
		$this->save( $product->get_id() );
		$this->recorder->json( 500, array( 'error' => 'internal_error' ) );

		$this->save( $product->get_id() );

		$config = ProductConfig::stored_on( $this->reload( $product->get_id() ) );
		$this->assertSame( InvalidReason::CHECK_FAILED, $config->invalid_reason );
		$this->assertCount( 1, $config->sides );
		$this->assertSame( self::PLACEMENT_ID, $config->sides[0]->placement_id );
		$this->assertSame( 'Front', $config->sides[0]->placement_name );
		$this->assertSame( 'mini-tee', $config->sides[0]->template_base_id );
		$this->assertSame( array(), $config->sides[0]->print_sizes );
		$this->recorder->json( 200, $this->blank() );
		$this->recorder->json( 200, $this->mockups() );
		$this->recorder->json( 200, self::printshops() );
		$panel = $this->panel->panel( $this->reload( $product->get_id() ) );
		$this->assertSame( self::TEMPLATE_ID, $panel['sides'][0]['template_id'] );

		// Pixonaut answers again: the same choice is checked and sold.
		$this->script_link( array( 'legal' => true ) );
		$this->save( $product->get_id() );
		$this->assertTrue( $this->reload( $product->get_id() )->is_purchasable() );
	}

	public function test_a_first_link_keeps_the_choice_when_the_blank_cannot_be_read(): void {
		$product = $this->product();
		$this->recorder->json( 404, array( 'error' => 'not_found' ) );

		$this->save( $product->get_id() );

		$config = ProductConfig::stored_on( $this->reload( $product->get_id() ) );
		$this->assertSame( InvalidReason::UNKNOWN_BLANK, $config->invalid_reason );
		$this->assertSame( self::PLACEMENT_ID, $config->sides[0]->placement_id );
		$this->assertSame( 'Mini tee', $config->sides[0]->template_name );
	}

	public function test_a_deleted_blank_keeps_the_choice_but_cannot_be_bought(): void {
		$product = $this->product();
		$this->recorder->json( 200, BlankFixture::deleted() );

		$this->save( $product->get_id() );

		$config = ProductConfig::stored_on( $this->reload( $product->get_id() ) );
		$this->assertSame( InvalidReason::BLANK_DELETED, $config->invalid_reason );
		$this->assertSame( 'Fixture tee', $config->blank_name );
		$this->assertSame( self::PLACEMENT_ID, $config->sides[0]->placement_id );
		$this->assertSame( 'Mini tee', $config->sides[0]->template_name );
		$this->assertCount( 1, $this->recorder->requests, 'nothing is checked on a deleted blank' );
		$this->assertFalse( $this->reload( $product->get_id() )->is_purchasable() );
	}

	public function test_the_panel_says_the_blank_is_deleted_before_the_product_is_checked_again(): void {
		$product = $this->product();
		$this->script_link( array( 'legal' => true ) );
		$this->save( $product->get_id() );
		$this->recorder->json( 200, BlankFixture::deleted() );
		$this->recorder->json( 200, $this->mockups() );
		$this->recorder->json( 200, self::printshops() );

		$panel = $this->panel->panel( $this->reload( $product->get_id() ) );

		$this->assertSame( InvalidReason::message( InvalidReason::BLANK_DELETED ), $panel['invalid'] );
		$this->assertSame( 'Front', $panel['placements'][0]['text'] );
	}

	public function test_saving_a_linked_product_stores_the_checked_configuration(): void {
		$product = $this->product();
		$this->script_link( array( 'legal' => true ) );

		$this->save( $product->get_id() );

		$config = ProductConfig::stored_on( $this->reload( $product->get_id() ) );
		$this->assertTrue( $config->personalise );
		$this->assertTrue( $config->valid );
		$this->assertSame( self::BLANK_ID, $config->blank_id );
		$this->assertSame( 'Fixture tee', $config->blank_name );
		$this->assertCount( 1, $config->sides );
		$side = $config->sides[0];
		$this->assertSame( self::PLACEMENT_ID, $side->placement_id );
		$this->assertSame( 'Front', $side->placement_name );
		$this->assertSame( 'mini-tee', $side->template_base_id );
		$this->assertSame( 2, $side->template_version );
		$this->assertSame( 'Mini tee', $side->template_name );
		$this->assertCount( 1, $side->print_sizes );
		$this->assertSame( (float) self::PRINT_WIDTH_MM, $side->print_sizes[0]->width_mm );
		$this->assertSame( (float) self::PRINT_HEIGHT_MM, $side->print_sizes[0]->height_mm );
		$this->assertTrue( $this->reload( $product->get_id() )->is_purchasable() );
		$this->assertFalse( $this->plugin->notices()->has( ProductPanel::notice_key( $product->get_id() ) ) );

		// One check of the sizes, against the chosen placement.
		$check = $this->recorder->requests[2];
		$this->assertSame( 'POST', $check['method'] );
		$this->assertStringEndsWith( '/v1/templates/mini-tee%402/print-size', $check['url'] );
		$this->assertEquals(
			array(
				'sizes'       => array(
					array(
						'widthMm'  => self::PRINT_WIDTH_MM,
						'heightMm' => self::PRINT_HEIGHT_MM,
					),
				),
				'blankId'     => self::BLANK_ID,
				'placementId' => self::PLACEMENT_ID,
			),
			json_decode( (string) $check['body'], true )
		);
	}

	public function test_an_illegal_size_is_saved_but_cannot_be_bought_and_raises_a_notice(): void {
		$product = $this->product();
		$this->script_link(
			array(
				'legal'  => false,
				'reason' => InvalidReason::TOO_LARGE,
			)
		);

		$this->save( $product->get_id() );

		$saved  = $this->reload( $product->get_id() );
		$config = ProductConfig::stored_on( $saved );
		$this->assertTrue( $config->personalise );
		$this->assertFalse( $config->valid );
		$this->assertSame( InvalidReason::TOO_LARGE, $config->invalid_reason );
		$this->assertSame( array(), $config->sides[0]->print_sizes );
		$this->assertFalse( $saved->is_purchasable() );
		$notices = $this->plugin->notices()->all();
		$key     = ProductPanel::notice_key( $product->get_id() );
		$this->assertArrayHasKey( $key, $notices );
		$this->assertStringContainsString( InvalidReason::message( InvalidReason::TOO_LARGE ), $notices[ $key ]['message'] );

		// Fixed: the product can be bought again and the notice goes.
		$this->script_link( array( 'legal' => true ) );
		$this->save( $product->get_id() );
		$this->assertTrue( $this->reload( $product->get_id() )->is_purchasable() );
		$this->assertFalse( $this->plugin->notices()->has( $key ) );
	}

	public function test_a_template_only_products_sizes_are_checked_against_its_template_alone(): void {
		$product = $this->product();
		$this->recorder->json( 200, array( 'verdicts' => array( array( 'legal' => true ) ) ) );
		$this->script_template();
		$this->recorder->json( 200, self::printshops() );

		$this->save( $product->get_id(), array( 'pixonaut_blank' => '' ), self::template_only_side() );

		$config = ProductConfig::stored_on( $this->reload( $product->get_id() ) );
		$this->assertTrue( $config->valid );
		$this->assertNull( $config->blank_id );
		$this->assertNull( $config->sides[0]->placement_id );
		$this->assertSame( 'mini-tee', $config->sides[0]->template_base_id );
		$this->assertEquals( array( new PrintSize( self::PRINT_WIDTH_MM, self::PRINT_HEIGHT_MM, 'Poster' ) ), $config->sides[0]->print_sizes );
		$this->assertCount( 3, $this->recorder->requests, 'the size check, the template, then the printshops' );
		$this->assertStringEndsWith( '/v1/templates/mini-tee%402/print-size', $this->recorder->requests[0]['url'] );
		$this->assertEquals(
			array(
				'sizes' => array(
					array(
						'widthMm'  => self::PRINT_WIDTH_MM,
						'heightMm' => self::PRINT_HEIGHT_MM,
					),
				),
			),
			json_decode( (string) $this->recorder->requests[0]['body'], true ),
			'no blank or placement is named'
		);
		$this->assertStringEndsWith( '/v1/templates/mini-tee%402', $this->recorder->requests[1]['url'] );
		$this->assertTrue( $this->reload( $product->get_id() )->is_purchasable() );
	}

	public function test_a_template_only_product_without_a_size_cannot_be_bought_and_raises_a_notice(): void {
		$product = $this->product();

		$this->save( $product->get_id(), array( 'pixonaut_blank' => '' ), array( 'placement' => '' ) );

		$saved  = $this->reload( $product->get_id() );
		$config = ProductConfig::stored_on( $saved );
		$this->assertSame( InvalidReason::MISSING_PRINT_SIZE, $config->invalid_reason );
		$this->assertSame( 'mini-tee', $config->sides[0]->template_base_id, 'the template is kept' );
		$this->assertFalse( $saved->is_purchasable() );
		$this->assertSame( array(), $this->recorder->requests, 'nothing to ask Pixonaut' );
		$key = ProductPanel::notice_key( $product->get_id() );
		$this->assertStringContainsString( esc_html( InvalidReason::message( InvalidReason::MISSING_PRINT_SIZE ) ), $this->plugin->notices()->all()[ $key ]['message'] );
	}

	public function test_a_template_only_size_left_incomplete_keeps_the_product_from_selling(): void {
		$product = $this->product();
		$this->recorder->json( 200, array( 'verdicts' => array( array( 'legal' => true ) ) ) );
		$side            = self::template_only_side();
		$side['sizes'][] = array(
			'kind'   => PostedSides::SIZE_CUSTOM,
			'width'  => '500',
			'height' => '',
			'label'  => 'Large',
		);

		$this->save( $product->get_id(), array( 'pixonaut_blank' => '' ), $side );

		$saved  = $this->reload( $product->get_id() );
		$config = ProductConfig::stored_on( $saved );
		$this->assertSame( InvalidReason::INVALID_PRINT_SIZE, $config->invalid_reason );
		$this->assertFalse( $saved->is_purchasable() );
	}

	public function test_a_template_only_size_off_the_templates_ratio_cannot_be_bought(): void {
		$product = $this->product();
		$this->recorder->json(
			200,
			array(
				'verdicts' => array(
					array(
						'legal'  => false,
						'reason' => InvalidReason::WRONG_RATIO,
					),
				),
			)
		);

		$this->save( $product->get_id(), array( 'pixonaut_blank' => '' ), self::template_only_side() );

		$config = ProductConfig::stored_on( $this->reload( $product->get_id() ) );
		$this->assertSame( InvalidReason::WRONG_RATIO, $config->invalid_reason );
		$this->assertSame( array(), $config->sides[0]->print_sizes );
	}

	public function test_a_paused_template_is_saved_but_cannot_be_bought_and_the_panel_says_so(): void {
		$product = $this->product();
		$this->recorder->json( 200, $this->blank() );
		$this->recorder->json( 200, $this->constraints( self::PLACEMENT_ID ) );
		$this->recorder->json( 200, array( 'verdicts' => array( array( 'legal' => true ) ) ) );
		$this->script_template( 'paused' );

		$this->save( $product->get_id() );

		$saved  = $this->reload( $product->get_id() );
		$config = ProductConfig::stored_on( $saved );
		$this->assertFalse( $config->valid );
		$this->assertSame( InvalidReason::TEMPLATE_NOT_LIVE, $config->invalid_reason );
		$this->assertSame( 'mini-tee', $config->sides[0]->template_base_id );
		$this->assertFalse( $saved->is_purchasable() );
		$notices = $this->plugin->notices()->all();
		$key     = ProductPanel::notice_key( $product->get_id() );
		$this->assertArrayHasKey( $key, $notices );
		$this->assertStringContainsString( InvalidReason::message( InvalidReason::TEMPLATE_NOT_LIVE ), $notices[ $key ]['message'] );
	}

	public function test_a_template_gone_since_it_was_chosen_cannot_be_bought(): void {
		$product = $this->product();
		$this->recorder->json( 404, array( 'error' => 'not_found' ) );

		$this->save( $product->get_id(), array( 'pixonaut_blank' => '' ), self::template_only_side() );

		$this->assertSame( InvalidReason::UNKNOWN_TEMPLATE, ProductConfig::stored_on( $this->reload( $product->get_id() ) )->invalid_reason );
	}

	public function test_a_personalised_product_without_a_template_cannot_be_bought(): void {
		$product = $this->product();

		$this->save( $product->get_id(), array( 'pixonaut_blank' => '' ), array( 'template' => '' ) );

		$saved = $this->reload( $product->get_id() );
		$this->assertSame( InvalidReason::MISSING_TEMPLATE, ProductConfig::stored_on( $saved )->invalid_reason );
		// Without a blank the template is the whole side: nothing is kept.
		$this->assertSame( array(), ProductConfig::stored_on( $saved )->sides );
		$this->assertFalse( $saved->is_purchasable() );
	}

	public function test_an_unreachable_pixonaut_leaves_the_product_unpurchasable(): void {
		$product = $this->product();
		$this->recorder->json( 500, array( 'error' => 'internal_error' ) );

		$this->save( $product->get_id() );

		$saved = $this->reload( $product->get_id() );
		$this->assertSame( InvalidReason::CHECK_FAILED, ProductConfig::stored_on( $saved )->invalid_reason );
		$this->assertFalse( $saved->is_purchasable() );
	}

	public function test_switching_personalisation_off_keeps_the_link_and_sells_the_product_plain(): void {
		$product = $this->product();
		$this->script_link(
			array(
				'legal'  => false,
				'reason' => InvalidReason::WRONG_RATIO,
			)
		);
		$this->save( $product->get_id() );

		$this->save( $product->get_id(), array( 'pixonaut_personalise' => null ) );

		$saved  = $this->reload( $product->get_id() );
		$config = ProductConfig::stored_on( $saved );
		$this->assertFalse( $config->personalise );
		$this->assertSame( self::BLANK_ID, $config->blank_id );
		$this->assertTrue( $saved->is_purchasable() );
		$this->assertFalse( $this->plugin->notices()->has( ProductPanel::notice_key( $product->get_id() ) ) );
	}

	public function test_the_saved_configuration_can_be_filtered(): void {
		$product = $this->product();
		$filter  = static fn ( ProductConfig $config ): ProductConfig => $config->with_validity( false, 'held' );
		add_filter( 'pixonaut_wc_catalogue_product_config', $filter );
		$this->recorder->json( 200, array( 'verdicts' => array( array( 'legal' => true ) ) ) );
		$this->script_template();
		$this->recorder->json( 200, self::printshops() );

		$this->save( $product->get_id(), array( 'pixonaut_blank' => '' ), self::template_only_side() );

		remove_filter( 'pixonaut_wc_catalogue_product_config', $filter );
		$this->assertSame( 'held', ProductConfig::stored_on( $this->reload( $product->get_id() ) )->invalid_reason );
	}

	public function test_saving_stores_the_approval_mode_override(): void {
		$product = $this->product();
		$this->script_link( array( 'legal' => true ) );

		$this->save( $product->get_id(), array( ProductPanel::APPROVAL_MODE_FIELD => ApprovalMode::CHECKBOX ) );

		$this->assertSame( ApprovalMode::CHECKBOX, ProductConfig::stored_on( $this->reload( $product->get_id() ) )->approval_mode );
	}

	public function test_the_store_default_or_an_unknown_mode_clears_the_override(): void {
		$product = $this->product();
		( new ProductConfig( personalise: true, approval_mode: ApprovalMode::CHECKBOX ) )->save_to( $product );
		$this->script_link( array( 'legal' => true ) );

		$this->save( $product->get_id(), array( ProductPanel::APPROVAL_MODE_FIELD => 'retired_mode' ) );

		$this->assertNull( ProductConfig::stored_on( $this->reload( $product->get_id() ) )->approval_mode );
	}

	public function test_the_panel_offers_the_store_default_and_every_mode(): void {
		$product = $this->product();
		( new ProductConfig( approval_mode: ApprovalMode::PROOF_EMAIL ) )->save_to( $product );
		$this->recorder->json( 200, self::printshops() );

		$panel = $this->panel->panel( $this->reload( $product->get_id() ) );

		$this->assertSame( ApprovalMode::PROOF_EMAIL, $panel['approval_mode'] );
		$this->assertSame( array( '', ApprovalMode::ADD_TO_CART, ApprovalMode::CHECKBOX, ApprovalMode::PROOF_EMAIL ), array_keys( $panel['approval_modes'] ) );
		$this->assertStringContainsString( ApprovalMode::names()[ ApprovalMode::ADD_TO_CART ], $panel['approval_modes'][''] );
	}

	public function test_saving_stores_the_layout_override_and_the_store_default_clears_it(): void {
		$product = $this->product();
		$this->script_link( array( 'legal' => true ) );
		$this->save( $product->get_id(), array( ProductPanel::LAYOUT_FIELD => Layout::BUTTON ) );
		$this->assertSame( Layout::BUTTON, ProductConfig::stored_on( $this->reload( $product->get_id() ) )->layout );

		$this->script_link( array( 'legal' => true ) );
		$this->save( $product->get_id(), array( ProductPanel::LAYOUT_FIELD => '' ) );

		$this->assertNull( ProductConfig::stored_on( $this->reload( $product->get_id() ) )->layout );
	}

	public function test_the_panel_offers_the_store_default_and_every_layout(): void {
		$product = $this->product();
		( new ProductConfig( layout: Layout::BUTTON ) )->save_to( $product );
		$this->recorder->json( 200, self::printshops() );

		$panel = $this->panel->panel( $this->reload( $product->get_id() ) );

		$this->assertSame( Layout::BUTTON, $panel['layout'] );
		$this->assertSame( array( '', Layout::INLINE, Layout::BUTTON ), array_keys( $panel['layouts'] ) );
		$this->assertStringContainsString( Layout::names()[ Layout::INLINE ], $panel['layouts'][''] );
	}

	public function test_a_variations_empty_layout_says_it_follows_the_product(): void {
		$parent    = new \WC_Product_Variable();
		$parent_id = $parent->save();
		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $parent_id );
		$variation->save();

		$this->recorder->json( 200, self::printshops() );

		$panel = $this->panel->panel( $variation );

		$this->assertSame( array( '', Layout::INLINE, Layout::BUTTON ), array_keys( $panel['layouts'] ) );
		$this->assertSame( 'As the product says', $panel['layouts'][''] );
	}

	public function test_saving_stores_the_form_layout_override_and_the_store_default_clears_it(): void {
		$product = $this->product();
		$this->script_link( array( 'legal' => true ) );
		$this->save( $product->get_id(), array( ProductPanel::FORM_LAYOUT_FIELD => FormLayout::STEPS ) );
		$this->assertSame( FormLayout::STEPS, ProductConfig::stored_on( $this->reload( $product->get_id() ) )->form_layout );

		$this->script_link( array( 'legal' => true ) );
		$this->save( $product->get_id(), array( ProductPanel::FORM_LAYOUT_FIELD => 'carousel' ) );

		$this->assertNull( ProductConfig::stored_on( $this->reload( $product->get_id() ) )->form_layout );
	}

	public function test_the_panel_offers_the_store_default_and_every_form_layout(): void {
		$product = $this->product();
		( new ProductConfig( form_layout: FormLayout::PAGE ) )->save_to( $product );
		$this->plugin->settings()->set( Settings::FORM_LAYOUT, FormLayout::STEPS );
		$this->recorder->json( 200, self::printshops() );

		$panel = $this->panel->panel( $this->reload( $product->get_id() ) );

		$this->assertSame( FormLayout::PAGE, $panel['form_layout'] );
		$this->assertSame( array( '', FormLayout::TEMPLATE, FormLayout::PAGE, FormLayout::STEPS ), array_keys( $panel['form_layouts'] ) );
		$this->assertSame( 'Store setting: Steps', $panel['form_layouts'][''] );
	}

	public function test_a_save_without_the_panel_changes_nothing(): void {
		$product = $this->product();
		wp_set_current_user( $this->user( 'administrator' ) );

		$this->panel->save( $product->get_id() );

		$this->assertSame( '', $this->reload( $product->get_id() )->get_meta( Schema::PRODUCT_CONFIG_META ) );
	}

	public function test_the_panel_lists_the_blanks_placements_and_the_chosen_template(): void {
		$product = $this->product();
		$this->script_link( array( 'legal' => true ) );
		$this->save( $product->get_id() );
		$this->recorder->json( 200, $this->blank() );
		$this->recorder->json( 200, $this->mockups() );
		$this->recorder->json( 200, self::printshops() );

		$panel = $this->panel->panel( $this->reload( $product->get_id() ) );

		$this->assertSame(
			array(
				array(
					'id'          => self::PLACEMENT_ID,
					'text'        => 'Front',
					'maxWidthMm'  => 330.0,
					'maxHeightMm' => 420.0,
					'thumbnail'   => self::BASE_URL . '/i/thumb-' . self::PLACEMENT_ID,
				),
			),
			$panel['placements']
		);
		$this->assertSame( self::BASE_URL . '/i/thumb-' . self::PLACEMENT_ID, $panel['blank_thumbnail'] );
		$this->assertSame( self::TEMPLATE_ID, $panel['sides'][0]['template_id'] );
		$this->assertSame(
			array(
				array(
					'start'  => true,
					'kind'   => PostedSides::SIZE_PLACEMENT_DEFAULT,
					'width'  => '280',
					'height' => '360',
					'label'  => '280 × 360 mm',
				),
			),
			$panel['sides'][0]['sizes']
		);
		$this->assertSame( '', $panel['invalid'] );
		// The blank names no printshop: the workspace's default prints it.
		$this->assertSame( 'Workspace default (Fixture Print AB)', $panel['printshop']['name'] );
		$this->assertSame( LinkedBlank::studio_url( self::BLANK_ID ), $panel['printshop']['url'] );
	}

	public function test_saving_stores_the_chosen_printshop_and_workspace_default_clears_it(): void {
		$printshop = PanelFields::PRODUCT_PREFIX . PanelFields::PRINTSHOP;
		$product   = $this->product();
		$this->script_link( array( 'legal' => true ) );
		$this->save( $product->get_id(), array( $printshop => 'ps_other' ) );
		$this->assertSame( 'ps_other', ProductConfig::stored_on( $this->reload( $product->get_id() ) )->printshop_id );

		$this->script_link( array( 'legal' => true ) );
		$this->save( $product->get_id() );
		$this->assertSame( 'ps_other', ProductConfig::stored_on( $this->reload( $product->get_id() ) )->printshop_id, 'a form without the field keeps it' );

		$this->script_link( array( 'legal' => true ) );
		$this->save( $product->get_id(), array( $printshop => '' ) );
		$this->assertNull( ProductConfig::stored_on( $this->reload( $product->get_id() ) )->printshop_id );
	}

	public function test_a_product_without_a_blank_chooses_from_the_workspaces_printshops(): void {
		$product = $this->product();
		( new ProductConfig( personalise: true, printshop_id: 'ps_other' ) )->save_to( $product );
		$this->recorder->json( 200, self::printshops() );

		$panel = $this->panel->panel( $this->reload( $product->get_id() ) );
		$html  = $this->panel->fields_html( $panel );

		$this->assertSame(
			array(
				''                 => 'Workspace default (Fixture Print AB)',
				self::PRINTSHOP_ID => 'Fixture Print AB',
				'ps_other'         => 'Other Print AB',
			),
			$panel['printshops'],
			'Gelato prints only on a blank'
		);
		$this->assertMatchesRegularExpression( '/<select id="pixonaut_printshop" name="pixonaut_printshop" class="pixonaut-printshop-choice"[^>]*>/', $html );
		$this->assertStringContainsString( '<option value="ps_other"  selected=\'selected\'>Other Print AB</option>', $html );
		$this->assertStringNotContainsString( 'Gelato', $html );
		$this->assertStringNotContainsString( 'pixonaut-printshop-name', $html, 'no blank decides it' );
	}

	public function test_a_product_without_a_blank_is_not_offered_a_default_that_prints_only_on_one(): void {
		$product = $this->product();
		( new ProductConfig( personalise: true ) )->save_to( $product );
		$this->recorder->json( 200, self::printshops( self::INTEGRATED_ID ) );

		$panel = $this->panel->panel( $this->reload( $product->get_id() ) );

		$this->assertSame(
			array(
				self::PRINTSHOP_ID => 'Fixture Print AB',
				'ps_other'         => 'Other Print AB',
			),
			$panel['printshops']
		);
	}

	public function test_a_workspace_whose_printshops_all_need_a_blank_says_so(): void {
		$product = $this->product();
		( new ProductConfig( personalise: true ) )->save_to( $product );
		$this->recorder->json(
			200,
			array(
				'connections' => array_values(
					array_filter(
						self::printshops( self::INTEGRATED_ID )['connections'],
						static fn ( array $connection ): bool => self::INTEGRATED_ID === $connection['id']
					)
				),
			)
		);

		$panel = $this->panel->panel( $this->reload( $product->get_id() ) );
		$html  = $this->panel->fields_html( $panel );

		$this->assertSame( array(), $panel['printshops'] );
		$this->assertStringContainsString( 'No printshop in your workspace prints without a blank.', $html );
		$this->assertStringNotContainsString( 'id="pixonaut_printshop"', $html );
	}

	public function test_a_product_without_a_blank_saved_on_a_printshop_that_needs_one_cannot_be_bought(): void {
		$product = $this->product();
		$this->recorder->json( 200, array( 'verdicts' => array( array( 'legal' => true ) ) ) );
		$this->script_template();
		$this->recorder->json( 200, self::printshops() );

		$this->save(
			$product->get_id(),
			array(
				'pixonaut_blank'     => '',
				'pixonaut_printshop' => self::INTEGRATED_ID,
			),
			self::template_only_side()
		);

		$product = $this->reload( $product->get_id() );
		$config  = ProductConfig::stored_on( $product );
		$this->assertSame( InvalidReason::PRINTSHOP_NEEDS_BLANK, $config->invalid_reason );
		$this->assertSame( self::INTEGRATED_ID, $config->printshop_id, 'the choice is kept, so the panel shows it' );
		$this->assertFalse( $product->is_purchasable() );

		$this->recorder->json( 200, self::printshops() );
		$panel = $this->panel->panel( $product );
		$html  = $this->panel->fields_html( $panel );
		$this->assertSame( 'Gelato (prints only on a blank)', $panel['printshops'][ self::INTEGRATED_ID ] );
		$this->assertStringContainsString( '<span class="pixonaut-field-error" role="alert">Choose a blank, or a printshop that prints without one.</span>', $html );
	}

	public function test_a_chosen_printshop_the_workspace_no_longer_has_stays_chosen(): void {
		$product = $this->product();
		( new ProductConfig( personalise: true, printshop_id: 'ps_gone' ) )->save_to( $product );
		$this->recorder->json( 200, self::printshops() );

		$panel = $this->panel->panel( $this->reload( $product->get_id() ) );

		$this->assertSame( 'A printshop no longer connected to the workspace', $panel['printshops']['ps_gone'] ?? null );
		$this->assertSame( 'ps_gone', $panel['printshop_id'] );
	}

	public function test_a_product_on_a_blank_shows_the_blanks_printshop_locked(): void {
		$product = $this->product();
		$this->script_link( array( 'legal' => true ) );
		$this->save( $product->get_id() );
		$this->recorder->json( 200, $this->blank() );
		$this->recorder->json( 200, $this->mockups() );
		$this->recorder->json( 200, self::printshops() );

		$html = $this->panel->fields_html( $this->panel->panel( $this->reload( $product->get_id() ) ) );

		$this->assertStringContainsString( '<span class="pixonaut-printshop-name">Workspace default (Fixture Print AB)</span>', $html );
		$this->assertMatchesRegularExpression( '/<p class="form-field pixonaut-printshop pixonaut-without-blank"\s+hidden>/', $html, 'the product\'s own choice waits, hidden, for the blank to go' );
	}

	public function test_the_blank_fields_route_draws_a_picked_blanks_printshop_and_views_before_the_save(): void {
		$product = $this->product();
		wp_set_current_user( $this->user( 'shop_manager' ) );
		$this->recorder->json( 200, $this->blank() );
		$this->recorder->json( 200, $this->mockups() );
		$this->recorder->json( 200, self::printshops() );

		$response = $this->get(
			BlankFields::ROUTE,
			array(
				'product' => (string) $product->get_id(),
				'blank'   => self::BLANK_ID,
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$parts = $response->get_data();
		$this->assertIsArray( $parts );
		$this->assertStringContainsString( '<span class="pixonaut-printshop-name">Workspace default (Fixture Print AB)</span>', $parts['blank_html'] );
		$this->assertStringContainsString( 'Blank variant: v_tee (found automatically)', $parts['blank_html'], 'found from the product\'s attributes, before the save' );
		$this->assertStringContainsString( 'name="pixonaut_views[ticked][]"', $parts['views_html'] );
		$this->assertNull( ProductConfig::stored_on( $this->reload( $product->get_id() ) )->blank_id, 'nothing is saved' );
	}

	public function test_the_blank_fields_route_answers_only_someone_who_may_edit_the_product(): void {
		$product = $this->product();
		wp_set_current_user( $this->user( 'customer' ) );

		$response = $this->get(
			BlankFields::ROUTE,
			array(
				'product' => (string) $product->get_id(),
				'blank'   => self::BLANK_ID,
			)
		);

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( array(), $this->recorder->requests, 'Pixonaut is not asked' );
	}

	public function test_the_placements_route_lists_a_blanks_placements_with_their_pictures(): void {
		wp_set_current_user( $this->user( 'shop_manager' ) );
		$this->recorder->json(
			200,
			$this->blank(
				array(
					'front' => 'Front',
					'back'  => 'Back',
				)
			)
		);
		$this->recorder->json( 200, $this->mockups( array( 'front' => 'Front' ) ) );

		$response = $this->get( CatalogueSearch::PLACEMENTS_ROUTE, array( 'blank' => self::BLANK_ID ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'results'    => array(
					array(
						'id'          => 'front',
						'text'        => 'Front',
						'maxWidthMm'  => 330.0,
						'maxHeightMm' => 420.0,
						'thumbnail'   => self::BASE_URL . '/i/thumb-front',
					),
					array(
						'id'          => 'back',
						'text'        => 'Back',
						'maxWidthMm'  => 330.0,
						'maxHeightMm' => 420.0,
						'thumbnail'   => '',
					),
				),
				'pagination' => array( 'more' => false ),
			),
			$response->get_data()
		);
	}

	public function test_the_template_route_answers_a_templates_preview_and_keeps_it_for_a_few_minutes(): void {
		wp_set_current_user( $this->user( 'shop_manager' ) );
		$this->script_template();

		$response = $this->get( CatalogueSearch::TEMPLATE_ROUTE, array( 'id' => self::TEMPLATE_ID ) );
		$again    = $this->get( CatalogueSearch::TEMPLATE_ROUTE, array( 'id' => self::TEMPLATE_ID ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( self::TEMPLATE_ID, $response->get_data()['results'][0]['id'] );
		$this->assertNotSame( '', $response->get_data()['results'][0]['thumbnail'] );
		$this->assertSame( $response->get_data(), $again->get_data() );
		$this->assertCount( 1, $this->recorder->requests );
	}

	/**
	 * @dataProvider background_answers
	 *
	 * @param array<string, mixed> $answer   What Pixonaut adds to the template.
	 * @param bool|null            $expected What the route says of its background; null when it says nothing.
	 */
	public function test_the_template_route_says_whether_the_background_shows_through_only_when_pixonaut_knows( array $answer, ?bool $expected ): void {
		wp_set_current_user( $this->user( 'shop_manager' ) );
		$this->recorder->raw( 200, OpenApiAssert::example( 'GET', '/v1/templates/{id}', 200, $answer ), 'application/json' );

		$result = $this->get( CatalogueSearch::TEMPLATE_ROUTE, array( 'id' => self::TEMPLATE_ID ) )->get_data()['results'][0];

		$this->assertSame( $expected, $result['backgroundSeeThrough'] ?? null );
		$this->assertSame( null !== $expected, array_key_exists( 'backgroundSeeThrough', $result ) );
	}

	/**
	 * @return array<string, array{array<string, mixed>, bool|null}>
	 */
	public static function background_answers(): array {
		return array(
			'shows through' => array( array( 'backgroundSeeThrough' => true ), true ),
			'opaque'        => array( array( 'backgroundSeeThrough' => false ), false ),
			'not known'     => array( array( 'backgroundSeeThrough' => null ), null ),
		);
	}

	public function test_the_template_route_says_nothing_of_the_background_when_a_pixonaut_without_the_field_answers(): void {
		wp_set_current_user( $this->user( 'shop_manager' ) );
		$template = json_decode( OpenApiAssert::example( 'GET', '/v1/templates/{id}', 200 ), true );
		unset( $template['backgroundSeeThrough'] );
		$this->recorder->off_spec( 200, $template );

		$result = $this->get( CatalogueSearch::TEMPLATE_ROUTE, array( 'id' => self::TEMPLATE_ID ) )->get_data()['results'][0];

		$this->assertArrayNotHasKey( 'backgroundSeeThrough', $result );
	}

	public function test_the_placements_route_is_a_502_when_the_blank_cannot_be_read(): void {
		wp_set_current_user( $this->user( 'shop_manager' ) );
		$this->recorder->json( 500, array( 'error' => 'internal_error' ) );

		$this->assertSame( 502, $this->get( CatalogueSearch::PLACEMENTS_ROUTE, array( 'blank' => self::BLANK_ID ) )->get_status() );
	}

	/**
	 * @return array<string, mixed> A `GET /v1/blanks` answer.
	 */
	private function blanks_answer(): array {
		return array(
			'blanks'       => array( $this->blank() ),
			'total'        => 1,
			'page'         => 1,
			'pageSize'     => Catalogue::SEARCH_PAGE_SIZE,
			'orderedFirst' => false,
		);
	}

	/**
	 * @param string                $route  Route below the namespace.
	 * @param array<string, string> $params Query parameters.
	 */
	private function get( string $route, array $params ): \WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/' . Plugin::REST_NAMESPACE . $route );
		$request->set_query_params( $params );
		return rest_get_server()->dispatch( $request );
	}
}
