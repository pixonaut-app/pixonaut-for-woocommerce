<?php
/**
 * Loaded before Strauss by bin/strauss.php (`auto_prepend_file`).
 *
 * Strauss logs through Monolog, which implements `Psr\Log\LoggerInterface`.
 * The checkout's own `psr/log` is prefixed in place, like every package the
 * plugin ships, and Strauss's alias autoloader can only stand in for the
 * unprefixed names by writing a file into the checkout's `vendor/composer/`,
 * which the release build mounts read-only. So the unprefixed interfaces are
 * read from the directory being prefixed, which Composer has just installed
 * and Strauss has not rewritten yet; once it has, the alias autoloader does.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

spl_autoload_register(
	static function ( string $class_name ): void {
		$namespace = 'Psr\\Log\\';
		if ( ! str_starts_with( $class_name, $namespace ) ) {
			return;
		}
		$file   = getcwd() . '/vendor/psr/log/src/' . str_replace( '\\', '/', substr( $class_name, strlen( $namespace ) ) ) . '.php';
		$source = is_file( $file ) ? file_get_contents( $file ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file, outside WordPress.
		if ( false !== $source && str_contains( $source, "\nnamespace Psr\\Log;" ) ) {
			require $file;
		}
	},
	true,
	true
);
