<?php
/**
 * A personalised order line's picture: the customer's design on the
 * product, on the order received page, in My Account and in order emails.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/order/line-picture.php.
 *
 * @package Pixonaut\WooCommerce
 * @version 0.1.0
 *
 * @var string $src    The picture's URL.
 * @var string $srcset The picture at every width it is served at.
 * @var int    $width  The width it is shown at, in pixels.
 * @var string $alt    Alternative text.
 */

defined( 'ABSPATH' ) || exit;
?>
<img class="pixonaut-line-picture" src="<?php echo esc_url( $src ); ?>" srcset="<?php echo esc_attr( $srcset ); ?>" sizes="<?php echo esc_attr( $width . 'px' ); ?>" width="<?php echo esc_attr( (string) $width ); ?>" alt="<?php echo esc_attr( $alt ); ?>" style="display:block;height:auto;" />
