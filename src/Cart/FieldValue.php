<?php
/**
 * One field's value as the customer chose it.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Cart;

use Pixonaut\WooCommerce\Labels;

/**
 * What a side's design shows for one of its form's fields, as the
 * storefront read it from the designer when the design was approved: the
 * field's label and its value as text. A picture keeps no value: it shows
 * as the `field_image` label. Kept on the cart line's side and copied to
 * the order line, for the line's details; the design itself, and its
 * proof, stay what is printed.
 */
final class FieldValue {

	public const TEXT   = 'text';
	public const SELECT = 'select';
	public const NUMBER = 'number';
	public const COLOR  = 'color';
	public const IMAGE  = 'image';

	/**
	 * The field types a line lists, with the label each shows by when its
	 * author gave the field none.
	 */
	public const TYPES = array(
		self::TEXT   => Labels::FIELD_TEXT,
		self::SELECT => Labels::FIELD_CHOICE,
		self::NUMBER => Labels::FIELD_NUMBER,
		self::COLOR  => Labels::FIELD_COLOUR,
		self::IMAGE  => Labels::FIELD_IMAGE,
	);

	/**
	 * At most this many fields are kept per side; the rest are dropped.
	 */
	public const MAX_FIELDS = 50;

	public const MAX_LABEL_LENGTH = 200;

	/**
	 * Long enough for any text a template takes; a longer value is cut.
	 */
	public const MAX_VALUE_LENGTH = 5000;

	/**
	 * Control characters a value may not carry; a line break and a tab stay.
	 */
	private const CONTROL_CHARACTERS = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u';

	/**
	 * @param string $label The field's label as the customer saw it; empty when its author gave it none.
	 * @param string $type  One of TYPES' keys.
	 * @param string $value The value as text; empty for a picture.
	 */
	public function __construct(
		public readonly string $label,
		public readonly string $type,
		public readonly string $value,
	) {
	}

	/**
	 * The fields of a side, from what the storefront posted or a line kept:
	 * entries of to_array()'s shape. An entry that is not one, or of an
	 * unknown type, or with no value, is skipped.
	 *
	 * @param mixed $data A list of entries.
	 *
	 * @return list<self>
	 */
	public static function list_from( mixed $data ): array {
		$fields = array();
		foreach ( is_array( $data ) ? $data : array() as $entry ) {
			$field = self::from_array( $entry );
			if ( null !== $field ) {
				$fields[] = $field;
			}
			if ( count( $fields ) >= self::MAX_FIELDS ) {
				break;
			}
		}
		return $fields;
	}

	/**
	 * @param mixed $data Shape of to_array().
	 */
	public static function from_array( mixed $data ): ?self {
		if ( ! is_array( $data ) || ! is_string( $data['label'] ?? null ) || ! is_string( $data['type'] ?? null ) || ! is_string( $data['value'] ?? null ) ) {
			return null;
		}
		if ( ! isset( self::TYPES[ $data['type'] ] ) ) {
			return null;
		}
		$value = self::IMAGE === $data['type'] ? '' : trim( self::clean( $data['value'], self::MAX_VALUE_LENGTH ) );
		if ( '' === $value && self::IMAGE !== $data['type'] ) {
			return null;
		}
		$label = trim( (string) preg_replace( '/\s+/u', ' ', self::clean( $data['label'], self::MAX_LABEL_LENGTH ) ) );
		return new self( $label, $data['type'], $value );
	}

	/**
	 * The fields of a list as the storefront posted it, a JSON string.
	 *
	 * @param mixed $posted The posted value.
	 *
	 * @return list<self>
	 */
	public static function list_from_json( mixed $posted ): array {
		return is_string( $posted ) ? self::list_from( json_decode( $posted, true ) ) : array();
	}

	/**
	 * @param array $fields The fields.
	 * @phpstan-param list<self> $fields
	 *
	 * @return list<array{label: string, type: string, value: string}>
	 */
	public static function list_to_array( array $fields ): array {
		return array_map( static fn ( self $field ): array => $field->to_array(), $fields );
	}

	/**
	 * @return array{label: string, type: string, value: string}
	 */
	public function to_array(): array {
		return array(
			'label' => $this->label,
			'type'  => $this->type,
			'value' => $this->value,
		);
	}

	/**
	 * The label the field shows by: its own, or its type's.
	 *
	 * @param Labels               $labels  The customer-facing texts.
	 * @param array<string, mixed> $context What the text is shown for.
	 */
	public function shown_label( Labels $labels, array $context ): string {
		return '' !== $this->label ? $this->label : $labels->get( self::TYPES[ $this->type ], $context );
	}

	/**
	 * The value as text: a picture's is the `field_image` label.
	 *
	 * @param Labels               $labels  The customer-facing texts.
	 * @param array<string, mixed> $context What the text is shown for.
	 */
	public function shown_value( Labels $labels, array $context ): string {
		return self::IMAGE === $this->type ? $labels->get( Labels::FIELD_IMAGE, $context ) : $this->value;
	}

	/**
	 * The value as escaped HTML, in full, its line breaks kept.
	 *
	 * @param Labels               $labels  The customer-facing texts.
	 * @param array<string, mixed> $context What the text is shown for.
	 */
	public function shown_html( Labels $labels, array $context ): string {
		return nl2br( esc_html( $this->shown_value( $labels, $context ) ), false );
	}

	/**
	 * The REST argument a route takes a side's fields in. It bounds
	 * nothing: list_from() caps and skips as the add to cart does, so the
	 * values never refuse the correction they come with.
	 *
	 * @return array<string, mixed>
	 */
	public static function rest_arg(): array {
		return array(
			'type'    => 'array',
			'default' => array(),
		);
	}

	/**
	 * Text with valid UTF-8 only, no control characters, and at most
	 * `$length` characters.
	 *
	 * @param string $text   The text.
	 * @param int    $length Its longest.
	 */
	private static function clean( string $text, int $length ): string {
		$text = (string) preg_replace( self::CONTROL_CHARACTERS, '', wp_check_invalid_utf8( $text, true ) );
		return mb_substr( str_replace( "\r\n", "\n", $text ), 0, $length );
	}
}
