<?php
/**
 * A side's arrangement of the designer form, as the product page mounts it.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use Pixonaut\WooCommerce\Catalogue\FormGroups;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Catalogue\TemplateForm;
use Pixonaut\WooCommerce\Catalogue\VariationAttributes;
use Pixonaut\WooCommerce\OptionGroups;
use WC_Product;

/**
 * The `formGroups` mount option of a side the store manager arranged: the
 * arrangement with each placed attribute as `{attribute: <slug>}`, which the
 * storefront script swaps for the add-to-cart form's field of that
 * attribute, and the product's other attributes used for variations in a
 * group of their own before it, where the designer showed them before. A side
 * nobody arranged has, when the product's options are to stand in groups of
 * their own (`OptionGroups`), one group per attribute in the product's order.
 */
final class FormGroupsMount {

	/**
	 * The group of the attributes the arrangement does not place.
	 */
	public const OPTIONS_GROUP_ID = FormGroups::OWN_GROUP_PREFIX . 'options';

	/**
	 * Put before an attribute's slug for the id of the group holding that
	 * attribute alone.
	 */
	public const OPTION_GROUP_PREFIX = FormGroups::OWN_GROUP_PREFIX . 'option-';

	/**
	 * Stands for the template's groups until `fitted()` knows them. Untitled,
	 * so the designer passes over it should it ever get there.
	 */
	private const TEMPLATE_GROUPS_ID = FormGroups::OWN_GROUP_PREFIX . 'template-groups';

	/**
	 * The option; null for a side that shows the template's own form.
	 *
	 * @param Side       $side    The side.
	 * @param WC_Product $product The product, or the variation the side is mounted for.
	 * @param string     $options Where the product's options stand on a side nobody arranged, an `OptionGroups` choice.
	 * @return array{groups: list<array<string, mixed>>}|null
	 */
	public static function option( Side $side, WC_Product $product, string $options = OptionGroups::ON_TOP ): ?array {
		if ( null === $side->form_groups ) {
			return self::option_groups( $product, $options );
		}
		$attributes = array_column( VariationAttributes::of( $product ), 'slug' );
		$groups     = array();
		foreach ( $side->form_groups->to_array()['groups'] as $group ) {
			// An attribute the product no longer varies by is left out.
			$group['items'] = array_values( array_filter( $group['items'], static fn ( array $item ): bool => ! isset( $item['attribute'] ) || in_array( $item['attribute'], $attributes, true ) ) );
			if ( array() !== $group['items'] || ! FormGroups::is_own( $group['id'] ) ) {
				$groups[] = $group;
			}
		}
		$unplaced = array_values( array_diff( $attributes, $side->form_groups->attributes() ) );
		return array( 'groups' => self::with_options( $groups, $unplaced ) );
	}

	/**
	 * The option fitted to the template's groups as the page read them. The
	 * designer passes over an untitled group the template lacks, its host
	 * items too, so such a group is left out here and its attributes join
	 * the Options group; without a read, the side shows the template's own
	 * form, the product's options first. The options' own groups standing
	 * first name nothing of the template's, so they need no read.
	 *
	 * @param mixed         $option          The `formGroups` option, from `option()`.
	 * @param string[]|null $template_groups The ids of the template's groups; null when the page could not read it.
	 * @phpstan-param list<string>|null $template_groups
	 * @return array{groups: list<array<string, mixed>>}|null
	 */
	public static function fitted( mixed $option, ?array $template_groups ): ?array {
		if ( ! is_array( $option ) || ! is_array( $option['groups'] ?? null ) ) {
			return null;
		}
		if ( null === $template_groups && ! self::names_only_options( $option['groups'] ) ) {
			return null;
		}
		$template_groups ??= array();
		$groups            = array();
		$orphaned          = array();
		$options           = array();
		foreach ( $option['groups'] as $group ) {
			if ( ! is_array( $group ) || ! is_string( $group['id'] ?? null ) ) {
				continue;
			}
			if ( self::TEMPLATE_GROUPS_ID === $group['id'] ) {
				foreach ( $template_groups as $template_group ) {
					$groups[] = array(
						'id'    => $template_group,
						'items' => array(),
					);
				}
				continue;
			}
			if ( self::OPTIONS_GROUP_ID === $group['id'] ) {
				$options = array_column( is_array( $group['items'] ?? null ) ? $group['items'] : array(), 'attribute' );
				continue;
			}
			if ( FormGroups::is_own( $group['id'] ) || isset( $group['title'] ) || in_array( $group['id'], $template_groups, true ) ) {
				$groups[] = $group;
				continue;
			}
			$orphaned = array_merge( $orphaned, array_column( is_array( $group['items'] ?? null ) ? $group['items'] : array(), 'attribute' ) );
		}
		return array( 'groups' => self::with_options( $groups, array_values( array_filter( array_merge( $options, $orphaned ), 'is_string' ) ) ) );
	}

