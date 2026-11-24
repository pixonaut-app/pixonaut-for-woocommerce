<?php
/**
 * Email to the customer about a parcel of personalised items that shipped before the rest of the order.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/emails/part-shipped.php.
 *
 * @package Pixonaut\WooCommerce
 * @version 1.31.0
 *
 * @var WC_Order|null             $order              The order.
 * @var array<string, mixed>|null $parcel             What `emails/parcel.php` shows of the parcel that shipped.
 * @var string                    $intro              The text above the parcel.
 * @var string                    $email_heading      The heading.
 * @var string                    $additional_content Text the store added in the email's settings.
 * @var WC_Email                  $email              The email.
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
