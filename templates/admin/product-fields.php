<?php
/**
 * One Pixonaut configuration's fields: the product's, in the Pixonaut tab, or
 * a variation's, in its Pixonaut panel.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/admin/product-fields.php.
 * Field names, classes and the `data-` attributes are what the plugin reads; keep them.
 *
 * @package Pixonaut\WooCommerce
 * @version 0.1.0
 *
 * @var array{personalise: string, blank: string, layout: string, form_layout: string, approval_mode: string, views: string, printshop: string, canvas_ratio: string, option_groups: string, group_numbers: string} $names Each field's posted name.
 * @var array{personalise: string, blank: string, layout: string, form_layout: string, approval_mode: string, initial_view: string, printshop: string, canvas_ratio: string, option_groups: string, group_numbers: string} $ids Each field's id.
 * @var string                                  $notice_text    What the invalid notice starts with.
 * @var int                                     $max_sides      Most sides a product has.
 * @var int                                     $recommended    Most sides Pixonaut's designer is built for; more are shown a hint.
 * @var bool                                    $personalise    Whether customers personalise it.
 * @var string                                  $blank_id       Linked blank, or empty.
 * @var string                                  $blank_name     Its name.
 * @var string                                  $blank_thumbnail Its first photo, shown beside its name; empty without one.
 * @var string                                  $variant_html   The blank variant field, rendered from `admin/product-variant.php`; empty
 *                                                              when it is shown elsewhere or not at all.
 * @var array{name: string, url: string}|null   $printshop      The printshop the blank prints at, and its studio page; null without a blank.
 * @var array<string, string>                   $printshops     The printshops a product without a blank can choose, names by id; the empty id is the workspace's default.
 * @var string                                  $printshop_id   The printshop it chose, or empty for the workspace's default.
 * @var list<array{name: string, views: list<array{id: string, label: string, thumbnail: string}>}>|null $views The blank's views, placement by placement; null without a blank, or when Pixonaut could not be asked.
 * @var list<string>                            $chosen_views   The ids of the views it is shown in; empty for all.
 * @var string                                  $initial_view   The id of the view the designer starts on; empty for the first.
 * @var list<string>                            $sides_html     Each side, rendered from `admin/product-side.php`.
 * @var string                                  $new_side_html  A new side, rendered with placeholder indexes.
 * @var string                                  $invalid        Why it cannot be bought; empty when it can.
 * @var string                                  $layout         Its layout, or empty for the store's default.
 * @var array<string, string>                   $layouts        Layout names by layout; the empty layout is the store's default.
 * @var string                                  $form_layout    Its form layout, or empty for the store's default.
 * @var array<string, string>                   $form_layouts   Form layout names by layout; the empty layout is the store's default.
 * @var string                                  $option_groups  Where its options stand in the form, or empty for the store's default.
 * @var array<string, string>                   $option_choices Those choices' names; the empty one is the store's default. Empty on a variation, which follows its product.
 * @var string                                  $group_numbers  Whether its form numbers its groups, or empty for the store's default.
 * @var array<string, string>                   $number_choices Those choices' names; the empty one is the store's default. Empty on a variation, which follows its product.
 * @var string                                  $approval_mode  Its approval mode, or empty for the store's default.
 * @var string                                  $canvas_ratio   How the designer draws a print size whose ratio is not the template's, a `CanvasRatio` choice.
 * @var array<string, string>                   $canvas_ratios  The choices' names, by choice; the empty one follows the template.
 * @var string                                  $images_html    The product images field, rendered from `admin/product-showcase-images.php`; empty for a variation, or without a template.
 * @var string                                  $regenerate_html The Regenerate product image button, rendered from `admin/product-regenerate.php`; empty for a variation, or a product that shows no Pixonaut picture.
 * @var array<string, string>                   $approval_modes Approval mode names by mode; the empty mode is the store's default.
 */

defined( 'ABSPATH' ) || exit;
?>
<?php if ( '' !== $invalid ) : ?>
	<div class="inline notice notice-error pixonaut-product-invalid">
		<p>
			<?php echo esc_html( $notice_text ); ?>
			<?php echo esc_html( $invalid ); ?>
		</p>
	</div>
<?php endif; ?>

