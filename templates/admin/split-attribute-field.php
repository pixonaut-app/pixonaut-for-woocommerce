<?php
/**
 * The setting on a global attribute's add and edit screens that lists each
 * of its values as its own product by default.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/admin/split-attribute-field.php.
 *
 * @package Pixonaut\WooCommerce
 * @version 1.4.0
 *
 * @var bool   $editing     Whether the attribute's edit screen shows it, a table row; else the add form.
 * @var string $field       The checkbox's name and id.
 * @var bool   $checked     Whether the setting is on.
 * @var string $nonce_field The setting's nonce field.
 */

defined( 'ABSPATH' ) || exit;

/* translators: Checkbox on the screen that adds or edits a product attribute: the shop lists each of the attribute's values as its own product. */
$pixonaut_label = __( 'Show each value as its own product', 'pixonaut-for-woocommerce' );
/* translators: Help under the "Show each value as its own product" checkbox on the screen that adds or edits a product attribute. */
$pixonaut_description = __( 'A variable product using this attribute for its variations is listed once per value in the shop, its other options inside each one. Each product can follow this, or turn it on or off for itself, on its Attributes tab. Only one attribute per product is listed this way.', 'pixonaut-for-woocommerce' );
?>
<?php if ( $editing ) : ?>
	<tr class="form-field pixonaut-split-attribute">
		<th scope="row" valign="top">
			<label for="<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $pixonaut_label ); ?></label>
		</th>
		<td>
			<input name="<?php echo esc_attr( $field ); ?>" id="<?php echo esc_attr( $field ); ?>" type="checkbox" value="1" <?php checked( $checked ); ?> />
			<?php echo $nonce_field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field() output. ?>
			<p class="description"><?php echo esc_html( $pixonaut_description ); ?></p>
		</td>
	</tr>
<?php else : ?>
	<div class="form-field pixonaut-split-attribute">
		<label for="<?php echo esc_attr( $field ); ?>"><input name="<?php echo esc_attr( $field ); ?>" id="<?php echo esc_attr( $field ); ?>" type="checkbox" value="1" <?php checked( $checked ); ?> /> <?php echo esc_html( $pixonaut_label ); ?></label>
		<?php echo $nonce_field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field() output. ?>
		<p class="description"><?php echo esc_html( $pixonaut_description ); ?></p>
	</div>
<?php endif; ?>
