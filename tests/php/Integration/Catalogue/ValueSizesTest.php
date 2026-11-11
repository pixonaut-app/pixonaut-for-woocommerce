<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Catalogue;

use Pixonaut\WooCommerce\Admin\InvalidProductNotice;
use Pixonaut\WooCommerce\Admin\PanelFields;
use Pixonaut\WooCommerce\Admin\VariationPanel;
use Pixonaut\WooCommerce\Admin\VariationSizeField;
use Pixonaut\WooCommerce\Catalogue\InvalidReason;
use Pixonaut\WooCommerce\Catalogue\PostedSides;
use Pixonaut\WooCommerce\Catalogue\PrintSize;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Catalogue\ValueSizes;
use Pixonaut\WooCommerce\Catalogue\VariationSize;
use Pixonaut\WooCommerce\Catalogue\VariationSizes;
use Pixonaut\WooCommerce\Tests\Support\BlankFixture;
use Pixonaut\WooCommerce\Tests\Support\VerdictAnswers;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Variable;
use WC_Product_Variation;

/**
 * A variable poster whose variations are chosen by Posterstorlek: printed
 * without a blank, at a size per value of Posterstorlek, a size of each
 * variation's own, or the product's.
 */
final class ValueSizesTest extends ProductPanelTestCase {

	private const ATTRIBUTE = 'Posterstorlek';

	/**
	 * The attribute as a variation names it.
	 */
	private const ATTRIBUTE_SLUG = 'posterstorlek';

	private const SMALL = '30×40 cm';

	private const LARGE = '50×70 cm';

	/**
	 * The one side's key, as a side without a blank or a key entered has.
	 */
	private const SIDE_KEY = 'design';

	private VariationPanel $variations;

	public function set_up(): void {
		parent::set_up();
		BlankFixture::remember_printshops( $this->cache );
		$this->variations = new VariationPanel( $this->panel, $this->plugin->templates(), $this->variant_field(), null, new VariationSizeField( new VariationSizes( $this->catalogue ), $this->plugin->templates() ) );
	}

	public function test_a_variation_with_its_own_setup_ignores_the_products_missing_size(): void {
		list( $parent, $small ) = $this->poster();
		$this->save_template_only( $parent );
		$own = new ProductConfig( true, true, null, null, array( new Side( self::SIDE_KEY, 'Poster', null, null, 'mini-tee', 2, null, 'Mini tee', array( new PrintSize( 300, 400, 'A' ) ) ) ) );
		$own->save_to( $small );

		$this->assertSame( InvalidReason::MISSING_PRINT_SIZE, ProductConfig::stored_on( $this->reload_any( $parent->get_id() ) )->invalid_reason );
		$this->assertTrue( ProductConfig::config_for( $this->reload_any( $small->get_id() ) )->valid );
	}

	public function test_a_variable_product_whose_variations_all_have_a_size_of_their_own_needs_none(): void {
		list( $parent, $small, $large ) = $this->poster();
		$this->save_template_only( $parent );
		$this->recorder->json( 200, array( 'verdicts' => array( VerdictAnswers::LEGAL ) ) );
		$this->save_variation( $small, 0, '300', '400' );
		$this->recorder->json( 200, array( 'verdicts' => array( VerdictAnswers::LEGAL ) ) );
		$this->save_variation( $large, 1, '500', '700' );

		$this->save_template_only( $parent );

		$this->assertSame( array( 300.0, 400.0 ), $this->sold_size( $small ) );
		$this->assertSame( array( 500.0, 700.0 ), $this->sold_size( $large ) );
		$this->assertTrue( $this->reload_any( $small->get_id() )->is_purchasable() );
		$this->assertTrue( $this->reload_any( $large->get_id() )->is_purchasable() );
		$this->assertTrue( $this->reload_any( $parent->get_id() )->is_purchasable() );
		$this->assertNull( InvalidReason::of( $this->reload_any( $parent->get_id() ), ProductConfig::stored_on( $this->reload_any( $parent->get_id() ) ) ) );
		$this->assertArrayNotHasKey( InvalidProductNotice::key( $parent->get_id() ), $this->plugin->notices()->all() );
	}

