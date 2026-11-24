<?php
/**
 * Email to the customer about tracking added or corrected after personalised items shipped.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/emails/tracking-changed.php.
 *
 * @package Pixonaut\WooCommerce
 * @version 1.31.0
 *
 * @var WC_Order|null               $order              The order.
 * @var list<WC_Order_Item_Product> $items              The items whose tracking changed, each showing its tracking.
 * @var array<string, mixed>|null   $parcel             When the email is about a parcel: what `emails/parcel.php` shows of it, in place of the items.
 * @var string                      $intro              The text above the items.
 * @var string                      $email_heading      The heading.
 * @var string                      $additional_content Text the store added in the email's settings.
 * @var WC_Email                    $email              The email.
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WooCommerce.Commenting.CommentHooks.MissingHookComment -- WooCommerce's own email hook.
?>

<p><?php echo esc_html( $intro ); ?></p>

<?php
if ( null !== $parcel ) {
	wc_get_template( 'emails/parcel.php', $parcel, 'woocommerce/pixonaut/', $email->template_base );
}
?>

<?php
if ( '' !== $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WooCommerce.Commenting.CommentHooks.MissingHookComment -- WooCommerce's own email hook.
