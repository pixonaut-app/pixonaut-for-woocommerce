<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Storefront;

use Pixonaut\WooCommerce\Catalogue\RequestCache;
use Pixonaut\WooCommerce\Layout;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Schema;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Storefront\InlineDesigner;
use Pixonaut\WooCommerce\Tests\Support\DesignFixture;
use Pixonaut\WooCommerce\Tests\Support\FreshRequest;
use Pixonaut\WooCommerce\Tests\Support\SplitPosters;
use WC_Product;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_UnitTestCase;

/**
 * A personalised variable product's page builds its designer, its form's
 * variations and its delivery rows once, and takes them from what is kept
 * in the next request, until something they are built from changes: then
 * the next request shows the change.
 */
final class ProductPageCacheTest extends WP_UnitTestCase {

	use SplitPosters;

	private const PICTURE = 'Bild';

	private const SIZE = 'Storlek';

	/** The variation each change is made to: the poster's first. */
	private const CHANGED = 0;

	/**
	 * How many variations WooCommerce built the form's data of.
	 *
	 * @var int
	 */
	private int $built = 0;

	/**
	 * The variation a test changes.
	 *
	 * @var int
	 */
	private int $changed_id = 0;

	public function set_up(): void {
		parent::set_up();
		Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' );
		$this->set_up_posters();
		$this->built = 0;
		add_filter( 'woocommerce_available_variation', array( $this, 'count_built' ) );
	}

	public function tear_down(): void {
		remove_filter( 'woocommerce_available_variation', array( $this, 'count_built' ) );
		$this->tear_down_posters();
		Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' )->settings()->set( Settings::DEFAULT_LAYOUT, Layout::INLINE );
		parent::tear_down();
	}

	/**
	 * Counts each variation WooCommerce builds the form's data of.
	 *
	 * @param mixed $data What the form is told of a variation.
	 * @return mixed
	 */
	public function count_built( mixed $data ): mixed {
		++$this->built;
		return $data;
	}

	public function test_the_next_request_takes_the_page_from_what_is_kept(): void {
		$poster = $this->personalised();
		$first  = $this->page( $poster );
		$this->assertSame( count( $poster->get_children() ), $this->built, 'built once' );

		$this->assertSame( $first, $this->page( $poster ) );
		$this->assertSame( count( $poster->get_children() ), $this->built, 'taken from what is kept' );
	}

	public function test_the_form_is_handed_every_variation_as_woocommerce_tells_them(): void {
		$poster = $this->personalised();
		$this->next_request( $poster );
		// As the form's script reads them: decoded from JSON.
		$expected = json_decode( (string) wp_json_encode( $poster->get_available_variations() ), true );

		$this->assertSame( $expected, $this->page( $poster )['variations'] );
		$this->assertSame( $expected, $this->page( $poster )['variations'], 'as kept' );
	}

	public function test_a_price_change_shows_on_the_next_request(): void {
		$poster = $this->personalised();
		$this->page( $poster );

		$variation = $this->variation_of( $poster );
		$variation->set_regular_price( '99' );
		$variation->save();

		$this->assertEquals( 99, $this->changed( $this->page( $poster ) )['display_price'] );
	}

	public function test_a_stock_change_shows_on_the_next_request(): void {
		$poster    = $this->personalised();
		$variation = $this->variation_of( $poster );
		$variation->set_manage_stock( true );
		$variation->set_stock_quantity( 5 );
		$variation->save();
		$this->assertTrue( $this->changed( $this->page( $poster ) )['is_in_stock'] );

		wc_update_product_stock( $variation, 0 );

		$this->assertFalse( $this->changed( $this->page( $poster ) )['is_in_stock'] );
	}

