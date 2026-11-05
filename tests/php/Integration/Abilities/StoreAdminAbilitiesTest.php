<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Abilities;

use Pixonaut\WooCommerce\Abilities\Abilities;
use Pixonaut\WooCommerce\Admin\Notices;
use Pixonaut\WooCommerce\Admin\RegeneratePictures;
use Pixonaut\WooCommerce\Catalogue\CatalogueCache;
use Pixonaut\WooCommerce\Catalogue\PictureQueue;
use Pixonaut\WooCommerce\Catalogue\ProductPictures;
use Pixonaut\WooCommerce\Catalogue\ProductRecheck;
use Pixonaut\WooCommerce\Connection\Connection;
use Pixonaut\WooCommerce\Connection\ConnectionStore;
use Pixonaut\WooCommerce\Connection\WorkspaceSettings;
use Pixonaut\WooCommerce\Layout;
use Pixonaut\WooCommerce\Settings\Setting;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Storefront\Accent;
use Pixonaut\WooCommerce\Tests\Integration\Catalogue\PicturedProducts;
use Pixonaut\WooCommerce\Tests\Support\ActionSchedulerRunner;
use Pixonaut\WooCommerce\Tests\Support\ConnectionAnswers;
use WP_Ability;
use WP_Error;
use WP_UnitTestCase;

/**
 * The abilities that manage the store's own Pixonaut setup: the settings,
 * the connection's brand, `www.` origin, webhook test and copied-site
 * pause, product pictures and the catalogue's re-check, as the Abilities
 * API (and so the MCP Adapter) runs them, against a recorder that answers
 * as Pixonaut.
 */
final class StoreAdminAbilitiesTest extends WP_UnitTestCase {

	use PicturedProducts;

	public const READ_SETTINGS = Abilities::NAMESPACE . '/get-plugin-settings';

	public const UPDATE_SETTINGS = Abilities::NAMESPACE . '/update-plugin-settings';

	public const CHOOSE_BRAND = Abilities::NAMESPACE . '/choose-brand';

	public const ALLOW_WWW = Abilities::NAMESPACE . '/allow-www';

	public const PING_WEBHOOK = Abilities::NAMESPACE . '/ping-webhook';

	public const FORGET_COPIED = Abilities::NAMESPACE . '/forget-copied-connection';

	public const REGENERATE = Abilities::NAMESPACE . '/regenerate-product-pictures';

	public const RECHECK = Abilities::NAMESPACE . '/recheck-products';

	public const WRITES = array( self::UPDATE_SETTINGS, self::CHOOSE_BRAND, self::ALLOW_WWW, self::PING_WEBHOOK, self::FORGET_COPIED, self::REGENERATE, self::RECHECK );

	private const DEFAULT_BRAND = 'br_3f6c1a2e-9b4d-4e7a-8c5f-000000000001';

	private const PETS_BRAND = 'br_3f6c1a2e-9b4d-4e7a-8c5f-000000000002';

	private const PETS_ACCENT = '#2e7d32';

	public function set_up(): void {
		parent::set_up();
		$this->set_up_pictures();
		wp_set_current_user( self::user( 'shop_manager' ) );
	}

	public function tear_down(): void {
		$this->tear_down_pictures();
		delete_option( Notices::OPTION );
		delete_option( Settings::OPTION );
		delete_option( WorkspaceSettings::OPTION );
		parent::tear_down();
	}

