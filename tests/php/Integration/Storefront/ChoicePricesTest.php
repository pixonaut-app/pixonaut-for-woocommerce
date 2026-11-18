<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Storefront;

use Pixonaut\WooCommerce\Admin\ChoicePriceField;
use Pixonaut\WooCommerce\Storefront\ChoicePrices;
use Pixonaut\WooCommerce\Storefront\PrintedSwatches;
use Pixonaut\WooCommerce\Storefront\ProductChoicePrices;
use Pixonaut\WooCommerce\Storefront\ProductPageCache;
use Pixonaut\WooCommerce\Templates;
use ReflectionMethod;
use WC_Product_Attribute;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_UnitTestCase;

/**
 * The prices a global attribute's swatches show: the merchant's choice per
 * attribute, and what a product page prints for it.
 */
final class ChoicePricesTest extends WP_UnitTestCase {

	private const SIZE = 'Size';

	private const FRAME = 'Frame';

	/**
	 * The global attributes, by name.
	 *
	 * @var array<string, int>
	 */
	private array $attributes = array();

	public function set_up(): void {
		parent::set_up();
		foreach ( array(
			self::SIZE  => array( 'S', 'M' ),
			self::FRAME => array( 'None', 'Oak' ),
		) as $name => $values ) {
			$id = wc_create_attribute( array( 'name' => $name ) );
			$this->assertIsInt( $id );
			$this->attributes[ $name ] = $id;
			$taxonomy                  = wc_attribute_taxonomy_name( $name );
			register_taxonomy( $taxonomy, array( 'product', 'product_variation' ) );
			// What WooCommerce holds for an attribute from `init` on.
			$GLOBALS['wc_product_attributes'][ $taxonomy ] = current( wp_list_filter( wc_get_attribute_taxonomies(), array( 'attribute_id' => $id ) ) );
			foreach ( $values as $value ) {
				wp_insert_term( $value, $taxonomy );
			}
		}
		ProductPageCache::forget_all();
	}

	public function tear_down(): void {
		foreach ( array_keys( $this->attributes ) as $name ) {
			$taxonomy = wc_attribute_taxonomy_name( $name );
			unregister_taxonomy( $taxonomy );
			unset( $GLOBALS['wc_product_attributes'][ $taxonomy ] );
		}
		delete_transient( 'wc_attribute_taxonomies' );
		delete_option( ChoicePrices::OPTION );
		$_POST = array();
		$_GET  = array();
		parent::tear_down();
	}

	public function test_an_attribute_shows_no_price_until_the_merchant_chooses_one(): void {
		$this->assertSame( ChoicePrices::NONE, ChoicePrices::choice( $this->attributes[ self::FRAME ] ) );
		$this->assertNull( ( new ProductChoicePrices() )->of( $this->poster() ) );
	}

	public function test_the_page_prints_the_variations_prices_and_the_shops_price_format(): void {
		ChoicePrices::set_choice( $this->attributes[ self::SIZE ], ChoicePrices::FROM );
		ChoicePrices::set_choice( $this->attributes[ self::FRAME ], ChoicePrices::ADD_ON );
		update_option( 'woocommerce_currency_pos', 'right_space' );
		update_option( 'woocommerce_price_thousand_sep', ' ' );
		update_option( 'woocommerce_price_num_decimals', '0' );

		$data = ( new ProductChoicePrices() )->of( $this->poster() );

		$this->assertNotNull( $data );
		$this->assertSame( array( 'attribute_pa_size', 'attribute_pa_frame' ), $data['fields'] );
		$this->assertSame(
			array(
				'attribute_pa_size'  => ChoicePrices::FROM,
				'attribute_pa_frame' => ChoicePrices::ADD_ON,
			),
			$data['roles']
		);
		$this->assertCount( 4, $data['variations'] );
		$this->assertContains(
			array(
				'values' => array(
					'attribute_pa_size'  => 'm',
					'attribute_pa_frame' => 'oak',
				),
				'price'  => 1280.0,
			),
			$data['variations']
		);
		$this->assertSame( "%2\$s\u{a0}%1\$s", $data['format']['pattern'], 'the shop\'s no-break space as text' );
		$this->assertSame( 0, $data['decimals'] );
	}

	public function test_each_value_shows_its_amount_for_the_choice_written_as_the_shop_writes_prices(): void {
		ChoicePrices::set_choice( $this->attributes[ self::SIZE ], ChoicePrices::FROM );
		ChoicePrices::set_choice( $this->attributes[ self::FRAME ], ChoicePrices::ADD_ON );
		update_option( 'woocommerce_currency', 'SEK' );
		update_option( 'woocommerce_currency_pos', 'right_space' );
		update_option( 'woocommerce_price_thousand_sep', ' ' );
		update_option( 'woocommerce_price_num_decimals', '0' );
		$prices  = new ProductChoicePrices();
		$product = $this->poster();
		$chosen  = array(
			'attribute_pa_size'  => 'm',
			'attribute_pa_frame' => 'none',
		);

		$this->assertSame( array( 'oak' => "+1 080\u{a0}kr" ), $prices->texts( $product, 'attribute_pa_frame', $chosen ) );
		$this->assertSame(
			array(
				's' => "from 100\u{a0}kr",
				'm' => "from 200\u{a0}kr",
			),
			$prices->texts( $product, 'attribute_pa_size', $chosen )
		);
		$this->assertSame( array(), $prices->texts( $product, 'attribute_pa_other', $chosen ) );
	}

