<?php
/**
 * Revokes the store's keys when the plugin is deleted.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Connection;

use Pixonaut\WooCommerce\Api\Client;
use Pixonaut\WooCommerce\Api\Connect;
use Pixonaut\WooCommerce\Api\Deprecations;
use Pixonaut\WooCommerce\Api\Hosts;
use Pixonaut\WooCommerce\Api\Logger;
use Pixonaut\WooCommerce\Api\Operations;
use Pixonaut\WooCommerce\Api\WcLogger;
use Pixonaut\WooCommerce\Api\WpHttpTransport;
use Throwable;

/**
 * Deleting the plugin tells Pixonaut, so the integration key stops working,
 * no longer counts toward the plan's connected stores, and Pixonaut stops
 * sending webhooks. Best effort: whatever happens, the uninstall goes on.
 */
final class UninstallRevoke {

	/**
	 * Short, so an unreachable Pixonaut holds up the Delete click briefly.
	 */
	public const TIMEOUT_SECONDS = 5;

	/**
	 * The longest all sites of a network wait on Pixonaut together; the
	 * sites after it are cleaned without revoking, so an outage cannot hold
	 * the request until the server ends it halfway.
	 */
	public const BUDGET_SECONDS = 15;

	/**
	 * When the revokes of this uninstall must be over.
	 */
	public static function deadline(): int {
		return time() + self::BUDGET_SECONDS;
	}

	/**
	 * Revokes the current site's connection, unless it is paused: a refused
	 * key is revoked already, and a copied site's keys are the keys of the
	 * site it was copied from.
	 *
	 * @param int $deadline Unix time by which to give up; nothing is sent once it has passed.
	 *
	 * @return bool Whether Pixonaut was asked and accepted.
	 */
	public static function run( int $deadline ): bool {
		$left = min( self::TIMEOUT_SECONDS, $deadline - time() );
		if ( $left <= 0 ) {
			return false;
		}
		try {
			$store = new ConnectionStore( Cipher::for_site() );
			if ( null === $store->load() || null !== $store->paused() ) {
				return false;
			}
			$logger = function_exists( 'wc_get_logger' ) ? new WcLogger() : self::silent();
			$client = new Client( Hosts::server_api(), Operations::plugin(), $store, new WpHttpTransport(), $logger, new Deprecations() );
			( new Connect( $client ) )->delete( $left );
			return true;
		} catch ( Throwable $e ) {
			return false;
		}
	}

	/**
	 * A logger for a site whose WooCommerce is inactive.
	 */
	private static function silent(): Logger {
		return new class() implements Logger {
			public function warning( string $message, array $context = array() ): void {
			}

			public function error( string $message, array $context = array() ): void {
			}
		};
	}
}
