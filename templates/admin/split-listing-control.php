<?php
/**
 * The control on a global attribute's row in the product's Attributes tab
 * choosing how the shop lists the attribute's values: Auto (as the
 * attribute does), a card per value always, a card per value only while a
 * filter chooses one, or no card per value.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/admin/split-listing-control.php.
 *
 * @package Pixonaut\WooCommerce
 * @version 1.12.0
 *
 * @var string                $field       The posted choice's name.
 * @var string                $id          The control's id.
 * @var string                $choice      The product's choice: `auto`, `on`, `filtered` or `off`.
 * @var array<string, string> $options     What each choice reads as, by choice, in order.
 * @var string                $nonce_field The control's nonce field.
 */

defined( 'ABSPATH' ) || exit;
?>
<tr class="pixonaut-split-listing">
	<td colspan="2">
		<div class="enable_variation show_if_variable">
			<label for="<?php echo esc_attr( $id ); ?>" class="pixonaut-split-listing__label">
				<?php
				/* translators: Label of a menu on an attribute's row in the product's Attributes tab: whether the shop lists each of the attribute's values as its own card. */
				esc_html_e( 'Cards in the shop', 'pixonaut-for-woocommerce' );
				?>
			</label>
			<select class="pixonaut-split-listing__choice" name="<?php echo esc_attr( $field ); ?>" id="<?php echo esc_attr( $id ); ?>">
				<?php foreach ( $options as $pixonaut_choice => $pixonaut_text ) : ?>
					<option value="<?php echo esc_attr( $pixonaut_choice ); ?>" <?php selected( $choice, $pixonaut_choice ); ?>><?php echo esc_html( $pixonaut_text ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php echo $nonce_field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field() output. ?>
		</div>
	</td>
</tr>
