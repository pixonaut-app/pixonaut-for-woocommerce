<?php
/**
 * One setting.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Settings;

use Closure;

/**
 * A setting's key, default, how a stored value is checked, and the field the
 * settings tab shows for it. The field is built only when the tab renders, so
 * its strings are translated after `init`.
 */
final class Setting {

	/**
	 * The settings tab's own section, saved by WooCommerce's settings form.
	 */
	public const GENERAL = 'general';

	/**
	 * The hidden developer tools section, saved by its own form.
	 */
	public const DEVELOPER = 'developer';

	/**
	 * How WooCommerce's settings form saves a ticked checkbox, and an unticked one.
	 */
	private const CHECKED = 'yes';

	private const UNCHECKED = 'no';

	/**
	 * A colour: `#rrggbb`.
	 */
	private const COLOUR_PATTERN = '/^#[0-9a-f]{6}$/i';

	/**
	 * @param string       $key       Key in the settings option.
	 * @param mixed        $fallback  Value used when none is stored or the stored one is invalid.
	 * @param Closure      $valid     Whether a value is acceptable.
	 * @param Closure      $field     Builds the WooCommerce settings field, without `id` and `default`.
	 * @param Closure|null $normalise Turns a valid value into the one read; null reads it as stored.
	 * @param string       $section   GENERAL or DEVELOPER.
	 * @param Closure|null $store     Turns a value read into the one stored, as the settings form saves it; null stores it as read.
	 * @param Closure|null $schema    The JSON schema of a value the setting takes, as an ability reads and writes it; null takes a string.
	 * @param Closure|null $text      Gives the field's `title` and `desc` without building its options; null builds the whole field.
	 * @phpstan-param Closure(mixed): bool $valid
	 * @phpstan-param Closure(): array<string, mixed> $field
	 * @phpstan-param (Closure(mixed): mixed)|null $normalise
	 * @phpstan-param (Closure(mixed): mixed)|null $store
	 * @phpstan-param (Closure(): array<string, mixed>)|null $schema
	 * @phpstan-param (Closure(): array<string, mixed>)|null $text
	 */
	public function __construct(
		public readonly string $key,
		public readonly mixed $fallback,
		private readonly Closure $valid,
		private readonly Closure $field,
		private readonly ?Closure $normalise = null,
		public readonly string $section = self::GENERAL,
		private readonly ?Closure $store = null,
		private readonly ?Closure $schema = null,
		private readonly ?Closure $text = null,
	) {
	}

	/**
	 * A choice between a fixed set of values, shown as a select.
	 *
	 * @param string  $key      Key in the settings option.
	 * @param string  $fallback Default choice.
	 * @param Closure $values   Lists the values currently allowed.
	 * @param Closure $field    Builds the field: `title`, `options` (value => label), optionally `desc`.
	 * @phpstan-param Closure(): list<string> $values
	 * @phpstan-param Closure(): array<string, mixed> $field
	 */
	public static function select( string $key, string $fallback, Closure $values, Closure $field ): self {
		return new self(
			$key,
			$fallback,
			static fn ( mixed $value ): bool => is_string( $value ) && in_array( $value, $values(), true ),
			static function () use ( $field, $values ): array {
				$definition = $field();
				$options    = is_array( $definition['options'] ?? null ) ? $definition['options'] : array();
				// Only the values still allowed, e.g. features that are enabled.
				$definition['options'] = array_intersect_key( $options, array_flip( $values() ) );
				return array( 'type' => 'select' ) + $definition;
			},
			null,
			self::GENERAL,
			null,
			static fn (): array => array(
				'type' => 'string',
				'enum' => $values(),
			)
		);
	}

