<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Storefront;

use MO;
use Pixonaut\WooCommerce\Api\Catalogue;
use Pixonaut\WooCommerce\Api\Deprecations;
use Pixonaut\WooCommerce\Api\HookListener;
use Pixonaut\WooCommerce\Api\Pixonaut;
use Pixonaut\WooCommerce\Api\WcLogger;
use Pixonaut\WooCommerce\Catalogue\CatalogueCache;
use Pixonaut\WooCommerce\Catalogue\PrintSize;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Connection\ConnectionStore;
use Pixonaut\WooCommerce\Connection\WorkspaceSettings;
use Pixonaut\WooCommerce\DesignerLanguage;
use Pixonaut\WooCommerce\FormLayout;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Storefront\ServerReads;
use Pixonaut\WooCommerce\Storefront\Storefront;
use Pixonaut\WooCommerce\Tests\Support\HttpRecorder;
use Pixonaut\WooCommerce\Tests\Support\OpenApiAssert;
use Translation_Entry;
use WP_UnitTestCase;

final class ServerReadsTest extends WP_UnitTestCase {

	/**
	 * Loopback, so the test bootstrap's host guard lets it through to the recorder.
	 */
	private const BASE_URL = 'http://127.0.0.1:9';

	/**
	 * The size of the spec example's front photo.
	 */
	private const PHOTO_WIDTH = 2400;

	private const PHOTO_HEIGHT = 3000;

	private const JSON = 'application/json';

	private const FRONT = array(
		'templateId'  => 'mini-tee@2',
		'blankId'     => 'blank_tee',
		'placementId' => 'front',
	);

	private HttpRecorder $recorder;

	private ConnectionStore $store;

	private Plugin $plugin;

	private ServerReads $reads;

	public function set_up(): void {
		parent::set_up();
		$this->recorder = ( new HttpRecorder( self::BASE_URL ) )->start();
		$this->plugin   = Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' );
		$this->store    = $this->plugin->connection_store();
		StorefrontTest::connect( $this->store );
		$client      = ( new Pixonaut( $this->store, new WcLogger(), new HookListener( new Deprecations() ), null, self::BASE_URL ) )->sdk();
		$this->reads = new ServerReads( new Catalogue( $client ), new CatalogueCache( $this->store ), $client );
		( new CatalogueCache( $this->store ) )->flush();
		delete_transient( ServerReads::PAUSE_TRANSIENT );
	}

	public function tear_down(): void {
		$this->recorder->stop();
		$this->store->clear();
		WorkspaceSettings::forget();
		delete_transient( ServerReads::PAUSE_TRANSIENT );
		unset( $GLOBALS['product'] );
		parent::tear_down();
	}

	public function test_a_templates_group_ids_are_read_for_a_side_with_no_read_and_kept(): void {
		$template = (string) OpenApiAssert::example( 'GET', '/v1/templates/{id}', 200 );
		$this->recorder->raw( 200, $template, 'application/json' );
		$decoded = json_decode( $template, true );

		$groups = $this->reads->template_groups( 'mini-tee@2', null );

		$this->assertSame( array_column( $decoded['formSchema']['steps'], 'groupId' ), $groups );
		$this->assertSame( $groups, $this->reads->template_groups( 'mini-tee@2', null ) );
		$this->assertCount( 1, $this->recorder->requests );
	}

	public function test_a_templates_group_ids_are_null_when_pixonaut_cannot_be_asked(): void {
		$this->recorder->json( 500, array( 'error' => 'internal_error' ) );

		$this->assertNull( $this->reads->template_groups( 'mini-tee@2', null ) );
		$this->assertNull( $this->reads->template_groups( 'mini-tee@2', null ), 'and it does not ask again at once' );
		$this->assertCount( 1, $this->recorder->requests );
	}

	public function test_a_side_on_a_blank_reads_its_template_its_placement_and_the_blanks_views(): void {
		$this->answer_side();

		$reads = $this->reads->for_mounts( array( 'front' => self::FRONT ) );

		$this->assertSame(
			array(
				'/v1/templates/mini-tee%402',
				'/v1/templates/mini-tee%402/constraints?blankId=blank_tee&placementId=front',
				'/v1/blanks/blank_tee/mockups?lang=en',
				'/v1/blanks/blank_tee/variants?lang=en',
			),
			$this->paths()
		);
		$this->assertSame( self::example_json( '/v1/templates/{id}' ), $reads['front']['template'] );
		$this->assertSame( self::constraints_json(), $reads['front']['placement'] );
		$this->assertSame( array( 'mockups', 'variants' ), array_keys( $reads['front']['blank'] ) );
	}

