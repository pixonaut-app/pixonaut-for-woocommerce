<?php
/**
 * Variations from the blank, above a variable product's variations: the
 * blanks its variations are printed on, the attribute value that picks
 * each, the options of each no attribute offers, and Preview and Generate,
 * with what they found or did.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/admin/product-variation-plan.php.
 * Classes and `data-` attributes are what the plugin's script reads; keep them.
 *
 * @package Pixonaut\WooCommerce
 * @version 1.25.0
 *
 * @var string $url        The product's variation plan route, which the buttons post to.
 * @var string $rest_nonce Nonce for the route.
 * @var list<array{blank_id: string, when: array<string, string>, fixed: array<string, string>}> $entries The plan's blanks; one to start from without a plan.
 * @var list<array{blank_id: string, name: string}> $blanks The blanks the product and its variations are set up on.
 * @var list<array<string, mixed>> $pickers The product's variation attributes not linked to a blank option, which can pick a blank: `attribute`, `name`, `values` (`value`, `label`).
 * @var list<array<string, mixed>> $linked  The ones linked to a blank option: `name`, `option`.
 * @var array{add: list<array<string, mixed>>, change: list<array<string, mixed>>, disable: list<array<string, mixed>>, skipped: list<array<string, mixed>>, kept: int, remaining: int, unpriced: int, unread: bool}|null $answer What Preview or Generate found or did; null before either.
 * @var bool   $dry_run   Whether `$answer` is a preview.
 * @var array<string, string> $why_texts What each reason in the lists says, by reason.
 */

defined( 'ABSPATH' ) || exit;

