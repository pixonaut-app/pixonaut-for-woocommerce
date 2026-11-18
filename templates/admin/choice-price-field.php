<?php
/**
 * The setting on a global attribute's add and edit screens that chooses the
 * price its options show beside their swatches.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/admin/choice-price-field.php.
 *
 * @package Pixonaut\WooCommerce
 * @version 1.25.0
 *
 * @var bool                  $editing     Whether the attribute's edit screen shows it, a table row; else the add form.
 * @var string                $field       The select's name and id.
 * @var string                $choice      The attribute's choice.
 * @var array<string, string> $choices     Each choice's words, by choice.
 * @var string                $nonce_field The setting's nonce field.
 */

defined( 'ABSPATH' ) || exit;

/* translators: Label of a select on the screen that adds or edits a product attribute: which price its options show on a product page. */
$pixonaut_label = __( 'Price beside each choice', 'pixonaut-for-woocommerce' );
/* translators: Help under the "Price beside each choice" select on the screen that adds or edits a product attribute. */
$pixonaut_description = __( 'For product options shown as swatches in the designer. An add-on, such as a hanger or a frame, shows what it adds to the product without add-ons in the chosen size; the cheapest option shows nothing. A base choice, such as a size, shows the lowest price the product has with it. Prices come from the variations, as the shop shows them.', 'pixonaut-for-woocommerce' );
$pixonaut_select      = static function () use ( $field, $choice, $choices ): void {
	echo '<select name="' . esc_attr( $field ) . '" id="' . esc_attr( $field ) . '">';
	foreach ( $choices as $pixonaut_value => $pixonaut_words ) {
		echo '<option value="' . esc_attr( (string) $pixonaut_value ) . '"' . selected( $choice, $pixonaut_value, false ) . '>' . esc_html( $pixonaut_words ) . '</option>';
	}
	echo '</select>';
};
?>
<?php if ( $editing ) : ?>
	<tr class="form-field pixonaut-choice-price">
		<th scope="row" valign="top">
			<label for="<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $pixonaut_label ); ?></label>
		</th>
		<td>
			<?php $pixonaut_select(); ?>
			<?php echo $nonce_field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field() output. ?>
			<p class="description"><?php echo esc_html( $pixonaut_description ); ?></p>
		</td>
	</tr>
<?php else : ?>
	<div class="form-field pixonaut-choice-price">
		<label for="<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $pixonaut_label ); ?></label>
		<?php $pixonaut_select(); ?>
		<?php echo $nonce_field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field() output. ?>
		<p class="description"><?php echo esc_html( $pixonaut_description ); ?></p>
	</div>
<?php endif; ?>
