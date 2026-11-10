<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Storefront;

use Pixonaut\WooCommerce\Admin\SwatchNamesField;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Storefront\SwatchLooks;
use Pixonaut\WooCommerce\Storefront\SwatchNames;
use Pixonaut\WooCommerce\Templates;
use WC_Product_Attribute;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_UnitTestCase;

final class SwatchLooksTest extends WP_UnitTestCase {

	private const FRAME = 'Frame';

	private const WOODEN_URL = 'https://cdn.example.test/values/wooden.png';

	private const OAK_URL = 'https://cdn.example.test/values/oak.svg';

	private Settings $settings;

	private int $frame = 0;

	public function set_up(): void {
		parent::set_up();
		$this->settings = Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' )->settings();
		$this->frame    = wc_create_attribute( array( 'name' => self::FRAME ) );
		$this->assertIsInt( $this->frame );
		$taxonomy = wc_attribute_taxonomy_name( self::FRAME );
		register_taxonomy( $taxonomy, array( 'product', 'product_variation' ) );
		// What WooCommerce holds for an attribute from `init` on.
		$GLOBALS['wc_product_attributes'][ $taxonomy ] = current( wp_list_filter( wc_get_attribute_taxonomies(), array( 'attribute_id' => $this->frame ) ) );
		foreach ( array( 'White', 'Black and white', 'Wooden', 'Oak', 'No frame' ) as $value ) {
			wp_insert_term( $value, $taxonomy );
		}
	}

	public function tear_down(): void {
		$taxonomy = wc_attribute_taxonomy_name( self::FRAME );
		unregister_taxonomy( $taxonomy );
		unset( $GLOBALS['wc_product_attributes'][ $taxonomy ] );
		delete_transient( 'wc_attribute_taxonomies' );
		delete_option( SwatchNames::OPTION );
		$this->settings->set( Settings::SWATCH_NAMES, true );
		$_POST = array();
		$_GET  = array();
		parent::tear_down();
	}

	public function test_each_value_takes_the_look_of_the_blanks_value_of_its_name(): void {
		$looks = SwatchLooks::for_product( $this->poster(), array( self::options(), array() ), $this->settings );

		$this->assertSame(
			array(
				'attribute_pa_frame' => array(
					'names' => true,
					'looks' => array(
						'white'           => array(
							'kind'    => 'fill',
							'colours' => array( '#ffffff' ),
						),
						'black-and-white' => array(
							'kind'    => 'fill',
							'colours' => array( '#000000', '#ffffff' ),
						),
						'wooden'          => array(
							'kind' => 'image',
							'url'  => self::WOODEN_URL,
						),
						'oak'             => array(
							'kind' => 'image',
							'url'  => self::OAK_URL,
						),
					),
				),
				'attribute_size'     => array(
					'names' => true,
					'looks' => array(
						'Large' => array(
							'kind'    => 'fill',
							'colours' => array( '#123456' ),
						),
					),
				),
			),
			$looks
		);
	}

	public function test_an_attribute_named_as_written_in_pixonaut_matches_when_the_designers_language_does_not(): void {
		$in_swedish = array(
			array(
				'name'        => 'Ram',
				'values'      => array( 'Trä' ),
				'appearances' => array(
					'Trä' => array(
						'kind' => 'image',
						'url'  => 'https://cdn.example.test/values/tra.png',
					),
				),
			),
		);

		$looks = SwatchLooks::for_product( $this->poster(), array( $in_swedish, self::options() ), $this->settings );

		$this->assertSame( self::WOODEN_URL, $looks['attribute_pa_frame']['looks']['wooden']['url'] ?? null );
	}

	public function test_a_look_it_cannot_draw_safely_is_left_out_and_so_is_an_attribute_without_one(): void {
		$options = array(
			array(
				'name'        => 'frame',
				'values'      => array( 'White', 'Wooden', 'Oak' ),
				'appearances' => array(
					'White'  => array(
						'kind'    => 'fill',
						'colours' => array( 'red;background:url(x)' ),
					),
					'Wooden' => array(
						'kind' => 'image',
						'url'  => 'javascript:alert(1)',
					),
					'Oak'    => null,
				),
			),
		);

		$this->assertSame( array(), SwatchLooks::for_product( $this->poster(), array( $options ), $this->settings ) );
	}

	public function test_an_attribute_shows_or_hides_the_names_whatever_the_stores_setting_says(): void {
		$this->settings->set( Settings::SWATCH_NAMES, false );
		$looks = SwatchLooks::for_product( $this->poster(), array( self::options() ), $this->settings );
		$this->assertFalse( $looks['attribute_pa_frame']['names'], 'the store hides them' );
		$this->assertFalse( $looks['attribute_size']['names'], 'a custom attribute follows the store' );

		SwatchNames::set_choice( $this->frame, SwatchNames::SHOW );
		$this->assertTrue( SwatchLooks::for_product( $this->poster(), array( self::options() ), $this->settings )['attribute_pa_frame']['names'] );

		$this->settings->set( Settings::SWATCH_NAMES, true );
		SwatchNames::set_choice( $this->frame, SwatchNames::HIDE );
		$looks = SwatchLooks::for_product( $this->poster(), array( self::options() ), $this->settings );
		$this->assertFalse( $looks['attribute_pa_frame']['names'] );
		$this->assertTrue( $looks['attribute_size']['names'] );
	}

