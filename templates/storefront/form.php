<?php
/**
 * The personalisation form, at the top of a personalised product's
 * add-to-cart form.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/storefront/form.php.
 * The storefront script mounts each side's form into its
 * `#pixonaut-form-<side>` and writes each side's proof token into its
 * `<field>[<side>]` field when the customer adds to the cart. Keep the fields
 * inside the form. In the checkbox approval mode, adding to the cart with the
 * approval box unticked marks `.pixonaut-approval` `is-invalid` and shows
 * `.pixonaut-approval__error` until the box is ticked, and each side's proof
 * button opens the proof of its design: the linked word in the box's label
 * ("preview"), or an `Open proof` button beside the box when the label links
 * none, in a dialog the script adds over the page, whose close button takes
 * the theme's button class from `data-pixonaut-button-class`. On a page opened to
 * edit a cart line's design, or an order line's after the customer asked for
 * changes to its proof, `data-pixonaut-edit` names the line and the routes
 * that save it, and the button saves the line instead of adding to the cart.
 * On a variable product, `data-pixonaut-variations` says what each variation
 * switches the designer to; the script hides the form of a variation bought
 * without a design. `data-pixonaut-variant` names the blank variant the
 * designer shows a product without variations in.
 *
 * A product with more than one side gets a tab per side. The script shows
 * the selected side's form, preview and proof button and hides the others';
 * each part says its side in `data-pixonaut-side`.
 *
 * On a product with one side, the designer draws the add-to-cart form's
 * variation fields in its first step, with its own colour, size and print
 * size pickers, and the rest of the add-to-cart row (the approval box, the
 * variation's price, the quantity and the button) on its last step, at the
 * end of the form when it is not laid out in steps. The script gathers that
 * row in a `.pixonaut-cart-row` right after this template's element, so
 * keep the button after it. Both stand in the printed form's slots from the
 * first paint, moved there by a script printed after the add-to-cart form.
 *
 * Each side's form is printed as its designer draws it, its steps and fields
 * with their controls, as `.pixonaut-form__placeholder`
 * (`storefront/form-placeholder.php`), so the page does not move when the
 * designer replaces it. The side's accent colour, when the store sets one,
 * is in its `--pixonaut-accent`. The `.pixonaut-reads` script holds, by side key, the
 * Pixonaut reads the server made for the designer, which it starts from
 * instead of reading them again in the browser, and in `data-pixonaut-read-at`
 * when they were made: the photo links in them expire, so a page a cache
 * kept longer leaves the reads to the browser.
 *
 * `data-pixonaut-group-numbers="off"` says the form numbers none of its
 * groups, which the stylesheet and the script leave out of both forms.
 *
 * `data-pixonaut-look` lists the theme's presets the designer takes its
 * look from (`ThemeLook`), which the stylesheet draws the printed form's
 * step badges, the tabs and the approval box by. On a variable product whose
 * page prints the price of the variation the form opens on,
 * `data-pixonaut-product-price` holds the product's own price, which the
 * script shows again once no variation is chosen, and
 * `data-pixonaut-opening-choice` the attribute values the printed price is for,
 * by field name, which the script keeps it for while the form holds them; left
 * out, it shows the product's own as soon as the form holds no variation.
 *
 * @package Pixonaut\WooCommerce
 * @version 0.1.0
 *
 * @var int                                 $product_id      The product.
 * @var array<string, array<string, mixed>> $mounts          Mount options by side key; empty when personalisation is unavailable.
 * @var string[]                            $sides           The product's side keys, each with a designs field.
 * @var array<string, string>               $side_labels     Each mounted side's name, by side key.
 * @var string                              $sides_label     The side tabs' accessible name.
 * @var string                              $field           Name of the designs field, indexed by side key.
 * @var string                              $unavailable     Text shown when personalisation is unavailable, or why an order line's design cannot be changed.
 * @var string                              $approval_field  Name of the approval box; empty unless the product's approval mode is the checkbox.
 * @var string                              $approve_preview The approval box's label, as plain text.
 * @var array{0: string, 1: string, 2: string}|null $approve_link The approval box's label split around the words that open the proof: before, the words, after; null when it links none.
 * @var string                              $open_proof      The proof button's label, beside the box when its label links no words.
 * @var string                              $button_class    The block theme's button class for the side tabs and the proof buttons; empty under a classic theme.
 * @var array<string, string>               $edit            The edited line's `key` (a cart item key, or an order line's signed state), `updateUrl`, `linkUrl` and `nonce`; empty when the page adds a new line.
 * @var array<int, array<string, mixed>>    $variations      On a variable product: what each variation switches the designer to, by variation id; empty otherwise.
 * @var string                              $variant         The ref of the blank variant the designer shows the product in; empty for none.
 * @var array<string, string>               $forms           Each mounted side's form as its designer draws it (`storefront/form-placeholder.php`), by side key.
 * @var string                              $reads           The server's Pixonaut reads, by side key, as a JSON object safe inside a script element; empty for none.
 * @var int                                 $read_at         When the page was made, in Unix seconds.
 * @var bool                                $group_numbers   Whether the form numbers its groups; left out, it does.
 * @var string                              $product_price   The product's own price HTML when the page prints its opening variation's; empty otherwise.
 * @var array<string, string>               $opening_choice  The attribute values the page prints the opening variation's price for, by field name; left out or empty when it prints the product's own.
 * @var string[]                            $look            The theme presets the designer takes its look from (`ThemeLook`).
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="pixonaut-form" data-pixonaut-product="<?php echo esc_attr( (string) $product_id ); ?>" data-pixonaut-field="<?php echo esc_attr( $field ); ?>"<?php echo array() === $mounts ? '' : ' data-pixonaut-mounts="' . esc_attr( (string) wp_json_encode( $mounts ) ) . '"'; ?><?php echo array() === $edit ? '' : ' data-pixonaut-edit="' . esc_attr( (string) wp_json_encode( $edit ) ) . '"'; ?><?php echo array() === $variations ? '' : ' data-pixonaut-variations="' . esc_attr( (string) wp_json_encode( (object) $variations ) ) . '"'; ?><?php echo '' === $variant ? '' : ' data-pixonaut-variant="' . esc_attr( $variant ) . '"'; ?><?php echo '' === ( $product_price ?? '' ) ? '' : ' data-pixonaut-product-price="' . esc_attr( $product_price ) . '"'; ?><?php echo array() === ( $opening_choice ?? array() ) ? '' : ' data-pixonaut-opening-choice="' . esc_attr( (string) wp_json_encode( $opening_choice ) ) . '"'; ?><?php echo array() === ( $look ?? array() ) ? '' : ' data-pixonaut-look="' . esc_attr( implode( ' ', $look ) ) . '"'; ?><?php echo false === ( $group_numbers ?? true ) ? ' data-pixonaut-group-numbers="off"' : ''; ?>>
	<p class="pixonaut-form__unavailable" role="alert"<?php echo array() === $mounts ? '' : ' hidden'; ?>><?php echo esc_html( $unavailable ); ?></p>
	<p class="pixonaut-form__error" role="alert" hidden></p>
	<?php $pixonaut_first = (string) array_key_first( $mounts ); ?>
	<?php $pixonaut_tabbed = count( $mounts ) > 1; ?>
	<?php if ( $pixonaut_tabbed ) : ?>
		<div class="pixonaut-tabs" role="tablist" aria-label="<?php echo esc_attr( $sides_label ); ?>">
			<?php foreach ( array_keys( $mounts ) as $pixonaut_side ) : ?>
				<button type="button" role="tab" id="<?php echo esc_attr( 'pixonaut-tab-' . $pixonaut_side ); ?>" class="<?php echo esc_attr( trim( 'pixonaut-tabs__tab ' . $button_class ) ); ?>" data-pixonaut-side="<?php echo esc_attr( (string) $pixonaut_side ); ?>" aria-controls="<?php echo esc_attr( 'pixonaut-form-' . $pixonaut_side ); ?>" aria-selected="<?php echo $pixonaut_first === (string) $pixonaut_side ? 'true' : 'false'; ?>" tabindex="<?php echo $pixonaut_first === (string) $pixonaut_side ? '0' : '-1'; ?>"><?php echo esc_html( $side_labels[ $pixonaut_side ] ?? (string) $pixonaut_side ); ?></button>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
	<?php foreach ( array_keys( $mounts ) as $pixonaut_side ) : ?>
		<?php $pixonaut_accent = is_string( $mounts[ $pixonaut_side ]['accent'] ?? null ) ? $mounts[ $pixonaut_side ]['accent'] : ''; ?>
		<div id="<?php echo esc_attr( 'pixonaut-form-' . $pixonaut_side ); ?>" class="pixonaut-form__side" data-pixonaut-side="<?php echo esc_attr( (string) $pixonaut_side ); ?>"<?php echo '' === $pixonaut_accent ? '' : ' style="' . esc_attr( '--pixonaut-accent: ' . $pixonaut_accent ) . '"'; ?><?php echo $pixonaut_tabbed ? ' role="tabpanel" aria-labelledby="' . esc_attr( 'pixonaut-tab-' . $pixonaut_side ) . '"' : ''; ?><?php echo $pixonaut_first === (string) $pixonaut_side ? '' : ' hidden'; ?>>
			<?php echo $forms[ $pixonaut_side ] ?? ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A template's output, escaped there. ?>
		</div>
	<?php endforeach; ?>
	<?php if ( '' !== $reads ) : ?>
		<script type="application/json" class="pixonaut-reads" data-pixonaut-read-at="<?php echo esc_attr( (string) $read_at ); ?>"><?php echo $reads; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON with `<`, `>` and `&` escaped, so it cannot close the element. ?></script>
	<?php endif; ?>
	<?php if ( array() !== $mounts && '' !== $approval_field ) : ?>
		<div class="pixonaut-approval" data-pixonaut-button-class="<?php echo esc_attr( $button_class ); ?>">
			<label class="pixonaut-approval__label">
				<input type="checkbox" class="pixonaut-approval__box" name="<?php echo esc_attr( $approval_field ); ?>" value="yes" required />
				<?php if ( null === $approve_link ) : ?>
					<?php echo esc_html( $approve_preview ); ?>
				<?php else : ?>
					<span>
					<?php
					echo esc_html( $approve_link[0] );
					foreach ( array_keys( $mounts ) as $pixonaut_side ) {
						echo '<button type="button" class="pixonaut-approval__proof pixonaut-approval__proof--link" data-pixonaut-side="' . esc_attr( (string) $pixonaut_side ) . '"' . ( $pixonaut_first === (string) $pixonaut_side ? '' : ' hidden' ) . '>' . esc_html( $approve_link[1] ) . '</button>';
					}
					echo esc_html( $approve_link[2] );
					?>
					</span>
				<?php endif; ?>
			</label>
			<?php if ( null === $approve_link ) : ?>
				<?php foreach ( array_keys( $mounts ) as $pixonaut_side ) : ?>
					<button type="button" class="<?php echo esc_attr( trim( 'pixonaut-approval__proof ' . $button_class ) ); ?>" data-pixonaut-side="<?php echo esc_attr( (string) $pixonaut_side ); ?>"<?php echo $pixonaut_first === (string) $pixonaut_side ? '' : ' hidden'; ?>><?php echo esc_html( $open_proof ); ?></button>
				<?php endforeach; ?>
			<?php endif; ?>
			<p class="pixonaut-approval__error" role="alert" hidden></p>
		</div>
	<?php endif; ?>
	<?php foreach ( $sides as $pixonaut_side ) : ?>
		<input type="hidden" name="<?php echo esc_attr( $field . '[' . $pixonaut_side . ']' ); ?>" value="" />
	<?php endforeach; ?>
</div>
