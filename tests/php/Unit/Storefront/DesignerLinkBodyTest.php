<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Storefront;

use Brain\Monkey\Functions;
use Pixonaut\WooCommerce\Api\Deprecations;
use Pixonaut\WooCommerce\Api\DesignerLinks;
use Pixonaut\WooCommerce\Catalogue\PostedSides;
use Pixonaut\WooCommerce\Catalogue\PrintSize;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ProductVariant;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\FormLayout;
use Pixonaut\WooCommerce\Storefront\DesignerLinkBody;
use Pixonaut\WooCommerce\Tests\Support\RecordingLogger;
use Pixonaut\WooCommerce\Tests\Support\ScriptedClient;
use Pixonaut\WooCommerce\Tests\Unit\FakeOptions;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;

final class DesignerLinkBodyTest extends TestCase {

	use FakeOptions;

	private const BLANK = 'blank_tee';

	private const VARIANT = 'var_red';

	private const RETURN_URL = 'http://shop.test/?pixonaut_designer=return';

	private const LINK_URL = 'http://pixonaut.test/designer/dl_1';


	public function test_one_side_goes_at_the_top_level(): void {
		$link = DesignerLinkBody::for_config( self::config( array( self::front() ) ), self::RETURN_URL, 'en' );

		$this->assertNotNull( $link );
		$this->assertSame( 'mini-tee', $link->template_base_id );
		$this->assertSame(
			array(
				'blankId'                => self::BLANK,
				'placementId'            => 'front',
				'printSizes'             => array(
					array(
						'widthMm'  => 200.0,
						'heightMm' => 257.0,
						'label'    => 'Medium',
					),
				),
				'selectedPrintSizeIndex' => 0,
				'returnUrl'              => self::RETURN_URL,
				'locale'                 => 'en',
			),
			$link->body
		);
	}

	public function test_several_sides_go_under_sides_with_their_key_label_and_own_template(): void {
		$link = DesignerLinkBody::for_config( self::config( array( self::front(), self::back( 'mini-poster' ) ) ), self::RETURN_URL, 'sv' );

		$this->assertNotNull( $link );
		$this->assertSame( 'mini-tee', $link->template_base_id );
		$this->assertSame( array( 'sides', 'returnUrl', 'locale' ), array_keys( $link->body ) );
		$this->assertSame( 'sv', $link->body['locale'] );
		$this->assertSame(
			array(
				'key'         => 'front',
				'label'       => 'Front',
				'blankId'     => self::BLANK,
				'placementId' => 'front',
			),
			array_diff_key( $link->body['sides'][0], array_flip( array( 'printSizes', 'selectedPrintSizeIndex' ) ) )
		);
		$this->assertSame(
			array(
				'key'         => 'back',
				'label'       => 'Back',
				'blankId'     => self::BLANK,
				'placementId' => 'back',
				'templateId'  => 'mini-poster',
			),
			$link->body['sides'][1]
		);
	}

	public function test_a_side_on_the_first_sides_template_names_none(): void {
		$link = DesignerLinkBody::for_config( self::config( array( self::front(), self::back( 'mini-tee' ) ) ), self::RETURN_URL, 'en' );

		$this->assertNotNull( $link );
		$this->assertArrayNotHasKey( 'templateId', $link->body['sides'][1] );
	}

	public function test_a_template_only_product_sends_no_blank(): void {
		$side = new Side( 'design', 'Design', null, null, 'mini-tee', 2, null, null, array( new PrintSize( 300.0, 400.0, 'A3' ) ) );

		$link = DesignerLinkBody::for_config( new ProductConfig( true, false, null, null, array( $side ) ), self::RETURN_URL, 'en' );

		$this->assertNotNull( $link );
		$this->assertArrayNotHasKey( 'blankId', $link->body );
		$this->assertSame( 'en', $link->body['locale'] );
	}

	public function test_nothing_is_sent_for_a_product_that_cannot_be_personalised(): void {
		$unchecked = new Side( 'front', 'Front', 'front', null, 'mini-tee', null, null, null );

		$this->assertNull( DesignerLinkBody::for_config( self::config( array() ), self::RETURN_URL, 'en' ) );
		$this->assertNull( DesignerLinkBody::for_config( self::config( array( $unchecked ) ), self::RETURN_URL, 'en' ) );
		$this->assertNull( DesignerLinkBody::for_config( new ProductConfig( true, false, self::BLANK, null, array( self::front() ), null, null, false, 'unknown_blank' ), self::RETURN_URL, 'en' ) );
	}

	public function test_the_variant_sold_is_the_only_one_offered_and_the_one_started_on(): void {
		$link = DesignerLinkBody::for_config( self::config( array( self::front() ) ), self::RETURN_URL, 'en', self::variant() );

		$this->assertNotNull( $link );
		$this->assertSame( array( self::VARIANT ), $link->body['variants'] );
		$this->assertSame( self::VARIANT, $link->body['initialVariant'] );
		$this->assertArrayNotHasKey( 'views', $link->body );
	}

