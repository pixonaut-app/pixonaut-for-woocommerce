<?php
/**
 * Logs to WooCommerce's logger.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api;

use Pixonaut\WooCommerce\Vendor\Psr\Log\AbstractLogger;
use Stringable;

/**
 * A PSR-3 logger, for the SDK and the plugin. Entries appear under
 * WooCommerce > Status > Logs, source `pixonaut-api`.
 */
final class WcLogger extends AbstractLogger {

	public const SOURCE = 'pixonaut-api';

	/**
	 * @param mixed             $level   A PSR-3 level, which WooCommerce's logger shares.
	 * @param string|Stringable $message Message.
	 * @param array<mixed>      $context Context.
	 */
	public function log( $level, string|Stringable $message, array $context = array() ): void {
		wc_get_logger()->log( (string) $level, (string) $message, array( 'source' => self::SOURCE ) + $context );
	}
}
