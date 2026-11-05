<?php
/**
 * The warning under a major update of Pixonaut for WooCommerce in the plugins list.
 *
 * Printed inside the update row's paragraph, so it closes that paragraph and
 * opens an empty one for the markup that follows.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/admin/upgrade-notice.php.
 *
 * @package Pixonaut\WooCommerce
 * @version 0.1.0
 *
 * @var string $message        The warning; may hold inline markup allowed in posts.
 * @var string $upgrade_notice The release's own upgrade notice; may be empty.
 */

defined( 'ABSPATH' ) || exit;
?>
</p>
<div class="pixonaut-upgrade-notice">
	<p><strong><?php echo wp_kses_post( $message ); ?></strong></p>
	<?php if ( '' !== $upgrade_notice ) : ?>
		<p><?php echo wp_kses_post( $upgrade_notice ); ?></p>
	<?php endif; ?>
</div>
<p class="hidden">
