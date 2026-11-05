<?php
/**
 * Unit suite bootstrap: no WordPress, functions mocked with Brain Monkey.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

// Plugin files guarded by `defined( 'ABSPATH' ) || exit;` would otherwise end
// the run, with status 0, as soon as a test loads one.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/pixonaut-unit-wordpress/' );
}
