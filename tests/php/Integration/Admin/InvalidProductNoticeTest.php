<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Admin;

use Pixonaut\WooCommerce\Admin\InvalidProductNotice;
use Pixonaut\WooCommerce\Admin\Notices;
use Pixonaut\WooCommerce\Catalogue\InvalidDetail;
use Pixonaut\WooCommerce\Catalogue\InvalidReason;
use Pixonaut\WooCommerce\Catalogue\PrintSize;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_UnitTestCase;

/**
 * The notice of a product or variation customers cannot buy: what it is
 * about, where it links, and that it does not outlive the configuration
 * that raised it.
 */
final class InvalidProductNoticeTest extends WP_UnitTestCase {

	private const SIZE_ATTRIBUTE = 'Size';

	private const ORIENTATION_ATTRIBUTE = 'Orientation';

	private const COLOUR_ATTRIBUTE = 'Color';

	private Notices $notices;

	private InvalidProductNotice $notice;

	public function set_up(): void {
		parent::set_up();
		$plugin        = Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' );
		$this->notices = $plugin->notices();
		$this->notice  = new InvalidProductNotice( $this->notices );
		delete_option( Notices::OPTION );
	}

	public function tear_down(): void {
		delete_option( Notices::OPTION );
		parent::tear_down();
	}

	public function test_a_variations_notice_names_the_variation_and_links_to_its_products_variations(): void {
		$variation = $this->variation();
		$config    = self::config()->with_validity( false, InvalidReason::CHECK_FAILED, new InvalidDetail( code: ApiException::TRANSPORT_FAILED ) );
		$config->save_to( $variation );

		$this->notice->note( $variation, $config );

		$message = $this->notices->all()[ InvalidProductNotice::key( $variation->get_id() ) ]['message'] ?? '';
		$this->assertStringContainsString( 'Sagoslott, variation', $message );
		$this->assertStringContainsString( '50x70', $message, 'named by its attributes, as its own name is its product’s' );
		$this->assertStringContainsString( 'post=' . $variation->get_parent_id(), $message );
		$this->assertStringContainsString( '#variable_product_options', $message );
		$this->assertStringContainsString( 'could not be reached', $message, 'a call that got no answer says so' );
	}

	public function test_a_check_that_failed_without_an_unanswered_call_does_not_say_pixonaut_could_not_be_reached(): void {
		$this->assertStringNotContainsString( 'could not be reached', InvalidReason::message( InvalidReason::CHECK_FAILED ) );
		$this->assertStringNotContainsString( 'could not be reached', InvalidReason::message( InvalidReason::CHECK_FAILED, new InvalidDetail( code: ApiException::UNEXPECTED_RESPONSE ) ) );
		$this->assertStringContainsString( 'could not be reached', InvalidReason::message( InvalidReason::CHECK_FAILED, new InvalidDetail( code: ApiException::TRANSPORT_FAILED ) ) );
	}

	public function test_a_notice_left_over_a_configuration_saved_since_is_cleared_before_notices_show(): void {
		$variation = $this->variation();
		$failed    = self::config()->with_validity( false, InvalidReason::CHECK_FAILED, new InvalidDetail( code: ApiException::TRANSPORT_FAILED ) );
		$failed->save_to( $variation );
		$this->notice->note( $variation, $failed );
		// Saved meanwhile by another request, whose clearing of the notice was lost.
		self::config()->save_to( $this->reload( $variation->get_id() ) );
		$still        = $this->variation();
		$still_config = self::config()->with_validity( false, InvalidReason::TOO_LARGE );
		$still_config->save_to( $still );
		$this->notice->note( $still, $still_config );

		$this->notice->reconcile();

		$this->assertFalse( $this->notices->has( InvalidProductNotice::key( $variation->get_id() ) ) );
		$this->assertTrue( $this->notices->has( InvalidProductNotice::key( $still->get_id() ) ), 'a notice that still holds stays' );
	}

	public function test_a_notice_of_a_product_that_is_gone_is_cleared(): void {
		$this->notices->add( InvalidProductNotice::key( PHP_INT_MAX ), 'Customers cannot buy Gone: …', 'error', false );

		$this->notice->reconcile();

		$this->assertSame( array(), $this->notices->all() );
	}

	/**
	 * An overriding variation of a product named Sagoslott, with more than
	 * two attributes, so WooCommerce names it as its product.
	 */
	private function variation(): WC_Product {
		$parent     = new WC_Product_Variable();
		$attributes = array();
		foreach ( array(
			self::SIZE_ATTRIBUTE        => '50x70',
			self::COLOUR_ATTRIBUTE      => 'White',
			self::ORIENTATION_ATTRIBUTE => 'Vertical',
		) as $name => $value ) {
			$attribute = new WC_Product_Attribute();
			$attribute->set_name( $name );
			$attribute->set_options( array( $value ) );
			$attribute->set_visible( true );
			$attribute->set_variation( true );
			$attributes[] = $attribute;
		}
		$parent->set_name( 'Sagoslott' );
		$parent->set_attributes( $attributes );
		$parent->save();
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_attributes(
			array(
				sanitize_title( self::SIZE_ATTRIBUTE )   => '50x70',
				sanitize_title( self::COLOUR_ATTRIBUTE ) => 'White',
				sanitize_title( self::ORIENTATION_ATTRIBUTE ) => 'Vertical',
			)
		);
		$variation->set_regular_price( '30' );
		$variation->save();
		return $this->reload( $variation->get_id() );
	}

	/**
	 * A sellable overriding configuration.
	 */
	private static function config(): ProductConfig {
		return new ProductConfig(
			personalise: true,
			override: true,
			blank_id: 'blank_poster',
			sides: array( new Side( 'default', 'Print', 'default', 'Print', 'tpl_chart', 1, null, null, array( new PrintSize( 500, 700, 'Poster' ) ) ) ),
		);
	}

	/**
	 * @param int $product_id A product or variation.
	 */
	private function reload( int $product_id ): WC_Product {
		$product = wc_get_product( $product_id );
		$this->assertInstanceOf( WC_Product::class, $product );
		return $product;
	}
}
