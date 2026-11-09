<?php
/**
 * The control the designer draws for one field of a template's form.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

/**
 * What the designer's form draws for one field of a template's `formSchema`,
 * worked out the way the SDK picks it, so the page prints the same control
 * at the same size before the SDK runs: an upload button, a text box holding
 * the default, a menu, tiles, a slider, a colour swatch.
 *
 * A control with a `name` is printed as the input the customer uses, which the
 * SDK takes over under that name (`data-pixonaut-field`) with what it holds: a
 * text, a menu, a slider, a free colour. Any other is a picture of the control
 * until the SDK draws it, a choice drawn as tiles or as a drawn list among
 * them: the SDK would keep a printed input for one in place of its tiles.
 *
 * @phpstan-type Control array{
 *     label: string,
 *     type: string,
 *     kind: string,
 *     value: string,
 *     aside: string,
 *     help: string,
 *     rows: int,
 *     max_length: int,
 *     options: list<string>,
 *     chosen: int,
 *     position: float,
 *     step: string,
 *     name: string,
 *     upload: bool,
 *     several: bool,
 *     custom: bool,
 *     bounds: list<string>,
 *     stops: list<array<string, mixed>>,
 *     host: string,
 *     own_step: bool,
 *     placeholder: string,
 *     words: array<string, string>
 * }
 */
final class FormControl {

	/** A picture field, drawn as an upload button, picture tiles, or both. */
	public const IMAGE = 'image';

	/** A picture's crop: a zoom slider, then Move picture and Reset. */
	public const CROP = 'crop';

	/** A choice drawn as tiles: a font, a shape or a fit. */
	public const TILES = 'tiles';

	/** A choice drawn as a menu. */
	public const SELECT = 'select';

	/** A gradient, drawn as one colour control per colour along it. */
	public const GRADIENT = 'gradient';

	/** A colour picked freely, drawn as one round swatch. */
	public const COLOR = 'color';

	/** A colour picked from a palette, drawn as a row of round swatches. */
	public const PALETTE = 'palette';

	/** A place for one of the add-to-cart form's own fields. */
	public const HOST = 'host';

	/** A number, drawn as a slider. */
	public const NUMBER = 'number';

	/** A text field on one line. */
	public const TEXT = 'text';

	/** A text field that takes more than one line, drawn as a text area. */
	public const TEXT_LINES = 'text-lines';

	/** A layer's scale slider, which the SDK draws after the step's fields. */
	public const SCALE = 'scale';

	/**
	 * The hint to drag a layer on the preview, which the SDK draws once at
	 * the end of a step whose layers move or turn.
	 */
	public const MOVE = 'move';

	/** Tiles of a font's face, a fit's words and a shape's outline. */
	public const FONT_TILES  = 'font';
	public const FIT_TILES   = 'fit';
	public const SHAPE_TILES = 'shape';

	/** The props whose choices the SDK draws as tiles rather than a menu, and the tiles each draws. */
	private const TILE_PROPS = array(
		'shape'      => self::SHAPE_TILES,
		'fontFamily' => self::FONT_TILES,
		'fit'        => self::FIT_TILES,
	);

	/** An image layer's picture: the one picture field that may be left out. */
	private const SRC_PROP = 'src';

	/** The prop of a text layer's size, a share of the plate's height. */
	private const FONT_SIZE_PROP = 'fontSizeFrac';

	/** The key the artwork's own fields, the die-cut's among them, travel under. */
	public const ARTWORK_KEY = '$artwork';

	/** The most offered values the SDK draws as tiles; a longer list is a drawn list. */
	private const MAX_TILES = 8;

	/** The plain rectangle: a shape field's default when no shape is chosen. */
	private const UNIT_SQUARE_PATH = 'M 0 0 L 1 0 L 1 1 L 0 1 Z';

	/** The text area's rows when the field sets no line limit, and the most it takes. */
	private const DEFAULT_ROWS = 3;
	private const MAX_ROWS     = 5;

	/** A slider's ends when the field sets none, as a browser's range input has them. */
	private const SLIDER_MIN = 0;
	private const SLIDER_MAX = 100;

	/** A range input's step that leaves a default off any grid where it is. */
	private const ANY_STEP = 'any';

	/** The decimals a number is printed with in an input's attribute. */
	private const ATTRIBUTE_DECIMALS = 15;

	/** A layer's scale slider's ends when the author set none, and its grain when they set no step. */
	private const SCALE_MIN  = 0.1;
	private const SCALE_MAX  = 10;
	private const SCALE_STEP = 0.01;

