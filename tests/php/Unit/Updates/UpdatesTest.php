<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Updates;

use Brain\Monkey\Functions;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Tests\Unit\FakeOptions;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;
use Pixonaut\WooCommerce\Updates\UpdateChannel;
use Pixonaut\WooCommerce\Updates\Updates;

final class UpdatesTest extends TestCase {

	use FakeOptions;

	private const FILE = '/var/www/html/wp-content/plugins/pixonaut-for-woocommerce/pixonaut-for-woocommerce.php';

	protected function setUp(): void {
		parent::setUp();
		$this->fake_options();
		Functions\stubTranslationFunctions();
	}

	public function test_wordpress_org_build_has_no_channel_and_no_checker(): void {
		Functions\expect( 'get_file_data' )->never();
		$settings = new Settings();
		$updates  = new Updates( $settings, self::FILE, 'github_pat_test', false );

		$updates->register();

		$this->assertArrayNotHasKey( UpdateChannel::SETTING, $settings->all() );
		$this->assertNull( $updates->updater() );
	}

	public function test_channel_defaults_to_stable(): void {
		$settings = new Settings();
		$settings->register( UpdateChannel::setting() );
		$updates = new Updates( $settings, self::FILE, null, true );

		$this->assertSame( UpdateChannel::STABLE, $updates->channel() );

		$settings->set( UpdateChannel::SETTING, UpdateChannel::BETA );
		$this->assertSame( UpdateChannel::BETA, $updates->channel() );
	}

	public function test_token_is_read_from_its_constant(): void {
		$this->assertNull( Updates::configured_token() );
	}
}
