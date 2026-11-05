<?php
/**
 * Theme override older than the plugin's template, for the status report test.
 *
 * @package Pixonaut\WooCommerce
 * @version 0.0.1
 *
 * @var string $message
 */

defined( 'ABSPATH' ) || exit;
?>
<p class="outdated-override"><?php echo esc_html( $message ); ?></p>
