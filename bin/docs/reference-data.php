<?php
/**
 * Run by bin/docs-generate.mjs in wp-env. Prints, as JSON, the data the
 * generated developer references are built from.
 *
 * `php bin/docs/reference-data.php hooks` prints the hooks, read from the
 * source without WordPress. `wp eval-file bin/docs/reference-data.php site
 * <file>` writes what the running site registers to <file>, since WordPress
 * may print notices of its own.
 *
 * @package Pixonaut\WooCommerce
 */

// No strict_types: `wp eval-file` evaluates the file after code of its own.

use Pixonaut\WooCommerce\Tests\Support\ReferenceData;

$pixonaut_root = dirname( __DIR__, 2 );
require_once $pixonaut_root . '/vendor/autoload.php';

$pixonaut_args = $args ?? array_slice( $argv, 1 );
$pixonaut_json = static fn ( array $data ): string => (string) json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );

if ( 'site' === ( $pixonaut_args[0] ?? '' ) ) {
	file_put_contents( $pixonaut_args[1], $pixonaut_json( ReferenceData::site( $pixonaut_root ) ) );
} else {
	echo $pixonaut_json( ReferenceData::hooks( $pixonaut_root ) );
}
