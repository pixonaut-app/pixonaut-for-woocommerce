<?php
/**
 * One side's form as the designer draws it, printed before the SDK runs.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/storefront/form-placeholder.php.
 * The storefront form prints it in each side's `#pixonaut-form-<side>`, and
 * the designer replaces it with its own form as soon as it is built. It
 * shows the steps and fields the designer ends up with, each with the
 * control the designer draws for it (an upload button, a text box holding
 * the default text, a menu, tiles, a slider, colour swatches) at the size it
 * draws it, in the designer's words, so nothing moves when the designer
 * takes over.
 *
 * A text, a menu, a slider and a free colour are the form the customer uses:
 * real inputs, each naming its field in `data-pixonaut-field`
 * (`<layer id>:<prop>`). The designer takes each over with what it holds, so
 * what is typed before it loads is kept. A copy of this template may leave an
 * input out, and the designer draws that field. They have no `name` and post
 * nothing. Everything else is a picture of the control until the designer
 * draws it: each such `.pixonaut-placeholder__part` is `inert` and hidden from
 * assistive technology.
 *
 * The `.pixonaut-placeholder__slot`s are places for the add-to-cart form's
 * own parts, named in `data-pixonaut-slot`: `options` for its option fields,
 * `cart` for its cart row, `attribute_<slug>` for an attribute's field a
 * side's groups place. They are moved in as the page is read, and work there.
 * A copy of this template without them leaves the parts where the theme put
 * them until the designer draws them.
 *
 * @package Pixonaut\WooCommerce
 * @version 0.1.0
 *
 * @var \Pixonaut\WooCommerce\Storefront\SideLayout $layout The side's layout.
 */

use Pixonaut\WooCommerce\Storefront\FormControl;

defined( 'ABSPATH' ) || exit;

$pixonaut_icon = static function ( string $kind ): void {
	$paths = 'upload' === $kind
		? array( 'M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4', 'M17 8 12 3 7 8', 'M12 3v12' )
		: array( 'M12.586 12.586 19 19', 'M3.688 3.037a.497.497 0 0 0-.651.651l6.5 15.999a.501.501 0 0 0 .947-.062l1.569-6.083a2 2 0 0 1 1.448-1.479l6.124-1.579a.5.5 0 0 0 .063-.947z' );
	echo '<svg class="' . esc_attr( 'pixonaut-placeholder__icon pixonaut-placeholder__icon--' . $kind ) . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false">';
	foreach ( $paths as $pixonaut_path ) {
		echo '<path d="' . esc_attr( $pixonaut_path ) . '"></path>';
	}
	echo '</svg>';
};

/**
 * Prints a slider at `$position` percent along it, with its ends written
 * under it when given.
 *
 * @param float    $position Where the thumb stands, as a percentage.
 * @param string[] $bounds   The low end's words and the high end's; empty to leave them to the designer.
 * @param array<string, mixed> $input A control (`FormControl`) to print the slider's input for; empty for none.
 */
$pixonaut_slider = static function ( float $position, array $bounds = array(), array $input = array() ): void {
	$at = (string) $position . '%';
	?>
	<span class="pixonaut-placeholder__slider">
		<span class="pixonaut-placeholder__track">
			<span class="pixonaut-placeholder__rail"></span>
			<span class="pixonaut-placeholder__fill" style="<?php echo esc_attr( 'width: ' . $at ); ?>"></span>
			<span class="pixonaut-placeholder__thumb" style="<?php echo esc_attr( 'left: ' . $at ); ?>"></span>
			<?php if ( array() !== $input ) : ?>
				<input type="range" class="pixonaut-placeholder__range" data-pixonaut-field="<?php echo esc_attr( (string) $input['name'] ); ?>" min="<?php echo esc_attr( (string) $input['bounds'][0] ); ?>" max="<?php echo esc_attr( (string) $input['bounds'][1] ); ?>" step="<?php echo esc_attr( (string) $input['step'] ); ?>" value="<?php echo esc_attr( (string) $input['value'] ); ?>" />
			<?php endif; ?>
		</span>
		<span class="pixonaut-placeholder__bounds">
		<?php
		foreach ( $bounds as $pixonaut_bound ) :
			?>
			<span><?php echo esc_html( $pixonaut_bound ); ?></span><?php endforeach; ?></span>
	</span>
	<?php
};

