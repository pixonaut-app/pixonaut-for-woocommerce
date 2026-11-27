<?php
/**
 * The plugin cannot ask Pixonaut for a value set.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api;

use RuntimeException;

/**
 * The Pixonaut client the plugin ships with does not know
 * `templates.valueSets.create` yet (`Pixonaut::AWAITED_OPERATIONS`), so no
 * set is made and nothing is saved.
 */
final class ValueSetsUnavailable extends RuntimeException {
}