	/** How far off a step's grid a bound may be and still count as on it: `1.15 / 0.01` is 114.99999999999999. */
	private const GRID_TOLERANCE = 1e-9;

	/**
	 * A text size's ends when the template sets none, as shares of the
	 * plate's height (the SDK's `offeredFontSizeBounds`), and its grain.
	 */
	private const FONT_SIZE_FLOOR   = 0.005;
	private const FONT_SIZE_CEILING = 0.5;
	private const FONT_SIZE_STEP    = 0.001;

	/** A crop's zoom ends when the author set none: the covering picture, and three times it. */
	private const CROP_MIN_ZOOM         = 1;
	private const CROP_DEFAULT_MAX_ZOOM = 3;

	/** The full slider, as a percentage. */
	private const PERCENT = 100;

	/** A colour channel's largest value, and a CMYK ink's. */
	private const CHANNEL_MAX = 255;
	private const INK_MAX     = 100;

	/** What a colour input shows when it is handed no colour. */
	private const NO_COLOUR = '#000000';

	/** The bytes of one UTF-16 code unit: a browser counts a text's length in them. */
	private const UTF16_UNIT_BYTES = 2;

	/**
	 * A field's control.
	 *
	 * @param array<mixed>                                          $field    A `formSchema` field.
	 * @param array<string, list<array{name: string, css: string}>> $palettes The template's palettes, each entry's name and CSS colour, by palette name.
	 * @param array<string, string>                                 $shapes   The shape each shape field starts on, by layer id, the die-cut's under the artwork's key: the template read's `layerShapeIds` and `dieShapeId`.
	 * @return Control
	 */
	public static function field( array $field, array $palettes, array $shapes = array() ): array {
		$control = self::drawn( $field, $palettes, $shapes );
		$layer   = self::text( $field['layerId'] ?? null );
		$prop    = self::text( $field['prop'] ?? null );
		if ( '' !== $layer && '' !== $prop && self::takes_input( $control ) ) {
			$control['name'] = $layer . ':' . $prop;
		}
		return $control;
	}

	/**
	 * Whether the control is printed as an input the SDK can take over: every
	 * text and free colour, a menu holding its default, and a slider that can.
	 *
	 * @param array<string, mixed> $control A field's control.
	 * @phpstan-param Control $control
	 */
	private static function takes_input( array $control ): bool {
		switch ( $control['type'] ) {
			case self::TEXT:
			case self::TEXT_LINES:
			case self::COLOR:
				return true;
			case self::SELECT:
				return array() !== $control['options'];
			case self::NUMBER:
				return array() !== $control['bounds'];
			default:
				return false;
		}
	}

