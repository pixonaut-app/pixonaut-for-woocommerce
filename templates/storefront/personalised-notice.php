<?php
/**
 * The soft note above the add-to-cart row saying this is the customer's
 * design, with a button changing it.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/storefront/personalised-notice.php.
 * The hidden field posts the kept design's key with the add-to-cart form, which
 * is how the design joins the cart; on a variable product the storefront script
 * shows the note only while the form holds its variation, by `data-pixonaut-design`.
 *
 * @package Pixonaut\WooCommerce
 * @version 0.1.0
 *
 * @var string                                  $design   Its product and variation, for the storefront script.
 * @var string                                  $kept_key The kept design's key.
 * @var string                                  $field    The field posting that key.
 * @var bool                                    $shown    Whether it shows when the page opens.
 * @var string                                  $message  The note's text.
 * @var list<array{text: string, url: string}>  $links    The button changing the design, or one per side.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="pixonaut-personalised" role="status" data-pixonaut-design="<?php echo esc_attr( $design ); ?>"<?php echo $shown ? '' : ' hidden'; ?>>
	<?php // A field of an extension's makes the Add to Cart with Options block post its form, which the design joins the cart through. ?>
	<input type="hidden" name="<?php echo esc_attr( $field ); ?>" value="<?php echo esc_attr( $kept_key ); ?>" />
	<span class="pixonaut-personalised__message"><?php echo esc_html( $message ); ?></span>
	<?php foreach ( $links as $pixonaut_link ) : ?>
		<a class="button pixonaut-personalised__change" href="<?php echo esc_url( $pixonaut_link['url'] ); ?>"><?php echo esc_html( $pixonaut_link['text'] ); ?></a>
	<?php endforeach; ?>
</div>
