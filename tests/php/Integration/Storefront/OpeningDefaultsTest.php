<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Storefront;

use Pixonaut\WooCommerce\Catalogue\PicturedVariation;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Storefront\OpeningChoice;
use Pixonaut\WooCommerce\Tests\Support\DesignFixture;
use Pixonaut\WooCommerce\Tests\Support\SplitPosters;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_UnitTestCase;

/**
 * A personalised variable product's own page opens on a whole variation:
 * each attribute the product stores no default for takes its value from the
 * first variation a customer can buy that agrees with the values chosen
 * already, which without any is the one its picture is drawn for.
 */
final class OpeningDefaultsTest extends WP_UnitTestCase {

	use SplitPosters;

	private const PICTURE = 'Bild';

	private const SIZE = 'Storlek';

	private const WITH_PHOTO = 'med-bild';

	private const WITHOUT_PHOTO = 'utan-bild';

	private const SMALL = '30x40';

	private const LARGE = '50x70';

	public function set_up(): void {
		parent::set_up();
		Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' );
		$this->set_up_posters();
	}

	public function tear_down(): void {
		$this->tear_down_posters();
		unset( $_REQUEST['attribute_pa_bild'], $_REQUEST['attribute_pa_storlek'], $_REQUEST['attribute_size'] );
		delete_option( 'woocommerce_hide_out_of_stock_items' );
		parent::tear_down();
	}

	public function test_a_product_with_no_defaults_opens_on_the_variation_its_picture_shows(): void {
		$poster = $this->personalised_poster();
		$this->go_to( (string) get_permalink( $poster->get_id() ) );

		$pictured = PicturedVariation::of( $poster );
		$this->assertInstanceOf( WC_Product_Variation::class, $pictured );
		$this->assertSame( $pictured->get_attributes(), $poster->get_default_attributes() );
		$this->assertSame(
			array(
				'attribute_pa_bild'    => self::WITH_PHOTO,
				'attribute_pa_storlek' => self::SMALL,
			),
			OpeningChoice::values( $poster )
		);
	}

	public function test_an_attribute_without_a_default_follows_the_ones_that_have_one(): void {
		$poster = $this->personalised_poster( array( 'pa_bild' => self::WITHOUT_PHOTO ) );
		$this->go_to( (string) get_permalink( $poster->get_id() ) );

		$this->assertSame(
			array(
				'pa_bild'    => self::WITHOUT_PHOTO,
				'pa_storlek' => self::SMALL,
			),
			$poster->get_default_attributes()
		);
	}

	public function test_a_default_is_kept_when_no_variation_agrees_with_it(): void {
		// Ram is a value of the attribute no variation sells.
		$poster = $this->personalised_poster( array( 'pa_bild' => 'ram' ) );
		$this->go_to( (string) get_permalink( $poster->get_id() ) );

		$this->assertSame( array( 'pa_bild' => 'ram' ), $poster->get_default_attributes() );
	}

	public function test_whole_defaults_are_left_as_they_are(): void {
		$defaults = array(
			'pa_bild'    => self::WITH_PHOTO,
			'pa_storlek' => self::LARGE,
		);
		$poster   = $this->personalised_poster( $defaults );
		$this->go_to( (string) get_permalink( $poster->get_id() ) );

		$this->assertSame( $defaults, $poster->get_default_attributes() );
	}

	public function test_a_value_the_address_names_chooses_the_rest(): void {
		// Sold without a photo in the small size only.
		$poster                        = $this->personalised_poster();
		$_REQUEST['attribute_pa_bild'] = self::WITHOUT_PHOTO;
		$this->go_to( (string) get_permalink( $poster->get_id() ) );

		$this->assertSame(
			array(
				'attribute_pa_bild'    => self::WITHOUT_PHOTO,
				'attribute_pa_storlek' => self::SMALL,
			),
			OpeningChoice::values( $poster )
		);
	}

	public function test_a_value_the_address_names_keeps_the_default_of_another_attribute(): void {
		$poster                        = $this->personalised_poster( array( 'pa_storlek' => self::LARGE ) );
		$_REQUEST['attribute_pa_bild'] = self::WITH_PHOTO;
		$this->go_to( (string) get_permalink( $poster->get_id() ) );

		$this->assertSame(
			array(
				'attribute_pa_bild'    => self::WITH_PHOTO,
				'attribute_pa_storlek' => self::LARGE,
			),
			OpeningChoice::values( $poster )
		);
	}

	public function test_a_value_the_address_spells_another_way_chooses_the_rest_as_the_form_selects_it(): void {
		$poster                        = $this->personalised_poster();
		$_REQUEST['attribute_pa_bild'] = 'Utan Bild';
		$this->go_to( (string) get_permalink( $poster->get_id() ) );

		$this->assertSame( array( 'pa_storlek' => self::SMALL ), $poster->get_default_attributes() );
	}

