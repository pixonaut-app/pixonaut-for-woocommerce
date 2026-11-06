<?php
/**
 * What a designer link is asked for.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use Pixonaut\WooCommerce\Catalogue\PrintSize;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ProductVariant;
use Pixonaut\WooCommerce\Catalogue\Side;

/**
 * Builds the body of `POST /v1/templates/{id}/designer-links` from a
 * product's sides: one side at the top level, or every side under `sides`,
 * each with its key and label, and its own template when it is not the
 * first side's.
 *
 * A side on a blank names the variant the product or variation sells as
 * the only one offered, so the hosted designer cannot switch to a colour
 * the cart would not get, and names its views only when the merchant left
 * out one the variant is photographed in, or the Design view: absent,
 * Pixonaut shows every view and its Design view too. The link's `layout`
 * covers every side's form; absent, each follows its template.
 */
final class DesignerLinkBody {

	/**
	 * The `afterApprove` that words the hosted page's button for adding to the cart.
	 */
	private const AFTER_APPROVE_ADD_TO_CART = 'add_to_cart';

	/**
	 * @param string               $template_base_id The template the link is made for: the first side's.
	 * @param array<string, mixed> $body             The request body.
	 */
	private function __construct(
		public readonly string $template_base_id,
		public readonly array $body,
	) {
	}

	/**
	 * The link for a product's sides, or null when a side cannot be sent.
	 *
	 * @param ProductConfig       $config     The product's configuration.
	 * @param string              $return_url Where the hosted designer sends the customer back to.
	 * @param string|null         $language   The hosted designer's language, one of `DesignerLanguage::languages()`; null for the workspace's default.
	 * @param ProductVariant|null $variant    The variant the product or variation sells, on a blank.
	 * @param bool                $frame      Whether the hosted designer frames the print area; see the `print_area_frame` setting.
	 * @param string|null         $layout     The form's layout, `page` or `steps`; null follows each side's template. See `FormLayout`.
	 * @param bool                $add_to_cart Whether the store adds the design to the cart on return, which the hosted page's button then says.
	 */
	public static function for_config( ProductConfig $config, string $return_url, ?string $language, ?ProductVariant $variant = null, bool $frame = false, ?string $layout = null, bool $add_to_cart = false ): ?self {
		$sides = $config->sides;
		if ( ! $config->valid || array() === $sides || ( count( $sides ) > 1 && null === $config->blank_id ) ) {
			return null;
		}
		$first = $sides[0];
		$parts = array();
		foreach ( $sides as $side ) {
			$part = self::side( $side, $config, $variant );
			if ( null === $part ) {
				return null;
			}
			$parts[] = $part;
		}

		if ( 1 === count( $sides ) ) {
			$body = $parts[0];
		} else {
			$body = array( 'sides' => array() );
			foreach ( $sides as $index => $side ) {
				$part = array(
					'key'   => $side->key,
					'label' => $side->label,
				) + $parts[ $index ];
				if ( $side->template_base_id !== $first->template_base_id ) {
					$part['templateId'] = $side->template_base_id;
				}
				$body['sides'][] = $part;
			}
		}
		$body['returnUrl'] = $return_url;
		if ( null !== $language ) {
			// Absent, the page uses the workspace's default language.
			$body['locale'] = $language;
		}
		if ( $frame ) {
			// Sent only when on, as Pixonaut's default is off: a link kept from before still matches.
			$body['printAreaFrame'] = true;
		}
		if ( null !== $layout ) {
			// Absent, as for a link kept from before, each side's form follows its template.
			$body['layout'] = $layout;
		}
		if ( $add_to_cart ) {
			// Sent only when on, as Pixonaut's default is the return: a link kept from before still matches.
			$body['afterApprove'] = self::AFTER_APPROVE_ADD_TO_CART;
		}
		return new self( $first->template_base_id, $body );
	}

	/**
	 * What one side sends: its blank and placement, the views and variant it
	 * is shown in and the view it starts on, and its size menu with the size
	 * it starts on chosen; null for a
	 * side whose template is unknown or whose placement does not match the
	 * blank.
	 *
	 * @param Side                $side    The side.
	 * @param ProductConfig       $config  The product's configuration.
	 * @param ProductVariant|null $variant The variant it sells, on a blank.
	 * @return array<string, mixed>|null
	 */
	private static function side( Side $side, ProductConfig $config, ?ProductVariant $variant ): ?array {
		$blank_id = $config->blank_id;
		if ( '' === $side->template_base_id || null === $side->template_version || ( null === $blank_id ) !== ( null === $side->placement_id ) ) {
			return null;
		}
		$part = array();
		if ( null !== $blank_id && null !== $side->placement_id ) {
			$part['blankId']     = $blank_id;
			$part['placementId'] = $side->placement_id;
			$views               = self::views( $side->placement_id, $config, $variant );
			if ( array() !== $views ) {
				$part['views'] = $views;
			}
			if ( null !== $config->initial_view ) {
				$part['initialView'] = $config->initial_view;
			}
			if ( null !== $variant?->ref ) {
				$part['variants']       = array( $variant->ref );
				$part['initialVariant'] = $variant->ref;
			}
		}
		if ( array() !== $side->print_sizes ) {
			$part['printSizes']             = array_map( static fn ( PrintSize $size ): array => $size->to_array(), $side->print_sizes );
			$part['selectedPrintSizeIndex'] = $side->selected_size_index();
		}
		return $part;
	}

	/**
	 * The views ticked on the product that show a placement, and the Design
	 * view when it is ticked, or none when every view the variant is
	 * photographed in there and the Design view are ticked: the merchant
	 * narrowed nothing, so Pixonaut shows all of them. A ticked view the
	 * variant has no photo in is not shown either way.
	 *
	 * @param string              $placement_id The side's placement.
	 * @param ProductConfig       $config       The product's configuration.
	 * @param ProductVariant|null $variant      The variant it sells.
	 * @return list<string>
	 */
	private static function views( string $placement_id, ProductConfig $config, ?ProductVariant $variant ): array {
		if ( array() === $config->views ) {
			return array();
		}
		$photographed = array();
		foreach ( null === $variant ? array() : $variant->views as $view ) {
			if ( $view['placement'] === $placement_id ) {
				$photographed[] = $view['id'];
			}
		}
		if ( array() === $photographed ) {
			// Nothing tells which views are this placement's: send what the merchant ticked.
			return $config->views;
		}
		$design = in_array( ProductConfig::DESIGN_VIEW, $config->views, true );
		if ( $design && array() === array_diff( $photographed, $config->views ) ) {
			return array();
		}
		$shown = array_values( array_intersect( $config->views, $photographed ) );
		if ( $design ) {
			$shown[] = ProductConfig::DESIGN_VIEW;
		}
		return array() === $shown ? $config->views : $shown;
	}
}
