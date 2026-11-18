<?php
/**
 * Prices, a section of the Pixonaut tab of a product printed on a blank:
 * what the printshop charges for each variation, its price and margin, the
 * parts of the store's pricing rule the product changes, whether its prices
 * change by themselves, and the buttons
 * that read the costs again and apply the rule.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/admin/product-prices.php.
 * Field names, classes and the `data-` attributes are what the plugin reads; keep them.
 *
 * @package Pixonaut\WooCommerce
 * @version 1.25.0
 *
 * @var string                $url        The product's prices route, which the buttons post to.
 * @var string                $field      The posted field holding the parts of the rule the product changes.
 * @var string                $changes_field   The posted field holding whether the product's prices change by themselves.
 * @var string                $changes         What the product is set to: `store`, `always` or `never`.
 * @var array<string, string> $changes_options What it can be set to, each with what the store manager reads, by value.
 * @var bool                  $open       Whether the section is open.
 * @var bool                  $rule_set   Whether any price has a target margin to follow, from the store, an attribute value, the product or a variation.
 * @var array<string, string> $own_parts  The parts of the rule the product changes, by part; empty for the store's.
 * @var array<string, string> $store      The store's rule, by part.
 * @var array<string, string> $parts      Each part's label, by part, in the order shown.
 * @var list<array{id: int, label: string, cost: string, cost_parts: string, price: string, margin: string, contribution: string, suggested: string, standing: string, flag: string|null, rule_at: string, changes: string, source: string, source_parts: string, conflicts: list<string>}> $rows Each price: its product or variation, what it is called, its cost, the cost's parts, its price, its margin, what it leaves over the cost excluding VAT, the price the rule suggests, how it stands to the rule (`own`, `follows`, `by_hand`), why it needs the store manager, when the rule last set it, whether its price changes by itself (`always` or `never` on a variation that answers its product, else `product` or `store`), the levels its rule comes from, where each part comes from when they come from several (one per line), and what two of its attribute values both set.
 * @var bool                  $unread     Whether no cost was read yet: the script reads them when the section opens.
 * @var int                   $flagged    Prices that follow the rule and need the store manager.
 * @var int                   $following  Prices the rule was applied to.
 * @var bool                  $variable   Whether the product has variations.
 * @var string                $settings   The store's pricing rule on the Pixonaut settings page.
 * @var bool                  $tax_note   Whether the store enters prices including tax.
 * @var array<string, string> $flag_texts What each flag says, by flag.
 */

defined( 'ABSPATH' ) || exit;