	public function test_the_template_and_the_blanks_views_are_read_in_the_designers_language_and_kept_per_language(): void {
		$this->answer_side();

		$this->reads->for_mounts( array( 'front' => array( 'locale' => 'sv' ) + self::FRONT ) );

		$this->assertSame(
			array(
				'/v1/templates/mini-tee%402?lang=sv',
				'/v1/templates/mini-tee%402/constraints?blankId=blank_tee&placementId=front',
				'/v1/blanks/blank_tee/mockups?lang=sv',
				'/v1/blanks/blank_tee/variants?lang=sv',
			),
			$this->paths()
		);

		$this->recorder->requests = array();
		$this->recorder
			->raw( 200, self::example_json( '/v1/templates/{id}' ), self::JSON )
			->raw( 200, self::example_json( '/v1/blanks/{id}/mockups' ), self::JSON )
			->raw( 200, self::example_json( '/v1/blanks/{id}/variants' ), self::JSON );
		$this->reads->for_mounts( array( 'front' => array( 'locale' => 'de' ) + self::FRONT ) );

		$this->assertSame( array( '/v1/templates/mini-tee%402?lang=de', '/v1/blanks/blank_tee/mockups?lang=de', '/v1/blanks/blank_tee/variants?lang=de' ), $this->paths() );
	}

	public function test_a_locale_pixonaut_does_not_speak_reads_the_template_in_the_workspaces_language(): void {
		$this->answer_side();

		$this->reads->for_mounts( array( 'front' => array( 'locale' => 'xx' ) + self::FRONT ) );

		$this->assertSame( '/v1/templates/mini-tee%402', $this->paths()[0] );
	}

	public function test_without_a_locale_the_blanks_views_are_read_in_the_language_the_template_names_for_its_workspace(): void {
		update_option( WorkspaceSettings::OPTION, array( 'default_language' => 'fi' ) );
		$this->answer_side( 'sv' );

		$this->reads->for_mounts( array( 'front' => self::FRONT ) );

		$this->assertSame( array( '/v1/blanks/blank_tee/mockups?lang=sv', '/v1/blanks/blank_tee/variants?lang=sv' ), array_slice( $this->paths(), 2 ) );
	}

	public function test_a_correction_reads_the_blanks_views_in_the_workspaces_default_language_as_last_read(): void {
		update_option( WorkspaceSettings::OPTION, array( 'default_language' => 'fi' ) );
		$this->recorder
			->raw( 200, self::example_json( '/v1/designs/{id}' ), self::JSON )
			->raw( 200, self::example_json( '/v1/blanks/{id}/mockups' ), self::JSON )
			->raw( 200, self::example_json( '/v1/blanks/{id}/variants' ), self::JSON );

		$this->reads->correction_views( array( 'front' => array( 'design' => 'des_1' ) + self::FRONT ) );

		$this->assertSame( array( '/v1/blanks/blank_tee/mockups?lang=fi', '/v1/blanks/blank_tee/variants?lang=fi' ), array_slice( $this->paths(), 1 ) );
	}

	public function test_the_reads_are_kept_for_the_next_page(): void {
		$this->answer_side();
		$this->reads->for_mounts( array( 'front' => self::FRONT ) );
		$this->recorder->requests = array();

		$again = $this->reads->for_mounts( array( 'front' => self::FRONT ) );

		$this->assertSame( array(), $this->recorder->requests );
		$this->assertArrayHasKey( 'blank', $again['front'] );
	}

	public function test_a_side_without_a_blank_reads_its_template_alone(): void {
		$this->recorder->raw( 200, self::example_json( '/v1/templates/{id}' ), self::JSON );

		$reads = $this->reads->for_mounts( array( 'front' => array( 'templateId' => 'mini-tee@2' ) ) );

		$this->assertSame( array( '/v1/templates/mini-tee%402' ), $this->paths() );
		$this->assertSame( array( 'template' ), array_keys( $reads['front'] ) );
	}