	public function test_a_variation_without_a_size_is_not_sold_and_the_product_says_so(): void {
		list( $parent, $small, $large ) = $this->poster();
		$this->save_template_only( $parent );
		$this->recorder->json( 200, array( 'verdicts' => array( VerdictAnswers::LEGAL ) ) );
		$this->save_variation( $small, 0, '300', '400' );

		$this->save_template_only( $parent );

		$this->assertTrue( $this->reload_any( $small->get_id() )->is_purchasable() );
		$this->assertFalse( $this->reload_any( $large->get_id() )->is_purchasable() );
		$this->assertSame( InvalidReason::MISSING_PRINT_SIZE, ProductConfig::config_for( $this->reload_any( $large->get_id() ) )->invalid_reason );
		$notice = $this->plugin->notices()->all()[ InvalidProductNotice::key( $parent->get_id() ) ] ?? null;
		$this->assertNotNull( $notice );
		$this->assertStringContainsString( esc_html( InvalidReason::message( InvalidReason::VARIATION_MISSING_PRINT_SIZE ) ), $notice['message'] );
	}

	public function test_a_template_not_live_stops_variations_with_a_size_of_their_own(): void {
		list( $parent, $small ) = $this->poster();
		$this->save_template_only( $parent );
		$this->recorder->json( 200, array( 'verdicts' => array( VerdictAnswers::LEGAL ) ) );
		$this->save_variation( $small, 0, '300', '400' );

		$this->save_template_only( $parent, array(), 'paused' );

		$this->assertSame( InvalidReason::TEMPLATE_NOT_LIVE, ProductConfig::config_for( $this->reload_any( $small->get_id() ) )->invalid_reason );
		$this->assertFalse( $this->reload_any( $small->get_id() )->is_purchasable() );
	}

	public function test_every_variation_with_a_value_prints_at_its_size(): void {
		list( $parent, $small, $large ) = $this->poster();

		$this->save_template_only(
			$parent,
			$this->value_sizes(
				array(
					self::SMALL => array( '300', '400', '' ),
					self::LARGE => array( '500', '700', 'Large' ),
				)
			)
		);

		$this->assertSame( array( 300.0, 400.0 ), $this->sold_size( $small ) );
		$this->assertSame( array( 500.0, 700.0 ), $this->sold_size( $large ) );
		$this->assertSame( self::SMALL, ProductConfig::config_for( $this->reload_any( $small->get_id() ) )->sides[0]->print_sizes[0]->label, 'an empty label is the value’s name' );
		$this->assertSame( 'Large', ProductConfig::config_for( $this->reload_any( $large->get_id() ) )->sides[0]->print_sizes[0]->label );
		$this->assertTrue( $this->reload_any( $small->get_id() )->is_purchasable() );
		$this->assertTrue( $this->reload_any( $large->get_id() )->is_purchasable() );
		$this->assertArrayNotHasKey( InvalidProductNotice::key( $parent->get_id() ), $this->plugin->notices()->all() );
		$this->assertFalse( ProductConfig::is_stored_on( $this->reload_any( $small->get_id() ) ), 'the rest of its setup follows the product' );
		$this->assertNull( VariationSize::stored_on( $this->reload_any( $small->get_id() ) )->size_of( self::SIDE_KEY ), 'not a size of its own' );
	}

	public function test_a_variations_own_size_wins_over_its_values(): void {
		list( $parent, $small, $large ) = $this->poster();
		$this->save_template_only(
			$parent,
			$this->value_sizes(
				array(
					self::SMALL => array( '300', '400', '' ),
					self::LARGE => array( '500', '700', '' ),
				)
			)
		);
		$this->recorder->json( 200, array( 'verdicts' => array( VerdictAnswers::LEGAL ) ) );

		$this->save_variation( $small, 0, '210', '297' );

		$this->assertSame( array( 210.0, 297.0 ), $this->sold_size( $small ) );
		$this->assertSame( array( 500.0, 700.0 ), $this->sold_size( $large ) );

		$this->recorder->json( 200, array( 'verdicts' => array( VerdictAnswers::LEGAL ) ) );
		$this->save_variation( $small, 0, null );

		$this->assertSame( array( 300.0, 400.0 ), $this->sold_size( $small ), 'back to its value’s size' );
		$this->assertTrue( $this->reload_any( $small->get_id() )->is_purchasable() );
	}

