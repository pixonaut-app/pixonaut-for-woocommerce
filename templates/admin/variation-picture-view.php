<?php
/**
 * In a personalised variation's options, what its image is drawn on: as its
 * product's image, or one of the photos of the blank for its side.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/admin/variation-picture-view.php.
 * Field names and classes are what the plugin reads; keep them.
 *
 * @package Pixonaut\WooCommerce
 * @version 1.2.0
 *
 * @var string $listed_name  Posted with the choice, so a form without it keeps the one saved.
 * @var string $choices_html The choices, rendered from `admin/picture-view-choices.php`.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="form-row form-row-full pixonaut-picture-view pixonaut-picture-view--variation">
	<input type="hidden" name="<?php echo esc_attr( $listed_name ); ?>" value="1" />
	<p class="pixonaut-picture-view__label"><?php /* translators: Label of the choices of what a variation's Pixonaut image is drawn on. Admin product page, variation options. */ esc_html_e( 'Variation image drawn on', 'pixonaut-for-woocommerce' ); ?></p>
	<?php echo $choices_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in admin/picture-view-choices.php. ?>
</div>
