<?php
/**
 * The product images field in the Pixonaut tab: a card per picture the
 * product can show, each with a thumbnail of it. The template's showcase
 * sets are ticked to be the product's images, each drawn on a photo of the
 * blank or as the design alone; the template's own picture, drawn with its
 * defaults, is shown while none is ticked.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/admin/product-showcase-images.php.
 * Field names, classes and the `data-` attributes are what the plugin reads; keep them.
 *
 * @package Pixonaut\WooCommerce
 * @version 1.4.0
 *
 * @var string                                                                                                    $field        The rows' posted name.
 * @var string                                                                                                    $listed_field Posted while the rows are listed.
 * @var list<array{id: string, name: string, kind: string, value: string, chosen: bool, gone: bool, view_gone: bool, choices_html: string, preview: string, photo: string, flat: bool}> $rows Each set: the template's, then chosen ones it no longer has; each with how it is drawn (`choices_html`, rendered from `admin/picture-view-choices.php`; empty when every set is flat), its preview (the template's own for a set it no longer has), the photo it is drawn on (empty for the design alone, or when not known), and whether it is drawn as the design alone.
 * @var bool                                                                                                      $listed       Whether Pixonaut listed the template's sets.
 * @var bool                                                                                                      $pictures_on  Whether Pixonaut pictures are product images.
 * @var string                                                                                                    $settings_url The settings page tab where Pixonaut pictures are turned on.
 * @var int                                                                                                       $max_images   Most sets a product shows; those ticked beyond it are left out.
 * @var bool                                                                                                      $on_photo     Whether a picture can be drawn on the blank's photo; when not, every set is flat.
 * @var bool                                                                                                      $has_blank    Whether the product has a blank.
 * @var string                                                                                                    $view_listed  Posted with the choice of the image drawn without a set.
 * @var string                                                                                                    $view_html    What the image drawn without a set is drawn on, rendered from `admin/picture-view-choices.php`; empty when it can only be flat.
 * @var string                                                                                                    $own_preview  The template's preview; empty for none.
 * @var string                                                                                                    $own_photo    The photo the image drawn without a set is drawn on; empty for the design alone, or when not known.
 * @var bool                                                                                                      $own_flat     Whether the image drawn without a set is the design alone.
 * @var int                                                                                                       $saved_sets   How many sets the product shows as saved, which stand while Pixonaut cannot list them.
 */

defined( 'ABSPATH' ) || exit;