	public function test_a_side_opening_a_stored_design_reads_nothing(): void {
		$reads = $this->reads->for_mounts(
			array(
				'front' => array(
					'correctionLink' => 'cl_token',
					'design'         => 'des_1',
				),
			)
		);

		$this->assertSame( array(), $reads );
		$this->assertSame( array(), $this->recorder->requests );
	}

	public function test_a_correction_reads_its_designs_print_size_and_on_a_blank_the_blanks_views_for_its_layout(): void {
		$this->recorder
			->raw( 200, self::example_json( '/v1/designs/{id}' ), self::JSON )
			->raw( 200, self::example_json( '/v1/blanks/{id}/mockups' ), self::JSON )
			->raw( 200, self::example_json( '/v1/blanks/{id}/variants' ), self::JSON )
			->raw( 200, self::example_json( '/v1/designs/{id}' ), self::JSON );
		$correction = array(
			'correctionLink' => 'cl_token',
			'design'         => 'des_1',
		);

		$views = $this->reads->correction_views(
			array(
				'front' => $correction + self::FRONT,
				'back'  => array( 'design' => 'des_2' ) + $correction,
				'other' => self::FRONT,
			)
		);

		$this->assertSame( array( '/v1/designs/des_1', '/v1/blanks/blank_tee/mockups', '/v1/blanks/blank_tee/variants', '/v1/designs/des_2' ), $this->paths() );
		$this->assertSame( array( 'front', 'back' ), array_keys( $views ) );
		$this->assertSame( array( 'printSize', 'blank' ), array_keys( $views['front'] ) );
		$this->assertSame( array( 'mockups', 'variants' ), array_keys( $views['front']['blank'] ) );
		$this->assertSame( array( 'printSize' ), array_keys( $views['back'] ) );
		$this->assertSame(
			array(
				'w' => 300,
				'h' => 400,
			),
			json_decode( $views['back']['printSize'], true )
		);
	}

	public function test_a_correction_keeps_its_print_size_when_the_blanks_views_fail(): void {
		$this->recorder->raw( 200, self::example_json( '/v1/designs/{id}' ), self::JSON );
		$this->recorder->fail();

		$views = $this->reads->correction_views( array( 'front' => array( 'design' => 'des_1' ) + self::FRONT ) );

		$this->assertSame( array( 'printSize' ), array_keys( $views['front'] ) );
		$this->assertNotFalse( get_transient( ServerReads::PAUSE_TRANSIENT ) );
	}

	public function test_a_corrections_print_size_is_kept_for_the_next_page(): void {
		$this->recorder->raw( 200, self::example_json( '/v1/designs/{id}' ), self::JSON );
		$this->reads->correction_views( array( 'front' => array( 'design' => 'des_1' ) ) );
		$this->recorder->requests = array();

		$views = $this->reads->correction_views( array( 'front' => array( 'design' => 'des_1' ) ) );

		$this->assertSame( array(), $this->recorder->requests );
		$this->assertSame( array( 'printSize' ), array_keys( $views['front'] ) );
	}

	public function test_a_failed_read_leaves_the_side_to_the_browser_and_pauses_the_reads(): void {
		$this->recorder->fail();

		$reads = $this->reads->for_mounts(
			array(
				'front' => self::FRONT,
				'back'  => array( 'placementId' => 'back' ) + self::FRONT,
			)
		);

		$this->assertSame( array(), $reads );
		$this->assertCount( 1, $this->recorder->requests );
		$this->assertNotFalse( get_transient( ServerReads::PAUSE_TRANSIENT ) );
		$this->assertSame( array(), $this->reads->for_mounts( array( 'front' => self::FRONT ) ) );
		$this->assertCount( 1, $this->recorder->requests );
	}

