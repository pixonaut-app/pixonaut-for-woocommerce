<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Abilities;

use Pixonaut\WooCommerce\Abilities\Abilities;
use Pixonaut\WooCommerce\Admin\Notices;
use Pixonaut\WooCommerce\Api\Catalogue;
use Pixonaut\WooCommerce\Api\CheckSlowdown;
use Pixonaut\WooCommerce\Api\Hosts;
use Pixonaut\WooCommerce\Catalogue\PrintSize;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ProductRecheck;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Tests\Support\ActionSchedulerRunner;
use Pixonaut\WooCommerce\Tests\Support\BlankFixture;
use Pixonaut\WooCommerce\Tests\Support\FreshRequest;
use Pixonaut\WooCommerce\Tests\Support\HttpRecorder;
use Pixonaut\WooCommerce\Tests\Support\OpenApiAssert;
use Pixonaut\WooCommerce\Tests\Support\VariantsAnswer;
use Pixonaut\WooCommerce\Tests\Support\VerdictAnswers;
use WC_Product;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_Ability;
use WP_UnitTestCase;

/**
 * Linking a variable product to a template version moves its variations
 * with a setup of their own onto that version, where their side follows the
 * template, rather than leaving them to a re-check a busy store runs late.
 */
final class LinkProductVariationsTest extends WP_UnitTestCase {

	private const LINK_PRODUCT = Abilities::NAMESPACE . '/link-product';

	private const BASE_ID = 'kids-tee';

	private const OLD_VERSION = 4;

	private const NEW_VERSION = 5;

	/**
	 * The product's print size, in millimetres.
	 */
	private const WIDTH_MM = 500;

	private const HEIGHT_MM = 700;

	/**
	 * A variation's own print size: a smaller child's size, at the template's ratio.
	 */
	private const OWN_WIDTH_MM = 250;

	private const OWN_HEIGHT_MM = 350;

	private const CONNECTED_AT = 1767225600;

	private const TOO_MANY_REQUESTS = 429;

