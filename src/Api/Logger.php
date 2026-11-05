<?php
/**
 * Where the client logs.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api;

interface Logger {

	/**
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Context.
	 */
	public function warning( string $message, array $context = array() ): void;

	/**
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Context.
	 */
	public function error( string $message, array $context = array() ): void;
}
