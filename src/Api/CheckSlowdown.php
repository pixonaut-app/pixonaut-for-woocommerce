<?php
/**
 * How long Pixonaut asked the store to hold its print size checks.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api;

use Closure;
use Pixonaut\WooCommerce\Schema;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\ErrorCode;

/**
 * Checking a product's configuration asks Pixonaut to judge print sizes,
 * and Pixonaut takes only so many of those a minute. Once it refuses one
 * for too many requests, no check asks again until the wait it named is
 * over: a save answers at once, as refused, and each re-check waits its
 * turn, so a run of saves spends no requests that would be refused anyway.
 * The wait is one for the whole store, kept in an option, so every request
 * and background job holds back together.
 */
final class CheckSlowdown {

	public const OPTION = Schema::CHECKS_SLOWED_UNTIL_OPTION;

	/**
	 * The wait when Pixonaut names none: its request windows are a minute long.
	 */
	public const BACKOFF_SECONDS = 60;

	/**
	 * Pixonaut's status for a request refused for too many requests.
	 */
	private const TOO_MANY_REQUESTS = 429;

	/**
	 * When checks may ask Pixonaut again, as Unix time; null when they may now.
	 */
	public static function until(): ?int {
		$until = (int) get_option( self::OPTION, 0 );
		return $until > time() ? $until : null;
	}

	/**
	 * Seconds until checks may ask Pixonaut again; 0 when they may now.
	 */
	public static function seconds_left(): int {
		$until = self::until();
		return null === $until ? 0 : $until - time();
	}

	/**
	 * Holds checks back after Pixonaut refused one for too many requests,
	 * for as long as it asked, or `BACKOFF_SECONDS` when it did not say. A
	 * longer wait already noted stands, whichever request notes it first.
	 *
	 * @param int|null $retry_after Seconds Pixonaut asked the store to wait; null when it did not say.
	 */
	public static function note( ?int $retry_after ): void {
		$until = time() + max( 1, $retry_after ?? self::BACKOFF_SECONDS );
		// Written in one statement that keeps the later time, as several requests can be refused at once.
		if ( 0 === self::extend( $until ) && ! add_option( self::OPTION, $until, '', false ) ) {
			// Another request added it meanwhile.
			self::extend( $until );
		}
		wp_cache_delete( self::OPTION, 'options' );
	}

	/**
	 * Moves the stored time on to a later one, leaving a later one as it is.
	 *
	 * @param int $until The time, as Unix time.
	 * @return int Rows changed: 0 when the option is missing or already holds as late a time.
	 */
	private static function extend( int $until ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the option API cannot compare and write in one statement; the cache is cleared by the caller.
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND CAST( option_value AS UNSIGNED ) < %d", (string) $until, self::OPTION, $until ) );
	}

	/**
	 * Sends a check, unless checks are held back: then it is refused, as
	 * Pixonaut would refuse it, without asking. A refusal for too many
	 * requests holds every check back for the wait it names.
	 *
	 * @template T
	 * @param string  $operation The SDK operation the check sends.
	 * @param Closure $send Sends it, and reads the answer.
	 * @phpstan-param Closure(): T $send
	 * @return T
	 *
	 * @throws ApiException When the check was refused or could not be sent.
	 */
	public static function ask( string $operation, Closure $send ): mixed {
		if ( null !== self::until() ) {
			$error = new ApiException( $operation, self::TOO_MANY_REQUESTS, ErrorCode::RATE_LIMITED, true, max( 1, self::seconds_left() ) );
		} else {
			try {
				return $send();
			} catch ( ApiException $e ) {
				if ( ErrorCode::RATE_LIMITED === $e->errorCode ) {
					self::note( $e->retryAfter );
				}
				$error = $e;
			}
		}
		throw $error;
	}
}
