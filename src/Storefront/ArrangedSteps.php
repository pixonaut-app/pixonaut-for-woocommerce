<?php
/**
 * A template's steps as a side's `formGroups` lays them out.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use Pixonaut\WooCommerce\Catalogue\FormGroups;

/**
 * The steps the designer's form shows for a side the store manager arranged,
 * worked out as the SDK's `arrangeSteps()` and `arrangedNodes()` do: the
 * arrangement's groups first, in its order, each with its items, then every
 * group it does not name, each with its fields placed nowhere else. A layer's
 * size slider follows its last field, else ends the step; the hint to drag the
 * step's layers ends it. A step left with nothing to show is passed over.
 *
 * @phpstan-import-type Control from FormControl
 */
final class ArrangedSteps {

	/** The add-to-cart form names an attribute's field `attribute_<slug>`. */
	private const ATTRIBUTE_FIELD_PREFIX = 'attribute_';

	/**
	 * The arrangement's steps.
	 *
	 * @param list<array<string, mixed>> $steps   The template's `formSchema` steps.
	 * @param list<array<string, mixed>> $groups  The arrangement's groups (`FormGroupsMount`).
	 * @param bool                       $lending Whether the add-to-cart form lends the designer its attribute fields: without it, an attribute item is left out, as is a group of the product's own that it leaves empty.
	 * @param callable                   $control The control of a `formSchema` field.
	 * @phpstan-param callable(array<mixed>): array<string, mixed> $control
	 * @return list<array{label: string, fields: list<Control>}>
	 */
	public static function of( array $steps, array $groups, bool $lending, callable $control ): array {
		$by_field = array();
		$by_id    = array();
		foreach ( $steps as $step ) {
			if ( is_string( $step['groupId'] ?? null ) ) {
				$by_id[ $step['groupId'] ] = $step;
			}
			foreach ( self::rows( $step['fields'] ?? null ) as $field ) {
				$by_field[ self::name( $field ) ] = $field;
			}
		}
		$placed   = array();
		$arranged = array();
		foreach ( $groups as $group ) {
			$id = is_string( $group['id'] ?? null ) ? $group['id'] : '';
			if ( '' === $id ) {
				continue;
			}
			$items = array();
			foreach ( self::rows( $group['items'] ?? null ) as $item ) {
				if ( is_string( $item['attribute'] ?? null ) ) {
					if ( $lending ) {
						$items[] = array( 'host' => self::ATTRIBUTE_FIELD_PREFIX . $item['attribute'] );
					}
					continue;
				}
				$name = is_string( $item['field'] ?? null ) ? $item['field'] : '';
				if ( isset( $by_field[ $name ] ) && ! isset( $placed[ $name ] ) ) {
					$placed[ $name ] = true;
					$items[]         = array(
						'field' => $by_field[ $name ],
						'words' => $item,
					);
				}
			}
			if ( array() === $items && FormGroups::is_own( $id ) ) {
				continue;
			}
			$arranged[ $id ] = array(
				'label' => is_string( $group['title'] ?? null ) ? $group['title'] : ( is_string( $by_id[ $id ]['label'] ?? null ) ? $by_id[ $id ]['label'] : $id ),
				'items' => $items,
				'step'  => $by_id[ $id ] ?? array(),
			);
		}
		foreach ( $by_id as $id => $step ) {
			if ( ! isset( $arranged[ $id ] ) ) {
				$arranged[ $id ] = array(
					'label' => is_string( $step['label'] ?? null ) ? $step['label'] : '',
					'items' => array(),
					'step'  => $step,
				);
			}
		}
		$shown = array();
		foreach ( $arranged as $section ) {
			$fields = self::fields( $section, $placed, $control );
			if ( array() !== $fields ) {
				$shown[] = array(
					'label'  => $section['label'],
					'fields' => $fields,
				);
			}
		}
		return $shown;
	}