	/**
	 * What the SDK draws for a field.
	 *
	 * @param array<mixed>                                          $field    A `formSchema` field.
	 * @param array<string, list<array{name: string, css: string}>> $palettes The template's palettes.
	 * @param array<string, string>                                 $shapes   The shape each shape field starts on, by layer id.
	 * @return Control
	 */
	private static function drawn( array $field, array $palettes, array $shapes ): array {
		$constraints = is_array( $field['constraints'] ?? null ) ? $field['constraints'] : array();
		$default     = $field['default'] ?? null;
		$prop        = is_string( $field['prop'] ?? null ) ? $field['prop'] : '';
		$one_of      = is_array( $constraints['oneOf'] ?? null ) ? array_values( array_map( 'strval', array_filter( $constraints['oneOf'], 'is_scalar' ) ) ) : null;
		$control     = self::control( self::text( $field['label'] ?? null ), self::text( $field['type'] ?? null ) );
		$control     = array( 'help' => trim( self::text( $field['description'] ?? null ) ) ) + $control;
		if ( self::IMAGE === $control['type'] ) {
			return self::picture( $control, $prop, $constraints, $one_of, $default );
		}
		if ( self::CROP === $control['type'] ) {
			return self::crop( $control, $constraints, $default );
		}
		if ( null !== $one_of && array() !== $one_of ) {
			$chosen = array_search( is_scalar( $default ) ? (string) $default : null, $one_of, true );
			$faces  = isset( self::TILE_PROPS[ $prop ] );
			if ( $faces && count( $one_of ) <= self::MAX_TILES ) {
				$control['type'] = self::TILES;
				$control['kind'] = self::TILE_PROPS[ $prop ];
				if ( self::SHAPE_TILES === $control['kind'] ) {
					$layer = is_string( $field['layerId'] ?? null ) ? $field['layerId'] : self::ARTWORK_KEY;
					return self::shapes( $control, $constraints, $one_of, $default, $shapes[ $layer ] ?? null );
				}
				$control['options'] = $one_of;
				$control['chosen']  = false === $chosen ? -1 : $chosen;
				return $control;
			}
			$control['type'] = self::SELECT;
			$control['kind'] = $prop;
			if ( self::SHAPE_TILES === ( self::TILE_PROPS[ $prop ] ?? null ) ) {
				// A shape's id is no word to show; the drawn list names it.
				return $control;
			}
			$control['value'] = false === $chosen ? $one_of[0] : $one_of[ $chosen ];
			if ( ! $faces && false !== $chosen ) {
				// A menu showing another value than the default would change the design if taken.
				$control['options'] = $one_of;
			}
			return $control;
		}
		if ( is_array( $default ) && is_array( $default['gradient'] ?? null ) ) {
			$control['type'] = self::GRADIENT;
			$stops           = is_array( $default['gradient']['stops'] ?? null ) ? $default['gradient']['stops'] : array();
			foreach ( $stops as $stop ) {
				$control['stops'][] = self::colour_control( self::control( '', self::COLOR ), is_array( $stop ) ? ( $stop['color'] ?? null ) : null, $constraints, $palettes );
			}
			return $control;
		}
		if ( null !== self::colour( $default ) || is_string( $constraints['palette'] ?? null ) ) {
			return self::colour_control( $control, $default, $constraints, $palettes );
		}
		if ( is_int( $default ) || is_float( $default ) ) {
			$bounds              = self::FONT_SIZE_PROP === $prop ? self::font_size_bounds( $constraints, $default ) : array( $constraints['min'] ?? null, $constraints['max'] ?? null );
			$control['type']     = self::NUMBER;
			$control['position'] = self::position( $default, $bounds[0], $bounds[1] );
			$low                 = self::number( $bounds[0] ) ?? self::SLIDER_MIN;
			$high                = self::number( $bounds[1] ) ?? self::SLIDER_MAX;
			if ( $default >= $low && $default <= $high ) {
				$step              = self::number( $constraints['step'] ?? null ) ?? ( self::FONT_SIZE_PROP === $prop ? self::FONT_SIZE_STEP : null );
				$control['value']  = self::number_text( $default );
				$control['bounds'] = array( self::number_text( $low ), self::number_text( $high ) );
				$control['step']   = null === $step || $step <= 0 ? self::ANY_STEP : self::number_text( $step );
			}
			return $control;
		}
		if ( is_array( $default ) ) {
			// A value the page has no control for: its label alone.
			return $control;
		}
		$lines                 = $constraints['maxLines'] ?? null;
		$control['type']       = 1 === $lines ? self::TEXT : self::TEXT_LINES;
		$control['rows']       = 1 === $lines ? 1 : min( is_int( $lines ) && $lines > 1 ? $lines : self::DEFAULT_ROWS, self::MAX_ROWS );
		$control['value']      = is_string( $default ) ? $default : '';
		$control['max_length'] = is_int( $constraints['maxLength'] ?? null ) && $constraints['maxLength'] > 0 ? $constraints['maxLength'] : 0;
		// The designer counts the characters beside the label, as the browser counts a text box's length.
		$control['aside'] = $control['max_length'] > 0 ? self::length( $control['value'] ) . ' / ' . $control['max_length'] : '';
		return $control;
	}

	/**
	 * A layer's scale slider.
	 *
	 * @param array<mixed> $scale The layer's `transform.scale`.
	 * @return Control
	 */
	public static function scale( array $scale ): array {
		$control             = self::control( self::text( $scale['label'] ?? null ), self::SCALE );
		$control['help']     = trim( self::text( $scale['description'] ?? null ) );
		$bounds              = self::scale_bounds( $scale );
		$control['position'] = self::position( 1, $bounds[0], $bounds[1] );
		return $control;
	}

	/**
	 * The hint to drag the step's layers on the preview.
	 *
	 * @param string[] $labels The author's label of each layer that moves or turns, empty when it set none.
	 * @phpstan-param list<string> $labels
	 * @return Control
	 */
	public static function move( array $labels ): array {
		$control            = self::control( 1 === count( $labels ) ? $labels[0] : '', self::MOVE );
		$control['several'] = count( $labels ) > 1;
		return $control;
	}

