<?php
/**
 * What the Pixonaut tab shows of a product's blank: the printshop it prints
 * at, and which of its variants the product is. The panel draws it again
 * when another blank is picked, before the product is updated.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/admin/product-blank.php.
 * The classes are what the plugin reads; keep them.
 *
 * @package Pixonaut\WooCommerce
 * @version 1.4.0
 *
 * @var array{name: string, url: string, integration: string} $printshop    The printshop the blank prints at, its page in the Pixonaut studio, and its name when it is a print partner's integration.
 * @var string                                                 $variant_html The blank variant field, rendered from `admin/product-variant.php`; empty for a variable product.
 * @var bool                                                   $variable     Whether the product is variable: each variation then has its own variant.
 */

defined( 'ABSPATH' ) || exit;
?>
<p class="form-field pixonaut-printshop-line">
	<span class="pixonaut-field-label"><?php /* translators: Label of the printshop that makes the product's orders. Admin product page. */ esc_html_e( 'Printshop', 'pixonaut-for-woocommerce' ); ?></span>
	<span class="pixonaut-field-control">
		<span class="pixonaut-printshop-name"><?php echo esc_html( $printshop['name'] ); ?></span>
		<?php if ( '' !== $printshop['url'] ) : ?>
			· <a href="<?php echo esc_url( $printshop['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php /* translators: Link to the Pixonaut studio where the merchant changes the blank's printshop. Admin product page. */ esc_html_e( 'Change in Pixonaut', 'pixonaut-for-woocommerce' ); ?></a>
		<?php endif; ?>
	</span>
</p>
<?php if ( $variable ) : ?>
	<p class="form-field pixonaut-variant-per-variation">
		<span class="pixonaut-field-label"><?php /* translators: Label of the variant of the blank the product is printed on. Admin product page. */ esc_html_e( 'Variant', 'pixonaut-for-woocommerce' ); ?></span>
		<span class="pixonaut-field-control"><?php esc_html_e( 'Each variation has its own, under Variations.', 'pixonaut-for-woocommerce' ); ?></span>
	</p>
<?php endif; ?>
<?php echo $variant_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in admin/product-variant.php. ?>
