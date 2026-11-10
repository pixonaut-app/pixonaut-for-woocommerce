<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Catalogue;

use Pixonaut\WooCommerce\Admin\PanelFields;
use Pixonaut\WooCommerce\Admin\ProductPanel;
use Pixonaut\WooCommerce\Admin\VariationPanel;
use Pixonaut\WooCommerce\Api\Hosts;
use Pixonaut\WooCommerce\Catalogue\CanvasRatio;
use Pixonaut\WooCommerce\Catalogue\InvalidReason;
use Pixonaut\WooCommerce\Catalogue\PictureView;
use Pixonaut\WooCommerce\Catalogue\PostedSides;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Tests\Support\ConnectionAnswers;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Variable;
use WC_Product_Variation;

/**
 * A variable product's Pixonaut tab and each variation's panel, saved as the
 * product screen posts them.
 */
final class VariationPanelTest extends ProductPanelTestCase {

	private const LEGAL = array( 'legal' => true );

	private const ILLEGAL = array(
		'legal'  => false,
		'reason' => InvalidReason::TOO_LARGE,
	);

	private const FIRST_VERSION = 1;

	/**
	 * A version published after `TEMPLATE_ID`'s.
	 */
	private const PUBLISHED_VERSION = 3;

	private const A4_WIDTH_MM = '210';

	private const A4_HEIGHT_MM = '297';

	private VariationPanel $variations;

	public function set_up(): void {
		parent::set_up();
		$this->variations = new VariationPanel( $this->panel, $this->plugin->templates(), $this->variant_field() );
	}

	public function test_a_variable_product_is_linked_in_its_pixonaut_tab(): void {
		list( $parent ) = $this->variable_product();
		$this->script_link( self::LEGAL );

		$this->save( $parent->get_id() );

		$config = ProductConfig::stored_on( $this->reload_any( $parent->get_id() ) );
		$this->assertTrue( $config->personalise );
		$this->assertTrue( $config->valid );
		$this->assertContains( 'show_if_variable', $this->panel->add_tab( array() )[ ProductPanel::TAB ]['class'] );
	}

	public function test_a_variation_without_an_override_inherits_and_asks_pixonaut_nothing(): void {
		list( $parent, $small ) = $this->linked_variable_product();
		$requests               = count( $this->recorder->requests );

		$this->save_variation( $small, 0, null );

		$this->assertCount( $requests, $this->recorder->requests );
		$this->assertFalse( ProductConfig::is_stored_on( $this->reload_any( $small->get_id() ) ) );
		$resolved = ProductConfig::config_for( $this->reload_any( $small->get_id() ) );
		$this->assertSame( self::BLANK_ID, $resolved->blank_id );
		$this->assertEquals( ProductConfig::stored_on( $this->reload_any( $parent->get_id() ) )->sides[0]->print_sizes, $resolved->sides[0]->print_sizes );
	}

	public function test_an_overriding_variation_is_checked_and_sold_with_its_own_menu(): void {
		list( , $small, $large ) = $this->linked_variable_product();
		$this->script_custom_size( self::LEGAL );

		$this->save_variation( $large, 1, $this->a4_side() );

		$own = ProductConfig::stored_on( $this->reload_any( $large->get_id() ) );
		$this->assertTrue( $own->override );
		$this->assertTrue( $own->valid );
		$this->assertSame( 'A4', ProductConfig::config_for( $this->reload_any( $large->get_id() ) )->sides[0]->print_sizes[0]->label );
		$this->assertNotSame( 'A4', ProductConfig::config_for( $this->reload_any( $small->get_id() ) )->sides[0]->print_sizes[0]->label );
	}

