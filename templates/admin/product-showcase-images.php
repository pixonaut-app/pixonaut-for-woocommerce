<?php
/**
 * The product images field in the Pixonaut tab: what the product image is
 * drawn on, and the template's showcase sets, each ticked to be one of the
 * product's images and drawn on a photo of the blank or flat.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/admin/product-showcase-images.php.
 * Field names and classes are what the plugin reads; keep them.
 *
 * @package Pixonaut\WooCommerce
 * @version 1.4.0
 *
 * @var string                                                                                                    $field        The rows' posted name.
 * @var string                                                                                                    $listed_field Posted while the rows are listed.
 * @var list<array{id: string, name: string, kind: string, value: string, chosen: bool, gone: bool, view_gone: bool, choices_html: string}> $rows Each set: the template's, then chosen ones it no longer has; each with how it is drawn (`choices_html`, rendered from `admin/picture-view-choices.php`; empty when every set is flat).
 * @var bool                                                                                                      $listed       Whether Pixonaut listed the template's sets.
 * @var bool                                                                                                      $pictures_on  Whether Pixonaut pictures are product images.
 * @var int                                                                                                       $max_images   Most sets a product shows; those ticked beyond it are left out.
 * @var bool                                                                                                      $on_photo     Whether a picture can be drawn on the blank's photo; when not, every set is flat.
 * @var bool                                                                                                      $has_blank    Whether the product has a blank.
 * @var string                                                                                                    $view_listed  Posted with the choice of the image drawn without a set.
 * @var string                                                                                                    $view_html    What the image drawn without a set is drawn on, rendered from `admin/picture-view-choices.php`; empty when it can only be flat.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="options_group pixonaut-group pixonaut-showcase-images">
	<h4 class="pixonaut-group__title"><?php /* translators: Heading of a group of fields in the Pixonaut tab of the product edit page, shown to the merchant: the product's images drawn by Pixonaut. */ esc_html_e( 'Product images', 'pixonaut-for-woocommerce' ); ?></h4>
	<?php if ( ! $pictures_on ) : ?>
		<p class="description pixonaut-showcase-images__off"><?php esc_html_e( 'Pixonaut pictures are not used as product images: turn on Use Pixonaut pictures as product images in the Pixonaut settings for these to show.', 'pixonaut-for-woocommerce' ); ?></p>
	<?php endif; ?>
	<?php if ( '' !== $view_html ) : ?>
		<div class="pixonaut-picture-view">
			<input type="hidden" name="<?php echo esc_attr( $view_listed ); ?>" value="1" />
			<p class="pixonaut-picture-view__intro"><?php esc_html_e( 'What the product image is drawn on, while no example set below is ticked.', 'pixonaut-for-woocommerce' ); ?></p>
			<?php echo $view_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in admin/picture-view-choices.php. ?>
		</div>
	<?php endif; ?>
	<?php if ( ! $listed ) : ?>
		<p class="description"><?php esc_html_e( 'Pixonaut could not be reached to list the template’s showcase sets. The sets chosen before are kept.', 'pixonaut-for-woocommerce' ); ?></p>
	<?php elseif ( array() !== $rows ) : ?>
		<p class="pixonaut-group__intro"><?php esc_html_e( 'Example sets of the template: the first ticked is the product image, the others go to its gallery.', 'pixonaut-for-woocommerce' ); ?></p>
		<input type="hidden" name="<?php echo esc_attr( $listed_field ); ?>" value="1" />
		<ul class="pixonaut-showcase-images__list">
			<?php foreach ( $rows as $pixonaut_index => $pixonaut_row ) : ?>
				<?php $pixonaut_name = $field . '[' . $pixonaut_index . ']'; ?>
				<li class="pixonaut-showcase-image<?php echo $pixonaut_row['gone'] ? ' pixonaut-showcase-image--gone' : ''; ?>">
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $pixonaut_name . '[use]' ); ?>" value="yes" <?php checked( $pixonaut_row['chosen'] ); ?> />
						<span><?php echo esc_html( $pixonaut_row['name'] ); ?></span>
					</label>
					<input type="hidden" name="<?php echo esc_attr( $pixonaut_name . '[id]' ); ?>" value="<?php echo esc_attr( $pixonaut_row['id'] ); ?>" />
					<input type="hidden" name="<?php echo esc_attr( $pixonaut_name . '[name]' ); ?>" value="<?php echo esc_attr( $pixonaut_row['name'] ); ?>" />
					<?php if ( '' === $pixonaut_row['choices_html'] ) : ?>
						<input type="hidden" name="<?php echo esc_attr( $pixonaut_name . '[kind]' ); ?>" value="<?php echo esc_attr( $pixonaut_row['value'] ); ?>" />
					<?php else : ?>
						<?php echo $pixonaut_row['choices_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in admin/picture-view-choices.php. ?>
					<?php endif; ?>
					<?php if ( $pixonaut_row['gone'] ) : ?>
						<span class="description pixonaut-showcase-image__gone"><?php esc_html_e( 'This set is no longer in the template, so its image shows the template’s own defaults. Untick it, or choose another set.', 'pixonaut-for-woocommerce' ); ?></span>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php if ( ! $on_photo ) : ?>
			<p class="description pixonaut-showcase-images__flat">
				<?php if ( $has_blank ) : ?>
					<?php esc_html_e( 'The blank has no product photo for this side, so each set is drawn as the design alone.', 'pixonaut-for-woocommerce' ); ?>
				<?php else : ?>
					<?php esc_html_e( 'The product has no blank, so each set is drawn as the design alone.', 'pixonaut-for-woocommerce' ); ?>
				<?php endif; ?>
			</p>
		<?php endif; ?>
		<p class="description">
			<?php esc_html_e( 'Listed for the template the first side was saved with. The images are drawn again when the template is republished.', 'pixonaut-for-woocommerce' ); ?>
			<?php /* translators: %d: most showcase sets a product shows as its images. Admin product page. */ echo esc_html( sprintf( __( 'Up to %d sets are shown; those ticked after them are left out.', 'pixonaut-for-woocommerce' ), $max_images ) ); ?>
		</p>
	<?php endif; ?>
</div>