	public function test_an_attribute_change_shows_on_the_next_request(): void {
		$poster = $this->personalised();
		$this->page( $poster );

		$variation = $this->variation_of( $poster );
		$variation->set_attributes(
			array(
				'pa_bild'    => 'utan-bild',
				'pa_storlek' => '50x70',
			)
		);
		$variation->save();

		$this->assertSame( '50x70', $this->changed( $this->page( $poster ) )['attributes']['attribute_pa_storlek'] );
		$this->assertSame( 'utan-bild', $this->changed( $this->page( $poster ) )['attributes']['attribute_pa_bild'] );
	}

	public function test_an_image_change_shows_on_the_next_request(): void {
		$poster = $this->personalised();
		$this->page( $poster );
		$image = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$this->assertIsInt( $image );

		$variation = $this->variation_of( $poster );
		$variation->set_image_id( $image );
		$variation->save();

		$this->assertSame( $image, $this->changed( $this->page( $poster ) )['image_id'] );
	}

	public function test_a_template_change_shows_on_the_next_request(): void {
		$poster = $this->personalised();
		$this->assertSame( 2, $this->page( $poster )['template_version'] );

		VariationSwitchesTest::front( array( 'version' => 3 ) )->save_to( $poster );

		$this->assertSame( 3, $this->page( $poster )['template_version'] );
	}

	public function test_a_settings_change_shows_on_the_next_request(): void {
		$poster = $this->personalised();
		$this->assertTrue( $this->page( $poster )['designer'] );

		Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' )->settings()->set( Settings::DEFAULT_LAYOUT, Layout::BUTTON );

		$this->assertFalse( $this->page( $poster )['designer'] );
	}

	public function test_a_description_change_shows_in_the_delivery_row_on_the_next_request(): void {
		$poster = $this->personalised();
		$this->page( $poster );

		$variation = $this->variation_of( $poster );
		$variation->set_description( 'Inramad i ek.' );
		$variation->save();

		$this->assertStringContainsString( 'Inramad i ek.', $this->page( $poster )['rows'] );
	}

	public function test_a_currency_change_shows_on_the_next_request(): void {
		$poster = $this->personalised();
		// The form shows a variation's price while the variations' prices differ.
		$variation = $this->variation_of( $poster );
		$variation->set_regular_price( '99' );
		$variation->save();
		$this->assertStringNotContainsString( get_woocommerce_currency_symbol( 'SEK' ), $this->changed( $this->page( $poster ) )['price_html'] );

		update_option( 'woocommerce_currency', 'SEK' );

		$this->assertStringContainsString( get_woocommerce_currency_symbol( 'SEK' ), $this->changed( $this->page( $poster ) )['price_html'] );
	}

	public function test_another_language_reads_its_own(): void {
		$poster = $this->personalised();
		$this->page( $poster );
		$built = $this->built;

		$swedish = static fn (): string => 'sv_SE';
		add_filter( 'determine_locale', $swedish );
		try {
			$this->page( $poster );
		} finally {
			remove_filter( 'determine_locale', $swedish );
		}

		$this->assertGreaterThan( $built, $this->built );
	}

	public function test_a_tax_display_change_and_another_tax_location_read_their_own(): void {
		update_option( 'woocommerce_calc_taxes', 'yes' );
		$poster = $this->personalised();
		$this->page( $poster );
		$built = $this->built;

		update_option( 'woocommerce_tax_display_shop', 'incl' );
		$this->page( $poster );
		$this->assertGreaterThan( $built, $this->built, 'tax display' );
		$built = $this->built;

		WC()->customer = new \WC_Customer( 0, true );
		WC()->customer->set_billing_country( 'NO' );
		WC()->customer->set_shipping_country( 'NO' );
		$this->page( $poster );
		$this->assertGreaterThan( $built, $this->built, 'tax location' );
	}

