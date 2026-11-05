<?php
/**
 * Where a personalised product's or variation's image is set: a line on
 * its Pixonaut picture, and beside an image of the store's own, the link
 * that puts the picture back.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/admin/product-picture.php.
 * The link's class and data attributes are what the plugin's script reads; keep them.
 *
 * @package Pixonaut\WooCommerce
 * @version 0.1.0
 *
 * @var string $state       `picture`, `own` or `waiting`: what the image is.
 * @var int    $picture     The drawn picture's attachment id; 0 when none is drawn.
 * @var string $picture_url The drawn picture's thumbnail URL; empty when none is drawn.
 */

defined( 'ABSPATH' ) || exit;
?>
<p class="pixonaut-picture pixonaut-picture--<?php echo esc_attr( $state ); ?>">
	<?php if ( 'own' === $state ) : ?>
		<a href="#" class="pixonaut-use-picture" data-picture="<?php echo esc_attr( (string) $picture ); ?>" data-picture-url="<?php echo esc_url( $picture_url ); ?>"><?php esc_html_e( 'Use the Pixonaut picture', 'pixonaut-for-woocommerce' ); ?></a>
		<span class="description"><?php esc_html_e( 'Your own image is shown. Pixonaut’s picture, its template on the blank, takes its place again when you remove it.', 'pixonaut-for-woocommerce' ); ?></span>
	<?php elseif ( 'picture' === $state ) : ?>
		<span class="description"><?php esc_html_e( 'Pixonaut’s picture of this product, its template on the blank. It is drawn again when the product changes. An image you set yourself is kept.', 'pixonaut-for-woocommerce' ); ?></span>
	<?php else : ?>
		<span class="description"><?php esc_html_e( 'Pixonaut’s picture of this product, its template on the blank, is set here once it is drawn.', 'pixonaut-for-woocommerce' ); ?></span>
	<?php endif; ?>
</p>
