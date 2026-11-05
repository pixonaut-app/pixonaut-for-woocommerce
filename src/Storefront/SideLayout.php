<?php
/**
 * What one side of the designer looks like once it is drawn.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\DesignerLanguage;

/**
 * The layout a side's designer ends up with, worked out on the server from
 * its mount options and the reads it starts from, so the page prints it
 * before the SDK runs: the preview's aspect ratio, the views of its strip,
 * and the steps and fields of its form.
 *
 * @phpstan-import-type Control from FormControl
 */
final class SideLayout {

	/**
	 * The preview's ratio when nothing read says otherwise: the SDK draws a
	 * square until the photo arrives.
	 */
	private const SQUARE = array( 1, 1 );

	/** The form layout that shows one step at a time. */
	private const STEPS_LAYOUT = 'steps';

	/**
	 * @param array{0: int|float, 1: int|float}                         $ratio The preview's width and height, in any unit.
	 * @param list<array{id: string, label: string, thumbnail: string}> $views The views of the strip, in order, each with its name and its photo's link; empty for no strip.
	 * @param list<array{label: string, fields: list<Control>}>         $steps The steps the form shows at first, each with its fields' controls (`FormControl`), a layer's scale slider among them as one of type `scale`, and the hint to drag its layers as one of type `move`.
	 * @param string[]                                                  $sizes The print size picker's entries; empty for no picker.
	 * @param bool                                                      $options_step Whether the form shows the product's options as its first step, before the steps in `$steps`, which then are empty.
	 * @param bool                                                      $template_shaped Whether the preview keeps the template's shape whatever height a theme holds previews at: a side on no blank, which has no photos to switch between.
	 * @param string[]                                                  $step_labels In the steps layout, the name of every step the form goes through, the product's options' first (unnamed: the designer names it in its own words); empty when the form is one page.
	 * @param string|null                                               $language The designer's language, one of `DesignerLanguage::languages()`: the mount's `locale`, else the template read's `workspaceLanguage`; null when neither names one.
	 * @param bool                                                      $powered_by Whether the designer shows "Powered by Pixonaut" under the preview: unless the template read says it does not, as the SDK decides.
	 * @param bool                                                      $lends_options Whether the add-to-cart form's option fields, when it has any, go in the form as one part: with one side, unless its groups place them one by one.
	 * @param bool                                                      $lends_cart Whether the add-to-cart form's cart row goes in the form: with one side.
	 * @param string[]                                                  $later_hosts In the steps layout, the field name of every attribute the groups place in a step after the first, which the printed form holds in hidden slots; empty otherwise.
	 * @phpstan-param list<string> $sizes
	 * @phpstan-param list<string> $step_labels
	 * @phpstan-param list<string> $later_hosts
	 */
	public function __construct(
		public readonly array $ratio,
		public readonly array $views,
		public readonly array $steps,
		public readonly array $sizes,
		public readonly bool $options_step = false,
		public readonly bool $template_shaped = false,
		public readonly array $step_labels = array(),
		public readonly ?string $language = null,
		public readonly bool $powered_by = true,
		public readonly bool $lends_options = false,
		public readonly bool $lends_cart = false,
		public readonly array $later_hosts = array(),
	) {
	}

