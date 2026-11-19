<?php
/**
 * Email to the store naming prices whose margin fell below its lowest margin.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/emails/margin-floor.php.
 *
 * @package Pixonaut\WooCommerce
 * @version 1.29.0
 *
 * @var array[]  $rows               Each price: `product`, `variation` (empty for a simple product), `price`, `cost`, `margin` and `edit_url`, as text.
 * @var string   $floor              The lowest margin, such as 25.0 %.
 * @var int      $more               How many more prices fell below the lowest margin than the email names; they are named in a later one.
 * @var string   $list_url           The products list, filtered to the products with a price below the lowest margin.
 * @var string   $email_heading      The heading.
 * @var string   $additional_content Text the store added in the email's settings.
 * @var WC_Email $email              The email.
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WooCommerce.Commenting.CommentHooks.MissingHookComment -- WooCommerce's own email hook.
?>

<p>
	<?php
	printf(
		/* translators: %s: the lowest margin the store accepts, such as 25.0 %. Email to the merchant. */
		esc_html__( 'The margin of these prices fell below your lowest margin of %s, against what the printshop charges for the product and its shipping, excluding VAT:', 'pixonaut-for-woocommerce' ),
		esc_html( $floor )
	);
	?>
</p>
<table class="td" cellspacing="0" cellpadding="6" border="1" style="width: 100%; margin-bottom: 16px;">
	<thead>
		<tr>
			<th class="td" scope="col" style="text-align: left;"><?php /* translators: Column heading in the email to the merchant about prices below the lowest margin. */ esc_html_e( 'Product', 'pixonaut-for-woocommerce' ); ?></th>
			<th class="td" scope="col" style="text-align: left;"><?php /* translators: Column heading in the email to the merchant about prices below the lowest margin. */ esc_html_e( 'Price', 'pixonaut-for-woocommerce' ); ?></th>
			<th class="td" scope="col" style="text-align: left;"><?php /* translators: Column heading in the email to the merchant about prices below the lowest margin: what the printshop charges, with shipping. */ esc_html_e( 'Cost', 'pixonaut-for-woocommerce' ); ?></th>
			<th class="td" scope="col" style="text-align: left;"><?php /* translators: Column heading in the email to the merchant about prices below the lowest margin. */ esc_html_e( 'Margin', 'pixonaut-for-woocommerce' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( $rows as $pixonaut_row ) : ?>
			<tr>
				<td class="td" style="text-align: left;">
					<a href="<?php echo esc_url( $pixonaut_row['edit_url'] ); ?>"><?php echo esc_html( $pixonaut_row['product'] ); ?></a>
					<?php if ( '' !== $pixonaut_row['variation'] ) : ?>
						<br /><?php echo esc_html( $pixonaut_row['variation'] ); ?>
					<?php endif; ?>
				</td>
				<td class="td" style="text-align: left;"><?php echo esc_html( $pixonaut_row['price'] ); ?></td>
				<td class="td" style="text-align: left;"><?php echo esc_html( $pixonaut_row['cost'] ); ?></td>
				<td class="td" style="text-align: left;"><?php echo esc_html( $pixonaut_row['margin'] ); ?></td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>
<?php if ( $more > 0 ) : ?>
	<p>
		<?php
		echo esc_html(
			sprintf(
				/* translators: %d: how many more prices. Email to the merchant about prices below the lowest margin, after the table of those it names. */
				_n( '%d more price fell below your lowest margin and will be named in a later email.', '%d more prices fell below your lowest margin and will be named in later emails.', $more, 'pixonaut-for-woocommerce' ),
				$more
			)
		);
		?>
		<a href="<?php echo esc_url( $list_url ); ?>"><?php /* translators: Link in the email to the merchant to the products list, filtered to the products with a price below the lowest margin. */ esc_html_e( 'Show the products', 'pixonaut-for-woocommerce' ); ?></a>
	</p>
<?php endif; ?>
<p><?php esc_html_e( 'Each price is named once, until its margin is back at your lowest margin and falls below it again.', 'pixonaut-for-woocommerce' ); ?></p>

<?php
if ( '' !== $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WooCommerce.Commenting.CommentHooks.MissingHookComment -- WooCommerce's own email hook.
