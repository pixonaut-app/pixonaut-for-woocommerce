<?php
/**
 * In a personalised variation's options, the print size of each of its
 * product's personalised sides: as the product sells it (on a blank whose
 * print areas change with an option, the area of the variation's blank
 * variant), or a size of the variation's own, without overriding the rest
 * of its setup.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/admin/variation-print-size.php.
 * Field names and classes are what the plugin reads; keep them.
 *
 * @package Pixonaut\WooCommerce
 * @version 1.4.0
 *
 * @var string $listed_name Posted with the sizes, so a form without them keeps the ones saved.
 * @var bool   $hidden      Whether the field is hidden: the variation overrides its whole setup.
 * @var list<array{label: string, name: string, id: string, inherited: string, custom: bool, width: string, height: string, size_label: string, error: string}> $sides
 *                          Each side: its label, the base name of its fields, the id of its dropdown, what it prints at
 *                          as the product sells it, whether the variation has a size of its own, that size's width,
 *                          height and label, and why it cannot be printed (empty when it can).
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="woocommerce_options_panel pixonaut-variation-sizes" <?php echo $hidden ? 'hidden' : ''; ?>>
	<input type="hidden" name="<?php echo esc_attr( $listed_name ); ?>" value="1" />
	<?php foreach ( $sides as $pixonaut_side ) : ?>
		<p class="form-field pixonaut-variation-size">
			<label for="<?php echo esc_attr( $pixonaut_side['id'] ); ?>">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: the side's name, e.g. "Front". Label of a variation's print size field. Admin product page, variation options. */
						__( 'Print size: %s', 'pixonaut-for-woocommerce' ),
						$pixonaut_side['label']
					)
				);
				?>
			</label>
			<select id="<?php echo esc_attr( $pixonaut_side['id'] ); ?>" name="<?php echo esc_attr( $pixonaut_side['name'] . '[kind]' ); ?>" class="pixonaut-variation-size-kind">
				<option value="product" <?php selected( ! $pixonaut_side['custom'] ); ?>><?php echo esc_html( $pixonaut_side['inherited'] ); ?></option>
				<option value="custom" <?php selected( $pixonaut_side['custom'] ); ?>><?php /* translators: Option in a variation's print size dropdown: enter a width and height for this variation alone. Admin product page. */ esc_html_e( 'Custom size for this variation', 'pixonaut-for-woocommerce' ); ?></option>
			</select>
			<span class="pixonaut-variation-size-custom" <?php echo $pixonaut_side['custom'] ? '' : 'hidden'; ?>>
				<label class="pixonaut-size-dimension">
					<?php /* translators: Label of a custom print size's width field. Admin product page. */ esc_html_e( 'Width', 'pixonaut-for-woocommerce' ); ?>
					<input type="text" inputmode="decimal" class="short pixonaut-variation-size-width" name="<?php echo esc_attr( $pixonaut_side['name'] . '[width]' ); ?>" value="<?php echo esc_attr( $pixonaut_side['width'] ); ?>" aria-label="<?php /* translators: Screen reader label of the custom print size's width field. Admin product page. */ esc_attr_e( 'Width in millimetres', 'pixonaut-for-woocommerce' ); ?>" />
				</label>
				<label class="pixonaut-size-dimension">
					<?php /* translators: Label of a custom print size's height field. Admin product page. */ esc_html_e( 'Height', 'pixonaut-for-woocommerce' ); ?>
					<input type="text" inputmode="decimal" class="short pixonaut-variation-size-height" name="<?php echo esc_attr( $pixonaut_side['name'] . '[height]' ); ?>" value="<?php echo esc_attr( $pixonaut_side['height'] ); ?>" aria-label="<?php /* translators: Screen reader label of the custom print size's height field. Admin product page. */ esc_attr_e( 'Height in millimetres', 'pixonaut-for-woocommerce' ); ?>" />
				</label>
				<?php /* translators: Unit after a custom print size's width and height: millimetres. Admin product page. */ esc_html_e( 'mm', 'pixonaut-for-woocommerce' ); ?>
				<input type="text" class="short pixonaut-variation-size-label" name="<?php echo esc_attr( $pixonaut_side['name'] . '[label]' ); ?>" value="<?php echo esc_attr( $pixonaut_side['size_label'] ); ?>" placeholder="<?php /* translators: Placeholder of the field naming a print size, which customers see. Admin product page. */ esc_attr_e( 'Label, e.g. A4', 'pixonaut-for-woocommerce' ); ?>" aria-label="<?php /* translators: Screen reader label of the field naming a print size. Admin product page. */ esc_attr_e( 'Label customers see', 'pixonaut-for-woocommerce' ); ?>" />
			</span>
			<?php if ( '' !== $pixonaut_side['error'] ) : ?>
				<span class="pixonaut-field-error pixonaut-variation-size-error" role="alert"><?php echo esc_html( $pixonaut_side['error'] ); ?></span>
			<?php endif; ?>
		</p>
	<?php endforeach; ?>
</div>
