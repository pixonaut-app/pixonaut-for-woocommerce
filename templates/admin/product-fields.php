<?php
/**
 * One Pixonaut configuration's fields: the product's, in the Pixonaut tab, or
 * a variation's, in its Pixonaut panel.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/admin/product-fields.php.
 * Field names, classes and the `data-` attributes are what the plugin reads; keep them.
 *
 * @package Pixonaut\WooCommerce
 * @version 1.4.0
 *
 * @var array{personalise: string, blank: string, layout: string, form_layout: string, approval_mode: string, views: string, printshop: string, canvas_ratio: string, option_groups: string, group_numbers: string} $names Each field's posted name.
 * @var array{personalise: string, blank: string, layout: string, form_layout: string, approval_mode: string, initial_view: string, printshop: string, canvas_ratio: string, option_groups: string, group_numbers: string} $ids Each field's id.
 * @var string                                  $notice_text    What the invalid notice starts with.
 * @var int                                     $max_sides      Most sides a product has.
 * @var int                                     $recommended    Most sides Pixonaut's designer is built for; more are shown a hint.
 * @var bool                                    $personalise    Whether customers personalise it.
 * @var string                                  $personalise_text What the Personalise checkbox says: that customers design the product, or the variation.
 * @var string                                  $blank_id       Linked blank, or empty.
 * @var string                                  $blank_name     Its name.
 * @var string                                  $blank_thumbnail Its first photo, shown beside its name; empty without one.
 * @var string                                  $blank_html     What depends on the blank, rendered from `admin/product-blank.php`: its printshop and variant;
 *                                                              empty without a blank.
 * @var string                                  $views_html     The views the product is shown in, rendered from `admin/product-views.php`.
 * @var list<string>                            $open           The disclosures open when the product was last updated (`views`, `store`, `side-<index>`).
 * @var string                                  $open_field     The field posting the disclosures open, for the product's own fields; empty for a variation's.
 * @var array<string, string>                   $printshops     The printshops a product without a blank can choose, names by id; the empty id is the workspace's default.
 *                                                              Empty when no printshop of the workspace prints without a blank.
 * @var string                                  $printshops_url The workspace's printshops in the Pixonaut studio; empty while not known.
 * @var string                                  $printshop_id   The printshop it chose, or empty for the workspace's default.
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

$pixonaut_store_changed     = count(
	array_filter(
		array( $layout, $form_layout, $option_groups, $group_numbers, $approval_mode, \Pixonaut\WooCommerce\Catalogue\CanvasRatio::FOLLOW === $canvas_ratio ? '' : $canvas_ratio ),
		static fn ( string $value ): bool => '' !== $value
	)
);
$pixonaut_printshop_refused = \Pixonaut\WooCommerce\Catalogue\InvalidReason::message( \Pixonaut\WooCommerce\Catalogue\InvalidReason::PRINTSHOP_NEEDS_BLANK ) === $invalid;

/**
 * Prints one of the store settings a product can change: a label and its select.
 *
 * @param string                $key     The field's key in `$names` and `$ids`.
 * @param string                $label   Its label.
 * @param array<string, string> $choices Its choices' names, by choice.
 * @param string                $current The choice made.
 */
$pixonaut_setting = static function ( string $key, string $label, array $choices, string $current ) use ( $names, $ids ): void {
	?>
	<p class="form-field">
		<label for="<?php echo esc_attr( $ids[ $key ] ); ?>"><?php echo esc_html( $label ); ?></label>
		<select id="<?php echo esc_attr( $ids[ $key ] ); ?>" name="<?php echo esc_attr( $names[ $key ] ); ?>" class="pixonaut-store-setting" style="width: 50%;">
			<?php foreach ( $choices as $pixonaut_choice => $pixonaut_choice_name ) : ?>
				<option value="<?php echo esc_attr( (string) $pixonaut_choice ); ?>" <?php selected( (string) $pixonaut_choice, $current ); ?>><?php echo esc_html( $pixonaut_choice_name ); ?></option>
			<?php endforeach; ?>
		</select>
	</p>
	<?php
};
?>
<?php if ( '' !== $invalid ) : ?>
	<div class="inline notice notice-error pixonaut-product-invalid">
		<p>
			<?php echo esc_html( $notice_text ); ?>
			<?php echo esc_html( $invalid ); ?>
		</p>
	</div>
<?php endif; ?>

<div class="options_group pixonaut-group pixonaut-group--personalise">
	<p class="form-field">
		<label for="<?php echo esc_attr( $ids['personalise'] ); ?>"><?php echo esc_html_x( 'Personalise', 'checkbox on the product: customers personalise it', 'pixonaut-for-woocommerce' ); ?></label>
		<span class="pixonaut-field-control">
			<input type="checkbox" class="checkbox pixonaut-personalise" id="<?php echo esc_attr( $ids['personalise'] ); ?>" name="<?php echo esc_attr( $names['personalise'] ); ?>" value="yes" <?php checked( $personalise ); ?> />
			<span class="description"><?php echo esc_html( $personalise_text ); ?></span>
		</span>
	</p>