	public function test_an_invalid_override_stops_only_that_variation_until_unticked(): void {
		list( $parent, $small, $large ) = $this->linked_variable_product();
		$this->script_custom_size( self::ILLEGAL );

		$this->save_variation( $large, 1, $this->a4_side() );

		$this->assertFalse( $this->reload_any( $large->get_id() )->is_purchasable() );
		$this->assertTrue( $this->reload_any( $small->get_id() )->is_purchasable() );
		$this->assertTrue( $this->reload_any( $parent->get_id() )->is_purchasable() );
		$key = ProductPanel::notice_key( $large->get_id() );
		$this->assertTrue( $this->plugin->notices()->has( $key ) );
		$requests = count( $this->recorder->requests );

		$this->save_variation( $large, 1, null );

		$this->assertCount( $requests, $this->recorder->requests, 'Unticking asks Pixonaut nothing.' );
		$this->assertTrue( $this->reload_any( $large->get_id() )->is_purchasable() );
		$this->assertFalse( $this->plugin->notices()->has( $key ) );
		$kept = ProductConfig::stored_on( $this->reload_any( $large->get_id() ) );
		$this->assertFalse( $kept->override );
		$this->assertSame( 'mini-tee', $kept->sides[0]->template_base_id, 'The link is kept for ticking again.' );
	}

	public function test_an_overriding_variation_follows_the_template_or_sticks_to_a_version(): void {
		list( , $small, $large ) = $this->linked_variable_product();
		$this->script_custom_size( self::LEGAL );
		$this->save_variation( $small, 0, $this->a4_side() );
		$this->script_custom_size( self::LEGAL );
		$this->save_variation(
			$large,
			1,
			array(
				'pinned'  => PostedSides::PINNED,
				'version' => (string) self::FIRST_VERSION,
			) + $this->a4_side()
		);

		foreach ( array( $small, $large ) as $variation ) {
			$this->recorder->json( 200, $this->blank() );
			$this->recorder->json( 200, array( 'verdicts' => array( self::LEGAL ) ) );
			$product = $this->reload_any( $variation->get_id() );
			$this->linker->recheck( ProductConfig::stored_on( $product ), array( 'mini-tee' => self::PUBLISHED_VERSION ) )->save_to( $product );
		}

		$followed = ProductConfig::stored_on( $this->reload_any( $small->get_id() ) )->sides[0];
		$pinned   = ProductConfig::stored_on( $this->reload_any( $large->get_id() ) )->sides[0];
		$this->assertSame( array( self::PUBLISHED_VERSION, false, 'A4' ), array( $followed->template_version, $followed->pinned, $followed->print_sizes[0]->label ) );
		$this->assertSame( array( self::FIRST_VERSION, true, 'A4' ), array( $pinned->template_version, $pinned->pinned, $pinned->print_sizes[0]->label ) );
	}

	public function test_an_overriding_variation_chooses_its_own_canvas_ratio(): void {
		list( , $small, $large ) = $this->linked_variable_product();
		$this->script_custom_size( self::LEGAL );
		$this->save_variation( $small, 0, $this->a4_side(), array( PanelFields::CANVAS_RATIO => CanvasRatio::TEMPLATE ) );
		$this->script_custom_size( self::LEGAL );
		$this->save_variation( $large, 1, array( PostedSides::CANVAS_RATIO => CanvasRatio::PRINT_SIZE ) + $this->a4_side() );

		$own = ProductConfig::stored_on( $this->reload_any( $small->get_id() ) );
		$this->assertTrue( $own->draw_at_template_ratio );
		$this->assertFalse( ProductConfig::stored_on( $this->reload_any( $large->get_id() ) )->sides[0]->draw_at_template_ratio );
		$config = ProductConfig::config_for( $this->reload_any( $small->get_id() ) );
		$this->assertTrue( $config->draw_at_template_ratio_for( $config->sides[0] ) );
	}

	public function test_a_variable_product_cannot_be_bought_when_no_variation_can(): void {
		list( $parent, $small, $large ) = $this->linked_variable_product();
		$this->script_custom_size( self::ILLEGAL );
		$this->save_variation( $large, 1, $this->a4_side() );
		$this->script_custom_size( self::ILLEGAL );

		$this->save_variation( $small, 0, $this->a4_side() );

		$this->assertFalse( $this->reload_any( $small->get_id() )->is_purchasable() );
		$this->assertFalse( $this->reload_any( $parent->get_id() )->is_purchasable() );
	}