	public function test_nothing_of_a_shopper_is_kept(): void {
		$poster = $this->personalised();
		$user   = self::factory()->user->create( array( 'role' => 'customer' ) );
		$this->assertIsInt( $user );
		wp_set_current_user( $user );
		$this->page( $poster );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$kept = (string) implode( "\n", $wpdb->get_col( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_' . Schema::PRODUCT_PAGE_CACHE_PREFIX ) . '%' ) ) );
		$this->assertNotSame( '', $kept );
		$this->assertNotSame( '', wp_get_current_user()->user_login );
		foreach ( array( wp_get_current_user()->user_login, wp_get_current_user()->user_email ) as $shopper ) {
			$this->assertStringNotContainsString( '"' . $shopper . '"', $kept );
		}
	}

	public function test_a_plugin_option_of_bookkeeping_keeps_what_is_kept(): void {
		$poster = $this->personalised();
		$this->page( $poster );
		$built = $this->built;

		update_option( Schema::REQUEST_LOG_OPTION, array( 'x' ) );
		$this->page( $poster );

		$this->assertSame( $built, $this->built );
	}

	/**
	 * What the product's page shows, as the next request builds it: the
	 * variations the form is handed, whether the designer shows, its first
	 * side's template version, and the attributes table's rows.
	 *
	 * @param WC_Product $product The variable product.
	 * @return array{variations: mixed, designer: bool, template_version: int|null, rows: string}
	 */
	private function page( WC_Product $product ): array {
		$product = $this->next_request( $product );
		$plugin  = Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' );

		$GLOBALS['product'] = $product;
		ob_start();
		woocommerce_variable_add_to_cart();
		$form = (string) ob_get_clean();

		$designer = $plugin->inline_products()->for_product( $product );
		$rows     = apply_filters( 'woocommerce_display_product_attributes', array(), $product );
		return array(
			'variations'       => self::handed( $form ),
			'designer'         => $designer instanceof InlineDesigner,
			'template_version' => null === $designer ? null : ( $designer->config->sides[0]->template_version ?? null ),
			'rows'             => (string) wp_json_encode( $rows ),
		);
	}

	/**
	 * The variations the form is handed, as WooCommerce's script reads them.
	 *
	 * @param string $form The form's HTML.
	 * @return mixed
	 */
	private static function handed( string $form ): mixed {
		if ( 1 !== preg_match( '/setAttribute\("data-product_variations",(".*?")\);/', $form, $matches ) ) {
			return null;
		}
		return json_decode( (string) json_decode( $matches[1] ), true );
	}

	/**
	 * What the form is told of the changed variation.
	 *
	 * @param array{variations: mixed} $page The page.
	 * @return array<string, mixed>
	 */
	private function changed( array $page ): array {
		$this->assertIsArray( $page['variations'] );
		foreach ( $page['variations'] as $variation ) {
			if ( $this->changed_id === $variation['variation_id'] ) {
				return $variation;
			}
		}
		$this->fail( 'The changed variation is in the form' );
	}

	private function variation_of( WC_Product $product ): WC_Product_Variation {
		$this->changed_id = (int) $product->get_children()[ self::CHANGED ];
		$variation        = wc_get_product( $this->changed_id );
		$this->assertInstanceOf( WC_Product_Variation::class, $variation );
		return $variation;
	}

	/**
	 * Starts a request of its own on the product's page, as the next
	 * shopper's: nothing read in this one is held, only what is kept.
	 *
	 * @param WC_Product $product The variable product.
	 */
	private function next_request( WC_Product $product ): WC_Product {
		FreshRequest::forget_filter_reads();
		FreshRequest::forget_page_reads();
		RequestCache::forget();
		$this->go_to( (string) get_permalink( $product->get_id() ) );
		$fresh = wc_get_product( $product->get_id() );
		$this->assertInstanceOf( WC_Product::class, $fresh );
		return $fresh;
	}

	private function personalised(): WC_Product_Variable {
		$poster = $this->poster();
		VariationSwitchesTest::front()->save_to( $poster );
		DesignFixture::sell_variant( $poster );
		WC_Product_Variable::sync( $poster->get_id() );
		$read = wc_get_product( $poster->get_id() );
		$this->assertInstanceOf( WC_Product_Variable::class, $read );
		return $read;
	}
}
