<?php
/**
 * The setting on a global attribute's add and edit screens naming the
 * attribute in the shop's addresses, as in `?size=50x70-cm`.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/admin/attribute-query-name-field.php.
 *
 * @package Pixonaut\WooCommerce
 * @version 1.12.0
 *
 * @var bool   $editing     Whether the attribute's edit screen shows it, a table row; else the add form.
 * @var string $field       The text field's name and id.
 * @var string $name        The name the store gave the attribute; empty when it goes by its slug.
 * @var string $placeholder The name it goes by without one: its slug; empty on the add form.
 * @var int    $max_length  The longest name, in characters.
 * @var string $refusal     Why the name just posted was not saved; empty when it was.
 * @var string $nonce_field The setting's nonce field.
 */

defined( 'ABSPATH' ) || exit;

/* translators: Label of a text field on the screen that adds or edits a product attribute: the short name the attribute goes by in the shop's web addresses. */
$pixonaut_label = __( 'Name in addresses', 'pixonaut-for-woocommerce' );
/* translators: Help under the "Name in addresses" field on the screen that adds or edits a product attribute. "?size=50x70-cm" is an example of a web address's query; keep it as it is. */
$pixonaut_description = __( 'The name a product page\'s address and the shop\'s filters give this attribute, as in ?size=50x70-cm: lowercase letters, digits, and hyphens or underscores. Left empty, it goes by its slug.', 'pixonaut-for-woocommerce' );
/* translators: %s: Why the name was refused. Shown under the "Name in addresses" field on the screen that adds or edits a product attribute, after the attribute was saved. */
$pixonaut_refused = __( 'The attribute was saved without this name in addresses: %s', 'pixonaut-for-woocommerce' );
$pixonaut_input   = static function () use ( $field, $name, $placeholder, $max_length ): void {
	echo '<input type="text" name="' . esc_attr( $field ) . '" id="' . esc_attr( $field ) . '" value="' . esc_attr( $name ) . '" placeholder="' . esc_attr( $placeholder ) . '" maxlength="' . esc_attr( (string) $max_length ) . '" autocomplete="off" spellcheck="false" />';
};
$pixonaut_error   = static function () use ( $refusal, $pixonaut_refused ): void {
	if ( '' !== $refusal ) {
		echo '<div class="notice notice-error inline pixonaut-query-name-refused"><p>' . esc_html( sprintf( $pixonaut_refused, $refusal ) ) . '</p></div>';
	}
};
?>
<?php if ( $editing ) : ?>
	<tr class="form-field pixonaut-query-name">
		<th scope="row" valign="top">
			<label for="<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $pixonaut_label ); ?></label>
		</th>
		<td>
			<?php $pixonaut_input(); ?>
			<?php $pixonaut_error(); ?>
			<?php echo $nonce_field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field() output. ?>
			<p class="description"><?php echo esc_html( $pixonaut_description ); ?></p>
		</td>
	</tr>
<?php else : ?>
	<div class="form-field pixonaut-query-name">
		<label for="<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $pixonaut_label ); ?></label>
		<?php $pixonaut_input(); ?>
		<?php $pixonaut_error(); ?>
		<?php echo $nonce_field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field() output. ?>
		<p class="description"><?php echo esc_html( $pixonaut_description ); ?></p>
	</div>
<?php endif; ?>
