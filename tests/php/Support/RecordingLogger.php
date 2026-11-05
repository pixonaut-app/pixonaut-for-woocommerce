<?php
/**
 * A logger that keeps what it was told.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Support;

use Pixonaut\WooCommerce\Api\Logger;

final class RecordingLogger implements Logger {

	/**
	 * @var list<array{level: string, message: string, context: array<string, mixed>}>
	 */
	public array $entries = array();

	public function warning( string $message, array $context = array() ): void {
		$this->entries[] = array(
			'level'   => 'warning',
			'message' => $message,
			'context' => $context,
		);
	}

	public function error( string $message, array $context = array() ): void {
		$this->entries[] = array(
			'level'   => 'error',
			'message' => $message,
			'context' => $context,
		);
	}
}
