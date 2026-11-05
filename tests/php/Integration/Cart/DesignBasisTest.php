<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Cart;

use Pixonaut\WooCommerce\Cart\CartCleanup;
use Pixonaut\WooCommerce\Cart\DesignBasis;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Tests\Support\ActionSchedulerRunner;
use Pixonaut\WooCommerce\Tests\Support\DesignFixture;
use WC_Cart_Session;
use WC_Product;
use WC_Product_Simple;

final class DesignBasisTest extends PersonalisedCartTestCase {

	public function set_up(): void {
		parent::set_up();
		as_unschedule_all_actions( CartCleanup::ACTION );
		$this->script_commit();
		$this->assertTrue( $this->post_add_to_cart() );
		WC()->cart->set_session();
		wc_clear_notices();
	}

	public function tear_down(): void {
		as_unschedule_all_actions( CartCleanup::ACTION );
		parent::tear_down();
	}

	/**
	 * The product's configuration with its front side changed.
	 *
	 * @return iterable<string, array{0: callable(Side): Side}>
	 */
	public static function side_changes(): iterable {
		yield 'placement' => array( static fn ( Side $side ): Side => new Side( $side->key, $side->label, 'back', 'Back', $side->template_base_id, $side->template_version, $side->previous_version, $side->template_name, $side->print_sizes ) );
		yield 'template' => array( static fn ( Side $side ): Side => new Side( $side->key, $side->label, $side->placement_id, $side->placement_name, 'tpl_other', $side->template_version, $side->previous_version, $side->template_name, $side->print_sizes ) );
		yield 'side key' => array( static fn ( Side $side ): Side => new Side( 'chest', $side->label, $side->placement_id, $side->placement_name, $side->template_base_id, $side->template_version, $side->previous_version, $side->template_name, $side->print_sizes ) );
	}

	/**
	 * @dataProvider side_changes
	 *
	 * @param callable(Side): Side $change Changes the front side.
	 */
	public function test_changing_what_a_carted_design_was_made_on_drops_the_line_and_its_design( callable $change ): void {
		$config = self::config();
		$config->with_sides( array( $change( $config->sides[0] ) ) )->save_to( $this->product() );

		self::reload_cart();

		$this->assert_dropped();
		$this->assertCount( 1, wc_get_notices( 'notice' ), 'WooCommerce says the product was modified.' );
	}

	public function test_changing_the_blank_drops_the_line(): void {
		$config = self::config();
		( new ProductConfig( personalise: true, blank_id: 'blank_other', blank_name: 'Other tee', sides: $config->sides ) )->save_to( $this->product() );

		self::reload_cart();

		$this->assert_dropped();
	}

	public function test_adding_a_side_drops_the_line(): void {
		$config = self::config();
		$front  = $config->sides[0];
		$config->with_sides( array( $front, new Side( 'back', 'Back', 'back', 'Back', $front->template_base_id, $front->template_version, $front->previous_version, null, $front->print_sizes ) ) )
			->save_to( $this->product() );

		self::reload_cart();

		$this->assert_dropped();
	}

	public function test_changing_the_price_or_a_template_s_live_version_keeps_the_line(): void {
		$product = $this->product();
		$product->set_regular_price( '99' );
		$product->set_description( 'Now in organic cotton.' );
		$product->save();
		$front = self::config()->sides[0];
		self::config()->with_sides( array( new Side( $front->key, $front->label, $front->placement_id, $front->placement_name, $front->template_base_id, DesignFixture::LIVE_VERSION + 1, DesignFixture::LIVE_VERSION, $front->template_name, $front->print_sizes ) ) )
			->save_to( $this->product() );

		self::reload_cart();

		$this->assertCount( 1, WC()->cart->get_cart() );
		$this->assertSame( array(), ActionSchedulerRunner::pending( CartCleanup::ACTION ) );
	}

	public function test_a_line_carted_before_the_basis_joined_the_hash_is_kept(): void {
		$this->cart_without_basis();

		self::reload_cart();

		$this->assertCount( 1, WC()->cart->get_cart() );
		$this->assertSame( array(), wc_get_notices() );
		$this->assertSame( array(), ActionSchedulerRunner::pending( CartCleanup::ACTION ) );
		$item = (array) reset( WC()->cart->cart_contents );
		$this->assertSame( wc_get_cart_item_data_hash( $this->product() ), $item['data_hash'] ?? null, 'The line takes the hash with the basis.' );
	}

	public function test_a_line_carted_before_the_basis_joined_the_hash_drops_once_its_product_changes(): void {
		$this->cart_without_basis();
		self::reload_cart();
		WC()->cart->set_session();
		$config = self::config();
		$front  = $config->sides[0];
		$config->with_sides( array( new Side( $front->key, $front->label, 'back', 'Back', $front->template_base_id, $front->template_version, $front->previous_version, $front->template_name, $front->print_sizes ) ) )
			->save_to( $this->product() );

		self::reload_cart();

		$this->assert_dropped();
	}

	/**
	 * Gives the carted line the hash WooCommerce made before the basis joined it.
	 */
	private function cart_without_basis(): void {
		$basis = static fn ( array $data ): array => array_diff_key( $data, array( DesignBasis::KEY => true ) );
		add_filter( 'woocommerce_cart_item_data_to_validate', $basis, PHP_INT_MAX );
		$hash  = wc_get_cart_item_data_hash( $this->product() );
		remove_filter( 'woocommerce_cart_item_data_to_validate', $basis, PHP_INT_MAX );
		$cart = (array) WC()->session->get( 'cart' );
		foreach ( array_keys( $cart ) as $key ) {
			$cart[ $key ]['data_hash'] = $hash;
		}
		WC()->session->set( 'cart', $cart );
	}

	public function test_a_product_that_is_not_personalised_hashes_as_woocommerce_does(): void {
		$plain = new WC_Product_Simple();
		$plain->save();

		$this->assertSame(
			md5(
				(string) wp_json_encode(
					array(
						'type'       => 'simple',
						'attributes' => '',
					)
				)
			),
			wc_get_cart_item_data_hash( $plain )
		);
	}

	private function assert_dropped(): void {
		$this->assertSame( array(), WC()->cart->get_cart() );
		$this->assertCount( 1, array_merge( ...array_values( wc_get_notices() ) ), 'The customer is told why.' );
		$this->assertCount( 1, ActionSchedulerRunner::pending( CartCleanup::ACTION ), 'Its design is deleted.' );
	}

	private function product(): WC_Product {
		$product = wc_get_product( $this->product_id );
		$this->assertInstanceOf( WC_Product::class, $product );
		return $product;
	}

	/**
	 * Loads the cart from the session, as the customer's next request does.
	 */
	private static function reload_cart(): void {
		// A request starts with an empty cart; WooCommerce keeps what it held when it drops every line.
		WC()->cart->set_cart_contents( array() );
		( new WC_Cart_Session( WC()->cart ) )->get_cart_from_session();
	}
}
