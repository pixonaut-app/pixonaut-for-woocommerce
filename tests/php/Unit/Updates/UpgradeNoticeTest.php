<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Updates;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Pixonaut\WooCommerce\Templates;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;
use Pixonaut\WooCommerce\Updates\UpgradeNotice;

final class UpgradeNoticeTest extends TestCase {

	private const BASENAME = 'pixonaut-for-woocommerce/pixonaut-for-woocommerce.php';

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
	}

	/**
	 * @return array<string, array{string, string, bool}>
	 */
	public static function upgrades(): array {
		return array(
			'major'                  => array( '1.4.2', '2.0.0', true ),
			'minor'                  => array( '1.4.2', '1.5.0', false ),
			'patch'                  => array( '1.4.2', '1.4.3', false ),
			'minor below 1.0.0'      => array( '0.3.1', '0.4.0', true ),
			'patch below 1.0.0'      => array( '0.3.1', '0.3.2', false ),
			'first stable'           => array( '0.9.0', '1.0.0', true ),
			'beta of the next major' => array( '1.4.2', '2.0.0-beta.1', true ),
			'major beta to release'  => array( '2.0.0-beta.3', '2.0.0', false ),
			'downgrade'              => array( '2.0.0', '1.9.0', false ),
			'not a version'          => array( '1.4.2', 'main', false ),
		);
	}

	/**
	 * @dataProvider upgrades
	 *
	 * @param string $from  The installed version.
	 * @param string $to    The version on offer.
	 * @param bool   $major Whether the upgrade is major.
	 */
	public function test_is_major( string $from, string $to, bool $major ): void {
		$this->assertSame( $major, UpgradeNotice::is_major( $from, $to ) );
	}

	public function test_hooks_the_plugins_update_row(): void {
		$notice = new UpgradeNotice( self::BASENAME, '1.4.2', new Templates( '/templates/' ) );
		Actions\expectAdded( 'in_plugin_update_message-' . self::BASENAME )->once()->with( array( $notice, 'render' ), 10, 2 );

		$notice->register();
	}

	public function test_renders_a_major_update_with_its_upgrade_notice(): void {
		Functions\expect( 'wc_get_template' )->once()->with(
			'admin/upgrade-notice.php',
			array(
				'message'        => 'Version 2.0.0 is a major update and may hold breaking changes. Read its changelog and back up your site before you update.',
				'upgrade_notice' => 'Templates moved.',
			),
			Templates::THEME_PATH,
			'/templates/'
		);

		$this->notice()->render(
			array(),
			(object) array(
				'new_version'    => '2.0.0',
				'upgrade_notice' => 'Templates moved.',
			)
		);
	}

	public function test_minor_update_gets_no_notice(): void {
		Functions\expect( 'wc_get_template' )->never();

		$this->notice()->render( array(), (object) array( 'new_version' => '1.5.0' ) );
	}

	public function test_filter_changes_or_hides_the_notice(): void {
		Filters\expectApplied( 'pixonaut_wc_updates_upgrade_notice' )->once()->with( \Mockery::type( 'string' ), '2.0.0', '1.4.2' )->andReturn( '' );
		Functions\expect( 'wc_get_template' )->never();

		$this->notice()->render( array(), (object) array( 'new_version' => '2.0.0' ) );
	}

	private function notice(): UpgradeNotice {
		return new UpgradeNotice( self::BASENAME, '1.4.2', new Templates( '/templates/' ) );
	}
}
