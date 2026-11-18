<?php
/**
 * The blank option a global attribute stands for, and the option value each
 * of its values stands for, on the attribute's edit screen.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/admin/blank-option-field.php.
 *
 * @package Pixonaut\WooCommerce
 * @version 1.23.0
 *
 * @var string       $option_field The option select's name and id.
 * @var string       $values_field The value selects' name, by term id.
 * @var string       $option       The option the attribute stands for; empty when it is not linked.
 * @var list<string> $options      The options it can stand for.
 * @var list<array{term_id: int, name: string, value: string, values: list<string>, same: string|null}> $values Each of its values: its term, its linked option value (empty for none), the option values it can stand for, and the one of its name.
 * @var string       $nonce_field  The setting's nonce field.
 */

defined( 'ABSPATH' ) || exit;

/* translators: Label of a select on the screen that edits a product attribute: the option of the Pixonaut blank (product base) the attribute stands for, such as a frame's Color. */
$pixonaut_label = __( 'Blank option', 'pixonaut-for-woocommerce' );
/* translators: Option of the "Blank option" select on the screen that edits a product attribute: the attribute stands for no option of the blank. */
$pixonaut_unlinked = _x( 'Not linked', 'blank option of an attribute', 'pixonaut-for-woocommerce' );
/* translators: Help under the "Blank option" select on the screen that edits a product attribute. */
$pixonaut_description = __( 'The option of the Pixonaut blank this attribute stands for, such as a frame\'s colour. Its values then pick the blank\'s variant and show in the designer as the option\'s colour or picture swatches. The options listed are those of the blanks the products with this attribute use.', 'pixonaut-for-woocommerce' );
/* translators: Column heading in the table of an attribute's values on the screen that edits a product attribute. */
$pixonaut_value_heading = _x( 'Value', 'attribute value', 'pixonaut-for-woocommerce' );
/* translators: Column heading in the table of an attribute's values: the value of the blank's option each one stands for. */
$pixonaut_option_value_heading = __( 'Blank option value', 'pixonaut-for-woocommerce' );
/* translators: Option of a value's "Blank option value" select: the value stands for no value of the blank's option. */
$pixonaut_value_unlinked = _x( 'Not linked', 'blank option value of an attribute value', 'pixonaut-for-woocommerce' );
/* translators: %s: the blank option's value of the same name as the attribute value. Option of a value's "Blank option value" select: the value stands for the option value of its name. */
$pixonaut_same_name = __( 'Same name: %s', 'pixonaut-for-woocommerce' );
/* translators: Help under the table of an attribute's values on the screen that edits a product attribute. */
$pixonaut_values_description = __( 'A value that is not linked shows as text and picks no variant by this option. Save after choosing another option to list its values.', 'pixonaut-for-woocommerce' );
// The attribute screen's `.form-table th, .form-table td` rules would give the table's cells a row's width and padding.
$pixonaut_cell_style = 'width: auto; padding: 8px 10px;';
/* translators: Shown under the "Blank option" select on the screen that edits a product attribute, while the attribute stands for no option. */
$pixonaut_not_linked_description = __( 'Not linked: its values show as text, and pick the blank\'s variant only where they have the names of an option and its values.', 'pixonaut-for-woocommerce' );
?>
<tr class="form-field pixonaut-blank-option">
	<th scope="row" valign="top">
		<label for="<?php echo esc_attr( $option_field ); ?>"><?php echo esc_html( $pixonaut_label ); ?></label>
	</th>
	<td>
		<select name="<?php echo esc_attr( $option_field ); ?>" id="<?php echo esc_attr( $option_field ); ?>">
			<option value=""<?php selected( $option, '' ); ?>><?php echo esc_html( $pixonaut_unlinked ); ?></option>
			<?php foreach ( $options as $pixonaut_option ) : ?>
				<option value="<?php echo esc_attr( $pixonaut_option ); ?>"<?php selected( $option, $pixonaut_option ); ?>><?php echo esc_html( $pixonaut_option ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php echo $nonce_field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field() output. ?>
		<p class="description"><?php echo esc_html( $pixonaut_description ); ?></p>
		<?php if ( '' !== $option && array() !== $values ) : ?>
			<table class="widefat striped pixonaut-blank-option-values" style="max-width: 40em;">
				<thead>
					<tr>
						<th scope="col" style="<?php echo esc_attr( $pixonaut_cell_style ); ?>"><?php echo esc_html( $pixonaut_value_heading ); ?></th>
						<th scope="col" style="<?php echo esc_attr( $pixonaut_cell_style ); ?>"><?php echo esc_html( $pixonaut_option_value_heading ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $values as $pixonaut_value ) : ?>
						<?php $pixonaut_id = $values_field . '-' . $pixonaut_value['term_id']; ?>
						<tr>
							<td style="<?php echo esc_attr( $pixonaut_cell_style ); ?>"><label for="<?php echo esc_attr( $pixonaut_id ); ?>"><?php echo esc_html( $pixonaut_value['name'] ); ?></label></td>
							<td style="<?php echo esc_attr( $pixonaut_cell_style ); ?>">
								<select name="<?php echo esc_attr( $values_field . '[' . $pixonaut_value['term_id'] . ']' ); ?>" id="<?php echo esc_attr( $pixonaut_id ); ?>">
									<option value=""<?php selected( $pixonaut_value['value'], '' ); ?>>
										<?php echo esc_html( null === $pixonaut_value['same'] ? $pixonaut_value_unlinked : sprintf( $pixonaut_same_name, $pixonaut_value['same'] ) ); ?>
									</option>
									<?php foreach ( $pixonaut_value['values'] as $pixonaut_offered ) : ?>
										<option value="<?php echo esc_attr( $pixonaut_offered ); ?>"<?php selected( $pixonaut_value['value'], $pixonaut_offered ); ?>><?php echo esc_html( $pixonaut_offered ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description"><?php echo esc_html( $pixonaut_values_description ); ?></p>
		<?php elseif ( '' === $option ) : ?>
			<p class="description"><?php echo esc_html( $pixonaut_not_linked_description ); ?></p>
		<?php endif; ?>
	</td>
</tr>
