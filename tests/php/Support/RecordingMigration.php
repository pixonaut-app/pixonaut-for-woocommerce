<?php
/**
 * A migration that records when it runs.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Support;

use Pixonaut\WooCommerce\Migrations\Migration;

final class RecordingMigration implements Migration {

	public function __construct(
		private readonly int $version,
		private readonly MigrationLog $log,
	) {
	}

	public function version(): int {
		return $this->version;
	}

	public function up(): void {
		$this->log->versions[] = $this->version;
	}
}