	public function test_an_overriding_variation_can_stop_personalising(): void {
		list( , $small ) = $this->linked_variable_product();

		$this->save_variation( $small, 0, $this->a4_side(), array( PanelFields::PERSONALISE => null ) );

		$this->assertFalse( ProductConfig::config_for( $this->reload_any( $small->get_id() ) )->personalise );
		// The link it was shown, the product's, is kept for switching personalisation back on.
		$kept = ProductConfig::stored_on( $this->reload_any( $small->get_id() ) );
		$this->assertTrue( $kept->override );
		$this->assertSame( self::BLANK_ID, $kept->blank_id );
		$this->assertSame( 'mini-tee', $kept->sides[0]->template_base_id );
	}

	public function test_a_variable_product_cannot_be_bought_when_its_only_sellable_variation_is_disabled(): void {
		list( $parent, $small, $large ) = $this->linked_variable_product();
		$this->script_custom_size( self::ILLEGAL );
		$this->save_variation( $large, 1, $this->a4_side() );
		$small->set_status( 'private' );
		$small->save();

		$this->assertFalse( $this->reload_any( $parent->get_id() )->is_purchasable() );
	}

	public function test_a_page_of_variations_asks_a_failing_pixonaut_once_per_lookup(): void {
		list( , $small, $large ) = $this->linked_variable_product();
		$requests                = count( $this->recorder->requests );
		$this->recorder->json( 404, array( 'error' => 'not_found' ) );
		$this->recorder->json( 404, array( 'error' => 'not_found' ) );
		$this->recorder->json( 500, array( 'error' => 'internal_error' ) );

		ob_start();
		$this->variations->render( 0, array(), get_post( $small->get_id() ) );
		$this->variations->render( 1, array(), get_post( $large->get_id() ) );
		$html = (string) ob_get_clean();

		$this->assertCount( $requests + 3, $this->recorder->requests, 'The blank, its mockups and the printshops, once each.' );
		$this->assertStringContainsString( 'name="pixonaut_variation[1][sides][0][placement]"', $html );
	}

	public function test_a_variation_panel_without_its_nonce_is_not_saved(): void {
		list( , $small ) = $this->linked_variable_product();

		$this->save_variation( $small, 0, $this->a4_side(), array( PanelFields::NONCE => 'forged' ) );

		$this->assertFalse( ProductConfig::is_stored_on( $this->reload_any( $small->get_id() ) ) );
	}

	public function test_a_variation_saves_the_photo_its_image_is_drawn_on_only_while_it_was_listed(): void {
		list( , $small ) = $this->linked_variable_product();
		$photo           = array(
			'kind'  => PictureView::MOCKUP,
			'view'  => 'view-front',
			'photo' => 'mock-front',
		);

		$this->save_variation( $small, 0, null, array( PanelFields::PICTURE_VIEW => PictureView::field_value( $photo ) ) );
		$this->assertNull( PictureView::on( $this->reload_any( $small->get_id() ) ), 'not listed' );

		$this->save_variation(
			$small,
			0,
			null,
			array(
				PanelFields::PICTURE_VIEW        => PictureView::field_value( $photo ),
				PanelFields::PICTURE_VIEW_LISTED => '1',
			)
		);
		$this->assertSame( $photo, PictureView::on( $this->reload_any( $small->get_id() ) ) );
	}

