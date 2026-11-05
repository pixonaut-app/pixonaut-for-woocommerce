<?php
/**
 * Regenerate now's dialog, on the products list and the product screen: the
 * confirmation before the pictures are drawn, then each product's progress
 * while they are.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/admin/regenerate-now.php.
 * The id, classes and `data-` attributes are what the plugin's script reads; keep them.
 *
 * @package Pixonaut\WooCommerce
 * @version 0.1.0
 *
 * @var string $root_id    The dialog's id, which the script finds it by.
 * @var string $start_url  The route that queues the products' pictures and lists those to draw.
 * @var string $draw_url   The route that draws one product's or variation's pictures.
 * @var string $rest_nonce Nonce for the REST routes.
 */

defined( 'ABSPATH' ) || exit;

$pixonaut_texts = array(
	/* translators: %1$s: pictures drawn so far, %2$s: pictures to draw, of one product. Regenerate now's progress. */
	'pictures'  => __( '%1$s of %2$s pictures', 'pixonaut-for-woocommerce' ),
	/* translators: %1$s: products done so far, %2$s: products selected. Regenerate now's progress. */
	'products'  => __( '%1$s of %2$s products done', 'pixonaut-for-woocommerce' ),
	/* translators: Regenerate now's progress: the product's pictures are not drawn yet. */
	'waiting'   => __( 'Waiting', 'pixonaut-for-woocommerce' ),
	/* translators: Regenerate now's progress: the product's pictures are drawn. */
	'drawn'     => __( 'Done', 'pixonaut-for-woocommerce' ),
	'failed'    => __( 'Pixonaut could not draw some pictures; they are tried again later.', 'pixonaut-for-woocommerce' ),
	'kept'      => __( 'Your own image is kept.', 'pixonaut-for-woocommerce' ),
	'drawing'   => __( 'Being drawn in the background.', 'pixonaut-for-woocommerce' ),
	/* translators: Regenerate now's progress: the request drawing this product's picture failed. */
	'unreached' => __( 'Could not be drawn here. It stays queued for the background.', 'pixonaut-for-woocommerce' ),
	'done'      => __( 'The product images are regenerated. Close this to see them.', 'pixonaut-for-woocommerce' ),
	'partial'   => __( 'Some product images could not be regenerated. See each product above.', 'pixonaut-for-woocommerce' ),
	'stopped'   => __( 'Stopped. The products not drawn yet are drawn in the background.', 'pixonaut-for-woocommerce' ),
	'error'     => __( 'The product images could not be regenerated. Try again in a moment.', 'pixonaut-for-woocommerce' ),
	'none'      => __( 'None of the selected products shows a Pixonaut picture, so no product image was regenerated.', 'pixonaut-for-woocommerce' ),
	/* translators: Heading of Regenerate now's dialog while the pictures are drawn. */
	'running'   => __( 'Regenerating product images', 'pixonaut-for-woocommerce' ),
);
?>
<dialog id="<?php echo esc_attr( $root_id ); ?>" class="pixonaut-now" aria-labelledby="<?php echo esc_attr( $root_id ); ?>-title"
	data-start-url="<?php echo esc_url( $start_url ); ?>"
	data-draw-url="<?php echo esc_url( $draw_url ); ?>"
	data-rest-nonce="<?php echo esc_attr( $rest_nonce ); ?>"
	<?php foreach ( $pixonaut_texts as $pixonaut_key => $pixonaut_text ) : ?>
		data-<?php echo esc_attr( $pixonaut_key ); ?>-text="<?php echo esc_attr( $pixonaut_text ); ?>"
	<?php endforeach; ?>
	>
	<h2 id="<?php echo esc_attr( $root_id ); ?>-title" class="pixonaut-now__title"><?php /* translators: Heading of the confirmation before Regenerate now draws the product images. */ esc_html_e( 'Regenerate now?', 'pixonaut-for-woocommerce' ); ?></h2>
	<div class="pixonaut-now__confirm">
		<p><?php esc_html_e( 'The product images are drawn right away, in this tab, one product after another. The site is busier while it runs.', 'pixonaut-for-woocommerce' ); ?></p>
		<p><?php esc_html_e( 'Keep this tab open until it finishes. Closing it or pressing Stop leaves the rest to the background queue.', 'pixonaut-for-woocommerce' ); ?></p>
	</div>
	<div class="pixonaut-now__progress" hidden>
		<p class="pixonaut-now__summary" role="status" aria-live="polite"></p>
		<progress class="pixonaut-now__bar" value="0" max="1"></progress>
		<ul class="pixonaut-now__products"></ul>
	</div>
	<div class="pixonaut-now__actions">
		<button type="button" class="button pixonaut-now__cancel"><?php /* translators: Button in Regenerate now's confirmation: closes it without drawing anything. */ esc_html_e( 'Cancel', 'pixonaut-for-woocommerce' ); ?></button>
		<button type="button" class="button button-primary pixonaut-now__start"><?php /* translators: Button confirming Regenerate now: draws the product images right away. */ esc_html_e( 'Regenerate now', 'pixonaut-for-woocommerce' ); ?></button>
		<button type="button" class="button pixonaut-now__stop" hidden><?php /* translators: Button stopping Regenerate now; the products not drawn yet are left to the background queue. */ esc_html_e( 'Stop', 'pixonaut-for-woocommerce' ); ?></button>
		<button type="button" class="button button-primary pixonaut-now__close" hidden><?php /* translators: Button closing Regenerate now's dialog once it finished or was stopped. */ esc_html_e( 'Close', 'pixonaut-for-woocommerce' ); ?></button>
	</div>
</dialog>
