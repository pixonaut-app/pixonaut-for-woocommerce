<?php
/**
 * The dates in a store route's answer, with their offset.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities\Store;

use DateTimeImmutable;
use DateTimeZone;
use WP_REST_Response;

/**
 * WordPress's and WooCommerce's routes answer a date without an offset: in
 * the site's timezone, or in UTC for a field whose name ends in `_gmt`. An
 * agent reads them as RFC 3339, so each field the route's own schema
 * declares a `date-time` is given its offset; any other value, however
 * much it looks like a date, is left as the store keeps it.
 */
final class RouteDates {

	/**
	 * A date and time as the routes answer it, without an offset.
	 */
	private const OFFSETLESS_DATE = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/';

	/**
	 * How the routes' answers are read, `Y-m-d\TH:i:s`, from midnight.
	 */
	private const OFFSETLESS_FORMAT = '!Y-m-d\TH:i:s';

	private const GMT_SUFFIX = '_gmt';

	private const DATE_TIME = 'date-time';

	/**
	 * @param mixed            $data     The route's answer, as JSON reads it.
	 * @param WP_REST_Response $response The answer, which names the route that gave it.
	 * @return mixed
	 */
	public static function with_offsets( mixed $data, WP_REST_Response $response ): mixed {
		$schema = self::schema( $response );
		if ( array() === $schema ) {
			return $data;
		}
		// A list answers its items, each as the route's schema describes one.
		if ( is_array( $data ) && array_is_list( $data ) && 'array' !== ( $schema['type'] ?? null ) ) {
			return array_map( static fn ( mixed $item ): mixed => self::dated( $item, $schema, null ), $data );
		}
		return self::dated( $data, $schema, null );
	}

	/**
	 * @param mixed                $value  An answer, or a part of one.
	 * @param array<string, mixed> $schema What the route's schema says of it.
	 * @param string|null          $field  The field it is the value of; null for an item.
	 * @return mixed
	 */
	private static function dated( mixed $value, array $schema, ?string $field ): mixed {
		if ( is_string( $value ) ) {
			return self::DATE_TIME === ( $schema['format'] ?? null ) && null !== $field && 1 === preg_match( self::OFFSETLESS_DATE, $value )
				? self::with_offset( $value, str_ends_with( $field, self::GMT_SUFFIX ) )
				: $value;
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$items      = is_array( $schema['items'] ?? null ) ? $schema['items'] : array();
		$properties = is_array( $schema['properties'] ?? null ) ? $schema['properties'] : array();
		foreach ( $value as $key => $child ) {
			if ( is_int( $key ) ) {
				// An item of a list keeps the name of the field the list is.
				$value[ $key ] = self::dated( $child, $items, $field );
			} elseif ( is_array( $properties[ $key ] ?? null ) ) {
				$value[ $key ] = self::dated( $child, $properties[ $key ], $key );
			}
		}
		return $value;
	}

	/**
	 * The schema of the route that gave the answer; empty when it has none.
	 *
	 * @param WP_REST_Response $response A route's answer.
	 * @return array<string, mixed>
	 */
	private static function schema( WP_REST_Response $response ): array {
		$route = $response->get_matched_route();
		if ( '' === $route ) {
			return array();
		}
		$options = rest_get_server()->get_route_options( $route );
		$schema  = is_array( $options ) ? ( $options['schema'] ?? null ) : null;
		$schema  = is_callable( $schema ) ? call_user_func( $schema ) : null;
		return is_array( $schema ) ? $schema : array();
	}

	/**
	 * A date and time without an offset as RFC 3339 with one, e.g.
	 * `2026-10-31T14:00:00+01:00`.
	 *
	 * @param string $value  `Y-m-d\TH:i:s`.
	 * @param bool   $in_utc Whether it is in UTC rather than the site's timezone.
	 */
	private static function with_offset( string $value, bool $in_utc ): string {
		$date = DateTimeImmutable::createFromFormat( self::OFFSETLESS_FORMAT, $value, $in_utc ? new DateTimeZone( 'UTC' ) : wp_timezone() );
		return false === $date ? $value : $date->format( DATE_RFC3339 );
	}
}
