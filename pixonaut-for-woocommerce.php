<?php
/**
 * Plugin Name:          Pixonaut for WooCommerce
 * Plugin URI:           https://github.com/pixonaut-app/pixonaut-for-woocommerce/blob/main/docs/merchant/index.md
 * Description:          Personalised products for WooCommerce through Pixonaut: customers design on the product page, paid lines become printshop orders.
 * x-release-please-start-version
 * Version:              1.6.0
 * x-release-please-end
 * Requires at least:    6.9
 * Tested up to:         7.1
 * Requires PHP:         8.1
 * Requires Plugins:     woocommerce
 * WC requires at least: 10.6
 * WC tested up to:      11.2
 * Author:               Pixonaut
 * Author URI:           https://pixonaut.app
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          pixonaut-for-woocommerce
 * Domain Path:          /languages
 * Update URI:           https://github.com/pixonaut-app/pixonaut-for-woocommerce
 *
 * @package Pixonaut\WooCommerce
 */

// This file and Requirements stay parseable by old PHP, so a site below the
// minimum gets a notice instead of a fatal error.

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/vendor/autoload.php';

if ( ! \Pixonaut\WooCommerce\Requirements::php_supported( PHP_VERSION ) ) {
	add_action( 'admin_notices', array( \Pixonaut\WooCommerce\Requirements::class, 'php_notice' ) );
	return;
}

\Pixonaut\WooCommerce\Plugin::boot( __FILE__ );