/**
 * A crop's zoom as the designer writes it.
 *
 * @param string $zoom The zoom, as a number.
 */
$pixonaut_zoom = static function ( string $zoom ): string {
	/* translators: %s: how far a picture is zoomed in, e.g. 1.5 for one and a half times. Beside the zoom slider of a picture in the product page's designer. */
	return sprintf( _x( '%s×', 'designer crop zoom', 'pixonaut-for-woocommerce' ), $zoom );
};

/**
 * What a colour control writes beside its heading: a palette's chosen
 * entry, "Custom" for a colour of the customer's own, a colour's hex.
 *
 * @param array<string, mixed> $control A colour or palette control (`FormControl`).
 */
$pixonaut_colour_aside = static function ( array $control ): string {
	if ( FormControl::PALETTE === $control['type'] && '' !== $control['value'] ) {
		/* translators: Beside a colour field's heading in the product page's designer, when the customer's colour is one of their own rather than the palette's, as Pixonaut's designer words it. */
		return _x( 'Custom', 'designer colour', 'pixonaut-for-woocommerce' );
	}
	return (string) $control['aside'];
};

/**
 * Prints a colour control: one round swatch, or a palette's swatches with
 * "Custom…" and the customer's own colour beside them when it offers any
 * colour.
 *
 * @param array<string, mixed> $control A colour or palette control (`FormControl`).
 */
$pixonaut_colour = static function ( array $control ): void {
	if ( FormControl::COLOR === $control['type'] ) {
		$input = '' === $control['name'] ? '' : '<input type="color" class="pixonaut-placeholder__well-input" data-pixonaut-field="' . esc_attr( (string) $control['name'] ) . '" value="' . esc_attr( (string) $control['value'] ) . '" />';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped as it is put together.
		echo '<span class="pixonaut-placeholder__swatches"><span class="pixonaut-placeholder__swatch is-chosen" style="' . esc_attr( 'background: ' . $control['value'] ) . '">' . $input . '</span></span>';
		return;
	}
	if ( $control['custom'] ) {
		echo '<span class="pixonaut-placeholder__palette">';
	}
	echo '<span class="pixonaut-placeholder__swatches pixonaut-placeholder__swatches--palette">';
	foreach ( $control['options'] as $pixonaut_index => $pixonaut_option ) {
		echo '<span class="' . esc_attr( 'pixonaut-placeholder__swatch' . ( $pixonaut_index === $control['chosen'] ? ' is-chosen' : '' ) ) . '"><span class="pixonaut-placeholder__swatch-face" style="' . esc_attr( 'background: ' . $pixonaut_option ) . '"></span></span>';
	}
	echo '</span>';
	if ( $control['custom'] ) {
		echo '<span class="' . esc_attr( 'pixonaut-placeholder__custom' . ( '' === $control['value'] ? '' : ' is-chosen' ) ) . '"></span>';
		if ( '' !== $control['value'] ) {
			echo '<span class="pixonaut-placeholder__swatch pixonaut-placeholder__well" style="' . esc_attr( 'background: ' . $control['value'] ) . '"></span>';
		}
		echo '</span>';
	}
};

/**
 * What a tile shows: a font's "Aa" in the system's face until the designer
 * loads the font's, a fit's words, "None" for no shape, nothing for a shape
 * (the designer draws its outline).
 *
 * @param string $kind   The tiles' kind (`FormControl::FONT_TILES` and the others).
 * @param string $option The value the tile offers.
 */
