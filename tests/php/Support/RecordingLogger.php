<?php
/**
 * A logger that keeps what it was told.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Support;

use Pixonaut\WooCommerce\Vendor\Psr\Log\AbstractLogger;
use Stringable;

final class RecordingLogger extends AbstractLogger {

	/**
	 * @var list<array{level: string, message: string, context: array<mixed>}>
	 */
	public array $entries = array();

	/**
	 * @param mixed             $level   A PSR-3 level.
	 * @param string|Stringable $message Message.
	 * @param array<mixed>      $context Context.
	 */
	public function log( $level, string|Stringable $message, array $context = array() ): void {
		$this->entries[] = array(
			'level'   => (string) $level,
			'message' => (string) $message,
			'context' => $context,
		);
	}
}