	/**
	 * A place in the form for one of the add-to-cart form's own fields, which
	 * is put there rather than drawn: its field's name.
	 *
	 * @param string $name     The attribute field's name.
	 * @param bool   $own_step Whether it stands in a step of its own, whose heading names it.
	 * @return Control
	 */
	public static function host( string $name, bool $own_step = false ): array {
		$control             = self::control( '', self::HOST );
		$control['host']     = $name;
		$control['own_step'] = $own_step;
		return $control;
	}

	/**
	 * `$control` with the placeholder and the offered values' words a side's
	 * arrangement gives its field (`formGroups`).
	 *
	 * @param array<string, mixed> $control A field's control.
	 * @param array<string, mixed> $words   The arrangement's item for it.
	 * @return Control
	 */
	public static function with_words( array $control, array $words ): array {
		$control['placeholder'] = is_string( $words['placeholder'] ?? null ) ? $words['placeholder'] : '';
		$control['words']       = is_array( $words['options'] ?? null ) || is_object( $words['options'] ?? null ) ? array_filter( (array) $words['options'], 'is_string' ) : array();
		/**
		 * The control, with the two keys set.
		 *
		 * @var Control $control
		 */
		return $control;
	}

	/**
	 * A colour value as CSS reads it: an RGB colour as itself, a CMYK one
	 * (or a spot ink's CMYK) as it reads on a screen, roughly; null for any
	 * other value.
	 *
	 * @param mixed $value A colour value.
	 */
	public static function colour( mixed $value ): ?string {
		if ( ! is_array( $value ) ) {
			return null;
		}
		if ( is_array( $value['spot'] ?? null ) ) {
			$value = is_array( $value['spot']['fallback'] ?? null ) ? $value['spot']['fallback'] : array();
		}
		$rgb  = self::numbers( $value['rgb'] ?? null, 3 );
		$cmyk = self::numbers( $value['cmyk'] ?? null, 4 );
		if ( null === $rgb && null !== $cmyk ) {
			$black = 1 - $cmyk[3] / self::INK_MAX;
			$rgb   = array_map( static fn ( $ink ): float => self::CHANNEL_MAX * ( 1 - $ink / self::INK_MAX ) * $black, array_slice( $cmyk, 0, 3 ) );
		}
		return null === $rgb ? null : vsprintf( '#%02x%02x%02x', array_map( static fn ( $channel ): int => (int) round( max( 0, min( self::CHANNEL_MAX, $channel ) ) ), $rgb ) );
	}

	/**
	 * A picture field: its tiles (a "no picture" one first, when the picture
	 * may be left out), the default chosen, and the upload button.
	 *
	 * @param array<string, mixed> $control     The control so far.
	 * @param string               $prop        The prop the field sets.
	 * @param array<mixed>         $constraints The field's constraints.
	 * @param list<string>|null    $one_of      The pictures offered; null for none.
	 * @param mixed                $fallback     The field's default.
	 * @phpstan-param Control $control
	 * @phpstan-param list<string>|null $one_of
	 * @return Control
	 */
	private static function picture( array $control, string $prop, array $constraints, ?array $one_of, mixed $fallback ): array {
		$none               = null !== $one_of && self::SRC_PROP === $prop && true !== ( $constraints['required'] ?? null );
		$control['options'] = array_merge( $none ? array( '' ) : array(), $one_of ?? array() );
		$control['upload']  = true === ( $constraints['allowUpload'] ?? null ) || null === $one_of;
		$chosen             = array_search( is_string( $fallback ) ? $fallback : '', $control['options'], true );
		$control['chosen']  = false === $chosen ? -1 : $chosen;
		return $control;
	}

	/**
	 * A picture's crop: the zoom slider at the author's zoom within the
	 * offered range, and the zoom and the range's ends as the slider writes
	 * them.
	 *
	 * @param array<string, mixed> $control     The control so far.
	 * @param array<mixed>         $constraints The field's constraints.
	 * @param mixed                $fallback     The author's crop.
	 * @phpstan-param Control $control
	 * @return Control
	 */
	private static function crop( array $control, array $constraints, mixed $fallback ): array {
		$low                 = self::number( $constraints['minZoom'] ?? null ) ?? self::CROP_MIN_ZOOM;
		$high                = self::number( $constraints['maxZoom'] ?? null ) ?? self::CROP_DEFAULT_MAX_ZOOM;
		$zoom                = self::number( is_array( $fallback ) ? ( $fallback['zoom'] ?? null ) : null ) ?? self::CROP_MIN_ZOOM;
		$control['position'] = self::position( $zoom, $low, $high );
		$control['value']    = (string) $zoom;
		$control['bounds']   = array( (string) $low, (string) $high );
		return $control;
	}

