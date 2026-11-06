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

	private const PREFIX = 'Pixonaut\\WooCommerce\\Vendor\\';

	/**
	 * A class or interface of each package bundled with the SDK, unprefixed.
	 */
	private const PREFIXED = array(
		'Pixonaut\\Sdk\\Pixonaut',
		'Nyholm\\Psr7\\Factory\\Psr17Factory',
		'Psr\\Http\\Client\\ClientInterface',
		'Psr\\Http\\Message\\RequestFactoryInterface',
		'Psr\\Http\\Message\\RequestInterface',
		'Psr\\Log\\LoggerInterface',
	);

	public function test_update_checker_is_prefixed(): void {
		$this->assertTrue( class_exists( 'Pixonaut\\WooCommerce\\Vendor\\YahnisElsts\\PluginUpdateChecker\\v5p7\\PucFactory' ) );
	}

	public function test_the_pixonaut_sdk_and_its_psr_dependencies_are_prefixed(): void {
		foreach ( self::PREFIXED as $name ) {
			$this->assertTrue( class_exists( self::PREFIX . $name ) || interface_exists( self::PREFIX . $name ), "{$name} is not prefixed." );
		}
		$this->assertSame( self::PREFIX . 'Pixonaut\\Sdk\\Pixonaut', ( new \ReflectionClass( self::PREFIX . 'Pixonaut\\Sdk\\Pixonaut' ) )->getName() );
	}

	public function test_the_sdk_names_only_prefixed_classes(): void {
		$sdk = (string) file_get_contents( dirname( __DIR__, 3 ) . '/vendor/pixonaut/pixonaut-php/src/Pixonaut.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.

		$this->assertStringContainsString( 'namespace Pixonaut\\WooCommerce\\Vendor\\Pixonaut\\Sdk;', $sdk );
		$this->assertStringContainsString( 'use Pixonaut\\WooCommerce\\Vendor\\Psr\\Http\\Client\\ClientInterface;', $sdk );
		$this->assertStringNotContainsString( "\nuse Psr\\", $sdk );
	}

	public function test_composer_installed_versions_is_left_unprefixed(): void {
		$this->assertNotNull( InstalledVersions::getVersion( self::UPDATE_CHECKER ) );
		$this->assertFalse( class_exists( 'Pixonaut\\WooCommerce\\Vendor\\Composer\\InstalledVersions', false ) );
	}
}
