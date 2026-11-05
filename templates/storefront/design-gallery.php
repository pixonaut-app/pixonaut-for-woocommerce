<?php
/**
 * The customer's design in the place of the product image, with one picture
 * per side, in the classes of WooCommerce's gallery.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/storefront/design-gallery.php.
 * It stands right after the product's gallery, which it hides while it is not
 * `hidden`. A variable product prints one per variation with a design; the
 * storefront script shows the one of the chosen variation, by
 * `data-pixonaut-design`.
 *
 * @package Pixonaut\WooCommerce
 * @version 0.1.0
 *
 * @var string                                                 $design   The design's key.
 * @var bool                                                   $shown    Whether it shows when the page opens.
 * @var list<array{src: string, srcset: string, alt: string, paper: bool}>  $pictures One picture per side; `paper` draws it on white, display only, when nothing would stand behind its see-through background.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="images pixonaut-design-gallery" data-pixonaut-design="<?php echo esc_attr( $design ); ?>"<?php echo $shown ? '' : ' hidden'; ?>>
	<?php foreach ( $pictures as $pixonaut_picture ) : ?>
		<div class="woocommerce-product-gallery__image<?php echo $pixonaut_picture['paper'] ? ' pixonaut-design-gallery__paper' : ''; ?>">
			<img src="<?php echo esc_url( $pixonaut_picture['src'] ); ?>" srcset="<?php echo esc_attr( $pixonaut_picture['srcset'] ); ?>" sizes="(min-width: 768px) 50vw, 100vw" alt="<?php echo esc_attr( $pixonaut_picture['alt'] ); ?>" decoding="async" />
		</div>
	<?php endforeach; ?>
</div>
