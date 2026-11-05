<?php
/**
 * Pixonaut refused an idempotency key: the plugin built the request wrong.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api;

/**
 * `idempotency_key_reused` (same key, other body) or `invalid_idempotency_key`.
 * Sending again never helps; whoever catches it stops and flags the work for
 * attention.
 */
final class IdempotencyBug extends ApiError {
}
