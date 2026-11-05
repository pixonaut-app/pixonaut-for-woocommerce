<?php
/**
 * A variation's Pixonaut panel, below its attributes in the Variations tab.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/admin/product-variation.php.
 * Field names, classes and the `data-` attributes are what the plugin reads; keep them.
 *
 * @package Pixonaut\WooCommerce
 * @version 0.1.0
 *
 * @var string                $nonce_name     Name of the panel's nonce field.
 * @var string                $nonce          The nonce.
 * @var string                $override_name  Name of the override checkbox.
 * @var string                $override_id    Its id.
 * @var bool                  $override       Whether the variation uses its own configuration.
 * @var int                   $max_sides      Most sides a variation has.
 * @var string                $fields_html    The variation's fields, rendered from `admin/product-fields.php`.
 * @var array<string, string> $search_urls    `templates`, `blanks`, `fitting-templates`, `placements`, `template` REST URLs.
 * @var string                $rest_nonce     Nonce for the REST routes.
 * @var string                $variant_html   The blank variant field, rendered from `admin/product-variant.php`; empty
 *                                            for a variation not personalised on a blank.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="pixonaut-variation-panel pixonaut-fields-root form-row form-row-full"
	data-templates-url="<?php echo esc_url( $search_urls['templates'] ); ?>"
	data-blanks-url="<?php echo esc_url( $search_urls['blanks'] ); ?>"
	data-fitting-templates-url="<?php echo esc_url( $search_urls['fitting-templates'] ); ?>"
	data-placements-url="<?php echo esc_url( $search_urls['placements'] ); ?>"
	data-template-url="<?php echo esc_url( $search_urls['template'] ); ?>"
	data-rest-nonce="<?php echo esc_attr( $rest_nonce ); ?>"
	data-max-sides="<?php echo esc_attr( (string) $max_sides ); ?>">
	<input type="hidden" name="<?php echo esc_attr( $nonce_name ); ?>" value="<?php echo esc_attr( $nonce ); ?>" />
	<h4 class="pixonaut-variation-panel__title"><?php /* translators: Heading of the Pixonaut fields on a variation of a variable product, shown to the merchant; the product name, keep it as it is. */ esc_html_e( 'Pixonaut', 'pixonaut-for-woocommerce' ); ?></h4>
	<p class="pixonaut-variation-override">
		<label class="pixonaut-variation-override__label" for="<?php echo esc_attr( $override_id ); ?>">
			<input type="checkbox" class="checkbox pixonaut-override" id="<?php echo esc_attr( $override_id ); ?>" name="<?php echo esc_attr( $override_name ); ?>" value="yes" <?php checked( $override ); ?> />
			<?php /* translators: Checkbox label on a variable product's variation panel: this variation uses its own personalisation settings. Admin product page. */ esc_html_e( 'Override for this variation', 'pixonaut-for-woocommerce' ); ?>
		</label>
	</p>
	<p class="description pixonaut-variation-inherits" <?php echo $override ? 'hidden' : ''; ?>><?php esc_html_e( 'This variation is personalised as the product’s Pixonaut tab says. Tick to give it its own blank, sides, templates and settings.', 'pixonaut-for-woocommerce' ); ?></p>
	<p class="description pixonaut-variation-overrides" <?php echo $override ? '' : 'hidden'; ?>><?php esc_html_e( 'This variation uses its own configuration below, not the product’s Pixonaut tab.', 'pixonaut-for-woocommerce' ); ?></p>
	<div class="woocommerce_options_panel pixonaut-variation-fields" <?php echo $override ? '' : 'hidden'; ?>>
		<?php echo $fields_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in admin/product-fields.php. ?>
	</div>
	<div class="woocommerce_options_panel pixonaut-variation-variant">
		<?php echo $variant_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in admin/product-variant.php. ?>
	</div>
</div>