	public function test_a_value_size_refused_stops_that_variation_alone(): void {
		list( $parent, $small, $large ) = $this->poster();

		$this->save_template_only(
			$parent,
			$this->value_sizes(
				array(
					self::SMALL => array( '300', '400', '' ),
					self::LARGE => array( '500', '900', '' ),
				)
			),
			'live',
			array(
				VerdictAnswers::LEGAL,
				array(
					'legal'         => false,
					'reason'        => InvalidReason::WRONG_RATIO,
					'lowResolution' => array(),
				),
			)
		);

		$this->assertTrue( $this->reload_any( $small->get_id() )->is_purchasable() );
		$this->assertFalse( $this->reload_any( $large->get_id() )->is_purchasable() );
		$this->assertSame( InvalidReason::WRONG_RATIO, ProductConfig::config_for( $this->reload_any( $large->get_id() ) )->invalid_reason );

		// The value's size is set on the product, so both the notice and the variation's field send the merchant there.
		$product_tab = InvalidReason::place( false );
		$this->assertStringContainsString( $product_tab, InvalidReason::stored( ProductConfig::config_for( $this->reload_any( $large->get_id() ) ) ) );
		$field = ( new VariationSizeField( new VariationSizes( $this->catalogue ), $this->plugin->templates() ) )->html( $this->reload_any( $large->get_id() ), PanelFields::variation( 1 ), false );
		$this->assertStringContainsString( esc_html( InvalidReason::message( InvalidReason::WRONG_RATIO ) ), $field );
		$this->assertStringNotContainsString( esc_html( InvalidReason::place( true ) ), $field );
	}

	public function test_a_value_size_changed_without_a_new_check_is_not_sold(): void {
		list( $parent, $small ) = $this->poster();
		$this->save_template_only(
			$parent,
			$this->value_sizes(
				array(
					self::SMALL => array( '300', '400', '' ),
					self::LARGE => array( '500', '700', '' ),
				)
			)
		);
		$stored = ProductConfig::stored_on( $this->reload_any( $parent->get_id() ) );
		$side   = $stored->sides[0];

		$stored->with_sides( array( $side->with_value_sizes( new ValueSizes( self::ATTRIBUTE_SLUG, array( self::SMALL => new PrintSize( 200, 266.7, self::SMALL ) ) ) ) ) )->save_to( $this->reload_any( $parent->get_id() ) );

		$config = ProductConfig::config_for( $this->reload_any( $small->get_id() ) );
		$this->assertSame( 200.0, $config->sides[0]->print_sizes[0]->width_mm, 'the product says what it prints at' );
		$this->assertSame( InvalidReason::VARIATION_SIZE_UNCHECKED, $config->invalid_reason );
	}

	public function test_a_recheck_keeps_the_sizes_per_value(): void {
		list( $parent, $small ) = $this->poster();
		$this->save_template_only(
			$parent,
			$this->value_sizes(
				array(
					self::SMALL => array( '300', '400', '' ),
					self::LARGE => array( '500', '700', '' ),
				)
			)
		);
		$stored = ProductConfig::stored_on( $this->reload_any( $parent->get_id() ) );

		$rechecked = $this->linker->recheck( $stored, array( 'mini-tee' => 2 ) );

		$this->assertEquals( $stored->sides[0]->value_sizes, $rechecked->sides[0]->value_sizes );
		$rechecked->save_to( $this->reload_any( $parent->get_id() ) );
		$this->assertSame( array( 300.0, 400.0 ), $this->sold_size( $small ) );
		$this->assertTrue( $this->reload_any( $small->get_id() )->is_purchasable() );
	}

	public function test_an_overriding_variation_takes_no_size_per_value(): void {
		list( $parent, $small ) = $this->poster();
		$this->save_template_only(
			$parent,
			$this->value_sizes(
				array(
					self::SMALL => array( '300', '400', '' ),
					self::LARGE => array( '500', '700', '' ),
				)
			)
		);
		// A first override starts from the product's sides, sizes per value among them.
		$own = ProductConfig::stored_on( $this->reload_any( $parent->get_id() ) )->with_override( true );
		$own = $own->with_sides( array( $own->sides[0]->with( 2, null, array( new PrintSize( 210, 270, 'Own' ) ), null, 0 ) ) )->with_validity( true );
		$own->save_to( $this->reload_any( $small->get_id() ) );

		$config = ProductConfig::config_for( $this->reload_any( $small->get_id() ) );

		$this->assertTrue( $config->valid );
		$this->assertSame( 'Own', $config->sides[0]->print_sizes[0]->label );
	}

