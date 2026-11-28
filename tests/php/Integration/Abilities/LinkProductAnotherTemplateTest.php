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
use Pixonaut\WooCommerce\Catalogue\InvalidReason;
use Pixonaut\WooCommerce\Catalogue\PrintSize;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ProductRecheck;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Catalogue\SideValue;
use Pixonaut\WooCommerce\Catalogue\SideValues;
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
 * Linking a variable product to another template moves its variations with
 * a setup of their own that differs from the product's only in blank or
 * print size from the template the product had onto the one linked, so the
 * template it leaves can go, and the variations take the product's values.
 */
final class LinkProductAnotherTemplateTest extends WP_UnitTestCase {

	private const LINK_PRODUCT = Abilities::NAMESPACE . '/link-product';

	/**
	 * The template the product is on before the link: one theme's copy.
	 */
	private const COPY_ID = 'kids-tee-havet';

	private const COPY_VERSION = 1;

	/**
	 * The template it is linked to: the one every theme is sold from, with values.
	 */
	private const ROOT_ID = 'kids-tee-sengangare';

	private const ROOT_VERSION = 2;

	/**
	 * A template unrelated to the product's, which one variation is on.
	 */
	private const OTHER_ID = 'kids-tee-without-photo';

	private const OTHER_VERSION = 3;

	private const SIDE_KEY = 'design';

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

	private const BLANK_ID = 'blank_baby_body';

	private const PLACEMENT_ID = 'front';

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

	public function test_an_own_print_size_on_the_template_the_product_left_moves_to_the_one_linked_and_takes_its_values(): void {
		$product   = $this->product_on_copy();
		$own       = $this->variation( $product, self::own_setup( self::COPY_ID, self::COPY_VERSION ) );
		$pinned    = $this->variation( $product, self::own_setup( self::COPY_ID, self::COPY_VERSION, true ) );
		$unrelated = $this->variation( $product, self::own_setup( self::OTHER_ID, self::OTHER_VERSION ) );
		$this->script_check();
		$this->live_list();
		$this->script_check();

		$answer = $this->link( $product );
		SideValues::replace_on( $this->reload( $product->get_id() ), array( self::SIDE_KEY => new SideValue( self::ROOT_ID, 'vs_havet', array( 'title' => array( 'content' => 'Havet' ) ) ) ), null );

		$this->assertIsArray( $answer );
		$this->assertSame( array( 1, 0 ), array( $answer['variations_moved'], $answer['variations_moved_later'] ) );
		$moved = $this->stored( $own );
		$side  = $moved->sides[0];
		$this->assertSame( array( self::ROOT_ID, self::ROOT_VERSION, null, false ), array( $side->template_base_id, $side->template_version, $side->previous_version, $side->pinned ) );
		$this->assertEquals( array( new PrintSize( self::OWN_WIDTH_MM, self::OWN_HEIGHT_MM, '' ) ), $side->print_sizes, 'The variation keeps its own print size.' );
		$this->assertTrue( $moved->valid );
		$this->assertSame( 'POST /v1/templates/' . self::ROOT_ID . '@' . self::ROOT_VERSION . '/print-size', $this->requested()[3] );
		$sold = SideValues::sold_with( $this->reload( $own ), $side );
		$this->assertSame( array( 'vs_havet', SideValues::FROM_PRODUCT ), array( $sold['value']?->value_set_id, $sold['from'] ) );
		$this->assertSame( self::COPY_ID, $this->stored( $pinned )->sides[0]->template_base_id, 'A pinned side stays on its template.' );
		$this->assertSame( array( self::OTHER_ID, self::OTHER_VERSION ), array( $this->stored( $unrelated )->sides[0]->template_base_id, $this->stored( $unrelated )->sides[0]->template_version ), 'A variation on another template stays on it.' );
	}

	public function test_a_variation_with_values_of_its_own_for_the_template_it_is_on_stays_on_it(): void {
		$product = $this->product_on_copy();
		$valued  = $this->variation( $product, self::own_setup( self::COPY_ID, self::COPY_VERSION ) );
		SideValues::replace_on( $this->reload( $valued ), array( self::SIDE_KEY => new SideValue( self::COPY_ID, 'vs_own', array( 'title' => array( 'content' => 'Mine' ) ) ) ), null );
		$this->script_check();

		$answer = $this->link( $product );

		$this->assertIsArray( $answer );
		$this->assertSame( array( 0, 0 ), array( $answer['variations_moved'], $answer['variations_moved_later'] ) );
		$this->assertSame( self::COPY_ID, $this->stored( $valued )->sides[0]->template_base_id );
		$this->assertCount( 2, $this->recorder->requests, 'Only the link itself is checked.' );
	}

