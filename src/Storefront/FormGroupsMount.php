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
