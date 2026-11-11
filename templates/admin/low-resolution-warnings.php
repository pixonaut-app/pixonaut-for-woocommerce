<?php
/**
 * Images of a product side's template that print below their recommended
 * resolution at one of its print sizes, each with what the store manager
 * can do about it.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/admin/low-resolution-warnings.php.
 * Field names and classes are what the plugin reads; keep them.
 *
 * @package Pixonaut\WooCommerce
 * @version 1.4.0
 *
 * @var list<array{key: string, name: string, text: string, refused: bool, quiet: bool, studio: string}> $rows
 *      Each image at a size: the posted name of its choice, what it says, whether Pixonaut refused
 *      the size for it, whether the user may stop warnings (a store setting), and the picture's page
 *      in the Pixonaut studio (empty while the workspace is not known).
 */

use Pixonaut\WooCommerce\Admin\LowResolutionWarnings;

defined( 'ABSPATH' ) || exit;
?>
<div class="inline notice notice-warning pixonaut-low-resolution">
	<?php foreach ( $rows as $pixonaut_row ) : ?>
		<div class="pixonaut-low-resolution__row" data-key="<?php echo esc_attr( $pixonaut_row['key'] ); ?>">
			<p class="pixonaut-low-resolution__text"><?php echo esc_html( $pixonaut_row['text'] ); ?></p>
			<div class="pixonaut-low-resolution__actions">
				<?php if ( '' !== $pixonaut_row['studio'] ) : ?>
					<a class="button pixonaut-low-resolution__upscale" href="<?php echo esc_url( $pixonaut_row['studio'] ); ?>" target="_blank" rel="noopener noreferrer"><?php /* translators: Button on a warning about an image that prints below its recommended resolution: opens the image in the Pixonaut studio, where it is upscaled. Admin product page. */ esc_html_e( 'Upscale in Pixonaut', 'pixonaut-for-woocommerce' ); ?></a>
				<?php endif; ?>
				<?php if ( $pixonaut_row['refused'] ) : ?>
					<?php if ( '' !== $pixonaut_row['studio'] ) : ?>
						<a class="button-link pixonaut-low-resolution__accept-studio" href="<?php echo esc_url( $pixonaut_row['studio'] ); ?>" target="_blank" rel="noopener noreferrer"><?php /* translators: Link on a print size Pixonaut refuses because an image prints below its recommended resolution: opens the image in the Pixonaut studio, where its resolution can be accepted as it is. Admin product page. */ esc_html_e( 'Accept in Pixonaut', 'pixonaut-for-woocommerce' ); ?></a>
					<?php endif; ?>
				<?php else : ?>
					<select class="pixonaut-low-resolution__choice" name="<?php echo esc_attr( $pixonaut_row['name'] ); ?>" aria-label="<?php /* translators: Screen reader label of the dropdown choosing what to do about an image that prints below its recommended resolution. Admin product page. */ esc_attr_e( 'What to do about this image', 'pixonaut-for-woocommerce' ); ?>">
						<option value=""><?php /* translators: Option of the dropdown on a warning about an image printed below its recommended resolution: keep the warning. Admin product page. */ esc_html_e( 'Keep warning', 'pixonaut-for-woocommerce' ); ?></option>
						<option value="<?php echo esc_attr( LowResolutionWarnings::ACCEPT ); ?>"><?php /* translators: Option of the dropdown on a warning about an image printed below its recommended resolution: accept it at this print size, which hides the warning. Admin product page. */ esc_html_e( 'Accept at this size', 'pixonaut-for-woocommerce' ); ?></option>
						<?php if ( $pixonaut_row['quiet'] ) : ?>
							<option value="<?php echo esc_attr( LowResolutionWarnings::QUIET_IMAGE ); ?>"><?php /* translators: Option of the dropdown on a warning about an image printed below its recommended resolution: never warn about this image again, on any product. Admin product page. */ esc_html_e( 'Don’t warn me again about this image', 'pixonaut-for-woocommerce' ); ?></option>
							<option value="<?php echo esc_attr( LowResolutionWarnings::QUIET_ALL ); ?>"><?php /* translators: Option of the dropdown on a warning about an image printed below its recommended resolution: never warn about any image again. Admin product page. */ esc_html_e( 'Don’t warn me again about any image', 'pixonaut-for-woocommerce' ); ?></option>
						<?php endif; ?>
					</select>
					<span class="description"><?php /* translators: Hint beside the dropdown on a warning about an image printed below its recommended resolution. Admin product page. */ esc_html_e( 'Saved when you update the product.', 'pixonaut-for-woocommerce' ); ?></span>
				<?php endif; ?>
			</div>
		</div>
	<?php endforeach; ?>
</div>
