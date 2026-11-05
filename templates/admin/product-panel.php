<?php
/**
 * The Pixonaut tab of the product data box.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/admin/product-panel.php.
 * The classes and the `data-` attributes are what the plugin reads; keep them.
 *
 * @package Pixonaut\WooCommerce
 * @version 0.1.0
 *
 * @var string                $panel_id    The panel's id, the tab's target.
 * @var string                $nonce_field Nonce field markup.
 * @var int                   $max_sides   Most sides a product has.
 * @var string                $fields_html The configuration's fields, rendered from `admin/product-fields.php`.
 * @var array<string, string> $search_urls `templates`, `blanks`, `fitting-templates`, `placements`, `template` REST URLs.
 * @var string                $rest_nonce  Nonce for the REST routes.
 * @var string                $variant_html Empty: the blank variant field is in `$fields_html`, with the blank.
 */

defined( 'ABSPATH' ) || exit;
?>
<div id="<?php echo esc_attr( $panel_id ); ?>" class="panel woocommerce_options_panel hidden pixonaut-product-panel pixonaut-fields-root"
	data-templates-url="<?php echo esc_url( $search_urls['templates'] ); ?>"
	data-blanks-url="<?php echo esc_url( $search_urls['blanks'] ); ?>"
	data-fitting-templates-url="<?php echo esc_url( $search_urls['fitting-templates'] ); ?>"
	data-placements-url="<?php echo esc_url( $search_urls['placements'] ); ?>"
	data-template-url="<?php echo esc_url( $search_urls['template'] ); ?>"
	data-rest-nonce="<?php echo esc_attr( $rest_nonce ); ?>"
	data-max-sides="<?php echo esc_attr( (string) $max_sides ); ?>">
	<?php echo $nonce_field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field() markup. ?>
	<?php echo $fields_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in admin/product-fields.php. ?>
	<?php echo $variant_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in admin/product-variant.php. ?>
</div>