	public function test_a_printed_swatch_carries_its_price_after_its_name(): void {
		$swatches = new ReflectionMethod( PrintedSwatches::class, 'swatches' );
		$swatches->setAccessible( true );

		$this->assertSame(
			'<div class="pixonaut-swatches pixonaut-swatches--printed" aria-hidden="true" inert>'
				. '<span class="pixonaut-swatches__swatch is-chosen">None</span>'
				. '<span class="pixonaut-swatches__swatch"><span class="pixonaut-swatches__name">Oak</span><span class="pixonaut-swatches__price">+50 kr</span></span>'
				. '</div>',
			$swatches->invoke(
				null,
				array(
					'none' => 'None',
					'oak'  => 'Oak',
				),
				'none',
				null,
				array( 'oak' => '+50 kr' )
			)
		);
	}

	public function test_the_attribute_screens_save_its_choice_and_a_deleted_attribute_forgets_it(): void {
		$frame = $this->attributes[ self::FRAME ];
		$field = new ChoicePriceField( Templates::for_plugin( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' ) );
		$field->register();
		ChoicePrices::register();

		$_POST = array(
			ChoicePriceField::FIELD       => ChoicePrices::ADD_ON,
			ChoicePriceField::NONCE_FIELD => wp_create_nonce( ChoicePriceField::NONCE_ACTION ),
		);
		wc_update_attribute( $frame, array( 'name' => self::FRAME ) );
		$this->assertSame( ChoicePrices::ADD_ON, ChoicePrices::choice( $frame ) );

		$_GET['edit'] = (string) $frame;
		ob_start();
		$field->render_edit();
		$edited = (string) ob_get_clean();
		$this->assertStringContainsString( '<tr class="form-field pixonaut-choice-price">', $edited );
		$this->assertStringContainsString( 'value="add_on" selected=\'selected\'', $edited );

		$_POST = array( ChoicePriceField::FIELD => ChoicePrices::FROM );
		wc_update_attribute( $frame, array( 'name' => self::FRAME ) );
		$this->assertSame( ChoicePrices::ADD_ON, ChoicePrices::choice( $frame ), 'an update not from the screen leaves it' );

		$_POST = array(
			ChoicePriceField::FIELD       => ChoicePrices::NONE,
			ChoicePriceField::NONCE_FIELD => wp_create_nonce( ChoicePriceField::NONCE_ACTION ),
		);
		wc_update_attribute( $frame, array( 'name' => self::FRAME ) );
		$this->assertFalse( get_option( ChoicePrices::OPTION ), 'None stores nothing' );

		ChoicePrices::set_choice( $frame, ChoicePrices::FROM );
		wc_delete_attribute( $frame );
		$this->assertFalse( get_option( ChoicePrices::OPTION ) );
	}

	/**
	 * A value's term id.
	 *
	 * @param string $slug     The value's slug.
	 * @param string $taxonomy Its attribute's taxonomy.
	 */
	private static function term_id( string $slug, string $taxonomy ): int {
		$term = get_term_by( 'slug', $slug, $taxonomy );
		return $term instanceof \WP_Term ? $term->term_id : 0;
	}

	/**
	 * A poster in two sizes, bare or in an oak frame.
	 */
	private function poster(): WC_Product_Variable {
		$product    = new WC_Product_Variable();
		$attributes = array();
		foreach ( array(
			self::SIZE  => array( 's', 'm' ),
			self::FRAME => array( 'none', 'oak' ),
		) as $name => $slugs ) {
			$taxonomy  = wc_attribute_taxonomy_name( $name );
			$attribute = new WC_Product_Attribute();
			$attribute->set_id( $this->attributes[ $name ] );
			$attribute->set_name( $taxonomy );
			$attribute->set_options( array_map( static fn ( string $slug ): int => self::term_id( $slug, $taxonomy ), $slugs ) );
			$attribute->set_variation( true );
			$attributes[] = $attribute;
		}
		$product->set_attributes( $attributes );
		$product->save();
		foreach ( array(
			array( 's', 'none', '100' ),
			array( 's', 'oak', '150' ),
			array( 'm', 'none', '200' ),
			array( 'm', 'oak', '1280' ),
		) as [ $size, $frame, $price ] ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $product->get_id() );
			$variation->set_attributes(
				array(
					'pa_size'  => $size,
					'pa_frame' => $frame,
				)
			);
			$variation->set_regular_price( $price );
			$variation->save();
		}
		WC_Product_Variable::sync( $product->get_id() );
		$reloaded = wc_get_product( $product->get_id() );
		$this->assertInstanceOf( WC_Product_Variable::class, $reloaded );
		return $reloaded;
	}
}