	public function test_without_a_variant_none_is_named(): void {
		$link = DesignerLinkBody::for_config( self::config( array( self::front() ) ), self::RETURN_URL, 'en' );

		$this->assertNotNull( $link );
		$this->assertArrayNotHasKey( 'variants', $link->body );
		$this->assertArrayNotHasKey( 'initialVariant', $link->body );
	}

	public function test_views_are_sent_only_where_the_merchant_left_one_out(): void {
		$config = self::config( array( self::front(), self::back( 'mini-tee' ) ), array( 'front-flat', 'back-flat', 'back-model', ProductConfig::DESIGN_VIEW ) );

		$link = DesignerLinkBody::for_config( $config, self::RETURN_URL, 'en', self::variant() );

		$this->assertNotNull( $link );
		$this->assertSame( array( 'front-flat', ProductConfig::DESIGN_VIEW ), $link->body['sides'][0]['views'] );
		$this->assertArrayNotHasKey( 'views', $link->body['sides'][1] );
		$this->assertSame( array( self::VARIANT ), $link->body['sides'][1]['variants'] );
		$this->assertSame( self::VARIANT, $link->body['sides'][1]['initialVariant'] );
	}

	public function test_every_photographed_view_and_the_design_view_ticked_sends_none(): void {
		$config = self::config( array( self::front() ), array( 'front-model', ProductConfig::DESIGN_VIEW, 'front-flat', 'front-unshot' ) );

		$link = DesignerLinkBody::for_config( $config, self::RETURN_URL, 'en', self::variant() );

		$this->assertNotNull( $link );
		$this->assertArrayNotHasKey( 'views', $link->body );
	}

	public function test_the_design_view_left_out_sends_the_photographed_views(): void {
		$config = self::config( array( self::front() ), array( 'front-model', 'front-flat' ) );

		$link = DesignerLinkBody::for_config( $config, self::RETURN_URL, 'en', self::variant() );

		$this->assertNotNull( $link );
		$this->assertSame( array( 'front-model', 'front-flat' ), $link->body['views'] );
	}

	public function test_the_design_view_alone_is_sent_alone(): void {
		$config = new ProductConfig( true, false, self::BLANK, 'Tee', array( self::front() ), views: array( ProductConfig::DESIGN_VIEW ), initial_view: ProductConfig::DESIGN_VIEW );

		$link = DesignerLinkBody::for_config( $config, self::RETURN_URL, 'en', self::variant() );

		$this->assertNotNull( $link );
		$this->assertSame( array( ProductConfig::DESIGN_VIEW ), $link->body['views'] );
		$this->assertSame( ProductConfig::DESIGN_VIEW, $link->body['initialView'] );
	}

	public function test_the_print_area_frame_is_asked_for_only_when_on(): void {
		$config = self::config( array( self::front() ) );

		$framed = DesignerLinkBody::for_config( $config, self::RETURN_URL, 'en', null, true );
		$plain  = DesignerLinkBody::for_config( $config, self::RETURN_URL, 'en' );

		$this->assertNotNull( $framed );
		$this->assertNotNull( $plain );
		$this->assertTrue( $framed->body['printAreaFrame'] );
		$this->assertArrayNotHasKey( 'printAreaFrame', $plain->body );
	}

	public function test_the_form_layout_is_sent_only_when_fixed(): void {
		$config = self::config( array( self::front() ) );

		$steps    = DesignerLinkBody::for_config( $config, self::RETURN_URL, 'en', null, false, FormLayout::STEPS );
		$template = DesignerLinkBody::for_config( $config, self::RETURN_URL, 'en' );

		$this->assertNotNull( $steps );
		$this->assertNotNull( $template );
		$this->assertSame( FormLayout::STEPS, $steps->body['layout'] );
		$this->assertArrayNotHasKey( 'layout', $template->body );
	}

	public function test_each_side_starts_on_its_chosen_size_and_the_products_starting_view(): void {
		$sizes  = array( new PrintSize( 200.0, 257.0, 'Medium' ), new PrintSize( 280.0, 360.0, 'Large' ) );
		$front  = self::front()->with( 2, null, $sizes, null, 1 );
		$config = new ProductConfig( true, false, self::BLANK, 'Tee', array( $front, self::back( 'mini-tee' ) ), views: array( 'front-flat', 'back-flat' ), initial_view: 'back-flat' );

		$link = DesignerLinkBody::for_config( $config, self::RETURN_URL, 'en', self::variant() );

		$this->assertNotNull( $link );
		$this->assertSame( 1, $link->body['sides'][0]['selectedPrintSizeIndex'] );
		// Each side's designer opens on it only when it is that side's placement's.
		$this->assertSame( array( 'back-flat', 'back-flat' ), array_column( $link->body['sides'], 'initialView' ) );
	}

	public function test_without_a_starting_view_none_is_named(): void {
		$link = DesignerLinkBody::for_config( self::config( array( self::front() ) ), self::RETURN_URL, 'en' );

		$this->assertNotNull( $link );
		$this->assertArrayNotHasKey( 'initialView', $link->body );
	}

