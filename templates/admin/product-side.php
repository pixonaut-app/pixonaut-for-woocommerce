<?php
/**
 * One side in the Pixonaut tab's sides repeater.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/admin/product-side.php.
 * Field names, classes and the `data-` attributes are what the plugin reads; keep them.
 *
 * @package Pixonaut\WooCommerce
 * @version 1.4.0
 *
 * @var string                                                                    $field         Name of the posted sides field.
 * @var string                                                                    $id_prefix     Put before every field's id.
 * @var string                                                                    $index         The side's index, or the placeholder a new side's is put in.
 * @var string                                                                    $size_index    The placeholder a new size row's index is put in.
 * @var array{key: string, label: string, title?: string, placement_id: string, template_id: string, template_name: string, pinned: bool, version: int, versions: list<int>, mode: string, offers_properties: bool|null, misfit_warning?: string, form_groups?: string, canvas_ratio?: string, sizes: list<array{kind: string, width: string, height: string, label: string, start: bool}>, options_by?: string, options?: list<array{value: string, size: string, usual: bool, image: string, adjusted: bool, adjust_url: string, reset_url: string}>} $side The side's fields: `label` is the name the merchant typed, empty while it follows the placement or template, and `title` the name it has now; `versions` are its template's versions, the latest first, `template_id` names the latest, and `pinned` whether it stays on `version` rather than following the latest; a standing side on a blank whose print areas change with an option has `options`, its design per row of `options_by` (empty otherwise), `misfit_warning` names the blank's sizes its template cannot be printed at (empty when it fits every size), and `canvas_ratio` is how the designer draws a print size whose ratio is not the template's, a `CanvasRatio` choice (empty for the product's).
 * @var array{kind: string, width: string, height: string, label: string, start: bool} $new_size A new size row's fields.
 * @var list<array{id: string, text: string, thumbnail: string, maxWidthMm?: float, maxHeightMm?: float}> $placements The blank's placements, each with its first view's photo (empty without one) and the largest print it takes, when Pixonaut said.
 * @var list<string>                                                              $open       The disclosures open when the product was last updated; `side-<index>` opens this side's.
 * @var array{url: string, attributes: list<array{slug: string, label: string}>}|null $groups The Groups setting's template groups route and the product's attributes used for variations; null where the form is not arranged (a variation's own sides).
 */

use Pixonaut\WooCommerce\Catalogue\CanvasRatio;
use Pixonaut\WooCommerce\Catalogue\PostedSides;
use Pixonaut\WooCommerce\Catalogue\Side;

defined( 'ABSPATH' ) || exit;

$pixonaut_name = static fn ( string $sub ): string => $field . '[' . $index . ']' . $sub;
$pixonaut_id   = static fn ( string $sub ): string => $id_prefix . 'side_' . $index . '_' . $sub;

/**
 * Prints one row of the side's print-size menu.
 *
 * @param string                                                                         $row  The row's index.
 * @param array{kind: string, width: string, height: string, label: string, start: bool} $size The row's fields.
 */