if ( $flagged > 0 ) {
	/* translators: %d: how many prices. Summary of the Prices section of the product edit page. */
	$pixonaut_summary = sprintf( _n( '%d price to review', '%d prices to review', $flagged, 'pixonaut-for-woocommerce' ), $flagged );
} elseif ( $following > 0 ) {
	$pixonaut_summary = __( 'Following the pricing rule', 'pixonaut-for-woocommerce' );
} elseif ( $rule_set ) {
	$pixonaut_summary = __( 'Rule not applied yet', 'pixonaut-for-woocommerce' );
} else {
	$pixonaut_summary = __( 'No target margin set', 'pixonaut-for-woocommerce' );
}
?>
<details class="pixonaut-group pixonaut-disclosure pixonaut-prices pixonaut-with-blank" data-disclosure="prices" data-url="<?php echo esc_url( $url ); ?>" <?php echo $unread ? 'data-unread' : ''; ?> <?php echo $variable ? '' : 'data-simple'; ?> <?php echo $open ? 'open' : ''; ?>
	data-failed-text="<?php esc_attr_e( 'Pixonaut could not be reached to read the printshop’s costs. Try again in a moment.', 'pixonaut-for-woocommerce' ); ?>">
	<summary>
		<strong><?php /* translators: Heading of a closed section in the Pixonaut tab of the product edit page: what the printshop charges for the product, and the prices that follow from it. */ echo esc_html_x( 'Prices', 'product section', 'pixonaut-for-woocommerce' ); ?></strong>
		<span class="pixonaut-disclosure__summary"><?php echo esc_html( $pixonaut_summary ); ?></span>
	</summary>
	<p class="pixonaut-group__intro">
		<?php esc_html_e( 'What the printshop charges for the product and its shipping, and the margin and contribution each price leaves. Apply sets every price to the step nearest the target margin plus the fixed amount; from then on the prices follow the rule.', 'pixonaut-for-woocommerce' ); ?>
		<?php if ( $tax_note ) : ?>
			<?php esc_html_e( 'Margins, contributions and the fixed amount are worked out on the price excluding VAT.', 'pixonaut-for-woocommerce' ); ?>
		<?php endif; ?>
	</p>
	<fieldset class="pixonaut-prices__rule">
		<legend class="screen-reader-text"><?php esc_html_e( 'Pricing rule for this product', 'pixonaut-for-woocommerce' ); ?></legend>
		<?php foreach ( $parts as $pixonaut_part => $pixonaut_label ) : ?>
			<?php $pixonaut_id = 'pixonaut_price_rule_' . $pixonaut_part; ?>
			<p class="pixonaut-prices__part">
				<label for="<?php echo esc_attr( $pixonaut_id ); ?>"><?php echo esc_html( $pixonaut_label ); ?></label>
				<input type="text" inputmode="decimal" id="<?php echo esc_attr( $pixonaut_id ); ?>" class="short pixonaut-prices__input" name="<?php echo esc_attr( $field . '[' . $pixonaut_part . ']' ); ?>" data-part="<?php echo esc_attr( $pixonaut_part ); ?>" value="<?php echo esc_attr( $own_parts[ $pixonaut_part ] ?? '' ); ?>" placeholder="<?php echo esc_attr( '' === ( $store[ $pixonaut_part ] ?? '' ) ? _x( 'None', 'no value set for a part of the pricing rule', 'pixonaut-for-woocommerce' ) : $store[ $pixonaut_part ] ); ?>" />
			</p>
		<?php endforeach; ?>
		<p class="pixonaut-prices__part pixonaut-prices__changes-part">
			<label for="pixonaut_price_automatic"><?php /* translators: Label of a field in the Prices section of the product edit page: whether this product's prices change by themselves when a printshop's cost changes. */ esc_html_e( 'Automatic price changes', 'pixonaut-for-woocommerce' ); ?></label>
			<select id="pixonaut_price_automatic" class="pixonaut-prices__changes" name="<?php echo esc_attr( $changes_field ); ?>">
				<?php foreach ( $changes_options as $pixonaut_value => $pixonaut_label ) : ?>
					<option value="<?php echo esc_attr( $pixonaut_value ); ?>" <?php selected( $changes, $pixonaut_value ); ?>><?php echo esc_html( $pixonaut_label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p class="description pixonaut-prices__rule-hint">
			<?php echo esc_html( $variable ? __( 'An empty field follows the store’s pricing rule, shown in grey. A value of a global attribute may change a part for its variations (Products → Attributes), and a variation may change any part in its own price rule; the most specific wins.', 'pixonaut-for-woocommerce' ) : __( 'An empty field follows the store’s pricing rule, shown in grey.', 'pixonaut-for-woocommerce' ) ); ?>
			<a href="<?php echo esc_url( $settings ); ?>"><?php /* translators: Link from the Prices section of the product edit page to the pricing rule on the Pixonaut settings page. */ esc_html_e( 'Store’s pricing rule', 'pixonaut-for-woocommerce' ); ?></a>
		</p>
	</fieldset>
	<p class="pixonaut-prices__actions">
		<button type="button" class="button button-primary pixonaut-prices__apply" data-action="apply"><?php /* translators: Button in the Prices section of the product edit page: sets every price of the product to the pricing rule's price. */ echo esc_html( $variable ? __( 'Apply to all prices', 'pixonaut-for-woocommerce' ) : __( 'Apply to the price', 'pixonaut-for-woocommerce' ) ); ?></button>
		<button type="button" class="button pixonaut-prices__refresh" data-action="refresh"><?php /* translators: Button in the Prices section of the product edit page: reads what the printshop charges again. */ esc_html_e( 'Refresh costs', 'pixonaut-for-woocommerce' ); ?></button>
		<span class="spinner pixonaut-prices__spinner"></span>
		<span class="pixonaut-prices__status" role="status" aria-live="polite"></span>
	</p>
	<?php if ( ! $rule_set ) : ?>
		<p class="description pixonaut-prices__no-rule"><?php esc_html_e( 'Enter a target margin here, or on the Pixonaut settings page, to price by the rule.', 'pixonaut-for-woocommerce' ); ?></p>
	<?php endif; ?>
	<div class="pixonaut-prices__scroll">
		<table class="widefat striped pixonaut-prices__table">
			<thead>
				<tr>
					<th scope="col"><?php echo esc_html( $variable ? __( 'Variation', 'pixonaut-for-woocommerce' ) : __( 'Product', 'pixonaut-for-woocommerce' ) ); ?></th>
					<th scope="col" class="pixonaut-prices__number"><?php /* translators: Column of the Prices table of the product edit page: what the printshop charges for the product and its shipping. */ esc_html_e( 'Cost', 'pixonaut-for-woocommerce' ); ?></th>
					<th scope="col" class="pixonaut-prices__number"><?php /* translators: Column of the Prices table of the product edit page: the regular price in the store. */ esc_html_e( 'Price', 'pixonaut-for-woocommerce' ); ?></th>
					<th scope="col" class="pixonaut-prices__number"><?php /* translators: Column of the Prices table of the product edit page: the share of the price left after the cost. */ esc_html_e( 'Margin', 'pixonaut-for-woocommerce' ); ?></th>
					<th scope="col" class="pixonaut-prices__number"><?php /* translators: Column of the Prices table of the product edit page: the amount of money the price leaves after the cost, excluding VAT. */ esc_html_e( 'Contribution', 'pixonaut-for-woocommerce' ); ?></th>
					<th scope="col"><?php /* translators: Column of the Prices table of the product edit page: how the price stands to the pricing rule, and what the rule suggests. */ esc_html_e( 'Pricing rule', 'pixonaut-for-woocommerce' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $pixonaut_row ) : ?>
					<?php $pixonaut_flag = null === $pixonaut_row['flag'] ? '' : ( $flag_texts[ $pixonaut_row['flag'] ] ?? '' ); ?>
					<tr class="pixonaut-prices__row<?php echo '' === $pixonaut_flag ? '' : ' is-flagged'; ?>" data-item="<?php echo esc_attr( (string) $pixonaut_row['id'] ); ?>" data-standing="<?php echo esc_attr( $pixonaut_row['standing'] ); ?>">
						<th scope="row" class="pixonaut-prices__name"><?php echo esc_html( $pixonaut_row['label'] ); ?></th>
						<td class="pixonaut-prices__number" data-label="<?php esc_attr_e( 'Cost', 'pixonaut-for-woocommerce' ); ?>">
							<?php echo esc_html( '' === $pixonaut_row['cost'] ? '—' : $pixonaut_row['cost'] ); ?>
							<?php if ( '' !== $pixonaut_row['cost_parts'] ) : ?>
								<span class="description pixonaut-prices__parts"><?php echo esc_html( $pixonaut_row['cost_parts'] ); ?></span>
							<?php endif; ?>
						</td>
						<td class="pixonaut-prices__number pixonaut-prices__price" data-label="<?php esc_attr_e( 'Price', 'pixonaut-for-woocommerce' ); ?>"><?php echo esc_html( '' === $pixonaut_row['price'] ? '—' : $pixonaut_row['price'] ); ?></td>
						<td class="pixonaut-prices__number" data-label="<?php esc_attr_e( 'Margin', 'pixonaut-for-woocommerce' ); ?>"><?php echo esc_html( '' === $pixonaut_row['margin'] ? '—' : $pixonaut_row['margin'] ); ?></td>
						<td class="pixonaut-prices__number" data-label="<?php esc_attr_e( 'Contribution', 'pixonaut-for-woocommerce' ); ?>"><?php echo esc_html( '' === $pixonaut_row['contribution'] ? '—' : $pixonaut_row['contribution'] ); ?></td>
						<td class="pixonaut-prices__rule-cell" data-label="<?php esc_attr_e( 'Pricing rule', 'pixonaut-for-woocommerce' ); ?>">
							<?php if ( \Pixonaut\WooCommerce\Pricing\PriceState::BY_HAND === $pixonaut_row['standing'] ) : ?>
								<span class="pixonaut-prices__standing"><?php /* translators: In the Prices table of the product edit page: the store manager changed this price after the pricing rule set it, so the rule leaves it alone. */ esc_html_e( 'Edited by hand: kept', 'pixonaut-for-woocommerce' ); ?></span>
								<?php if ( $rule_set ) : ?>
									<button type="button" class="button-link pixonaut-prices__follow" data-action="follow" data-item="<?php echo esc_attr( (string) $pixonaut_row['id'] ); ?>"><?php /* translators: Button on a price edited by hand, in the Prices table of the product edit page: the pricing rule sets the price again from now on. */ esc_html_e( 'Follow the rule', 'pixonaut-for-woocommerce' ); ?></button>
								<?php endif; ?>
							<?php elseif ( '' !== $pixonaut_flag ) : ?>
								<span class="pixonaut-prices__flag"><?php echo esc_html( $pixonaut_flag ); ?></span>
							<?php elseif ( \Pixonaut\WooCommerce\Pricing\PriceState::FOLLOWS === $pixonaut_row['standing'] ) : ?>
								<span class="pixonaut-prices__standing">
									<?php
									echo esc_html(
										'' === $pixonaut_row['rule_at']
											? __( 'Follows the rule', 'pixonaut-for-woocommerce' )
											/* translators: %s: a date. In the Prices table of the product edit page: the pricing rule set this price on that day. */
											: sprintf( __( 'Follows the rule, set %s', 'pixonaut-for-woocommerce' ), $pixonaut_row['rule_at'] )
									);
									?>
								</span>
							<?php endif; ?>
							<?php if ( $variable && \Pixonaut\WooCommerce\Pricing\PriceChanges::ALWAYS === $pixonaut_row['changes'] ) : ?>
								<span class="pixonaut-prices__changes-note"><?php /* translators: In the Prices table of the product edit page: this variation's price changes by itself when its cost changes, whatever the product and the store say. */ esc_html_e( 'Automatic price changes: always', 'pixonaut-for-woocommerce' ); ?></span>
							<?php elseif ( $variable && \Pixonaut\WooCommerce\Pricing\PriceChanges::NEVER === $pixonaut_row['changes'] ) : ?>
								<span class="pixonaut-prices__changes-note"><?php /* translators: In the Prices table of the product edit page: this variation's price never changes by itself, whatever the product and the store say. */ esc_html_e( 'Automatic price changes: never', 'pixonaut-for-woocommerce' ); ?></span>
							<?php endif; ?>
							<?php if ( '' !== $pixonaut_row['source'] ) : ?>
								<span class="pixonaut-prices__source" <?php echo '' === $pixonaut_row['source_parts'] ? '' : 'title="' . esc_attr( $pixonaut_row['source_parts'] ) . '" tabindex="0"'; ?>>
									<?php
									/* translators: %s: where a price's pricing rule comes from, e.g. "Store" or "Ram (Frame), Store". In the Prices table of the product edit page. */
									echo esc_html( sprintf( __( 'Rule from: %s', 'pixonaut-for-woocommerce' ), $pixonaut_row['source'] ) );
									?>
								</span>
							<?php endif; ?>
							<?php foreach ( $pixonaut_row['conflicts'] as $pixonaut_conflict ) : ?>
								<span class="pixonaut-prices__conflict"><?php echo esc_html( $pixonaut_conflict ); ?></span>
							<?php endforeach; ?>
							<?php if ( '' !== $pixonaut_row['suggested'] && \Pixonaut\WooCommerce\Pricing\PriceState::BY_HAND !== $pixonaut_row['standing'] ) : ?>
								<span class="pixonaut-prices__suggested">
									<?php
									/* translators: %s: a price. In the Prices table of the product edit page: the price the pricing rule would set. */
									echo esc_html( sprintf( __( 'Suggested: %s', 'pixonaut-for-woocommerce' ), $pixonaut_row['suggested'] ) );
									?>
								</span>
								<?php if ( \Pixonaut\WooCommerce\Pricing\PriceState::FLAG_SALE !== $pixonaut_row['flag'] ) : ?>
									<button type="button" class="button-link pixonaut-prices__apply-one" data-action="apply" data-item="<?php echo esc_attr( (string) $pixonaut_row['id'] ); ?>"><?php /* translators: Button beside a suggested price, in the Prices table of the product edit page: sets that one price. */ echo esc_html_x( 'Apply', 'one suggested price', 'pixonaut-for-woocommerce' ); ?></button>
								<?php endif; ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</details>