	public function test_a_custom_value_the_address_names_by_its_slug_chooses_the_rest(): void {
		$product = new WC_Product_Variable();
		$product->set_name( 'Sign' );
		$product->set_status( 'publish' );
		$attributes = array();
		foreach ( array(
			'Size'   => array( 'Small', 'Large' ),
			'Finish' => array( 'Matt', 'Gloss' ),
		) as $name => $values ) {
			$attribute = new WC_Product_Attribute();
			$attribute->set_name( $name );
			$attribute->set_options( $values );
			$attribute->set_variation( true );
			$attributes[] = $attribute;
		}
		$product->set_attributes( $attributes );
		$product->save();
		foreach ( array( array( 'Small', 'Matt' ), array( 'Large', 'Gloss' ) ) as list( $size, $finish ) ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $product->get_id() );
			$variation->set_attributes(
				array(
					'size'   => $size,
					'finish' => $finish,
				)
			);
			$variation->set_regular_price( '10' );
			$variation->save();
		}
		VariationSwitchesTest::front()->save_to( $product );
		DesignFixture::sell_variant( $product );
		$product                    = $this->reloaded( $product );
		$_REQUEST['attribute_size'] = 'large';
		$this->go_to( (string) get_permalink( $product->get_id() ) );

		$this->assertSame( array( 'finish' => 'Gloss' ), $product->get_default_attributes() );
	}

	public function test_an_attribute_the_variation_is_sold_in_any_value_of_stays_open(): void {
		$poster = $this->personalised_poster( array(), array( array( self::WITH_PHOTO, '' ) ) );
		$this->go_to( (string) get_permalink( $poster->get_id() ) );

		$this->assertSame( array( 'pa_bild' => self::WITH_PHOTO ), $poster->get_default_attributes() );
	}

	public function test_a_variation_the_form_does_not_offer_is_passed_over(): void {
		update_option( 'woocommerce_hide_out_of_stock_items', 'yes' );
		$poster = $this->personalised_poster();
		$first  = wc_get_product( $poster->get_children()[0] );
		$this->assertInstanceOf( WC_Product_Variation::class, $first );
		$first->set_stock_status( 'outofstock' );
		$first->save();
		$poster = $this->reloaded( $poster );
		$this->go_to( (string) get_permalink( $poster->get_id() ) );

		$this->assertSame(
			array(
				'pa_bild'    => self::WITH_PHOTO,
				'pa_storlek' => self::LARGE,
			),
			$poster->get_default_attributes()
		);
	}

	public function test_nothing_is_filled_in_anywhere_but_the_products_own_page(): void {
		$poster = $this->personalised_poster();
		$this->go_to( home_url( '/' ) );

		$this->assertSame( array(), $poster->get_default_attributes() );
	}

	public function test_the_product_keeps_the_defaults_it_stores(): void {
		$poster = $this->personalised_poster();
		$this->go_to( (string) get_permalink( $poster->get_id() ) );
		$poster->get_default_attributes();
		$poster->save();

		$this->assertSame( array(), $this->reloaded( $poster )->get_default_attributes( 'edit' ) );
	}

	public function test_a_product_that_is_not_personalised_opens_as_woocommerce_has_it(): void {
		$poster = $this->poster();
		$this->go_to( (string) get_permalink( $poster->get_id() ) );

		$this->assertSame( array(), $poster->get_default_attributes() );
	}

	/**
	 * A poster personalised on the tee's front, with the defaults given.
	 *
	 * @param array<string, string>                  $defaults   Its default attributes; none for a product without.
	 * @param list<array{0: string, 1: string}>|null $variations The picture and size of each variation it sells, in order; null for the fixture's own.
	 */
	private function personalised_poster( array $defaults = array(), ?array $variations = null ): WC_Product {
		$poster = $this->poster();
		if ( null !== $variations ) {
			foreach ( $poster->get_children() as $child_id ) {
				wp_delete_post( $child_id, true );
			}
			foreach ( $variations as list( $picture, $size ) ) {
				$this->variation( $poster, $picture, $size );
			}
		}
		VariationSwitchesTest::front()->save_to( $poster );
		DesignFixture::sell_variant( $poster );
		$poster = $this->reloaded( $poster );
		if ( array() !== $defaults ) {
			$poster->set_default_attributes( $defaults );
			$poster->save();
		}
		return $this->reloaded( $poster );
	}

	private function reloaded( WC_Product $product ): WC_Product {
		WC_Product_Variable::sync( $product->get_id() );
		$read = wc_get_product( $product->get_id() );
		$this->assertInstanceOf( WC_Product::class, $read );
		return $read;
	}
}
