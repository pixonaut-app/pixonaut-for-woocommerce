<?php
/**
 * A connected store with a personalised product and an empty cart, for cart
 * and checkout tests.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Cart;

use Pixonaut\WooCommerce\Admin\Notices;
use Pixonaut\WooCommerce\Api\Hosts;
use Pixonaut\WooCommerce\Cart\AddToCart;
use Pixonaut\WooCommerce\Catalogue\PrintSize;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Refs;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Tests\Support\DesignFixture;
use Pixonaut\WooCommerce\Tests\Support\HttpRecorder;
use Pixonaut\WooCommerce\Tests\Support\VariantsAnswer;
use WC_Product_Simple;
use WP_UnitTestCase;

abstract class PersonalisedCartTestCase extends WP_UnitTestCase {

	protected const SITE = '7c9e6679-7425-40de-944b-e07fc1f90ae7';

	protected const TENANT = 'tnt_live';

	protected const TOKEN = 'eyJhdWQiOiJwcm9vZiJ9.c2lnbmF0dXJl';

	protected const PREVIOUS_VERSION = 1;

	protected HttpRecorder $recorder;

	protected VariantsAnswer $variants;

	protected Plugin $plugin;

	protected int $product_id;

	public function set_up(): void {
		parent::set_up();
		$this->plugin   = Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' );
		$this->recorder = ( new HttpRecorder( Hosts::server_api() ) )->start();
		$this->variants = ( new VariantsAnswer() )->start();
		$this->plugin->connection_store()->save( self::connection() );

		$product = new WC_Product_Simple();
		$product->set_name( 'Personalised tee' );
		$product->set_regular_price( '20' );
		$product->save();
		self::config()->save_to( $product );
		DesignFixture::sell_variant( $product );
		$this->product_id = $product->get_id();

		WC()->initialize_session();
		WC()->initialize_cart();
		WC()->cart->empty_cart();
		wc_clear_notices();
	}

	public function tear_down(): void {
		$this->recorder->stop();
		$this->variants->stop();
		$this->plugin->connection_store()->clear();
		WC()->cart->empty_cart();
		wc_clear_notices();
		delete_option( Notices::OPTION );
		unset( $_POST[ AddToCart::FIELD ], $_POST[ AddToCart::FIELDS_FIELD ], $_POST[ AddToCart::APPROVAL_FIELD ] );
		delete_option( Settings::OPTION );
		parent::tear_down();
	}

	protected static function connection(): StoredConnection {
		return new StoredConnection(
			self::SITE,
			home_url(),
			time(),
			self::TENANT,
			'Fixture Prints',
			StoredConnection::LIVE,
			'conn_main',
			'ik_live_fixture_main',
			'ik_id',
			'WooCommerce · example.org',
			'pk_live_fixture_main',
			'pk_id',
			array( 'http://example.org' ),
			'wh_fixture_main',
			'http://example.org/wp-json/pixonaut/v1/webhook',
			'whsec_fixture_main'
		);
	}

	protected static function config(): ProductConfig {
		return new ProductConfig(
			personalise: true,
			blank_id: DesignFixture::BLANK_ID,
			blank_name: 'Fixture tee',
			sides: array(
				new Side(
					'front',
					'Front',
					DesignFixture::PLACEMENT_ID,
					'Front',
					DesignFixture::TEMPLATE_BASE_ID,
					DesignFixture::LIVE_VERSION,
					self::PREVIOUS_VERSION,
					'Mini tee',
					array( new PrintSize( DesignFixture::WIDTH_MM, DesignFixture::HEIGHT_MM, 'Large' ) )
				),
			)
		);
	}

	protected static function cart_ref( string $token = self::TOKEN ): string {
		return Refs::cart_ref( self::SITE, Refs::line_key( array( 'front' => $token ), (string) WC()->session->get_customer_id() ) );
	}

	/**
	 * Scripts a commit and the read of the design it made.
	 *
	 * @param array<string, mixed> $design Design fields over the fixture's.
	 */
	protected function script_commit( array $design = array() ): void {
		$this->recorder
			->json(
				201,
				array(
					'designId'      => DesignFixture::ID,
					'printFloorDpi' => 150,
					'lowResolution' => array(),
				)
			)
			->json( 200, DesignFixture::design( $design + array( 'externalRef' => self::cart_ref() ) ) );
	}

	/**
	 * Posts the product page's add-to-cart form, as WooCommerce's form
	 * handler adds a simple product: validation, then the cart.
	 *
	 * @param mixed $designs The posted `pixonaut_designs` value; null posts none.
	 *
	 * @return bool Whether the product joined the cart.
	 */
	protected function post_add_to_cart( mixed $designs = array( 'front' => self::TOKEN ) ): bool {
		if ( null === $designs ) {
			unset( $_POST[ AddToCart::FIELD ] );
		} else {
			$_POST[ AddToCart::FIELD ] = $designs;
		}
		$quantity = 1;
		if ( ! apply_filters( 'woocommerce_add_to_cart_validation', true, $this->product_id, $quantity ) ) {
			return false;
		}
		return false !== WC()->cart->add_to_cart( $this->product_id, $quantity );
	}

	/**
	 * @return list<string> Error notices shown to the customer.
	 */
	protected static function errors(): array {
		return array_values( array_map( static fn ( array $notice ): string => (string) $notice['notice'], wc_get_notices( 'error' ) ) );
	}
}