	/**
	 * A shape field's tiles: "None" first when the author allows no shape,
	 * then each shape offered, the one it starts on chosen.
	 *
	 * @param array<string, mixed> $control     The control so far.
	 * @param array<mixed>         $constraints The field's constraints.
	 * @param string[]             $one_of      The shapes offered, by id.
	 * @param mixed                $fallback     The field's default: the shape's outline.
	 * @param string|null          $authored    The shape it starts on, by id; null when the template read names none.
	 * @phpstan-param Control $control
	 * @phpstan-param list<string> $one_of
	 * @return Control
	 */
	private static function shapes( array $control, array $constraints, array $one_of, mixed $fallback, ?string $authored ): array {
		$none               = true === ( $constraints['allowEmpty'] ?? null );
		$control['options'] = array_merge( $none ? array( '' ) : array(), $one_of );
		$unchosen           = null === $fallback || ( is_array( $fallback ) && 'path' === ( $fallback['type'] ?? null ) && self::UNIT_SQUARE_PATH === ( $fallback['dFrac'] ?? null ) );
		$chosen             = null !== $authored ? array_search( $authored, $control['options'], true ) : ( $none && $unchosen ? 0 : false );
		$control['chosen']  = false === $chosen ? -1 : $chosen;
		return $control;
	}

	/**
	 * A colour's control: a palette's swatches when the field names a
	 * palette, else one swatch.
	 *
	 * @param array<string, mixed>                                  $control     The control so far.
	 * @param mixed                                                 $value       The colour: a colour value, or a palette entry's name.
	 * @param array<mixed>                                          $constraints The field's constraints.
	 * @param array<string, list<array{name: string, css: string}>> $palettes    The template's palettes.
	 * @phpstan-param Control $control
	 * @return Control
	 */
	private static function colour_control( array $control, mixed $value, array $constraints, array $palettes ): array {
		$colour = self::colour( $value );
		if ( ! is_string( $constraints['palette'] ?? null ) ) {
			$control['type']  = self::COLOR;
			$control['value'] = $colour ?? self::NO_COLOUR;
			// The designer writes the colour beside the label, a CMYK one as
			// its print profile shows it, which the page leaves to it.
			$control['aside'] = is_array( $value ) && is_array( $value['rgb'] ?? null ) && null !== $colour ? strtoupper( $colour ) : '';
			return $control;
		}
		$entries            = $palettes[ $constraints['palette'] ] ?? array();
		$control['type']    = self::PALETTE;
		$control['options'] = array_column( $entries, 'css' );
		foreach ( $entries as $index => $entry ) {
			if ( is_string( $value ) ? $entry['name'] === $value : $entry['css'] === $colour ) {
				$control['chosen'] = $index;
				$control['aside']  = $entry['name'];
				break;
			}
		}
		$control['custom'] = true === ( $constraints['anyColor'] ?? null );
		if ( $control['custom'] && -1 === $control['chosen'] ) {
			// A colour the palette does not hold is the customer's own: the
			// designer opens its picker on it, beside "Custom…".
			$control['value'] = $colour ?? self::NO_COLOUR;
		}
		return $control;
	}

	/**
	 * A text size's slider ends when the template leaves them out, as the
	 * SDK fills them: the template's own bounds win, and the range always
	 * holds the default.
	 *
	 * @param array<mixed> $constraints The field's constraints.
	 * @param int|float    $value       The field's default.
	 * @return array{0: int|float, 1: int|float}
	 */
	private static function font_size_bounds( array $constraints, int|float $value ): array {
		$min  = self::number( $constraints['min'] ?? null );
		$max  = self::number( $constraints['max'] ?? null ) ?? max( self::FONT_SIZE_CEILING, $min ?? $value, $value );
		$step = self::number( $constraints['step'] ?? null );
		$step = null !== $step && $step > 0 ? $step : self::FONT_SIZE_STEP;
		if ( null !== $min ) {
			return array( $min, $max );
		}
		// The lowest point of the default's step grid at or above the floor.
		$start = min( self::FONT_SIZE_FLOOR, $max, $value );
		return array( $value - floor( ( $value - $start ) / $step + self::GRID_TOLERANCE ) * $step, $max );
	}

