<?php
/**
 * The Regenerate in background and Regenerate now buttons in the Pixonaut
 * tab, a spinner while the pictures are drawn in the background, and the
 * line saying how the drawing went. Regenerate now asks for confirmation
 * and shows its progress in a dialog (`admin/regenerate-now.php`); it stays
 * on while the background drawing runs, so pictures stuck in the queue can
 * be drawn right away.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/admin/product-regenerate.php.
 * Classes and data attributes are what the plugin's script reads; keep them.
 *
 * @package Pixonaut\WooCommerce
 * @version 0.1.0
 *
 * @var string $url        The product's pictures route, which Regenerate in background posts to and then asks until they are drawn.
 * @var int    $product_id The product, which Regenerate now draws.
 * @var string $state How the product's pictures stand now: `drawing`, `drawn`, `failed`, `kept` or `error` (nothing was queued).
 */

defined( 'ABSPATH' ) || exit;

$pixonaut_states = array(
	\Pixonaut\WooCommerce\Admin\RegeneratePictures::DRAWING => __( 'Regenerating the product image, its gallery pictures and its variations’ images…', 'pixonaut-for-woocommerce' ),
	\Pixonaut\WooCommerce\Admin\RegeneratePictures::DRAWN  => __( 'The product image is regenerated. Reload the page to see it here.', 'pixonaut-for-woocommerce' ),
	\Pixonaut\WooCommerce\Admin\RegeneratePictures::FAILED => __( 'Pixonaut could not draw the product image. The product keeps the image it has, and it is tried again later.', 'pixonaut-for-woocommerce' ),
	\Pixonaut\WooCommerce\Admin\RegeneratePictures::KEPT   => __( 'The product shows an image you set yourself, which is kept. Its gallery’s and variations’ Pixonaut pictures, if it has any, are drawn again. Use the Pixonaut picture under Product image to show Pixonaut’s picture instead.', 'pixonaut-for-woocommerce' ),
	\Pixonaut\WooCommerce\Admin\RegeneratePictures::NOT_QUEUED => __( 'The product image could not be regenerated. Try again in a moment.', 'pixonaut-for-woocommerce' ),
);
?>
<div class="options_group pixonaut-group pixonaut-regenerate"
	data-url="<?php echo esc_url( $url ); ?>"
	<?php foreach ( $pixonaut_states as $pixonaut_state => $pixonaut_text ) : ?>
		data-<?php echo esc_attr( $pixonaut_state ); ?>-text="<?php echo esc_attr( $pixonaut_text ); ?>"
	<?php endforeach; ?>
	>
	<p class="form-field">
		<span class="pixonaut-field-control">
			<button type="button" class="button pixonaut-regenerate__button"<?php disabled( \Pixonaut\WooCommerce\Admin\RegeneratePictures::DRAWING, $state ); ?>><?php /* translators: Bulk and row action on the admin products list, and button in the product's Pixonaut tab: queues the product's Pixonaut pictures to be drawn again by background jobs. */ esc_html_e( 'Regenerate in background', 'pixonaut-for-woocommerce' ); ?></button>
			<button type="button" class="button pixonaut-regenerate__now" data-product-id="<?php echo esc_attr( (string) $product_id ); ?>"><?php /* translators: Bulk and row action on the admin products list, and button in the product's Pixonaut tab: draws the product's Pixonaut pictures again right away, while the page stays open. */ esc_html_e( 'Regenerate now', 'pixonaut-for-woocommerce' ); ?></button>
			<span class="spinner pixonaut-regenerate__spinner<?php echo \Pixonaut\WooCommerce\Admin\RegeneratePictures::DRAWING === $state ? ' is-active' : ''; ?>"></span>
		</span>
		<span class="description pixonaut-regenerate__status" role="status" aria-live="polite"><?php echo \Pixonaut\WooCommerce\Admin\RegeneratePictures::DRAWING === $state ? esc_html( $pixonaut_states[ \Pixonaut\WooCommerce\Admin\RegeneratePictures::DRAWING ] ) : ''; ?></span>
	</p>
	<p class="description pixonaut-regenerate__intro"><?php esc_html_e( 'Draws the product image, its gallery pictures and its variations’ images again from the template version and settings they use now: in background jobs, or right away while this page stays open.', 'pixonaut-for-woocommerce' ); ?></p>
</div>