	public function test_the_settings_are_read_by_their_keys_without_the_developer_tools(): void {
		$read = $this->succeed( self::READ_SETTINGS, array() );

		$this->assertSame( array_keys( $this->plugin->settings()->section( Setting::GENERAL ) ), array_keys( $read ) );
		$this->assertArrayNotHasKey( Settings::API_BASE, $read );
		$this->assertSame( Layout::INLINE, $read[ Settings::DEFAULT_LAYOUT ] );
		$this->assertTrue( $read[ Settings::PRODUCT_PICTURES ] );
		$schema = $this->ability( self::READ_SETTINGS )->get_output_schema();
		$this->assertSame( 'integer', $schema['properties'][ Settings::PROOF_DEADLINE_DAYS ]['type'] );
		$this->assertContains( Layout::INLINE, $schema['properties'][ Settings::DEFAULT_LAYOUT ]['enum'] );
		$this->assertStringStartsWith( 'Default layout: ', $schema['properties'][ Settings::DEFAULT_LAYOUT ]['description'] );
	}

	public function test_settings_given_change_in_one_write_and_the_others_keep_their_values(): void {
		$this->plugin->settings()->set( Settings::PROOF_REMINDERS, 2 );
		$writes = 0;
		add_action(
			'update_option_' . Settings::OPTION,
			static function () use ( &$writes ): void {
				++$writes;
			}
		);

		$answer = $this->succeed(
			self::UPDATE_SETTINGS,
			array(
				Settings::SHOW_FIELD_VALUES      => true,
				Settings::PROOF_DEADLINE_DAYS    => 5,
				Settings::DESIGNER_ACCENT        => Accent::CUSTOM,
				Settings::DESIGNER_ACCENT_COLOUR => '#1E73BE',
			)
		);

		$this->assertSame( 1, $writes );
		$this->assertTrue( $answer[ Settings::SHOW_FIELD_VALUES ] );
		$this->assertSame( 5, $answer[ Settings::PROOF_DEADLINE_DAYS ] );
		$this->assertSame( '#1e73be', $this->plugin->settings()->get( Settings::DESIGNER_ACCENT_COLOUR ) );
		$this->assertSame( 2, $answer[ Settings::PROOF_REMINDERS ] );
		// Stored as the settings page's form saves a checkbox.
		$this->assertSame( 'yes', get_option( Settings::OPTION )[ Settings::SHOW_FIELD_VALUES ] );
	}

	public function test_a_value_a_setting_does_not_take_is_refused_and_nothing_changes(): void {
		$refusals = array(
			array( Settings::DEFAULT_LAYOUT => 'sideways' ),
			array( Settings::PROOF_DEADLINE_DAYS => 0 ),
			array( Settings::API_BASE => 'https://example.test' ),
			array( Settings::DESIGNER_ACCENT => Accent::CUSTOM ),
		);
		foreach ( $refusals as $input ) {
			$answer = $this->ability( self::UPDATE_SETTINGS )->execute( array( Settings::SHOW_FIELD_VALUES => true ) + $input );
			$this->assertInstanceOf( WP_Error::class, $answer, (string) wp_json_encode( $input ) );
		}
		$this->assertFalse( get_option( Settings::OPTION ) );
	}

	public function test_the_connection_status_lists_the_workspaces_brands_and_the_last_test_request(): void {
		$this->recorder->json( 200, self::connect_answer( self::two_brands( null ) ) );

		$status = $this->succeed( Abilities::NAMESPACE . '/get-connection-status', array() );

		$this->assertTrue( $status['connected'] );
		$this->assertNull( $status['paused'] );
		$this->assertSame( array( self::DEFAULT_BRAND, self::PETS_BRAND ), array_column( $status['workspace_settings']['brands'], 'id' ) );
		$this->assertSame( (string) Connection::www_origin(), $status['www_origin'] );
		$this->assertFalse( $status['www_allowed'] );
		$this->assertNull( $status['last_ping'] );
	}

