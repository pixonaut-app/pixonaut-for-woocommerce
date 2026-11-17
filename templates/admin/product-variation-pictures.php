<?php
/**
 * The Picture for every variation choice in a variable product's Pixonaut
 * tab, under Product images: as the store-wide setting, on or off. When it
 * changes, the plugin's script asks the cost route what the change does to
 * the shop's storage and shows it under the choice; past the warning, it
 * shows the confirmation box, which must be ticked to save it on.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/admin/product-variation-pictures.php.
 * Classes, names and data attributes are what the plugin's script and the save read; keep them.
 *
 * @package Pixonaut\WooCommerce
 * @version 1.15.0
 *
 * @var string $field         The choice's field name.
 * @var string $anchor        The field's id, which the studio links to.
 * @var string $choice        The product's choice: `default`, `on` or `off`.
 * @var bool   $store_wide    Whether the store-wide setting is on.
 * @var string $cost_url      The route answering what a choice costs.
 * @var string $nonce         The REST nonce the route is asked with.
 * @var string $confirm_field The confirmation box's field name.
 * @var int    $product_id    The product.
 */

defined( 'ABSPATH' ) || exit;

$pixonaut_choices = array(
	\Pixonaut\WooCommerce\Catalogue\VariationPictures::DEFAULT => $store_wide
		/* translators: Option of the Picture for every variation choice in a product's Pixonaut tab: follow the store-wide setting, which is on. */
		? __( 'As the store-wide setting (on)', 'pixonaut-for-woocommerce' )
		/* translators: Option of the Picture for every variation choice in a product's Pixonaut tab: follow the store-wide setting, which is off. */
		: __( 'As the store-wide setting (off)', 'pixonaut-for-woocommerce' ),
	/* translators: Option of the Picture for every variation choice in a product's Pixonaut tab. */
	\Pixonaut\WooCommerce\Catalogue\VariationPictures::ON  => _x( 'On', 'picture for every variation', 'pixonaut-for-woocommerce' ),
	/* translators: Option of the Picture for every variation choice in a product's Pixonaut tab. */
	\Pixonaut\WooCommerce\Catalogue\VariationPictures::OFF => _x( 'Off', 'picture for every variation', 'pixonaut-for-woocommerce' ),
);
?>
<div class="options_group pixonaut-group pixonaut-variation-pictures" id="<?php echo esc_attr( $anchor ); ?>">
	<p class="form-field">
		<label for="<?php echo esc_attr( $field ); ?>"><?php /* translators: Label of a choice in a variable product's Pixonaut tab: whether each variation gets a Pixonaut picture of its own. */ esc_html_e( 'Picture for every variation', 'pixonaut-for-woocommerce' ); ?></label>
		<select id="<?php echo esc_attr( $field ); ?>" name="<?php echo esc_attr( $field ); ?>"
			data-pixonaut-cost-url="<?php echo esc_url( $cost_url ); ?>"
			data-pixonaut-cost-nonce="<?php echo esc_attr( $nonce ); ?>"
			data-pixonaut-cost-product="<?php echo esc_attr( (string) $product_id ); ?>"
			data-pixonaut-cost-saved="<?php echo esc_attr( $choice ); ?>"
			data-pixonaut-confirm-name="<?php echo esc_attr( $confirm_field ); ?>"
			data-pixonaut-confirm-label="<?php /* translators: Box the store manager ticks to turn on a picture for every variation although it takes much of the shop's storage. */ esc_attr_e( 'Turn it on anyway', 'pixonaut-for-woocommerce' ); ?>"
			data-pixonaut-cost-error="<?php /* translators: Shown under Picture for every variation when what changing it costs could not be read. */ esc_attr_e( 'What this costs could not be read. Saving it on still asks for a confirmation when it takes much of your shop\'s storage.', 'pixonaut-for-woocommerce' ); ?>"
			>
			<?php foreach ( $pixonaut_choices as $pixonaut_value => $pixonaut_label ) : ?>
				<option value="<?php echo esc_attr( $pixonaut_value ); ?>"<?php selected( $choice, $pixonaut_value ); ?>><?php echo esc_html( $pixonaut_label ); ?></option>
			<?php endforeach; ?>
		</select>
		<span class="description"><?php esc_html_e( 'Draws each variation on its blank\'s photo, in its own colour and size, so the product page shows the variation a shopper picks. Pictures are stored in your shop\'s media library, on your shop\'s own server, not in Pixonaut. Off deletes the variation pictures nothing else uses.', 'pixonaut-for-woocommerce' ); ?></span>
	</p>
	<div class="pixonaut-variation-pictures__cost" role="status" aria-live="polite" hidden></div>
</div>
