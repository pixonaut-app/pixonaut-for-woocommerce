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
 * @var bool                                                   $importing    Whether Pixonaut is still importing the blank from the printshop's catalogue: it has no variants yet.
 * @var string                                                 $blank_name   The blank's name.
 * @var string                                                 $pairing_html Each variation's variant, rendered from `admin/product-variant-pairing.php`; empty for a simple product.
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
<?php if ( $importing ) : ?>
	<p class="form-field pixonaut-blank-importing" data-importing>
		<span class="pixonaut-field-control">
			<span class="spinner is-active"></span>
			<?php /* translators: 1: a blank's name, such as "Classic poster"; 2: the printshop it is imported from, such as Gelato. Shown in the Pixonaut tab of the product edit page while Pixonaut imports the product. */ ?>
			<?php echo esc_html( sprintf( __( 'Importing %1$s from %2$s into Pixonaut…', 'pixonaut-for-woocommerce' ), $blank_name, $printshop['name'] ) ); ?>
		</span>
		<span class="description"><?php esc_html_e( 'This takes a minute or two. You can update the product meanwhile: its variants are paired once the import is done.', 'pixonaut-for-woocommerce' ); ?></span>
	</p>
<?php elseif ( $variable ) : ?>
	<?php if ( '' === $pairing_html ) : ?>
		<p class="form-field pixonaut-variant-per-variation">
			<span class="pixonaut-field-label"><?php /* translators: Label of the variant of the blank the product is printed on. Admin product page. */ esc_html_e( 'Variant', 'pixonaut-for-woocommerce' ); ?></span>
			<span class="pixonaut-field-control"><?php esc_html_e( 'Each variation has its own, under Variations.', 'pixonaut-for-woocommerce' ); ?></span>
		</p>
	<?php endif; ?>
	<?php echo $pairing_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in admin/product-variant-pairing.php. ?>
<?php else : ?>
	<?php echo $variant_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in admin/product-variant.php. ?>
<?php endif; ?>