<div class="options_group pixonaut-group">
	<h4 class="pixonaut-group__title"><?php /* translators: Heading of a group of fields in the Pixonaut tab of the product edit page, shown to the merchant: whether customers design the product. */ esc_html_e( 'Personalisation', 'pixonaut-for-woocommerce' ); ?></h4>
	<p class="pixonaut-group__intro"><?php esc_html_e( 'Whether customers design this product before they buy it.', 'pixonaut-for-woocommerce' ); ?></p>
	<p class="form-field">
		<label for="<?php echo esc_attr( $ids['personalise'] ); ?>"><?php echo esc_html_x( 'Personalise', 'checkbox on the product: customers personalise it', 'pixonaut-for-woocommerce' ); ?></label>
		<span class="pixonaut-field-control">
			<input type="checkbox" class="checkbox pixonaut-personalise" id="<?php echo esc_attr( $ids['personalise'] ); ?>" name="<?php echo esc_attr( $names['personalise'] ); ?>" value="yes" <?php checked( $personalise ); ?> />
			<span class="description"><?php esc_html_e( 'Customers design this product with a Pixonaut template.', 'pixonaut-for-woocommerce' ); ?></span>
		</span>
		<span class="description pixonaut-unpersonalised-hint" <?php echo $personalise ? 'hidden' : ''; ?>><?php esc_html_e( 'Tick it to choose the blank it is printed on, its sides and their templates.', 'pixonaut-for-woocommerce' ); ?></span>
	</p>
</div>

