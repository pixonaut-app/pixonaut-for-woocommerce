<?php
/**
 * The pinned Pixonaut API spec.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api;

/**
 * The Pixonaut API release the client is built against. Mirrors
 * `contracts/pixonaut/spec.lock.json`, which `pnpm contracts:pin` writes and
 * a unit test holds this constant to.
 */
final class Spec {

	public const VERSION = '1.0.0';
}
