<?php
/**
 * Which blank variant each variation of a variable product is, in the
 * product's Pixonaut tab: one row per variation, unpaired ones first.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/admin/product-variant-pairing.php.
 * The fields' names and the classes are what the plugin reads; keep them.
 *
 * @package Pixonaut\WooCommerce
 * @version 1.4.0
 *
 * @var list<array{id: int, name: string, url: string, picked: string, matched: string, choices: array<string, string>, unpaired: bool, area: string}> $rows Each variation: its id, attributes, link, picked variant (empty to match), the automatic choice's label, the variants by ref, whether no variant is its, and its variant's print area.
 * @var int    $more        Variations past the rows drawn.
 * @var int    $total       Variations in all.
 * @var int    $paired      Variations with a variant.
 * @var string $printshop   Whose variants they are, such as Gelato; "Blank" for the store's own blank.
 * @var string $blank_id    The blank the rows were drawn for.
 * @var string $field       The picks' field name.
 * @var string $drawn_field The picks-as-drawn field name.
 * @var string $blank_field The blank field name.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="form-field pixonaut-variant-pairing">
	<span class="pixonaut-field-label"><?php /* translators: Label of the table pairing each variation with a variant of the blank. Admin product page. */ esc_html_e( 'Variants', 'pixonaut-for-woocommerce' ); ?></span>
	<div class="pixonaut-field-control">
		<input type="hidden" name="<?php echo esc_attr( $blank_field ); ?>" value="<?php echo esc_attr( $blank_id ); ?>" />
		<p class="pixonaut-variant-pairing__summary">
			<?php
			echo esc_html(
				$paired === $total
					/* translators: 1: how many variations; 2: a printshop, such as Gelato, or "Blank". Above the variant pairing table of the product edit page. */
					? sprintf( _n( '%1$d variation is paired with a %2$s variant.', 'All %1$d variations are paired with a %2$s variant.', $total, 'pixonaut-for-woocommerce' ), $total, $printshop )
					/* translators: 1: how many variations have a variant; 2: how many variations there are; 3: a printshop, such as Gelato, or "Blank". Above the variant pairing table of the product edit page. */
					: sprintf( _n( '%1$d of %2$d variation is paired with a %3$s variant. Pick one for the rest: they cannot be bought until then.', '%1$d of %2$d variations are paired with a %3$s variant. Pick one for the rest: they cannot be bought until then.', $total, 'pixonaut-for-woocommerce' ), $paired, $total, $printshop )
			);
			?>
		</p>
		<div class="pixonaut-variant-pairing__scroll">
			<table class="widefat striped pixonaut-variant-pairing__table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Variation', 'pixonaut-for-woocommerce' ); ?></th>
						<?php /* translators: %s: a printshop, such as Gelato, or "Blank". Column of the variant pairing table of the product edit page. */ ?>
						<th scope="col"><?php echo esc_html( sprintf( __( '%s variant', 'pixonaut-for-woocommerce' ), $printshop ) ); ?></th>
						<th scope="col"><?php esc_html_e( 'Print area', 'pixonaut-for-woocommerce' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $pixonaut_row ) : ?>
						<?php $pixonaut_select = 'pixonaut_pairing_' . $pixonaut_row['id']; ?>
						<tr class="pixonaut-variant-pairing__row<?php echo $pixonaut_row['unpaired'] ? ' is-unpaired' : ''; ?>" data-variation="<?php echo esc_attr( (string) $pixonaut_row['id'] ); ?>">
							<td><a href="<?php echo esc_url( $pixonaut_row['url'] ); ?>" class="pixonaut-variant-pairing__variation"><?php echo esc_html( $pixonaut_row['name'] ); ?></a></td>
							<td>
								<input type="hidden" name="<?php echo esc_attr( $drawn_field . '[' . $pixonaut_row['id'] . ']' ); ?>" value="<?php echo esc_attr( $pixonaut_row['picked'] ); ?>" />
								<select id="<?php echo esc_attr( $pixonaut_select ); ?>" name="<?php echo esc_attr( $field . '[' . $pixonaut_row['id'] . ']' ); ?>" class="pixonaut-variant-pairing__select" aria-label="<?php echo esc_attr( sprintf( /* translators: 1: a printshop, such as Gelato, or "Blank"; 2: a variation's attributes, such as "Size: 70×100 cm". */ __( '%1$s variant for %2$s', 'pixonaut-for-woocommerce' ), $printshop, $pixonaut_row['name'] ) ); ?>"<?php echo $pixonaut_row['unpaired'] ? ' aria-invalid="true"' : ''; ?>>
									<option value="" <?php selected( $pixonaut_row['picked'], '' ); ?>><?php echo esc_html( $pixonaut_row['unpaired'] && '' === $pixonaut_row['picked'] ? __( 'Pick a variant', 'pixonaut-for-woocommerce' ) : $pixonaut_row['matched'] ); ?></option>
									<?php foreach ( $pixonaut_row['choices'] as $pixonaut_ref => $pixonaut_choice ) : ?>
										<option value="<?php echo esc_attr( (string) $pixonaut_ref ); ?>" <?php selected( $pixonaut_row['picked'], (string) $pixonaut_ref ); ?>><?php echo esc_html( $pixonaut_choice ); ?></option>
									<?php endforeach; ?>
								</select>
								<?php if ( $pixonaut_row['unpaired'] ) : ?>
									<?php /* translators: %s: a printshop, such as Gelato, or "Blank". Under a variation's variant in the pairing table of the product edit page. */ ?>
									<span class="pixonaut-field-error" role="alert"><?php echo esc_html( sprintf( __( 'No %s variant found: pick one.', 'pixonaut-for-woocommerce' ), $printshop ) ); ?></span>
								<?php elseif ( '' !== $pixonaut_row['picked'] ) : ?>
									<span class="description"><?php esc_html_e( 'Picked by you.', 'pixonaut-for-woocommerce' ); ?></span>
								<?php endif; ?>
							</td>
							<td class="pixonaut-variant-pairing__area">
								<?php if ( $pixonaut_row['unpaired'] ) : ?>
									<?php /* translators: In the Print area column of the variant pairing table: a variation without a variant has no print area yet. */ ?>
									<span class="description"><?php esc_html_e( 'None until paired', 'pixonaut-for-woocommerce' ); ?></span>
								<?php else : ?>
									<?php echo esc_html( '' === $pixonaut_row['area'] ? '—' : $pixonaut_row['area'] ); ?>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php if ( $more > 0 ) : ?>
			<?php /* translators: %d: how many more variations are paired than the table shows. Under the variant pairing table of the product edit page. */ ?>
			<p class="description"><?php echo esc_html( sprintf( _n( 'And %d more, under Variations.', 'And %d more, under Variations.', $more, 'pixonaut-for-woocommerce' ), $more ) ); ?></p>
		<?php endif; ?>
		<p class="description"><?php esc_html_e( 'Each variation prints at its variant’s print area. A variation’s own Variant field, under Variations, is the same choice.', 'pixonaut-for-woocommerce' ); ?></p>
	</div>
</div>