	public function test_a_brand_is_chosen_through_pixonaut_and_kept_for_the_designer(): void {
		$this->recorder->json( 200, self::connect_answer( self::two_brands( self::PETS_BRAND ) ) );

		$status = $this->succeed( self::CHOOSE_BRAND, array( 'brand_id' => self::PETS_BRAND ) );

		$this->assertSame( 'PATCH', $this->recorder->requests[0]['method'] );
		$this->assertSame( array( 'brandId' => self::PETS_BRAND ), json_decode( (string) $this->recorder->requests[0]['body'], true ) );
		$this->assertSame( self::PETS_BRAND, WorkspaceSettings::brand_id() );
		$this->assertSame( self::PETS_BRAND, $status['workspace_settings']['chosen_brand_id'] );
		$this->assertCount( 1, $this->recorder->requests, 'The answer is the one Pixonaut confirmed the brand with.' );
	}

	public function test_a_brand_not_in_the_workspace_is_refused(): void {
		$this->recorder->json( 404, array( 'error' => 'brand_not_found' ) );

		$answer = $this->ability( self::CHOOSE_BRAND )->execute( array( 'brand_id' => 'br_gone' ) );

		$this->assertInstanceOf( WP_Error::class, $answer );
		$this->assertSame( 'pixonaut_wc_ability_brand_not_found', $answer->get_error_code() );
		$this->assertCount( 1, $this->recorder->requests );
	}

	public function test_the_www_counterpart_is_allowed_and_taken_away(): void {
		$www    = (string) Connection::www_origin();
		$origin = Connection::origin( Connection::site_url() );
		$this->recorder->json( 200, self::connect_answer( array(), array( $origin, $www ) ) );

		$status = $this->succeed( self::ALLOW_WWW, array() );

		$this->assertSame( 'PATCH', $this->recorder->requests[0]['method'] );
		$this->assertSame( array( 'additionalOrigins' => array( $www ) ), json_decode( (string) $this->recorder->requests[0]['body'], true ) );
		$this->assertTrue( $status['www_allowed'] );

		$this->recorder->json( 200, self::connect_answer() );
		$this->assertFalse( $this->succeed( self::ALLOW_WWW, array( 'allow' => false ) )['www_allowed'] );
		$this->assertSame( array( 'additionalOrigins' => array() ), json_decode( (string) $this->recorder->requests[1]['body'], true ) );
		// Answered from what Pixonaut confirmed, not read again.
		$this->assertCount( 2, $this->recorder->requests );
	}

	public function test_the_webhook_is_sent_a_test_request_and_how_it_went_is_answered(): void {
		$this->recorder->json( 200, array( 'delivered' => true ) );
		$this->recorder->json( 200, self::connect_answer() );

		$status = $this->succeed( self::PING_WEBHOOK, array() );

		$this->assertSame( 'POST', $this->recorder->requests[0]['method'] );
		$this->assertStringEndsWith( '/ping', $this->recorder->requests[0]['url'] );
		$this->assertTrue( $status['last_ping']['delivered'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $status['last_ping']['at'] );
	}

	public function test_a_copied_sites_connection_is_forgotten_without_revoking_its_keys(): void {
		$refused = $this->ability( self::FORGET_COPIED )->execute( array() );
		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertNotNull( $this->plugin->connection_store()->load() );

		$this->plugin->connection_store()->pause( ConnectionStore::PAUSED_SITE_CHANGED );
		$status = $this->succeed( self::FORGET_COPIED, array() );

		$this->assertFalse( $status['connected'] );
		$this->assertNull( $this->plugin->connection_store()->load() );
		$this->assertSame( array(), $this->recorder->requests );
	}

	public function test_the_connection_abilities_are_refused_while_paused_and_ask_pixonaut_nothing(): void {
		$this->plugin->connection_store()->pause( Connection::PAUSED_UNAUTHORIZED );

		foreach ( array(
			self::CHOOSE_BRAND => array( 'brand_id' => null ),
			self::ALLOW_WWW    => array(),
			self::PING_WEBHOOK => array(),
			self::RECHECK      => array(),
		) as $name => $input ) {
			$answer = $this->ability( $name )->execute( $input );
			$this->assertInstanceOf( WP_Error::class, $answer, $name );
		}
		$this->assertSame( array(), $this->recorder->requests );
	}

	public function test_several_products_pictures_are_drawn_again_as_the_admin_action_does(): void {
		$first  = $this->drawn( $this->product( self::config() ) )['product'];
		$second = $this->drawn( $this->product( self::config() ) )['product'];

		$answer = $this->succeed( self::REGENERATE, array( 'product_ids' => array( $first->get_id(), $second->get_id() ) ) );

		$this->assertSame(
			array(
				array(
					'product_id' => $first->get_id(),
					'state'      => RegeneratePictures::DRAWING,
				),
				array(
					'product_id' => $second->get_id(),
					'state'      => RegeneratePictures::DRAWING,
				),
			),
			$answer['products']
		);
		$this->recorder->raw( 200, self::png(), 'image/png' );
		$this->recorder->raw( 200, self::png(), 'image/png' );
		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );
		$this->assertSame( RegeneratePictures::DRAWN, RegeneratePictures::state( $this->reload( $first ) ) );
		$this->assertSame( RegeneratePictures::DRAWN, RegeneratePictures::state( $this->reload( $second ) ) );
	}

