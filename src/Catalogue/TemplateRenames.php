<?php
/**
 * The template ids Pixonaut changed.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use Pixonaut\WooCommerce\Schema;

/**
 * Each base id a template had before Pixonaut changed it, mapped to the one
 * it became. Pixonaut keeps answering an old id as an alias, and moves the
 * designs made under it to the new one, so a design read after a change
 * names the new id while something the store still holds names the old.
 * Comparing both by `current()` makes them the same template.
 *
 * A chain of changes (a to b, then b to c) is followed to its end. An id
 * that becomes a template's id again is no longer an old one.
 */
final class TemplateRenames {

	public const OPTION = Schema::TEMPLATE_RENAMES_OPTION;

	/**
	 * Notes that the template `$from` is now `$to`.
	 *
	 * @param string $from The old base id.
	 * @param string $to   The new base id.
	 */
	public static function record( string $from, string $to ): void {
		if ( '' === $from || '' === $to || $from === $to ) {
			return;
		}
		$renames = self::all();
		if ( ( $renames[ $from ] ?? null ) === $to && ! isset( $renames[ $to ] ) ) {
			return;
		}
		$renames[ $from ] = $to;
		// `$to` names a template now, whatever it named before.
		unset( $renames[ $to ] );
		update_option( self::OPTION, $renames, false );
	}

	/**
	 * The id a template has now: `$base_id` followed through every change
	 * noted, or `$base_id` itself when it was never changed.
	 *
	 * @param string $base_id A template's base id.
	 */
	public static function current( string $base_id ): string {
		$renames = self::all();
		$seen    = array();
		while ( isset( $renames[ $base_id ] ) && ! isset( $seen[ $base_id ] ) ) {
			$seen[ $base_id ] = true;
			$base_id          = (string) $renames[ $base_id ];
		}
		return $base_id;
	}

	/**
	 * The first id the template was known by here: `$base_id` followed back
	 * through every change noted. The same for every id the template had, so
	 * what is keyed by it does not change when the template's id does.
	 *
	 * @param string $base_id A template's base id.
	 */
	public static function first( string $base_id ): string {
		$earlier = array_flip( self::all() );
		$seen    = array();
		while ( isset( $earlier[ $base_id ] ) && ! isset( $seen[ $base_id ] ) ) {
			$seen[ $base_id ] = true;
			$base_id          = (string) $earlier[ $base_id ];
		}
		return $base_id;
	}

	/**
	 * Whether two base ids name the same template.
	 *
	 * @param string $one   A template's base id.
	 * @param string $other Another.
	 */
	public static function same( string $one, string $other ): bool {
		return $one === $other || self::current( $one ) === self::current( $other );
	}

	/**
	 * @return array<string, string> The new base id, by the old one.
	 */
	private static function all(): array {
		$stored  = get_option( self::OPTION, array() );
		$renames = array();
		// A key of digits alone comes back from PHP as an int.
		foreach ( is_array( $stored ) ? $stored : array() as $from => $to ) {
			if ( '' !== (string) $from && is_string( $to ) && '' !== $to ) {
				$renames[ (string) $from ] = $to;
			}
		}
		return $renames;
	}
}