	/**
	 * @param array<string, mixed> $options     The side's mount options.
	 * @param array<string, mixed> $reads       Its server reads (`ServerReads`), or a correction's (its design's `printSize`, the box the SDK draws it in, in place of the template's ratio); empty when none were made.
	 * @param string|null          $variant     The ref of the blank variant the page starts on; null for none.
	 * @param bool                 $has_options Whether the designer draws the product's options (its variation fields, the colour and size picker, the print size picker) as a step of their own when its form shows steps.
	 * @param bool                 $lending     Whether the add-to-cart form lends the designer its option fields and cart row: a product with one side.
	 */
	public static function of( array $options, array $reads, ?string $variant, bool $has_options = false, bool $lending = false ): self {
		$template  = self::map( $reads['template'] ?? null );
		$doc_ratio = self::ratio( self::map( $template['template'] ?? null )['ratio'] ?? null ) ?? self::ratio( $reads['printSize'] ?? null );
		$placement = is_string( $options['placementId'] ?? null ) ? $options['placementId'] : null;
		$mockups   = self::map( self::map( $reads['blank'] ?? null )['mockups'] ?? null );
		$shown     = self::strings( $options['views'] ?? array() );
		$variant ??= self::strings( $options['variants'] ?? array() )[0] ?? null;
		$views     = self::views( $mockups, $placement, $shown, $variant );
		if ( array() === $views && null !== $variant ) {
			// A variant the blank has no photo of: the SDK shows each view's own.
			$views = self::views( $mockups, $placement, $shown, null );
		}
		$first = self::first_view( $views, is_string( $options['initialView'] ?? null ) ? $options['initialView'] : null );
		$ratio = null !== $first && ProductConfig::DESIGN_VIEW !== $first['id'] ? $first['ratio'] : $doc_ratio;
		$sizes = array();
		// A side whose variations pick the size draws no picker of its own (`VariationSwitches::mounts()`).
		$picks_size = false !== ( self::map( $options['showPickers'] ?? null )['size'] ?? true );
		foreach ( $picks_size && is_array( $options['printSizes'] ?? null ) ? $options['printSizes'] : array() as $size ) {
			$sizes[] = is_array( $size ) && is_string( $size['label'] ?? null ) ? $size['label'] : '';
		}
		$arranged       = is_array( self::map( $options['formGroups'] ?? null )['groups'] ?? null ) ? array_values( array_filter( $options['formGroups']['groups'], 'is_array' ) ) : null;
		$hosted         = $lending && null !== $arranged && self::places_attribute( $arranged );
		$template_steps = self::steps( self::map( $template['formSchema'] ?? null ), is_string( $options['layout'] ?? null ) ? $options['layout'] : null, self::palettes( self::map( $template['template'] ?? null )['palettes'] ?? null ), self::shapes( $template ), $arranged, $lending );
		// A side whose groups place the attributes draws no step for its options.
		$options_step = $has_options && $template_steps['steps'] && ! $hosted;
		$step_labels  = $template_steps['steps'] ? array_merge( $options_step ? array( '' ) : array(), $template_steps['labels'] ) : array();
		return new self(
			$ratio ?? self::SQUARE,
			array_map(
				static fn ( array $view ): array => array(
					'id'        => $view['id'],
					'label'     => $view['label'],
					'thumbnail' => $view['thumbnail'],
				),
				$views
			),
			$options_step ? array() : $template_steps['shown'],
			$sizes,
			$options_step,
			null === $placement,
			$step_labels,
			self::language( $options['locale'] ?? null ) ?? self::language( $template['workspaceLanguage'] ?? null ),
			false !== ( $template['poweredBy'] ?? true ),
			$lending && ! $hosted,
			$lending,
			$template_steps['later_hosts'],
		);
	}

	/**
	 * The CSS custom properties the preview is sized by: its ratio as
	 * `aspect-ratio` takes it, and as a width per unit of height, which the
	 * narrow layout holds its width by.
	 */
	public function style(): string {
		return sprintf(
			'--pixonaut-preview-ratio: %1$s / %2$s; --pixonaut-preview-aspect: %3$s',
			self::number( $this->ratio[0] ),
			self::number( $this->ratio[1] ),
			self::number( $this->ratio[0] / $this->ratio[1] )
		);
	}

	/**
	 * The views the strip shows, in order: the placement's views the product
	 * is shown in and has a photo the SDK can draw, then the Design view,
	 * each with the photo the variant has in it (a photo naming no variant
	 * shows every one).
	 *
	 * @param array<string, mixed> $mockups   The blank's mockups answer.
	 * @param string|null          $placement The side's placement; null shows the Design view alone.
	 * @param string[]             $shown     The view ids the product is shown in, `design` among them; empty for all.
	 * @param string|null          $variant   The variant shown.
	 * @phpstan-param list<string> $shown
	 * @return list<array{id: string, label: string, thumbnail: string, ratio: array{0: int|float, 1: int|float}|null}>
	 */
	private static function views( array $mockups, ?string $placement, array $shown, ?string $variant ): array {
		$views = array();
		foreach ( is_array( $mockups['placements'] ?? null ) ? $mockups['placements'] : array() as $row ) {
			if ( ! is_array( $row ) || ( $row['id'] ?? null ) !== $placement ) {
				continue;
			}
			foreach ( is_array( $row['views'] ?? null ) ? $row['views'] : array() as $view ) {
				$id    = is_array( $view ) && is_string( $view['id'] ?? null ) ? $view['id'] : null;
				$photo = null === $id ? null : self::photo( is_array( $view['photos'] ?? null ) ? $view['photos'] : array(), $variant );
				if ( null === $id || null === $photo || ( array() !== $shown && ! in_array( $id, $shown, true ) ) ) {
					continue;
				}
				$views[] = array(
					'id'        => $id,
					'label'     => is_string( $view['label'] ?? null ) ? $view['label'] : '',
					'thumbnail' => is_string( $photo['thumbnailUrl'] ?? null ) ? $photo['thumbnailUrl'] : '',
					'ratio'     => self::ratio(
						array(
							'w' => $photo['width'] ?? null,
							'h' => $photo['height'] ?? null,
						)
					),
				);
			}
		}
		if ( array() === $views ) {
			return array();
		}
		if ( array() === $shown || in_array( ProductConfig::DESIGN_VIEW, $shown, true ) ) {
			// The SDK names the Design view in its own words.
			$views[] = array(
				'id'        => ProductConfig::DESIGN_VIEW,
				'label'     => '',
				'thumbnail' => '',
				'ratio'     => null,
			);
		}
		return $views;
	}

