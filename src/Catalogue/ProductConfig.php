<?php
/**
 * A product's or variation's Pixonaut configuration.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use LogicException;
use Pixonaut\WooCommerce\Schema;
use RuntimeException;
use WC_Product;

/**
 * Whether a product is personalised and how, stored as versioned JSON in the
 * product's (or variation's) `_pixonaut_config` meta. This class is the only
 * code that reads or writes that meta, and it keeps `ProductIndex` in step.
 *
 * A stored document is upgraded step by step to SCHEMA_VERSION when read. A
 * document from a newer plugin version reads as an invalid personalised
 * configuration, so the product cannot be bought unpersonalised, and is never
 * overwritten.
 */
final class ProductConfig {

	public const META_KEY = Schema::PRODUCT_CONFIG_META;

	public const SCHEMA_VERSION = 16;

	/**
	 * Invalid reason of a document written by a newer plugin version.
	 */
	public const REASON_NEWER_SCHEMA = 'newer_schema';

	/**
	 * The status of a variation a customer can choose; a disabled one is private.
	 */
	public const PUBLIC_STATUS = 'publish';

	/**
	 * The id of Pixonaut's Design view: the artwork alone, with no photo
	 * behind it, after the blank's own views. It can be among `$views` and be
	 * the starting view like any of them.
	 */
	public const DESIGN_VIEW = 'design';

	/**
	 * @param bool               $personalise    Whether customers personalise the product.
	 * @param bool               $override       On a variation: use this configuration instead of the product's.
	 * @param string|null        $blank_id       The Pixonaut blank; null for a template-only product.
	 * @param string|null        $blank_name     Cached blank name.
	 * @param Side[]             $sides          The personalisable sides, in display order.
	 * @param string|null        $layout         Layout override; null follows the setting, or on a variation its product.
	 * @param string|null        $approval_mode  Approval mode override; null follows the setting.
	 * @param bool               $valid          False when the configuration cannot be sold as is.
	 * @param string|null        $invalid_reason Why it is invalid, a code from the check that found it.
	 * @param string[]           $views          The ids of the blank's views the product is shown in, `DESIGN_VIEW` among them for the Design view; empty for all and the Design view.
	 * @param string|null        $form_layout    Form layout override; null follows the setting.
	 * @param string|null        $initial_view   The id of the view the designer starts on, one of `$views`
	 *                                           when it names any; null for the first.
	 * @param string|null        $printshop_id   The printshop that makes the product's orders while it has no
	 *                                           blank; null for the workspace's default. A blank decides its own.
	 * @param bool|null          $draw_at_template_ratio Whether the designer draws each side's canvas at its template's
	 *                                           ratio whatever print size is chosen (`CanvasRatio`); null follows
	 *                                           the template. A side's own choice comes first.
	 * @param string|null        $option_groups  Where the product's options stand in the form, an `OptionGroups` choice;
	 *                                           null follows the settings. A variation follows its product.
	 * @param string|null        $group_numbers  Whether the form numbers its groups, a `GroupNumbers` choice; null
	 *                                           follows the setting. A variation follows its product.
	 * @param array<int, string> $split_listing Whether each value of a global attribute is listed as its own
	 *                                    product, by attribute id: `SplitListing::ON` or `SplitListing::OFF`;
	 *                                    an attribute not named follows its own setting. A variation follows its product.
	 * @param InvalidDetail|null $invalid_detail Where it is invalid, and what Pixonaut answered, when the check knows.
	 * @phpstan-param list<Side> $sides
	 * @phpstan-param list<string> $views
	 */
	public function __construct(
		public readonly bool $personalise = false,
		public readonly bool $override = false,
		public readonly ?string $blank_id = null,
		public readonly ?string $blank_name = null,
		public readonly array $sides = array(),
		public readonly ?string $layout = null,
		public readonly ?string $approval_mode = null,
		public readonly bool $valid = true,
		public readonly ?string $invalid_reason = null,
		public readonly array $views = array(),
		public readonly ?string $form_layout = null,
		public readonly ?string $initial_view = null,
		public readonly ?string $printshop_id = null,
		public readonly ?bool $draw_at_template_ratio = null,
		public readonly ?string $option_groups = null,
		public readonly ?string $group_numbers = null,
		public readonly array $split_listing = array(),
		public readonly ?InvalidDetail $invalid_detail = null,
	) {
	}

