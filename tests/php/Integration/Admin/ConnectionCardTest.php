<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Admin;

use Pixonaut\WooCommerce\Admin\ConnectionScreen;
use Pixonaut\WooCommerce\Admin\HelpLinks;
use Pixonaut\WooCommerce\Plugin;
use WP_UnitTestCase;

final class ConnectionCardTest extends WP_UnitTestCase {

	private Plugin $plugin;

	public function set_up(): void {
		parent::set_up();
		$this->plugin = Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' );
	}

	public function test_a_connected_store_is_offered_disconnect_and_the_studio_only(): void {
		$html = $this->card( 'connected' );

		$this->assertStringContainsString( 'Disconnect', $html );
		$this->assertStringContainsString( 'Open the Pixonaut studio', $html );
		$this->assertStringNotContainsString( 'class="pixonaut-connect"', $html );
		$this->assertStringNotContainsString( 'Connect again', $html );
		$this->assertStringNotContainsString( 'or connect only to the', $html );
		$this->assertStringNotContainsString( 'Connect a staging or test site', $html );
	}

	public function test_a_store_pixonaut_cannot_reach_is_not_offered_to_connect(): void {
		$html = $this->card( 'unreachable' );

		$this->assertStringContainsString( 'Disconnect', $html );
		$this->assertStringNotContainsString( 'class="pixonaut-connect"', $html );
	}

	public function test_a_paused_store_is_offered_to_connect_again(): void {
		$html = $this->card( 'paused' );

		$this->assertStringContainsString( 'Connect again', $html );
		$this->assertStringContainsString( 'or connect only to the', $html );
		$this->assertStringContainsString( 'Disconnect', $html );
	}

	public function test_a_disconnected_store_is_offered_to_connect(): void {
		$html = $this->card( 'disconnected' );

		$this->assertStringContainsString( 'Connect to Pixonaut', $html );
		$this->assertStringContainsString( 'or connect only to the', $html );
		$this->assertStringNotContainsString( 'Disconnect', $html );
	}

	public function test_the_connect_form_offers_the_switch_off(): void {
		$html = $this->card( 'disconnected' );

		$this->assertStringContainsString( 'Allow Pixel to make changes to your store', $html );
		$this->assertStringContainsString( 'name="store_access" value="1"', $html );
		$this->assertStringNotContainsString( 'checked', $html );
	}

	public function test_a_connected_card_says_where_to_chat_with_pixel(): void {
		$html = $this->card(
			'connected',
			true,
			array(
				'on'  => false,
				'cut' => false,
				'url' => 'https://shop.example/save',
			)
		);

		$this->assertStringContainsString( 'You chat with Pixel in the Pixonaut studio.', $html );
	}

	public function test_a_connected_card_shows_the_switch_as_it_is(): void {
		$off = $this->card(
			'connected',
			true,
			array(
				'on'  => false,
				'cut' => false,
				'url' => 'http://example.org/switch',
			)
		);
		$this->assertStringContainsString( 'aria-checked="false"', $off );
		$this->assertStringContainsString( 'value="1"', $off );

		$on = $this->card(
			'connected',
			true,
			array(
				'on'  => true,
				'cut' => false,
				'url' => 'http://example.org/switch',
			)
		);
		$this->assertStringContainsString( 'aria-checked="true"', $on );
		$this->assertStringContainsString( 'name="store_access" value="0"', $on );

		$cut = $this->card(
			'connected',
			true,
			array(
				'on'  => false,
				'cut' => true,
				'url' => 'http://example.org/switch',
			)
		);
		$this->assertStringContainsString( 'Pixonaut cannot turn it back on', $cut );
	}

	public function test_a_card_with_no_way_to_change_it_has_no_switch_row(): void {
		$this->assertStringNotContainsString( 'pixonaut-store-access', $this->card( 'connected' ) );
	}

	public function test_turning_it_off_that_could_not_be_saved_is_shown_as_an_error(): void {
		$html = $this->card( 'connected', result: ConnectionScreen::STORE_ACCESS_NOT_SAVED );

		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'The store could not save the change', $html );
	}

	public function test_every_state_links_to_the_guide_on_connecting(): void {
		foreach ( array( 'disconnected', 'connected', 'paused', 'unreachable' ) as $state ) {
			$this->assertStringContainsString( HelpLinks::docs( HelpLinks::CONNECT ), $this->card( $state ), $state );
		}
	}

	public function test_a_connected_store_on_another_admin_origin_is_not_told_to_connect(): void {
		$this->assertStringNotContainsString( 'pixonaut-connection-blocked', $this->card( 'connected', false ) );
		$this->assertStringContainsString( 'pixonaut-connection-blocked', $this->card( 'paused', false ) );
	}

	/**
	 * The card as the Connection tab renders it.
	 *
	 * @param string                                       $state       disconnected, connected, paused or unreachable.
	 * @param bool                                         $can_connect Whether wp-admin is on the site's origin.
	 * @param array{on: bool, cut: bool, url: string}|null $store_access The switch.
	 * @param string                                       $result      The last action's result.
	 */
	private function card( string $state, bool $can_connect = true, ?array $store_access = array(
		'on'  => false,
		'cut' => false,
		'url' => '',
	), string $result = '' ): string {
		ob_start();
		$this->plugin->templates()->render(
			'admin/connection-card.php',
			array(
				'state'              => $state,
				'paused_reason'      => 'paused' === $state ? 'unauthorized' : '',
				'name'               => 'Shop key',
				'workspace'          => 'Acme',
				'environment'        => 'live',
				'site_url'           => home_url(),
				'origins'            => array(),
				'www_origin'         => '',
				'www_allowed'        => false,
				'www_url'            => admin_url( 'admin-post.php' ),
				'brand_url'          => '',
				'store_access'       => $store_access,
				'webhook'            => null,
				'webhook_reachable'  => true,
				'last_ping'          => null,
				'ping_url'           => '',
				'can_connect'        => $can_connect,
				'home_origin'        => 'http://example.org',
				'admin_origin'       => 'http://admin.example.org',
				'connect_url'        => admin_url( 'admin-post.php?action=connect' ),
				'connect_urls'       => array(
					'live'    => admin_url( 'admin-post.php?action=connect&environment=live' ),
					'sandbox' => admin_url( 'admin-post.php?action=connect&environment=sandbox' ),
				),
				'disconnect_url'     => admin_url( 'admin-post.php?action=disconnect' ),
				'studio_url'         => 'https://studio.pixonaut.example',
				'docs_url'           => HelpLinks::docs( HelpLinks::CONNECT ),
				'tunnel_docs_url'    => HelpLinks::docs( HelpLinks::CONNECT ),
				'custom_hosts'       => array(),
				'result'             => $result,
				'deprecations'       => array(),
				'workspace_settings' => null,
			)
		);
		return (string) ob_get_clean();
	}
}