	public function test_the_page_prints_the_designer_at_its_final_layout_with_the_reads(): void {
		$this->answer_side();
		$storefront = new Storefront( $this->plugin->inline_products(), $this->plugin->templates(), $this->plugin->labels(), $this->plugin->settings(), dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php', $this->reads );
		$product    = StorefrontTest::product( StorefrontTest::personalised() );
		$this->go_to( (string) get_permalink( $product->get_id() ) );
		$GLOBALS['product'] = $product;

		$storefront->replace_classic_gallery();
		ob_start();
		$storefront->render_classic_preview();
		$storefront->render_form();
		$html = (string) ob_get_clean();

		$this->assertCount( 4, $this->recorder->requests, 'the preview and the form share one set of reads' );
		$this->assertStringContainsString( 'style="--pixonaut-preview-ratio: 2400 / 3000; --pixonaut-preview-aspect: 0.8"', $html );
		$this->assertStringContainsString( 'id="pixonaut-views-front"', $html );
		$this->assertStringContainsString( '<span class="pixonaut-placeholder__step">Upload a motif</span>', $html );
		$this->assertStringContainsString( 'class="pixonaut-placeholder__field" data-pixonaut-type="image"', $html );
		$this->assertStringContainsString( 'role="status"', $html );
		$this->assertSame( 1, preg_match( '#<script type="application/json" class="pixonaut-reads" data-pixonaut-read-at="\d+">(.*?)</script>#s', $html, $match ) );
		$printed = json_decode( $match[1] ?? '', true );
		$this->assertIsArray( $printed );
		$this->assertSame( self::example( '/v1/templates/{id}' ), $printed['front']['template'] );
		$this->assertStringContainsString( '"constraints":{}', $match[1] ?? '', 'an empty object stays one' );
	}

	public function test_a_form_in_steps_prints_the_products_options_as_its_first_step(): void {
		$html = $this->steps_form( array( new PrintSize( 300.0, 360.0, '30×36' ), new PrintSize( 400.0, 480.0, '40×48' ) ) );

		// The options' step with its print size picker, then nothing of the template's until the customer moves on.
		$this->assertStringContainsString( '<span class="pixonaut-placeholder__step-title">Options</span>', $html );
		$this->assertStringContainsString( 'pixonaut-placeholder__sizes', $html );
		$this->assertStringContainsString( 'Next: Upload a motif', $html );
		$this->assertStringNotContainsString( 'data-pixonaut-type=', $html );
	}

	public function test_a_form_in_steps_with_one_print_size_opens_on_the_templates_first_step(): void {
		$html = $this->steps_form( array( new PrintSize( 300.0, 360.0, '30×36' ) ) );

		$this->assertStringContainsString( '<span class="pixonaut-placeholder__step-title">Upload a motif</span>', $html );
		$this->assertStringNotContainsString( 'pixonaut-placeholder__sizes', $html );
		$this->assertStringContainsString( 'data-pixonaut-type=', $html );
	}

	public function test_the_printed_form_speaks_the_designers_language_whatever_the_sites(): void {
		$languages = get_temp_dir();
		$swedish   = new MO();
		$swedish->add_entry(
			new Translation_Entry(
				array(
					'singular'     => 'Upload an image',
					'translations' => array( 'Ladda upp en bild' ),
				)
			)
		);
		$swedish->export_to_file( $languages . Plugin::TEXT_DOMAIN . '-sv_SE.mo' );
		$from_test = static fn ( $path, string $domain, string $locale ) => Plugin::TEXT_DOMAIN === $domain && 'sv_SE' === $locale ? $languages : $path;
		add_filter( 'lang_dir_for_domain', $from_test, 10, 3 );
		$this->plugin->settings()->set( Settings::DESIGNER_LANGUAGE, DesignerLanguage::SWEDISH );
		$this->answer_side();
		$storefront = new Storefront( $this->plugin->inline_products(), $this->plugin->templates(), $this->plugin->labels(), $this->plugin->settings(), dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php', $this->reads );
		$product    = StorefrontTest::product( StorefrontTest::personalised() );
		$this->go_to( (string) get_permalink( $product->get_id() ) );
		$GLOBALS['product'] = $product;

		ob_start();
		$storefront->render_form();
		$html = (string) ob_get_clean();
		remove_filter( 'lang_dir_for_domain', $from_test );
		wp_delete_file( $languages . Plugin::TEXT_DOMAIN . '-sv_SE.mo' );

		$this->assertSame( 'en_US', determine_locale() );
		$this->assertStringContainsString( 'Ladda upp en bild', $html );
		$this->assertSame( 'Upload an image', __( 'Upload an image', 'pixonaut-for-woocommerce' ), 'the page keeps its own words' );
	}

	public function test_without_reads_the_page_prints_a_square_preview_and_an_empty_form(): void {
		set_transient( ServerReads::PAUSE_TRANSIENT, 1, ServerReads::PAUSE_SECONDS );
		$storefront = new Storefront( $this->plugin->inline_products(), $this->plugin->templates(), $this->plugin->labels(), $this->plugin->settings(), dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php', $this->reads );
		$product    = StorefrontTest::product( StorefrontTest::personalised() );
		$this->go_to( (string) get_permalink( $product->get_id() ) );
		$GLOBALS['product'] = $product;

		ob_start();
		$storefront->render_classic_preview();
		$storefront->render_form();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '--pixonaut-preview-ratio: 1 / 1', $html );
		$this->assertStringNotContainsString( 'pixonaut-views', $html );
		$this->assertStringNotContainsString( 'pixonaut-reads', $html );
		$this->assertStringNotContainsString( 'pixonaut-placeholder__step', $html );
	}

	/**
	 * The form of a personalised product laid out in steps, sold in
	 * `$sizes`.
	 *
	 * @param PrintSize[] $sizes The side's print sizes.
	 * @phpstan-param list<PrintSize> $sizes
	 */
	private function steps_form( array $sizes ): string {
		$this->answer_side();
		$storefront = new Storefront( $this->plugin->inline_products(), $this->plugin->templates(), $this->plugin->labels(), $this->plugin->settings(), dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php', $this->reads );
		$config     = StorefrontTest::personalised();
		$front      = $config->sides[0];
		$side       = new Side( $front->key, $front->label, $front->placement_id, $front->placement_name, $front->template_base_id, $front->template_version, null, $front->template_name, $sizes );
		$product    = StorefrontTest::product( new ProductConfig( personalise: true, blank_id: $config->blank_id, blank_name: $config->blank_name, sides: array( $side ), form_layout: FormLayout::STEPS ) );
		$this->go_to( (string) get_permalink( $product->get_id() ) );
		$GLOBALS['product'] = $product;

		ob_start();
		$storefront->render_form();
		return (string) ob_get_clean();
	}

	/**
	 * Queues Pixonaut's answers to one side's reads, in the order they are made.
	 *
	 * @param string|null $workspace_language The template answer's `workspaceLanguage`; null for the spec example's.
	 */
	private function answer_side( ?string $workspace_language = null ): void {
		$mockups = json_decode( self::example_json( '/v1/blanks/{id}/mockups' ) );
		foreach ( $mockups->placements as $placement ) {
			foreach ( $placement->views as $view ) {
				foreach ( $view->photos as $photo ) {
					$photo->width  = self::PHOTO_WIDTH;
					$photo->height = self::PHOTO_HEIGHT;
				}
			}
		}
		$template = json_decode( self::example_json( '/v1/templates/{id}' ) );
		if ( null !== $workspace_language ) {
			$template->workspaceLanguage = $workspace_language; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Pixonaut's field.
		}
		$this->recorder
			->raw( 200, (string) wp_json_encode( $template ), self::JSON )
			->raw( 200, self::constraints_json(), self::JSON )
			->raw( 200, (string) wp_json_encode( $mockups ), self::JSON )
			->raw( 200, self::example_json( '/v1/blanks/{id}/variants' ), self::JSON );
	}

	/**
	 * The paths of the requests made, below the base.
	 *
	 * @return list<string>
	 */
	private function paths(): array {
		return array_map( static fn ( array $request ): string => substr( $request['url'], strlen( self::BASE_URL ) ), $this->recorder->requests );
	}

	/**
	 * The pinned spec's example constraints answer, with the `sizes` its
	 * placement must carry, which the example leaves out: a placement whose
	 * print area does not change with an option names none.
	 */
	private static function constraints_json(): string {
		$constraints                   = json_decode( self::example_json( '/v1/templates/{id}/constraints' ) );
		$constraints->placement->sizes = array(
			'fits'    => array(),
			'misfits' => array(),
		);
		return (string) wp_json_encode( $constraints );
	}

	/**
	 * The pinned spec's example answer of a read, as JSON text with its
	 * empty objects kept.
	 *
	 * @param string $path The read's path.
	 */
	private static function example_json( string $path ): string {
		return (string) wp_json_encode( json_decode( OpenApiAssert::example( 'GET', $path, 200 ) ) );
	}

	/**
	 * The pinned spec's example answer of a read.
	 *
	 * @param string $path The read's path.
	 * @return array<string, mixed>
	 */
	private static function example( string $path ): array {
		return json_decode( self::example_json( $path ), true );
	}
}
