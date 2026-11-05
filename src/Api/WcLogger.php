<?php
/**
 * Logs to WooCommerce's logger.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api;

/**
 * Entries appear under WooCommerce > Status > Logs, source `pixonaut-api`.
 */
final class WcLogger implements Logger {

	public const SOURCE = 'pixonaut-api';

	public function warning( string $message, array $context = array() ): void {
		wc_get_logger()->warning( $message, array( 'source' => self::SOURCE ) + $context );
	}

	public function error( string $message, array $context = array() ): void {
		wc_get_logger()->error( $message, array( 'source' => self::SOURCE ) + $context );
	}
}
