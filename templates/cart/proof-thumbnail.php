<?php
/**
 * A personalised cart line's image: the customer's design on the product (its
 * proof alone for a product on no blank), in place of the product's.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/cart/proof-thumbnail.php.
 *
 * @package Pixonaut\WooCommerce
 * @version 0.1.0
 *
 * @var string $src    The picture's URL at the thumbnail's width.
 * @var string $srcset The picture at every width it is served at.
 * @var int    $width  The thumbnail's width in pixels.
 * @var string $alt    Alternative text.
 */

defined( 'ABSPATH' ) || exit;
?>
<img class="pixonaut-proof-thumbnail attachment-woocommerce_thumbnail" src="<?php echo esc_url( $src ); ?>" srcset="<?php echo esc_attr( $srcset ); ?>" sizes="<?php echo esc_attr( $width . 'px' ); ?>" width="<?php echo esc_attr( (string) $width ); ?>" alt="<?php echo esc_attr( $alt ); ?>" loading="lazy" decoding="async" />
