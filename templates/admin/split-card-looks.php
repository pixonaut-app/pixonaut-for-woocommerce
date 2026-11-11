<?php
/**
 * Each value's card in the shop, under the "Show each value as its own
 * product" control on a global attribute's row in the product's Attributes
 * tab: a title and an image of its own. Shown while the row's choice lists
 * the attribute's values as their own products.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/admin/split-card-looks.php.
 *
 * @package Pixonaut\WooCommerce
 * @version 1.4.0
 *
 * @var string $field             The posted titles' and images' name, before `[<term id>][title]` and `[<term id>][image]`.
 * @var string $id                The row's id, which each field's id starts with.
 * @var bool   $shown             Whether the row's choice lists the values as their own products now.
 * @var bool   $attribute_default Whether the attribute lists its values as their own products by default.
 * @var string $product_title     The product's title, which a card without its own shows.
 * @var array<int, array{term_id: int, name: string, title: string, image: int, image_url: string}> $cards Each of the row's values.
 */

defined( 'ABSPATH' ) || exit;
?>
<tr class="pixonaut-split-cards" data-attribute-default="<?php echo $attribute_default ? '1' : '0'; ?>"<?php echo $shown ? '' : ' hidden'; ?>>
	<td colspan="2">
		<fieldset class="pixonaut-split-cards__fieldset show_if_variable">
			<legend class="pixonaut-split-cards__legend">
				<?php
				/* translators: Heading over a title and an image field per value of an attribute, on the attribute's row in the product's Attributes tab. */
				esc_html_e( 'Each value’s card in the shop', 'pixonaut-for-woocommerce' );
				?>
			</legend>
			<p class="description">
				<?php esc_html_e( 'A card without a title of its own shows the product’s. Without an image of its own, it shows the image of the value’s first variation that has one, else the product’s. Save attributes to add a row for a value you just added.', 'pixonaut-for-woocommerce' ); ?>
			</p>
			<?php foreach ( $cards as $pixonaut_card ) : ?>
				<?php
				$pixonaut_name = $field . '[' . $pixonaut_card['term_id'] . ']';
				$pixonaut_id   = $id . '-' . $pixonaut_card['term_id'];
				?>
				<div class="pixonaut-split-card">
					<label class="pixonaut-split-card__label" for="<?php echo esc_attr( $pixonaut_id ); ?>-title">
						<?php
						/* translators: %s: an attribute value, such as "With photo". Label of the field for the title of that value's card in the shop. */
						echo esc_html( sprintf( __( 'Title for “%s”', 'pixonaut-for-woocommerce' ), $pixonaut_card['name'] ) );
						?>
					</label>
					<input type="text" class="pixonaut-split-card__title" id="<?php echo esc_attr( $pixonaut_id ); ?>-title" name="<?php echo esc_attr( $pixonaut_name ); ?>[title]" value="<?php echo esc_attr( $pixonaut_card['title'] ); ?>" placeholder="<?php echo esc_attr( $product_title ); ?>" />
					<div class="pixonaut-split-card__image">
						<input type="hidden" class="pixonaut-split-card__image-id" name="<?php echo esc_attr( $pixonaut_name ); ?>[image]" value="<?php echo esc_attr( $pixonaut_card['image'] > 0 ? (string) $pixonaut_card['image'] : '' ); ?>" />
						<img class="pixonaut-split-card__preview" src="<?php echo esc_url( $pixonaut_card['image_url'] ); ?>" alt=""<?php echo '' === $pixonaut_card['image_url'] ? ' hidden' : ''; ?> />
						<button type="button" class="button pixonaut-split-card__choose"
							data-frame-title="<?php echo esc_attr( sprintf( /* translators: %s: an attribute value, such as "With photo". Title of the media library window choosing the image of that value's card in the shop. */ __( 'Image for “%s”', 'pixonaut-for-woocommerce' ), $pixonaut_card['name'] ) ); ?>"
							data-frame-button="<?php echo esc_attr__( 'Use this image', 'pixonaut-for-woocommerce' ); ?>"
						>
							<?php
							/* translators: %s: an attribute value, such as "With photo". Button opening the media library to choose the image of that value's card in the shop. */
							echo esc_html( sprintf( __( 'Choose an image for “%s”', 'pixonaut-for-woocommerce' ), $pixonaut_card['name'] ) );
							?>
						</button>
						<button type="button" class="button-link pixonaut-split-card__remove"<?php echo $pixonaut_card['image'] > 0 ? '' : ' hidden'; ?>>
							<?php
							/* translators: %s: an attribute value, such as "With photo". Link removing the image of that value's card in the shop, which then shows its default image. */
							echo esc_html( sprintf( __( 'Remove the image for “%s”', 'pixonaut-for-woocommerce' ), $pixonaut_card['name'] ) );
							?>
						</button>
					</div>
				</div>
			<?php endforeach; ?>
		</fieldset>
	</td>
</tr>