	public function test_a_product_whose_variations_all_override_needs_no_size(): void {
		list( $parent, $small, $large ) = $this->poster();
		$this->save_template_only( $parent );
		foreach ( array( $small, $large ) as $variation ) {
			( new ProductConfig( true, true, null, null, array( new Side( self::SIDE_KEY, 'Poster', null, null, 'mini-tee', 2, null, 'Mini tee', array( new PrintSize( 300, 400, 'A' ) ) ) ) ) )->save_to( $this->reload_any( $variation->get_id() ) );
		}

		$reloaded = $this->reload_any( $parent->get_id() );
		$this->assertNull( InvalidReason::of( $reloaded, ProductConfig::stored_on( $reloaded ) ) );
	}

	public function test_saving_the_last_variations_size_clears_the_products_notice(): void {
		list( $parent, $small, $large ) = $this->poster();
		$this->save_template_only( $parent );
		$this->recorder->json( 200, array( 'verdicts' => array( VerdictAnswers::LEGAL ) ) );
		$this->save_variation( $small, 0, '300', '400' );
		$this->variations->saved( $parent->get_id() );
		$this->assertArrayHasKey( InvalidProductNotice::key( $parent->get_id() ), $this->plugin->notices()->all() );
		$this->recorder->json( 200, array( 'verdicts' => array( VerdictAnswers::LEGAL ) ) );

		$this->save_variation( $large, 1, '500', '700' );
		$this->variations->saved( $parent->get_id() );

		$this->assertArrayNotHasKey( InvalidProductNotice::key( $parent->get_id() ), $this->plugin->notices()->all() );
	}

	public function test_a_variation_set_to_any_value_takes_the_products_size(): void {
		list( $parent ) = $this->poster();
		$any            = $this->variation( $parent, '' );

		$this->save_template_only(
			$parent,
			$this->value_sizes(
				array(
					self::SMALL => array( '300', '400', '' ),
					self::LARGE => array( '500', '700', '' ),
				)
			)
		);

		$this->assertFalse( $this->reload_any( $any->get_id() )->is_purchasable() );
		$this->assertSame( InvalidReason::MISSING_PRINT_SIZE, ProductConfig::config_for( $this->reload_any( $any->get_id() ) )->invalid_reason );
		$notice = $this->plugin->notices()->all()[ InvalidProductNotice::key( $parent->get_id() ) ] ?? null;
		$this->assertNotNull( $notice, 'the product still needs a size for it' );
	}

	public function test_on_a_blank_a_value_size_replaces_the_placements_default(): void {
		list( $parent, $small, $large ) = $this->poster();
		$this->script_link( VerdictAnswers::LEGAL );
		$this->recorder->json( 200, array( 'verdicts' => array( VerdictAnswers::LEGAL ) ) );

		$this->save( $parent->get_id(), array(), array( PostedSides::VALUE_SIZES => $this->value_sizes( array( self::SMALL => array( '200', '257.1', '' ) ) ) ) );

		$this->assertSame( array( 200.0, 257.1 ), $this->sold_size( $small ) );
		$this->assertTrue( $this->reload_any( $small->get_id() )->is_purchasable() );
		$this->assertTrue( ProductConfig::config_for( $this->reload_any( $large->get_id() ) )->sides[0]->print_sizes[0]->placement_default, 'without a value size, the placement’s default' );
		$last    = $this->recorder->requests[ array_key_last( $this->recorder->requests ) ] ?? null;
		$checked = json_decode( (string) ( $last['body'] ?? '' ), true );
		$this->assertSame( self::BLANK_ID, $checked['blankId'] ?? null, 'judged against the placement' );
	}

	/**
	 * The poster and its variations by Posterstorlek, small then large.
	 *
	 * @return array{WC_Product_Variable, WC_Product_Variation, WC_Product_Variation}
	 */
	private function poster(): array {
		$parent    = new WC_Product_Variable();
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( self::ATTRIBUTE );
		$attribute->set_options( array( self::SMALL, self::LARGE ) );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$parent->set_name( 'Poster' );
		$parent->set_attributes( array( $attribute ) );
		$parent->save();
		return array( $parent, $this->variation( $parent, self::SMALL ), $this->variation( $parent, self::LARGE ) );
	}