</div>

<div class="pixonaut-personalised" <?php echo $personalise ? '' : 'hidden'; ?>>
<?php if ( '' !== $open_field ) : ?>
	<input type="hidden" class="pixonaut-open-disclosures" name="<?php echo esc_attr( $open_field ); ?>" value="<?php echo esc_attr( implode( ',', $open ) ); ?>" />
<?php endif; ?>
<div class="options_group pixonaut-group">
	<h4 class="pixonaut-group__title"><?php /* translators: Heading of a group of fields in the Pixonaut tab of the product edit page, shown to the merchant: what the product is printed on, and where. */ esc_html_e( 'Printing', 'pixonaut-for-woocommerce' ); ?></h4>
	<p class="form-field">
		<label for="<?php echo esc_attr( $ids['blank'] ); ?>"><?php /* translators: Label of the blank picker: what the product is printed on, a blank such as a T-shirt, or nothing. Admin product page. */ esc_html_e( 'Printed on', 'pixonaut-for-woocommerce' ); ?></label>
		<span class="pixonaut-field-control">
			<select id="<?php echo esc_attr( $ids['blank'] ); ?>" name="<?php echo esc_attr( $names['blank'] ); ?>" class="pixonaut-search pixonaut-blank" style="width: 50%;" data-placeholder="<?php /* translators: Placeholder of the blank picker: the product has no blank, only the design is printed. Admin product page. */ esc_attr_e( 'No blank: the design alone', 'pixonaut-for-woocommerce' ); ?>" data-allow_clear="true">
				<option value=""></option>
				<?php if ( '' !== $blank_id ) : ?>
					<option value="<?php echo esc_attr( $blank_id ); ?>" selected="selected" data-thumbnail="<?php echo esc_url( $blank_thumbnail ); ?>"><?php echo esc_html( '' !== $blank_name ? $blank_name : $blank_id ); ?></option>
				<?php endif; ?>
			</select>
		</span>
		<span class="description pixonaut-without-blank" <?php echo '' === $blank_id ? '' : 'hidden'; ?>><?php esc_html_e( 'Without a blank, the design is printed by itself, such as a poster or a card.', 'pixonaut-for-woocommerce' ); ?></span>
	</p>
	<p class="form-field pixonaut-blank-loading" hidden>
		<span class="pixonaut-field-control pixonaut-blank-reading"><span class="spinner is-active"></span><?php esc_html_e( 'Reading the blank from Pixonaut…', 'pixonaut-for-woocommerce' ); ?></span>
		<span class="pixonaut-field-error pixonaut-blank-failed" role="alert" hidden><?php /* translators: Shown in the Pixonaut tab of the product edit page when a blank just picked could not be read; updating the product saves the blank and reads it again. */ esc_html_e( 'Pixonaut could not be reached to read this blank. Update the product to try again.', 'pixonaut-for-woocommerce' ); ?></span>
	</p>
	<p class="form-field pixonaut-printshop pixonaut-without-blank" <?php echo '' === $blank_id ? '' : 'hidden'; ?>>
		<label for="<?php echo esc_attr( $ids['printshop'] ); ?>"><?php /* translators: Label of the printshop that makes the product's orders. Admin product page. */ esc_html_e( 'Printshop', 'pixonaut-for-woocommerce' ); ?></label>
		<?php if ( array() !== $printshops ) : ?>
			<span class="pixonaut-field-control">
				<select id="<?php echo esc_attr( $ids['printshop'] ); ?>" name="<?php echo esc_attr( $names['printshop'] ); ?>" class="pixonaut-printshop-choice" style="width: 50%;" <?php echo $pixonaut_printshop_refused ? 'aria-invalid="true"' : ''; ?>>
					<?php foreach ( $printshops as $pixonaut_printshop_id => $pixonaut_printshop_name ) : ?>
						<option value="<?php echo esc_attr( (string) $pixonaut_printshop_id ); ?>" <?php selected( (string) $pixonaut_printshop_id, $printshop_id ); ?>><?php echo esc_html( $pixonaut_printshop_name ); ?></option>
					<?php endforeach; ?>
				</select>
			</span>
			<?php if ( $pixonaut_printshop_refused ) : ?>
				<span class="pixonaut-field-error" role="alert"><?php echo esc_html( $invalid ); ?></span>
			<?php endif; ?>
			<span class="description"><?php esc_html_e( 'Only printshops that print without a blank are listed.', 'pixonaut-for-woocommerce' ); ?></span>
		<?php else : ?>
			<span class="pixonaut-field-control pixonaut-printshop-none">
				<?php esc_html_e( 'No printshop in your workspace prints without a blank.', 'pixonaut-for-woocommerce' ); ?>
				<?php if ( '' !== $printshops_url ) : ?>
					<a href="<?php echo esc_url( $printshops_url ); ?>" target="_blank" rel="noopener noreferrer"><?php /* translators: Link to the printshops of the Pixonaut studio, where the merchant adds one. Admin product page. */ esc_html_e( 'Add one in Pixonaut', 'pixonaut-for-woocommerce' ); ?></a>
				<?php endif; ?>
			</span>
		<?php endif; ?>
	</p>
	<div class="pixonaut-blank-fields pixonaut-with-blank" <?php echo '' === $blank_id ? 'hidden' : ''; ?>>
		<?php echo $blank_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in admin/product-blank.php. ?>
	</div>
