<?php
/**
 * The functions another plugin reads the store's Pixonaut connection and
 * abilities with. Defined only once the plugin has loaded with WooCommerce active:
 * `function_exists( 'pixonaut_wc_status' )` says whether it is.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

use Pixonaut\WooCommerce\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Every ability of the plugin, and WooCommerce's product and order ones,
 * another plugin may offer as tools. Each runs as the current user, after
 * its own permission check. Registered whether or not the store is
 * connected.
 *
 * @return list<string> Ability names, e.g. `pixonaut-for-woocommerce/coupon-create`.
 */
function pixonaut_wc_abilities(): array {
	return Plugin::instance()->bridge_discovery()->abilities();
}

/**
 * The store's connection. Never a key.
 *
 * @return array{connected: bool, paused: string|null, workspace: string|null, workspace_id: string|null, environment: string|null, reason: string|null}
 */
function pixonaut_wc_status(): array {
	return Plugin::instance()->bridge_status()->read();
}