$pixonaut_tile_face = static function ( string $kind, string $option ): string {
	if ( FormControl::FONT_TILES === $kind ) {
		return 'Aa';
	}
	if ( FormControl::SHAPE_TILES === $kind ) {
		/* translators: Tile of a shape field in the product page's designer that leaves the shape out, as Pixonaut's designer words it. */
		return '' === $option ? _x( 'None', 'designer shape', 'pixonaut-for-woocommerce' ) : '';
	}
	$fits = array(
		/* translators: A way to fit a picture in its frame, on a tile in the product page's designer: the whole picture shows, leaving space around it. */
		'contain' => _x( 'Show whole picture', 'designer picture fit', 'pixonaut-for-woocommerce' ),
		/* translators: A way to fit a picture in its frame, on a tile in the product page's designer: the picture covers the frame, cut at its edges. */
		'cover'   => _x( 'Fill the frame', 'designer picture fit', 'pixonaut-for-woocommerce' ),
		/* translators: A way to fit a picture in its frame, on a tile in the product page's designer: the picture is stretched to the frame's shape. */
		'fill'    => _x( 'Stretch to fit', 'designer picture fit', 'pixonaut-for-woocommerce' ),
		/* translators: A way to fit a picture in its frame, on a tile in the product page's designer: the picture repeats across the frame. */
		'tile'    => _x( 'Repeat as pattern', 'designer picture fit', 'pixonaut-for-woocommerce' ),
	);
	return $fits[ $option ] ?? $option;
};

/**
 * The words a menu shows an offered value in, as the designer's: an alignment,
 * a case, a tone, a weight; anything else as itself.
 *
 * @param string $prop   The prop the menu sets.
 * @param string $option The value offered.
 */
$pixonaut_option_word = static function ( string $prop, string $option ): string {
	$words = array(
		'align'      => array(
			/* translators: A text's alignment, in a menu in the product page's designer. */
			'left'   => _x( 'Left', 'designer text alignment', 'pixonaut-for-woocommerce' ),
			/* translators: A text's alignment, in a menu in the product page's designer. */
			'center' => _x( 'Center', 'designer text alignment', 'pixonaut-for-woocommerce' ),
			/* translators: A text's alignment, in a menu in the product page's designer. */
			'right'  => _x( 'Right', 'designer text alignment', 'pixonaut-for-woocommerce' ),
		),
		'valign'     => array(
			/* translators: Where a text sits from top to bottom of its box, in a menu in the product page's designer. */
			'top'    => _x( 'Top', 'designer text position', 'pixonaut-for-woocommerce' ),
			/* translators: Where a text sits from top to bottom of its box, in a menu in the product page's designer. */
			'middle' => _x( 'Middle', 'designer text position', 'pixonaut-for-woocommerce' ),
			/* translators: Where a text sits from top to bottom of its box, in a menu in the product page's designer. */
			'bottom' => _x( 'Bottom', 'designer text position', 'pixonaut-for-woocommerce' ),
		),
		'textCase'   => array(
			/* translators: A text's case, in a menu in the product page's designer: the text as the customer wrote it. */
			'asTyped' => _x( 'As typed', 'designer text case', 'pixonaut-for-woocommerce' ),
			/* translators: A text's case, in a menu in the product page's designer. Written in that case. */
			'upper'   => _x( 'UPPERCASE', 'designer text case', 'pixonaut-for-woocommerce' ),
			/* translators: A text's case, in a menu in the product page's designer. Written in that case. */
			'lower'   => _x( 'lowercase', 'designer text case', 'pixonaut-for-woocommerce' ),
			/* translators: A text's case, in a menu in the product page's designer: each word starts with a capital. Written in that case. */
			'title'   => _x( 'Title Case', 'designer text case', 'pixonaut-for-woocommerce' ),
		),
		'tone'       => array(
			/* translators: A photo's tone, in a menu in the product page's designer: the photo as it is. */
			'none'          => _x( 'Original', 'designer photo tone', 'pixonaut-for-woocommerce' ),
			/* translators: A photo's tone, in a menu in the product page's designer. */
			'blackAndWhite' => _x( 'Black and white', 'designer photo tone', 'pixonaut-for-woocommerce' ),
			/* translators: A photo's tone, in a menu in the product page's designer. */
			'sepia'         => _x( 'Sepia', 'designer photo tone', 'pixonaut-for-woocommerce' ),
			/* translators: A photo's tone, in a menu in the product page's designer. */
			'duotone'       => _x( 'Duotone', 'designer photo tone', 'pixonaut-for-woocommerce' ),
			/* translators: A photo's tone, in a menu in the product page's designer: the photo in one colour. */
			'recolour'      => _x( 'Recolour', 'designer photo tone', 'pixonaut-for-woocommerce' ),
			/* translators: A photo's tone, in a menu in the product page's designer: its colours reversed. */
			'invert'        => _x( 'Invert', 'designer photo tone', 'pixonaut-for-woocommerce' ),
		),
		'fontWeight' => array(
			/* translators: A font's weight, in a menu in the product page's designer. */
			'400' => _x( 'Regular', 'designer font weight', 'pixonaut-for-woocommerce' ),
			/* translators: A font's weight, in a menu in the product page's designer. */
			'700' => _x( 'Bold', 'designer font weight', 'pixonaut-for-woocommerce' ),
		),
	);
	if ( 'fontWeight' === $prop && ! isset( $words[ $prop ][ $option ] ) ) {
		/* translators: %s: a font's weight as a number, e.g. 500. In a menu in the product page's designer. */
		return sprintf( __( 'Weight %s', 'pixonaut-for-woocommerce' ), $option );
	}
	return $words[ $prop ][ $option ] ?? $option;
};

