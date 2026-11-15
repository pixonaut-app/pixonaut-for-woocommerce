<?php
/**
 * One translation file's strings, as the translations of a text domain.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce;

use NOOP_Translations;
use WP_Translation_File;

/**
 * The strings of one compiled translation file, a `.l10n.php` or a `.mo`,
 * read with WordPress's own reader and answered the way a text domain's
 * translations are: a string the file does not have is answered as written.
 * `DesignerLanguage` prints the plugin's words in a language that is not the
 * request's with it.
 */
final class FileTranslations extends NOOP_Translations {

	/**
	 * What WordPress puts between a string's context and the string in a file's keys.
	 */
	private const CONTEXT_SEPARATOR = "\4";

	/**
	 * What WordPress puts between the plural forms of one entry.
	 */
	private const PLURAL_SEPARATOR = "\0";

	/**
	 * What ends the name of the file WordPress prefers to a `.mo` file, beside it.
	 */
	private const PHP_SUFFIX = '.l10n.php';

	private const MO_SUFFIX = '.mo';

	/**
	 * @param WP_Translation_File $file The file read.
	 */
	private function __construct( private readonly WP_Translation_File $file ) {
	}

	/**
	 * The translations WordPress would load for a `.mo` file's path: those
	 * of the `.l10n.php` file beside it when there is one, else the `.mo`
	 * file's; null when neither can be read.
	 *
	 * @param string $mo_file A `.mo` file's path; the file itself may be missing.
	 */
	public static function beside( string $mo_file ): ?self {
		if ( ! str_ends_with( $mo_file, self::MO_SUFFIX ) ) {
			return null;
		}
		$php_file = substr( $mo_file, 0, -strlen( self::MO_SUFFIX ) ) . self::PHP_SUFFIX;
		foreach ( array( $php_file, $mo_file ) as $path ) {
			$file = is_readable( $path ) ? WP_Translation_File::create( $path ) : false;
			if ( ! $file instanceof WP_Translation_File ) {
				continue;
			}
			// A file is parsed when first read, and says only then that it cannot be.
			$file->entries();
			if ( null === $file->error() ) {
				return new self( $file );
			}
		}
		return null;
	}

	/**
	 * @param string      $singular The string as written.
	 * @param string|null $context  Its context, if it has one.
	 * @return string
	 */
	public function translate( $singular, $context = null ) {
		$forms = $this->forms( (string) $singular, $context );
		return $forms[0] ?? (string) $singular;
	}

	/**
	 * @param string      $singular The string for one, as written.
	 * @param string      $plural   The string for several, as written.
	 * @param int         $count    How many.
	 * @param string|null $context  Its context, if it has one.
	 * @return string
	 */
	public function translate_plural( $singular, $plural, $count, $context = null ) {
		$forms = $this->forms( (string) $singular, $context );
		$form  = $forms[ $this->file->get_plural_form( (int) $count ) ] ?? null;
		if ( null !== $form ) {
			return $form;
		}
		return 1 === (int) $count ? (string) $singular : (string) $plural;
	}

	/**
	 * A string's translation in each plural form the file has for it; none
	 * when the file does not have the string.
	 *
	 * @param string      $singular The string as written.
	 * @param string|null $context  Its context, if it has one.
	 * @return list<string>
	 */
	private function forms( string $singular, ?string $context ): array {
		$key         = null === $context || '' === $context ? $singular : $context . self::CONTEXT_SEPARATOR . $singular;
		$translation = $this->file->translate( $key );
		return is_string( $translation ) && '' !== $translation ? explode( self::PLURAL_SEPARATOR, $translation ) : array();
	}
}
