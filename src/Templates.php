<?php
/**
 * Template loader.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce;

/**
 * Renders the plugin's markup through WooCommerce's template system, so a theme
 * overrides any template by copying it to `woocommerce/pixonaut/<name>`. Every
 * template starts with an `@version` header.
 */
final class Templates {

	/**
	 * Where a theme keeps its overrides, relative to the theme directory.
	 */
	public const THEME_PATH = 'woocommerce/pixonaut/';

	/**
	 * How much of a template is read for its `@version` header, as WooCommerce
	 * does for its own.
	 */
	private const HEADER_BYTES = 8192;

	/**
	 * @param string $default_path Directory of the plugin's own templates, with a trailing slash.
	 */
	public function __construct( private readonly string $default_path ) {
	}

	/**
	 * @param string $plugin_file Absolute path of the main plugin file.
	 */
	public static function for_plugin( string $plugin_file ): self {
		return new self( dirname( $plugin_file ) . '/templates/' );
	}

	/**
	 * Prints a template.
	 *
	 * @param string               $name Template path below `templates/`, e.g. `admin/notice.php`.
	 * @param array<string, mixed> $args Variables the template receives.
	 */
	public function render( string $name, array $args = array() ): void {
		wc_get_template( $name, $args, self::THEME_PATH, $this->default_path );
	}

	/**
	 * A template's output as a string.
	 *
	 * @param string               $name Template path below `templates/`.
	 * @param array<string, mixed> $args Variables the template receives.
	 */
	public function html( string $name, array $args = array() ): string {
		return wc_get_template_html( $name, $args, self::THEME_PATH, $this->default_path );
	}

	/**
	 * Directory of the plugin's own templates, with a trailing slash.
	 */
	public function default_path(): string {
		return $this->default_path;
	}

	/**
	 * The file a template resolves to: the theme's override, else the plugin's.
	 *
	 * @param string $name Template path below `templates/`.
	 */
	public function locate( string $name ): string {
		return wc_locate_template( $name, self::THEME_PATH, $this->default_path );
	}

	/**
	 * Every template the plugin ships, as paths below `templates/`, sorted.
	 *
	 * @return list<string>
	 */
	public function names(): array {
		$names = array();
		if ( ! is_dir( $this->default_path ) ) {
			return $names;
		}
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->default_path, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			if ( $file instanceof \SplFileInfo && 'php' === $file->getExtension() ) {
				$names[] = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $this->default_path ) ) );
			}
		}
		sort( $names );
		return $names;
	}

	/**
	 * The templates the active theme overrides, in the order of `names()`.
	 *
	 * @return list<TemplateOverride>
	 */
	public function overrides(): array {
		$overrides = array();
		foreach ( $this->names() as $name ) {
			$plugin_file = $this->default_path . $name;
			$located     = $this->locate( $name );
			if ( $located === $plugin_file || ! is_file( $located ) ) {
				continue;
			}
			$overrides[] = new TemplateOverride( $name, $located, self::version( $located ), self::version( $plugin_file ) );
		}
		return $overrides;
	}

	/**
	 * A template file's `@version` header, read the way WooCommerce reads its
	 * own; empty when the file has none.
	 *
	 * @param string $file Absolute path of the template.
	 */
	public static function version( string $file ): string {
		$data = is_readable( $file ) ? file_get_contents( $file, false, null, 0, self::HEADER_BYTES ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file.
		if ( false === $data || ! preg_match( '/^[ \t\/*#@]*@version(.*)$/mi', str_replace( "\r", "\n", $data ), $match ) ) {
			return '';
		}
		return trim( (string) preg_replace( '/\s*(?:\*\/|\?>).*/', '', $match[1] ) );
	}
}
