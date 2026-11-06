<?php
/**
 * The logger the plugin hands the SDK.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api;

use Pixonaut\WooCommerce\Vendor\Psr\Log\AbstractLogger;
use Pixonaut\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Stringable;

/**
 * Passes on everything the SDK logs but its deprecation warning, which it
 * writes for every response that carries one: `HookListener` logs a notice
 * once, when it is new or changed.
 */
final class SdkLogger extends AbstractLogger {

	/**
	 * The context key the SDK's deprecation warning carries.
	 */
	private const DEPRECATION = 'deprecation';

	/**
	 * @param LoggerInterface $logger Where the rest goes.
	 */
	public function __construct( private readonly LoggerInterface $logger ) {
	}

	/**
	 * @param mixed             $level   A PSR-3 level.
	 * @param string|Stringable $message Message.
	 * @param array<mixed>      $context Context.
	 */
	public function log( $level, string|Stringable $message, array $context = array() ): void {
		if ( array_key_exists( self::DEPRECATION, $context ) ) {
			return;
		}
		$this->logger->log( $level, $message, $context );
	}
}
