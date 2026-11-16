<?php
/**
 * The setting on a global attribute's add and edit screens choosing how
 * the shop lists its values by default: a card per value always, a card
 * per value only while a filter chooses one, or no card per value.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/admin/split-attribute-field.php.
 *
 * @package Pixonaut\WooCommerce
 * @version 1.12.0
 *
 * @var bool                  $editing     Whether the attribute's edit screen shows it, a table row; else the add form.
 * @var string                $field       The menu's name and id.
 * @var string                $mode        The attribute's setting: `off`, `on` or `filtered`.
 * @var array<string, string> $options     What each setting reads as, by setting, in order.
 * @var string                $nonce_field The setting's nonce field.
 */

defined( 'ABSPATH' ) || exit;

/* translators: Label of a menu on the screen that adds or edits a product attribute: whether the shop lists each of the attribute's values as its own card. */
$pixonaut_label = __( 'Cards in the shop', 'pixonaut-for-woocommerce' );
/* translators: Help under the "Cards in the shop" menu on the screen that adds or edits a product attribute. */
$pixonaut_description = __( 'A variable product using this attribute for its variations can be listed once per value in the shop, its other options inside each one: always, or only while a customer filters by this attribute, as for a framing that should not make cards of its own. Each product can follow this, or choose for itself, on its Attributes tab.', 'pixonaut-for-woocommerce' );
?>
<?php if ( $editing ) : ?>
	<tr class="form-field pixonaut-split-attribute">
		<th scope="row" valign="top">
			<label for="<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $pixonaut_label ); ?></label>
		</th>
		<td>
			<select name="<?php echo esc_attr( $field ); ?>" id="<?php echo esc_attr( $field ); ?>">
				<?php foreach ( $options as $pixonaut_mode => $pixonaut_text ) : ?>
					<option value="<?php echo esc_attr( $pixonaut_mode ); ?>" <?php selected( $mode, $pixonaut_mode ); ?>><?php echo esc_html( $pixonaut_text ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php echo $nonce_field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field() output. ?>
			<p class="description"><?php echo esc_html( $pixonaut_description ); ?></p>
		</td>
	</tr>
<?php else : ?>
	<div class="form-field pixonaut-split-attribute">
		<label for="<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $pixonaut_label ); ?></label>
		<select name="<?php echo esc_attr( $field ); ?>" id="<?php echo esc_attr( $field ); ?>">
			<?php foreach ( $options as $pixonaut_mode => $pixonaut_text ) : ?>
				<option value="<?php echo esc_attr( $pixonaut_mode ); ?>" <?php selected( $mode, $pixonaut_mode ); ?>><?php echo esc_html( $pixonaut_text ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php echo $nonce_field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field() output. ?>
		<p class="description"><?php echo esc_html( $pixonaut_description ); ?></p>
	</div>
<?php endif; ?>