$pixonaut_size = static function ( string $row, array $size ) use ( $pixonaut_name ): void {
	$pixonaut_size_name = static fn ( string $sub ): string => $pixonaut_name( '[sizes][' . $row . '][' . $sub . ']' );
	$pixonaut_custom    = PostedSides::SIZE_CUSTOM === $size['kind'];
	?>
	<li class="pixonaut-size">
		<input type="hidden" class="pixonaut-size-kind" name="<?php echo esc_attr( $pixonaut_size_name( 'kind' ) ); ?>" value="<?php echo esc_attr( $pixonaut_custom ? PostedSides::SIZE_CUSTOM : PostedSides::SIZE_PLACEMENT_DEFAULT ); ?>" />
		<label class="pixonaut-size-start">
			<input type="radio" name="<?php echo esc_attr( $pixonaut_name( '[start_size]' ) ); ?>" value="<?php echo esc_attr( $row ); ?>" <?php checked( $size['start'] ); ?> />
			<span class="screen-reader-text"><?php /* translators: Radio button on a print size: this size is chosen when the designer opens. Admin product page. */ esc_html_e( 'Selected when the designer opens', 'pixonaut-for-woocommerce' ); ?></span>
		</label>
		<span class="pixonaut-size-body">
			<span class="pixonaut-size-default" <?php echo $pixonaut_custom ? 'hidden' : ''; ?>>
				<strong><?php /* translators: A print size row: the size the print position (placement) itself defines. Admin product page. */ esc_html_e( 'The print position’s size', 'pixonaut-for-woocommerce' ); ?></strong>
				<?php if ( ! $pixonaut_custom && '' !== $size['label'] ) : ?>
					<span class="pixonaut-print-size"><?php echo esc_html( $size['label'] ); ?></span>
				<?php endif; ?>
			</span>
			<span class="pixonaut-size-custom" <?php echo $pixonaut_custom ? '' : 'hidden'; ?>>
				<input type="text" class="pixonaut-size-label" name="<?php echo esc_attr( $pixonaut_size_name( 'label' ) ); ?>" value="<?php echo esc_attr( $pixonaut_custom ? $size['label'] : '' ); ?>" placeholder="<?php /* translators: Placeholder of the field naming a print size, which customers see. Admin product page. */ esc_attr_e( 'Name, e.g. A3', 'pixonaut-for-woocommerce' ); ?>" aria-label="<?php /* translators: Screen reader label of the field naming a print size. Admin product page. */ esc_attr_e( 'Label customers see', 'pixonaut-for-woocommerce' ); ?>" />
				<input type="text" inputmode="decimal" class="pixonaut-size-width" name="<?php echo esc_attr( $pixonaut_size_name( 'width' ) ); ?>" value="<?php echo esc_attr( $pixonaut_custom ? $size['width'] : '' ); ?>" aria-label="<?php /* translators: Screen reader label of the custom print size's width field. Admin product page. */ esc_attr_e( 'Width in millimetres', 'pixonaut-for-woocommerce' ); ?>" aria-required="true" />
				<span aria-hidden="true">×</span>
				<input type="text" inputmode="decimal" class="pixonaut-size-height" name="<?php echo esc_attr( $pixonaut_size_name( 'height' ) ); ?>" value="<?php echo esc_attr( $pixonaut_custom ? $size['height'] : '' ); ?>" aria-label="<?php /* translators: Screen reader label of the custom print size's height field. Admin product page. */ esc_attr_e( 'Height in millimetres', 'pixonaut-for-woocommerce' ); ?>" aria-required="true" />
				<?php /* translators: Unit after a custom print size's width and height: millimetres. Admin product page. */ esc_html_e( 'mm', 'pixonaut-for-woocommerce' ); ?>
			</span>
			<span class="description pixonaut-size-limit" hidden></span>
		</span>
		<button type="button" class="button-link pixonaut-remove-size"><?php /* translators: Button that removes one print size row from a side. Admin product page. */ esc_html_e( 'Remove', 'pixonaut-for-woocommerce' ); ?></button>
	</li>
	<?php
};
?>
<?php
$pixonaut_more_open = in_array( 'side-' . $index, $open ?? array(), true )
	|| Side::STANDING === $side['mode'] || $side['pinned']
	|| '' !== ( $side['form_groups'] ?? '' ) || '' !== ( $side['canvas_ratio'] ?? '' );