// While the sets cannot be listed, the saved ones still stand.
$pixonaut_ticked = $listed ? count( array_filter( array_column( $rows, 'chosen' ) ) ) : $saved_sets;
$pixonaut_badges = array(
	/* translators: Badge on a picture in the Pixonaut tab's Product images: it is the product's main image. */
	'first'    => __( 'Product image', 'pixonaut-for-woocommerce' ),
	/* translators: Badge on a picture in the Pixonaut tab's Product images: it goes to the product's gallery. */
	'gallery'  => __( 'Gallery', 'pixonaut-for-woocommerce' ),
	/* translators: Badge on a picture in the Pixonaut tab's Product images: ticked past the most a product shows, so it is not shown. */
	'left_out' => __( 'Left out', 'pixonaut-for-woocommerce' ),
);
$pixonaut_place  = 0;
$pixonaut_thumb  = static function ( string $preview, string $photo, bool $flat, string $badge ): void {
	?>
	<span class="pixonaut-picture-card__thumb<?php echo $flat ? ' is-flat' : ''; ?>">
		<img class="pixonaut-picture-card__photo" src="<?php echo esc_url( $photo ); ?>" alt="" loading="lazy" <?php echo $flat || '' === $photo ? 'hidden' : ''; ?> />
		<?php if ( '' !== $preview ) : ?>
			<img class="pixonaut-picture-card__preview" src="<?php echo esc_url( $preview ); ?>" alt="" loading="lazy" />
		<?php endif; ?>
		<span class="pixonaut-picture-card__badge" <?php echo '' === $badge ? 'hidden' : ''; ?>><?php echo esc_html( $badge ); ?></span>
	</span>
	<?php
};
?>
<div class="options_group pixonaut-group pixonaut-showcase-images">
	<h4 class="pixonaut-group__title"><?php /* translators: Heading of a group of fields in the Pixonaut tab of the product edit page, shown to the merchant: the product's images drawn by Pixonaut. */ esc_html_e( 'Product images', 'pixonaut-for-woocommerce' ); ?></h4>
	<p class="pixonaut-group__intro"><?php esc_html_e( 'What the product page and the shop show before a customer designs anything.', 'pixonaut-for-woocommerce' ); ?></p>
	<?php if ( ! $pictures_on ) : ?>
		<div class="notice notice-warning inline pixonaut-showcase-images__off">
			<p>
				<?php
				printf(
					/* translators: %s: link to the Pixonaut settings page, reading "Pixonaut's settings". Admin product page. */
					esc_html__( 'These pictures are not shown yet: turn on Use Pixonaut pictures as product images in %s.', 'pixonaut-for-woocommerce' ),
					'<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Pixonaut’s settings', 'pixonaut-for-woocommerce' ) . '</a>'
				);
				?>
			</p>
		</div>
	<?php endif; ?>
	<div class="form-field pixonaut-pictures">
		<span class="pixonaut-field-label"><?php /* translators: Label of the product's pictures in the Pixonaut tab's Product images. */ esc_html_e( 'Pictures', 'pixonaut-for-woocommerce' ); ?></span>
		<?php if ( ! $listed ) : ?>
			<span class="description"><?php esc_html_e( 'Pixonaut could not be reached to list the template’s example sets. The sets chosen before are kept.', 'pixonaut-for-woocommerce' ); ?></span>
		<?php elseif ( array() === $rows ) : ?>
			<span class="description"><?php esc_html_e( 'The template has no example sets, so its own text and pictures are drawn.', 'pixonaut-for-woocommerce' ); ?></span>
		<?php else : ?>
			<span class="description">
				<?php
				/* translators: %d: most pictures a product shows. Admin product page. */
				echo esc_html( sprintf( __( 'Tick the example sets to show. The first ticked is the product image, the others go to its gallery, up to %d in all.', 'pixonaut-for-woocommerce' ), $max_images ) );
				?>
			</span>
			<input type="hidden" name="<?php echo esc_attr( $listed_field ); ?>" value="1" />
		<?php endif; ?>
		<ul class="pixonaut-picture-cards pixonaut-showcase-images__list<?php echo 0 === $pixonaut_ticked ? ' pixonaut-picture-cards--none-ticked' : ''; ?>"
			data-max="<?php echo esc_attr( (string) $max_images ); ?>"
			<?php foreach ( $pixonaut_badges as $pixonaut_badge_key => $pixonaut_badge_text ) : ?>
				data-<?php echo esc_attr( str_replace( '_', '-', $pixonaut_badge_key ) ); ?>="<?php echo esc_attr( $pixonaut_badge_text ); ?>"
			<?php endforeach; ?>
			>
			<?php foreach ( $listed ? $rows : array() as $pixonaut_index => $pixonaut_row ) : ?>
				<?php
				$pixonaut_name  = $field . '[' . $pixonaut_index . ']';
				$pixonaut_badge = '';
				if ( $pixonaut_row['chosen'] ) {
					$pixonaut_badge = 0 === $pixonaut_place ? $pixonaut_badges['first'] : ( $pixonaut_place < $max_images ? $pixonaut_badges['gallery'] : $pixonaut_badges['left_out'] );
					++$pixonaut_place;
				}
				?>
				<li class="pixonaut-picture-card pixonaut-showcase-image<?php echo $pixonaut_row['gone'] ? ' pixonaut-showcase-image--gone' : ''; ?>">
					<?php $pixonaut_thumb( $pixonaut_row['preview'], $pixonaut_row['photo'], $pixonaut_row['flat'], $pixonaut_badge ); ?>
					<label class="pixonaut-picture-card__name">
						<input type="checkbox" class="pixonaut-picture-card__use" name="<?php echo esc_attr( $pixonaut_name . '[use]' ); ?>" value="yes" <?php checked( $pixonaut_row['chosen'] ); ?> />
						<span><?php echo esc_html( $pixonaut_row['name'] ); ?></span>
					</label>
					<?php if ( $pixonaut_row['gone'] ) : ?>
						<span class="description pixonaut-showcase-image__gone"><?php esc_html_e( 'This set is no longer in the template, so its image shows the template’s own defaults. Untick it, or choose another set.', 'pixonaut-for-woocommerce' ); ?></span>
					<?php else : ?>
						<span class="description"><?php /* translators: Under a picture in the Pixonaut tab's Product images: the picture is one of the template's sets of example values. */ esc_html_e( 'Example set', 'pixonaut-for-woocommerce' ); ?></span>
					<?php endif; ?>
					<input type="hidden" name="<?php echo esc_attr( $pixonaut_name . '[id]' ); ?>" value="<?php echo esc_attr( $pixonaut_row['id'] ); ?>" />
					<input type="hidden" name="<?php echo esc_attr( $pixonaut_name . '[name]' ); ?>" value="<?php echo esc_attr( $pixonaut_row['name'] ); ?>" />
					<?php if ( '' === $pixonaut_row['choices_html'] ) : ?>
						<input type="hidden" name="<?php echo esc_attr( $pixonaut_name . '[kind]' ); ?>" value="<?php echo esc_attr( $pixonaut_row['value'] ); ?>" />
					<?php else : ?>
						<span class="pixonaut-picture-card__drawn-on" aria-hidden="true"><?php /* translators: Above the choices of what a picture in the Pixonaut tab's Product images is drawn on: a photo of the blank, or the design alone. */ esc_html_e( 'Drawn on', 'pixonaut-for-woocommerce' ); ?></span>
						<?php echo $pixonaut_row['choices_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in admin/picture-view-choices.php. ?>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
			<li class="pixonaut-picture-card pixonaut-picture-card--own pixonaut-picture-view">
				<?php $pixonaut_thumb( $own_preview, $own_photo, $own_flat, 0 === $pixonaut_ticked ? $pixonaut_badges['first'] : '' ); ?>
				<span class="pixonaut-picture-card__name"><?php /* translators: A picture in the Pixonaut tab's Product images: the template drawn with its own text and pictures. */ esc_html_e( 'The template’s own', 'pixonaut-for-woocommerce' ); ?></span>
				<span class="description"><?php esc_html_e( 'Shown while no example set is ticked.', 'pixonaut-for-woocommerce' ); ?></span>
				<?php if ( '' !== $view_html ) : ?>
					<input type="hidden" name="<?php echo esc_attr( $view_listed ); ?>" value="1" />
					<span class="pixonaut-picture-card__drawn-on" aria-hidden="true"><?php /* translators: Above the choices of what a picture in the Pixonaut tab's Product images is drawn on: a photo of the blank, or the design alone. */ esc_html_e( 'Drawn on', 'pixonaut-for-woocommerce' ); ?></span>
					<?php echo $view_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in admin/picture-view-choices.php. ?>
				<?php endif; ?>
			</li>
		</ul>
		<?php if ( ! $on_photo ) : ?>
			<span class="description pixonaut-showcase-images__flat">
				<?php if ( $has_blank ) : ?>
					<?php esc_html_e( 'The blank has no product photo for this side, so each picture is the design alone.', 'pixonaut-for-woocommerce' ); ?>
				<?php else : ?>
					<?php esc_html_e( 'Without a blank, each picture is the design alone.', 'pixonaut-for-woocommerce' ); ?>
				<?php endif; ?>
			</span>
		<?php endif; ?>
	</div>
</div>