/**
 * Prints a place for a part of the add-to-cart form.
 *
 * @param string $name   The part's name.
 * @param bool   $hidden Whether the designer does not show it yet.
 */
$pixonaut_slot = static function ( string $name, bool $hidden = false ): void {
	echo '<div class="pixonaut-placeholder__slot" data-pixonaut-slot="' . esc_attr( $name ) . '"' . ( $hidden ? ' hidden' : '' ) . '></div>';
};

/**
 * Prints a field's control.
 *
 * @param array<string, mixed> $field A control (`FormControl`).
 */
$pixonaut_field = static function ( array $field ) use ( $pixonaut_slot, $pixonaut_icon, $pixonaut_slider, $pixonaut_zoom, $pixonaut_colour, $pixonaut_colour_aside, $pixonaut_tile_face, $pixonaut_option_word ): void {
	$type = (string) $field['type'];
	$name = (string) $field['name'];
	$live = '' !== $name;
	$tag  = $live ? 'label' : 'span';
	$word = static fn ( string $option ): string => $field['words'][ $option ] ?? $pixonaut_option_word( (string) $field['kind'], $option );
	if ( FormControl::HOST === $type ) {
		$pixonaut_slot( (string) $field['host'] );
		return;
	}
	$label = (string) $field['label'];
	$aside = FormControl::PALETTE === $type ? $pixonaut_colour_aside( $field ) : (string) $field['aside'];
	$help  = (string) $field['help'];
	if ( FormControl::SCALE === $type && '' === $label ) {
		/* translators: Label of the slider that sizes a picture or text in the product page's designer, as Pixonaut's designer names it. */
		$label = _x( 'Size', 'designer scale slider', 'pixonaut-for-woocommerce' );
	}
	if ( '' === $help && $field['max_length'] > 0 ) {
		/* translators: %d: the most characters a text field takes. Help under a text field in the product page's designer. */
		$help = sprintf( _n( 'Up to %d character', 'Up to %d characters', (int) $field['max_length'], 'pixonaut-for-woocommerce' ), (int) $field['max_length'] );
	}
	$hint = $label;
	if ( FormControl::MOVE === $type && '' === $hint ) {
		$hint = $field['several']
			/* translators: Hint in the product page's designer, under the fields of pictures or texts the customer can move. */
			? __( 'Drag them on the preview to place them', 'pixonaut-for-woocommerce' )
			/* translators: Hint in the product page's designer, under the fields of a picture or text the customer can move. */
			: __( 'Drag it on the preview to place it', 'pixonaut-for-woocommerce' );
	}
	echo '<span class="pixonaut-placeholder__part"' . ( $live ? '' : ' inert aria-hidden="true"' ) . '>';
	if ( '' !== $help ) {
		echo '<span class="pixonaut-placeholder__described">';
	}
	?>
	<<?php echo esc_html( $tag ); ?> class="pixonaut-placeholder__field" data-pixonaut-type="<?php echo esc_attr( $type ); ?>">
		<?php if ( FormControl::MOVE === $type ) : ?>
			<span class="pixonaut-placeholder__hint">
				<?php $pixonaut_icon( 'pointer' ); ?>
				<span class="pixonaut-placeholder__hint-text"><?php echo esc_html( $hint ); ?></span>
				<span class="pixonaut-placeholder__reset"><?php /* translators: Link beside the hint to drag a picture or text on the product page's designer preview: puts it back where it started. */ echo esc_html_x( 'Reset', 'designer placement', 'pixonaut-for-woocommerce' ); ?></span>
			</span>
		<?php else : ?>
			<span class="pixonaut-placeholder__heading"><span class="pixonaut-placeholder__label"><?php echo esc_html( $label ); ?></span><?php echo '' === $aside ? '' : '<span class="pixonaut-placeholder__aside">' . esc_html( $aside ) . '</span>'; ?></span>
			<?php if ( FormControl::IMAGE === $type && array() === $field['options'] && $field['upload'] ) : ?>
				<span class="pixonaut-placeholder__picture">
					<span class="pixonaut-placeholder__upload">
						<span class="pixonaut-placeholder__upload-face"><?php $pixonaut_icon( 'upload' ); ?></span>
						<span class="pixonaut-placeholder__upload-text"><?php /* translators: Button of a picture field in the product page's designer, as Pixonaut's designer words it. */ esc_html_e( 'Upload an image', 'pixonaut-for-woocommerce' ); ?></span>
					</span>
				</span>
			<?php elseif ( FormControl::IMAGE === $type ) : ?>
				<span class="pixonaut-placeholder__picture">
					<?php echo $field['upload'] ? '<span class="pixonaut-placeholder__picture-row">' : ''; ?>
					<span class="pixonaut-placeholder__tiles">
						<?php foreach ( $field['options'] as $pixonaut_index => $pixonaut_option ) : ?>
							<span class="<?php echo esc_attr( 'pixonaut-placeholder__tile' . ( $pixonaut_index === $field['chosen'] ? ' is-chosen' : '' ) ); ?>">
								<?php if ( '' === $pixonaut_option ) : ?>
									<span class="pixonaut-placeholder__tile-text"><?php /* translators: Tile of a picture field in the product page's designer that leaves the picture out. */ esc_html_e( 'No picture', 'pixonaut-for-woocommerce' ); ?></span>
								<?php endif; ?>
							</span>
						<?php endforeach; ?>
					</span>
					<?php if ( $field['upload'] ) : ?>
						<span class="pixonaut-placeholder__upload pixonaut-placeholder__upload--tile"><span class="pixonaut-placeholder__upload-face"><?php $pixonaut_icon( 'upload' ); ?></span></span>
					<?php endif; ?>
					<?php echo $field['upload'] ? '</span>' : ''; ?>
				</span>
			<?php elseif ( FormControl::CROP === $type ) : ?>
				<span class="pixonaut-placeholder__crop">
					<span class="pixonaut-placeholder__row">
						<?php $pixonaut_slider( (float) $field['position'], array_map( $pixonaut_zoom, $field['bounds'] ) ); ?>
						<span class="pixonaut-placeholder__aside"><?php echo esc_html( $pixonaut_zoom( (string) $field['value'] ) ); ?></span>
					</span>
					<span class="pixonaut-placeholder__row">
						<span class="pixonaut-placeholder__button"><?php /* translators: Button under a picture's zoom slider in the product page's designer: drag the picture in its frame on the preview, as Pixonaut's designer words it. */ esc_html_e( 'Move picture', 'pixonaut-for-woocommerce' ); ?></span>
						<span class="pixonaut-placeholder__button"><?php /* translators: Button under a picture's zoom slider in the product page's designer: puts the picture back as the template frames it. */ echo esc_html_x( 'Reset', 'designer crop', 'pixonaut-for-woocommerce' ); ?></span>
					</span>
					<span class="pixonaut-placeholder__note"></span>
				</span>
			<?php elseif ( FormControl::TILES === $type ) : ?>
				<span class="pixonaut-placeholder__tiles" data-pixonaut-tiles="<?php echo esc_attr( (string) $field['kind'] ); ?>">
					<?php foreach ( $field['options'] as $pixonaut_index => $pixonaut_option ) : ?>
						<?php $pixonaut_face = FormControl::FONT_TILES === $field['kind'] ? 'Aa' : ( $field['words'][ $pixonaut_option ] ?? $pixonaut_tile_face( (string) $field['kind'], $pixonaut_option ) ); ?>
						<span class="<?php echo esc_attr( 'pixonaut-placeholder__tile' . ( $pixonaut_index === $field['chosen'] ? ' is-chosen' : '' ) ); ?>"><?php echo '' === $pixonaut_face ? '' : '<span class="pixonaut-placeholder__tile-text">' . esc_html( $pixonaut_face ) . '</span>'; ?></span>
					<?php endforeach; ?>
				</span>
			<?php elseif ( FormControl::SELECT === $type ) : ?>
				<?php if ( $live ) : ?>
					<select class="pixonaut-placeholder__select" data-pixonaut-field="<?php echo esc_attr( $name ); ?>">
						<?php foreach ( $field['options'] as $pixonaut_option ) : ?>
							<option value="<?php echo esc_attr( $pixonaut_option ); ?>"<?php selected( $pixonaut_option, (string) $field['value'] ); ?>><?php echo esc_html( $word( $pixonaut_option ) ); ?></option>
						<?php endforeach; ?>
					</select>
				<?php else : ?>
					<select class="pixonaut-placeholder__select" tabindex="-1"><option><?php echo esc_html( $word( (string) $field['value'] ) ); ?></option></select>
				<?php endif; ?>
			<?php elseif ( FormControl::GRADIENT === $type ) : ?>
				<span class="pixonaut-placeholder__gradient">
					<?php foreach ( $field['stops'] as $pixonaut_index => $pixonaut_stop ) : ?>
						<?php $pixonaut_stop_aside = $pixonaut_colour_aside( $pixonaut_stop ); ?>
						<span class="pixonaut-placeholder__stop">
							<span class="pixonaut-placeholder__heading pixonaut-placeholder__heading--stop"><span><?php /* translators: %d: the colour's place along a gradient, from 1. Heading of one of a gradient's colours in the product page's designer, as Pixonaut's designer words it. */ echo esc_html( sprintf( __( 'Colour %d', 'pixonaut-for-woocommerce' ), $pixonaut_index + 1 ) ); ?></span><?php echo '' === $pixonaut_stop_aside ? '' : '<span class="pixonaut-placeholder__aside">' . esc_html( $pixonaut_stop_aside ) . '</span>'; ?></span>
							<?php $pixonaut_colour( $pixonaut_stop ); ?>
						</span>
					<?php endforeach; ?>
				</span>
			<?php elseif ( FormControl::COLOR === $type || FormControl::PALETTE === $type ) : ?>
				<?php $pixonaut_colour( $field ); ?>
			<?php elseif ( FormControl::NUMBER === $type || FormControl::SCALE === $type ) : ?>
				<?php $pixonaut_slider( (float) $field['position'], array(), $live ? $field : array() ); ?>
			<?php elseif ( FormControl::TEXT === $type ) : ?>
				<input type="text" class="pixonaut-placeholder__input" data-pixonaut-field="<?php echo esc_attr( $name ); ?>"<?php echo $field['max_length'] > 0 ? ' maxlength="' . esc_attr( (string) $field['max_length'] ) . '"' : ''; ?> value="<?php echo esc_attr( (string) $field['value'] ); ?>" placeholder="<?php echo esc_attr( '' === $field['placeholder'] ? $label : $field['placeholder'] ); ?>" />
			<?php elseif ( FormControl::TEXT_LINES === $type ) : ?>
				<textarea class="pixonaut-placeholder__input" data-pixonaut-field="<?php echo esc_attr( $name ); ?>"<?php echo $field['max_length'] > 0 ? ' maxlength="' . esc_attr( (string) $field['max_length'] ) . '"' : ''; ?> rows="<?php echo esc_attr( (string) $field['rows'] ); ?>" placeholder="<?php echo esc_attr( '' === $field['placeholder'] ? $label : $field['placeholder'] ); ?>"><?php echo esc_textarea( (string) $field['value'] ); ?></textarea>
			<?php endif; ?>
		<?php endif; ?>
	</<?php echo esc_html( $tag ); ?>>
	<?php
	if ( '' !== $help ) {
		echo '<span class="pixonaut-placeholder__help">' . esc_html( $help ) . '</span></span>';
	}
	echo '</span>';
};

