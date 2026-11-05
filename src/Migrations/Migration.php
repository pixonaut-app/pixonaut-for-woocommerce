<?php
/**
 * A schema migration.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Migrations;

/**
 * One forward-only step of the stored schema. Versions are positive integers,
 * unique across all migrations; a migration never changes once released.
 */
interface Migration {

	public function version(): int;

	public function up(): void;
}