	/**
	 * The photo a view shows the variant in: its own, else the one naming
	 * no variant. A photo without a `mount` (still uploading, or with no
	 * print area marked) is one the SDK cannot draw, and counts for none.
	 *
	 * @param array<mixed> $photos  The view's photos.
	 * @param string|null  $variant The variant shown.
	 * @return array<string, mixed>|null
	 */
	private static function photo( array $photos, ?string $variant ): ?array {
		$any = null;
		foreach ( $photos as $photo ) {
			if ( ! is_array( $photo ) || ! is_array( $photo['mount'] ?? null ) ) {
				continue;
			}
			$refs = self::strings( $photo['variantRefs'] ?? array() );
			if ( null !== $variant && in_array( $variant, $refs, true ) ) {
				return $photo;
			}
			if ( array() === $refs || null === $variant ) {
				$any ??= $photo;
			}
		}
		return $any;
	}

	/**
	 * The view the designer opens on: the product's starting view when the
	 * strip has it, else the first.
	 *
	 * @param list<array{id: string, label: string, thumbnail: string, ratio: array{0: int|float, 1: int|float}|null}> $views   The strip's views.
	 * @param string|null                                                                                              $initial The product's starting view.
	 * @return array{id: string, label: string, thumbnail: string, ratio: array{0: int|float, 1: int|float}|null}|null
	 */
	private static function first_view( array $views, ?string $initial ): ?array {
		foreach ( $views as $view ) {
			if ( $view['id'] === $initial ) {
				return $view;
			}
		}
		return $views[0] ?? null;
	}

	/**
	 * The steps the form shows before the customer moves on (every step, or
	 * the first alone in the steps layout), the name of every step, and
	 * whether it shows steps.
	 *
	 * @param array<string, mixed>                                  $schema   The template's `formSchema`.
	 * @param string|null                                           $layout   The mount's form layout; null follows the template's.
	 * @param array<string, list<array{name: string, css: string}>> $palettes The template's palettes (`palettes()`).
	 * @param array<string, string>                                 $shapes   The shape each shape field starts on, by layer id (`shapes()`).
	 * @param list<array<string, mixed>>|null                       $arranged The groups the side arranged (`formGroups`); null for the template's own form.
	 * @param bool                                                  $lending  Whether the add-to-cart form lends the designer its attribute fields.
	 * @return array{shown: list<array{label: string, fields: list<Control>}>, labels: list<string>, steps: bool, later_hosts: list<string>}
	 */
	private static function steps( array $schema, ?string $layout, array $palettes, array $shapes, ?array $arranged = null, bool $lending = false ): array {
		$steps    = null === $arranged ? self::template_steps( $schema, $palettes, $shapes ) : ArrangedSteps::of(
			array_values( array_filter( is_array( $schema['steps'] ?? null ) ? $schema['steps'] : array(), 'is_array' ) ),
			$arranged,
			$lending,
			static fn ( array $field ): array => FormControl::field( $field, $palettes, $shapes )
		);
		$layout ??= is_string( $schema['layout'] ?? null ) ? $schema['layout'] : null;
		$in_steps = self::STEPS_LAYOUT === $layout;
		$later    = array();
		foreach ( $in_steps ? array_slice( $steps, 1 ) : array() as $step ) {
			foreach ( $step['fields'] as $control ) {
				if ( FormControl::HOST === $control['type'] ) {
					$later[] = $control['host'];
				}
			}
		}
		return array(
			'shown'       => $in_steps ? array_slice( $steps, 0, 1 ) : $steps,
			'labels'      => array_column( $steps, 'label' ),
			'steps'       => $in_steps,
			'later_hosts' => $later,
		);
	}

