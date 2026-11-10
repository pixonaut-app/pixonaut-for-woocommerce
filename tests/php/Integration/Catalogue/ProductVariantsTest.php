<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Catalogue;

use Pixonaut\WooCommerce\Admin\PanelFields;
use Pixonaut\WooCommerce\Admin\ProductPanel;
use Pixonaut\WooCommerce\Admin\VariantNotice;
use Pixonaut\WooCommerce\Admin\VariationPanel;
use Pixonaut\WooCommerce\Catalogue\ProductVariant;
use Pixonaut\WooCommerce\Tests\Support\BlankFixture;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Variable;
use WC_Product_Variation;

/**
 * Each product and variation personalised on a blank is one of the blank's
 * variants: matched by option labels, or picked on the product screen. One
 * with none, or a retired one, cannot be bought.
 */
final class ProductVariantsTest extends ProductPanelTestCase {

	private const LEGAL = array( 'legal' => true );

	private const AXIS = 'Colour';

	private VariationPanel $variations;

	public function set_up(): void {
		parent::set_up();
		$this->variations       = new VariationPanel( $this->panel, $this->plugin->templates(), $this->variant_field() );
		$this->variants->answer = self::blank_variants();
		add_action( 'woocommerce_ajax_save_product_variations', array( $this->variations, 'saved' ) );
	}

	public function tear_down(): void {
		remove_action( 'woocommerce_ajax_save_product_variations', array( $this->variations, 'saved' ) );
		parent::tear_down();
	}

	public function test_variations_are_matched_by_option_labels_among_the_variants_on_sale(): void {
		$variations = $this->linked_variable_product();

		$this->assertSame( 'v_red', ProductVariant::stored_on( $this->reload_any( $variations['Red']->get_id() ) )?->ref, 'the red no longer sold is passed over' );
		$this->assertTrue( $this->reload_any( $variations['Red']->get_id() )->is_purchasable() );
		$this->assertTrue( $this->reload_any( $variations['White']->get_id() )->is_purchasable(), 'a variant without a photo is still sold' );
		$this->assertFalse( $this->reload_any( $variations['Forest']->get_id() )->is_purchasable(), 'its variant is retired' );
		$this->assertFalse( $this->reload_any( $variations['Blue']->get_id() )->is_purchasable(), 'no variant is blue' );

		$notice = $this->notice( $variations['Red']->get_parent_id() );
		$this->assertStringContainsString( 'variation Forest, whose blank variant is retired', $notice );
		$this->assertStringContainsString( 'variation Blue, which is no variant of its blank', $notice );
		$this->assertStringNotContainsString( 'Red', $notice );
	}

	public function test_a_variant_picked_replaces_the_match_until_matching_by_options_again(): void {
		$variations = $this->linked_variable_product();
		$parent_id  = $variations['Blue']->get_parent_id();

		$this->save_variation( $variations['Blue'], 2, 'v_white' );

		$blue = ProductVariant::stored_on( $this->reload_any( $variations['Blue']->get_id() ) );
		$this->assertSame( array( 'v_white', 'v_white' ), array( $blue?->pick, $blue?->ref ) );
		$this->assertTrue( $this->reload_any( $variations['Blue']->get_id() )->is_purchasable() );
		$this->assertStringNotContainsString( 'Blue', $this->notice( $parent_id ) );

		$this->save_variation( $variations['Blue'], 2, '' );

		$this->assertNull( ProductVariant::stored_on( $this->reload_any( $variations['Blue']->get_id() ) )?->ref );
		$this->assertFalse( $this->reload_any( $variations['Blue']->get_id() )->is_purchasable() );
	}

	public function test_a_picked_variant_no_longer_sold_is_unavailable_as_well(): void {
		$variations = $this->linked_variable_product();

		$this->save_variation( $variations['Blue'], 2, 'v_navy' );

		$this->assertSame( 'no_longer_sold', ProductVariant::stored_on( $this->reload_any( $variations['Blue']->get_id() ) )?->retired );
		$this->assertFalse( $this->reload_any( $variations['Blue']->get_id() )->is_purchasable() );
		$this->assertStringContainsString( 'variation Blue, whose blank variant is retired', $this->notice( $variations['Blue']->get_parent_id() ) );
	}