	/**
	 * The configuration that applies to what the customer buys: a variation's
	 * own when it overrides, else the product's, at the variation's print
	 * sizes, its own or its attribute value's (`VariationSize`), as
	 * `resolved_for()` filters it. An overriding variation with no layout
	 * of its own takes the product's.
	 *
	 * @param WC_Product      $product   The product, or a variation when $variation is null.
	 * @param WC_Product|null $variation The chosen variation, if any.
	 */
	public static function config_for( WC_Product $product, ?WC_Product $variation = null ): self {
		if ( null === $variation && $product->is_type( 'variation' ) ) {
			$variation = $product;
			$parent    = wc_get_product( $product->get_parent_id() );
			if ( ! $parent instanceof WC_Product ) {
				return self::stored_on( $variation )->resolved_for( $variation );
			}
			$product = $parent;
		}
		if ( null === $variation ) {
			return self::stored_on( $product )->resolved_for( $product );
		}
		$config = self::inherited_by( $product, $variation );
		$sizes  = VariationSize::stored_on( $variation );
		if ( array() !== $sizes->sizes || self::has_row_sizes( $config ) || self::has_value_sizes( $config ) ) {
			// An overriding variation sets its sizes in its own setup.
			$config = $sizes->applied_to( $config, ProductVariant::sold_by( $variation, $config ), $config->override ? array() : ValueSizes::attributes_of( $variation ) );
		}
		return $config->resolved_for( $variation );
	}

	/**
	 * What a variation is set up with before its own print sizes and the
	 * filters apply: its own configuration when it overrides, with its
	 * product's groups, canvas ratio, form display and, when it names none,
	 * layout; else its product's.
	 *
	 * @param WC_Product $product   The variable product.
	 * @param WC_Product $variation One of its variations.
	 */
	public static function inherited_by( WC_Product $product, WC_Product $variation ): self {
		$own    = self::stored_on( $variation );
		$parent = self::stored_on( $product );
		if ( ! $own->override ) {
			return $parent;
		}
		return $own->with_groups_of( $parent )->with_canvas_ratio_of( $parent )->with_form_display_of( $parent )->with_layout( $own->layout ?? $parent->layout );
	}

