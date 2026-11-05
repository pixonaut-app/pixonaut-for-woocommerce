<?php
/**
 * The Personalise button, in a personalised product's add-to-cart form,
 * directly before WooCommerce's add-to-cart button and in its classes, in its place.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/storefront/button.php.
 * The button posts the add-to-cart form with `<field>` set to the product; the
 * plugin then sends the customer to Pixonaut's designer, and the product page
 * shows their design when they come back. When keeping it failed for a reason
 * that may pass (Pixonaut unreachable), or they came back in a browser or session
 * that did not open the designer, a second button, `.pixonaut-personalise__retry`,
 * posts `<retry_field>` to keep the design; it shows only beside the Personalise
 * button, never beside Add to cart. Keep the button a direct child of the form's row,
 * as WooCommerce's is: themes and WooCommerce's own block styles lay out
 * `form.cart > .single_add_to_cart_button`. The empty `pixonaut-personalise`
 * element is never drawn; while it is not `hidden` it hides the add-to-cart button.
 *
 * On a variable product, `data-pixonaut-variations` says what the button does
 * for each variation, by id: `personalised` shows the `.pixonaut-personalise__button`s,
 * `unavailable` shows `.pixonaut-personalise__unavailable`, and `plain` and
 * `designed` hide all of them and mark `.pixonaut-personalise` `hidden`, so the
 * variation is added to the cart, as it is or with the design the page shows. The
 * storefront script switches them as the customer chooses; all are printed, and
 * those not shown are `hidden`. `data-pixonaut-opens-designed` marks a page that opens on a
 * designed variation, where the button is `hidden` until another is chosen.
 *
 * `data-pixonaut-prices` holds each variation's price HTML by id; while it is
 * there the script shows the chosen variation's price in the theme's price
 * target or the summary price, and the variation price WooCommerce prints in
 * the form is left out. `data-pixonaut-product-price` holds the product's own
 * price when the page printed its opening variation's instead, and
 * `data-pixonaut-opening-choice` the attribute values that price is for, by field
 * name, which the script keeps it for while the form holds them; left out, it
 * shows the product's own as soon as the form holds no variation.
 *
 * @package Pixonaut\WooCommerce
 * @version 0.1.0
 *
 * @var int                $product_id  The product.
 * @var string             $field       The button's name.
 * @var bool               $shown       Whether the button shows before a variation is chosen; false on a variable product only its variations personalise.
 * @var bool               $designed    Whether the page shows the customer's design for what the form opens on, so Add to cart shows instead.
 * @var bool               $available   Whether the product can be personalised now.
 * @var array<int, string> $variations  On a variable product: `personalised`, `unavailable` or `plain`, by variation id; empty otherwise.
 * @var array<int, string> $prices      On a variable product: each variation's price HTML, by variation id; empty otherwise.
 * @var string             $product_price The product's own price HTML when the page prints its opening variation's; empty otherwise.
 * @var array<string, string> $opening_choice The attribute values the page prints the opening variation's price for, by field name; left out or empty when it prints the product's own.
 * @var string             $first_side  The key of the product's first side, for `pixonaut:price`; empty when only its variations personalise.
 * @var string             $button_class The block theme's button class; empty under a classic theme.
 * @var string             $label       The button's text.
 * @var string             $unavailable Text shown when personalisation is unavailable.
 * @var string             $retry_field The retry button's name.
 * @var string             $retry_state The design waiting to be added again, or empty.
 * @var string             $retry_label The retry button's text.
 */

defined( 'ABSPATH' ) || exit;

$pixonaut_switches = array() !== $variations;
// Buttons that start hidden must not be the form's default button: Enter in the quantity field would submit them. The script makes them submit when shown.
$pixonaut_showing = $shown && ! $designed;
$pixonaut_type    = ( $pixonaut_showing && $available ) ? 'submit' : 'button';
// Never beside Add to cart: Enter in the quantity field would submit it, in place of the design the page shows.
$pixonaut_retry_shown = $available && $pixonaut_showing;
?>
<span class="pixonaut-personalise"<?php echo $pixonaut_switches ? ' data-pixonaut-variations="' . esc_attr( (string) wp_json_encode( (object) $variations ) ) . '" data-pixonaut-prices="' . esc_attr( (string) wp_json_encode( (object) $prices ) ) . '" data-pixonaut-product="' . esc_attr( (string) $product_id ) . '" data-pixonaut-side="' . esc_attr( $first_side ) . '"' : ''; ?><?php echo '' === $product_price ? '' : ' data-pixonaut-product-price="' . esc_attr( $product_price ) . '"'; ?><?php echo array() === ( $opening_choice ?? array() ) ? '' : ' data-pixonaut-opening-choice="' . esc_attr( (string) wp_json_encode( $opening_choice ) ) . '"'; ?><?php echo $designed && $shown ? ' data-pixonaut-opens-designed' : ''; ?><?php echo $pixonaut_showing ? '' : ' hidden'; ?>></span>
<?php if ( $available || $pixonaut_switches ) : ?>
	<?php if ( '' !== $retry_state ) : ?>
		<button type="<?php echo $pixonaut_retry_shown ? 'submit' : 'button'; ?>" class="<?php echo esc_attr( trim( 'button alt pixonaut-personalise__button pixonaut-personalise__retry ' . $button_class ) ); ?>" name="<?php echo esc_attr( $retry_field ); ?>" value="<?php echo esc_attr( $retry_state ); ?>"<?php echo $pixonaut_retry_shown ? '' : ' hidden'; ?>><?php echo esc_html( $retry_label ); ?></button>
	<?php endif; ?>
	<button type="<?php echo esc_attr( $pixonaut_type ); ?>" class="<?php echo esc_attr( trim( 'single_add_to_cart_button button alt pixonaut-personalise__button ' . $button_class ) ); ?>" name="<?php echo esc_attr( $field ); ?>" value="<?php echo esc_attr( (string) $product_id ); ?>"<?php echo $pixonaut_showing && $available ? '' : ' hidden'; ?>><?php echo esc_html( $label ); ?></button>
<?php endif; ?>
<?php if ( ! $available || $pixonaut_switches ) : ?>
	<p class="pixonaut-personalise__unavailable" role="alert"<?php echo $pixonaut_showing && ! $available ? '' : ' hidden'; ?>><?php echo esc_html( $unavailable ); ?></p>
<?php endif; ?>
