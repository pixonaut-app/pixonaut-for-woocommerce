<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit;

use Composer\InstalledVersions;

/**
 * What `composer install` leaves in vendor/ once Strauss has run.
 */
final class VendorPrefixingTest extends TestCase {

	private const UPDATE_CHECKER = 'yahnis-elsts/plugin-update-checker';

	public function test_update_checker_is_prefixed(): void {
		$this->assertTrue( class_exists( 'Pixonaut\\WooCommerce\\Vendor\\YahnisElsts\\PluginUpdateChecker\\v5p7\\PucFactory' ) );
	}

	public function test_composer_installed_versions_is_left_unprefixed(): void {
		$this->assertNotNull( InstalledVersions::getVersion( self::UPDATE_CHECKER ) );
		$this->assertFalse( class_exists( 'Pixonaut\\WooCommerce\\Vendor\\Composer\\InstalledVersions', false ) );
	}
}