	public function test_a_pick_saved_while_the_variants_are_read_is_kept(): void {
		$variations = $this->linked_variable_product();
		$red        = $variations['Red'];
		$picked     = new ProductVariant( self::BLANK_ID, 'v_white' );
		// Red is retired in Pixonaut, so the refresh has something to write.
		$this->variants->answer['variants'][0]['retired'] = array( 'reason' => 'retired' );
		// The store manager picks a variant for Red while a refresh waits on Pixonaut.
		$this->variants->meanwhile = function () use ( $red, $picked ): void {
			$this->variants->meanwhile = null;
			$picked->save_to( $this->reload_any( $red->get_id() ) );
		};

		$this->variant_field()->refresh( $this->reload_any( $red->get_parent_id() ) );

		$this->assertSame( 'v_white', ProductVariant::stored_on( $this->reload_any( $red->get_id() ) )?->pick );
	}

	public function test_a_blank_whose_variants_cannot_be_read_is_asked_once_per_request(): void {
		$variations            = $this->linked_variable_product();
		$this->variants->error = new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
		$this->variants->reads = 0;
		$this->recorder->json( 200, $this->blank() );
		$this->recorder->json( 200, $this->mockups() );
		$this->recorder->json( 200, self::printshops() );
		$this->recorder->json( 200, $this->blank() );
		$this->recorder->json( 200, $this->mockups() );
		$this->recorder->json( 200, self::printshops() );

		ob_start();
		$this->variations->render( 0, array(), get_post( $variations['Red']->get_id() ) );
		$this->variations->render( 1, array(), get_post( $variations['White']->get_id() ) );
		$html = (string) ob_get_clean();

		$this->assertSame( 1, $this->variants->reads );
		$this->assertSame( 2, substr_count( $html, 'Pixonaut could not be reached to list the blank' ) );
	}

