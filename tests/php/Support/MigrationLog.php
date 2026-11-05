<?php
/**
 * Records which migrations ran.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Support;

final class MigrationLog {

	/**
	 * Versions in the order their migrations ran.
	 *
	 * @var list<int>
	 */
	public array $versions = array();
}