	public function test_views_are_sent_as_ticked_when_the_variant_names_none_of_the_placement(): void {
		$config  = self::config( array( self::front() ), array( 'front-flat' ) );
		$variant = new ProductVariant( self::BLANK, null, self::VARIANT, 'Red' );

		$link = DesignerLinkBody::for_config( $config, self::RETURN_URL, 'en', $variant );

		$this->assertNotNull( $link );
		$this->assertSame( array( 'front-flat' ), $link->body['views'] );
	}

	public function test_the_link_is_minted_for_the_first_sides_template_and_its_url_answered(): void {
		$this->fake_options();
		Functions\when( 'wp_json_encode' )->alias( static fn ( $value ) => json_encode( $value ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		$transport = new ScriptedClient();
		$links     = new DesignerLinks( $transport->sdk( ScriptedClient::credentials(), new RecordingLogger() ), new RecordingLogger() );
		$transport->json(
			201,
			array(
				'id'             => 'shl_1',
				'token'          => 'dl_1',
				'url'            => self::LINK_URL,
				'expiresAt'      => 1792111521804,
				'name'           => null,
				'brandId'        => null,
				'designLimit'    => null,
				'printAreaFrame' => false,
				'placementFrame' => false,
				'locale'         => null,
			)
		);
		$link = DesignerLinkBody::for_config( self::config( array( self::front(), self::back( 'mini-poster' ) ) ), self::RETURN_URL, 'en' );
		$this->assertNotNull( $link );

		$this->assertSame( self::LINK_URL, $links->create( $link->template_base_id, $link->body )['url'] );

		$sent = $transport->sent[0];
		$this->assertStringEndsWith( '/v1/templates/mini-tee/designer-links', $sent['url'] );
		// JSON writes a whole millimetre without its fraction.
		$this->assertEquals( $link->body, json_decode( (string) $sent['body'], true ) );
	}

	public function test_every_side_up_to_ten_is_sent_and_a_warning_on_the_link_is_logged(): void {
		$this->fake_options();
		Functions\when( 'wp_json_encode' )->alias( static fn ( $value ) => json_encode( $value ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		$transport = new ScriptedClient();
		$logger    = new RecordingLogger();
		$links     = new DesignerLinks( $transport->sdk( ScriptedClient::credentials(), new RecordingLogger() ), $logger );
		$transport->json(
			201,
			array(
				'id'             => 'shl_1',
				'token'          => 'dl_1',
				'url'            => self::LINK_URL,
				'expiresAt'      => 1792111521804,
				'name'           => null,
				'brandId'        => null,
				'designLimit'    => null,
				'printAreaFrame' => false,
				'placementFrame' => false,
				'locale'         => null,
				'warnings'       => array(
					array(
						'code'        => DesignerLinks::SIDES_OVER_RECOMMENDED,
						'recommended' => PostedSides::RECOMMENDED_SIDES,
						'sides'       => PostedSides::MAX_SIDES,
					),
				),
			)
		);
		$sides = array();
		for ( $i = 1; $i <= PostedSides::MAX_SIDES; $i++ ) {
			$sides[] = new Side( "side-{$i}", "Side {$i}", "placement-{$i}", null, 'mini-tee', 2, null, null );
		}
		$link = DesignerLinkBody::for_config( self::config( $sides ), self::RETURN_URL, 'en' );
		$this->assertNotNull( $link );
		$this->assertCount( PostedSides::MAX_SIDES, $link->body['sides'] );

		$this->assertSame( self::LINK_URL, $links->create( $link->template_base_id, $link->body )['url'] );

		$this->assertCount( 1, $logger->entries );
		$this->assertSame( 'warning', $logger->entries[0]['level'] );
		$this->assertStringContainsString( 'with 10 sides; its designer is built for at most 4', $logger->entries[0]['message'] );
		$this->assertSame( DesignerLinks::SIDES_OVER_RECOMMENDED, $logger->entries[0]['context']['warning']['code'] );
	}

	/**
	 * @param Side[]   $sides The product's sides.
	 * @param string[] $views The ids of the views ticked.
	 * @phpstan-param list<Side> $sides
	 * @phpstan-param list<string> $views
	 */
	private static function config( array $sides, array $views = array() ): ProductConfig {
		return new ProductConfig( true, false, self::BLANK, 'Tee', $sides, views: $views );
	}

	private static function variant(): ProductVariant {
		return new ProductVariant(
			self::BLANK,
			null,
			self::VARIANT,
			'Red',
			null,
			array(
				array(
					'id'        => 'front-flat',
					'placement' => 'front',
					'label'     => 'Flat',
				),
				array(
					'id'        => 'front-model',
					'placement' => 'front',
					'label'     => 'On model',
				),
				array(
					'id'        => 'back-flat',
					'placement' => 'back',
					'label'     => 'Flat',
				),
				array(
					'id'        => 'back-model',
					'placement' => 'back',
					'label'     => 'On model',
				),
			)
		);
	}

	private static function front(): Side {
		return new Side( 'front', 'Front', 'front', 'Front', 'mini-tee', 2, null, 'Mini tee', array( new PrintSize( 200.0, 257.0, 'Medium' ) ) );
	}

	private static function back( string $template ): Side {
		return new Side( 'back', 'Back', 'back', 'Back', $template, 1, null, 'Back template' );
	}
}