	public function test_an_inheriting_variation_shows_its_product_configuration_under_its_own_names(): void {
		list( , , $large ) = $this->linked_variable_product();
		$this->recorder->json( 200, $this->blank() );
		$this->recorder->json( 200, $this->mockups() );
		$this->recorder->json( 200, self::printshops() );

		ob_start();
		$this->variations->render( 1, array(), get_post( $large->get_id() ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="pixonaut_variation[1][override]"', $html );
		$this->assertStringContainsString( 'name="pixonaut_variation[1][sides][0][placement]"', $html );
		$this->assertStringContainsString( 'id="pixonaut_variation_1_side_0_placement"', $html );
		$this->assertStringContainsString( 'name="pixonaut_variation[1][blank]"', $html );
		$this->assertStringContainsString( 'Fixture tee', $html, 'Prefilled from the product.' );
		$this->assertStringContainsString( 'class="pixonaut-template-studio" href="' . Hosts::studio() . ConnectionAnswers::studio_path() . 'templates/mini-tee"', $html );
		$this->assertStringContainsString( 'class="woocommerce_options_panel pixonaut-variation-fields" hidden', $html );
	}

	/**
	 * A variable product with a small and a large variation, not linked.
	 *
	 * @return array{WC_Product_Variable, WC_Product_Variation, WC_Product_Variation}
	 */
	private function variable_product(): array {
		$parent = new WC_Product_Variable();
		$parent->set_name( 'Personalised hoodie' );
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'size' );
		$attribute->set_options( array( 'S', 'L' ) );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$parent->set_attributes( array( $attribute ) );
		$parent->save();
		$variations = array();
		foreach ( array( 'S', 'L' ) as $size ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $parent->get_id() );
			$variation->set_attributes( array( 'size' => $size ) );
			$variation->set_regular_price( '30' );
			$variation->save();
			$variations[] = $variation;
		}
		WC_Product_Variable::sync( $parent->get_id() );
		return array( $parent, $variations[0], $variations[1] );
	}

	/**
	 * The variable product, linked in its Pixonaut tab with the front in the placement default.
	 *
	 * @return array{WC_Product_Variable, WC_Product_Variation, WC_Product_Variation}
	 */
	private function linked_variable_product(): array {
		$products = $this->variable_product();
		$this->script_link( self::LEGAL );
		$this->save( $products[0]->get_id() );
		return $products;
	}

	/**
	 * A front sold in A4 alone.
	 *
	 * @return array<string, mixed>
	 */
	private function a4_side(): array {
		return array(
			'key'           => '',
			'label'         => '',
			'placement'     => self::PLACEMENT_ID,
			'template'      => self::TEMPLATE_ID,
			'template_name' => 'Mini tee',
			'sizes'         => array(
				array(
					'kind'   => PostedSides::SIZE_CUSTOM,
					'width'  => self::A4_WIDTH_MM,
					'height' => self::A4_HEIGHT_MM,
					'label'  => 'A4',
				),
			),
		);
	}

	/**
	 * Scripts a custom-size save: the blank, then the verdict.
	 *
	 * @param array<string, mixed> $verdict The verdict for the size.
	 */
	private function script_custom_size( array $verdict ): void {
		$this->recorder->json( 200, $this->blank() );
		$this->recorder->json( 200, array( 'verdicts' => array( $verdict ) ) );
		if ( true === ( $verdict['legal'] ?? null ) ) {
			$this->script_template();
		}
	}

	/**
	 * Saves a variation's panel as WooCommerce's variations save posts it.
	 *
	 * @param WC_Product                $variation The variation.
	 * @param int                       $loop      Its place in the variations list.
	 * @param array<string, mixed>|null $side      The one side posted with the override ticked; null leaves the override unticked.
	 * @param array<string, mixed>      $fields    Fields that differ; null leaves one out.
	 */
	private function save_variation( WC_Product $variation, int $loop, ?array $side, array $fields = array() ): void {
		wp_set_current_user( $this->user( 'administrator' ) );
		$posted = array_merge(
			array(
				PanelFields::NONCE       => wp_create_nonce( VariationPanel::NONCE_ACTION ),
				PanelFields::OVERRIDE    => null === $side ? null : 'yes',
				PanelFields::PERSONALISE => 'yes',
				PanelFields::BLANK       => self::BLANK_ID,
				PanelFields::SIDES       => null === $side ? null : array( $side ),
			),
			$fields
		);
		$_POST  = array(
			PanelFields::VARIATION_FIELD => array(
				$loop => wp_slash( array_filter( $posted, static fn ( mixed $value ): bool => null !== $value ) ),
			),
		);
		$this->variations->save( $variation->get_id(), $loop );
		$_POST = array();
	}

	private function reload_any( int $product_id ): WC_Product {
		$product = wc_get_product( $product_id );
		$this->assertInstanceOf( WC_Product::class, $product );
		return $product;
	}
}
