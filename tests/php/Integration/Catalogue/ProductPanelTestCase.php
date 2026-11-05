<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Catalogue;

use Pixonaut\WooCommerce\Admin\CatalogueSearch;
use Pixonaut\WooCommerce\Admin\LinkedBlank;
use Pixonaut\WooCommerce\Admin\Notices;
use Pixonaut\WooCommerce\Admin\ProductPanel;
use Pixonaut\WooCommerce\Admin\VariantField;
use Pixonaut\WooCommerce\Admin\VariantNotice;
use Pixonaut\WooCommerce\Api\Catalogue;
use Pixonaut\WooCommerce\Api\Client;
use Pixonaut\WooCommerce\Api\Deprecations;
use Pixonaut\WooCommerce\Api\Designs;
use Pixonaut\WooCommerce\Api\Operations;
use Pixonaut\WooCommerce\Api\WcLogger;
use Pixonaut\WooCommerce\Api\WpHttpTransport;
use Pixonaut\WooCommerce\Catalogue\CatalogueCache;
use Pixonaut\WooCommerce\Catalogue\PostedSides;
use Pixonaut\WooCommerce\Catalogue\ProductLinker;
use Pixonaut\WooCommerce\Catalogue\ProductVariants;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Tests\Support\BlankFixture;
use Pixonaut\WooCommerce\Tests\Support\ConnectionAnswers;
use Pixonaut\WooCommerce\Tests\Support\HttpRecorder;
use Pixonaut\WooCommerce\Tests\Support\OpenApiAssert;
use Pixonaut\WooCommerce\Tests\Support\RecordingLogger;
use Pixonaut\WooCommerce\Tests\Support\StaticCredentials;
use Pixonaut\WooCommerce\Tests\Support\VariantsAnswer;
use WC_Product_Simple;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * The product screen's Pixonaut tab against a recorder that answers as
 * Pixonaut: a product is saved as the screen posts it.
 */
abstract class ProductPanelTestCase extends WP_UnitTestCase {

	/**
	 * Loopback, so the test bootstrap's host guard lets it through to the recorder.
	 */
	protected const BASE_URL = 'http://127.0.0.1:9';

	protected const BLANK_ID = 'blank_tee';

	protected const PLACEMENT_ID = 'front';

	protected const TEMPLATE_ID = 'mini-tee@2';

	protected const PRINT_WIDTH_MM = 280;

	protected const PRINT_HEIGHT_MM = 360;

	protected const PRINTSHOP_ID = BlankFixture::PRINTSHOP_ID;

	protected HttpRecorder $recorder;

	protected RecordingLogger $logger;

	protected CatalogueSearch $search;

	protected ProductPanel $panel;

	protected Plugin $plugin;

	protected Catalogue $catalogue;

	protected CatalogueCache $cache;

	protected VariantsAnswer $variants;

	/**
	 * Checks configurations against the recorder, as the panel does.
	 *
	 * @var ProductLinker
	 */
	protected ProductLinker $linker;

	public function set_up(): void {
		parent::set_up();
		$this->logger    = new RecordingLogger();
		$this->recorder  = ( new HttpRecorder( self::BASE_URL ) )->start();
		$this->variants  = ( new VariantsAnswer() )->start();
		$this->plugin    = Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' );
		$client          = new Client( self::BASE_URL, Operations::plugin(), new StaticCredentials(), new WpHttpTransport(), new WcLogger(), new Deprecations() );
		$this->catalogue = new Catalogue( $client );
		$this->cache     = new CatalogueCache( $this->plugin->connection_store() );
		$this->search    = new CatalogueSearch( $this->catalogue, $this->cache );
		$this->linker    = new ProductLinker( $this->catalogue, new Designs( $client ), $this->logger );
		$this->panel     = new ProductPanel( $this->linker, new LinkedBlank( $this->catalogue, $this->cache ), $this->plugin->notices(), $this->plugin->templates(), $this->plugin->settings(), $this->plugin->file(), $this->variant_field(), null, null, $this->plugin->proof_urls() );

		ConnectionAnswers::keep_tenant_slug();

		// The routes answer from the recorder rather than the plugin's own Pixonaut.
		remove_action( 'rest_api_init', array( $this->plugin->catalogue_search(), 'register_routes' ) );
		$this->search->register();
		$GLOBALS['wp_rest_server'] = new WP_REST_Server();
		do_action( 'rest_api_init', $GLOBALS['wp_rest_server'] );
	}

