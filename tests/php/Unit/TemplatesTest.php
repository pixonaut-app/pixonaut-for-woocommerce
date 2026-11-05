<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

// phpcs:disable WordPress.WP.AlternativeFunctions -- the test writes and removes its own temporary templates.

namespace Pixonaut\WooCommerce\Tests\Unit;

use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\TemplateOverride;
use Pixonaut\WooCommerce\Templates;

final class TemplatesTest extends TestCase {

	private string $dir = '';

	protected function setUp(): void {
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/pixonaut-templates-' . uniqid();
		mkdir( $this->dir . '/admin', 0777, true );
	}

	protected function tearDown(): void {
		foreach ( array( 'admin/a.php', 'admin/b.php', 'admin/notes.txt', 'top.php' ) as $file ) {
			if ( is_file( $this->dir . '/' . $file ) ) {
				unlink( $this->dir . '/' . $file );
			}
		}
		rmdir( $this->dir . '/admin' );
		rmdir( $this->dir );
		parent::tearDown();
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function headers(): array {
		return array(
			'docblock line'        => array( "<?php\n/**\n * A template.\n *\n * @version 1.2.0\n */\n", '1.2.0' ),
			'closed on the line'   => array( "<?php\n/** @version 2.0.0 */\n", '2.0.0' ),
			'CR line endings'      => array( "<?php\r/**\r * @version 0.3.1\r */\r", '0.3.1' ),
			'no header'            => array( "<?php\n/**\n * A template.\n */\n", '' ),
			'other tag first'      => array( "<?php\n/**\n * @package Pixonaut\n * @version 1.0.0\n */\n", '1.0.0' ),
			'mention in the prose' => array( "<?php\n/**\n * Keep the @version tag.\n */\n", '' ),
		);
	}

	/**
	 * @dataProvider headers
	 *
	 * @param string $contents The file.
	 * @param string $version  Its version.
	 */
	public function test_version_reads_the_header( string $contents, string $version ): void {
		file_put_contents( $this->dir . '/admin/a.php', $contents );

		$this->assertSame( $version, Templates::version( $this->dir . '/admin/a.php' ) );
	}

	public function test_version_of_a_missing_file_is_empty(): void {
		$this->assertSame( '', Templates::version( $this->dir . '/missing.php' ) );
	}

	public function test_names_lists_php_templates_sorted(): void {
		foreach ( array( 'admin/b.php', 'top.php', 'admin/a.php', 'admin/notes.txt' ) as $file ) {
			file_put_contents( $this->dir . '/' . $file, '<?php' );
		}

		$this->assertSame( array( 'admin/a.php', 'admin/b.php', 'top.php' ), ( new Templates( $this->dir . '/' ) )->names() );
	}

	/**
	 * @return array<string, array{string, string, bool}>
	 */
	public static function versions(): array {
		return array(
			'same'      => array( '1.2.0', '1.2.0', false ),
			'older'     => array( '1.1.9', '1.2.0', true ),
			'newer'     => array( '1.3.0', '1.2.0', false ),
			'no header' => array( '', '1.2.0', true ),
		);
	}

	/**
	 * @dataProvider versions
	 *
	 * @param string $version        The theme copy's version.
	 * @param string $plugin_version The plugin's.
	 * @param bool   $outdated       Whether the copy is outdated.
	 */
	public function test_an_override_is_outdated_when_older_or_unversioned( string $version, string $plugin_version, bool $outdated ): void {
		$this->assertSame( $outdated, ( new TemplateOverride( 'admin/a.php', '/theme/a.php', $version, $plugin_version ) )->outdated() );
	}

	public function test_every_shipped_template_has_a_version_and_names_its_override_path(): void {
		$root      = dirname( __DIR__, 3 ) . '/templates/';
		$templates = new Templates( $root );

		$this->assertNotSame( array(), $templates->names() );
		foreach ( $templates->names() as $name ) {
			$this->assertNotSame( '', Templates::version( $root . $name ), "{$name} has no @version." );
			$this->assertStringContainsString( 'Override by copying to yourtheme/' . Templates::THEME_PATH . $name . '.', (string) file_get_contents( $root . $name ), "{$name} does not name its override path." );
		}
	}

	/**
	 * A template's `@version` names the release that ships its change, so it
	 * is never past the next release: a copy a theme made from a template
	 * marked with a version the plugin has not reached would never be flagged
	 * once the plugin changes the template again.
	 */
	public function test_no_shipped_template_is_versioned_past_the_next_release(): void {
		$root      = dirname( __DIR__, 3 ) . '/templates/';
		$templates = new Templates( $root );
		$ceiling   = self::next_release( Plugin::VERSION );

		foreach ( $templates->names() as $name ) {
			$version = Templates::version( $root . $name );
			$this->assertTrue(
				version_compare( $version, $ceiling, '<=' ),
				"{$name} says @version {$version}, past {$ceiling}, the highest release after " . Plugin::VERSION . '. Set it to the version of the release that ships the change.'
			);
		}
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function releases(): array {
		return array(
			'below 1.0.0, a minor' => array( '0.1.0', '0.2.0' ),
			'below 1.0.0, a patch' => array( '0.4.3', '0.5.0' ),
			'from 1.0.0, a major'  => array( '1.0.0', '2.0.0' ),
			'from 1.0.0, a minor'  => array( '2.3.1', '3.0.0' ),
		);
	}

	/**
	 * @dataProvider releases
	 *
	 * @param string $version The released version.
	 * @param string $next    The highest release that can follow it.
	 */
	public function test_the_next_release_is_at_most_a_breaking_one( string $version, string $next ): void {
		$this->assertSame( $next, self::next_release( $version ) );
	}

	/**
	 * The highest version the release after `$version` can have. release-please
	 * makes a breaking change a major release, or a minor one below 1.0.0
	 * (`bump-minor-pre-major` in release-please-config.json).
	 *
	 * @param string $version A released `major.minor.patch` version.
	 */
	private static function next_release( string $version ): string {
		[ $major, $minor ] = array_map( 'intval', explode( '.', $version ) );
		return 0 === $major ? '0.' . ( $minor + 1 ) . '.0' : ( $major + 1 ) . '.0.0';
	}
}
