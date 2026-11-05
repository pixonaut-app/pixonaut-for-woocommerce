<?php
/**
 * Reads a hook's docblock.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Support\Hooks;

use Closure;

/**
 * Splits a hook docblock into the fields of a Hook. Prose keeps its Markdown;
 * soft-wrapped lines are joined. The `@example` keeps its lines and
 * indentation.
 */
final class Docblock {

	/**
	 * `<$name> <description>`, what follows the type in a `@param` tag.
	 */
	private const PARAM_REST = '/^\s*(?:\.\.\.)?(\$\w+)\s*(.*)$/s';

	/**
	 * Brackets a type can hold spaces inside, e.g. `array<string, mixed>`.
	 */
	private const OPENING_BRACKETS = '<{(';

	private const CLOSING_BRACKETS = '>})';

	/**
	 * @param string  $text    The docblock, `/**` to `*\/`.
	 * @param Closure $resolve Makes a type's class names fully qualified.
	 * @phpstan-param Closure(string): string $resolve
	 * @return array{summary: string, description: string, since: string, params: list<array{type: string, name: string, description: string}>, example: string, deprecation: string}
	 */
	public static function parse( string $text, Closure $resolve ): array {
		$fields = array(
			'summary'     => '',
			'description' => '',
			'since'       => '',
			'params'      => array(),
			'example'     => '',
			'deprecation' => '',
		);

		list( $prose, $tags ) = self::split( self::lines( $text ) );

		$paragraphs            = self::paragraphs( $prose );
		$fields['summary']     = array_shift( $paragraphs ) ?? '';
		$fields['description'] = implode( "\n\n", $paragraphs );

		foreach ( $tags as list( $tag, $lines ) ) {
			$joined = trim( implode( ' ', array_map( 'trim', $lines ) ) );
			switch ( $tag ) {
				case 'since':
					$fields['since'] = (string) strtok( $joined, ' ' );
					break;
				case 'param':
					$type = self::leading_type( $joined );
					if ( '' !== $type && 1 === preg_match( self::PARAM_REST, substr( $joined, strlen( $type ) ), $match ) ) {
						$fields['params'][] = array(
							'type'        => $resolve( $type ),
							'name'        => $match[1],
							'description' => $match[2],
						);
					}
					break;
				case 'example':
					$fields['example'] = trim( implode( "\n", $lines ), "\n" );
					break;
				case 'deprecated':
					$fields['deprecation'] = $joined;
					break;
			}
		}
		return $fields;
	}

	/**
	 * The type a tag starts with: up to the first space outside brackets.
	 *
	 * @param string $text A tag's text.
	 */
	private static function leading_type( string $text ): string {
		$depth  = 0;
		$length = strlen( $text );
		for ( $i = 0; $i < $length; $i++ ) {
			$char = $text[ $i ];
			if ( str_contains( self::OPENING_BRACKETS, $char ) ) {
				++$depth;
			} elseif ( str_contains( self::CLOSING_BRACKETS, $char ) ) {
				--$depth;
			} elseif ( 0 === $depth && ctype_space( $char ) ) {
				return substr( $text, 0, $i );
			}
		}
		return $text;
	}

	/**
	 * The docblock's lines without the comment markers and the `* ` prefix.
	 *
	 * @param string $text The docblock.
	 * @return list<string>
	 */
	private static function lines( string $text ): array {
		$text  = (string) preg_replace( '#^\s*/\*\*|\*/\s*$#', '', $text );
		$lines = array();
		foreach ( explode( "\n", $text ) as $line ) {
			$lines[] = rtrim( (string) preg_replace( '/^\s*\* ?/', '', $line ) );
		}
		return $lines;
	}

	/**
	 * The prose before the first tag, and each tag with its lines: the rest
	 * of the tag's own line, then the lines up to the next tag.
	 *
	 * @param string[] $lines Docblock lines.
	 * @phpstan-param list<string> $lines
	 * @return array{0: list<string>, 1: list<array{0: string, 1: list<string>}>}
	 */
	private static function split( array $lines ): array {
		$prose = array();
		$tags  = array();
		$tag   = null;
		$body  = array();
		foreach ( $lines as $line ) {
			if ( 1 === preg_match( '/^@(\w+)\s*(.*)$/', $line, $match ) ) {
				if ( null !== $tag ) {
					$tags[] = array( $tag, $body );
				}
				$tag  = $match[1];
				$body = '' === $match[2] ? array() : array( $match[2] );
			} elseif ( null === $tag ) {
				$prose[] = $line;
			} else {
				$body[] = $line;
			}
		}
		if ( null !== $tag ) {
			$tags[] = array( $tag, $body );
		}
		return array( $prose, $tags );
	}

	/**
	 * Paragraphs of prose, each soft-wrapped run of lines joined into one.
	 *
	 * @param string[] $lines Prose lines.
	 * @phpstan-param list<string> $lines
	 * @return list<string>
	 */
	private static function paragraphs( array $lines ): array {
		$paragraphs = array();
		$split      = preg_split( '/\n\s*\n/', trim( implode( "\n", $lines ) ) );
		foreach ( false === $split ? array() : $split as $paragraph ) {
			$joined = trim( (string) preg_replace( '/\s*\n\s*/', ' ', $paragraph ) );
			if ( '' !== $joined ) {
				$paragraphs[] = $joined;
			}
		}
		return $paragraphs;
	}
}
