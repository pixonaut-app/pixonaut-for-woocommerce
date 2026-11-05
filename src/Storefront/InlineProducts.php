<?php
/**
 * Which products personalise inline, and how they mount.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use Pixonaut\WooCommerce\Api\Hosts;
use Pixonaut\WooCommerce\Approval\OrderEdit;
use Pixonaut\WooCommerce\ApprovalMode;
use Pixonaut\WooCommerce\Cart\CartEdit;
use Pixonaut\WooCommerce\Cart\CommittedSide;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Catalogue\ProductVariant;
use Pixonaut\WooCommerce\Connection\Connection;
use Pixonaut\WooCommerce\DesignerLanguage;
use Pixonaut\WooCommerce\FormLayout;
use Pixonaut\WooCommerce\Layout;
use Pixonaut\WooCommerce\OptionGroups;
use Pixonaut\WooCommerce\Settings\Settings;
use WC_Product;
use WC_Product_Variation;

/**
 * Resolves a product to the inline designer its page shows, or to none when
 * the product is not personalised or uses another layout.
 */
final class InlineProducts {

	/**
	 * @param Settings       $settings   The default layout, approval mode, form layout and the designer's language.
	 * @param Connection     $connection The keys the SDK mounts with.
	 * @param CartEdit|null  $edit       Opens a cart line's design when the page edits one; null never does.
	 * @param OrderEdit|null $order_edit Opens an order line's design after the customer asked for changes; null never does.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly Connection $connection,
		private readonly ?CartEdit $edit = null,
		private readonly ?OrderEdit $order_edit = null,
	) {
	}

	/**
	 * The inline designer for a product page, or null when the page shows
	 * none. On a page opened to edit one of the product's cart lines, it
	 * opens that line's designs; on one opened from a proof the customer
	 * asked to change, that order line's design, or says why it cannot.
	 *
	 * @param WC_Product $product The product the page shows.
	 */
	public function for_product( WC_Product $product ): ?InlineDesigner {
		$config   = ProductConfig::config_for( $product )->for_designer();
		$variants = $product->is_type( 'variable' ) ? array_map( static fn ( ProductConfig $variant ): ProductConfig => $variant->for_designer(), ProductConfig::variation_configs( $product ) ) : array();
		// A variable product shows the designer while it or one of its variations personalises inline.
		$page = $config->personalise ? $config : $this->first_inline( $variants );
		if ( null === $page || Layout::INLINE !== Layout::resolve( $page, $this->settings ) ) {
			return null;
		}
		$ordered = $this->order_edit?->line_for( $product );
		if ( null !== $this->order_edit && null !== $ordered ) {
			$variation = $ordered->item->get_variation_id() > 0 ? wc_get_product( $ordered->item->get_variation_id() ) : null;
			$bought    = $variation instanceof WC_Product ? ProductConfig::config_for( $product, $variation )->for_designer() : $page;
			// The approval UI is the product's own, as when it was added to the cart.
			return new InlineDesigner( $product, $bought, $this->order_edit->mounts( $ordered, $product ), $this->order_edit->approval_mode( $ordered ), null, $ordered, $this->order_edit->closed_message( $ordered ), variant: self::variant_bought( $variation instanceof WC_Product ? $variation : $product, $bought ) );
		}
		$edited = $this->edit?->line_for( $product );
		if ( null !== $this->edit && null !== $edited ) {
			// The line's own sides, which a variation's configuration may narrow or add to.
			$sides     = array_map( static fn ( CommittedSide $side ): string => $side->key, $edited->line->sides );
			$variation = $edited->variation_id > 0 ? wc_get_product( $edited->variation_id ) : null;
			$bought    = $variation instanceof WC_Product ? ProductConfig::config_for( $product, $variation )->for_designer() : $page;
			// The line keeps the approval mode it was added in.
			return new InlineDesigner( $product, $bought, $this->edit->mounts( $edited, $product, $sides ), $edited->line->approval_mode, $edited, variant: self::variant_bought( $variation instanceof WC_Product ? $variation : $product, $bought ) );
		}
		$mode = ApprovalMode::resolve( $page->approval_mode, $this->settings );
		if ( array() === $variants ) {
			$variant = null === $config->blank_id ? null : ProductVariant::sold_by( $product, $config );
			return new InlineDesigner( $product, $config, $this->mounts( $product, $config, null === $variant ? array() : array( $variant ) ), $mode, variant: $variant );
		}
		return $this->variable_designer( $product, $config, $page, $variants, $mode );
	}