$pixonaut_fills     = __( 'Customers fill in', 'pixonaut-for-woocommerce' );
$pixonaut_fixed     = __( 'Fixed design', 'pixonaut-for-woocommerce' );
$pixonaut_follow    = __( 'follows new template versions', 'pixonaut-for-woocommerce' );
/* translators: %d: the template version a product side stays on, e.g. 2. Summary of a side's settings. Admin product page. */
$pixonaut_stays = __( 'stays on version %d', 'pixonaut-for-woocommerce' );
?>
<div class="pixonaut-side" data-index="<?php echo esc_attr( $index ); ?>"
	data-missing-template="<?php esc_attr_e( 'Choose a template for this side.', 'pixonaut-for-woocommerce' ); ?>"
	data-missing-placement="<?php esc_attr_e( 'Choose where on the blank this side is printed.', 'pixonaut-for-woocommerce' ); ?>"
	data-missing-size="<?php esc_attr_e( 'Enter the width and height in millimetres.', 'pixonaut-for-woocommerce' ); ?>"
	data-size-limit="<?php /* translators: Shown under a custom print size's width and height. %1$s: the largest width, %2$s: the largest height the side's print position prints, both in millimetres. Admin product page. */ esc_attr_e( 'Up to %1$s × %2$s mm', 'pixonaut-for-woocommerce' ); ?>"
	data-size-over="<?php /* translators: Error under a custom print size larger than the print position prints. %1$s: the largest width, %2$s: the largest height, both in millimetres. Admin product page. */ esc_attr_e( 'This print position prints at most %1$s × %2$s mm.', 'pixonaut-for-woocommerce' ); ?>"
	data-offers-properties="<?php echo esc_attr( null === $side['offers_properties'] ? '' : ( $side['offers_properties'] ? 'true' : 'false' ) ); ?>">
	<div class="pixonaut-side__header">
		<strong class="pixonaut-side__title">
			<span class="pixonaut-side__name" data-placeholder="<?php echo esc_attr_x( 'Side', 'product side such as front or back', 'pixonaut-for-woocommerce' ); ?>"><?php echo esc_html( '' === ( $side['title'] ?? '' ) ? _x( 'Side', 'product side such as front or back', 'pixonaut-for-woocommerce' ) : $side['title'] ); ?></span>
		</strong>
		<button type="button" class="button-link button-link-delete pixonaut-remove-side pixonaut-with-blank"><?php /* translators: Button that removes a whole side from the product. Admin product page. */ esc_html_e( 'Remove side', 'pixonaut-for-woocommerce' ); ?></button>
	</div>
	<p class="form-field pixonaut-with-blank">
		<label for="<?php echo esc_attr( $pixonaut_id( 'placement' ) ); ?>"><?php /* translators: Label of the dropdown that picks the spot on the blank (placement) a side is printed on, such as chest or back. Admin product page. */ esc_html_e( 'Print position', 'pixonaut-for-woocommerce' ); ?> <span class="pixonaut-required-mark" aria-hidden="true">*</span></label>
		<span class="pixonaut-field-control">
			<select id="<?php echo esc_attr( $pixonaut_id( 'placement' ) ); ?>" name="<?php echo esc_attr( $pixonaut_name( '[placement]' ) ); ?>" class="pixonaut-side-placement" style="width: 50%;" aria-required="true">
				<option value=""><?php /* translators: First option of the print position dropdown, nothing chosen yet. Admin product page. */ esc_html_e( 'Choose a print position', 'pixonaut-for-woocommerce' ); ?></option>
				<?php foreach ( $placements as $pixonaut_placement ) : ?>
					<option value="<?php echo esc_attr( $pixonaut_placement['id'] ); ?>" data-thumbnail="<?php echo esc_url( $pixonaut_placement['thumbnail'] ); ?>"
						<?php if ( isset( $pixonaut_placement['maxWidthMm'], $pixonaut_placement['maxHeightMm'] ) ) : ?>
							data-max-width-mm="<?php echo esc_attr( (string) $pixonaut_placement['maxWidthMm'] ); ?>" data-max-height-mm="<?php echo esc_attr( (string) $pixonaut_placement['maxHeightMm'] ); ?>"
						<?php endif; ?>
						<?php selected( $pixonaut_placement['id'], $side['placement_id'] ); ?>><?php echo esc_html( $pixonaut_placement['text'] ); ?></option>
				<?php endforeach; ?>
			</select>
		</span>
	</p>
	<p class="form-field">
		<label for="<?php echo esc_attr( $pixonaut_id( 'template' ) ); ?>"><?php echo esc_html_x( 'Template', 'Pixonaut template that customers fill in', 'pixonaut-for-woocommerce' ); ?> <span class="pixonaut-required-mark" aria-hidden="true">*</span></label>
		<span class="pixonaut-field-control">
			<select id="<?php echo esc_attr( $pixonaut_id( 'template' ) ); ?>" name="<?php echo esc_attr( $pixonaut_name( '[template]' ) ); ?>" class="pixonaut-side-template" style="width: 50%;" data-placeholder="<?php /* translators: Placeholder of the template search dropdown. Admin product page. */ esc_attr_e( 'Search for a template', 'pixonaut-for-woocommerce' ); ?>" data-allow_clear="true" aria-required="true">
				<option value=""></option>
				<?php if ( '' !== $side['template_id'] ) : ?>
					<option value="<?php echo esc_attr( $side['template_id'] ); ?>" selected="selected"><?php echo esc_html( '' !== $side['template_name'] ? $side['template_name'] : $side['template_id'] ); ?></option>
				<?php endif; ?>
			</select>
		</span>
		<input type="hidden" class="pixonaut-side-template-name" name="<?php echo esc_attr( $pixonaut_name( '[template_name]' ) ); ?>" value="<?php echo esc_attr( $side['template_name'] ); ?>" />
	</p>
	<div class="inline notice notice-warning pixonaut-background-warning" hidden>
		<p><?php esc_html_e( 'This template’s background shows through, and this product has no blank: customers see the design on white paper in the designer, and it prints on white paper. You probably want to set a background on the template in Pixonaut. If transparent is what you intend, leave it as it is; customers can still buy the product.', 'pixonaut-for-woocommerce' ); ?></p>
	</div>
	<div class="inline notice notice-warning pixonaut-misfit-warning" <?php echo '' === ( $side['misfit_warning'] ?? '' ) ? 'hidden' : ''; ?>>
		<p><?php echo esc_html( $side['misfit_warning'] ?? '' ); ?></p>
	</div>
	<div class="form-field pixonaut-size-menu">
		<label><?php /* translators: Label of the list of print sizes customers choose from for a side. Admin product page. */ esc_html_e( 'Print sizes', 'pixonaut-for-woocommerce' ); ?></label>
		<div class="pixonaut-size-table">
			<div class="pixonaut-size-table__head" aria-hidden="true">
				<span><?php /* translators: Column heading over the radio buttons marking the print size chosen when the designer opens. Short. Admin product page. */ esc_html_e( 'Start', 'pixonaut-for-woocommerce' ); ?></span>
				<span><?php /* translators: Column heading over a side's print sizes. Admin product page. */ esc_html_e( 'Size customers choose', 'pixonaut-for-woocommerce' ); ?></span>
			</div>
			<ul class="pixonaut-sizes">
				<?php
				foreach ( $side['sizes'] as $pixonaut_row => $pixonaut_size_fields ) {
					$pixonaut_size( (string) $pixonaut_row, $pixonaut_size_fields );
				}
				?>
			</ul>
		</div>
		<template class="pixonaut-size-template"><?php $pixonaut_size( $size_index, $new_size ); ?></template>
		<span class="pixonaut-size-actions">
			<button type="button" class="button pixonaut-add-size pixonaut-with-blank"><?php /* translators: Button that adds the print position's own size to a side's print sizes. Admin product page. */ esc_html_e( 'Add the print position’s size', 'pixonaut-for-woocommerce' ); ?></button>
			<button type="button" class="button pixonaut-add-custom-size"><?php /* translators: Button that adds a print size entered in millimetres to a side. Admin product page. */ esc_html_e( 'Add a size in mm', 'pixonaut-for-woocommerce' ); ?></button>
		</span>
	</div>
	<details class="pixonaut-disclosure pixonaut-side-more" data-disclosure="side-<?php echo esc_attr( $index ); ?>" <?php echo $pixonaut_more_open ? 'open' : ''; ?>>
		<summary>
			<?php /* translators: A closed section of a product side holding its less used settings. Admin product page. */ esc_html_e( 'More for this side', 'pixonaut-for-woocommerce' ); ?>
			<span class="pixonaut-disclosure__summary pixonaut-side-more__summary" data-fills="<?php echo esc_attr( $pixonaut_fills ); ?>" data-fixed="<?php echo esc_attr( $pixonaut_fixed ); ?>" data-follow="<?php echo esc_attr( $pixonaut_follow ); ?>" data-stays="<?php echo esc_attr( $pixonaut_stays ); ?>">
				<?php echo esc_html( ( Side::STANDING === $side['mode'] ? $pixonaut_fixed : $pixonaut_fills ) . ' · ' . ( $side['pinned'] ? sprintf( $pixonaut_stays, $side['version'] ) : $pixonaut_follow ) ); ?>
			</span>
		</summary>
		<p class="form-field pixonaut-with-blank">
			<label for="<?php echo esc_attr( $pixonaut_id( 'label' ) ); ?>"><?php /* translators: Label of the fields naming a product side and its key. Admin product page. */ esc_html_e( 'Name', 'pixonaut-for-woocommerce' ); ?></label>
			<span class="pixonaut-field-control">
				<input type="text" class="short pixonaut-side-label" id="<?php echo esc_attr( $pixonaut_id( 'label' ) ); ?>" name="<?php echo esc_attr( $pixonaut_name( '[label]' ) ); ?>" value="<?php echo esc_attr( $side['label'] ); ?>" placeholder="<?php /* translators: Placeholder of the field naming a product side, which customers see. Admin product page. */ esc_attr_e( 'Name customers see, e.g. Front', 'pixonaut-for-woocommerce' ); ?>" />
				<input type="text" class="short pixonaut-side-key" id="<?php echo esc_attr( $pixonaut_id( 'key' ) ); ?>" name="<?php echo esc_attr( $pixonaut_name( '[key]' ) ); ?>" value="<?php echo esc_attr( $side['key'] ); ?>" placeholder="<?php /* translators: Placeholder of the field for a side's key, a short lowercase identifier. Admin product page. */ esc_attr_e( 'Key, e.g. front', 'pixonaut-for-woocommerce' ); ?>" aria-label="<?php /* translators: Screen reader label of the field for a side's key. Admin product page. */ esc_attr_e( 'Side key', 'pixonaut-for-woocommerce' ); ?>" />
			</span>
			<span class="description"><?php esc_html_e( 'Shown on the side’s tab in the designer. Empty, both follow the print position.', 'pixonaut-for-woocommerce' ); ?></span>
		</p>
		<p class="form-field pixonaut-side-mode-field">
			<span class="pixonaut-field-control">
				<input type="hidden" name="<?php echo esc_attr( $pixonaut_name( '[mode]' ) ); ?>" value="<?php echo esc_attr( Side::STANDING ); ?>" />
				<label>
					<input type="checkbox" class="pixonaut-side-mode" id="<?php echo esc_attr( $pixonaut_id( 'mode' ) ); ?>" name="<?php echo esc_attr( $pixonaut_name( '[mode]' ) ); ?>" value="<?php echo esc_attr( Side::PERSONALISED ); ?>" <?php checked( Side::STANDING !== $side['mode'] ); ?> />
					<?php /* translators: Checkbox on a product side; unticked, the side is a fixed design printed as the template is. Admin product page. */ esc_html_e( 'Customers fill in the template', 'pixonaut-for-woocommerce' ); ?>
				</label>
			</span>
			<span class="description"><?php esc_html_e( 'Unticked, it is a fixed design: printed as the template is, made when you update the product.', 'pixonaut-for-woocommerce' ); ?></span>
		</p>
	<div class="inline notice notice-warning pixonaut-mode-warning pixonaut-mode-warning--standing" <?php echo Side::STANDING === $side['mode'] && true === $side['offers_properties'] ? '' : 'hidden'; ?>>
		<p><?php esc_html_e( 'This template offers fields to fill in. On a standing side customers do not get to fill them in: it is printed as the template is.', 'pixonaut-for-woocommerce' ); ?></p>
	</div>
	<div class="inline notice notice-warning pixonaut-mode-warning pixonaut-mode-warning--personalised" <?php echo Side::PERSONALISED === $side['mode'] && false === $side['offers_properties'] ? '' : 'hidden'; ?>>
		<p><?php esc_html_e( 'This template offers nothing to fill in, so customers have nothing to personalise. Make the side standing to sell it as the template is.', 'pixonaut-for-woocommerce' ); ?></p>
	</div>
	<?php if ( array() !== ( $side['options'] ?? array() ) ) : ?>
		<?php
		$pixonaut_by    = $side['options_by'] ?? '';
		$pixonaut_shown = 8; // Two rows of tiles at the panel's width; Show all opens the rest.
		$pixonaut_count = count( $side['options'] );
		?>
		<div class="form-field pixonaut-standing-options" data-count="<?php echo esc_attr( (string) $pixonaut_count ); ?>" <?php echo Side::STANDING === $side['mode'] ? '' : 'hidden'; ?>>
			<label>
				<?php
				/* translators: %s: the option a blank's print areas change with, as the blank names it, e.g. "Size". Label of a standing side's previews, one per value of that option. Admin product page. */
				echo esc_html( sprintf( __( 'Per %s', 'pixonaut-for-woocommerce' ), $pixonaut_by ) );
				?>
			</label>
			<span class="description">
				<?php
				/* translators: %s: the option a blank's print areas change with, as the blank names it, e.g. "Size". Admin product page. */
				echo esc_html( sprintf( __( 'The print area changes with %s, so each of its values is printed from its own design of this side, made when you update the product. Check how it sits on each, and adjust its layout by hand where it needs it: an adjusted value keeps its layout when the side changes, until you reset it.', 'pixonaut-for-woocommerce' ), $pixonaut_by ) );
				?>
			</span>
			<p class="pixonaut-standing-options__pending" hidden><?php esc_html_e( 'The previews appear when you update the product. An adjusted value keeps its layout.', 'pixonaut-for-woocommerce' ); ?></p>
			<ul class="pixonaut-standing-options__tiles">
				<?php foreach ( $side['options'] as $pixonaut_at => $pixonaut_option ) : ?>
					<li class="pixonaut-standing-option" <?php echo $pixonaut_at < $pixonaut_shown ? '' : 'hidden data-more'; ?>>
						<?php if ( '' !== $pixonaut_option['image'] ) : ?>
							<?php
							/* translators: 1: the option, e.g. "Size"; 2: its value, e.g. "XL". Alt text of a standing side's preview for one option. Admin product page. */
							$pixonaut_alt = sprintf( __( '%1$s %2$s: the design on the blank', 'pixonaut-for-woocommerce' ), $pixonaut_by, $pixonaut_option['value'] );
							?>
							<img class="pixonaut-standing-option__image" src="<?php echo esc_url( $pixonaut_option['image'] ); ?>" alt="<?php echo esc_attr( $pixonaut_alt ); ?>" width="120" height="120" loading="lazy" />
						<?php else : ?>
							<span class="pixonaut-standing-option__image pixonaut-standing-option__image--none" aria-hidden="true"></span>
						<?php endif; ?>
						<span class="pixonaut-standing-option__value">
							<strong><?php echo esc_html( $pixonaut_option['value'] ); ?></strong>
							<?php if ( $pixonaut_option['adjusted'] ) : ?>
								<span class="pixonaut-standing-option__adjusted"><?php /* translators: Badge beside an option's value whose layout the store manager adjusted by hand on a standing side. Admin product page. */ esc_html_e( 'Adjusted', 'pixonaut-for-woocommerce' ); ?></span>
							<?php endif; ?>
						</span>
						<?php if ( '' !== $pixonaut_option['size'] ) : ?>
							<span class="description"><?php echo esc_html( $pixonaut_option['size'] ); ?></span>
						<?php endif; ?>
						<?php if ( $pixonaut_option['usual'] ) : ?>
							<span class="description"><?php /* translators: Under a standing side's preview for one option value: the side's print size does not fit that value's print area, so the design prints at the value's usual size. Admin product page. */ esc_html_e( "Usual size: the side's is too big", 'pixonaut-for-woocommerce' ); ?></span>
						<?php endif; ?>
						<span class="pixonaut-standing-option__actions">
							<?php if ( $pixonaut_option['adjusted'] ) : ?>
								<a class="pixonaut-standing-option__adjust" href="<?php echo esc_url( $pixonaut_option['adjust_url'] ); ?>"><?php /* translators: Link that opens an option's adjusted layout on Pixonaut again, to adjust it once more. Admin product page. */ esc_html_e( 'Adjust again', 'pixonaut-for-woocommerce' ); ?></a>
								<?php
								/* translators: %s: an option's value, e.g. "XL". Confirmation before the store manager resets the layout they adjusted by hand for that option. Admin product page. */
								$pixonaut_confirm = sprintf( __( 'Reset the layout of %s? It then follows the side again, and the layout you adjusted is not kept.', 'pixonaut-for-woocommerce' ), $pixonaut_option['value'] );
								?>
								<a class="pixonaut-standing-option__reset" href="<?php echo esc_url( $pixonaut_option['reset_url'] ); ?>" data-confirm="<?php echo esc_attr( $pixonaut_confirm ); ?>"><?php /* translators: Link that puts an option whose layout was adjusted by hand back on the side's own layout. Admin product page. */ esc_html_e( 'Reset', 'pixonaut-for-woocommerce' ); ?></a>
							<?php else : ?>
								<a class="pixonaut-standing-option__adjust" href="<?php echo esc_url( $pixonaut_option['adjust_url'] ); ?>"><?php /* translators: Link that opens one option's design of a standing side on Pixonaut, to move and scale its artwork by hand. Admin product page. */ esc_html_e( 'Adjust layout', 'pixonaut-for-woocommerce' ); ?></a>
							<?php endif; ?>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php if ( $pixonaut_count > $pixonaut_shown ) : ?>
				<?php
				/* translators: %d: how many options a standing side has a preview of. Button that shows them all. Admin product page. */
				$pixonaut_all = sprintf( _n( 'Show all %d', 'Show all %d', $pixonaut_count, 'pixonaut-for-woocommerce' ), $pixonaut_count );
				?>
				<button type="button" class="button-link pixonaut-standing-options__more" aria-expanded="false" data-all="<?php echo esc_attr( $pixonaut_all ); ?>" data-fewer="<?php /* translators: Button that shows only the first of a standing side's option previews again. Admin product page. */ esc_attr_e( 'Show fewer', 'pixonaut-for-woocommerce' ); ?>"><?php echo esc_html( $pixonaut_all ); ?></button>
			<?php endif; ?>
		</div>
	<?php endif; ?>
	<p class="form-field pixonaut-template-version" <?php echo array() === $side['versions'] ? 'hidden' : ''; ?>>
		<label for="<?php echo esc_attr( $pixonaut_id( 'version' ) ); ?>"><?php /* translators: Label of the setting that keeps a product side on one version of its template. Admin product page. */ esc_html_e( 'Template version', 'pixonaut-for-woocommerce' ); ?></label>
		<span class="pixonaut-field-control">
			<label class="pixonaut-template-version__pin">
				<input type="checkbox" class="pixonaut-side-pinned" name="<?php echo esc_attr( $pixonaut_name( '[pinned]' ) ); ?>" value="<?php echo esc_attr( PostedSides::PINNED ); ?>" <?php checked( $side['pinned'] ); ?> />
				<?php /* translators: Checkbox on a product side: keep the side on the template version picked beside it instead of moving to each new version published. Admin product page. */ esc_html_e( 'Stay on one version', 'pixonaut-for-woocommerce' ); ?>
			</label>
			<select id="<?php echo esc_attr( $pixonaut_id( 'version' ) ); ?>" name="<?php echo esc_attr( $pixonaut_name( '[version]' ) ); ?>" class="pixonaut-side-version"
				data-current="<?php /* translators: Option of a product side's template version picker: the template's latest published version. %d: its number, e.g. 3. Admin product page. */ esc_attr_e( 'Version %d (current)', 'pixonaut-for-woocommerce' ); ?>"
				data-version="<?php /* translators: Option of a product side's template version picker. %d: the version's number, e.g. 2. Admin product page. */ esc_attr_e( 'Version %d', 'pixonaut-for-woocommerce' ); ?>"
				data-max-versions="<?php echo esc_attr( (string) Side::MAX_VERSIONS_OFFERED ); ?>"
				<?php echo $side['pinned'] ? '' : 'hidden'; ?>>
				<?php foreach ( $side['versions'] as $pixonaut_at => $pixonaut_version ) : ?>
					<option value="<?php echo esc_attr( (string) $pixonaut_version ); ?>" <?php selected( $pixonaut_version, $side['version'] ); ?>>
						<?php
						echo esc_html(
							sprintf(
								0 === $pixonaut_at
									/* translators: Option of a product side's template version picker: the template's latest published version. %d: its number, e.g. 3. Admin product page. */
									? __( 'Version %d (current)', 'pixonaut-for-woocommerce' )
									/* translators: Option of a product side's template version picker. %d: the version's number, e.g. 2. Admin product page. */
									: __( 'Version %d', 'pixonaut-for-woocommerce' ),
								$pixonaut_version
							)
						);
						?>
					</option>
				<?php endforeach; ?>
			</select>
		</span>
		<span class="description"><?php esc_html_e( 'Unticked, the side follows each new version of the template.', 'pixonaut-for-woocommerce' ); ?></span>
	</p>
	<?php if ( null !== ( $groups ?? null ) ) : ?>
		<div class="form-field pixonaut-groups"
			data-url="<?php echo esc_url( $groups['url'] ); ?>"
			data-attributes="<?php echo esc_attr( (string) wp_json_encode( $groups['attributes'] ) ); ?>"
			data-strings="<?php echo esc_attr( (string) wp_json_encode( \Pixonaut\WooCommerce\Admin\GroupsStrings::all() ) ); ?>"
			<?php echo Side::STANDING === $side['mode'] ? 'hidden' : ''; ?>>
			<button type="button" class="pixonaut-groups__expand" aria-expanded="false" aria-controls="<?php echo esc_attr( $pixonaut_id( 'groups' ) ); ?>"><span class="pixonaut-groups__arrow" aria-hidden="true">▸</span><?php /* translators: Label of the setting that arranges the designer's form groups for one product side; a button that shows or hides it. Admin product page. */ esc_html_e( 'Groups', 'pixonaut-for-woocommerce' ); ?></button>
			<span class="description pixonaut-groups__summary"><?php esc_html_e( 'The order and names of the groups customers see in the designer. Open to arrange them.', 'pixonaut-for-woocommerce' ); ?></span>
			<div class="pixonaut-groups__content" id="<?php echo esc_attr( $pixonaut_id( 'groups' ) ); ?>" hidden>
				<div class="pixonaut-groups__body" aria-live="polite"></div>
				<span class="description"><?php esc_html_e( 'The order and names customers see in the designer on this product’s page. Drag groups and their fields to reorder them. A name changed here is this product’s only, and shows in every language; empty, the template’s own shows. Once arranged here, this side no longer follows Options in the form.', 'pixonaut-for-woocommerce' ); ?></span>
			</div>
			<input type="hidden" class="pixonaut-groups-value" name="<?php echo esc_attr( $pixonaut_name( '[' . \Pixonaut\WooCommerce\Admin\ProductPanel::FORM_GROUPS_FIELD . ']' ) ); ?>" value="<?php echo esc_attr( $side['form_groups'] ?? '' ); ?>" />
		</div>
	<?php endif; ?>
	<p class="form-field pixonaut-side-canvas-ratio" <?php echo Side::STANDING === $side['mode'] ? 'hidden' : ''; ?>>
		<label for="<?php echo esc_attr( $pixonaut_id( PostedSides::CANVAS_RATIO ) ); ?>"><?php /* translators: Label of the dropdown choosing whether the designer's canvas keeps the template's proportions or takes the chosen print size's, asked as a question. Admin product page. */ esc_html_e( 'When a print size has other proportions', 'pixonaut-for-woocommerce' ); ?></label>
		<select id="<?php echo esc_attr( $pixonaut_id( PostedSides::CANVAS_RATIO ) ); ?>" name="<?php echo esc_attr( $pixonaut_name( '[' . PostedSides::CANVAS_RATIO . ']' ) ); ?>" class="pixonaut-side-canvas-ratio__choice">
			<?php
			/* translators: Option of a product side's canvas shape dropdown: the side draws its canvas as the product's own setting, under Store settings for this product, says. Admin product page. */
			foreach ( CanvasRatio::names( __( 'As the product’s store settings say', 'pixonaut-for-woocommerce' ) ) as $pixonaut_ratio => $pixonaut_ratio_name ) :
				?>
				<option value="<?php echo esc_attr( (string) $pixonaut_ratio ); ?>" <?php selected( (string) $pixonaut_ratio, $side['canvas_ratio'] ?? CanvasRatio::FOLLOW ); ?>><?php echo esc_html( $pixonaut_ratio_name ); ?></option>
			<?php endforeach; ?>
		</select>
	</p>
	</details>
</div>
