<?php
/**
 * The block theme presets the designer takes its look from.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

/**
 * Which of the presets the storefront script reads from the page
 * (`assets/src/storefront/theme-look.ts`, `THEME_PROPERTIES`) the theme
 * defines in its global settings. The form prints them in its
 * `data-pixonaut-look`, which the storefront stylesheet draws the printed
 * form's step badges, the tabs and the approval box by, so the page draws
 * them from the first paint rather than once the script has read them. The
 * script writes the attribute again from what the page computes, which also
 * finds a preset a theme sets in its own stylesheet.
 */
final class ThemeLook {

	/**
	 * Each look's preset: the settings path under which the theme lists it
	 * (a palette, the font families) with the slug it has there, or a
	 * custom value's path.
	 */
	private const PRESETS = array(
		'accent'      => array( array( 'color', 'palette' ), 'accent' ),
		'border'      => array( array( 'color', 'palette' ), 'border-strong' ),
		'error'       => array( array( 'color', 'palette' ), 'error' ),
		'bodyFont'    => array( array( 'typography', 'fontFamilies' ), 'body' ),
		'headingFont' => array( array( 'typography', 'fontFamilies' ), 'heading' ),
		'radius'      => array( array( 'custom', 'radius', 'small' ), null ),
	);

	/**
	 * The looks the theme defines, in the order the script lists them.
	 *
	 * @return list<string>
	 */
	public static function presets(): array {
		if ( ! function_exists( 'wp_get_global_settings' ) ) {
			return array();
		}
		$found = array();
		foreach ( self::PRESETS as $look => list( $path, $slug ) ) {
			$value = wp_get_global_settings( $path );
			if ( null === $slug ? self::is_set( $value ) : self::lists( $value, $slug ) ) {
				$found[] = $look;
			}
		}
		return $found;
	}

	/**
	 * Whether a preset list, by origin (theme, default, custom), has an
	 * entry of `$slug`.
	 *
	 * @param mixed  $origins The setting.
	 * @param string $slug    The entry's slug.
	 */
	private static function lists( mixed $origins, string $slug ): bool {
		foreach ( is_array( $origins ) ? $origins : array() as $entries ) {
			foreach ( is_array( $entries ) ? $entries : array() as $entry ) {
				if ( is_array( $entry ) && ( $entry['slug'] ?? null ) === $slug ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Whether a custom value is set.
	 *
	 * @param mixed $value The setting.
	 */
	private static function is_set( mixed $value ): bool {
		return ( is_string( $value ) && '' !== $value ) || is_int( $value ) || is_float( $value );
	}
}