	public function test_a_product_showing_no_picture_is_refused_and_nothing_is_drawn_again(): void {
		$pictured = $this->drawn( $this->product( self::config() ) )['product'];
		$plain    = new \WC_Product_Simple();
		$plain->save();

		$answer = $this->ability( self::REGENERATE )->execute( array( 'product_ids' => array( $pictured->get_id(), $plain->get_id() ) ) );

		$this->assertInstanceOf( WP_Error::class, $answer );
		$this->assertSame( 'pixonaut_wc_ability_not_pictured', $answer->get_error_code() );
		$this->assertFalse( PictureQueue::drawing( $this->reload( $pictured ) ) );
	}

	public function test_products_are_rechecked_after_the_cached_catalogue_is_cleared(): void {
		update_option( CatalogueCache::GENERATION_OPTION, 4 );

		$answer = $this->succeed( self::RECHECK, array() );

		$this->assertSame(
			array(
				'cache_cleared' => true,
				'queued'        => true,
			),
			$answer
		);
		$this->assertSame( 5, (int) get_option( CatalogueCache::GENERATION_OPTION ) );
		$this->assertNotFalse( as_next_scheduled_action( ProductRecheck::QUEUE_ACTION, null, ProductRecheck::GROUP ) );
	}

	public function test_every_write_is_refused_to_a_user_who_may_not_manage_the_store_or_edit_the_product(): void {
		$product = $this->drawn( $this->product( self::config() ) )['product'];
		$inputs  = array(
			self::UPDATE_SETTINGS => array( Settings::SHOW_FIELD_VALUES => true ),
			self::CHOOSE_BRAND    => array( 'brand_id' => self::PETS_BRAND ),
			self::ALLOW_WWW       => array(),
			self::PING_WEBHOOK    => array(),
			self::FORGET_COPIED   => array(),
			self::REGENERATE      => array( 'product_ids' => array( $product->get_id() ) ),
			self::RECHECK         => array(),
		);
		$this->assertSame( self::WRITES, array_keys( $inputs ) );
		$this->plugin->connection_store()->pause( ConnectionStore::PAUSED_SITE_CHANGED );

		foreach ( array( 'customer', 'contributor', 'author', 'editor' ) as $role ) {
			wp_set_current_user( self::user( $role ) );
			foreach ( $inputs as $name => $input ) {
				$answer = $this->ability( $name )->execute( $input );
				$this->assertInstanceOf( WP_Error::class, $answer, $name . ' as ' . $role );
				$this->assertSame( 'ability_invalid_permissions', $answer->get_error_code(), $name . ' as ' . $role );
			}
			$this->assertInstanceOf( WP_Error::class, $this->ability( self::READ_SETTINGS )->execute( array() ), $role );
		}
		$this->assertFalse( get_option( Settings::OPTION ) );
		$this->assertNotNull( $this->plugin->connection_store()->load() );
		$this->assertFalse( PictureQueue::drawing( $this->reload( $product ) ) );
		// Only the picture drawn before the users came.
		$this->assertCount( 1, $this->recorder->requests );
	}