	/**
	 * The blank variant field, reading variants through the test's recorder.
	 */
	protected function variant_field(): VariantField {
		return new VariantField( new ProductVariants( $this->catalogue, new VariantNotice( $this->plugin->notices() ) ), $this->plugin->product_recheck(), $this->plugin->templates(), new WcLogger() );
	}

	public function tear_down(): void {
		$this->recorder->stop();
		$this->variants->stop();
		remove_action( 'rest_api_init', array( $this->search, 'register_routes' ) );
		add_action( 'rest_api_init', array( $this->plugin->catalogue_search(), 'register_routes' ) );
		$GLOBALS['wp_rest_server'] = null;
		$_POST                     = array();
		delete_option( Notices::OPTION );
		parent::tear_down();
	}

	/**
	 * Scripts the answers a one-sided save with a blank gets: the blank, the
	 * template's constraints on the placement, the size verdict, then, for a
	 * legal size, the template's read saying it is live.
	 *
	 * @param array<string, mixed> $verdict The verdict for the default size.
	 */
	protected function script_link( array $verdict ): void {
		$this->recorder->json( 200, $this->blank() );
		$this->recorder->json( 200, $this->constraints( self::PLACEMENT_ID ) );
		$this->recorder->json( 200, array( 'verdicts' => array( $verdict ) ) );
		if ( true === ( $verdict['legal'] ?? null ) ) {
			$this->script_template();
		}
	}

	/**
	 * Scripts `GET /v1/templates/{id}`, read once a save finds the product
	 * sellable.
	 *
	 * @param string $status `live` or `paused`.
	 */
	protected function script_template( string $status = Catalogue::LIVE ): void {
		$this->recorder->raw( 200, OpenApiAssert::example( 'GET', '/v1/templates/{id}', 200, array( 'status' => $status ) ), 'application/json' );
	}

	/**
	 * A `GET /v1/templates/{id}/constraints` answer on a placement: the tee's
	 * 280:360 artwork fitted into the default box.
	 *
	 * @param string $placement_id The placement.
	 * @return array<string, mixed>
	 */
	protected function constraints( string $placement_id ): array {
		return array(
			'templateId'     => self::TEMPLATE_ID,
			'ratio'          => array(
				'w' => self::PRINT_WIDTH_MM,
				'h' => self::PRINT_HEIGHT_MM,
			),
			'ratioTolerance' => 0,
			'fit'            => 'contain',
			'placement'      => array(
				'blankId'            => self::BLANK_ID,
				'placementId'        => $placement_id,
				'maxWidthMm'         => 330,
				'maxHeightMm'        => 420,
				'defaultWidthMm'     => 300,
				'defaultHeightMm'    => 360,
				'printWidthMm'       => self::PRINT_WIDTH_MM,
				'printHeightMm'      => self::PRINT_HEIGHT_MM,
				'bleedMm'            => 3,
				'safeAreaMm'         => 5,
				// Answered by every placement; the plugin does not draw colours.
				'cmykPreviewProfile' => 'ISOcoated_v2_eci',
				'coverage'           => 'within',
				'sizes'              => array(
					'fits'    => array(),
					'misfits' => array(),
				),
			),
		);
	}

	/**
	 * A `GET /v1/blanks/{id}` answer.
	 *
	 * @param array<string, string> $placements Placement names customers see, by id.
	 * @param string|null           $printshop  The printshop the blank resolves to.
	 * @param string|null           $source     Where that comes from, a `LinkedBlank::SOURCE_*`; null for
	 *                                          the blank's own, or the workspace's default when it names none.
	 * @return array<string, mixed>
	 */
	protected function blank( array $placements = array( self::PLACEMENT_ID => 'Front' ), ?string $printshop = null, ?string $source = null ): array {
		return BlankFixture::answer( $placements, $printshop, $source );
	}

