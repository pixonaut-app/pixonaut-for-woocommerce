<?php
/**
 * The blank variant field of a product or variation personalised on a blank.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/admin/product-variant.php.
 * The field's name and the classes are what the plugin reads; keep them.
 * The product screen's script hides the options marked `data-retired`, but
 * the one picked, until "Show retired variants" is ticked.
 *
 * @package Pixonaut\WooCommerce
 * @version 1.4.0
 *
 * @var string                $name     The field's name.
 * @var string                $id       Its id.
 * @var string                $picked   The picked variant's ref; empty to find it from the product's attributes.
 * @var string                $matched  The label of finding it automatically, with the variant it found.
 * @var array<string, string> $choices  Each variant of the blank, label by ref.
 * @var string[]              $retired  The refs of the variants the blank no longer sells.
 * @var string[]              $warnings What is wrong with the variant used.
 * @var string                $explained Where the variant is found automatically from.
 */

defined( 'ABSPATH' ) || exit;
?>
<p class="form-field pixonaut-variant">
	<label for="<?php echo esc_attr( $id ); ?>"><?php /* translators: Label of the dropdown choosing which variant of the Pixonaut blank this product is printed on. Admin product page. */ esc_html_e( 'Variant', 'pixonaut-for-woocommerce' ); ?></label>
	<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" class="pixonaut-variant-select">
		<option value="" <?php selected( $picked, '' ); ?>><?php echo esc_html( $matched ); ?></option>
		<?php foreach ( $choices as $pixonaut_ref => $pixonaut_choice ) : ?>
			<option value="<?php echo esc_attr( (string) $pixonaut_ref ); ?>"<?php echo in_array( (string) $pixonaut_ref, $retired, true ) ? ' data-retired' : ''; ?> <?php selected( $picked, (string) $pixonaut_ref ); ?>><?php echo esc_html( $pixonaut_choice ); ?></option>
		<?php endforeach; ?>
	</select>
	<?php if ( array() !== $retired ) : ?>
		<label class="pixonaut-variant-show-retired-label">
			<input type="checkbox" class="pixonaut-variant-show-retired" />
			<?php /* translators: Checkbox beside the blank variant dropdown on the product edit page: also list the variants the blank no longer sells. */ esc_html_e( 'Show retired variants', 'pixonaut-for-woocommerce' ); ?>
		</label>
	<?php endif; ?>
	<?php foreach ( $warnings as $pixonaut_warning ) : ?>
		<span class="pixonaut-field-error pixonaut-variant-warning" role="alert"><?php echo esc_html( $pixonaut_warning ); ?></span>
	<?php endforeach; ?>
	<?php if ( array() === $warnings ) : ?>
		<span class="description pixonaut-variant-explained"><?php echo esc_html( $explained ); ?></span>
	<?php endif; ?>
</p>
