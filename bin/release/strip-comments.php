<?php
/**
 * Removes the comments from every PHP file under the directories given,
 * keeping the code and its layout as they are.
 *
 * The wordpress.org flavor runs it on the SDK's generated models, whose
 * docblocks repeat the API spec's descriptions: nothing reads them at run
 * time, and they are most of the zip's size.
 *
 * Usage: php strip-comments.php <dir> [<dir> ...]
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

/** The tokens dropped: line, block and doc comments. */
const COMMENT_TOKENS = array( T_COMMENT, T_DOC_COMMENT );

/**
 * The source without its comments. A line left holding only whitespace is
 * removed, so a dropped docblock leaves no gap.
 *
 * @param string $source A PHP file's contents.
 */
function without_comments( string $source ): string {
	$code = '';
	foreach ( token_get_all( $source ) as $token ) {
		if ( is_array( $token ) && in_array( $token[0], COMMENT_TOKENS, true ) ) {
			// A `//` comment owns its line end: keep it, so the next line stays apart.
			if ( str_ends_with( $token[1], "\n" ) ) {
				$code .= "\n";
			}
			continue;
		}
		$code .= is_array( $token ) ? $token[1] : $token;
	}
	return (string) preg_replace( '/^[ \t]*\r?\n/m', '', $code );
}

$dirs = array_slice( $argv, 1 );
if ( array() === $dirs ) {
	fwrite( STDERR, "Usage: php strip-comments.php <dir> [<dir> ...]\n" );
	exit( 1 );
}

foreach ( $dirs as $dir ) {
	if ( ! is_dir( $dir ) ) {
		fwrite( STDERR, "Not a directory: {$dir}\n" );
		exit( 1 );
	}
	$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $files as $file ) {
		if ( 'php' === $file->getExtension() ) {
			$path = $file->getPathname();
			file_put_contents( $path, without_comments( (string) file_get_contents( $path ) ) );
		}
	}
}