	public function test_the_attribute_screens_save_its_choice_and_a_deleted_attribute_forgets_it(): void {
		$field = new SwatchNamesField( Templates::for_plugin( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' ) );
		$field->register();
		SwatchNames::register();

		$_POST = array(
			SwatchNamesField::FIELD       => SwatchNames::HIDE,
			SwatchNamesField::NONCE_FIELD => wp_create_nonce( SwatchNamesField::NONCE_ACTION ),
		);
		wc_update_attribute( $this->frame, array( 'name' => self::FRAME ) );
		$this->assertSame( SwatchNames::HIDE, SwatchNames::choice( $this->frame ) );

		$_GET['edit'] = (string) $this->frame;
		ob_start();
		$field->render_edit();
		$edited = (string) ob_get_clean();
		$this->assertStringContainsString( '<tr class="form-field pixonaut-swatch-names">', $edited );
		$this->assertStringContainsString( 'value="hide" selected=\'selected\'', $edited );

		$_POST = array( SwatchNamesField::FIELD => SwatchNames::SHOW );
		wc_update_attribute( $this->frame, array( 'name' => self::FRAME ) );
		$this->assertSame( SwatchNames::HIDE, SwatchNames::choice( $this->frame ), 'an update not from the screen leaves it' );

		$_POST = array(
			SwatchNamesField::FIELD       => SwatchNames::INHERIT,
			SwatchNamesField::NONCE_FIELD => wp_create_nonce( SwatchNamesField::NONCE_ACTION ),
		);
		wc_update_attribute( $this->frame, array( 'name' => self::FRAME ) );
		$this->assertFalse( get_option( SwatchNames::OPTION ), 'Inherit stores nothing' );

		SwatchNames::set_choice( $this->frame, SwatchNames::SHOW );
		wc_delete_attribute( $this->frame );
		$this->assertFalse( get_option( SwatchNames::OPTION ) );
	}

	/**
	 * The blank's options as Pixonaut answers them: a frame drawn as
	 * colours and pictures, one value as its name, and a size of which one
	 * value is a colour.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function options(): array {
		return array(
			array(
				'name'        => 'frame',
				'values'      => array( 'White', 'Black and White', 'Wooden', 'Oak', 'No frame' ),
				'appearances' => array(
					'White'           => array(
						'kind'    => 'fill',
						'colours' => array( '#ffffff' ),
					),
					'Black and White' => array(
						'kind'    => 'fill',
						'colours' => array( '#000000', '#ffffff' ),
					),
					'Wooden'          => array(
						'kind'    => 'image',
						'assetId' => 'asset_wooden',
						'url'     => self::WOODEN_URL,
						'mime'    => 'image/png',
					),
					'Oak'             => array(
						'kind'    => 'image',
						'assetId' => 'asset_oak',
						'url'     => self::OAK_URL,
					),
					'No frame'        => null,
				),
			),
			array(
				'name'        => 'Size',
				'values'      => array( 'Small', 'Large' ),
				'appearances' => array(
					'Small' => null,
					'Large' => array(
						'kind'    => 'fill',
						'colours' => array( '#123456' ),
					),
				),
			),
		);
	}

	/**
	 * A poster in each frame and two sizes, the frame a global attribute
	 * and the size a custom one.
	 */
	private function poster(): WC_Product_Variable {
		$taxonomy = wc_attribute_taxonomy_name( self::FRAME );
		$frame    = new WC_Product_Attribute();
		$frame->set_id( $this->frame );
		$frame->set_name( $taxonomy );
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);
		$frame->set_options( is_array( $terms ) ? $terms : array() );
		$frame->set_variation( true );
		$size = new WC_Product_Attribute();
		$size->set_name( 'Size' );
		$size->set_options( array( 'Small', 'Large' ) );
		$size->set_variation( true );
		$product = new WC_Product_Variable();
		$product->set_name( 'Poster' );
		$product->set_attributes( array( $frame, $size ) );
		$product->save();
		foreach ( array( 'white', 'black-and-white', 'wooden', 'oak', 'no-frame' ) as $slug ) {
			foreach ( array( 'Small', 'Large' ) as $value ) {
				$variation = new WC_Product_Variation();
				$variation->set_parent_id( $product->get_id() );
				$variation->set_attributes(
					array(
						$taxonomy => $slug,
						'size'    => $value,
					)
				);
				$variation->set_regular_price( '10' );
				$variation->save();
			}
		}
		$fresh = wc_get_product( $product->get_id() );
		$this->assertInstanceOf( WC_Product_Variable::class, $fresh );
		return $fresh;
	}
}