/**
 * Prints the print size picker, which the designer draws only when there
 * is more than one size to pick.
 */
$pixonaut_sizes = static function () use ( $layout ): void {
	if ( count( $layout->sizes ) <= 1 ) {
		return;
	}
	?>
	<span class="pixonaut-placeholder__part" inert aria-hidden="true"><span class="pixonaut-placeholder__sizes">
		<span class="pixonaut-placeholder__legend"><?php /* translators: Heading of the print size picker in the product page's designer, as Pixonaut's designer words it. */ esc_html_e( 'Print size', 'pixonaut-for-woocommerce' ); ?></span>
		<span class="pixonaut-placeholder__chips">
			<?php foreach ( $layout->sizes as $pixonaut_index => $pixonaut_size ) : ?>
				<span class="<?php echo esc_attr( 'pixonaut-placeholder__chip' . ( 0 === $pixonaut_index ? ' is-chosen' : '' ) ); ?>"><?php echo esc_html( $pixonaut_size ); ?></span>
			<?php endforeach; ?>
		</span>
	</span></span>
	<?php
};

$pixonaut_steps = $layout->step_labels;
if ( $layout->options_step ) {
	/* translators: Name of the first step of the product page's designer, where the customer picks the product's options, as Pixonaut's designer words it. */
	$pixonaut_steps[0] = _x( 'Options', 'designer step', 'pixonaut-for-woocommerce' );
}
?>
<div class="pixonaut-form__placeholder"<?php echo array() === $pixonaut_steps ? '' : ' data-pixonaut-layout="steps"'; ?>>
	<?php if ( array() === $pixonaut_steps ) : ?>
		<?php $pixonaut_sizes(); ?>
		<?php
		if ( $layout->lends_options ) {
			$pixonaut_slot( 'options' );
		}
		?>
		<?php foreach ( $layout->steps as $pixonaut_index => $pixonaut_step ) : ?>
			<span class="pixonaut-placeholder__part" inert aria-hidden="true">
				<?php if ( $pixonaut_index > 0 ) : ?>
					<span class="pixonaut-placeholder__divider"></span>
				<?php endif; ?>
				<span class="pixonaut-placeholder__step"><?php echo esc_html( $pixonaut_step['label'] ); ?></span>
			</span>
			<?php foreach ( $pixonaut_step['fields'] as $pixonaut_control ) : ?>
				<?php $pixonaut_field( $pixonaut_control ); ?>
			<?php endforeach; ?>
		<?php endforeach; ?>
		<?php
		if ( $layout->lends_cart ) {
			$pixonaut_slot( 'cart' );
		}
		?>
	<?php else : ?>
		<?php
		if ( ! $layout->options_step ) {
			// Drawn above the form when no step holds them.
			$pixonaut_sizes();
		}
		?>
		<span class="pixonaut-placeholder__part" inert aria-hidden="true">
			<span class="pixonaut-placeholder__stepper">
				<span class="pixonaut-placeholder__progress">
					<?php foreach ( $pixonaut_steps as $pixonaut_index => $pixonaut_name ) : ?>
						<span class="<?php echo esc_attr( 'pixonaut-placeholder__segment' . ( 0 === $pixonaut_index ? ' is-current' : '' ) ); ?>"><span class="pixonaut-placeholder__bar"></span><span class="pixonaut-placeholder__segment-name"><?php echo esc_html( $pixonaut_name ); ?></span></span>
					<?php endforeach; ?>
				</span>
				<span class="pixonaut-placeholder__titles">
					<span class="pixonaut-placeholder__counter pixonaut-placeholder__counter--long"><?php /* translators: 1: the step shown, 2: how many steps there are. Above a step's title in the product page's designer. */ echo esc_html( sprintf( __( 'Step %1$d of %2$d', 'pixonaut-for-woocommerce' ), 1, count( $pixonaut_steps ) ) ); ?></span>
					<span class="pixonaut-placeholder__counter pixonaut-placeholder__counter--short"><?php /* translators: 1: the step shown, 2: how many steps there are. Above a step's title in the product page's designer, in place of "Step 1 of 2" where the designer is narrow. */ echo esc_html( sprintf( __( '%1$d of %2$d', 'pixonaut-for-woocommerce' ), 1, count( $pixonaut_steps ) ) ); ?></span>
					<span class="pixonaut-placeholder__step-title"><?php echo esc_html( $pixonaut_steps[0] ); ?></span>
				</span>
			</span>
		</span>
		<span class="pixonaut-placeholder__panel">
			<?php
			if ( $layout->options_step ) {
				$pixonaut_sizes();
				if ( $layout->lends_options ) {
					$pixonaut_slot( 'options' );
				}
			}
			?>
			<?php foreach ( $layout->steps[0]['fields'] ?? array() as $pixonaut_control ) : ?>
				<?php $pixonaut_field( $pixonaut_control ); ?>
			<?php endforeach; ?>
			<?php
			foreach ( $layout->later_hosts as $pixonaut_host ) {
				$pixonaut_slot( $pixonaut_host, true );
			}
			?>
		</span>
		<span class="pixonaut-placeholder__part" inert aria-hidden="true">
			<span class="pixonaut-placeholder__nav">
				<span class="pixonaut-placeholder__back"><?php /* translators: Button in the product page's designer that goes back a step. */ echo esc_html_x( 'Back', 'designer step', 'pixonaut-for-woocommerce' ); ?></span>
				<?php if ( count( $pixonaut_steps ) > 1 ) : ?>
					<span class="pixonaut-placeholder__next"><?php /* translators: %s: the next step's name. Button in the product page's designer that moves on a step. */ echo esc_html( sprintf( __( 'Next: %s', 'pixonaut-for-woocommerce' ), $pixonaut_steps[1] ) ); ?></span>
				<?php endif; ?>
			</span>
		</span>
		<?php
		if ( $layout->lends_cart ) {
			// The designer shows the cart row on the last step alone.
			$pixonaut_slot( 'cart', count( $pixonaut_steps ) > 1 );
		}
		?>
	<?php endif; ?>
</div>
