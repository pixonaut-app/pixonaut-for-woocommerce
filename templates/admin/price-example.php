<?php
/**
 * The pricing rule's example under its fields on the settings page's Prices tab.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/admin/price-example.php.
 *
 * @package Pixonaut\WooCommerce
 * @version 1.23.0
 *
 * @var list<array{amount: string, cost: string, price: string, contribution: string, margin: string}> $rows Each example cost as a decimal and as shown, the price the rule sets for it, and the contribution and margin that leaves; empty where the rule sets no price.
 * @var array<string, string> $parts  The id of each part's field, by part, for the settings script.
 * @var float                 $vat    The tax rate in prices as entered, in percent; 0 when they are entered without tax.
 * @var array<string, mixed>  $format How the store shows money and a margin, for the settings script.
 */

defined( 'ABSPATH' ) || exit;

?>
<tr class="pixonaut-price-example-row">
	<th scope="row" class="titledesc"><?php /* translators: Title of the example on the Prices tab of the Pixonaut settings page: what the pricing rule prices a few costs at. */ esc_html_e( 'Example', 'pixonaut-for-woocommerce' ); ?></th>
	<td class="forminp">
		<table class="widefat striped pixonaut-price-example" data-pixonaut-price-example data-parts="<?php echo esc_attr( (string) wp_json_encode( $parts ) ); ?>" data-vat="<?php echo esc_attr( (string) $vat ); ?>" data-format="<?php echo esc_attr( (string) wp_json_encode( $format ) ); ?>">
			<thead>
				<tr>
					<th scope="col"><?php /* translators: Column of the pricing rule's example on the settings page: what the printshop charges for a product and its shipping. */ esc_html_e( 'Cost', 'pixonaut-for-woocommerce' ); ?></th>
					<th scope="col"><?php /* translators: Column of the pricing rule's example on the settings page: the price the rule sets. */ esc_html_e( 'Price', 'pixonaut-for-woocommerce' ); ?></th>
					<th scope="col"><?php /* translators: Column of the pricing rule's example on the settings page: the amount of money the price leaves after the cost, excluding VAT. */ esc_html_e( 'Contribution', 'pixonaut-for-woocommerce' ); ?></th>
					<th scope="col"><?php /* translators: Column of the pricing rule's example on the settings page: the share of the price, excluding VAT, left after the cost. */ esc_html_e( 'Margin', 'pixonaut-for-woocommerce' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $pixonaut_row ) : ?>
					<tr data-cost="<?php echo esc_attr( $pixonaut_row['amount'] ); ?>">
						<td><?php echo esc_html( $pixonaut_row['cost'] ); ?></td>
						<td data-shows="price"><?php echo esc_html( '' === $pixonaut_row['price'] ? '—' : $pixonaut_row['price'] ); ?></td>
						<td data-shows="contribution"><?php echo esc_html( '' === $pixonaut_row['contribution'] ? '—' : $pixonaut_row['contribution'] ); ?></td>
						<td data-shows="margin"><?php echo esc_html( '' === $pixonaut_row['margin'] ? '—' : $pixonaut_row['margin'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description">
			<?php
			echo esc_html(
				$vat > 0
					/* translators: %s: a tax rate as a number, such as 25. Under the pricing rule's example on the settings page. */
					? sprintf( __( 'What the rule prices these costs at, as you change the fields above. Prices include %s %% VAT; contribution and margin are worked out without it.', 'pixonaut-for-woocommerce' ), number_format_i18n( $vat, floor( $vat ) === $vat ? 0 : 2 ) )
					: __( 'What the rule prices these costs at, as you change the fields above.', 'pixonaut-for-woocommerce' )
			);
			?>
		</p>
	</td>
</tr>
