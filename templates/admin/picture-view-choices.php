<?php
/**
 * The choices of what a product picture is drawn on: the photo the product
 * page starts on, each photo of the side's placement, or the flat design,
 * each a tile with its thumbnail.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/admin/picture-view-choices.php.
 * Field names and classes are what the plugin reads; keep them.
 *
 * @package Pixonaut\WooCommerce
 * @version 1.4.0
 *
 * @var string                                                                             $name    The radio buttons' posted name.
 * @var string                                                                             $value   The value checked.
 * @var string                                                                             $label   What the group chooses, for screen readers.
 * @var list<array{value: string, name: string, description: string, thumbnail: string, flat?: bool}> $choices Each choice: its posted value, name, what it draws (may be empty), thumbnail (empty for none), and whether it draws the design alone.
 * @var string                                                                             $note    Said under the choices when the one checked is no longer on the blank; empty otherwise.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="pixonaut-showcase-image__kinds" role="radiogroup" aria-label="<?php echo esc_attr( $label ); ?>">
	<?php foreach ( $choices as $pixonaut_choice ) : ?>
		<label class="pixonaut-showcase-kind<?php echo '' === $pixonaut_choice['description'] ? ' pixonaut-showcase-kind--photo' : ''; ?><?php echo ( $pixonaut_choice['flat'] ?? false ) ? ' pixonaut-showcase-kind--flat' : ''; ?>" title="<?php echo esc_attr( '' === $pixonaut_choice['description'] ? $pixonaut_choice['name'] : $pixonaut_choice['description'] ); ?>">
			<input type="radio" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $pixonaut_choice['value'] ); ?>" <?php checked( $pixonaut_choice['value'], $value ); ?> />
			<span class="pixonaut-showcase-kind__thumbnail">
				<?php if ( '' !== $pixonaut_choice['thumbnail'] ) : ?>
					<img src="<?php echo esc_url( $pixonaut_choice['thumbnail'] ); ?>" alt="" loading="lazy" />
				<?php endif; ?>
			</span>
			<span class="pixonaut-showcase-kind__words">
				<span class="pixonaut-showcase-kind__name"><?php echo esc_html( $pixonaut_choice['name'] ); ?></span>
				<?php if ( '' !== $pixonaut_choice['description'] ) : ?>
					<span class="description"><?php echo esc_html( $pixonaut_choice['description'] ); ?></span>
				<?php endif; ?>
			</span>
		</label>
	<?php endforeach; ?>
</div>
<?php if ( '' !== $note ) : ?>
	<span class="description pixonaut-picture-view__gone"><?php echo esc_html( $note ); ?></span>
<?php endif; ?>
