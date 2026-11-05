<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Automattic\WooCommerce\Utilities\OrderUtil;
use Pixonaut\WooCommerce\Api\Deprecations;
use Pixonaut\WooCommerce\Catalogue\PictureGallery;
use Pixonaut\WooCommerce\Catalogue\PictureImage;
use Pixonaut\WooCommerce\Catalogue\PictureRunner;
use Pixonaut\WooCommerce\Catalogue\ProductPictures;
use Pixonaut\WooCommerce\Catalogue\ShowcaseImages;
use Pixonaut\WooCommerce\Connection\WorkspaceSettings;
use Pixonaut\WooCommerce\Migrations\CreateWebhookEventsTable;
use Pixonaut\WooCommerce\Migrations\MigrationRunner;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Proofs\ProofCache;
use Pixonaut\WooCommerce\Proofs\ProofUrls;
use Pixonaut\WooCommerce\Requirements;
use Pixonaut\WooCommerce\Schema;
use Pixonaut\WooCommerce\Storefront\DesignerLinkWithdrawals;
use Pixonaut\WooCommerce\Storefront\KeptDesignerLinks;
use Pixonaut\WooCommerce\Storefront\UnavailableReports;
use Pixonaut\WooCommerce\Tests\Support\DesignFixture;
use Pixonaut\WooCommerce\Tests\Support\MigrationLog;
use Pixonaut\WooCommerce\Tests\Support\RecordingMigration;
use Pixonaut\WooCommerce\Webhooks\EventsPull;
use Pixonaut\WooCommerce\Webhooks\EventStore;
use RuntimeException;
use WC_Order;
use WC_Product_Simple;
use WP_UnitTestCase;

final class PluginTest extends WP_UnitTestCase {

	public function test_boots_alongside_woocommerce(): void {
		$this->assertGreaterThan( 0, did_action( 'woocommerce_loaded' ) );
		$this->assertNotFalse( has_action( 'plugins_loaded', array( Plugin::boot( $this->plugin_file() ), 'on_plugins_loaded' ) ) );
		$this->assertFalse( has_action( 'admin_notices', array( Requirements::class, 'woocommerce_notice' ) ) );
		$this->assertFalse( has_action( 'admin_notices', array( Requirements::class, 'woocommerce_version_notice' ) ) );
	}

	public function test_boots_nothing_but_a_notice_on_woocommerce_below_the_minimum(): void {
		$log    = new MigrationLog();
		$plugin = new Plugin( $this->plugin_file(), new MigrationRunner( array( new RecordingMigration( 1, $log ) ) ), '10.5.2' );

		$plugin->on_plugins_loaded();
		ob_start();
		Requirements::woocommerce_version_notice();
		$notice = (string) ob_get_clean();

		$this->assertNotFalse( has_action( 'admin_notices', array( Requirements::class, 'woocommerce_version_notice' ) ) );
		$this->assertFalse( has_action( 'admin_notices', array( Requirements::class, 'woocommerce_notice' ) ) );
		$this->assertSame( array(), $log->versions );
		$this->assertStringContainsString( 'needs WooCommerce ' . Requirements::MIN_WOOCOMMERCE . ' or newer', $notice );
		$this->assertStringContainsString( 'This site runs WooCommerce ' . WC_VERSION, $notice );
	}

	public function test_declares_hpos_and_cart_checkout_blocks_compatibility(): void {
		$plugin = plugin_basename( $this->plugin_file() );

		foreach ( Plugin::COMPATIBLE_FEATURES as $feature ) {
			$compatible = FeaturesUtil::get_compatible_plugins_for_feature( $feature );
			$this->assertContains( $plugin, $compatible['compatible'], $feature );
		}
	}

	public function test_orders_use_the_configured_order_store(): void {
		$hpos = '1' === getenv( 'PIXONAUT_WC_HPOS' );

		$this->assertSame( $hpos, OrderUtil::custom_orders_table_usage_is_enabled() );

		$order = new WC_Order();
		$order->save();
		// With HPOS the posts table only holds a placeholder for the order.
		$this->assertSame( $hpos ? 'shop_order_placehold' : 'shop_order', get_post_type( $order->get_id() ) );
	}

