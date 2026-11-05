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
	 * The template's groups, in its order.
	 *
	 * @param mixed $template The template as Pixonaut answered it.
	 * @return list<array{id: string, label: string, fields: list<array{field: string, label: string, help: string, type: string, options: list<string>}>}>
	 */
	public static function groups( mixed $template ): array {
		$schema = is_array( $template ) && is_array( $template['formSchema'] ?? null ) ? $template['formSchema'] : array();
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