</div>

<div class="options_group pixonaut-group pixonaut-sides-group">
	<h4 class="pixonaut-group__title">
		<span class="pixonaut-sides-title--one"><?php /* translators: Heading of the group of fields in the Pixonaut tab of the product edit page holding a product's only side: its template and the print sizes customers choose. */ esc_html_e( 'Template and print sizes', 'pixonaut-for-woocommerce' ); ?></span>
		<span class="pixonaut-sides-title--many"><?php /* translators: Heading of a group of fields in the Pixonaut tab of the product edit page, shown to the merchant: the product's sides, such as front and back, each with its template. */ esc_html_e( 'Sides', 'pixonaut-for-woocommerce' ); ?></span>
	</h4>
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
				__( 'Another print position, such as the back. Up to %d sides.', 'pixonaut-for-woocommerce' ),
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
</div>
<?php echo $images_html ?? ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in admin/product-showcase-images.php. ?>
<?php echo $regenerate_html ?? ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in admin/product-regenerate.php. ?>

<div class="pixonaut-views-disclosure pixonaut-with-blank" <?php echo '' === $blank_id ? 'hidden' : ''; ?>>
	<?php echo $views_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in admin/product-views.php. ?>
</div>

<details class="pixonaut-group pixonaut-disclosure pixonaut-store-settings" data-disclosure="store" <?php echo $pixonaut_store_changed > 0 || in_array( 'store', $open, true ) ? 'open' : ''; ?>>
	<summary>
		<strong><?php /* translators: Heading of a closed section in the Pixonaut tab of the product edit page: the Pixonaut settings page's choices, changed for this product alone. */ esc_html_e( 'Store settings for this product', 'pixonaut-for-woocommerce' ); ?></strong>
		<span class="pixonaut-disclosure__summary" data-none="<?php esc_attr_e( 'Follows the store’s settings', 'pixonaut-for-woocommerce' ); ?>" data-some="<?php /* translators: %d: how many of the store's settings this product changes. Admin product page. */ esc_attr_e( '%d changed for this product', 'pixonaut-for-woocommerce' ); ?>">
			<?php
			echo esc_html(
				0 === $pixonaut_store_changed
					? __( 'Follows the store’s settings', 'pixonaut-for-woocommerce' )
					/* translators: %d: how many of the store's settings this product changes. Admin product page. */
					: sprintf( _n( '%d changed for this product', '%d changed for this product', $pixonaut_store_changed, 'pixonaut-for-woocommerce' ), $pixonaut_store_changed )
			);
			?>
		</span>
	</summary>
	<p class="pixonaut-group__intro"><?php esc_html_e( 'Each follows the Pixonaut settings page until you change it here.', 'pixonaut-for-woocommerce' ); ?></p>
	<?php
	/* translators: Label of the dropdown choosing how customers open the designer on the product page. Admin product page. */
	$pixonaut_setting( 'layout', __( 'Layout', 'pixonaut-for-woocommerce' ), $layouts, $layout );
	/* translators: Label of the dropdown choosing how the product page designer lays out its form. Admin product page. */
	$pixonaut_setting( 'form_layout', __( 'Form layout', 'pixonaut-for-woocommerce' ), $form_layouts, $form_layout );
	if ( array() !== $option_choices ) {
		/* translators: Label of the dropdown choosing where the product's options, such as Size or Colour, stand in the designer's form. Admin product page. */
		$pixonaut_setting( 'option_groups', __( 'Options in the form', 'pixonaut-for-woocommerce' ), $option_choices, $option_groups );
		/* translators: Label of the dropdown choosing whether the designer's form numbers its groups. Admin product page. */
		$pixonaut_setting( 'group_numbers', __( 'Group numbers', 'pixonaut-for-woocommerce' ), $number_choices, $group_numbers );
	}
	/* translators: Label of the dropdown choosing whether the designer's canvas keeps the template's proportions or takes the chosen print size's, asked as a question. Admin product page. */
	$pixonaut_setting( 'canvas_ratio', __( 'When a print size has other proportions', 'pixonaut-for-woocommerce' ), $canvas_ratios, $canvas_ratio );
	/* translators: Label of the dropdown choosing how customers approve their design (proof) before printing. Admin product page. */
	$pixonaut_setting( 'approval_mode', __( 'Approval', 'pixonaut-for-woocommerce' ), $approval_modes, $approval_mode );
	?>
</details>
</div>
