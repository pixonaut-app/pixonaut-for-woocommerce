<?php
/**
 * The day's exchange rates, from the European Central Bank.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Pricing;

use Closure;
use Pixonaut\WooCommerce\Schema;

/**
 * A printshop charges in its billing currency, which need not be the
 * store's. Costs are converted with the ECB's euro reference rates: one
 * rate per currency against the euro, published each working day, free and
 * without a key. A rate between two other currencies goes through the euro.
 *
 * The rates are kept in an option and read again once a day, when a
 * product's costs are refreshed. When they cannot be read, the last ones
 * are kept and used until the day they are of is a week ago; past that they
 * are stale, and nothing is converted with them.
 */
final class ExchangeRates {

	public const OPTION = Schema::EXCHANGE_RATES_OPTION;

	/**
	 * The ECB's reference rates of the last working day, as XML.
	 */
	public const SOURCE_URL = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml';

	/**
	 * The currency every rate is against.
	 */
	public const BASE = 'EUR';

	private const SCHEMA_VERSION = 1;

	/**
	 * How old the rates may be before they are read again: a day.
	 */
	private const FRESH_SECONDS = 86400;

	/**
	 * How long after a read that failed the next one waits: an hour.
	 */
	private const RETRY_SECONDS = 3600;

	/**
	 * How long after the day they are of rates still convert: a week.
	 */
	public const STALE_SECONDS = 604800;

	/**
	 * How long the ECB may take to answer.
	 */
	private const TIMEOUT_SECONDS = 10;

	/**
	 * One currency's rate in the ECB's list: `<Cube currency='USD' rate='1.0812'/>`.
	 */
	private const RATE_PATTERN = '/currency=[\'"]([A-Z]{3})[\'"]\s+rate=[\'"]([0-9]+(?:\.[0-9]+)?)[\'"]/';

	/**
	 * The day the list is of: `<Cube time='2026-11-13'>`.
	 */
	private const DATE_PATTERN = '/time=[\'"](\d{4}-\d{2}-\d{2})[\'"]/';

	/**
	 * How the ECB's list ends: one without it was cut short.
	 */
	private const END_OF_FILE = '</gesmes:Envelope>';

	/**
	 * @param Closure $clock `fn(): int`, the time now.
	 * @phpstan-param Closure(): int $clock
	 */
	public function __construct( private readonly Closure $clock ) {
	}

	/**
	 * Reads the rates again when the ones kept are older than a day, unless
	 * a read failed within the hour. A read that fails keeps the rates.
	 */
	public function refresh(): void {
		$kept = self::kept();
		$now  = ( $this->clock )();
		if ( $now - $kept['read_at'] < self::FRESH_SECONDS || $now - $kept['tried_at'] < self::RETRY_SECONDS ) {
			return;
		}
		$response = wp_safe_remote_get( self::SOURCE_URL, array( 'timeout' => self::TIMEOUT_SECONDS ) );
		$body     = is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ? '' : wp_remote_retrieve_body( $response );
		$rates    = array();
		if ( preg_match_all( self::RATE_PATTERN, $body, $found, PREG_SET_ORDER ) > 0 ) {
			foreach ( $found as $rate ) {
				$rates[ $rate[1] ] = $rate[2];
			}
		}
		// Kept whole or not at all: a file cut short, or with a rate of nothing, replaces no rate that converts.
		$whole = str_contains( $body, self::END_OF_FILE ) && array() === array_filter( $rates, static fn ( string $rate ): bool => (float) $rate <= 0 );
		if ( ! $whole || array() === $rates || 1 !== preg_match( self::DATE_PATTERN, $body, $date ) || false === strtotime( $date[1] . ' UTC' ) ) {
			update_option( self::OPTION, array( 'tried_at' => $now ) + $kept, false );
			return;
		}
		update_option(
			self::OPTION,
			array(
				'v'        => self::SCHEMA_VERSION,
				'date'     => $date[1],
				'rates'    => $rates,
				'read_at'  => $now,
				'tried_at' => $now,
			),
			false
		);
	}

	/**
	 * What one unit of a currency is in another, by the rates kept; null
	 * when either currency has no rate, none were ever read, or the ones
	 * kept are stale.
	 *
	 * @param string $from The currency converted from, as its ISO 4217 code.
	 * @param string $to   The currency converted to.
	 */
	public function rate( string $from, string $to ): ?float {
		$kept = self::kept();
		if ( $this->is_stale() ) {
			return null;
		}
		$rates = array( self::BASE => '1' ) + $kept['rates'];
		if ( ! isset( $rates[ $from ], $rates[ $to ] ) || (float) $rates[ $from ] <= 0 || (float) $rates[ $to ] <= 0 ) {
			return null;
		}
		return (float) $rates[ $to ] / (float) $rates[ $from ];
	}

	/**
	 * Whether rates are kept and the day they are of is over a week ago:
	 * by the day the ECB published them, not the day they were read, so an
	 * old list answered again is as old as it says.
	 */
	public function is_stale(): bool {
		$kept = self::kept();
		$day  = strtotime( $kept['date'] . ' UTC' );
		return array() !== $kept['rates'] && ( false === $day || ( $this->clock )() - $day > self::STALE_SECONDS );
	}

	/**
	 * The day the rates kept are of, `2026-11-13`; empty when none are kept.
	 */
	public function date(): string {
		return self::kept()['date'];
	}

	/**
	 * The rates kept.
	 *
	 * @return array{v: int, date: string, rates: array<string, string>, read_at: int, tried_at: int}
	 */
	private static function kept(): array {
		$stored = get_option( self::OPTION );
		$stored = is_array( $stored ) && self::SCHEMA_VERSION === ( $stored['v'] ?? self::SCHEMA_VERSION ) ? $stored : array();
		$rates  = array();
		foreach ( is_array( $stored['rates'] ?? null ) ? $stored['rates'] : array() as $currency => $rate ) {
			if ( is_string( $currency ) && is_numeric( $rate ) ) {
				$rates[ $currency ] = (string) $rate;
			}
		}
		return array(
			'v'        => self::SCHEMA_VERSION,
			'date'     => is_string( $stored['date'] ?? null ) ? $stored['date'] : '',
			'rates'    => $rates,
			'read_at'  => is_int( $stored['read_at'] ?? null ) ? $stored['read_at'] : 0,
			'tried_at' => is_int( $stored['tried_at'] ?? null ) ? $stored['tried_at'] : 0,
		);
	}
}