<div class="pixonaut-personalised" <?php echo $personalise ? '' : 'hidden'; ?>>
<div class="options_group pixonaut-group">
	<h4 class="pixonaut-group__title"><?php /* translators: Heading of a group of fields in the Pixonaut tab of the product edit page, shown to the merchant: the blank the design is printed on. */ esc_html_e( 'Printed on', 'pixonaut-for-woocommerce' ); ?></h4>
	<p class="pixonaut-group__intro"><?php esc_html_e( 'The blank product the design is printed on, and which of its variants this is.', 'pixonaut-for-woocommerce' ); ?></p>
	<p class="form-field">
		<label for="<?php echo esc_attr( $ids['blank'] ); ?>"><?php echo esc_html_x( 'Blank', 'Pixonaut blank, the unprinted product a design is printed on', 'pixonaut-for-woocommerce' ); ?></label>
		<span class="pixonaut-field-control">
			<select id="<?php echo esc_attr( $ids['blank'] ); ?>" name="<?php echo esc_attr( $names['blank'] ); ?>" class="pixonaut-search pixonaut-blank" style="width: 50%;" data-placeholder="<?php /* translators: Placeholder of the blank dropdown: the product has no blank, only a template. Admin product page. */ esc_attr_e( 'No blank: template only', 'pixonaut-for-woocommerce' ); ?>" data-allow_clear="true">
				<option value=""></option>
				<?php if ( '' !== $blank_id ) : ?>
					<option value="<?php echo esc_attr( $blank_id ); ?>" selected="selected" data-thumbnail="<?php echo esc_url( $blank_thumbnail ); ?>"><?php echo esc_html( '' !== $blank_name ? $blank_name : $blank_id ); ?></option>
				<?php endif; ?>
			</select>
		</span>
		<span class="description"><?php esc_html_e( 'The product the design is printed on, e.g. a T-shirt. Without one, customers buy the template’s design alone, such as a poster or a card.', 'pixonaut-for-woocommerce' ); ?></span>
	</p>
	<p class="form-field pixonaut-printshop">
		<label for="<?php echo esc_attr( $ids['printshop'] ); ?>"><?php /* translators: Label of the printshop that makes the product's orders. Admin product page. */ esc_html_e( 'Printshop', 'pixonaut-for-woocommerce' ); ?></label>
		<span class="pixonaut-field-control">
			<select id="<?php echo esc_attr( $ids['printshop'] ); ?>" name="<?php echo esc_attr( $names['printshop'] ); ?>" class="pixonaut-printshop-choice pixonaut-without-blank" style="width: 50%;" <?php echo '' === $blank_id ? '' : 'hidden'; ?>>
				<?php foreach ( $printshops as $pixonaut_printshop_id => $pixonaut_printshop_name ) : ?>
					<option value="<?php echo esc_attr( (string) $pixonaut_printshop_id ); ?>" <?php selected( (string) $pixonaut_printshop_id, $printshop_id ); ?>><?php echo esc_html( $pixonaut_printshop_name ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php if ( null !== $printshop ) : ?>
				<select class="pixonaut-printshop-name pixonaut-with-blank" style="width: 50%;" disabled aria-label="<?php /* translators: Screen reader label of the locked printshop of a product on a blank. Admin product page. */ esc_attr_e( 'Printshop, decided by the blank', 'pixonaut-for-woocommerce' ); ?>">
					<option selected="selected"><?php echo esc_html( $printshop['name'] ); ?></option>
				</select>
				<?php if ( '' !== $printshop['url'] ) : ?>
					<a class="pixonaut-with-blank" href="<?php echo esc_url( $printshop['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php /* translators: Link to the Pixonaut studio where the merchant changes the blank's printshop. Admin product page. */ esc_html_e( 'Change in Pixonaut', 'pixonaut-for-woocommerce' ); ?></a>
				<?php endif; ?>
			<?php endif; ?>
		</span>
		<span class="description pixonaut-without-blank" <?php echo '' === $blank_id ? '' : 'hidden'; ?>><?php esc_html_e( 'Where this product’s orders are made. Workspace default sends them to the workspace’s default printshop.', 'pixonaut-for-woocommerce' ); ?></span>
		<span class="description pixonaut-with-blank" <?php echo '' === $blank_id ? 'hidden' : ''; ?>><?php esc_html_e( 'The blank decides where its prints are made.', 'pixonaut-for-woocommerce' ); ?></span>
	</p>
	<?php echo $variant_html ?? ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in admin/product-variant.php. ?>
</div>

<div class="options_group pixonaut-group">
	<h4 class="pixonaut-group__title"><?php /* translators: Heading of a group of fields in the Pixonaut tab of the product edit page, shown to the merchant: the product sides, such as front and back, each with its template. */ esc_html_e( 'Sides', 'pixonaut-for-woocommerce' ); ?></h4>
	<p class="pixonaut-group__intro"><?php esc_html_e( 'Each side is one place on the blank customers design, with its template and print sizes.', 'pixonaut-for-woocommerce' ); ?></p>
<div class="pixonaut-sides">
	<?php
	foreach ( $sides_html as $pixonaut_side_html ) {
		echo $pixonaut_side_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in admin/product-side.php.
	}
	?>
</div>
<template class="pixonaut-side-template"><?php echo $new_side_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in admin/product-side.php. ?></template>
<p class="pixonaut-sides-actions pixonaut-with-blank">
	<button type="button" class="button pixonaut-add-side"><?php /* translators: Button that adds a product side. Admin product page. */ esc_html_e( 'Add side', 'pixonaut-for-woocommerce' ); ?></button>
	<span class="description">
		<?php
		echo esc_html(
			sprintf(
				/* translators: %d: most sides a product can have. */
				__( 'Each placement can be one side, up to %d sides.', 'pixonaut-for-woocommerce' ),
				$max_sides
			)
		);
		?>
	</span>
	<span class="description pixonaut-sides-over-recommended" data-recommended="<?php echo esc_attr( (string) $recommended ); ?>" <?php echo count( $sides_html ) > $recommended ? '' : 'hidden'; ?>>
		<?php
		echo esc_html(
			sprintf(
				/* translators: %d: most sides Pixonaut's designer is built for. */
				__( 'Pixonaut recommends at most %d sides. Each side is one more step in the designer, and slows it down on the customer’s device.', 'pixonaut-for-woocommerce' ),
				$recommended
			)
		);
		?>
	</span>
</p>
<p class="pixonaut-sides-actions pixonaut-without-blank" <?php echo '' === $blank_id ? '' : 'hidden'; ?>>
	<span class="description"><?php esc_html_e( 'A product without a blank has one side. Choose a blank to give it more.', 'pixonaut-for-woocommerce' ); ?></span>
</p>
</div>
<div class="options_group pixonaut-group">
	<h4 class="pixonaut-group__title"><?php /* translators: Heading of a group of fields in the Pixonaut tab of the product edit page, shown to the merchant: how customers open the designer and approve their design. */ esc_html_e( 'On the product page', 'pixonaut-for-woocommerce' ); ?></h4>
	<p class="pixonaut-group__intro"><?php esc_html_e( 'How customers open the designer, the photos they see their design on, and how they approve it. Store default follows the Pixonaut settings page.', 'pixonaut-for-woocommerce' ); ?></p>
	<p class="form-field">
		<label for="<?php echo esc_attr( $ids['layout'] ); ?>"><?php /* translators: Label of the dropdown choosing how customers open the designer on the product page. Admin product page. */ esc_html_e( 'Layout', 'pixonaut-for-woocommerce' ); ?></label>
		<select id="<?php echo esc_attr( $ids['layout'] ); ?>" name="<?php echo esc_attr( $names['layout'] ); ?>" style="width: 50%;">
			<?php foreach ( $layouts as $pixonaut_layout => $pixonaut_layout_name ) : ?>
				<option value="<?php echo esc_attr( (string) $pixonaut_layout ); ?>" <?php selected( (string) $pixonaut_layout, $layout ); ?>><?php echo esc_html( $pixonaut_layout_name ); ?></option>
			<?php endforeach; ?>
		</select>
		<span class="description"><?php esc_html_e( 'How customers open the designer on the product page.', 'pixonaut-for-woocommerce' ); ?></span>
	</p>
	<p class="form-field">
		<label for="<?php echo esc_attr( $ids['form_layout'] ); ?>"><?php /* translators: Label of the dropdown choosing how the product page designer lays out its form. Admin product page. */ esc_html_e( 'Form layout', 'pixonaut-for-woocommerce' ); ?></label>
		<select id="<?php echo esc_attr( $ids['form_layout'] ); ?>" name="<?php echo esc_attr( $names['form_layout'] ); ?>" style="width: 50%;">
			<?php foreach ( $form_layouts as $pixonaut_form_layout => $pixonaut_form_layout_name ) : ?>
				<option value="<?php echo esc_attr( (string) $pixonaut_form_layout ); ?>" <?php selected( (string) $pixonaut_form_layout, $form_layout ); ?>><?php echo esc_html( $pixonaut_form_layout_name ); ?></option>
			<?php endforeach; ?>
		</select>
		<span class="description"><?php esc_html_e( 'How the designer on the product page lays out its form. The Personalise button opens Pixonaut’s designer, which always follows the template.', 'pixonaut-for-woocommerce' ); ?></span>
	</p>
	<?php if ( array() !== $option_choices ) : ?>
		<p class="form-field">
			<label for="<?php echo esc_attr( $ids['option_groups'] ); ?>"><?php /* translators: Label of the dropdown choosing where the product's options, such as Size or Colour, stand in the designer's form. Admin product page. */ esc_html_e( 'Options in the form', 'pixonaut-for-woocommerce' ); ?></label>
			<select id="<?php echo esc_attr( $ids['option_groups'] ); ?>" name="<?php echo esc_attr( $names['option_groups'] ); ?>" style="width: 50%;">
				<?php foreach ( $option_choices as $pixonaut_option_choice => $pixonaut_option_choice_name ) : ?>
					<option value="<?php echo esc_attr( (string) $pixonaut_option_choice ); ?>" <?php selected( (string) $pixonaut_option_choice, $option_groups ); ?>><?php echo esc_html( $pixonaut_option_choice_name ); ?></option>
				<?php endforeach; ?>
			</select>
			<span class="description"><?php esc_html_e( 'Where the product’s options, such as Size or Colour, stand in the designer on the product page: on top of the form, or each in a group of its own named after it. A side whose Groups you arranged keeps them.', 'pixonaut-for-woocommerce' ); ?></span>
		</p>
		<p class="form-field">
			<label for="<?php echo esc_attr( $ids['group_numbers'] ); ?>"><?php /* translators: Label of the dropdown choosing whether the designer's form numbers its groups. Admin product page. */ esc_html_e( 'Group numbers', 'pixonaut-for-woocommerce' ); ?></label>
			<select id="<?php echo esc_attr( $ids['group_numbers'] ); ?>" name="<?php echo esc_attr( $names['group_numbers'] ); ?>" style="width: 50%;">
				<?php foreach ( $number_choices as $pixonaut_number_choice => $pixonaut_number_choice_name ) : ?>
					<option value="<?php echo esc_attr( (string) $pixonaut_number_choice ); ?>" <?php selected( (string) $pixonaut_number_choice, $group_numbers ); ?>><?php echo esc_html( $pixonaut_number_choice_name ); ?></option>
				<?php endforeach; ?>
			</select>
			<span class="description"><?php esc_html_e( 'Whether the designer on the product page numbers its groups: 1 Photo, 2 Text on one page, Step 1 of 2 in steps. Off shows the group names with no numbers.', 'pixonaut-for-woocommerce' ); ?></span>
		</p>
	<?php endif; ?>
	<p class="form-field">
		<label for="<?php echo esc_attr( $ids['canvas_ratio'] ); ?>"><?php /* translators: Label of the dropdown choosing whether the designer's canvas keeps the template's proportions or takes the chosen print size's. Admin product page. */ esc_html_e( 'Canvas shape', 'pixonaut-for-woocommerce' ); ?></label>
		<select id="<?php echo esc_attr( $ids['canvas_ratio'] ); ?>" name="<?php echo esc_attr( $names['canvas_ratio'] ); ?>" style="width: 50%;">
			<?php foreach ( $canvas_ratios as $pixonaut_ratio => $pixonaut_ratio_name ) : ?>
				<option value="<?php echo esc_attr( (string) $pixonaut_ratio ); ?>" <?php selected( (string) $pixonaut_ratio, $canvas_ratio ); ?>><?php echo esc_html( $pixonaut_ratio_name ); ?></option>
			<?php endforeach; ?>
		</select>
		<span class="description"><?php esc_html_e( 'The shape customers design on when a print size’s proportions are not the template’s. In the template’s shape, the canvas keeps its shape whatever size is chosen, and the print is cropped to the size as the template fits it; in the print size’s shape, the canvas changes shape with each size. Each side can choose otherwise.', 'pixonaut-for-woocommerce' ); ?></span>
	</p>
	<p class="form-field">
		<label for="<?php echo esc_attr( $ids['approval_mode'] ); ?>"><?php /* translators: Label of the dropdown choosing how customers approve their design (proof) before printing. Admin product page. */ esc_html_e( 'Approval', 'pixonaut-for-woocommerce' ); ?></label>
		<select id="<?php echo esc_attr( $ids['approval_mode'] ); ?>" name="<?php echo esc_attr( $names['approval_mode'] ); ?>" style="width: 50%;">
			<?php foreach ( $approval_modes as $pixonaut_mode => $pixonaut_mode_name ) : ?>
				<option value="<?php echo esc_attr( (string) $pixonaut_mode ); ?>" <?php selected( (string) $pixonaut_mode, $approval_mode ); ?>><?php echo esc_html( $pixonaut_mode_name ); ?></option>
			<?php endforeach; ?>
		</select>
		<span class="description"><?php esc_html_e( 'How customers approve their design before it is printed.', 'pixonaut-for-woocommerce' ); ?></span>
	</p>
	<div class="pixonaut-with-blank pixonaut-views" data-blank="<?php echo esc_attr( $blank_id ); ?>">
		<div class="form-field pixonaut-views__shown">
			<label class="pixonaut-views__label"><?php /* translators: Label of the views customers see their design in. Admin product page. */ esc_html_e( 'Shown in', 'pixonaut-for-woocommerce' ); ?></label>
			<?php if ( null !== $views ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $names['views'] . '[' . \Pixonaut\WooCommerce\Admin\ProductPanel::VIEWS_BLANK_KEY . ']' ); ?>" value="<?php echo esc_attr( $blank_id ); ?>" />
				<?php foreach ( $views as $pixonaut_placement ) : ?>
					<fieldset class="pixonaut-views__placement">
						<legend><?php echo esc_html( $pixonaut_placement['name'] ); ?></legend>
						<ul class="pixonaut-views__list">
							<?php foreach ( $pixonaut_placement['views'] as $pixonaut_view ) : ?>
								<li class="pixonaut-view">
									<label>
										<input type="checkbox" name="<?php echo esc_attr( $names['views'] . '[' . \Pixonaut\WooCommerce\Admin\ProductPanel::VIEWS_TICKED_KEY . '][]' ); ?>" value="<?php echo esc_attr( $pixonaut_view['id'] ); ?>" <?php checked( in_array( $pixonaut_view['id'], $chosen_views, true ) ); ?> />
										<?php if ( '' !== $pixonaut_view['thumbnail'] ) : ?>
											<img src="<?php echo esc_url( $pixonaut_view['thumbnail'] ); ?>" alt="" loading="lazy" />
										<?php endif; ?>
										<span><?php echo esc_html( $pixonaut_view['label'] ); ?></span>
									</label>
								</li>
							<?php endforeach; ?>
							<?php if ( array() === $pixonaut_placement['views'] ) : ?>
								<li class="description"><?php esc_html_e( 'This placement has no views in Pixonaut yet.', 'pixonaut-for-woocommerce' ); ?></li>
							<?php endif; ?>
						</ul>
					</fieldset>
				<?php endforeach; ?>
				<fieldset class="pixonaut-views__placement pixonaut-views__design">
					<legend><?php echo esc_html_x( 'Design', 'view of the design alone, without the product', 'pixonaut-for-woocommerce' ); ?></legend>
					<ul class="pixonaut-views__list">
						<li class="pixonaut-view">
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $names['views'] . '[' . \Pixonaut\WooCommerce\Admin\ProductPanel::VIEWS_TICKED_KEY . '][]' ); ?>" value="<?php echo esc_attr( \Pixonaut\WooCommerce\Catalogue\ProductConfig::DESIGN_VIEW ); ?>" <?php checked( in_array( \Pixonaut\WooCommerce\Catalogue\ProductConfig::DESIGN_VIEW, $chosen_views, true ) ); ?> />
								<span><?php esc_html_e( 'The design alone, without the product', 'pixonaut-for-woocommerce' ); ?></span>
							</label>
						</li>
					</ul>
				</fieldset>
			<?php elseif ( '' !== $blank_id ) : ?>
				<p class="description"><?php esc_html_e( 'Pixonaut could not be reached to list the blank’s views. The views chosen before are kept.', 'pixonaut-for-woocommerce' ); ?></p>
			<?php endif; ?>
			<p class="description pixonaut-views-unlisted" hidden><?php esc_html_e( 'The blank’s views are listed once the product is updated with it.', 'pixonaut-for-woocommerce' ); ?></p>
			<?php if ( null === $views ) : ?>
				<span class="description"><?php esc_html_e( 'Customers see their design in the views ticked here, and on its own in the Design view when that is ticked. With none ticked, they see it in every view of the blank and in the Design view. The designer opens on the starting view, one of those ticked.', 'pixonaut-for-woocommerce' ); ?></span>
			<?php endif; ?>
		</div>
		<?php if ( null !== $views ) : ?>
			<p class="form-field pixonaut-views__initial">
				<label for="<?php echo esc_attr( $ids['initial_view'] ); ?>"><?php /* translators: Label of the dropdown choosing which view the designer opens on. Admin product page. */ esc_html_e( 'Starting view', 'pixonaut-for-woocommerce' ); ?></label>
				<select id="<?php echo esc_attr( $ids['initial_view'] ); ?>" name="<?php echo esc_attr( $names['views'] . '[' . \Pixonaut\WooCommerce\Admin\ProductPanel::VIEWS_INITIAL_KEY . ']' ); ?>" class="pixonaut-initial-view">
					<option value=""><?php /* translators: Option in the starting view dropdown: open on the first view of the blank. Admin product page. */ esc_html_e( 'The first view', 'pixonaut-for-woocommerce' ); ?></option>
					<?php foreach ( $views as $pixonaut_placement ) : ?>
						<?php if ( array() !== $pixonaut_placement['views'] ) : ?>
							<optgroup label="<?php echo esc_attr( $pixonaut_placement['name'] ); ?>">
								<?php foreach ( $pixonaut_placement['views'] as $pixonaut_view ) : ?>
									<option value="<?php echo esc_attr( $pixonaut_view['id'] ); ?>" <?php selected( $pixonaut_view['id'], $initial_view ); ?>><?php echo esc_html( $pixonaut_view['label'] ); ?></option>
								<?php endforeach; ?>
							</optgroup>
						<?php endif; ?>
					<?php endforeach; ?>
					<option value="<?php echo esc_attr( \Pixonaut\WooCommerce\Catalogue\ProductConfig::DESIGN_VIEW ); ?>" <?php selected( \Pixonaut\WooCommerce\Catalogue\ProductConfig::DESIGN_VIEW, $initial_view ); ?>><?php echo esc_html_x( 'Design', 'view of the design alone, without the product', 'pixonaut-for-woocommerce' ); ?></option>
				</select>
				<span class="description"><?php esc_html_e( 'Customers see their design in the views ticked here, and on its own in the Design view when that is ticked. With none ticked, they see it in every view of the blank and in the Design view. The designer opens on the starting view, one of those ticked.', 'pixonaut-for-woocommerce' ); ?></span>
			</p>
		<?php endif; ?>
	</div>
</div>
<?php echo $images_html ?? ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in admin/product-showcase-images.php. ?>
<?php echo $regenerate_html ?? ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in admin/product-regenerate.php. ?>
</div>