	/**
	 * Whether a side of the configuration has a size per attribute value.
	 *
	 * @param self $config A configuration.
	 */
	private static function has_value_sizes( self $config ): bool {
		foreach ( $config->sides as $side ) {
			if ( null !== $side->value_sizes ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a side of the configuration prints at its own size per row of
	 * its blank's print areas.
	 *
	 * @param self $config A configuration.
	 */
	private static function has_row_sizes( self $config ): bool {
		foreach ( $config->sides as $side ) {
			if ( array() !== $side->row_sizes ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The configuration of each of a variable product's variations a customer
	 * can choose, by variation id.
	 *
	 * @param WC_Product $product A variable product.
	 * @return array<int, self>
	 */
	public static function variation_configs( WC_Product $product ): array {
		return array_map( static fn ( array $sold ): self => $sold[1], self::sold_variations( $product ) );
	}

	/**
	 * Each of a variable product's variations a customer can choose, with
	 * its configuration, by variation id.
	 *
	 * @param WC_Product $product A variable product.
	 * @return array<int, array{0: WC_Product, 1: self}>
	 */
	public static function sold_variations( WC_Product $product ): array {
		$sold = array();
		foreach ( $product->get_children() as $child_id ) {
			$variation = wc_get_product( $child_id );
			// A disabled variation is stored as private.
			if ( $variation instanceof WC_Product && self::PUBLIC_STATUS === $variation->get_status() ) {
				$sold[ $variation->get_id() ] = array( $variation, self::config_for( $product, $variation ) );
			}
		}
		return $sold;
	}

	/**
	 * The configuration stored on one product or variation, without inheritance.
	 *
	 * @param WC_Product $product A product or variation.
	 */
	public static function stored_on( WC_Product $product ): self {
		$document = self::document_on( $product );
		return null === $document ? new self() : self::from_json( $document );
	}

	/**
	 * Whether a product or variation has a configuration of its own stored,
	 * used or not.
	 *
	 * @param WC_Product $product A product or variation.
	 */
	public static function is_stored_on( WC_Product $product ): bool {
		return null !== self::document_on( $product );
	}

	/**
	 * The stored document as it is, for `replace_on()` to write over only it.
	 *
	 * @param WC_Product $product A product or variation.
	 * @return string|null Null when none is stored.
	 */
	public static function document_on( WC_Product $product ): ?string {
		$raw = $product->get_meta( self::META_KEY, true );
		return is_string( $raw ) && '' !== $raw ? $raw : null;
	}

	/**
	 * Stores the configuration on a product or variation. When it cannot be
	 * encoded, to_json() throws a RuntimeException and the stored one is kept.
	 *
	 * @param WC_Product $product A product or variation.
	 * @throws LogicException When the stored document comes from a newer plugin version.
	 */
	public function save_to( WC_Product $product ): void {
		$raw = $product->get_meta( self::META_KEY, true );
		if ( is_string( $raw ) && self::stored_version( $raw ) > self::SCHEMA_VERSION ) {
			throw new LogicException( 'The stored Pixonaut configuration comes from a newer plugin version; it is not overwritten.' );
		}
		$json = $this->to_json();
		$product->update_meta_data( self::META_KEY, $json );
		ProductIndex::write( $product, $this );
		$product->save_meta_data();
	}

	/**
	 * Stores the configuration on a product or variation only while the
	 * stored document is still $expected, so a slow writer never overwrites
	 * a configuration saved after it read, nor restores one deleted. Writes
	 * the database directly; $product itself is left as it was read.
	 *
	 * @param WC_Product $product  A product or variation.
	 * @param string     $expected The document the configuration was made from, as `document_on()` read it.
	 * @return bool False when another document was stored meanwhile; nothing is written then.
	 * @throws LogicException When $expected comes from a newer plugin version.
	 */
	public function replace_on( WC_Product $product, string $expected ): bool {
		if ( self::stored_version( $expected ) > self::SCHEMA_VERSION ) {
			throw new LogicException( 'The stored Pixonaut configuration comes from a newer plugin version; it is not overwritten.' );
		}
		$json = $this->to_json();
		if ( $json === $expected ) {
			return true;
		}
		global $wpdb;
		$product_id = $product->get_id();
		// One conditional UPDATE: no row matches once another document is stored, or none is.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- update_post_meta() adds the row when it is gone; its caches are cleared below.
		$updated = $wpdb->update(
			$wpdb->postmeta,
			array( 'meta_value' => $json ),
			array(
				'post_id'    => $product_id,
				'meta_key'   => self::META_KEY,
				'meta_value' => $expected,
			)
		);
		// phpcs:enable
		if ( ! is_int( $updated ) || $updated < 1 ) {
			return false;
		}
		ProductIndex::rewrite( $product_id, $this );
		clean_post_cache( $product_id );
		wc_delete_product_transients( $product_id );
		return true;
	}

	/**
	 * Removes the configuration from a product or variation.
	 *
	 * @param WC_Product $product A product or variation.
	 */
	public static function delete_from( WC_Product $product ): void {
		$product->delete_meta_data( self::META_KEY );
		ProductIndex::write( $product, null );
		$product->save_meta_data();
	}

	/**
	 * A copy with another validity.
	 *
	 * @param bool               $valid  Whether the configuration can be sold.
	 * @param string|null        $reason Why not; ignored when valid.
	 * @param InvalidDetail|null $detail Where, and what Pixonaut answered; ignored when valid.
	 */
	public function with_validity( bool $valid, ?string $reason = null, ?InvalidDetail $detail = null ): self {
		return $this->with(
			array(
				'valid'          => $valid,
				'invalid_reason' => $valid ? null : $reason,
				'invalid_detail' => $valid ? null : $detail,
			)
		);
	}

	/**
	 * The sides a customer designs, in display order.
	 *
	 * @return list<Side>
	 */
	public function personalised_sides(): array {
		return array_values( array_filter( $this->sides, static fn ( Side $side ): bool => ! $side->is_standing() ) );
	}

	/**
	 * The sides printed from a standing design, in display order.
	 *
	 * @return list<Side>
	 */
	public function standing_sides(): array {
		return array_values( array_filter( $this->sides, static fn ( Side $side ): bool => $side->is_standing() ) );
	}

	/**
	 * What the designer sees of the configuration: its personalised sides
	 * alone. A product whose sides are all standing opens no designer, so it
	 * reads as not personalised: the storefront shows it as any other
	 * product. The cart and fulfilment read the whole configuration.
	 */
	public function for_designer(): self {
		$sides = $this->personalised_sides();
		if ( count( $sides ) === count( $this->sides ) ) {
			return $this;
		}
		return $this->with_sides( $sides )->with_personalise( $this->personalise && array() !== $sides );
	}

	/**
	 * A copy with other sides.
	 *
	 * @param Side[] $sides The sides, in display order.
	 * @phpstan-param list<Side> $sides
	 */
	public function with_sides( array $sides ): self {
		return $this->with( array( 'sides' => $sides ) );
	}

	/**
	 * A copy whose sides on template `$from` are on `$to`, as Pixonaut
	 * changed the template's id; this very configuration when none is.
	 *
	 * @param string $from The template's old base id.
	 * @param string $to   Its new base id.
	 */
	public function with_template_renamed( string $from, string $to ): self {
		$sides = array_map( static fn ( Side $side ): Side => $side->with_template_renamed( $from, $to ), $this->sides );
		return $sides === $this->sides ? $this : $this->with_sides( $sides );
	}

	/**
	 * A copy whose form arrangements name an attribute by another slug; this
	 * very configuration when none names it.
	 *
	 * @param string $from The attribute's slug.
	 * @param string $to   Its new slug.
	 */
	public function with_attribute_renamed( string $from, string $to ): self {
		$renamed = false;
		$sides   = array();
		foreach ( $this->sides as $side ) {
			$placed  = null !== $side->form_groups && in_array( $from, $side->form_groups->attributes(), true );
			$renamed = $renamed || $placed;
			$sides[] = $placed ? $side->with_form_groups( $side->form_groups->with_attribute_renamed( $from, $to ) ) : $side;
		}
		return $renamed ? $this->with_sides( $sides ) : $this;
	}

	/**
	 * A copy whose sides with no arrangement of their own take the one of
	 * the product's side of the same key: only the product arranges its
	 * form, so a variation with its own configuration (another print size,
	 * say) keeps the product's groups.
	 *
	 * @param ProductConfig $product The product's own stored configuration.
	 */
	private function with_groups_of( ProductConfig $product ): self {
		$arranged = array();
		foreach ( $product->sides as $side ) {
			$arranged[ $side->key ] = $side->form_groups;
		}
		$sides = array_map( static fn ( Side $side ): Side => null === $side->form_groups && null !== ( $arranged[ $side->key ] ?? null ) ? $side->with_form_groups( $arranged[ $side->key ] ) : $side, $this->sides );
		return $this->with_sides( $sides );
	}

	/**
	 * A copy with the product's choices of where its options stand in the
	 * form and whether its groups are numbered: only the product makes them.
	 *
	 * @param ProductConfig $product The product's own stored configuration.
	 */
	private function with_form_display_of( ProductConfig $product ): self {
		return $this->with_option_groups( $product->option_groups )->with_group_numbers( $product->group_numbers );
	}

	/**
	 * A copy whose sides with no canvas ratio choice of their own, on a
	 * configuration with none either, take the product's: the choice of the
	 * product's side of the same key, else the product's own. A variation
	 * with its own configuration chooses first, then the side, then the
	 * product.
	 *
	 * @param ProductConfig $product The product's own stored configuration.
	 */
	private function with_canvas_ratio_of( ProductConfig $product ): self {
		if ( null !== $this->draw_at_template_ratio ) {
			return $this;
		}
		$chosen = array();
		foreach ( $product->sides as $side ) {
			$chosen[ $side->key ] = $product->draw_at_template_ratio_for( $side );
		}
		$sides = array_map(
			static fn ( Side $side ): Side => null === $side->draw_at_template_ratio ? $side->with_draw_at_template_ratio( $chosen[ $side->key ] ?? $product->draw_at_template_ratio ) : $side,
			$this->sides
		);
		return $this->with_sides( $sides );
	}

	/**
	 * Whether the designer draws a side's canvas at its template's ratio
	 * whatever print size is chosen: the side's choice, else the
	 * configuration's. Null follows the template's own setting.
	 *
	 * @param Side $side One of the configuration's sides.
	 */
	public function draw_at_template_ratio_for( Side $side ): ?bool {
		return $side->draw_at_template_ratio ?? $this->draw_at_template_ratio;
	}

	/**
	 * A copy that draws its sides' canvases at their templates' ratio, at
	 * the chosen print size's, or as each template says.
	 *
	 * @param bool|null $draw Whether it draws at the template's ratio; null follows the template.
	 */
	public function with_draw_at_template_ratio( ?bool $draw ): self {
		return $this->with( array( 'draw_at_template_ratio' => $draw ) );
	}

	/**
	 * A copy that does or does not personalise.
	 *
	 * @param bool $personalise Whether the product is personalised.
	 */
	public function with_personalise( bool $personalise ): self {
		return $this->with( array( 'personalise' => $personalise ) );
	}

	/**
	 * A copy on another blank, not shown in any of its views yet: what the
	 * product screen shows of a blank picked before the product is saved.
	 *
	 * @param string $blank_id The blank.
	 */
	public function on_blank( string $blank_id ): self {
		return $this->with(
			array(
				'blank_id'     => $blank_id,
				'blank_name'   => null,
				'views'        => array(),
				'initial_view' => null,
			)
		);
	}

	/**
	 * A copy with another layout override.
	 *
	 * @param string|null $layout A layout, or null to follow the setting.
	 */
	public function with_layout( ?string $layout ): self {
		return $this->with( array( 'layout' => $layout ) );
	}

	/**
	 * A copy with another form layout override.
	 *
	 * @param string|null $form_layout A form layout, or null to follow the setting.
	 */
	public function with_form_layout( ?string $form_layout ): self {
		return $this->with( array( 'form_layout' => $form_layout ) );
	}

	/**
	 * A copy with another choice of where the product's options stand in the form.
	 *
	 * @param string|null $option_groups An `OptionGroups` choice, or null to follow the settings.
	 */
	public function with_option_groups( ?string $option_groups ): self {
		return $this->with( array( 'option_groups' => $option_groups ) );
	}

	/**
	 * A copy with another choice of whether the form numbers its groups.
	 *
	 * @param string|null $group_numbers A `GroupNumbers` choice, or null to follow the setting.
	 */
	public function with_group_numbers( ?string $group_numbers ): self {
		return $this->with( array( 'group_numbers' => $group_numbers ) );
	}

	/**
	 * A copy with other choices of whether each value of an attribute is
	 * listed as its own product.
	 *
	 * @param array<int, string> $split_listing `SplitListing::ON` or `SplitListing::OFF` by attribute id; an attribute left out follows its own setting.
	 */
	public function with_split_listing( array $split_listing ): self {
		return $this->with( array( 'split_listing' => SplitListing::choices_from( $split_listing ) ) );
	}

	/**
	 * A copy with another approval mode override.
	 *
	 * @param string|null $approval_mode An approval mode, or null to follow the setting.
	 */
	public function with_approval_mode( ?string $approval_mode ): self {
		return $this->with( array( 'approval_mode' => $approval_mode ) );
	}

	/**
	 * A copy shown in other views.
	 *
	 * @param string[]    $views        The ids of the blank's views the product is shown in; empty for all.
	 * @param string|null $initial_view The view the designer starts on; null for the first.
	 * @phpstan-param list<string> $views
	 */
	public function with_views( array $views, ?string $initial_view = null ): self {
		return $this->with(
			array(
				'views'        => $views,
				'initial_view' => $initial_view,
			)
		);
	}

	/**
	 * A copy that does or does not override its product's configuration.
	 *
	 * @param bool $override On a variation: use this configuration instead of the product's.
	 */
	public function with_override( bool $override ): self {
		return $this->with( array( 'override' => $override ) );
	}

	/**
	 * A copy that sends its orders to another printshop while it has no blank.
	 *
	 * @param string|null $printshop_id A printshop connection's id, or null for the workspace's default.
	 */
	public function with_printshop( ?string $printshop_id ): self {
		return $this->with( array( 'printshop_id' => $printshop_id ) );
	}

	/**
	 * A copy with some properties changed.
	 *
	 * @param array<string, mixed> $changes New values by constructor parameter name.
	 */
	private function with( array $changes ): self {
		return new self( ...array_merge( get_object_vars( $this ), $changes ) );
	}

	/**
	 * The printshop a line of the product is sent to: its own choice while
	 * it has no blank, null for the workspace's default or the blank's.
	 */
	public function order_printshop(): ?string {
		return null === $this->blank_id ? $this->printshop_id : null;
	}

	/**
	 * What the storefront and the cart see of a stored configuration: whether
	 * it personalises, its layout and approval mode overrides and each of its
	 * sides, as the `pixonaut_wc_catalogue_*` filters resolve them.
	 *
	 * @param WC_Product $bought The product or variation the customer buys.
	 */
	public function resolved_for( WC_Product $bought ): self {
		if ( ! $this->personalise ) {
			return $this;
		}

		/**
		 * Filters whether a personalised product is personalised where it is
		 * sold: return false and the storefront, the cart and purchasability
		 * treat it as a plain product. Only consulted for a product whose
		 * configuration personalises it; the product screen still shows the
		 * stored configuration.
		 *
		 * @since 0.1.0
		 *
		 * @param bool          $personalise Whether the product is personalised; true.
		 * @param WC_Product    $bought      The product or variation the customer buys.
		 * @param ProductConfig $config      The stored configuration.
		 *
		 * @example
		 * add_filter( 'pixonaut_wc_catalogue_is_personalisable', function ( $personalise, $bought ) {
		 *     return $bought->is_in_stock() ? $personalise : false;
		 * }, 10, 2 );
		 */
		if ( ! (bool) apply_filters( 'pixonaut_wc_catalogue_is_personalisable', true, $bought, $this ) ) {
			return $this->with_personalise( false );
		}

		/**
		 * Filters a personalised product's layout override. Null follows the
		 * store's default layout; a layout the store does not offer does too.
		 *
		 * @since 0.1.0
		 *
		 * @param string|null   $layout The product's override: `inline`, `button`, or null.
		 * @param WC_Product    $bought The product or variation the customer buys.
		 * @param ProductConfig $config The stored configuration.
		 *
		 * @example
		 * add_filter( 'pixonaut_wc_catalogue_layout', function ( $layout, $bought ) {
		 *     return has_term( 'posters', 'product_cat', $bought->get_parent_id() ?: $bought->get_id() ) ? 'button' : $layout;
		 * }, 10, 2 );
		 */
		$layout = apply_filters( 'pixonaut_wc_catalogue_layout', $this->layout, $bought, $this );

		/**
		 * Filters a personalised product's approval mode override. Null
		 * follows the store's default approval mode; a mode the store does
		 * not offer does too.
		 *
		 * @since 0.1.0
		 *
		 * @param string|null   $approval_mode The product's override: `add_to_cart`, `checkbox`, `proof_email`, or null.
		 * @param WC_Product    $bought        The product or variation the customer buys.
		 * @param ProductConfig $config        The stored configuration.
		 *
		 * @example
		 * add_filter( 'pixonaut_wc_catalogue_approval_mode', function ( $approval_mode, $bought ) {
		 *     return $bought->get_price() > 100 ? 'proof_email' : $approval_mode;
		 * }, 10, 2 );
		 */
		$approval_mode = apply_filters( 'pixonaut_wc_catalogue_approval_mode', $this->approval_mode, $bought, $this );

		$sides = array();
		foreach ( $this->sides as $side ) {
			/**
			 * Filters a side of a personalised product as the storefront, the
			 * cart and purchasability see it. The product screen edits the
			 * stored side, unfiltered.
			 *
			 * The side's print sizes were judged by Pixonaut when the product
			 * was saved; a size added here is not, and Pixonaut refuses a
			 * design at one it cannot print.
			 *
			 * @since 0.1.0
			 *
			 * @param Side          $side   The side as stored.
			 * @param WC_Product    $bought The product or variation the customer buys.
			 * @param ProductConfig $config The configuration the side belongs to.
			 *
			 * @example
			 * add_filter( 'pixonaut_wc_catalogue_side_config', function ( $side, $bought ) {
			 *     if ( 'back' !== $side->key || ! $bought->is_on_sale() ) {
			 *         return $side;
			 *     }
			 *     $label = __( 'Back (free while on sale)', 'my-theme' );
			 *     return new \Pixonaut\WooCommerce\Catalogue\Side( $side->key, $label, $side->placement_id, $side->placement_name, $side->template_base_id, $side->template_version, $side->previous_version, $side->template_name, $side->print_sizes );
			 * }, 10, 2 );
			 */
			$filtered = apply_filters( 'pixonaut_wc_catalogue_side_config', $side, $bought, $this );
			$sides[]  = $filtered instanceof Side ? $filtered : $side; // @phpstan-ignore instanceof.alwaysTrue (a filter may return anything.)
		}
		return $this->with(
			array(
				'sides'         => $sides,
				'layout'        => is_string( $layout ) ? $layout : null,
				'approval_mode' => is_string( $approval_mode ) ? $approval_mode : null,
			)
		);
	}

	/**
	 * @param string $json A stored document.
	 */
	public static function from_json( string $json ): self {
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return new self();
		}
		$version = is_int( $data['v'] ?? null ) ? $data['v'] : 0;
		if ( $version > self::SCHEMA_VERSION ) {
			// An older reader cannot tell whether the newer document overrides
			// the product's configuration, so on a variation it must, to fail
			// closed rather than fall back to the parent's.
			return new self( personalise: true, override: true, valid: false, invalid_reason: self::REASON_NEWER_SCHEMA );
		}
		if ( $version < 1 ) {
			return new self();
		}
		foreach ( self::upgrades() as $from => $upgrade ) {
			if ( $from >= $version ) {
				$data = $upgrade( $data );
			}
		}
		return self::from_array( $data );
	}

	/**
	 * Steps that upgrade a stored document by one version, keyed by the
	 * version they upgrade from, in order. Add one with every schema change.
	 *
	 * @return array<int, callable(array<string, mixed>): array<string, mixed>>
	 */
	private static function upgrades(): array {
		return array(
			// Version 2 shows the product in its blank's views, chosen once for the product, rather than in mockups chosen per side.
			1  => static function ( array $data ): array {
				foreach ( is_array( $data['sides'] ?? null ) ? $data['sides'] : array() as $index => $side ) {
					if ( is_array( $side ) ) {
						unset( $side['mockupIds'] );
						$data['sides'][ $index ] = $side;
					}
				}
				$data['views'] = array();
				return $data;
			},
			// Version 3 adds the form layout override; a product saved before follows the store's.
			2  => static function ( array $data ): array {
				$data['formLayout'] = null;
				return $data;
			},
			// Version 4 adds the starting view and each side's starting size; a product saved before starts on the first of each.
			3  => static function ( array $data ): array {
				foreach ( is_array( $data['sides'] ?? null ) ? $data['sides'] : array() as $index => $side ) {
					if ( is_array( $side ) ) {
						$side['startSize']       = 0;
						$data['sides'][ $index ] = $side;
					}
				}
				$data['initialView'] = null;
				return $data;
			},
			// Version 5 gives each side a mode; a side saved before is personalised.
			4  => static function ( array $data ): array {
				foreach ( is_array( $data['sides'] ?? null ) ? $data['sides'] : array() as $index => $side ) {
					if ( is_array( $side ) ) {
						$side['mode']             = Side::PERSONALISED;
						$side['standingDesignId'] = null;
						$data['sides'][ $index ]  = $side;
					}
				}
				return $data;
			},
			// Version 6 lets each side arrange its designer form; a side saved before shows the template's.
			5  => static function ( array $data ): array {
				foreach ( is_array( $data['sides'] ?? null ) ? $data['sides'] : array() as $index => $side ) {
					if ( is_array( $side ) ) {
						$side['formGroups']      = null;
						$data['sides'][ $index ] = $side;
					}
				}
				return $data;
			},
			// Version 7 lets a product without a blank choose its printshop; a product saved before uses the workspace's default.
			6  => static function ( array $data ): array {
				$data['printshopId'] = null;
				return $data;
			},
			// Version 8 gives a standing side a design per row of print areas that change with an option; a side saved before has its one design.
			7  => static function ( array $data ): array {
				foreach ( is_array( $data['sides'] ?? null ) ? $data['sides'] : array() as $index => $side ) {
					if ( is_array( $side ) ) {
						$side['standingRows']    = array();
						$data['sides'][ $index ] = $side;
					}
				}
				return $data;
			},
			// Version 9 lets a standing side's row be laid out by hand; a row saved before follows the side.
			8  => static function ( array $data ): array {
				foreach ( is_array( $data['sides'] ?? null ) ? $data['sides'] : array() as $index => $side ) {
					if ( ! is_array( $side ) || ! is_array( $side['standingRows'] ?? null ) ) {
						continue;
					}
					foreach ( $side['standingRows'] as $at => $row ) {
						if ( is_array( $row ) ) {
							$side['standingRows'][ $at ]['adjusted'] = null;
						}
					}
					$data['sides'][ $index ] = $side;
				}
				return $data;
			},
			// Version 10 keeps the blank's sizes each side's template cannot be printed at; a side saved before knows of none until it is checked again.
			9  => static function ( array $data ): array {
				foreach ( is_array( $data['sides'] ?? null ) ? $data['sides'] : array() as $index => $side ) {
					if ( is_array( $side ) ) {
						$side['misfitSizes']     = array();
						$data['sides'][ $index ] = $side;
					}
				}
				return $data;
			},
			// Version 11 lets the product and each side choose the designer's canvas shape; a product saved before follows its templates.
			10 => static function ( array $data ): array {
				foreach ( is_array( $data['sides'] ?? null ) ? $data['sides'] : array() as $index => $side ) {
					if ( is_array( $side ) ) {
						$side['drawAtTemplateRatio'] = null;
						$data['sides'][ $index ]     = $side;
					}
				}
				$data['drawAtTemplateRatio'] = null;
				return $data;
			},
			// Version 12 lets a product choose where its options stand in the form and whether its groups are numbered; a product saved before follows the store.
			11 => static function ( array $data ): array {
				$data['optionGroups'] = null;
				$data['groupNumbers'] = null;
				return $data;
			},
			// Version 13 keeps what a side sold at the placement's default prints at per row of a blank whose print areas vary; a side saved before knows of none until it is checked again.
			12 => static function ( array $data ): array {
				foreach ( is_array( $data['sides'] ?? null ) ? $data['sides'] : array() as $index => $side ) {
					if ( is_array( $side ) ) {
						$side['rowSizes']        = array();
						$data['sides'][ $index ] = $side;
					}
				}
				return $data;
			},
			// Version 14 lets a product choose whether each value of an attribute is listed as its own product; a product saved before follows its attributes.
			13 => static function ( array $data ): array {
				$data['splitListing'] = array();
				return $data;
			},
			// Version 15 lets a side of a variable product take a print size per value of one of its attributes; a side saved before has none.
			14 => static function ( array $data ): array {
				foreach ( is_array( $data['sides'] ?? null ) ? $data['sides'] : array() as $index => $side ) {
					if ( is_array( $side ) ) {
						$side['valueSizes']      = null;
						$data['sides'][ $index ] = $side;
					}
				}
				return $data;
			},
			// Version 16 keeps the sizes at which a side's template prints an image below its recommended resolution; a side saved before knows of none until it is checked again.
			15 => static function ( array $data ): array {
				foreach ( is_array( $data['sides'] ?? null ) ? $data['sides'] : array() as $index => $side ) {
					if ( is_array( $side ) ) {
						$side['lowResolution']   = array();
						$data['sides'][ $index ] = $side;
					}
				}
				return $data;
			},
		);
	}

	/**
	 * @throws RuntimeException When the configuration cannot be encoded.
	 */
	public function to_json(): string {
		$json = wp_json_encode( $this->to_array() );
		if ( false === $json ) {
			throw new RuntimeException( 'The Pixonaut configuration could not be encoded as JSON.' );
		}
		return $json;
	}

	/**
	 * @param array<string, mixed> $data A document at SCHEMA_VERSION.
	 */
	private static function from_array( array $data ): self {
		$blank = is_array( $data['blank'] ?? null ) ? $data['blank'] : array();
		$sides = array();
		foreach ( is_array( $data['sides'] ?? null ) ? $data['sides'] : array() as $side ) {
			$parsed = Side::from_array( $side );
			if ( null !== $parsed ) {
				$sides[] = $parsed;
			}
		}
		$validity = is_array( $data['validity'] ?? null ) ? $data['validity'] : array();
		$valid    = ! isset( $validity['valid'] ) || true === $validity['valid'];

		return new self(
			true === ( $data['personalise'] ?? false ),
			true === ( $data['override'] ?? false ),
			self::text_or_null( $blank['id'] ?? null ),
			self::text_or_null( $blank['name'] ?? null ),
			$sides,
			self::text_or_null( $data['layout'] ?? null ),
			self::text_or_null( $data['approvalMode'] ?? null ),
			$valid,
			$valid ? null : self::text_or_null( $validity['reason'] ?? null ),
			self::view_ids( $data['views'] ?? null ),
			self::text_or_null( $data['formLayout'] ?? null ),
			self::text_or_null( $data['initialView'] ?? null ),
			self::text_or_null( $data['printshopId'] ?? null ),
			CanvasRatio::from_stored( $data['drawAtTemplateRatio'] ?? null ),
			self::text_or_null( $data['optionGroups'] ?? null ),
			self::text_or_null( $data['groupNumbers'] ?? null ),
			SplitListing::choices_from( $data['splitListing'] ?? null ),
			$valid ? null : InvalidDetail::from_array( $validity['detail'] ?? null ),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'v'                   => self::SCHEMA_VERSION,
			'personalise'         => $this->personalise,
			'override'            => $this->override,
			'blank'               => null === $this->blank_id ? null : array(
				'id'   => $this->blank_id,
				'name' => $this->blank_name,
			),
			'sides'               => array_map( static fn ( Side $side ): array => $side->to_array(), $this->sides ),
			'layout'              => $this->layout,
			'approvalMode'        => $this->approval_mode,
			'validity'            => array(
				'valid'  => $this->valid,
				'reason' => $this->invalid_reason,
			) + ( null === $this->invalid_detail ? array() : array( 'detail' => $this->invalid_detail->to_array() ) ),
			'views'               => $this->views,
			'formLayout'          => $this->form_layout,
			'initialView'         => $this->initial_view,
			'printshopId'         => $this->printshop_id,
			'drawAtTemplateRatio' => $this->draw_at_template_ratio,
			'optionGroups'        => $this->option_groups,
			'groupNumbers'        => $this->group_numbers,
			// Encoded as an object, its keys attribute ids; as an empty list when there are none.
			'splitListing'        => $this->split_listing,
		);
	}

	private static function stored_version( string $json ): int {
		$data = json_decode( $json, true );
		return is_array( $data ) && is_int( $data['v'] ?? null ) ? $data['v'] : 0;
	}

	/**
	 * Stored view ids, each once; anything else is left out.
	 *
	 * @param mixed $value The stored `views`.
	 * @return list<string>
	 */
	private static function view_ids( mixed $value ): array {
		$ids = array();
		foreach ( is_array( $value ) ? $value : array() as $id ) {
			if ( is_string( $id ) && '' !== $id && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	private static function text_or_null( mixed $value ): ?string {
		return is_string( $value ) && '' !== $value ? $value : null;
	}
}
