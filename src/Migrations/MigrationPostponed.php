<?php
/**
 * A migration that could not finish and can wait.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Migrations;

use RuntimeException;

/**
 * Thrown by a migration the plugin works without, so that its failure does
 * not fail the request. The runner logs it and tries again later. A migration
 * the plugin cannot work without throws any other exception instead.
 */
final class MigrationPostponed extends RuntimeException {
}