	/**
	 * The designer of a variable product. Each side mounts once, with the
	 * print sizes of every variation that has it, and each variation says
	 * what it switches the side to; see `VariationSwitches`.
	 *
	 * @param WC_Product                $product  The variable product.
	 * @param ProductConfig             $config   Its own configuration.
	 * @param ProductConfig             $page     The configuration the page mounts first: its own, or its first variation's personalised inline.
	 * @param array<int, ProductConfig> $variants Each variation's configuration, by variation id.
	 * @param string                    $mode     The page configuration's approval mode.
	 */
	private function variable_designer( WC_Product $product, ProductConfig $config, ProductConfig $page, array $variants, string $mode ): InlineDesigner {
		$switches = new VariationSwitches();
		$sold     = $this->sold_by_blank( $variants );
		if ( $config->personalise ) {
			$switches->add_mounts( $this->mounts( $product, $config, $sold[ (string) $config->blank_id ] ?? array() ), $config );
		}
		foreach ( $variants as $variation_id => $variant ) {
			$variation = wc_get_product( $variation_id );
			if ( ! $variation instanceof WC_Product ) {
				continue;
			}
			$attributes = $variation instanceof WC_Product_Variation ? $variation->get_variation_attributes() : array();
			$switches->set_price_html( $variation_id, $variation->get_price_html() );
			if ( ! $variant->personalise ) {
				$switches->add_plain( $variation_id, $attributes );
				continue;
			}
			// A variation on another layout cannot switch the inline designer to it.
			$mounts       = Layout::INLINE === Layout::resolve( $variant, $this->settings ) ? $this->mounts( $variation, $variant, $sold[ (string) $variant->blank_id ] ?? array() ) : array();
			$variant_mode = ApprovalMode::resolve( $variant->approval_mode, $this->settings );
			if ( array() === $mounts || ! ProductVariant::sellable( $variation, $variant ) ) {
				$switches->add_unavailable( $variation_id, $attributes );
				continue;
			}
			$switches->add_mounts( $mounts, $variant );
			$switches->add_personalised( $variation_id, $attributes, $mounts, $variant_mode, null === $variant->blank_id ? null : ProductVariant::sold_by( $variation, $variant ) );
			if ( ApprovalMode::CHECKBOX === $variant_mode ) {
				$mode = ApprovalMode::CHECKBOX;
			}
		}
		return new InlineDesigner( $product, $config->personalise ? $config : $page, $switches->mounts(), $mode, variations: $switches->variations(), side_labels: $switches->side_labels() );
	}

	/**
	 * The variant refs a variable product's variations personalised inline
	 * sell, by blank: what a side's designer offers on that blank, the same
	 * for every variation on it, so a switch between them never remounts.
	 *
	 * @param array<int, ProductConfig> $configs Configurations by variation id.
	 * @return array<string, list<string>>
	 */
	private function sold_by_blank( array $configs ): array {
		$sold = array();
		foreach ( $configs as $variation_id => $config ) {
			$variation = wc_get_product( $variation_id );
			if ( null === $config->blank_id || ! $config->personalise || ! $variation instanceof WC_Product || Layout::INLINE !== Layout::resolve( $config, $this->settings ) ) {
				continue;
			}
			$ref = ProductVariant::sold_by( $variation, $config );
			if ( null !== $ref && ! in_array( $ref, $sold[ $config->blank_id ] ?? array(), true ) ) {
				$sold[ $config->blank_id ][] = $ref;
			}
		}
		return $sold;
	}

	/**
	 * The first configuration that personalises on the inline layout; a
	 * variation on another layout cannot be the one the page mounts.
	 *
	 * @param array<int, ProductConfig> $configs Configurations by variation id.
	 */
	private function first_inline( array $configs ): ?ProductConfig {
		foreach ( $configs as $config ) {
			if ( $config->personalise && Layout::INLINE === Layout::resolve( $config, $this->settings ) ) {
				return $config;
			}
		}
		return null;
	}

