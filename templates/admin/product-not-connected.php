<?php
/**
 * The next step in the product's Pixonaut tab while the store is not connected.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/admin/product-not-connected.php.
 *
 * @package Pixonaut\WooCommerce
 * @version 0.1.0
 *
 * @var string $settings_url The connection settings.
 * @var string $docs_url     The guide on linking products.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="inline notice notice-info pixonaut-not-connected">
	<p>
		<?php esc_html_e( 'This store is not connected to Pixonaut yet, so no blank or template can be chosen here.', 'pixonaut-for-woocommerce' ); ?>
		<a href="<?php echo esc_url( $settings_url ); ?>"><?php /* translators: Link in the product's Pixonaut tab to the plugin's connection settings; here Connect is a verb. */ esc_html_e( 'Connect the store', 'pixonaut-for-woocommerce' ); ?></a>
		·
		<a href="<?php echo esc_url( $docs_url ); ?>" target="_blank" rel="noopener noreferrer"><?php /* translators: Link in the product's Pixonaut tab to the guide on linking products to Pixonaut. */ esc_html_e( 'How linking products works', 'pixonaut-for-woocommerce' ); ?></a>
	</p>
</div>
