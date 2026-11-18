<?php
/**
 * "Own price rule", a closed section on a variation of a product priced from
 * its printshop cost: the parts of the pricing rule this variation changes.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/admin/variation-price-rule.php.
 * Field names are what the plugin reads; keep them.
 *
 * @package Pixonaut\WooCommerce
 * @version 1.26.0
 *
 * @var string                $name      The posted field holding the variation's parts and the section's nonce.
 * @var string                $id        The section's id, which each field's id starts with.
 * @var string                $nonce     The section's nonce.
 * @var array<string, string> $own_parts The parts the variation changes, by part; empty for one it follows.
 * @var array<string, string> $inherited What each part is without the variation's own, by part; empty for none.
 * @var array<string, string> $sources   Where each part without the variation's own comes from, by part.
 * @var array<string, string> $parts     Each part's label, by part, in the order shown.
 * @var bool                  $open      Whether the section is open: the variation changes a part.
 */

defined( 'ABSPATH' ) || exit;
?>
<details class="pixonaut-variation-price-rule form-row form-row-full" <?php echo $open ? 'open' : ''; ?>>
	<summary><strong><?php /* translators: Heading of a closed section on a variation of a variable product: the parts of the pricing rule this variation changes. Admin product page. */ esc_html_e( 'Own price rule', 'pixonaut-for-woocommerce' ); ?></strong></summary>
	<input type="hidden" name="<?php echo esc_attr( $name . '[nonce]' ); ?>" value="<?php echo esc_attr( $nonce ); ?>" />
	<p class="description"><?php esc_html_e( 'The parts of the pricing rule this variation changes. An empty field follows its product, its attribute values or the store, as shown in grey.', 'pixonaut-for-woocommerce' ); ?></p>
	<div class="pixonaut-variation-price-rule__parts">
		<?php foreach ( $parts as $pixonaut_part => $pixonaut_label ) : ?>
			<?php
			$pixonaut_field_id = $id . '_' . $pixonaut_part;
			$pixonaut_value    = $inherited[ $pixonaut_part ] ?? '';
			$pixonaut_hint     = '' === $pixonaut_value
				? _x( 'None', 'no value set for a part of the pricing rule', 'pixonaut-for-woocommerce' )
				/* translators: 1: a value of a part of the pricing rule, e.g. 50; 2: where it comes from, e.g. Store or 70×100 cm (Size). Placeholder of a pricing rule field on a variation. */
				: sprintf( __( '%1$s (%2$s)', 'pixonaut-for-woocommerce' ), $pixonaut_value, $sources[ $pixonaut_part ] ?? '' );
			?>
			<p class="form-field pixonaut-variation-price-rule__part">
				<label for="<?php echo esc_attr( $pixonaut_field_id ); ?>"><?php echo esc_html( $pixonaut_label ); ?></label>
				<input type="text" inputmode="decimal" class="short" id="<?php echo esc_attr( $pixonaut_field_id ); ?>" name="<?php echo esc_attr( $name . '[parts][' . $pixonaut_part . ']' ); ?>" value="<?php echo esc_attr( $own_parts[ $pixonaut_part ] ?? '' ); ?>" placeholder="<?php echo esc_attr( $pixonaut_hint ); ?>" />
			</p>
		<?php endforeach; ?>
	</div>
</details>
