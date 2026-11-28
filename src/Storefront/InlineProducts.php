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
use Pixonaut\WooCommerce\Catalogue\BlankOptionLinks;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\RequestCache;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Catalogue\SideValues;
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
	 * The filter a designer's mounts pass through.
	 */
	private const FILTERS = array( 'pixonaut_wc_storefront_mount_options' );


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
		if ( $product->is_type( 'variable' ) && $product->get_id() > 0 && ! $this->edits( $product ) ) {
			// The gallery, the form, the swatches and the price each ask for the designer; its variations are read once, and kept for the next shopper.
			return RequestCache::remember( $this->designer_key( $product ), fn (): ?InlineDesigner => $this->kept_designer( $product ) );
		}
		return $this->built( $product );
	}

	/**
	 * Whether the page edits a cart or order line of the product, which is
	 * the shopper's own.
	 *
	 * @param WC_Product $product The product the page shows.
	 */
	private function edits( WC_Product $product ): bool {
		return null !== $this->order_edit?->line_for( $product ) || null !== $this->edit?->line_for( $product );
	}

	/**
	 * A variable product's designer, as kept from an earlier request while
	 * nothing it is built from has changed (`ProductPageCache`).
	 *
	 * @param WC_Product $product A variable product, on a page that edits no line of it.
	 */
	private function kept_designer( WC_Product $product ): ?InlineDesigner {
		$context = $this->designer_context( $product );
		if ( null === $context ) {
			return $this->built( $product );
		}
		$kept = ProductPageCache::remember(
			$product,
			'designer',
			$context,
			function () use ( $product ): ?array {
				$designer = $this->built( $product );
				return null === $designer ? null : array(
					'config'          => $designer->config,
					'mounts'          => $designer->mounts,
					'approval_mode'   => $designer->approval_mode,
					'variations'      => $designer->variations,
					'side_labels'     => $designer->side_labels(),
					'variant'         => $designer->variant,
					'opening_variant' => $designer->opening_variant,
				);
			}
		);
		if ( null === $kept ) {
			return null;
		}
		return new InlineDesigner( $product, $kept['config'], $kept['mounts'], $kept['approval_mode'], variations: $kept['variations'], side_labels: $kept['side_labels'], variant: $kept['variant'], opening_variant: $kept['opening_variant'] );
	}

	/**
	 * What a kept designer depends on besides what every kept read does:
	 * the values the page opens on, and whether the connection is paused.
	 * Null, so nothing is kept, while the address names a value the
	 * product offers none of.
	 *
	 * @param WC_Product $product A variable product.
	 */
	private function designer_context( WC_Product $product ): ?string {
		$opening = is_product() && get_queried_object_id() === $product->get_id() ? OpeningChoice::values( $product ) : array();
		return OpeningChoice::offers_all( $product, $opening ) ? (string) wp_json_encode( array( $opening, $this->connection->paused() ) ) : null;
	}

	/**
	 * The designer the page shows, built from the product's variations.
	 *
	 * @param WC_Product $product The product the page shows.
	 */
	private function built( WC_Product $product ): ?InlineDesigner {
		$config   = ProductConfig::config_for( $product )->for_designer();
		$sold     = $product->is_type( 'variable' ) ? ProductConfig::kept_variations( $product ) : array();
		$variants = array_map( static fn ( array $variation ): ProductConfig => $variation[1]->for_designer(), $sold );
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
			return new InlineDesigner( $product, $config, $this->mounts( $product, $config, null === $variant ? array() : array( $variant ) ), $mode, variant: $variant, page_count: self::pages_sold( $product, $config ) );
		}
		return $this->variable_designer( $product, $config, $page, $variants, array_map( static fn ( array $variation ): WC_Product => $variation[0], $sold ), $mode );
	}

	/**
	 * What a variable product's designer is built from besides its
	 * variations' configurations and the settings and connection this
	 * builder reads: the variation it opens on, the language, and the
	 * filters on its mount options.
	 *
	 * @param WC_Product $product A variable product.
	 */
	private function designer_key( WC_Product $product ): string {
		return implode(
			'|',
			array(
				'inline_designer',
				spl_object_id( $this ),
				ProductConfig::variations_key( $product ),
				// The opening variation: chosen on the product's own page only, from the address or the defaults.
				is_product() && get_queried_object_id() === $product->get_id() ? md5( (string) wp_json_encode( OpeningChoice::values( $product ) ) ) : '',
				determine_locale(),
				RequestCache::filters_key( self::FILTERS ),
			)
		);
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
	 * @param array<int, WC_Product>    $objects  Each variation, by variation id.
	 * @param string                    $mode     The page configuration's approval mode.
	 */
	private function variable_designer( WC_Product $product, ProductConfig $config, ProductConfig $page, array $variants, array $objects, string $mode ): InlineDesigner {
		$switches = new VariationSwitches();
		// The variant each variation sells: read once, as the switches and the blanks' lists each ask.
		$refs = array();
		foreach ( $variants as $variation_id => $variant ) {
			$variation             = $objects[ $variation_id ] ?? null;
			$refs[ $variation_id ] = null === $variant->blank_id || ! $variation instanceof WC_Product ? null : ProductVariant::sold_by( $variation, $variant );
		}
		$sold = $this->sold_by_blank( $variants, $refs );
		// Mount options of the variations alike, while nothing filters them per variation.
		$shared = '' === RequestCache::filters_key( self::FILTERS ) ? array() : null;
		$own    = $config->personalise ? $this->mounts( $product, $config, $sold[ (string) $config->blank_id ] ?? array() ) : null;
		// Each variation that can switch the designer to it: `variation`, its `config`, `attributes` and `mounts`.
		$entries = array();
		foreach ( $variants as $variation_id => $variant ) {
			$variation = $objects[ $variation_id ] ?? null;
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
			$mounts = Layout::INLINE === Layout::resolve( $variant, $this->settings ) ? $this->variation_mounts( $variation, $variant, $sold[ (string) $variant->blank_id ] ?? array(), $shared ) : array();
			// As `ProductVariant::sellable()`: personalised on a blank, it needs a variant.
			if ( array() === $mounts || ( null !== $variant->blank_id && null === $refs[ $variation_id ] ) ) {
				$switches->add_unavailable( $variation_id, $attributes );
				continue;
			}
			$entries[ $variation_id ] = array(
				'variation'  => $variation,
				'config'     => $variant,
				'attributes' => $attributes,
				'mounts'     => $mounts,
			);
		}
		// The page opens on the variation its address or the product's defaults choose: the
		// sides mount with its options, so the page prints its form and preview.
		$opening_id = OpeningChoice::variation_among( $product, array_map( static fn ( array $entry ): WC_Product => $entry['variation'], $entries ) );
		$opening    = $entries[ $opening_id ] ?? null;
		if ( null !== $opening ) {
			$switches->open_on( $opening['mounts'] );
		}
		if ( null !== $own ) {
			$switches->add_mounts( $own, $config );
		}
		foreach ( $entries as $variation_id => $entry ) {
			$variant      = $entry['config'];
			$variant_mode = ApprovalMode::resolve( $variant->approval_mode, $this->settings );
			$switches->add_mounts( $entry['mounts'], $variant );
			$switches->add_personalised( $variation_id, $entry['attributes'], $entry['mounts'], $variant_mode, $refs[ $variation_id ], self::pages_sold( $entry['variation'], $variant ) );
			if ( ApprovalMode::CHECKBOX === $variant_mode ) {
				$mode = ApprovalMode::CHECKBOX;
			}
		}
		return new InlineDesigner( $product, $config->personalise ? $config : $page, $switches->mounts(), $mode, variations: $switches->variations(), side_labels: $switches->side_labels(), opening_variant: null === $opening ? null : $refs[ $opening_id ] ?? null );
	}

	/**
	 * A variation's mount options. Variations set up alike, with the same
	 * values, mount alike, but for what the mount options filter makes of
	 * each: while nothing filters them, those of one serve all set up as it is.
	 *
	 * @param WC_Product                                              $variation A variation.
	 * @param ProductConfig                                           $config    Its configuration.
	 * @param string[]                                                $variants  The refs of its blank's variants the product sells.
	 * @param array<string, array<string, array<string, mixed>>>|null $shared    Mount options by configuration, kept across calls; null to keep none.
	 * @phpstan-param list<string> $variants
	 * @return array<string, array<string, mixed>>
	 */
	private function variation_mounts( WC_Product $variation, ProductConfig $config, array $variants, ?array &$shared ): array {
		if ( null === $shared ) {
			return $this->mounts( $variation, $config, $variants );
		}
		// Alike with the values they are sold with: a variation of its own may have other values than its twin.
		$values           = array_map( static fn ( Side $side ): ?string => SideValues::set_of( $variation, $side ), $config->sides );
		$key              = md5( serialize( array( $config, $values ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- compares two configurations, never stored.
		$shared[ $key ] ??= $this->mounts( $variation, $config, $variants );
		return $shared[ $key ];
	}

	/**
	 * The variant refs a variable product's variations personalised inline
	 * sell, by blank: what a side's designer offers on that blank, the same
	 * for every variation on it, so a switch between them never remounts.
	 *
	 * @param array<int, ProductConfig> $configs Configurations by variation id.
	 * @param array<int, string|null>   $refs    The variant each variation sells, by variation id.
	 * @return array<string, list<string>>
	 */
	private function sold_by_blank( array $configs, array $refs ): array {
		$sold = array();
		foreach ( $configs as $variation_id => $config ) {
			if ( null === $config->blank_id || ! $config->personalise || Layout::INLINE !== Layout::resolve( $config, $this->settings ) ) {
				continue;
			}
			$ref = $refs[ $variation_id ] ?? null;
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
			$value_set = SideValues::set_of( $product, $side );
			if ( null !== $value_set ) {
				// The side's own values, by its template's base id: the designer opens on them, and names the set in every read and request.
				$options['valueSets'] = array( $side->template_base_id => $value_set );
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
			 * On a variable product the options are kept between requests
			 * with the rest of the designer, until the product or a setting
			 * changes: a callback answers the same for every shopper.
			 *
			 * @since 0.1.0
			 *
			 * @param array<string, mixed> $options  `publishableKey`, `apiBase`, `templateId`, `blankId`, `placementId`, `placements`, `views` and `variants` (with a blank; `variants` once a variant is sold), `initialView` (with a blank, when the product names a starting view), `printSizes` (with a size menu), `selectedPrintSizeIndex` (the size the side starts on), `locale` (one of `DesignerLanguage::languages()`, absent for the workspace's default), `layout` (`page` or `steps`, absent to follow the template), `formGroups` (with an arrangement), `valueSets` (the template's base id and the value set of the side's own values, with values; `set-product-values`), `drawAtTemplateRatio` (`true` to draw the canvas at the template's ratio at every print size, `false` at the chosen size's; absent to follow the template), `preload`, `showPrintAreaFrame`, `accent` (with a custom accent colour).
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
	 * The pages an item of a product or variation has, which its designer
	 * shows; null for one on no blank, one sold at no page count, and one
	 * whose count cannot be read, which is not sold.
	 *
	 * @param WC_Product    $sold   The product or variation sold.
	 * @param ProductConfig $config Its configuration.
	 */
	private static function pages_sold( WC_Product $sold, ProductConfig $config ): ?int {
		$count = null === $config->blank_id ? null : BlankOptionLinks::page_count( $sold );
		return BlankOptionLinks::UNRESOLVED_PAGES === $count ? null : $count;
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
