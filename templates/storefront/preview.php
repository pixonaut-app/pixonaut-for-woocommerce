<?php
/**
 * The live preview of a personalised product, in place of its gallery.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/storefront/preview.php.
 * The storefront script mounts each side into its `#pixonaut-preview-<side>`,
 * with the strip of the product's views under it in `#pixonaut-views-<side>`,
 * headed by how many there are,
 * and, on a product with more than one side, shows the one whose tab is
 * selected; the first is shown until then. Each side is printed at the size
 * its designer ends up with, the preview at the photo's aspect ratio, so the
 * page does not move when the designer starts. The preview's placeholder
 * keeps the SDK from showing its preview before the photo and the design are
 * drawn: it replaces the placeholder, and the form and views placeholders,
 * once they are. The spinner over it shows until the design is drawn, when
 * the script marks the side `is-drawn`. A side on no blank is marked
 * `data-pixonaut-shape="template"`: it keeps its template's shape inside the
 * height a theme holds previews at. The designer draws "Powered by
 * Pixonaut" into `#pixonaut-mark-<side>`, under the preview and outside
 * anything a theme draws around it; a side whose designer shows it is
 * marked `data-pixonaut-mark`, and keeps the mark's room there until it is
 * drawn. A note under the sides says the preview follows what the customer
 * types. `.pixonaut-preview__images` holds the product's own images, its
 * featured image and then its gallery's, or WooCommerce's placeholder: when
 * the designer cannot load, the script marks the preview `is-unavailable`,
 * shows them in place of the sides and hides the note.
 *
 * @package Pixonaut\WooCommerce
 * @version 1.4.0
 *
 * @var int                       $product_id The product.
 * @var string[]                  $sides      Side keys, in display order.
 * @var bool                      $classic    In a classic template, where the theme lays out the gallery by its `images` class.
 * @var string                    $note       The note under the preview.
 * @var string                    $loading    What the spinner reads out.
 * @var array<string, \Pixonaut\WooCommerce\Storefront\SideLayout> $layouts Each side's layout, by side key.
 * @var int[]                     $images     The product's image attachment ids, its featured image first; empty for none.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="<?php echo esc_attr( $classic ? 'pixonaut-preview images' : 'pixonaut-preview' ); ?>" data-pixonaut-product="<?php echo esc_attr( (string) $product_id ); ?>">
	<?php foreach ( $sides as $pixonaut_index => $pixonaut_side ) : ?>
		<?php $pixonaut_layout = $layouts[ $pixonaut_side ] ?? null; ?>
		<div class="pixonaut-preview__frame" data-pixonaut-side="<?php echo esc_attr( $pixonaut_side ); ?>"<?php echo null === $pixonaut_layout ? '' : ' style="' . esc_attr( $pixonaut_layout->style() ) . '"'; ?><?php echo null !== $pixonaut_layout && $pixonaut_layout->template_shaped ? ' data-pixonaut-shape="template"' : ''; ?><?php echo null === $pixonaut_layout || $pixonaut_layout->powered_by ? ' data-pixonaut-mark' : ''; ?><?php echo 0 === $pixonaut_index ? '' : ' hidden'; ?>>
			<div class="pixonaut-preview__stage">
				<div id="<?php echo esc_attr( 'pixonaut-preview-' . $pixonaut_side ); ?>" class="pixonaut-preview__side" data-pixonaut-side="<?php echo esc_attr( $pixonaut_side ); ?>"><span class="pixonaut-preview__placeholder" aria-hidden="true"></span></div>
				<span class="pixonaut-spinner" role="status"><span class="screen-reader-text"><?php echo esc_html( $loading ); ?></span></span>
			</div>
			<div id="<?php echo esc_attr( 'pixonaut-mark-' . $pixonaut_side ); ?>" class="pixonaut-preview__mark" data-pixonaut-side="<?php echo esc_attr( $pixonaut_side ); ?>"></div>
			<?php if ( null !== $pixonaut_layout && array() !== $pixonaut_layout->views ) : ?>
				<div id="<?php echo esc_attr( 'pixonaut-views-' . $pixonaut_side ); ?>" class="pixonaut-views" data-pixonaut-side="<?php echo esc_attr( $pixonaut_side ); ?>">
					<span class="pixonaut-views__count">
					<?php
					$pixonaut_view_count = count( $pixonaut_layout->views );
					/* translators: %s: How many views of the product the strip under its preview shows, as Pixonaut's designer counts them. */
					echo esc_html( sprintf( _n( '%s view', '%s views', $pixonaut_view_count, 'pixonaut-for-woocommerce' ), number_format_i18n( $pixonaut_view_count ) ) );
					?>
					</span>
					<span class="pixonaut-views__strip">
					<?php foreach ( $pixonaut_layout->views as $pixonaut_view ) : ?>
						<span class="pixonaut-views__view" aria-hidden="true">
							<span class="pixonaut-views__photo">
								<?php if ( '' !== $pixonaut_view['thumbnail'] ) : ?>
									<img src="<?php echo esc_url( $pixonaut_view['thumbnail'] ); ?>" alt="" />
								<?php endif; ?>
							</span>
							<span class="pixonaut-views__label"><?php echo esc_html( $pixonaut_view['label'] ); ?></span>
						</span>
					<?php endforeach; ?>
					</span>
				</div>
			<?php endif; ?>
		</div>
	<?php endforeach; ?>
	<div class="pixonaut-preview__images" hidden>
		<?php if ( array() === ( $images ?? array() ) ) : ?>
			<?php echo wc_placeholder_img( 'woocommerce_single' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce's image markup. ?>
		<?php else : ?>
			<?php foreach ( $images as $pixonaut_index => $pixonaut_image ) : ?>
				<?php echo wp_get_attachment_image( $pixonaut_image, 0 === $pixonaut_index ? 'woocommerce_single' : 'woocommerce_gallery_thumbnail' ); ?>
			<?php endforeach; ?>
		<?php endif; ?>
	</div>
	<p class="pixonaut-preview__note"><?php echo esc_html( $note ); ?></p>
</div>