	/**
	 * A variation of the poster.
	 *
	 * @param WC_Product_Variable $poster The poster.
	 * @param string              $value  Its Posterstorlek; empty for any.
	 */
	private function variation( WC_Product_Variable $poster, string $value ): WC_Product_Variation {
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $poster->get_id() );
		$variation->set_attributes( array( self::ATTRIBUTE_SLUG => $value ) );
		$variation->set_regular_price( '30' );
		$variation->save();
		WC_Product_Variable::sync( $poster->get_id() );
		return $variation;
	}

	/**
	 * Saves the poster without a blank or a print size of its own, as the
	 * product screen does, scripting the template's read and a verdict per
	 * variation a size per value is judged for.
	 *
	 * @param WC_Product                 $poster      The poster.
	 * @param array<string, mixed>       $value_sizes The posted sizes per value; empty for none.
	 * @param string                     $status      The template's status.
	 * @param list<array<string, mixed>> $verdicts   The verdicts of the sizes per value, one per variation judged.
	 */
	private function save_template_only( WC_Product $poster, array $value_sizes = array(), string $status = 'live', array $verdicts = array() ): void {
		$this->script_template( $status );
		$count = count( $value_sizes['rows'][ self::ATTRIBUTE_SLUG ] ?? array() );
		for ( $i = 0; $i < $count; $i++ ) {
			$this->recorder->json( 200, array( 'verdicts' => array( $verdicts[ $i ] ?? VerdictAnswers::LEGAL ) ) );
		}
		$this->save(
			$poster->get_id(),
			array( 'pixonaut_blank' => '' ),
			array(
				'placement'              => '',
				'sizes'                  => array(),
				PostedSides::VALUE_SIZES => $value_sizes,
			)
		);
	}

	/**
	 * Sizes per Posterstorlek as the product screen posts them.
	 *
	 * @param array<string, array{string, string, string}> $sizes Width, height and label, by value.
	 * @return array<string, mixed>
	 */
	private function value_sizes( array $sizes ): array {
		$rows = array();
		foreach ( $sizes as $value => list( $width, $height, $label ) ) {
			$rows[] = array(
				'value'  => $value,
				'name'   => $value,
				'width'  => $width,
				'height' => $height,
				'label'  => $label,
			);
		}
		return array(
			'attribute' => self::ATTRIBUTE_SLUG,
			'rows'      => array( self::ATTRIBUTE_SLUG => $rows ),
		);
	}

	/**
	 * Saves a variation's panel, following its product, with a size of its
	 * own for its one side, or none for null.
	 *
	 * @param WC_Product  $variation The variation.
	 * @param int         $loop      Its place in the variations list.
	 * @param string|null $width     Width in millimetres; null for the product's size.
	 * @param string      $height    Height in millimetres.
	 */
	private function save_variation( WC_Product $variation, int $loop, ?string $width, string $height = '' ): void {
		wp_set_current_user( $this->user( 'administrator' ) );
		$size  = null === $width ? array( 'kind' => 'product' ) : array(
			'kind'   => VariationSizeField::CUSTOM,
			'width'  => $width,
			'height' => $height,
			'label'  => '',
		);
		$_POST = array(
			PanelFields::VARIATION_FIELD => array(
				$loop => wp_slash(
					array(
						PanelFields::NONCE             => wp_create_nonce( VariationPanel::NONCE_ACTION ),
						PanelFields::PRINT_SIZE_LISTED => '1',
						PanelFields::PRINT_SIZE        => array( self::SIDE_KEY => $size ),
					)
				),
			),
		);
		$this->variations->save( $variation->get_id(), $loop );
		$_POST = array();
	}

	/**
	 * What a variation is sold at on its one side, width and height.
	 *
	 * @param WC_Product $variation The variation.
	 * @return array{float, float}
	 */
	private function sold_size( WC_Product $variation ): array {
		$size = ProductConfig::config_for( $this->reload_any( $variation->get_id() ) )->sides[0]->print_sizes[0];
		return array( $size->width_mm, $size->height_mm );
	}

	private function reload_any( int $product_id ): WC_Product {
		$product = wc_get_product( $product_id );
		$this->assertInstanceOf( WC_Product::class, $product );
		return $product;
	}
}
