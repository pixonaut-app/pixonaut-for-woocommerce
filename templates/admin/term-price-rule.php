<?php
/**
 * The pricing rule's parts on the screens that add and edit a value of a
 * global product attribute: what the value changes for every variation with
 * it, in every product.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/admin/term-price-rule.php.
 * Field names are what the plugin reads; keep them.
 *
 * @package Pixonaut\WooCommerce
 * @version 1.27.0
 *
 * @var bool                  $editing     Whether the value's edit screen shows it, table rows; else the add form.
 * @var string                $field       The posted field holding the parts, by part.
 * @var array<string, string> $own_parts   The parts the value changes, by part; empty for the store's.
 * @var array<string, string> $store       The store's rule, by part.
 * @var array<string, string> $parts       Each part's label, by part, in the order shown.
 * @var list<string>          $clashes     What to say of each part a value of another attribute sets too.
 * @var string                $settings    The store's pricing rule on the Pixonaut settings page.
 * @var string                $nonce_field The fields' nonce field.
 */

defined( 'ABSPATH' ) || exit;

/* translators: Heading of the pricing rule fields on the screen that adds or edits a value of a product attribute. */
$pixonaut_heading = __( 'Pricing rule for this value', 'pixonaut-for-woocommerce' );
/* translators: Help under the pricing rule fields on the screen that adds or edits a value of a product attribute. */
$pixonaut_help  = __( 'Changes the store’s pricing rule for every variation with this value, in every product. An empty field follows the store’s rule, shown in grey. A product or a variation that sets a part itself wins over the value.', 'pixonaut-for-woocommerce' );
$pixonaut_none  = _x( 'None', 'no value set for a part of the pricing rule', 'pixonaut-for-woocommerce' );
$pixonaut_input = static function ( string $part, string $id ) use ( $field, $own_parts, $store, $pixonaut_none ): void {
	echo '<input type="text" inputmode="decimal" class="pixonaut-term-price-rule__input" id="' . esc_attr( $id ) . '" name="' . esc_attr( $field . '[' . $part . ']' ) . '" value="' . esc_attr( $own_parts[ $part ] ?? '' ) . '" placeholder="' . esc_attr( '' === ( $store[ $part ] ?? '' ) ? $pixonaut_none : $store[ $part ] ) . '" />';
};
?>
<?php if ( $editing ) : ?>
	<tr class="form-field pixonaut-term-price-rule">
		<th scope="row" colspan="2"><h2><?php echo esc_html( $pixonaut_heading ); ?></h2></th>
	</tr>
	<?php foreach ( $parts as $pixonaut_part => $pixonaut_label ) : ?>
		<?php $pixonaut_id = 'pixonaut_term_price_rule_' . $pixonaut_part; ?>
		<tr class="form-field pixonaut-term-price-rule__part">
			<th scope="row" valign="top"><label for="<?php echo esc_attr( $pixonaut_id ); ?>"><?php echo esc_html( $pixonaut_label ); ?></label></th>
			<td><?php $pixonaut_input( $pixonaut_part, $pixonaut_id ); ?></td>
		</tr>
	<?php endforeach; ?>
	<tr class="form-field pixonaut-term-price-rule__help">
		<th scope="row"></th>
		<td>
			<?php echo $nonce_field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field() output. ?>
			<?php foreach ( $clashes as $pixonaut_clash ) : ?>
				<div class="notice notice-warning inline pixonaut-term-price-rule__clash"><p><?php echo esc_html( $pixonaut_clash ); ?></p></div>
			<?php endforeach; ?>
			<p class="description"><?php echo esc_html( $pixonaut_help ); ?> <a href="<?php echo esc_url( $settings ); ?>"><?php /* translators: Link from the pricing rule fields of a product attribute's value to the pricing rule on the Pixonaut settings page. */ esc_html_e( 'Store’s pricing rule', 'pixonaut-for-woocommerce' ); ?></a></p>
		</td>
	</tr>
<?php else : ?>
	<div class="form-field pixonaut-term-price-rule">
		<h2><?php echo esc_html( $pixonaut_heading ); ?></h2>
		<?php foreach ( $parts as $pixonaut_part => $pixonaut_label ) : ?>
			<?php $pixonaut_id = 'pixonaut_term_price_rule_' . $pixonaut_part; ?>
			<p class="pixonaut-term-price-rule__part">
				<label for="<?php echo esc_attr( $pixonaut_id ); ?>"><?php echo esc_html( $pixonaut_label ); ?></label>
				<?php $pixonaut_input( $pixonaut_part, $pixonaut_id ); ?>
			</p>
		<?php endforeach; ?>
		<?php echo $nonce_field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field() output. ?>
		<p class="description"><?php echo esc_html( $pixonaut_help ); ?></p>
	</div>
<?php endif; ?>