	/**
	 * The steps of the template's own form, each with its fields' controls.
	 *
	 * @param array<string, mixed>                                  $schema   The template's `formSchema`.
	 * @param array<string, list<array{name: string, css: string}>> $palettes The template's palettes (`palettes()`).
	 * @param array<string, string>                                 $shapes   The shape each shape field starts on, by layer id (`shapes()`).
	 * @return list<array{label: string, fields: list<Control>}>
	 */
	private static function template_steps( array $schema, array $palettes, array $shapes ): array {
		$steps = array();
		foreach ( is_array( $schema['steps'] ?? null ) ? $schema['steps'] : array() as $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}
			$fields = array();
			foreach ( is_array( $step['fields'] ?? null ) ? $step['fields'] : array() as $field ) {
				if ( is_array( $field ) ) {
					$fields[] = FormControl::field( $field, $palettes, $shapes );
				}
			}
			$moves = array();
			foreach ( is_array( $step['transforms'] ?? null ) ? $step['transforms'] : array() as $transform ) {
				$axes  = is_array( $transform ) ? self::map( $transform['transform'] ?? null ) : array();
				$scale = self::map( $axes['scale'] ?? null );
				if ( true === ( $scale['editable'] ?? null ) ) {
					$fields[] = FormControl::scale( $scale );
				}
				$translate = self::map( $axes['translate'] ?? null );
				if ( true === ( $translate['editable'] ?? null ) || true === ( self::map( $axes['rotate'] ?? null )['editable'] ?? null ) ) {
					$moves[] = is_string( $translate['label'] ?? null ) ? $translate['label'] : '';
				}
			}
			if ( array() !== $moves ) {
				$fields[] = FormControl::move( $moves );
			}
			$steps[] = array(
				'label'  => is_string( $step['label'] ?? null ) ? $step['label'] : '',
				'fields' => $fields,
			);
		}
		return $steps;
	}

	/**
	 * Whether the groups place any of the product's attributes.
	 *
	 * @param list<array<string, mixed>> $groups The arrangement's groups.
	 */
	private static function places_attribute( array $groups ): bool {
		foreach ( $groups as $group ) {
			foreach ( is_array( $group['items'] ?? null ) ? $group['items'] : array() as $item ) {
				if ( is_array( $item ) && isset( $item['attribute'] ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * The template's palettes, each entry's name and the colour CSS draws
	 * it in, by palette name.
	 *
	 * @param mixed $palettes The template's `palettes`.
	 * @return array<string, list<array{name: string, css: string}>>
	 */
	private static function palettes( mixed $palettes ): array {
		$found = array();
		foreach ( is_array( $palettes ) ? $palettes : array() as $name => $entries ) {
			$colours = array();
			foreach ( is_array( $entries ) ? $entries : array() as $entry ) {
				$colour = is_array( $entry ) ? FormControl::colour( $entry['color'] ?? null ) : null;
				if ( null !== $colour ) {
					$colours[] = array(
						'name' => is_string( $entry['name'] ?? null ) ? $entry['name'] : '',
						'css'  => $colour,
					);
				}
			}
			$found[ (string) $name ] = $colours;
		}
		return $found;
	}

	/**
	 * The shape each shape field starts on, by layer id: each shape layer's
	 * (`layerShapeIds`), and the die-cut's under the artwork's key
	 * (`dieShapeId`).
	 *
	 * @param array<string, mixed> $template The template read.
	 * @return array<string, string>
	 */
	private static function shapes( array $template ): array {
		$shapes = array_filter( self::map( $template['layerShapeIds'] ?? null ), 'is_string' );
		if ( is_string( $template['dieShapeId'] ?? null ) ) {
			$shapes[ FormControl::ARTWORK_KEY ] = $template['dieShapeId'];
		}
		return $shapes;
	}

	/**
	 * @param mixed $code A language code, or a locale.
	 * @return string|null One of `DesignerLanguage::languages()`; null for any other value.
	 */
	private static function language( mixed $code ): ?string {
		return is_string( $code ) ? DesignerLanguage::for_locale( $code ) : null;
	}

	/**
	 * A positive `{w, h}` as a pair; null for any other value, such as a
	 * photo whose size is not known yet.
	 *
	 * @param mixed $size A `{w, h}`.
	 * @return array{0: int|float, 1: int|float}|null
	 */
	private static function ratio( mixed $size ): ?array {
		$w = is_array( $size ) ? ( $size['w'] ?? null ) : null;
		$h = is_array( $size ) ? ( $size['h'] ?? null ) : null;
		return ( is_int( $w ) || is_float( $w ) ) && ( is_int( $h ) || is_float( $h ) ) && $w > 0 && $h > 0 ? array( $w, $h ) : null;
	}

	/**
	 * @param mixed $value Decoded JSON.
	 * @return array<string, mixed>
	 */
	private static function map( mixed $value ): array {
		return is_array( $value ) ? $value : array();
	}

	/**
	 * @param mixed $value Decoded JSON.
	 * @return list<string>
	 */
	private static function strings( mixed $value ): array {
		return is_array( $value ) ? array_values( array_filter( $value, 'is_string' ) ) : array();
	}

	/**
	 * A number as CSS reads it, whatever the locale.
	 *
	 * @param int|float $value A ratio part.
	 */
	private static function number( int|float $value ): string {
		return rtrim( rtrim( sprintf( '%.4F', $value ), '0' ), '.' );
	}
}