	/**
	 * A whole number within a range, shown as a number field. A value stored
	 * as a numeric string, as the settings form saves it, reads as an int.
	 *
	 * @param string  $key      Key in the settings option.
	 * @param int     $fallback Default value.
	 * @param int     $min      Smallest value allowed.
	 * @param int     $max      Largest value allowed.
	 * @param Closure $field    Builds the field: `title`, optionally `desc`.
	 * @phpstan-param Closure(): array<string, mixed> $field
	 */
	public static function number( string $key, int $fallback, int $min, int $max, Closure $field ): self {
		return new self(
			$key,
			$fallback,
			static function ( mixed $value ) use ( $min, $max ): bool {
				$number = self::whole_number( $value );
				return null !== $number && $number >= $min && $number <= $max;
			},
			static fn (): array => array(
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => $min,
					'max'  => $max,
					'step' => 1,
				),
			) + $field(),
			self::whole_number( ... ),
			self::GENERAL,
			null,
			static fn (): array => array(
				'type'    => 'integer',
				'minimum' => $min,
				'maximum' => $max,
			)
		);
	}

	/**
	 * On or off, shown as a checkbox on the settings tab. WooCommerce's form
	 * saves it as `yes` or `no`; it reads as a bool.
	 *
	 * @param string  $key      Key in the settings option.
	 * @param bool    $fallback Default value.
	 * @param Closure $field    Builds the field: `title`, optionally `desc`.
	 * @phpstan-param Closure(): array<string, mixed> $field
	 */
	public static function checkbox( string $key, bool $fallback, Closure $field ): self {
		return new self(
			$key,
			$fallback,
			static fn ( mixed $value ): bool => is_bool( $value ) || in_array( $value, array( self::CHECKED, self::UNCHECKED ), true ),
			static fn (): array => array( 'type' => 'checkbox' ) + $field(),
			static fn ( mixed $value ): bool => true === $value || self::CHECKED === $value,
			self::GENERAL,
			// WooCommerce's form ticks the box only for `yes`.
			static fn ( mixed $value ): string => true === $value ? self::CHECKED : self::UNCHECKED,
			static fn (): array => array( 'type' => 'boolean' )
		);
	}

	/**
	 * A colour, `#rrggbb` in lower case, or empty for none, shown as a
	 * colour picker.
	 *
	 * @param string  $key   Key in the settings option.
	 * @param Closure $field Builds the field: `title`, optionally `desc`.
	 * @phpstan-param Closure(): array<string, mixed> $field
	 */
	public static function colour( string $key, Closure $field ): self {
		return new self(
			$key,
			'',
			static fn ( mixed $value ): bool => is_string( $value ) && ( '' === trim( $value ) || 1 === preg_match( self::COLOUR_PATTERN, trim( $value ) ) ),
			static fn (): array => array( 'type' => 'color' ) + $field(),
			static fn ( mixed $value ): string => strtolower( trim( (string) $value ) ),
			self::GENERAL,
			null,
			static fn (): array => array(
				'type'    => 'string',
				// Empty for none.
				'pattern' => '^(#[0-9a-fA-F]{6})?$',
			)
		);
	}

	/**
	 * An http or https address, without a trailing slash, or empty for
	 * none. A developer tools setting.
	 *
	 * @param string  $key   Key in the settings option.
	 * @param Closure $field Builds the field: `title`, optionally `desc`.
	 * @phpstan-param Closure(): array<string, mixed> $field
	 */
	public static function url( string $key, Closure $field ): self {
		return new self(
			$key,
			'',
			static fn ( mixed $value ): bool => is_string( $value ) && ( '' === trim( $value ) || self::is_web_address( trim( $value ) ) ),
			static fn (): array => array( 'type' => 'url' ) + $field(),
			static fn ( mixed $value ): string => untrailingslashit( trim( (string) $value ) ),
			self::DEVELOPER
		);
	}

	/**
	 * Any number of values, none by default, shown as a multiple select.
	 * Each value is kept as long as it is one `$value` takes, and only once.
	 *
	 * @param string  $key     Key in the settings option.
	 * @param Closure $value   Whether a value is one the setting takes.
	 * @param Closure $field   Builds the field: `title`, optionally `desc`.
	 * @param Closure $options Lists the field's options (value => label), only when the field is shown.
	 * @phpstan-param Closure(string): bool $value
	 * @phpstan-param Closure(): array<string, mixed> $field
	 * @phpstan-param Closure(): array<string, string> $options
	 */
	public static function multiselect( string $key, Closure $value, Closure $field, Closure $options ): self {
		return new self(
			$key,
			array(),
			static fn ( mixed $values ): bool => is_array( $values ),
			static fn (): array => array(
				'type'    => 'multiselect',
				'class'   => 'wc-enhanced-select',
				'options' => $options(),
			) + $field(),
			static fn ( mixed $values ): array => array_values( array_unique( array_filter( (array) $values, static fn ( mixed $one ): bool => is_string( $one ) && $value( $one ) ) ) ),
			self::GENERAL,
			null,
			static fn (): array => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'string' ),
				'uniqueItems' => true,
			),
			$field
		);
	}

	/**
	 * On or off, off by default. A developer tools setting.
	 *
	 * @param string  $key   Key in the settings option.
	 * @param Closure $field Builds the field: `title`, optionally `desc`.
	 * @phpstan-param Closure(): array<string, mixed> $field
	 */
	public static function toggle( string $key, Closure $field ): self {
		return new self(
			$key,
			false,
			static fn ( mixed $value ): bool => is_bool( $value ),
			static fn (): array => array( 'type' => 'checkbox' ) + $field(),
			null,
			self::DEVELOPER
		);
	}

	/**
	 * @param string $value A trimmed value.
	 */
	private static function is_web_address( string $value ): bool {
		$scheme = wp_parse_url( $value, PHP_URL_SCHEME );
		$host   = wp_parse_url( $value, PHP_URL_HOST );
		return in_array( $scheme, array( 'http', 'https' ), true ) && is_string( $host ) && '' !== $host;
	}

	/**
	 * @param mixed $value A stored value, or null when none is stored.
	 * @return mixed The value, or the fallback when it is missing or invalid.
	 */
	public function resolve( mixed $value ): mixed {
		if ( null === $value || ! ( $this->valid )( $value ) ) {
			return $this->fallback;
		}
		return null === $this->normalise ? $value : ( $this->normalise )( $value );
	}

	/**
	 * @param mixed $value A value as `resolve()` reads it.
	 * @return mixed The value as the settings option stores it.
	 */
	public function stored( mixed $value ): mixed {
		return null === $this->store ? $value : ( $this->store )( $value );
	}

	/**
	 * @param mixed $value An int, or a string of digits.
	 * @return int|null Null when it is neither.
	 */
	private static function whole_number( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			return $value;
		}
		return is_string( $value ) && 1 === preg_match( '/^-?\d+$/', trim( $value ) ) ? (int) $value : null;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function field(): array {
		return ( $this->field )();
	}

	/**
	 * Whether a value is one the setting takes as it is, rather than one
	 * `resolve()` would read as the default.
	 *
	 * @param mixed $value A value as `resolve()` reads it.
	 */
	public function accepts( mixed $value ): bool {
		return null !== $value && ( $this->valid )( $value );
	}

	/**
	 * The JSON schema of the setting's value, as an ability reads and
	 * writes it, described by the field's title and description.
	 *
	 * @return array<string, mixed>
	 */
	public function schema(): array {
		$text  = ( $this->text ?? $this->field )();
		$parts = array_filter( array( $text['title'] ?? '', $text['desc'] ?? '' ), static fn ( mixed $part ): bool => is_string( $part ) && '' !== $part );
		return ( null === $this->schema ? array( 'type' => 'string' ) : ( $this->schema )() ) + array(
			'description' => wp_strip_all_tags( implode( ': ', $parts ) ),
			'default'     => $this->fallback,
		);
	}
}
