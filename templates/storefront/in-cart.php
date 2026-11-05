<?php
/**
 * An item that is in the cart, in place of the add-to-cart form on its product
 * page: its options and quantity, a button changing its design, a button to the
 * cart and a link to make another.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/storefront/in-cart.php.
 * It is not a form: nothing on this page adds to the cart.
 *
 * @package Pixonaut\WooCommerce
 * @version 0.1.0
 *
 * @var string                                  $message       The text saying the item is in the cart.
 * @var list<string>                            $details       Its options, when it has any, and its quantity.
 * @var list<array{text: string, url: string}>  $links         The button changing the design, or one per side.
 * @var string                                  $cart_url      The cart's address.
 * @var string                                  $cart_label    The cart button's text.
 * @var string                                  $another_url   The plain product page's address.
 * @var string                                  $another_label The link's text.
 * @var string                                  $button_class  The block theme's button class; empty under a classic theme.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="pixonaut-in-cart">
	<div class="pixonaut-in-cart__status" role="status">
		<span class="pixonaut-in-cart__message">
			<svg class="pixonaut-in-cart__check" aria-hidden="true" width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="M2.5 8.5l3.5 3.5 7.5-8"></path></svg><?php echo esc_html( implode( ' · ', array_merge( array( $message ), $details ) ) ); ?>
		</span>
		<?php foreach ( $links as $pixonaut_link ) : ?>
			<a class="button pixonaut-personalised__change" href="<?php echo esc_url( $pixonaut_link['url'] ); ?>"><?php echo esc_html( $pixonaut_link['text'] ); ?></a>
		<?php endforeach; ?>
	</div>
	<a class="<?php echo esc_attr( trim( 'button alt pixonaut-in-cart__view ' . $button_class ) ); ?>" href="<?php echo esc_url( $cart_url ); ?>"><?php echo esc_html( $cart_label ); ?></a>
	<a class="pixonaut-in-cart__another" href="<?php echo esc_url( $another_url ); ?>"><?php echo esc_html( $another_label ); ?></a>
</div>