	public function test_one_pixonaut_did_not_answer_is_on_the_template_linked_on_its_own_blank_until_its_queued_check(): void {
		$product = $this->product_on_copy();
		$own     = $this->variation( $product, self::own_setup( self::COPY_ID, self::COPY_VERSION, false, self::BLANK_ID ) );
		$this->script_check();
		$this->live_list();
		$this->recorder->fail();

		$answer = $this->link( $product );

		$this->assertIsArray( $answer );
		$this->assertSame( array( 0, 1 ), array( $answer['variations_moved'], $answer['variations_moved_later'] ) );
		$moved = $this->stored( $own );
		$this->assertSame( array( self::BLANK_ID, self::PLACEMENT_ID ), array( $moved->blank_id, $moved->sides[0]->placement_id ), 'It keeps its blank and placement.' );
		$this->assertSame( array( self::ROOT_ID, self::ROOT_VERSION ), array( $moved->sides[0]->template_base_id, $moved->sides[0]->template_version ) );
		$this->assertSame( array( false, InvalidReason::CHECK_PENDING ), array( $moved->valid, $moved->invalid_reason ), 'Not sold until Pixonaut has checked it there.' );
		$this->assertEquals( array( new PrintSize( self::OWN_WIDTH_MM, self::OWN_HEIGHT_MM, '' ) ), $moved->sides[0]->menu(), 'Its print size is checked there.' );
		$queued = array();
		foreach ( ActionSchedulerRunner::pending( ProductRecheck::ACTION ) as $action_id ) {
			$queued = array_merge( $queued, \ActionScheduler::store()->fetch_action( (string) $action_id )->get_args()['product_ids'] );
		}
		$this->assertSame( array( $own ), $queued );
	}

	/**
	 * Links the product to the root template, template-only in the product's size.
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
				'template_id' => self::ROOT_ID . '@' . self::ROOT_VERSION,
				'width_mm'    => self::WIDTH_MM,
				'height_mm'   => self::HEIGHT_MM,
			)
		);
	}

	/**
	 * Scripts one check of a setup: its size's verdict, then the template.
	 */
	private function script_check(): void {
		$this->recorder->json( 200, array( 'verdicts' => array( VerdictAnswers::LEGAL ) ) );
		$this->recorder->raw( 200, OpenApiAssert::example( 'GET', '/v1/templates/{id}', 200, array( 'status' => Catalogue::LIVE ) ), 'application/json' );
	}

	/**
	 * Scripts `GET /v1/templates?status=live`: the root template and the unrelated one.
	 */
	private function live_list(): void {
		$items = array();
		foreach ( array(
			self::ROOT_ID  => self::ROOT_VERSION,
			self::OTHER_ID => self::OTHER_VERSION,
		) as $base_id => $version ) {
			$items[] = array(
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
			);
		}
		$this->recorder->json(
			200,
			array(
				'items'    => $items,
				'total'    => count( $items ),
				'page'     => 1,
				'pageSize' => Catalogue::LIST_PAGE_SIZE,
			)
		);
	}

	/**
	 * A variation's own template-only setup in its own size.
	 *
	 * @param string      $base_id  Its template.
	 * @param int         $version  The template's version.
	 * @param bool        $pinned   Whether its side stays on that version.
	 * @param string|null $blank_id Its blank, on its front; null for none.
	 */
	private static function own_setup( string $base_id, int $version, bool $pinned = false, ?string $blank_id = null ): ProductConfig {
		return new ProductConfig(
			personalise: true,
			override: true,
			blank_id: $blank_id,
			sides: array( new Side( self::SIDE_KEY, 'Kids tee', null === $blank_id ? null : self::PLACEMENT_ID, null, $base_id, $version, null, 'Kids tee', array( new PrintSize( self::OWN_WIDTH_MM, self::OWN_HEIGHT_MM, '' ) ), pinned: $pinned ) ),
		);
	}

	/**
	 * A variable product sold on one theme's copy of the template.
	 */
	private function product_on_copy(): WC_Product_Variable {
		$product = new WC_Product_Variable();
		$product->set_name( 'Kids tee' );
		$product->save();
		( new ProductConfig(
			personalise: true,
			sides: array( new Side( self::SIDE_KEY, 'Kids tee', null, null, self::COPY_ID, self::COPY_VERSION, null, 'Kids tee', array( new PrintSize( self::WIDTH_MM, self::HEIGHT_MM, '' ) ) ) ),
		) )->save_to( $product );
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
