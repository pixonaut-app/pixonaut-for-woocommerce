<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Catalogue;

use Pixonaut\WooCommerce\Api\Hosts;
use Pixonaut\WooCommerce\Catalogue\PrintSize;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ProductPictures;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Tests\Support\ActionSchedulerRunner;
use Pixonaut\WooCommerce\Tests\Support\DesignFixture;
use Pixonaut\WooCommerce\Tests\Support\HttpRecorder;
use Pixonaut\WooCommerce\Tests\Support\PngFixture;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;

/**
 * Personalised products with an image of their own, Pixonaut answering for
 * their pictures, for a test case of product pictures.
 */
trait PicturedProducts {

	private HttpRecorder $recorder;

	private Plugin $plugin;

	private int $own_image;

	private function set_up_pictures(): void {
		$this->plugin   = Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' );
		$this->recorder = ( new HttpRecorder( Hosts::server_api() ) )->start();
		$this->plugin->connection_store()->save( self::connection() );
		$this->plugin->catalogue_cache()->flush();
		$this->own_image = $this->image();
	}

	private function tear_down_pictures(): void {
		$this->recorder->stop();
		$this->plugin->connection_store()->clear();
		delete_option( Settings::OPTION );
	}

	/**
	 * A new image in the media library.
	 */
	private function image(): int {
		$image = self::factory()->attachment->create( array( 'post_mime_type' => 'image/jpeg' ) );
		$this->assertIsInt( $image );
		return $image;
	}

	/**
	 * Draws the product's picture.
	 *
	 * @param WC_Product $product A personalised product, its drawing queued.
	 * @return array{product: WC_Product, id: int} The product, and its picture.
	 */
	private function drawn( WC_Product $product ): array {
		$this->recorder->raw( 200, self::png(), 'image/png' );
		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );
		$id = (int) $this->reload( $product )->get_image_id();
		$this->assertNotSame( $this->own_image, $id );
		$this->assertSame( $id, get_post_thumbnail_id( $product->get_id() ) );
		return array(
			'product' => $product,
			'id'      => $id,
		);
	}

	/**
	 * A product with its own image, configured, and selling the fixture's variant.
	 *
	 * @param ProductConfig $config       Its configuration.
	 * @param bool          $with_variant Whether it sells a blank variant.
	 */
	private function product( ProductConfig $config, bool $with_variant = true ): WC_Product {
		$product = new WC_Product_Simple();
		$product->set_name( 'Personalised tee' );
		$product->set_image_id( $this->own_image );
		$product->save();
		$config->save_to( $product );
		if ( $with_variant && null !== $config->blank_id ) {
			DesignFixture::sell_variant( $product );
		}
		return $this->reload( $product );
	}

	/**
	 * A configured sign in `sign_sizes()` of the same template, each a
	 * variation: their pictures are drawn from the same source.
	 */
	private function sign(): WC_Product {
		$product   = new WC_Product_Variable();
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'Size' );
		$attribute->set_options( self::sign_sizes() );
		$attribute->set_variation( true );
		$product->set_name( 'Sign' );
		$product->set_attributes( array( $attribute ) );
		$product->save();
		self::config( null )->save_to( $product );
		foreach ( self::sign_sizes() as $size ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $product->get_id() );
			$variation->set_attributes( array( 'size' => $size ) );
			$variation->set_regular_price( '20' );
			$variation->save();
		}
		return $this->reload( $product );
	}

	/**
	 * The sizes `sign()` comes in.
	 *
	 * @return list<string>
	 */
	private static function sign_sizes(): array {
		return array( 'Small', 'Medium', 'Large' );
	}

	private function reload( WC_Product $product ): WC_Product {
		$read = wc_get_product( $product->get_id() );
		$this->assertInstanceOf( WC_Product::class, $read );
		return $read;
	}

	/**
	 * An 8×8 white PNG.
	 */
	private static function png(): string {
		return PngFixture::white();
	}

	private static function config( ?string $blank_id = DesignFixture::BLANK_ID, int $version = DesignFixture::LIVE_VERSION ): ProductConfig {
		return new ProductConfig(
			personalise: true,
			blank_id: $blank_id,
			sides: array(
				new Side(
					'front',
					'Front',
					null === $blank_id ? null : DesignFixture::PLACEMENT_ID,
					null,
					DesignFixture::TEMPLATE_BASE_ID,
					$version,
					null,
					'Mini tee',
					null === $blank_id ? array( new PrintSize( DesignFixture::WIDTH_MM, DesignFixture::HEIGHT_MM, 'Large' ) ) : array()
				),
			)
		);
	}

	private static function publishable_key(): string {
		return 'pk_live_fixture_main';
	}

	private static function connection(): StoredConnection {
		return new StoredConnection(
			'7c9e6679-7425-40de-944b-e07fc1f90ae7',
			home_url(),
			time(),
			'tnt_live',
			'Fixture Prints',
			StoredConnection::LIVE,
			'conn_main',
			'ik_live_fixture_main',
			'ik_id',
			'WooCommerce · example.org',
			self::publishable_key(),
			'pk_id',
			array( 'http://example.org' ),
			'wh_fixture_main',
			'http://example.org/wp-json/pixonaut/v1/webhook',
			'whsec_fixture_main'
		);
	}
}
