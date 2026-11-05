<?php
/**
 * A request that got no response: DNS, connection, timeout.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api;

use RuntimeException;

final class TransportFailure extends RuntimeException {
}