	/**
	 * The option, made for the product's template, carried to a variation
	 * on another template, to be `fitted()` to that template's groups next.
	 * The product's groups keep their order and their names: a group of the
	 * product's template the arrangement left untitled takes that template's
	 * label for it. Attributes stay where they are; a field moves to the
	 * other template's field of the same field key, else stays when the
	 * other template has it too (as in an arrangement made for that
	 * template), and is left out otherwise; a group left holding nothing
	 * that the other template lacks is left out too. The other template's fields placed nowhere follow the
	 * product's groups in its own groups, as the designer shows a field the
	 * option does not place.
	 *
	 * @param mixed $option The `formGroups` option, from `option()`.
	 * @param mixed $from   The product's template as Pixonaut answered it; null when the page could not read it.
	 * @param mixed $to     The variation's template as Pixonaut answered it.
	 * @return array{groups: list<array<string, mixed>>}|null
	 */
	public static function carried( mixed $option, mixed $from, mixed $to ): ?array {
		if ( ! is_array( $option ) || ! is_array( $option['groups'] ?? null ) ) {
			return null;
		}
		$labels    = array_column( TemplateForm::groups( $from ), 'label', 'id' );
		$to_groups = TemplateForm::groups( $to );
		$to_ids    = array_column( $to_groups, 'id' );
		$offered   = array_column( array_merge( array(), ...array_column( $to_groups, 'fields' ) ), 'field' );
		$to_keys   = TemplateForm::field_keys( $to );
		$moved     = array();
		foreach ( TemplateForm::field_keys( $from ) as $key => $field ) {
			if ( isset( $to_keys[ $key ] ) && in_array( $to_keys[ $key ], $offered, true ) ) {
				$moved[ $field ] = $to_keys[ $key ];
			}
		}
		$groups = array();
		foreach ( $option['groups'] as $group ) {
			if ( ! is_array( $group ) || ! is_string( $group['id'] ?? null ) ) {
				continue;
			}
			$items = array();
			foreach ( is_array( $group['items'] ?? null ) ? $group['items'] : array() as $item ) {
				if ( ! is_array( $item ) || ! is_string( $item['field'] ?? null ) ) {
					$items[] = $item;
				} elseif ( isset( $moved[ $item['field'] ] ) ) {
					$items[] = array_merge( $item, array( 'field' => $moved[ $item['field'] ] ) );
				} elseif ( in_array( $item['field'], $offered, true ) ) {
					$items[] = $item;
				}
			}
			$label = $labels[ $group['id'] ] ?? '';
			if ( ! FormGroups::is_own( $group['id'] ) && ! isset( $group['title'] ) && '' !== $label ) {
				$group['title'] = $label;
			}
			if ( array() === $items && self::TEMPLATE_GROUPS_ID !== $group['id'] && ! in_array( $group['id'], $to_ids, true ) ) {
				continue;
			}
			$groups[] = array_merge( $group, array( 'items' => $items ) );
		}
		return array( 'groups' => $groups );
	}

	/**
	 * The form the designer shows on the template under the option: each
	 * group it shows, in order, with its heading and its items. A group the
	 * template lacks shows only when it has a title and an item, and a
	 * field the template lacks is passed over, as the designer passes them
	 * over; the template's groups the option does not name follow in its own
	 * order either way, so they are left out. Two options with the same form
	 * on a template show the same there, so the designer mounted with one
	 * can switch to the template without mounting again. Null for none.
	 *
	 * @param mixed $option   The `formGroups` option, fitted to its template.
	 * @param mixed $template The template as Pixonaut answered it.
	 * @return list<array{0: string, 1: string, 2: list<mixed>}>|null
	 */
	public static function shown_on( mixed $option, mixed $template ): ?array {
		if ( ! is_array( $option ) || ! is_array( $option['groups'] ?? null ) ) {
			return null;
		}
		$labels  = array_column( TemplateForm::groups( $template ), 'label', 'id' );
		$offered = array_column( array_merge( array(), ...array_column( TemplateForm::groups( $template ), 'fields' ) ), 'field' );
		$placed  = array();
		$shown   = array();
		foreach ( $option['groups'] as $group ) {
			if ( ! is_array( $group ) || ! is_string( $group['id'] ?? null ) ) {
				continue;
			}
			$title = is_string( $group['title'] ?? null ) ? $group['title'] : null;
			$own   = isset( $labels[ $group['id'] ] );
			if ( null === $title && ! $own ) {
				continue;
			}
			$items = array();
			foreach ( is_array( $group['items'] ?? null ) ? $group['items'] : array() as $item ) {
				$field = is_array( $item ) ? ( $item['field'] ?? null ) : null;
				if ( ! is_string( $field ) ) {
					$items[] = $item;
				} elseif ( in_array( $field, $offered, true ) && ! in_array( $field, $placed, true ) ) {
					$placed[] = $field;
					$items[]  = $item;
				}
			}
			if ( $own || array() !== $items ) {
				$shown[] = array( $group['id'], $title ?? $labels[ $group['id'] ], $items );
			}
		}
		return $shown;
	}