	public function test_the_variant_field_lists_the_variants_and_warns_about_one_no_photo_shows(): void {
		$variations = $this->linked_variable_product();
		$this->recorder->json( 200, $this->blank() );
		$this->recorder->json( 200, $this->mockups() );
		$this->recorder->json( 200, self::printshops() );

		ob_start();
		$this->variations->render( 3, array(), get_post( $variations['White']->get_id() ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="pixonaut_variation[3][variant]"', $html );
		$this->assertStringNotContainsString( 'name="' . ProductPanel::OPEN_FIELD . '"', $html, 'only the product remembers its open sections' );
		$this->assertStringContainsString( '<input type="hidden" name="pixonaut_variation[3][variant_blank]" value="' . self::BLANK_ID . '" />', $html, 'the pick is bound to the blank it was drawn for' );
		$this->assertStringContainsString( 'Blank variant: Colour: White (found automatically)', $html );
		$this->assertStringContainsString( '<option value="v_red" >Colour: Red</option>', $html );
		$this->assertStringContainsString( '<option value="v_forest" data-retired >Colour: Forest (retired)</option>', $html );
		$this->assertStringContainsString( 'class="pixonaut-variant-show-retired"', $html );
		$this->assertStringContainsString( 'No view the product is shown in has a photo of this variant on Front', $html );
	}

	public function test_the_variant_field_of_a_variation_matched_among_the_variants_on_sale_warns_of_nothing(): void {
		$variations = $this->linked_variable_product();
		$this->recorder->json( 200, $this->blank() );
		$this->recorder->json( 200, $this->mockups() );
		$this->recorder->json( 200, self::printshops() );

		ob_start();
		$this->variations->render( 0, array(), get_post( $variations['Red']->get_id() ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Blank variant: Colour: Red (found automatically)', $html );
		$this->assertStringNotContainsString( 'pixonaut-variant-warning', $html );
	}

	public function test_the_variant_field_of_a_variation_set_to_a_retired_variant_shows_it_marked(): void {
		$variations = $this->linked_variable_product();
		$this->save_variation( $variations['Blue'], 2, 'v_navy' );
		$this->recorder->json( 200, $this->blank() );
		$this->recorder->json( 200, $this->mockups() );
		$this->recorder->json( 200, self::printshops() );

		ob_start();
		$this->variations->render( 2, array(), get_post( $variations['Blue']->get_id() ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( "<option value=\"v_navy\" data-retired  selected='selected'>Colour: Navy (retired)</option>", $html );
		$this->assertStringContainsString( 'This variant is retired', $html );
	}

	public function test_the_variant_field_warns_about_a_variant_with_no_photo_in_the_views_the_product_is_shown_in(): void {
		$variations = $this->linked_variable_product();
		$parent     = $variations['Red']->get_parent_id();
		$this->script_link( self::LEGAL );
		$this->save(
			$parent,
			array(
				'pixonaut_views' => array(
					ProductPanel::VIEWS_BLANK_KEY  => self::BLANK_ID,
					ProductPanel::VIEWS_TICKED_KEY => array( 'view_back' ),
				),
			)
		);
		$this->recorder->json( 200, $this->blank() );
		$this->recorder->json( 200, $this->mockups() );
		$this->recorder->json( 200, self::printshops() );

		ob_start();
		$this->variations->render( 0, array(), get_post( $variations['Red']->get_id() ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'No view the product is shown in has a photo of this variant on Front', $html );
	}

	public function test_a_simple_product_is_matched_by_its_one_valued_attributes_or_picked(): void {
		$product = $this->product();
		$this->linked_simple( $product, array( 'Red' ) );
		$this->assertSame( 'v_red', ProductVariant::stored_on( $this->reload( $product->get_id() ) )?->ref );
		$this->assertTrue( $this->reload( $product->get_id() )->is_purchasable() );

		$other = $this->product();
		$this->linked_simple( $other, array( 'Red', 'White' ) );
		$this->assertFalse( $this->reload( $other->get_id() )->is_purchasable(), 'an attribute of two values names no variant' );
		$this->assertStringContainsString( 'it is no variant of its blank', $this->notice( $other->get_id() ) );

		$this->script_link( self::LEGAL );
		$this->save( $other->get_id(), array( 'pixonaut_variant' => 'v_white' ) );

		$this->assertSame( 'v_white', ProductVariant::stored_on( $this->reload( $other->get_id() ) )?->ref );
		$this->assertTrue( $this->reload( $other->get_id() )->is_purchasable() );
		$this->assertSame( '', $this->notice( $other->get_id() ) );
	}

	public function test_a_pick_drawn_for_another_blank_is_not_saved_on_the_blank_saved(): void {
		$bound   = PanelFields::PRODUCT_PREFIX . PanelFields::VARIANT_BLANK;
		$product = $this->product();
		$this->linked_simple( $product, array( 'Red', 'White' ) );

		$this->script_link( self::LEGAL );
		$this->save(
			$product->get_id(),
			array(
				'pixonaut_variant' => 'v_white',
				$bound             => 'blank_left',
			)
		);
		$this->assertNull( ProductVariant::stored_on( $this->reload( $product->get_id() ) )?->pick, 'the last blank\'s pick is not this blank\'s variant' );

		$this->script_link( self::LEGAL );
		$this->save(
			$product->get_id(),
			array(
				'pixonaut_variant' => 'v_white',
				$bound             => self::BLANK_ID,
			)
		);
		$this->assertSame( 'v_white', ProductVariant::stored_on( $this->reload( $product->get_id() ) )?->pick );
	}

	public function test_a_product_no_longer_on_a_blank_sells_no_variant(): void {
		$product = $this->product();
		$this->linked_simple( $product, array( 'Red' ) );

		$this->save( $product->get_id(), array( 'pixonaut_personalise' => null ) );

		$this->assertNull( ProductVariant::stored_on( $this->reload( $product->get_id() ) ) );
		$this->assertTrue( $this->reload( $product->get_id() )->is_purchasable() );
	}

	/**
	 * The blank's variants: red, white with no photo, forest retired by the
	 * merchant, navy no longer sold, and a red no longer sold that shares the
	 * red's options.
	 *
	 * @return array<string, mixed>
	 */
	private static function blank_variants(): array {
		$variant = static fn ( string $colour, ?string $retired = null, bool $photo = true ): array => array(
			'ref'     => 'v_' . strtolower( $colour ),
			'options' => array( self::AXIS => $colour ),
			'retired' => null === $retired ? null : array( 'reason' => $retired ),
			'views'   => $photo ? array(
				array(
					'placementId' => self::PLACEMENT_ID,
					'viewId'      => 'view_front',
					'label'       => 'Front',
				),
			) : array(),
		);
		return BlankFixture::variants(
			array(
				$variant( 'Red' ),
				$variant( 'White', null, false ),
				$variant( 'Forest', 'retired' ),
				$variant( 'Navy', 'no_longer_sold' ),
				array( 'ref' => 'v_red_old' ) + $variant( 'Red', 'no_longer_sold' ),
			),
			array( self::AXIS )
		);
	}

	/**
	 * A variable product in four colours, linked in its Pixonaut tab.
	 *
	 * @return array<string, WC_Product_Variation> The variations by colour.
	 */
	private function linked_variable_product(): array {
		$colours = array( 'Red', 'White', 'Forest', 'Blue' );
		$parent  = new WC_Product_Variable();
		$parent->set_name( 'Personalised tee' );
		$parent->set_status( 'publish' );
		$parent->set_attributes( array( self::attribute( $colours, true ) ) );
		$parent->save();
		$variations = array();
		foreach ( $colours as $colour ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $parent->get_id() );
			$variation->set_attributes( array( strtolower( self::AXIS ) => $colour ) );
			$variation->set_regular_price( '30' );
			$variation->save();
			$variations[ $colour ] = $variation;
		}
		WC_Product_Variable::sync( $parent->get_id() );
		$this->script_link( self::LEGAL );
		$this->save( $parent->get_id() );
		return $variations;
	}

	/**
	 * Gives a simple product a colour attribute and links it.
	 *
	 * @param WC_Product $product The product.
	 * @param string[]   $colours The attribute's values.
	 */
	private function linked_simple( WC_Product $product, array $colours ): void {
		$product->set_attributes( array( self::attribute( $colours, false ) ) );
		$product->save();
		$this->script_link( self::LEGAL );
		$this->save( $product->get_id() );
	}

	/**
	 * A custom colour attribute.
	 *
	 * @param string[] $colours   Its values.
	 * @param bool     $variation Whether variations are made from it.
	 */
	private static function attribute( array $colours, bool $variation ): WC_Product_Attribute {
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( self::AXIS );
		$attribute->set_options( $colours );
		$attribute->set_visible( true );
		$attribute->set_variation( $variation );
		return $attribute;
	}

	/**
	 * Saves a variation's panel as the product screen posts it, without an override.
	 *
	 * @param WC_Product $variation The variation.
	 * @param int        $loop      Its place in the list.
	 * @param string     $variant   The variant field's value.
	 */
	private function save_variation( WC_Product $variation, int $loop, string $variant ): void {
		wp_set_current_user( $this->user( 'administrator' ) );
		$_POST = array(
			PanelFields::VARIATION_FIELD => array(
				$loop => array(
					PanelFields::NONCE   => wp_create_nonce( VariationPanel::NONCE_ACTION ),
					PanelFields::VARIANT => $variant,
				),
			),
		);
		$this->variations->save( $variation->get_id(), $loop );
		// WooCommerce fires it once the batch of variations is saved.
		do_action( 'woocommerce_ajax_save_product_variations', $variation->get_parent_id() );
		$_POST = array();
	}

	/**
	 * The product's variant notice; empty when it has none.
	 *
	 * @param int $product_id The product.
	 */
	private function notice( int $product_id ): string {
		$notice = $this->plugin->notices()->all()[ VariantNotice::key( $product_id ) ] ?? null;
		return null === $notice ? '' : $notice['message'];
	}

	private function reload_any( int $product_id ): WC_Product {
		$product = wc_get_product( $product_id );
		$this->assertInstanceOf( WC_Product::class, $product );
		return $product;
	}
}
