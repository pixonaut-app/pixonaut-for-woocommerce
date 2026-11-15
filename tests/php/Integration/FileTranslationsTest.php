<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration;

use Pixonaut\WooCommerce\DesignerLanguage;
use Pixonaut\WooCommerce\FileTranslations;
use Pixonaut\WooCommerce\Plugin;
use WP_Textdomain_Registry;
use WP_UnitTestCase;

/**
 * A translation is read from the `.l10n.php` file WordPress prefers, with
 * or without a `.mo` file beside it, so a build that ships only the former
 * still prints the plugin's words in a language that is not the request's.
 */
final class FileTranslationsTest extends WP_UnitTestCase {

	private const LOCALE = 'sv_SE';

	/**
	 * A `.l10n.php` file as WordPress compiles one: a plain string, one
	 * with a context and one with plural forms.
	 */
	private const PHP_FILE = <<<'PHP'
<?php
return array(
	'language'     => 'sv_SE',
	'plural-forms' => 'nplurals=2; plural=(n != 1);',
	'messages'     => array(
		'Your design'                  => 'Din design',
		"button\4Edit"                 => 'Ändra',
		'%d side'                      => "%d sida\0%d sidor",
	),
);
PHP;

	/**
	 * Where the test keeps its translation files.
	 *
	 * @var string
	 */
	private string $dir;

	public function set_up(): void {
		parent::set_up();
		$this->dir = trailingslashit( get_temp_dir() ) . 'pixonaut-wc-languages-' . wp_generate_password( 8, false ) . '/';
		wp_mkdir_p( $this->dir );
	}

	public function tear_down(): void {
		foreach ( (array) glob( $this->dir . '*' ) as $file ) {
			wp_delete_file( (string) $file );
		}
		rmdir( $this->dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- a directory the test made.
		parent::tear_down();
	}

	public function test_it_reads_the_php_file_beside_a_mo_file_that_is_not_there(): void {
		$translations = FileTranslations::beside( $this->php_file_written() );

		$this->assertInstanceOf( FileTranslations::class, $translations );
		$this->assertSame( 'Din design', $translations->translate( 'Your design' ) );
		$this->assertSame( 'Ändra', $translations->translate( 'Edit', 'button' ) );
		$this->assertSame( 'Edit', $translations->translate( 'Edit' ), 'without its context it is another string' );
		$this->assertSame( '%d sida', $translations->translate_plural( '%d side', '%d sides', 1 ) );
		$this->assertSame( '%d sidor', $translations->translate_plural( '%d side', '%d sides', 2 ) );
	}

	public function test_a_string_the_file_does_not_have_is_answered_as_written(): void {
		$translations = FileTranslations::beside( $this->php_file_written() );

		$this->assertInstanceOf( FileTranslations::class, $translations );
		$this->assertSame( 'Add to cart', $translations->translate( 'Add to cart' ) );
		$this->assertSame( '%d proof', $translations->translate_plural( '%d proof', '%d proofs', 1 ) );
		$this->assertSame( '%d proofs', $translations->translate_plural( '%d proof', '%d proofs', 2 ) );
	}

	public function test_there_are_none_without_a_file_or_with_one_that_cannot_be_read(): void {
		$this->assertNull( FileTranslations::beside( $this->mo_file() ) );
		$this->assertNull( FileTranslations::beside( $this->dir . 'not-a-translation.txt' ) );

		file_put_contents( $this->mo_file(), 'not a .mo file' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a fixture in the test's own directory.

		$this->assertNull( FileTranslations::beside( $this->mo_file() ) );
	}

	public function test_the_plugins_words_are_printed_in_another_language_from_the_php_file_alone(): void {
		global $wp_textdomain_registry;
		$this->assertInstanceOf( WP_Textdomain_Registry::class, $wp_textdomain_registry );
		$this->php_file_written();
		$registry               = $wp_textdomain_registry;
		$wp_textdomain_registry = new WP_Textdomain_Registry(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restored below.
		$wp_textdomain_registry->set_custom_path( Plugin::TEXT_DOMAIN, $this->dir );
		try {
			$printed = DesignerLanguage::words_in( DesignerLanguage::SWEDISH, static fn (): string => __( 'Your design', 'pixonaut-for-woocommerce' ) ); // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment -- the fixture file's string.
		} finally {
			$wp_textdomain_registry = $registry; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the request's own.
		}

		$this->assertSame( 'Din design', $printed );
		$this->assertSame( 'Your design', __( 'Your design', 'pixonaut-for-woocommerce' ), 'the request keeps its own language' ); // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment -- the fixture file's string.
	}

	private function mo_file(): string {
		return $this->dir . Plugin::TEXT_DOMAIN . '-' . self::LOCALE . '.mo';
	}

	/**
	 * Writes the `.l10n.php` file and answers the path of the `.mo` file
	 * it stands in for.
	 */
	private function php_file_written(): string {
		file_put_contents( $this->dir . Plugin::TEXT_DOMAIN . '-' . self::LOCALE . '.l10n.php', self::PHP_FILE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a fixture in the test's own directory.
		return $this->mo_file();
	}
}
