<?php
/**
 * A template's customer form, as the product screen arranges it.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

/**
 * Reads the groups and fields of a template's `formSchema` (`GET
 * /v1/templates/{id}`) that the product screen's Groups setting lists: each
 * step's group, its fields named `"<layerId>:<prop>"` as the designer's
 * `formGroups` names them, with the template's words and offered values.
 */
final class TemplateForm {

	/**
	 * Most offered values listed for one field.
	 */
	private const MAX_OPTIONS = FormGroups::MAX_OPTIONS;

	/**
	 * The `layerId` the form gives the artwork's own fields: the
	 * background's and the die's, which belong to no layer.
	 */
	private const ARTWORK_LAYER_ID = '$artwork';

	/**
	 * The artwork's prop the die's offered shapes are a field of.
	 */
	private const SHAPE_PROP = 'shape';

	/**
	 * A layer's prop its opacity is a field of.
	 */
	private const OPACITY_PROP = 'opacity';

	/**
	 * The prop of a field's spec its author's field key is stored under.
	 */
	private const FIELD_KEY_PROP = 'fieldKey';

	/**
	 * A text layer's prop its words are a field of.
	 */
	private const TEXT_CONTENT_PROP = 'content';

	/**
	 * The template's groups, in its order.
	 *
	 * @param mixed $template The template as Pixonaut answered it.
	 * @return list<array{id: string, label: string, fields: list<array{field: string, label: string, help: string, type: string, options: list<string>}>}>
	 */
	public static function groups( mixed $template ): array {
		$template = is_array( $template ) ? self::with_layer_names( $template ) : array();
		$schema   = is_array( $template['formSchema'] ?? null ) ? $template['formSchema'] : array();
		$groups = array();
		foreach ( is_array( $schema['steps'] ?? null ) ? $schema['steps'] : array() as $step ) {
			if ( ! is_array( $step ) || ! is_string( $step['groupId'] ?? null ) || '' === $step['groupId'] ) {
				continue;
			}
			$fields = array();
			foreach ( is_array( $step['fields'] ?? null ) ? $step['fields'] : array() as $field ) {
				if ( is_array( $field ) && is_string( $field['layerId'] ?? null ) && is_string( $field['prop'] ?? null ) ) {
					$fields[] = self::field( $field );
				}
			}
			$groups[] = array(
				'id'     => $step['groupId'],
				'label'  => self::text( $step['label'] ?? null ),
				'fields' => $fields,
			);
		}
		return $groups;
	}

	/**
	 * The template with each unlabelled text field of its `formSchema`
	 * headed with its layer's name, as Pixonaut's designer heads it: a
	 * merchant names a layer "Name" or "Weight" for what it holds. Only a
	 * text layer's words take it, and only when the author left the label
	 * empty or Pixonaut named the field by its prop, as its reads did before
	 * they gave the name themselves; a layer without a name keeps the label.
	 *
	 * @param array<mixed> $template The template as Pixonaut answered it.
	 * @return array<mixed>
	 */
	public static function with_layer_names( array $template ): array {
		$names = array();
		foreach ( is_array( $template['template']['layers'] ?? null ) ? $template['template']['layers'] : array() as $layer ) {
			if ( is_array( $layer ) && is_string( $layer['id'] ?? null ) && is_string( $layer['name'] ?? null ) && '' !== trim( $layer['name'] ) ) {
				$names[ $layer['id'] ] = $layer['name'];
			}
		}
		if ( array() === $names || ! is_array( $template['formSchema']['steps'] ?? null ) ) {
			return $template;
		}
		foreach ( $template['formSchema']['steps'] as $step_index => $step ) {
			foreach ( is_array( $step ) && is_array( $step['fields'] ?? null ) ? $step['fields'] : array() as $field_index => $field ) {
				$label = is_array( $field ) ? ( $field['label'] ?? null ) : null;
				$name  = is_array( $field ) && is_string( $field['layerId'] ?? null ) ? ( $names[ $field['layerId'] ] ?? null ) : null;
				if ( null !== $name && self::TEXT_CONTENT_PROP === ( $field['prop'] ?? null ) && ( ! is_string( $label ) || '' === trim( $label ) || self::TEXT_CONTENT_PROP === $label ) ) {
					$template['formSchema']['steps'][ $step_index ]['fields'][ $field_index ]['label'] = $name;
				}
			}
		}
		return $template;
	}

	/**
	 * The fields the template's author gave a field key, by that key: the
	 * key a customer's value follows into another template with a field of
	 * the same key. Read from the template's document as Pixonaut's
	 * `fieldKeySites` reads it: the background's props and the die's under
	 * the artwork's reserved layer id, then each layer's props and opacity;
	 * only a field a customer is offered, and of two sharing a key, the first.
	 *
	 * @param mixed $template The template as Pixonaut answered it.
	 * @return array<string, string> `"<layerId>:<prop>"` by field key.
	 */
	public static function field_keys( mixed $template ): array {
		$doc   = is_array( $template ) && is_array( $template['template'] ?? null ) ? $template['template'] : array();
		$sites = array();
		foreach ( is_array( $doc['background'] ?? null ) ? $doc['background'] : array() as $prop => $spec ) {
			$sites[] = array( self::ARTWORK_LAYER_ID . ':' . $prop, $spec );
		}
		$sites[] = array( self::ARTWORK_LAYER_ID . ':' . self::SHAPE_PROP, $doc['shape'] ?? null );
		foreach ( is_array( $doc['layers'] ?? null ) ? $doc['layers'] : array() as $layer ) {
			if ( ! is_array( $layer ) || ! is_string( $layer['id'] ?? null ) ) {
				continue;
			}
			foreach ( is_array( $layer['props'] ?? null ) ? $layer['props'] : array() as $prop => $spec ) {
				$sites[] = array( $layer['id'] . ':' . $prop, $spec );
			}
			$sites[] = array( $layer['id'] . ':' . self::OPACITY_PROP, $layer['opacity'] ?? null );
		}
		$keys = array();
		foreach ( $sites as list( $field, $spec ) ) {
			$key = is_array( $spec ) ? ( $spec[ self::FIELD_KEY_PROP ] ?? null ) : null;
			if ( is_array( $spec ) && true === ( $spec['editable'] ?? null ) && is_string( $key ) && '' !== $key && ! isset( $keys[ $key ] ) ) {
				$keys[ $key ] = $field;
			}
		}
		return $keys;
	}

	/**
	 * @param array<mixed> $field A `formSchema` field.
	 * @return array{field: string, label: string, help: string, type: string, options: list<string>}
	 */
	private static function field( array $field ): array {
		$constraints = is_array( $field['constraints'] ?? null ) ? $field['constraints'] : array();
		$options     = array();
		foreach ( is_array( $constraints['oneOf'] ?? null ) ? $constraints['oneOf'] : array() as $value ) {
			if ( ( is_string( $value ) || is_int( $value ) || is_float( $value ) ) && count( $options ) < self::MAX_OPTIONS ) {
				$options[] = (string) $value;
			}
		}
		return array(
			'field'   => $field['layerId'] . ':' . $field['prop'],
			'label'   => self::text( $field['label'] ?? null ),
			'help'    => self::text( $field['description'] ?? null ),
			'type'    => self::text( $field['type'] ?? null ),
			'options' => $options,
		);
	}

	private static function text( mixed $value ): string {
		return is_string( $value ) ? $value : '';
	}
}