	/**
	 * Mount options by side key; empty when the product cannot be personalised now.
	 *
	 * @param WC_Product    $product  The product, or the variation the configuration applies to.
	 * @param ProductConfig $config   Its configuration.
	 * @param string[]      $variants The refs of the blank's variants the product sells; empty for none known.
	 * @return array<string, array<string, mixed>>
	 * @phpstan-param list<string> $variants
	 */
	private function mounts( WC_Product $product, ProductConfig $config, array $variants = array() ): array {
		$connection = $this->connection->current();
		if ( null === $connection || null !== $this->connection->paused() || ! $config->valid ) {
			return array();
		}
		$form_layout = FormLayout::resolve( $config, $this->settings );
		$options_at  = OptionGroups::resolve( $config, $this->settings );
		$language    = DesignerLanguage::resolve( $this->settings, determine_locale() );
		$mounts      = array();
		foreach ( $config->sides as $side ) {
			if ( null === $side->template_version || ( null === $config->blank_id ) !== ( null === $side->placement_id ) ) {
				return array();
			}
			$options = array(
				'publishableKey'         => $connection->publishable_key,
				'apiBase'                => Hosts::api(),
				'templateId'             => self::template_id( $side ),
				'selectedPrintSizeIndex' => $side->selected_size_index(),
			);
			if ( null !== $language ) {
				// Absent, the SDK uses the workspace's default language.
				$options['locale'] = $language;
			}
			$options += DesignerLook::mount_options( $this->settings );
			if ( null !== $form_layout ) {
				$options['layout'] = $form_layout;
			}
			$options += ProductMount::options( $config, $side, $variants );
			$groups   = FormGroupsMount::option( $side, $product, $options_at );
			if ( null !== $groups ) {
				$options['formGroups'] = $groups;
			}

			/**
			 * Filters the options one side of the inline designer mounts with,
			 * before the targets are added. The storefront script runs them
			 * through the JavaScript filter `pixonaut.designer.mountOptions` too.
			 * On a variable product the filter runs for the product and for
			 * each variation, and a side mounts with the print sizes of all.
			 *
			 * Pixonaut checks every proof against the product's configuration
			 * when it is added to the cart, so a template, blank, placement or
			 * print size changed here is refused there. `views` lists the ids
			 * of the blank's views the designer shows (empty for all),
			 * `placements` the side's placement, and `variants` the refs of
			 * the blank's variants the product sells: on a variable product,
			 * those of every variation on the blank, which the designer's
			 * colour and size picker offers. `preload` is the store's
			 * `designer_preload` setting: what the designer loads ahead
			 * (`auto`, `background` or `off`, or the SDK's `eager`). The
			 * storefront script adds `initialVariant`, the chosen
			 * variation's, and mounts a side whose tab is hidden with
			 * `preload` `off`, preloading in that mode once the tab shows.
			 * `showPrintAreaFrame` is the store's `print_area_frame`
			 * setting and `accent` its custom `designer_accent_colour`; with
			 * the theme's accent chosen, the script reads the colour of the
			 * theme's buttons into `accent` when the options name none. The
			 * script draws the designer's colour and size picker only on a
			 * product without variations whose `variants` lists more than
			 * one (a variable product's variation fields offer them), unless
			 * `showVariantPicker` says otherwise. On a product with one
			 * side it adds `optionsStep` and `lastStepContent`, which draw
			 * the add-to-cart form's variation fields in the designer's
			 * first step and its cart row on the last. A side the store
			 * manager arranged, or whose product's options stand in groups
			 * of their own, has `formGroups`, the designer's groups in
			 * their order with the product's words; an item
			 * `{attribute: <slug>}` in it is the variation field of that
			 * attribute, which the script moves into that group instead of
			 * into the first step (on a product with one side; with more it
			 * leaves the item out), and marks complete once it holds a
			 * choice.
			 *
			 * @since 0.1.0
			 *
			 * @param array<string, mixed> $options  `publishableKey`, `apiBase`, `templateId`, `blankId`, `placementId`, `placements`, `views` and `variants` (with a blank; `variants` once a variant is sold), `initialView` (with a blank, when the product names a starting view), `printSizes` (with a size menu), `selectedPrintSizeIndex` (the size the side starts on), `locale` (one of `DesignerLanguage::languages()`, absent for the workspace's default), `layout` (`page` or `steps`, absent to follow the template), `formGroups` (with an arrangement), `drawAtTemplateRatio` (`true` to draw the canvas at the template's ratio at every print size, `false` at the chosen size's; absent to follow the template), `preload`, `showPrintAreaFrame`, `accent` (with a custom accent colour).
			 * @param WC_Product           $product  The product, or on a variable product the variation the options are for.
			 * @param string               $side_key The side, e.g. `front`.
			 *
			 * @example
			 * add_filter( 'pixonaut_wc_storefront_mount_options', function ( $options ) {
			 *     $options['selectedPrintSizeIndex'] = 1;
			 *     return $options;
			 * } );
			 */
			$mounts[ $side->key ] = (array) apply_filters( 'pixonaut_wc_storefront_mount_options', $options, $product, $side->key );
		}
		return $mounts;
	}

	/**
	 * The blank variant a line bought shows the designer in, as the product
	 * page showed it; null for a product on no blank or selling none.
	 *
	 * @param WC_Product    $bought The product or variation bought.
	 * @param ProductConfig $config Its configuration.
	 */
	private static function variant_bought( WC_Product $bought, ProductConfig $config ): ?string {
		return null === $config->blank_id ? null : ProductVariant::sold_by( $bought, $config );
	}

	/**
	 * The template id Pixonaut serves the side's live version under.
	 *
	 * @param Side $side A side with a known version.
	 */
	public static function template_id( Side $side ): string {
		return $side->template_base_id . '@' . (string) $side->template_version;
	}
}