	private const RETRY_AFTER_SECONDS = 30;

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
		$this->recorder->stop();
		$this->variants->stop();
		$this->plugin->connection_store()->clear();
		delete_option( Notices::OPTION );
		delete_option( CheckSlowdown::OPTION );
		parent::tear_down();
	}

	public function test_linking_moves_each_own_setup_following_the_template_onto_the_version_linked(): void {
		$product   = $this->variable_product();
		$own       = $this->variation( $product, self::own_setup( false ) );
		$pinned    = $this->variation( $product, self::own_setup( true ) );
		$following = $this->variation( $product, null );
		$this->script_link();
		$this->live_list( self::NEW_VERSION );
		$this->script_own_check();

		$answer = $this->link( $product );

		$this->assertIsArray( $answer );
		$this->assertSame( 1, $answer['variations_moved'] );
		$this->assertSame( 0, $answer['variations_moved_later'] );
		$side = $this->stored( $own )->sides[0];
		$this->assertSame( array( self::NEW_VERSION, self::OLD_VERSION ), array( $side->template_version, $side->previous_version ) );
		$this->assertEquals( array( new PrintSize( self::OWN_WIDTH_MM, self::OWN_HEIGHT_MM, '' ) ), $side->print_sizes, 'The variation keeps its own print size.' );
		$this->assertTrue( $this->stored( $own )->valid );
		$this->assertSame( self::OLD_VERSION, $this->stored( $pinned )->sides[0]->template_version, 'A pinned side stays on its version.' );
		$this->assertNull( ProductConfig::document_on( $this->reload( $following ) ), 'A variation following its product keeps following it.' );
		$this->assertSame( 'POST /v1/templates/kids-tee@5/print-size', $this->requested()[3] );
	}

	public function test_a_variation_pixonaut_refuses_for_too_many_requests_moves_in_the_recheck_queued_for_it(): void {
		$product = $this->variable_product();
		$own     = $this->variation( $product, self::own_setup( false ) );
		as_unschedule_all_actions( ProductRecheck::ACTION );
		$this->script_link();
		$this->live_list( self::NEW_VERSION );
		$this->recorder->json( self::TOO_MANY_REQUESTS, array( 'error' => 'rate_limited' ), array( 'Retry-After' => (string) self::RETRY_AFTER_SECONDS ) );

		$answer = $this->link( $product );

		$this->assertIsArray( $answer );
		$this->assertSame( array( 0, 1 ), array( $answer['variations_moved'], $answer['variations_moved_later'] ) );
		$this->assertSame( self::OLD_VERSION, $this->stored( $own )->sides[0]->template_version );
		$this->assertCount( 1, ActionSchedulerRunner::pending( ProductRecheck::ACTION ) );
	}

	public function test_a_variation_pixonaut_did_not_answer_is_queued_with_the_ones_after_a_refusal(): void {
		$product = $this->variable_product();
		$first   = $this->variation( $product, self::own_setup( false ) );
		$second  = $this->variation( $product, self::own_setup( false ) );
		$this->script_link();
		$this->live_list( self::NEW_VERSION );
		$this->recorder->fail();
		$this->recorder->json( self::TOO_MANY_REQUESTS, array( 'error' => 'rate_limited' ), array( 'Retry-After' => (string) self::RETRY_AFTER_SECONDS ) );

		$answer = $this->link( $product );

		$this->assertIsArray( $answer );
		$this->assertSame( array( 0, 2 ), array( $answer['variations_moved'], $answer['variations_moved_later'] ) );
		$queued = array();
		foreach ( ActionSchedulerRunner::pending( ProductRecheck::ACTION ) as $action_id ) {
			$queued = array_merge( $queued, \ActionScheduler::store()->fetch_action( $action_id )->get_args()['product_ids'] );
		}
		sort( $queued );
		$this->assertSame( array( $first, $second ), $queued );
	}

	public function test_a_template_no_longer_live_moves_nothing(): void {
		$product = $this->variable_product();
		$own     = $this->variation( $product, self::own_setup( false ) );
		$this->script_link();
		$this->live_list( self::NEW_VERSION, 'other-tee' );

		$answer = $this->link( $product );

		$this->assertIsArray( $answer );
		$this->assertSame( array( 0, 0 ), array( $answer['variations_moved'], $answer['variations_moved_later'] ) );
		$this->assertSame( self::OLD_VERSION, $this->stored( $own )->sides[0]->template_version );
		$this->assertCount( 3, $this->recorder->requests, 'No variation is checked.' );
	}

	public function test_linking_asks_nothing_more_when_no_variation_has_a_setup_of_its_own_on_another_version(): void {
		$product = $this->variable_product();
		$this->variation( $product, null );
		$this->variation( $product, self::own_setup( true ) );
		$this->script_link();

		$answer = $this->link( $product );

		$this->assertIsArray( $answer );
		$this->assertSame( array( 0, 0 ), array( $answer['variations_moved'], $answer['variations_moved_later'] ) );
		$this->assertCount( 2, $this->recorder->requests, 'Only the link itself is checked.' );
	}

	/**
	 * Links the product at the new version, template-only in the product's size.
	 *
	 * @param WC_Product_Variable $product The product.
	 * @return mixed What the ability answers.
	 */
	private function link( WC_Product_Variable $product ): mixed {
		$ability = wp_get_ability( self::LINK_PRODUCT );
		$this->assertInstanceOf( WP_Ability::class, $ability );
		return $ability->execute(
			array(
				'product_id'  => $product->get_id(),
				'template_id' => self::BASE_ID . '@' . self::NEW_VERSION,
				'width_mm'    => self::WIDTH_MM,
				'height_mm'   => self::HEIGHT_MM,
			)
		);
	}

	/**
	 * Scripts the product's own check: its size's verdict, then the template.
	 */
	private function script_link(): void {
		$this->recorder->json( 200, array( 'verdicts' => array( VerdictAnswers::LEGAL ) ) );
		$this->recorder->raw( 200, OpenApiAssert::example( 'GET', '/v1/templates/{id}', 200, array( 'status' => Catalogue::LIVE ) ), 'application/json' );
	}

	/**
	 * Scripts a variation's re-check at the new version: its size's verdict, then the template.
	 */
	private function script_own_check(): void {
		$this->script_link();
	}

	/**
	 * Scripts `GET /v1/templates?status=live`: the template, live at `$version`.
	 *
	 * @param int    $version The live version.
	 * @param string $base_id The template live; the product's unless another is named.
	 */
	private function live_list( int $version, string $base_id = self::BASE_ID ): void {
		$this->recorder->json(
			200,
			array(
				'items'    => array(
					array(
						'id'                   => $base_id . '@' . $version,
						'baseId'               => $base_id,
						'name'                 => 'Kids tee',
						'latestVersion'        => $version,
						'ratio'                => array(
							'w' => self::WIDTH_MM,
							'h' => self::HEIGHT_MM,
						),
						'ratioTolerance'       => 0,
						'fieldKeys'            => array(),
						'previewUrl'           => 'https://api.pixonaut.test/i/preview',
						'offersProperties'     => true,
						'backgroundSeeThrough' => null,
					),
				),
				'total'    => 1,
				'page'     => 1,
				'pageSize' => Catalogue::LIST_PAGE_SIZE,
			)
		);
	}

	/**
	 * A variation's own template-only setup on the old version, in its own size.
	 *
	 * @param bool $pinned Whether its side stays on that version.
	 */
	private static function own_setup( bool $pinned ): ProductConfig {
		return new ProductConfig(
			personalise: true,
			override: true,
			sides: array( new Side( 'design', 'Kids tee', null, null, self::BASE_ID, self::OLD_VERSION, null, 'Kids tee', array( new PrintSize( self::OWN_WIDTH_MM, self::OWN_HEIGHT_MM, '' ) ), pinned: $pinned ) ),
		);
	}

	private function variable_product(): WC_Product_Variable {
		$product = new WC_Product_Variable();
		$product->set_name( 'Kids tee' );
		$product->save();
		return $product;
	}

	/**
	 * A variation of the product, with its own setup or following the product's.
	 *
	 * @param WC_Product_Variable $product The product.
	 * @param ProductConfig|null  $config  Its own setup; null to follow the product's.
	 */
	private function variation( WC_Product_Variable $product, ?ProductConfig $config ): int {
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $product->get_id() );
		$variation->set_regular_price( '25' );
		$variation->save();
		$config?->save_to( $variation );
		WC_Product_Variable::sync( $product->get_id() );
		// What the variations' saves queued is not what these tests are about.
		as_unschedule_all_actions( ProductRecheck::ACTION );
		return $variation->get_id();
	}

	/**
	 * @return list<string> Each request as `<METHOD> <path>`.
	 */
	private function requested(): array {
		return array_map(
			static fn ( array $request ): string => $request['method'] . ' ' . rawurldecode( (string) wp_parse_url( $request['url'], PHP_URL_PATH ) ),
			$this->recorder->requests
		);
	}

	private function stored( int $product_id ): ProductConfig {
		return ProductConfig::stored_on( $this->reload( $product_id ) );
	}

	private function reload( int $product_id ): WC_Product {
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