	/**
	 * A step's controls: its items, the fields of its own placed nowhere,
	 * each layer's size slider, then the hint to drag its layers.
	 *
	 * @param array{label: string, items: list<array<string, mixed>>, step: array<string, mixed>} $section The step.
	 * @param array<string, true>                                                                 $placed  The fields the arrangement places.
	 * @param callable                                                                            $control The control of a `formSchema` field.
	 * @phpstan-param callable(array<mixed>): array<string, mixed> $control
	 * @return list<Control>
	 */
	private static function fields( array $section, array $placed, callable $control ): array {
		$items = $section['items'];
		foreach ( self::rows( $section['step']['fields'] ?? null ) as $field ) {
			if ( ! isset( $placed[ self::name( $field ) ] ) ) {
				$items[] = array(
					'field' => $field,
					'words' => array(),
				);
			}
		}
		$fields  = array();
		$last_of = array();
		foreach ( $items as $item ) {
			if ( isset( $item['host'] ) ) {
				$fields[] = FormControl::host( (string) $item['host'] );
				continue;
			}
			$field    = is_array( $item['field'] ?? null ) ? $item['field'] : array();
			$words    = is_array( $item['words'] ?? null ) ? $item['words'] : array();
			$fields[] = FormControl::with_words( $control( self::worded( $field, $words ) ), $words );

			$last_of[ is_string( $field['layerId'] ?? null ) ? $field['layerId'] : '' ] = count( $fields ) - 1;
		}
		$moves  = array();
		$scales = array();
		foreach ( self::rows( $section['step']['transforms'] ?? null ) as $transform ) {
			$axes  = is_array( $transform['transform'] ?? null ) ? $transform['transform'] : array();
			$scale = is_array( $axes['scale'] ?? null ) ? $axes['scale'] : array();
			if ( true === ( $scale['editable'] ?? null ) ) {
				$layer = is_string( $transform['layerId'] ?? null ) ? $transform['layerId'] : '';
				$scales[ $last_of[ $layer ] ?? PHP_INT_MAX ][] = FormControl::scale( $scale );
			}
			$translate = is_array( $axes['translate'] ?? null ) ? $axes['translate'] : array();
			$rotate    = is_array( $axes['rotate'] ?? null ) ? $axes['rotate'] : array();
			if ( true === ( $translate['editable'] ?? null ) || true === ( $rotate['editable'] ?? null ) ) {
				$moves[] = is_string( $translate['label'] ?? null ) ? $translate['label'] : '';
			}
		}
		$ordered = array();
		foreach ( $fields as $index => $one ) {
			$ordered[] = $one;
			array_push( $ordered, ...( $scales[ $index ] ?? array() ) );
		}
		array_push( $ordered, ...( $scales[ PHP_INT_MAX ] ?? array() ) );
		if ( array() !== $moves ) {
			$ordered[] = FormControl::move( $moves );
		}
		return $ordered;
	}

	/**
	 * `$field` with the heading and help text the arrangement gives it.
	 *
	 * @param array<string, mixed> $field A `formSchema` field.
	 * @param array<string, mixed> $words The arrangement's item for it.
	 * @return array<string, mixed>
	 */
	private static function worded( array $field, array $words ): array {
		if ( is_string( $words['label'] ?? null ) ) {
			$field['label'] = $words['label'];
		}
		if ( is_string( $words['help'] ?? null ) ) {
			$field['description'] = $words['help'];
		}
		return $field;
	}

	/**
	 * @param array<string, mixed> $field A `formSchema` field.
	 */
	private static function name( array $field ): string {
		return ( is_string( $field['layerId'] ?? null ) ? $field['layerId'] : '' ) . ':' . ( is_string( $field['prop'] ?? null ) ? $field['prop'] : '' );
	}

	/**
	 * @param mixed $value Decoded JSON.
	 * @return list<array<string, mixed>>
	 */
	private static function rows( mixed $value ): array {
		return is_array( $value ) ? array_values( array_filter( $value, 'is_array' ) ) : array();
	}
}
