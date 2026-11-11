<?php
/**
 * The setting on a global attribute's add and edit screens that shows or
 * hides its values' names beside its colour and picture swatches.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/admin/swatch-names-field.php.
 *
 * @package Pixonaut\WooCommerce
 * @version 1.4.0
 *
 * @var bool                  $editing     Whether the attribute's edit screen shows it, a table row; else the add form.
 * @var string                $field       The select's name and id.
 * @var string                $choice      The attribute's choice.
 * @var array<string, string> $choices     Each choice's words, by choice.
 * @var string                $nonce_field The setting's nonce field.
 */

defined( 'ABSPATH' ) || exit;

/* translators: Label of a select on the screen that adds or edits a product attribute: whether its values' names show beside their colour or picture swatches in the shop. */
$pixonaut_label = __( 'Value names beside swatches', 'pixonaut-for-woocommerce' );
/* translators: Help under the "Value names beside swatches" select on the screen that adds or edits a product attribute. */
$pixonaut_description = __( 'For product options shown as swatches in the designer: whether a value drawn as a colour or a picture shows its name beside it. The store\'s setting is on the Pixonaut settings page. A value without a colour or picture always shows its name.', 'pixonaut-for-woocommerce' );
$pixonaut_select      = static function () use ( $field, $choice, $choices ): void {
	echo '<select name="' . esc_attr( $field ) . '" id="' . esc_attr( $field ) . '">';
	foreach ( $choices as $pixonaut_value => $pixonaut_words ) {
		echo '<option value="' . esc_attr( (string) $pixonaut_value ) . '"' . selected( $choice, $pixonaut_value, false ) . '>' . esc_html( $pixonaut_words ) . '</option>';
	}
	echo '</select>';
};
?>
<?php if ( $editing ) : ?>
	<tr class="form-field pixonaut-swatch-names">
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
	<div class="form-field pixonaut-swatch-names">
		<label for="<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $pixonaut_label ); ?></label>
		<?php $pixonaut_select(); ?>
		<?php echo $nonce_field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field() output. ?>
		<p class="description"><?php echo esc_html( $pixonaut_description ); ?></p>
	</div>
<?php endif; ?>