	public function test_migration_runner_records_the_version_in_the_options_table(): void {
		// The plugin's own migrations ran when it loaded.
		delete_option( MigrationRunner::VERSION_OPTION );
		$log    = new MigrationLog();
		$runner = new MigrationRunner( array( new RecordingMigration( 1, $log ) ) );

		$this->assertSame( 1, $runner->run() );
		$this->assertSame( 0, $runner->run() );
		$this->assertSame( array( 1 ), $log->versions );
		$this->assertSame( 1, (int) get_option( MigrationRunner::VERSION_OPTION ) );
	}

	public function test_the_webhook_events_migration_fails_when_the_table_is_not_created(): void {
		global $wpdb;
		$prefix = $wpdb->prefix;
		// A prefix no table has, and a dbDelta that creates nothing.
		$wpdb->prefix = $prefix . 'missing_';
		add_filter( 'dbdelta_create_queries', '__return_empty_array' );

		try {
			( new CreateWebhookEventsTable() )->up();
			$this->fail( 'Expected the migration to throw.' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( EventStore::table(), $e->getMessage() );
		} finally {
			$wpdb->prefix = $prefix;
		}
	}

	public function test_uninstall_removes_the_plugin_options(): void {
		update_option( MigrationRunner::VERSION_OPTION, 3 );
		update_option( MigrationRunner::LOCK_OPTION, time() );
		update_option( Deprecations::OPTION, array( 'GET /v1/connect' => array() ) );
		$cursor = array(
			'after'      => 'evt_1',
			'connection' => 'sc_0b1c2d3e-4f50-4a6b-8c7d-000000000004',
		);
		update_option( EventsPull::CURSOR_OPTION, $cursor );
		update_option( EventsPull::BASELINE_OPTION, $cursor );
		$throttle = UnavailableReports::throttle_option( 1, 'front', UnavailableReports::WASM_BOOT );
		add_option( $throttle, (string) time(), '', false );
		add_option( PictureRunner::SLOT_OPTION_PREFIX . 0, (string) time(), '', false );
		$proofs = new ProofCache();
		$proofs->put( DesignFixture::ID, DesignFixture::PROOF_ID, ProofUrls::LARGEST_WIDTH, "\xFF\xD8\xFFjpeg" );
		$proofs->lock( DesignFixture::ID, DesignFixture::PROOF_ID, ProofUrls::LARGEST_WIDTH );
		$product = ( new WC_Product_Simple() )->save();
		update_post_meta( $product, KeptDesignerLinks::META, array( 'en' => array( 'id' => 'shl_1' ) ) );
		$picture = self::factory()->attachment->create( array( 'post_mime_type' => 'image/png' ) );
		$this->assertIsInt( $picture );
		update_post_meta( $picture, ProductPictures::PICTURE_OF_META, $product );
		update_post_meta( $product, ProductPictures::META_KEY, '{}' );
		update_post_meta( $product, Schema::PRODUCT_KEEPS_IMAGE_META, 'yes' );
		$own_image = self::factory()->attachment->create( array( 'post_mime_type' => 'image/png' ) );
		$this->assertIsInt( $own_image );
		set_post_thumbnail( $product, $picture );
		update_post_meta( $product, PictureImage::PREVIOUS_META, $own_image );
		$gallery_picture = self::factory()->attachment->create( array( 'post_mime_type' => 'image/png' ) );
		$this->assertIsInt( $gallery_picture );
		update_post_meta( $gallery_picture, ProductPictures::PICTURE_OF_META, $product );
		update_post_meta( $product, PictureGallery::GALLERY_META, $gallery_picture . ',' . $own_image );
		update_post_meta( $product, ShowcaseImages::META_KEY, '[{"id":"showcase-anna","name":"Anna","kind":"mockup"}]' );
		$trashed         = ( new WC_Product_Simple() )->save();
		$trashed_picture = self::factory()->attachment->create( array( 'post_mime_type' => 'image/png' ) );
		$this->assertIsInt( $trashed_picture );
		update_post_meta( $trashed_picture, ProductPictures::PICTURE_OF_META, $trashed );
		update_post_meta( $trashed, PictureImage::THUMBNAIL_META, $trashed_picture );
		update_post_meta( $trashed, PictureImage::PREVIOUS_META, $own_image );
		wp_trash_post( $trashed );
		update_option( DesignerLinkWithdrawals::WITHDRAWALS_OPTION, array( 'shl_2' ), false );
		update_option( WorkspaceSettings::OPTION, array( 'accent' => '#1a73e8' ), false );
		$this->assertTrue( Plugin::boot( $this->plugin_file() )->designer_links()->lock( $product ) );
		$member = self::factory()->user->create();
		$this->assertIsInt( $member );
		update_user_option( $member, Schema::MEMBER_LINK_META, 'sealed' );
		update_user_option( $member, Schema::MEMBER_LINK_REVOKED_META, time() );
		update_user_meta( $member, Schema::MEMBER_LINK_ATTEMPT_META, array( 'state_hash' => 'x' ) );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', plugin_basename( $this->plugin_file() ) );
		}
		$queries = array();
		add_filter(
			'query',
			static function ( string $query ) use ( &$queries ): string {
				$queries[] = $query;
				return $query;
			}
		);
		require dirname( __DIR__, 3 ) . '/uninstall.php';

		// The test suite turns DROP TABLE into DROP TEMPORARY TABLE, so the drop itself is not observable.
		$this->assertNotEmpty( preg_grep( '/^DROP (TEMPORARY )?TABLE IF EXISTS ' . preg_quote( EventStore::table(), '/' ) . '$/', $queries ) );
		$this->assertFalse( get_user_option( Schema::MEMBER_LINK_META, $member ) );
		$this->assertFalse( get_user_option( Schema::MEMBER_LINK_REVOKED_META, $member ) );
		$this->assertSame( '', get_user_meta( $member, Schema::MEMBER_LINK_ATTEMPT_META, true ) );
		$this->assertFalse( get_option( MigrationRunner::VERSION_OPTION ) );
		$this->assertFalse( get_option( MigrationRunner::LOCK_OPTION ) );
		$this->assertFalse( get_option( Deprecations::OPTION ) );
		$this->assertFalse( get_option( EventsPull::CURSOR_OPTION ) );
		$this->assertFalse( get_option( EventsPull::BASELINE_OPTION ) );
		wp_cache_delete( $throttle, 'options' );
		$this->assertFalse( get_option( $throttle ) );
		wp_cache_delete( PictureRunner::SLOT_OPTION_PREFIX . 0, 'options' );
		$this->assertFalse( get_option( PictureRunner::SLOT_OPTION_PREFIX . 0 ) );
		wp_cache_flush();
		$this->assertNull( $proofs->get( DesignFixture::ID, DesignFixture::PROOF_ID, ProofUrls::LARGEST_WIDTH ) );
		$this->assertTrue( $proofs->lock( DesignFixture::ID, DesignFixture::PROOF_ID, ProofUrls::LARGEST_WIDTH ) );
		$this->assertSame( '', get_post_meta( $product, KeptDesignerLinks::META, true ) );
		$this->assertNull( get_post( $picture ) );
		$this->assertNotNull( get_post( $own_image ) );
		$this->assertSame( '', get_post_meta( $product, ProductPictures::META_KEY, true ) );
		$this->assertSame( '', get_post_meta( $product, Schema::PRODUCT_KEEPS_IMAGE_META, true ) );
		$this->assertSame( $own_image, get_post_thumbnail_id( $product ) );
		$this->assertSame( (string) $own_image, get_post_meta( $trashed, PictureImage::THUMBNAIL_META, true ) );
		$this->assertSame( '', get_post_meta( $product, PictureImage::PREVIOUS_META, true ) );
		$this->assertNull( get_post( $gallery_picture ) );
		$this->assertSame( (string) $own_image, get_post_meta( $product, PictureGallery::GALLERY_META, true ) );
		$this->assertSame( '', get_post_meta( $product, ShowcaseImages::META_KEY, true ) );
		$this->assertFalse( get_option( DesignerLinkWithdrawals::WITHDRAWALS_OPTION ) );
		$this->assertFalse( get_option( WorkspaceSettings::OPTION ) );
		$this->assertTrue( Plugin::boot( $this->plugin_file() )->designer_links()->lock( $product ) );
	}

	public function test_outbound_requests_to_real_hosts_are_refused(): void {
		$response = wp_remote_get( 'https://api.pixonaut.app/v1/connect' );

		$this->assertWPError( $response );
		$this->assertSame( 'pixonaut_test_host_blocked', $response->get_error_code() );
	}

	private function plugin_file(): string {
		return dirname( __DIR__, 3 ) . '/pixonaut-for-woocommerce.php';
	}
}
