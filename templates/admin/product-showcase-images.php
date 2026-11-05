<?php
/**
 * The product images field in the Pixonaut tab: the template's showcase
 * sets, each ticked to be one of the product's images.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/admin/product-showcase-images.php.
 * Field names and classes are what the plugin reads; keep them.
 *
 * @package Pixonaut\WooCommerce
 * @version 0.1.0
 *
 * @var string                                                                       $field        The rows' posted name.
 * @var string                                                                       $listed_field Posted while the rows are listed.
 * @var list<array{id: string, name: string, kind: string, chosen: bool, gone: bool}> $rows         Each set: the template's, then chosen ones it no longer has; never empty while listed.
 * @var bool                                                                         $listed       Whether Pixonaut listed the template's sets.
 * @var bool                                                                         $pictures_on  Whether Pixonaut pictures are product images.
 * @var int                                                                          $max_images   Most sets a product shows; those ticked beyond it are left out.
 * @var array<string, array{name: string, description: string, thumbnail: string}> $kinds        How a set can be drawn, by kind: its name, what it draws, and a thumbnail of the photo or the template (empty when there is none). Empty when there is no photo to draw on, so every set is flat.
 * @var bool                                                                         $has_blank    Whether the product has a blank.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="options_group pixonaut-group pixonaut-showcase-images">
	<h4 class="pixonaut-group__title"><?php /* translators: Heading of a group of fields in the Pixonaut tab of the product edit page, shown to the merchant: the product's images drawn by Pixonaut. */ esc_html_e( 'Product images', 'pixonaut-for-woocommerce' ); ?></h4>
	<p class="pixonaut-group__intro"><?php esc_html_e( 'Show the template filled in with one of its showcase sets, example values such as a real name or photo, instead of its placeholders. The first set ticked is the product image, the others are added to the product gallery. Customers still start designing from the template’s own defaults.', 'pixonaut-for-woocommerce' ); ?></p>
	<?php if ( ! $pictures_on ) : ?>
		<p class="description pixonaut-showcase-images__off"><?php esc_html_e( 'Pixonaut pictures are not used as product images: turn on Use Pixonaut pictures as product images in the Pixonaut settings for these to show.', 'pixonaut-for-woocommerce' ); ?></p>
	<?php endif; ?>
	<?php if ( ! $listed ) : ?>
		<p class="description"><?php esc_html_e( 'Pixonaut could not be reached to list the template’s showcase sets. The sets chosen before are kept.', 'pixonaut-for-woocommerce' ); ?></p>
	<?php else : ?>
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
					<?php if ( array() === $kinds ) : ?>
						<input type="hidden" name="<?php echo esc_attr( $pixonaut_name . '[kind]' ); ?>" value="<?php echo esc_attr( $pixonaut_row['kind'] ); ?>" />
					<?php else : ?>
						<div class="pixonaut-showcase-image__kinds" role="radiogroup" aria-label="<?php /* translators: %s: name of a showcase set of the template. Admin product page. */ echo esc_attr( sprintf( __( 'How %s is drawn', 'pixonaut-for-woocommerce' ), $pixonaut_row['name'] ) ); ?>">
							<?php foreach ( $kinds as $pixonaut_kind => $pixonaut_kind_words ) : ?>
								<label class="pixonaut-showcase-kind">
									<input type="radio" name="<?php echo esc_attr( $pixonaut_name . '[kind]' ); ?>" value="<?php echo esc_attr( $pixonaut_kind ); ?>" <?php checked( $pixonaut_kind, $pixonaut_row['kind'] ); ?> />
									<span class="pixonaut-showcase-kind__thumbnail">
										<?php if ( '' !== $pixonaut_kind_words['thumbnail'] ) : ?>
											<img src="<?php echo esc_url( $pixonaut_kind_words['thumbnail'] ); ?>" alt="" loading="lazy" />
										<?php endif; ?>
									</span>
									<span class="pixonaut-showcase-kind__words">
										<span class="pixonaut-showcase-kind__name"><?php echo esc_html( $pixonaut_kind_words['name'] ); ?></span>
										<span class="description"><?php echo esc_html( $pixonaut_kind_words['description'] ); ?></span>
									</span>
								</label>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
					<?php if ( $pixonaut_row['gone'] ) : ?>
						<span class="description pixonaut-showcase-image__gone"><?php esc_html_e( 'This set is no longer in the template, so its image shows the template’s own defaults. Untick it, or choose another set.', 'pixonaut-for-woocommerce' ); ?></span>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php if ( array() === $kinds ) : ?>
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
