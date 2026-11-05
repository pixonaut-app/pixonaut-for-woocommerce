<?php
/**
 * An admin notice raised by Pixonaut for WooCommerce.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/admin/notice.php.
 *
 * @package Pixonaut\WooCommerce
 * @version 0.1.0
 *
 * @var string $message     Notice text; may hold inline markup allowed in posts.
 * @var string $type        error, warning, info or success.
 * @var string $dismiss_url Link that dismisses the notice; empty when it cannot be dismissed.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="notice notice-<?php echo esc_attr( $type ); ?> pixonaut-notice">
	<p><?php echo wp_kses_post( $message ); ?></p>
	<?php if ( '' !== $dismiss_url ) : ?>
		<p><a href="<?php echo esc_url( $dismiss_url ); ?>"><?php /* translators: Link that hides an admin notice, read by the merchant. */ esc_html_e( 'Dismiss', 'pixonaut-for-woocommerce' ); ?></a></p>
	<?php endif; ?>
</div>
