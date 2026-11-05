<?php
/**
 * Under a personalised order line waiting for the customer's approval, on
 * the order received page and in My Account: a link to each of its proof
 * pages, where the customer approves the design, rejects it or asks for
 * changes.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/order/proof-links.php.
 *
 * @package Pixonaut\WooCommerce
 * @version 0.1.0
 *
 * @var string[] $proof_urls One proof page per design still waiting.
 * @var string   $link_text  The links' text.
 */

defined( 'ABSPATH' ) || exit;
?>
<?php foreach ( $proof_urls as $pixonaut_url ) : ?>
	<p class="pixonaut-proof-links"><a href="<?php echo esc_url( $pixonaut_url ); ?>"><?php echo esc_html( $link_text ); ?></a></p>
<?php endforeach; ?>
