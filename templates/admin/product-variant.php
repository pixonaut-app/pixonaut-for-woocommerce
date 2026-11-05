<?php
/**
 * The blank variant field of a product or variation personalised on a blank.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/admin/product-variant.php.
 * The field's name and the classes are what the plugin reads; keep them.
 *
 * @package Pixonaut\WooCommerce
 * @version 0.1.0
 *
 * @var string                $name     The field's name.
 * @var string                $id       Its id.
 * @var string                $picked   The picked variant's ref; empty to match by options.
 * @var string                $matched  The label of matching by options, with the variant it matches.
 * @var array<string, string> $choices  Each variant of the blank, label by ref.
 * @var string[]              $warnings What is wrong with the variant used.
 * @var string                $explained What matching by options does, and what happens when no variant matches.
 */

defined( 'ABSPATH' ) || exit;
?>
<p class="form-field pixonaut-variant">
	<label for="<?php echo esc_attr( $id ); ?>"><?php /* translators: Label of the dropdown choosing which variant of the Pixonaut blank this product is printed on. Admin product page. */ esc_html_e( 'Blank variant', 'pixonaut-for-woocommerce' ); ?></label>
	<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" class="pixonaut-variant-select">
		<option value="" <?php selected( $picked, '' ); ?>><?php echo esc_html( $matched ); ?></option>
		<?php foreach ( $choices as $pixonaut_ref => $pixonaut_choice ) : ?>
			<option value="<?php echo esc_attr( (string) $pixonaut_ref ); ?>" <?php selected( $picked, (string) $pixonaut_ref ); ?>><?php echo esc_html( $pixonaut_choice ); ?></option>
		<?php endforeach; ?>
	</select>
	<span class="description pixonaut-variant-explained"><?php echo esc_html( $explained ); ?></span>
</p>
<?php foreach ( $warnings as $pixonaut_warning ) : ?>
	<p class="pixonaut-variant-warning notice notice-warning inline"><?php echo esc_html( $pixonaut_warning ); ?></p>
<?php endforeach; ?>