$pixonaut_lists = null === $answer ? array() : array(
	/* translators: Heading of the list of variations Generate would make, or made. Variations tab of the product edit page. */
	'add'     => $dry_run ? __( 'To add', 'pixonaut-for-woocommerce' ) : __( 'Added', 'pixonaut-for-woocommerce' ),
	/* translators: Heading of the list of variations Generate would change, or changed. */
	'change'  => $dry_run ? __( 'To change', 'pixonaut-for-woocommerce' ) : __( 'Changed', 'pixonaut-for-woocommerce' ),
	/* translators: Heading of the list of variations Generate would disable, or disabled. They are never deleted. */
	'disable' => $dry_run ? __( 'To disable', 'pixonaut-for-woocommerce' ) : __( 'Disabled', 'pixonaut-for-woocommerce' ),
	/* translators: Heading of the list of combinations of values no variation is made for. */
	'skipped' => __( 'Not made', 'pixonaut-for-woocommerce' ),
);
?>
<div class="pixonaut-variation-plan" data-url="<?php echo esc_url( $url ); ?>" data-rest-nonce="<?php echo esc_attr( $rest_nonce ); ?>"
	data-failed-text="<?php esc_attr_e( 'The variations could not be worked out. Try again in a moment.', 'pixonaut-for-woocommerce' ); ?>">
	<h3 class="pixonaut-variation-plan__title"><?php /* translators: Heading of a section above the variations in the Variations tab of the product edit page. */ esc_html_e( 'Variations from the blank', 'pixonaut-for-woocommerce' ); ?></h3>
	<p class="pixonaut-variation-plan__intro">
		<?php esc_html_e( 'Makes one variation for every combination of the values in the Attributes tab, each on its blank and sold as the blank variant its values name, priced by the pricing rule. Variations you have are kept; one no longer offered is disabled, never deleted.', 'pixonaut-for-woocommerce' ); ?>
		<?php if ( array() !== $linked ) : ?>
			<?php
			/* translators: %s: attributes with the blank option each stands for, e.g. "Frame (Color), Size (Size)". */
			echo esc_html( sprintf( __( 'Linked to blank options: %s.', 'pixonaut-for-woocommerce' ), implode( ', ', array_map( static fn ( array $row ): string => $row['name'] . ' (' . $row['option'] . ')', $linked ) ) ) );
			?>
		<?php else : ?>
			<?php esc_html_e( 'No attribute is linked to a blank option yet: link one on its edit screen under Products › Attributes.', 'pixonaut-for-woocommerce' ); ?>
		<?php endif; ?>
	</p>
	<datalist id="pixonaut-variation-plan-blanks">
		<?php foreach ( $blanks as $pixonaut_blank ) : ?>
			<option value="<?php echo esc_attr( $pixonaut_blank['blank_id'] ); ?>"><?php echo esc_html( $pixonaut_blank['name'] ); ?></option>
		<?php endforeach; ?>
	</datalist>
	<ol class="pixonaut-variation-plan__entries">
		<?php foreach ( $entries as $pixonaut_index => $pixonaut_entry ) : ?>
			<li class="pixonaut-variation-plan__entry">
				<p class="form-field">
					<label for="pixonaut-variation-plan-blank-<?php echo esc_attr( (string) $pixonaut_index ); ?>"><?php /* translators: Label of a field naming the Pixonaut blank (product base) some of a product's variations are printed on. */ esc_html_e( 'Blank', 'pixonaut-for-woocommerce' ); ?></label>
					<input type="text" class="pixonaut-variation-plan__blank" id="pixonaut-variation-plan-blank-<?php echo esc_attr( (string) $pixonaut_index ); ?>" list="pixonaut-variation-plan-blanks" value="<?php echo esc_attr( $pixonaut_entry['blank_id'] ); ?>" placeholder="blank_…" />
				</p>
				<?php foreach ( $pickers as $pixonaut_picker ) : ?>
					<?php $pixonaut_id = 'pixonaut-variation-plan-when-' . $pixonaut_index . '-' . sanitize_html_class( (string) $pixonaut_picker['attribute'] ); ?>
					<p class="form-field">
						<label for="<?php echo esc_attr( $pixonaut_id ); ?>">
							<?php
							/* translators: %s: an attribute's name, e.g. Frame. Label of a select choosing the attribute value that picks this blank. */
							echo esc_html( sprintf( __( 'When %s is', 'pixonaut-for-woocommerce' ), (string) $pixonaut_picker['name'] ) );
							?>
						</label>
						<select class="pixonaut-variation-plan__when" id="<?php echo esc_attr( $pixonaut_id ); ?>" data-attribute="<?php echo esc_attr( (string) $pixonaut_picker['attribute'] ); ?>">
							<option value=""><?php /* translators: Option of a "When … is" select: the attribute does not pick this blank. */ esc_html_e( 'Any value', 'pixonaut-for-woocommerce' ); ?></option>
							<?php foreach ( (array) $pixonaut_picker['values'] as $pixonaut_value ) : ?>
								<option value="<?php echo esc_attr( (string) $pixonaut_value['value'] ); ?>" <?php selected( $pixonaut_entry['when'][ (string) $pixonaut_picker['attribute'] ] ?? '', $pixonaut_value['value'] ); ?>><?php echo esc_html( (string) $pixonaut_value['label'] ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
				<?php endforeach; ?>
				<p class="form-field">
					<label for="pixonaut-variation-plan-fixed-<?php echo esc_attr( (string) $pixonaut_index ); ?>"><?php /* translators: Label of a field naming the value sold of each option of the blank no attribute offers, e.g. Orientation: Vertical. */ esc_html_e( 'Fixed options', 'pixonaut-for-woocommerce' ); ?></label>
					<input type="text" class="pixonaut-variation-plan__fixed" id="pixonaut-variation-plan-fixed-<?php echo esc_attr( (string) $pixonaut_index ); ?>"
						value="<?php echo esc_attr( implode( ', ', array_map( static fn ( string $option, string $value ): string => $option . ': ' . $value, array_keys( $pixonaut_entry['fixed'] ), $pixonaut_entry['fixed'] ) ) ); ?>"
						placeholder="<?php esc_attr_e( 'Orientation: Vertical', 'pixonaut-for-woocommerce' ); ?>" />
				</p>
				<button type="button" class="button-link pixonaut-variation-plan__remove"><?php /* translators: Button removing a blank from the variations' plan. */ esc_html_e( 'Remove this blank', 'pixonaut-for-woocommerce' ); ?></button>
			</li>
		<?php endforeach; ?>
	</ol>
	<p class="pixonaut-variation-plan__actions">
		<button type="button" class="button pixonaut-variation-plan__add"><?php /* translators: Button adding another blank to the variations' plan. */ esc_html_e( 'Add a blank', 'pixonaut-for-woocommerce' ); ?></button>
		<button type="button" class="button" data-action="preview"><?php /* translators: Button listing what generating the variations would add, change and disable, without changing anything. */ esc_html_e( 'Preview', 'pixonaut-for-woocommerce' ); ?></button>
		<button type="button" class="button button-primary" data-action="generate"><?php /* translators: Button making, changing and disabling the variations as the plan says. */ esc_html_e( 'Generate from the blank', 'pixonaut-for-woocommerce' ); ?></button>
		<span class="spinner pixonaut-variation-plan__spinner"></span>
	</p>
	<p class="pixonaut-variation-plan__status" role="status" aria-live="polite"></p>
	<?php if ( null !== $answer ) : ?>
		<div class="pixonaut-variation-plan__result">
			<?php foreach ( $pixonaut_lists as $pixonaut_key => $pixonaut_heading ) : ?>
				<?php if ( array() !== $answer[ $pixonaut_key ] ) : ?>
					<details class="pixonaut-variation-plan__list" <?php echo 'skipped' === $pixonaut_key ? '' : 'open'; ?>>
						<summary><?php echo esc_html( $pixonaut_heading . ' (' . count( $answer[ $pixonaut_key ] ) . ')' ); ?></summary>
						<ul>
							<?php foreach ( $answer[ $pixonaut_key ] as $pixonaut_row ) : ?>
								<li>
									<?php echo esc_html( (string) $pixonaut_row['label'] ); ?>
									<?php if ( isset( $pixonaut_row['variant'] ) ) : ?>
										<span class="pixonaut-variation-plan__variant">— <?php echo esc_html( (string) $pixonaut_row['variant'] ); ?></span>
									<?php endif; ?>
									<?php if ( isset( $pixonaut_row['why'] ) ) : ?>
										<span class="pixonaut-variation-plan__why">(<?php echo esc_html( $why_texts[ (string) $pixonaut_row['why'] ] ?? (string) $pixonaut_row['why'] ); ?>)</span>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
					</details>
				<?php endif; ?>
			<?php endforeach; ?>
			<p class="pixonaut-variation-plan__kept">
				<?php
				/* translators: %d: how many variations. */
				echo esc_html( sprintf( _n( '%d variation kept as it is.', '%d variations kept as they are.', $answer['kept'], 'pixonaut-for-woocommerce' ), $answer['kept'] ) );
				?>
				<?php if ( $answer['remaining'] > 0 ) : ?>
					<?php
					/* translators: %d: how many variations. */
					echo esc_html( sprintf( _n( '%d more variation is made when you generate again.', '%d more variations are made when you generate again.', $answer['remaining'], 'pixonaut-for-woocommerce' ), $answer['remaining'] ) );
					?>
				<?php endif; ?>
				<?php if ( $answer['unread'] ) : ?>
					<?php esc_html_e( 'Pixonaut could not be reached to read the printshop\'s costs, so the new variations are not priced yet: click Apply under Prices in the Pixonaut tab in a moment.', 'pixonaut-for-woocommerce' ); ?>
				<?php endif; ?>
				<?php if ( $answer['unpriced'] > 0 ) : ?>
					<?php
					/* translators: %d: how many variations. */
					echo esc_html( sprintf( _n( '%d variation has no price yet, so customers cannot buy it: set a target margin, or price it.', '%d variations have no price yet, so customers cannot buy them: set a target margin, or price them.', $answer['unpriced'], 'pixonaut-for-woocommerce' ), $answer['unpriced'] ) );
					?>
				<?php endif; ?>
			</p>
		</div>
	<?php endif; ?>
</div>
