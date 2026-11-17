<?php
/**
 * The settings the active theme declares for a store to set.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Theme;

use WP_Error;

/**
 * The settings a theme declares on `pixonaut_wc_theme_settings`, each stored
 * in the WordPress option its key names, and checked against its JSON
 * schema as WordPress's REST API checks a value. The plugin names no theme's
 * settings: the theme says what it has.
 *
 * @phpstan-type Declared array{label: string, description: string, schema: array<string, mixed>, default: mixed}
 */
final class ThemeSettings {

	/**
	 * Each well-formed setting the theme declares, by its option: an entry
	 * whose key is not a string, or with no string label, string description
	 * or array schema, is left out. A missing default is null.
	 *
	 * @return array<string, Declared>
	 */
	public function declared(): array {
		/**
		 * Filters the settings the active theme declares for a store to set,
		 * which the abilities `get-theme-settings` and `update-theme-settings`
		 * read and change, also as shop tools. Each entry is keyed by the
		 * WordPress option the setting is stored in: `label` and
		 * `description` (what it does, each value) are strings, `schema` is
		 * the JSON schema a value must fit, as WordPress's REST API validates
		 * it, and `default` is what the theme shows while the option is unset
		 * (null when missing). An entry that is not well formed is left out.
		 *
		 * @since 1.16.0
		 *
		 * @param array<mixed> $settings The settings declared so far, each by its option; empty.
		 *
		 * @example
		 * add_filter( 'pixonaut_wc_theme_settings', function ( array $settings ) {
		 *     $settings['my_theme_header'] = array(
		 *         'label'       => __( 'Header', 'my-theme' ),
		 *         'description' => __( 'Which header the pages show: simple or mega.', 'my-theme' ),
		 *         'schema'      => array( 'type' => 'string', 'enum' => array( 'simple', 'mega' ) ),
		 *         'default'     => 'simple',
		 *     );
		 *     return $settings;
		 * } );
		 */
		$filtered = (array) apply_filters( 'pixonaut_wc_theme_settings', array() );
		$declared = array();
		foreach ( $filtered as $key => $entry ) {
			if ( ! is_string( $key ) || ! is_array( $entry ) || ! is_string( $entry['label'] ?? null ) || ! is_string( $entry['description'] ?? null ) || ! is_array( $entry['schema'] ?? null ) ) {
				continue;
			}
			$declared[ $key ] = array(
				'label'       => $entry['label'],
				'description' => $entry['description'],
				'schema'      => $entry['schema'],
				'default'     => $entry['default'] ?? null,
			);
		}
		return $declared;
	}

	/**
	 * Each declared setting with its value now, in the order declared.
	 *
	 * @return list<array{key: string, label: string, description: string, schema: array<string, mixed>, default: mixed, value: mixed}>
	 */
	public function read(): array {
		$read = array();
		foreach ( $this->declared() as $key => $setting ) {
			$read[] = array( 'key' => $key ) + $setting + array( 'value' => self::value( $key, $setting ) );
		}
		return $read;
	}

	/**
	 * Why the values given cannot all be stored, or null when they can: a
	 * key no setting has, or a value its schema does not take. Null for a
	 * setting deletes its option, whatever the schema.
	 *
	 * @param array<mixed> $values The values to store, by key.
	 * @return array{key: string, error: WP_Error|null}|null The first refused key, with the schema's error for a value that does not fit (null for an unknown key).
	 */
	public function refusal( array $values ): ?array {
		$declared = $this->declared();
		foreach ( $values as $key => $value ) {
			$key = (string) $key;
			if ( ! isset( $declared[ $key ] ) ) {
				return array(
					'key'   => $key,
					'error' => null,
				);
			}
			if ( null === $value ) {
				continue;
			}
			$valid = rest_validate_value_from_schema( $value, $declared[ $key ]['schema'], $key );
			if ( is_wp_error( $valid ) ) {
				return array(
					'key'   => $key,
					'error' => $valid,
				);
			}
		}
		return null;
	}

	/**
	 * Stores the values given, each as its schema sanitizes it; null deletes
	 * the option, so the theme shows its default. Check them with
	 * refusal() first.
	 *
	 * @param array<mixed> $values The values, by key, of declared settings.
	 */
	public function write( array $values ): void {
		$declared = $this->declared();
		foreach ( $values as $key => $value ) {
			$key = (string) $key;
			if ( ! isset( $declared[ $key ] ) ) {
				continue;
			}
			if ( null === $value ) {
				delete_option( $key );
				continue;
			}
			update_option( $key, rest_sanitize_value_from_schema( $value, $declared[ $key ]['schema'], $key ) );
		}
	}

	/**
	 * A setting's value: the stored option, as its schema reads it, when it
	 * fits the schema; otherwise, or while it is unset, the default.
	 *
	 * @param string       $key     The option.
	 * @param array<mixed> $setting The setting.
	 * @phpstan-param Declared $setting
	 */
	private static function value( string $key, array $setting ): mixed {
		$stored = get_option( $key, null );
		if ( null === $stored || true !== rest_validate_value_from_schema( $stored, $setting['schema'], $key ) ) {
			return $setting['default'];
		}
		return rest_sanitize_value_from_schema( $stored, $setting['schema'], $key );
	}
}