	/**
	 * Whether the option holds the stand-in for the template's groups, so
	 * fitting it needs them read.
	 *
	 * @param mixed $option The `formGroups` option, from `option()`.
	 */
	public static function awaits_template_groups( mixed $option ): bool {
		$groups = is_array( $option ) && is_array( $option['groups'] ?? null ) ? $option['groups'] : array();
		return in_array( self::TEMPLATE_GROUPS_ID, array_column( $groups, 'id' ), true );
	}

	/**
	 * Whether fitting the option to a template needs that template read:
	 * false for one whose every group is one attribute's own, which fits
	 * any template as it is.
	 *
	 * @param mixed $option The `formGroups` option, from `option()`.
	 */
	public static function needs_template( mixed $option ): bool {
		return is_array( $option ) && is_array( $option['groups'] ?? null ) && ! self::names_only_options( $option['groups'] );
	}

	/**
	 * Whether every group is one attribute's own.
	 *
	 * @param array<mixed> $groups The option's groups.
	 */
	private static function names_only_options( array $groups ): bool {
		foreach ( $groups as $group ) {
			if ( ! is_array( $group ) || ! is_string( $group['id'] ?? null ) || ! str_starts_with( $group['id'], self::OPTION_GROUP_PREFIX ) ) {
				return false;
			}
		}
		return array() !== $groups;
	}

	/**
	 * A group per attribute the product varies by, named after it, in the
	 * product's order, before or after the template's groups. Null when the
	 * options stay on top, or the product has none.
	 *
	 * @param WC_Product $product The product, or the variation the side is mounted for.
	 * @param string     $options An `OptionGroups` choice.
	 * @return array{groups: list<array<string, mixed>>}|null
	 */
	private static function option_groups( WC_Product $product, string $options ): ?array {
		$attributes = OptionGroups::ON_TOP === $options ? array() : VariationAttributes::of( $product );
		if ( array() === $attributes ) {
			return null;
		}
		$groups = array_map(
			static fn ( array $attribute ): array => array(
				'id'    => self::OPTION_GROUP_PREFIX . $attribute['slug'],
				'title' => $attribute['label'],
				'items' => array( array( 'attribute' => $attribute['slug'] ) ),
			),
			$attributes
		);
		if ( OptionGroups::LAST === $options ) {
			array_unshift(
				$groups,
				array(
					'id'    => self::TEMPLATE_GROUPS_ID,
					'items' => array(),
				)
			);
		}
		return array( 'groups' => $groups );
	}

	/**
	 * `$groups` with the Options group of `$slugs` before them, when there
	 * is any.
	 *
	 * @param array<array<string, mixed>> $groups The groups.
	 * @param string[]                    $slugs  Attribute slugs.
	 * @phpstan-param list<array<string, mixed>> $groups
	 * @phpstan-param list<string> $slugs
	 * @return list<array<string, mixed>>
	 */
	private static function with_options( array $groups, array $slugs ): array {
		if ( array() === $slugs ) {
			return $groups;
		}
		return array_merge(
			array(
				array(
					'id'    => self::OPTIONS_GROUP_ID,
					/* translators: Heading of the designer's group holding the product's options, such as Size or Colour, on the product page. */
					'title' => _x( 'Options', 'designer group of the product options', 'pixonaut-for-woocommerce' ),
					'items' => array_map( static fn ( string $slug ): array => array( 'attribute' => $slug ), $slugs ),
				),
			),
			$groups
		);
	}
}