	/**
	 * A `GET /v1/blanks/{id}/mockups` answer: each placement with one view
	 * named after it, holding one photo and, on the front, one more whose
	 * upload has not finished.
	 *
	 * @param array<string, string> $placements Placement names, by id.
	 * @return array<string, mixed>
	 */
	protected function mockups( array $placements = array( self::PLACEMENT_ID => 'Front' ) ): array {
		return BlankFixture::mockups( self::BASE_URL, $placements );
	}

	/**
	 * A `GET /v1/printshop-connections` answer: the workspace's default and one more.
	 *
	 * @return array<string, mixed>
	 */
	protected static function printshops(): array {
		return array(
			'connections' => array(
				array(
					'id'        => self::PRINTSHOP_ID,
					'name'      => 'Fixture Print AB',
					'adapter'   => 'custom',
					'active'    => true,
					'isDefault' => true,
				),
				array(
					'id'        => 'ps_other',
					'name'      => 'Other Print AB',
					'adapter'   => 'custom',
					'active'    => true,
					'isDefault' => false,
				),
			),
		);
	}

	/**
	 * Saves the product as the product screen does, as an administrator,
	 * with one side on the front in the placement's default size.
	 *
	 * @param int                  $product_id The product.
	 * @param array<string, mixed> $fields     Fields that differ from a linked product's; null leaves one out.
	 * @param array<string, mixed> $side       Side fields that differ.
	 */
	protected function save( int $product_id, array $fields = array(), array $side = array() ): void {
		$this->save_sides( $product_id, array( $side ), $fields );
	}

	/**
	 * Saves the product with several sides, each on the front with the live
	 * tee template in the placement's default size unless it says otherwise.
	 *
	 * @param int                        $product_id The product.
	 * @param list<array<string, mixed>> $sides      Each side's fields that differ.
	 * @param array<string, mixed>       $fields     Fields that differ from a linked product's; null leaves one out.
	 */
	protected function save_sides( int $product_id, array $sides, array $fields = array() ): void {
		wp_set_current_user( $this->user( 'administrator' ) );
		$posted = array_merge(
			array(
				ProductPanel::NONCE_FIELD => wp_create_nonce( ProductPanel::NONCE_ACTION ),
				'pixonaut_personalise'    => 'yes',
				'pixonaut_blank'          => self::BLANK_ID,
			),
			$fields
		);
		$_POST  = array_filter( $posted, static fn ( mixed $value ): bool => null !== $value );

		$_POST[ ProductPanel::SIDES_FIELD ] = array_map(
			static fn ( array $side ): array => array_merge(
				array(
					'key'           => '',
					'label'         => '',
					'placement'     => self::PLACEMENT_ID,
					'template'      => self::TEMPLATE_ID,
					'template_name' => 'Mini tee',
					'sizes'         => array( array( 'kind' => PostedSides::SIZE_PLACEMENT_DEFAULT ) ),
				),
				$side
			),
			$sides
		);
		$this->panel->save( $product_id );
		$_POST = array();
	}

	/**
	 * A side's fields without a blank: the template sold at the tee's artwork size.
	 *
	 * @return array<string, mixed>
	 */
	protected static function template_only_side(): array {
		return array(
			'placement' => '',
			'sizes'     => array(
				array(
					'kind'   => PostedSides::SIZE_CUSTOM,
					'width'  => (string) self::PRINT_WIDTH_MM,
					'height' => (string) self::PRINT_HEIGHT_MM,
					'label'  => 'Poster',
				),
			),
		);
	}

	protected function product(): WC_Product_Simple {
		$product = new WC_Product_Simple();
		$product->set_name( 'Personalised tee' );
		$product->set_regular_price( '25' );
		$product->save();
		return $product;
	}

	protected function reload( int $product_id ): WC_Product_Simple {
		$product = wc_get_product( $product_id );
		$this->assertInstanceOf( WC_Product_Simple::class, $product );
		return $product;
	}

	protected function user( string $role ): int {
		$id = self::factory()->user->create( array( 'role' => $role ) );
		return is_int( $id ) ? $id : 0;
	}
}