	/**
	 * A layer's scale slider ends, as the designer reads its scale axis:
	 * each bound the author's when it is a scale above zero, else 0.1 or 10,
	 * narrowed onto the axis's step grid.
	 *
	 * @param array<mixed> $axis The layer's `transform.scale`.
	 * @return array{0: int|float, 1: int|float}
	 */
	private static function scale_bounds( array $axis ): array {
		$scale    = static fn ( mixed $value ): bool => null !== self::number( $value ) && $value > 0;
		$min      = $scale( $axis['min'] ?? null ) ? $axis['min'] : self::SCALE_MIN;
		$max      = $scale( $axis['max'] ?? null ) ? $axis['max'] : self::SCALE_MAX;
		$authored = $scale( $axis['step'] ?? null ) && $axis['step'] <= self::SCALE_MAX;
		$step     = $authored ? $axis['step'] : self::SCALE_STEP;
		// An author's step counts from their minimum, hundredths from zero.
		$base  = $authored && null !== self::number( $axis['min'] ?? null ) ? $axis['min'] : 0;
		$first = (int) ceil( ( $min - $base ) / $step - self::GRID_TOLERANCE );
		$last  = (int) floor( ( $max - $base ) / $step + self::GRID_TOLERANCE );
		if ( $min <= $max && $first > $last ) {
			return array( $min, $min );
		}
		return array( $base + $first * $step, $base + $last * $step );
	}

	/**
	 * A text's length as a browser counts it: in UTF-16 code units, so an
	 * emoji outside the Basic Multilingual Plane counts as two.
	 *
	 * @param string $text UTF-8 text.
	 */
	private static function length( string $text ): int {
		return intdiv( strlen( mb_convert_encoding( $text, 'UTF-16LE', 'UTF-8' ) ), self::UTF16_UNIT_BYTES );
	}

	/**
	 * A number as an input's attribute takes it, whatever the locale.
	 *
	 * @param int|float $value A number.
	 */
	private static function number_text( int|float $value ): string {
		$text = sprintf( '%.' . self::ATTRIBUTE_DECIMALS . 'F', $value );
		return rtrim( rtrim( $text, '0' ), '.' );
	}

	/**
	 * @param mixed $value Decoded JSON.
	 * @return int|float|null The number; null for any other value.
	 */
	private static function number( mixed $value ): int|float|null {
		return is_int( $value ) || is_float( $value ) ? $value : null;
	}

	/**
	 * A control with nothing set but its label and type.
	 *
	 * @param string $label The field's label.
	 * @param string $type  The control's type.
	 * @return Control
	 */
	private static function control( string $label, string $type ): array {
		return array(
			'label'       => $label,
			'type'        => $type,
			'kind'        => '',
			'value'       => '',
			'aside'       => '',
			'help'        => '',
			'rows'        => 0,
			'max_length'  => 0,
			'options'     => array(),
			'chosen'      => -1,
			'position'    => 0.0,
			'step'        => '',
			'name'        => '',
			'upload'      => false,
			'several'     => false,
			'custom'      => false,
			'bounds'      => array(),
			'stops'       => array(),
			'host'        => '',
			'own_step'    => false,
			'placeholder' => '',
			'words'       => array(),
		);
	}

	/**
	 * Where `$value` stands between the slider's ends, as a percentage.
	 *
	 * @param int|float $value The value.
	 * @param mixed     $min   The low end; the default one when not a number.
	 * @param mixed     $max   The high end; the default one when not a number.
	 */
	private static function position( int|float $value, mixed $min, mixed $max ): float {
		$low  = is_int( $min ) || is_float( $min ) ? $min : self::SLIDER_MIN;
		$high = is_int( $max ) || is_float( $max ) ? $max : self::SLIDER_MAX;
		if ( $high <= $low ) {
			return 0.0;
		}
		return round( max( 0, min( 1, ( $value - $low ) / ( $high - $low ) ) ) * self::PERCENT, 4 );
	}

	/**
	 * @param mixed $value Decoded JSON.
	 * @param int   $count How many numbers it holds.
	 * @return list<int|float>|null `$count` numbers, or null.
	 */
	private static function numbers( mixed $value, int $count ): ?array {
		if ( ! is_array( $value ) || count( $value ) !== $count ) {
			return null;
		}
		$numbers = array();
		foreach ( $value as $number ) {
			if ( ! is_int( $number ) && ! is_float( $number ) ) {
				return null;
			}
			$numbers[] = $number;
		}
		return $numbers;
	}

	/**
	 * @param mixed $value Decoded JSON.
	 */
	private static function text( mixed $value ): string {
		return is_string( $value ) ? $value : '';
	}
}
