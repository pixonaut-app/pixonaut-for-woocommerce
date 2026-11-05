<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Catalogue;

use Pixonaut\WooCommerce\Admin\ConnectionScreen;
use Pixonaut\WooCommerce\Admin\HelpLinks;
use Pixonaut\WooCommerce\Admin\LinkedBlank;
use Pixonaut\WooCommerce\Admin\ProductPanel;
use Pixonaut\WooCommerce\Tests\Integration\Storefront\StorefrontTest;

/**
 * The product's Pixonaut tab says how to go on while the store is not connected.
 */
final class ProductPanelConnectTest extends ProductPanelTestCase {

	public function tear_down(): void {
		$this->plugin->connection_store()->clear();
		unset( $GLOBALS['product_object'] );
		parent::tear_down();
	}

	private function rendered(): string {
		$panel                     = new ProductPanel( $this->linker, new LinkedBlank( $this->catalogue, $this->cache ), $this->plugin->notices(), $this->plugin->templates(), $this->plugin->settings(), $this->plugin->file(), $this->variant_field(), null, null, $this->plugin->proof_urls(), $this->plugin->connection() );
		$GLOBALS['product_object'] = $this->product();
		$this->recorder->json( 200, self::printshops() );
		ob_start();
		$panel->render();
		return (string) ob_get_clean();
	}

	public function test_a_disconnected_store_links_to_the_connection_settings_and_the_guide(): void {
		$this->plugin->connection_store()->clear();

		$html = $this->rendered();

		$this->assertStringContainsString( 'pixonaut-not-connected', $html );
		$this->assertStringContainsString( esc_url( ConnectionScreen::settings_url() ), $html );
		$this->assertStringContainsString( HelpLinks::docs( HelpLinks::LINKING_PRODUCTS ), $html );
	}

	public function test_a_connected_store_shows_no_next_step(): void {
		StorefrontTest::connect( $this->plugin->connection_store() );

		$this->assertStringNotContainsString( 'pixonaut-not-connected', $this->rendered() );
	}
}