	public function test_the_abilities_are_registered_with_their_schemas_and_marked_for_mcp(): void {
		foreach ( array( self::READ_SETTINGS, ...self::WRITES ) as $name ) {
			$ability = $this->ability( $name );
			$this->assertSame( Abilities::CATEGORY, $ability->get_category(), $name );
			$this->assertSame( 'object', $ability->get_input_schema()['type'] ?? null, $name );
			$this->assertSame( 'object', $ability->get_output_schema()['type'] ?? null, $name );
			$this->assertSame( array( 'public' => true ), $ability->get_meta_item( 'mcp' ), $name );
			$this->assertSame( self::READ_SETTINGS === $name, $ability->get_meta_item( 'annotations' )['readonly'], $name );
		}
	}

	private function ability( string $name ): WP_Ability {
		$ability = wp_get_ability( $name );
		$this->assertInstanceOf( WP_Ability::class, $ability, $name );
		return $ability;
	}

	/**
	 * Runs an ability that is to succeed.
	 *
	 * @param string       $name  The ability.
	 * @param array<mixed> $input Its input.
	 * @return array<string, mixed>
	 */
	private function succeed( string $name, array $input ): array {
		$answer = $this->ability( $name )->execute( $input );
		$this->assertIsArray( $answer, $answer instanceof WP_Error ? $answer->get_error_message() : $name );
		return $answer;
	}

	/**
	 * `GET` or `PATCH /v1/connect` about the stored connection.
	 *
	 * @param array<string, mixed> $settings The workspace settings that differ from none.
	 * @param string[]             $origins  The origins its publishable key takes.
	 * @phpstan-param list<string> $origins
	 * @return array<string, mixed>
	 */
	private static function connect_answer( array $settings = array(), array $origins = array( 'http://example.org' ) ): array {
		$connection = self::connection();
		return array(
			'allowedOrigins'    => $origins,
			'connectionId'      => $connection->connection_id,
			'environment'       => $connection->environment,
			'name'              => $connection->integration_key_label,
			'siteUrl'           => untrailingslashit( home_url() ),
			'tenantId'          => $connection->tenant_id,
			'tenantName'        => $connection->tenant_name,
			'tenantSlug'        => ConnectionAnswers::TENANT_SLUG,
			'webhookEndpointId' => $connection->webhook_id,
		) + $settings + ConnectionAnswers::WORKSPACE_SETTINGS;
	}

	/**
	 * The workspace's two brands, the store drawn in one of them.
	 *
	 * @param string|null $brand_id The brand chosen for the store; null for the default.
	 * @return array<string, mixed>
	 */
	private static function two_brands( ?string $brand_id ): array {
		$pets = self::PETS_BRAND === $brand_id;
		return array(
			'branding' => array(
				'logoUrl'     => null,
				'accentColor' => $pets ? self::PETS_ACCENT : null,
				'brandId'     => $pets ? self::PETS_BRAND : self::DEFAULT_BRAND,
			),
			'brandId'  => $brand_id,
			'brands'   => array(
				array(
					'id'          => self::DEFAULT_BRAND,
					'name'        => 'Acme',
					'isDefault'   => true,
					'logoUrl'     => null,
					'accentColor' => null,
				),
				array(
					'id'          => self::PETS_BRAND,
					'name'        => 'Acme Pets',
					'isDefault'   => false,
					'logoUrl'     => null,
					'accentColor' => self::PETS_ACCENT,
				),
			),
		);
	}

	private static function user( string $role ): int {
		$user = self::factory()->user->create( array( 'role' => $role ) );
		self::assertIsInt( $user );
		return $user;
	}
}
