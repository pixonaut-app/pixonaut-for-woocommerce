<?php
/**
 * The Pixonaut tab on the product screen.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Admin;

use LogicException;
use Pixonaut\WooCommerce\ApprovalMode;
use Pixonaut\WooCommerce\Catalogue\CanvasRatio;
use Pixonaut\WooCommerce\Catalogue\InvalidReason;
use Pixonaut\WooCommerce\Catalogue\MisfitSizes;
use Pixonaut\WooCommerce\Catalogue\PostedSides;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ProductLinker;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Catalogue\VariationAttributes;
use Pixonaut\WooCommerce\Connection\Connection;
use Pixonaut\WooCommerce\FormLayout;
use Pixonaut\WooCommerce\GroupNumbers;
use Pixonaut\WooCommerce\Layout;
use Pixonaut\WooCommerce\OptionGroups;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Proofs\ProofPicture;
use Pixonaut\WooCommerce\Proofs\ProofUrls;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Templates;
use WC_Product;

/**
 * Links a simple or variable product to a blank and its sides: per side a
 * placement, a template and a print-size menu, and the blank's views the
 * product is shown in. Saving checks every side with Pixonaut; a product that fails a check
 * is saved, cannot be bought, and raises an admin notice saying why until it
 * is fixed. A variable product's configuration is what its variations
 * inherit; `VariationPanel` overrides it per variation.
 */
final class ProductPanel {

	public const TAB = 'pixonaut';

	public const PANEL_ID = 'pixonaut_product_data';

	public const NONCE_ACTION = 'pixonaut_wc_save_product';

	public const NONCE_FIELD = 'pixonaut_wc_product_nonce';

	public const SCRIPT_HANDLE = 'pixonaut-wc-admin-product';

	/**
	 * The layout field; empty follows the store's default.
	 */
	public const LAYOUT_FIELD = PanelFields::PRODUCT_PREFIX . PanelFields::LAYOUT;

	/**
	 * The form layout field; empty follows the store's default.
	 */
	public const FORM_LAYOUT_FIELD = PanelFields::PRODUCT_PREFIX . PanelFields::FORM_LAYOUT;

	/**
	 * The approval mode field; empty follows the store's default.
	 */
	public const APPROVAL_MODE_FIELD = PanelFields::PRODUCT_PREFIX . PanelFields::APPROVAL_MODE;

	/**
	 * The product types the tab configures. A variable product's
	 * configuration is what its variations inherit.
	 */
	private const PRODUCT_TYPES = array( 'simple', 'variable' );

	/**
	 * After WooCommerce's own tabs, which go up to 70 (Advanced).
	 */
	private const TAB_PRIORITY = 75;

	/**
	 * The posted sides repeater.
	 */
	public const SIDES_FIELD = PanelFields::PRODUCT_PREFIX . PanelFields::SIDES;

	/**
	 * A posted side's arrangement of its designer form, as JSON text.
	 */
	public const FORM_GROUPS_FIELD = 'form_groups';

	/**
	 * Index placeholders in the markup for a new side and a new size row,
	 * which the panel's script replaces with the next free index.
	 */
	public const NEW_SIDE_INDEX = '__side__';

	public const NEW_SIZE_INDEX = '__size__';

	/**
	 * The views field's parts: the blank its views were listed for, and the
	 * ids of those ticked.
	 */
	public const VIEWS_BLANK_KEY = 'blank';

	public const VIEWS_TICKED_KEY = 'ticked';

	/**
	 * The views field's part naming the view the designer starts on; empty for the first.
	 */
	public const VIEWS_INITIAL_KEY = 'initial';

	/**
	 * Posted with the panel: the disclosures open when it was saved, which
	 * stay open on the page the save leads to.
	 */
	public const OPEN_FIELD = 'pixonaut_open_disclosures';

	/**
	 * How long, in seconds, the disclosures open at a save are remembered:
	 * ten minutes, well past the page load that follows it.
	 */
	private const OPEN_TTL = 600;

	/**
	 * Most views a product is shown in: more than a blank photographs.
	 */
	private const MAX_VIEWS = 50;

	/**
	 * Width in pixels a standing side's option preview is drawn at: its
	 * 120 px tile at twice the pixel density.
	 */
	private const OPTION_PREVIEW_WIDTH = 240;

	/**
	 * @param ProductLinker           $linker     Checks and builds the configuration.
	 * @param LinkedBlank             $blank      Reads the linked blank's placements, views and printshop.
	 * @param Notices                 $notices    Raises the invalid-product notice.
	 * @param Templates               $templates  Renders the panel.
	 * @param Settings                $settings   The default layout, form layout and approval mode.
	 * @param string                  $file       Main plugin file, for asset URLs.
	 * @param VariantField            $variant    Renders and saves the product's blank variant.
	 * @param ShowcaseField|null      $showcases  Renders the product images field; null for none.
	 * @param RegeneratePictures|null $regenerate Renders the Regenerate product image button; null for none.
	 * @param ProofUrls|null          $proofs     Signs the previews of a standing side's options; null to show them without.
	 * @param Connection|null         $connection While the store is not connected, the panel says how to connect it; null never says.
	 */
	public function __construct(
		private readonly ProductLinker $linker,
		private readonly LinkedBlank $blank,
		private readonly Notices $notices,
		private readonly Templates $templates,
		private readonly Settings $settings,
		private readonly string $file,
		private readonly VariantField $variant,
		private readonly ?ShowcaseField $showcases = null,
		private readonly ?RegeneratePictures $regenerate = null,
		private readonly ?ProofUrls $proofs = null,
		private readonly ?Connection $connection = null,
	) {
	}

	public function register(): void {
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'render' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'deleted_post', array( $this, 'forget' ) );
	}

	/**
	 * The notice raised while a product's configuration is invalid.
	 *
	 * @param int $product_id The product.
	 */
	public static function notice_key( int $product_id ): string {
		return InvalidProductNotice::key( $product_id );
	}

	/**
	 * @param array<string, array<string, mixed>> $tabs The product data tabs.
	 * @return array<string, array<string, mixed>>
	 */
	public function add_tab( array $tabs ): array {
		$tabs[ self::TAB ] = array(
			/* translators: Title of the Pixonaut tab on the product edit page, shown to the merchant; the product name, keep it as it is. */
			'label'    => __( 'Pixonaut', 'pixonaut-for-woocommerce' ),
			'target'   => self::PANEL_ID,
			'class'    => array( 'show_if_simple', 'show_if_variable' ),
			'priority' => self::TAB_PRIORITY,
		);
		return $tabs;
	}

	public function render(): void {
		global $product_object;
		if ( ! $product_object instanceof WC_Product ) {
			return;
		}
		$this->templates->render(
			'admin/product-panel.php',
			array(
				'panel_id'     => self::PANEL_ID,
				'nonce_field'  => wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD, true, false ),
				'max_sides'    => PostedSides::MAX_SIDES,
				// Shown with the blank; a variable product's variations each have their own.
				'fields_html'  => $this->not_connected_html() . $this->fields_html(
					$this->panel( $product_object ) + array(
						'variant_html'    => $product_object->is_type( 'variable' ) ? '' : $this->variant->html( $product_object, PanelFields::product(), $this->integration( ProductConfig::stored_on( $product_object ) ) ),
						'images_html'     => null === $this->showcases ? '' : $this->showcases->html( $product_object ),
						'regenerate_html' => null === $this->regenerate ? '' : $this->regenerate->html( $product_object ),
					)
				),
				'search_urls'  => self::search_urls(),
				'rest_nonce'   => wp_create_nonce( 'wp_rest' ),
				'variant_html' => '',
				'product_id'   => $product_object->get_id(),
			)
		);
	}

	/**
	 * The next step while the store is not connected, as
	 * `admin/product-not-connected.php` prints it; empty otherwise. A paused
	 * connection has a notice of its own.
	 */
	private function not_connected_html(): string {
		if ( null === $this->connection || null !== $this->connection->current() || null !== $this->connection->paused() ) {
			return '';
		}
		return $this->templates->html(
			'admin/product-not-connected.php',
			array(
				'settings_url' => ConnectionScreen::settings_url(),
				'docs_url'     => HelpLinks::docs( HelpLinks::LINKING_PRODUCTS ),
			)
		);
	}

	/**
	 * A configuration's fields, as `admin/product-fields.php` prints them.
	 *
	 * @param array<string, mixed> $panel What `panel()` gave.
	 */
	public function fields_html( array $panel ): string {
		$id_prefix = is_string( $panel['id_prefix'] ?? null ) ? $panel['id_prefix'] : '';
		$side      = fn ( string $index, mixed $fields ): string => $this->templates->html(
			'admin/product-side.php',
			array(
				'field'      => $panel['field'],
				'id_prefix'  => $id_prefix,
				'index'      => $index,
				'size_index' => self::NEW_SIZE_INDEX,
				'side'       => $fields,
				'new_size'   => self::default_size_fields(),
				'placements' => $panel['placements'],
				'groups'     => $panel['groups'] ?? null,
				'open'       => $panel['open'],
			)
		);

		$panel['sides_html'] = array();
		foreach ( is_array( $panel['sides'] ) ? $panel['sides'] : array() as $index => $fields ) {
			$panel['sides_html'][] = $side( (string) $index, $fields );
		}
		$panel['new_side_html'] = $side( self::NEW_SIDE_INDEX, self::side_fields( null ) + $this->standing_options( null ) );
		$panel['blank_html']    = $this->blank_html( $panel );
		$panel['views_html']    = $this->views_html( $panel );
		return $this->templates->html( 'admin/product-fields.php', $panel );
	}

	/**
	 * What the panel shows of its blank, as `admin/product-blank.php` prints
	 * it; empty without a blank.
	 *
	 * @param array<string, mixed> $panel What `panel()` gave, with the blank variant field as `variant_html`.
	 */
	private function blank_html( array $panel ): string {
		if ( null === $panel['printshop'] ) {
			return '';
		}
		return $this->templates->html(
			'admin/product-blank.php',
			array(
				'printshop'    => $panel['printshop'],
				'variant_html' => is_string( $panel['variant_html'] ?? null ) ? $panel['variant_html'] : '',
				'variable'     => true === $panel['variable'],
			)
		);
	}

	/**
	 * What the panel shows of a blank picked but not saved yet: its printshop
	 * and variant, and its views, drawn as the panel draws them for a saved
	 * blank.
	 *
	 * @param WC_Product $product  The product, simple or variable.
	 * @param string     $blank_id The blank picked.
	 * @return array{blank_html: string, views_html: string}
	 */
	public function blank_fields( WC_Product $product, string $blank_id ): array {
		$config                = ProductConfig::stored_on( $product );
		$config                = $config->blank_id === $blank_id ? $config : $config->on_blank( $blank_id );
		$panel                 = $this->panel( $product, $config );
		$panel['variant_html'] = $product->is_type( 'variable' ) ? '' : $this->variant->html( $product, PanelFields::product(), $this->integration( $config ), $blank_id );
		return array(
			'blank_html' => $this->blank_html( $panel ),
			'views_html' => $this->views_html( $panel ),
		);
	}

	/**
	 * The views the product is shown in, as `admin/product-views.php` prints
	 * them: open when they were open at the last update, or show a choice.
	 *
	 * @param array<string, mixed> $panel What `panel()` gave.
	 */
	private function views_html( array $panel ): string {
		$open = is_array( $panel['open'] ) && in_array( 'views', $panel['open'], true );
		// The template takes whether its own section is open, not the list.
		return $this->templates->html( 'admin/product-views.php', array_replace( $panel, array( 'open' => $open ) ) );
	}

	/**
	 * The print partner a configuration's blank is from, such as Gelato,
	 * which its variant is called after; empty without a blank, or for a
	 * blank of the store's own printshop.
	 *
	 * @param ProductConfig $config The configuration.
	 */
	private function integration( ProductConfig $config ): string {
		if ( null === $config->blank_id ) {
			return '';
		}
		return $this->blank->printshop( $config->blank_id, $this->blank->read( $config->blank_id ) )['integration'];
	}

	/**
	 * What the fields show for a product or a variation.
	 *
	 * @param WC_Product         $product The product or variation the fields are posted for.
	 * @param ProductConfig|null $config  The configuration shown; null for the one stored on the product.
	 * @param PanelFields|null   $fields  Where the fields are posted; null for the product's own.
	 * @return array<string, mixed>
	 */
	public function panel( WC_Product $product, ?ProductConfig $config = null, ?PanelFields $fields = null ): array {
		$config ??= ProductConfig::stored_on( $product );
		$fields ??= PanelFields::product();
		$blank    = null === $config->blank_id ? null : $this->blank->read( $config->blank_id );
		$views    = null === $config->blank_id ? null : $this->blank->views( $config->blank_id );
		$pictures = LinkedBlank::placement_thumbnails( $views );

		return array(
			'names'           => array(
				PanelFields::PERSONALISE   => $fields->name( PanelFields::PERSONALISE ),
				PanelFields::BLANK         => $fields->name( PanelFields::BLANK ),
				PanelFields::LAYOUT        => $fields->name( PanelFields::LAYOUT ),
				PanelFields::FORM_LAYOUT   => $fields->name( PanelFields::FORM_LAYOUT ),
				PanelFields::APPROVAL_MODE => $fields->name( PanelFields::APPROVAL_MODE ),
				PanelFields::VIEWS         => $fields->name( PanelFields::VIEWS ),
				PanelFields::PRINTSHOP     => $fields->name( PanelFields::PRINTSHOP ),
				PanelFields::CANVAS_RATIO  => $fields->name( PanelFields::CANVAS_RATIO ),
				PanelFields::OPTION_GROUPS => $fields->name( PanelFields::OPTION_GROUPS ),
				PanelFields::GROUP_NUMBERS => $fields->name( PanelFields::GROUP_NUMBERS ),
			),
			'ids'             => array(
				PanelFields::PERSONALISE   => $fields->id( PanelFields::PERSONALISE ),
				PanelFields::BLANK         => $fields->id( PanelFields::BLANK ),
				PanelFields::LAYOUT        => $fields->id( PanelFields::LAYOUT ),
				PanelFields::FORM_LAYOUT   => $fields->id( PanelFields::FORM_LAYOUT ),
				PanelFields::APPROVAL_MODE => $fields->id( PanelFields::APPROVAL_MODE ),
				PanelFields::INITIAL_VIEW  => $fields->id( PanelFields::INITIAL_VIEW ),
				PanelFields::PRINTSHOP     => $fields->id( PanelFields::PRINTSHOP ),
				PanelFields::CANVAS_RATIO  => $fields->id( PanelFields::CANVAS_RATIO ),
				PanelFields::OPTION_GROUPS => $fields->id( PanelFields::OPTION_GROUPS ),
				PanelFields::GROUP_NUMBERS => $fields->id( PanelFields::GROUP_NUMBERS ),
			),
			'id_prefix'       => $fields->id_prefix,
			'field'           => $fields->name( PanelFields::SIDES ),
			'notice_text'     => $product->is_type( 'variation' )
				? __( 'This variation cannot be bought until its Pixonaut configuration is fixed:', 'pixonaut-for-woocommerce' )
				: __( 'This product cannot be bought until its Pixonaut configuration is fixed:', 'pixonaut-for-woocommerce' ),
			'max_sides'       => PostedSides::MAX_SIDES,
			'recommended'     => PostedSides::RECOMMENDED_SIDES,
			'personalise'     => $config->personalise,
			'blank_id'        => $config->blank_id ?? '',
			'blank_name'      => $config->blank_name ?? '',
			// The blank's first photo: its first placement's first view.
			'blank_thumbnail' => (string) reset( $pictures ),
			'placements'      => array_map( static fn ( array $placement ): array => $placement + array( 'thumbnail' => $pictures[ $placement['id'] ] ?? '' ), $this->placements( $config, $blank ) ),
			'views'           => $views,
			'chosen_views'    => $config->views,
			'initial_view'    => $config->initial_view ?? '',
			// The tile of the view of the design alone shows the template's preview.
			'design_preview'  => null === $views || null === $this->showcases ? '' : $this->showcases->preview( $config ),
			'sides'           => array_map( fn ( ?Side $side ): array => self::side_fields( $side ) + $this->standing_options( $side, $product->get_id() ), array() === $config->sides ? array( null ) : $config->sides ),
			'printshop'       => null === $config->blank_id ? null : $this->blank->printshop( $config->blank_id, $blank ),
			'printshops'      => $this->printshops( $config ),
			'printshop_id'    => $config->printshop_id ?? '',
			'printshops_url'  => StudioPage::section( StudioPage::PRINTSHOPS ),
			'variable'        => $product->is_type( 'variable' ),
			'open'            => self::remembered_open( $product ),
			// Only the product's own panel remembers them: a variation's would post over it.
			'open_field'      => $product->is_type( 'variation' ) ? '' : self::OPEN_FIELD,
			'invalid'         => self::invalid( $config, $blank ),
			'layout'          => in_array( $config->layout, Layout::available(), true ) ? (string) $config->layout : '',
			'layouts'         => $this->layouts( $product ),
			'form_layout'     => in_array( $config->form_layout, FormLayout::available(), true ) ? (string) $config->form_layout : '',
			'form_layouts'    => self::choices( FormLayout::names(), FormLayout::available(), (string) $this->settings->get( Settings::FORM_LAYOUT ) ),
			'option_groups'   => in_array( $config->option_groups, OptionGroups::available(), true ) ? (string) $config->option_groups : '',
			// Only the product chooses: a variation follows it.
			'option_choices'  => $product->is_type( 'variation' ) ? array() : self::choices( OptionGroups::names(), OptionGroups::available(), OptionGroups::of_store( $this->settings ) ),
			'group_numbers'   => in_array( $config->group_numbers, GroupNumbers::available(), true ) ? (string) $config->group_numbers : '',
			'number_choices'  => $product->is_type( 'variation' ) ? array() : self::choices( GroupNumbers::names(), GroupNumbers::available(), GroupNumbers::of_store( $this->settings ) ),
			'approval_mode'   => in_array( $config->approval_mode, ApprovalMode::available(), true ) ? (string) $config->approval_mode : '',
			'canvas_ratio'    => CanvasRatio::posted( $config->draw_at_template_ratio ),
			'canvas_ratios'   => CanvasRatio::names(
				$product->is_type( 'variation' )
					/* translators: Option of a product side's Canvas shape dropdown: the side draws its canvas as the product's Canvas shape setting says. Admin product page. */
					? __( 'As the product says', 'pixonaut-for-woocommerce' )
					/* translators: Option of the product's Canvas shape dropdown: the designer draws the canvas as the template's own setting says. Admin product page. */
					: __( 'As the template says', 'pixonaut-for-woocommerce' )
			),
			'approval_modes'  => self::choices( ApprovalMode::names(), ApprovalMode::available(), (string) $this->settings->get( Settings::DEFAULT_APPROVAL_MODE ) ),
			// A variation's options are chosen already, so only the product arranges its form.
			'groups'          => $product->is_type( 'variation' ) ? null : array(
				'url'        => rest_url( Plugin::REST_NAMESPACE . CatalogueSearch::TEMPLATE_GROUPS_ROUTE ),
				'attributes' => VariationAttributes::of( $product ),
			),
		);
	}

	/**
	 * The catalogue search routes the fields' scripts ask.
	 *
	 * @return array<string, string>
	 */
	public static function search_urls(): array {
		return array(
			'templates'         => rest_url( Plugin::REST_NAMESPACE . CatalogueSearch::TEMPLATES_ROUTE ),
			'blanks'            => rest_url( Plugin::REST_NAMESPACE . CatalogueSearch::BLANKS_ROUTE ),
			'fitting-templates' => rest_url( Plugin::REST_NAMESPACE . CatalogueSearch::FITTING_TEMPLATES_ROUTE ),
			'placements'        => rest_url( Plugin::REST_NAMESPACE . CatalogueSearch::PLACEMENTS_ROUTE ),
			'template'          => rest_url( Plugin::REST_NAMESPACE . CatalogueSearch::TEMPLATE_ROUTE ),
			'blank-fields'      => rest_url( Plugin::REST_NAMESPACE . BlankFields::ROUTE ),
		);
	}

	/**
	 * Saves the panel. WooCommerce has checked its own nonce and the
	 * capability by now; the panel's nonce shows the panel was on the form.
	 *
	 * @param int $product_id The product.
	 */
	public function save( int $product_id ): void {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product || ! $product->is_type( self::PRODUCT_TYPES ) ) {
			return;
		}
		$fields = PanelFields::product();
		$posted = array();
		foreach ( array( PanelFields::PERSONALISE, PanelFields::BLANK, PanelFields::SIDES, PanelFields::LAYOUT, PanelFields::FORM_LAYOUT, PanelFields::APPROVAL_MODE, PanelFields::VIEWS, PanelFields::PRINTSHOP, PanelFields::CANVAS_RATIO, PanelFields::OPTION_GROUPS, PanelFields::GROUP_NUMBERS ) as $key ) {
			$name = $fields->name( $key );
			if ( isset( $_POST[ $name ] ) ) {
				$posted[ $key ] = wp_unslash( $_POST[ $name ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- configure() sanitises each field.
			}
		}
		$this->configure( $product, $posted );
		$this->remember_open( $product, isset( $_POST[ self::OPEN_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::OPEN_FIELD ] ) ) : '' );
		if ( $product->is_type( 'variable' ) ) {
			// Its variations' variants follow the blank it was saved with.
			$this->variant->refresh( $product );
			return;
		}
		$name  = $fields->name( PanelFields::VARIANT );
		$bound = $fields->name( PanelFields::VARIANT_BLANK );
		$this->variant->save(
			$product,
			isset( $_POST[ $name ] ) ? sanitize_text_field( wp_unslash( $_POST[ $name ] ) ) : null,
			isset( $_POST[ $bound ] ) ? sanitize_text_field( wp_unslash( $_POST[ $bound ] ) ) : null
		);
	}

	/**
	 * Checks what was posted for a product or a variation with Pixonaut,
	 * saves it on it and raises or clears its notice.
	 *
	 * @param WC_Product           $product  The product or variation.
	 * @param array<string, mixed> $posted   The fields as posted, unslashed, by `PanelFields` key.
	 * @param ProductConfig|null   $current  What the posted choice changes, its overrides kept; null for what is stored.
	 */
	public function configure( WC_Product $product, array $posted, ?ProductConfig $current = null ): ProductConfig {
		return $this->store( $product, $this->checked( $product, $posted, $current ) );
	}

	/**
	 * The configuration what was posted for a product or a variation makes,
	 * checked with Pixonaut and filtered, without saving it.
	 *
	 * @param WC_Product           $product  The product or variation.
	 * @param array<string, mixed> $posted   The fields as posted, unslashed, by `PanelFields` key.
	 * @param ProductConfig|null   $current  What the posted choice changes, its overrides kept; null for what is stored.
	 */
	public function checked( WC_Product $product, array $posted, ?ProductConfig $current = null ): ProductConfig {
		$before        = $current ?? ProductConfig::stored_on( $product );
		$config        = $this->linker->link(
			$before,
			isset( $posted[ PanelFields::PERSONALISE ] ),
			self::text( $posted[ PanelFields::BLANK ] ?? '' ),
			PostedSides::parse( is_array( $posted[ PanelFields::SIDES ] ?? null ) ? self::sanitised_sides( $posted[ PanelFields::SIDES ] ) : array() )
		);
		$layout        = self::text( $posted[ PanelFields::LAYOUT ] ?? '' );
		$form_layout   = self::text( $posted[ PanelFields::FORM_LAYOUT ] ?? '' );
		$approval_mode = self::text( $posted[ PanelFields::APPROVAL_MODE ] ?? '' );
		$config        = $config
			->with_layout( in_array( $layout, Layout::available(), true ) ? $layout : null )
			->with_form_layout( in_array( $form_layout, FormLayout::available(), true ) ? $form_layout : null )
			->with_approval_mode( in_array( $approval_mode, ApprovalMode::available(), true ) ? $approval_mode : null );
		$views         = self::views( $posted[ PanelFields::VIEWS ] ?? null, $config->blank_id );
		if ( null !== $views ) {
			$config = $config->with_views( ...$views );
		}
		if ( isset( $posted[ PanelFields::CANVAS_RATIO ] ) ) {
			$config = $config->with_draw_at_template_ratio( CanvasRatio::from_posted( self::text( $posted[ PanelFields::CANVAS_RATIO ] ) ) );
		}
		if ( isset( $posted[ PanelFields::OPTION_GROUPS ] ) ) {
			$option_groups = self::text( $posted[ PanelFields::OPTION_GROUPS ] );
			$config        = $config->with_option_groups( in_array( $option_groups, OptionGroups::available(), true ) ? $option_groups : null );
		}
		if ( isset( $posted[ PanelFields::GROUP_NUMBERS ] ) ) {
			$group_numbers = self::text( $posted[ PanelFields::GROUP_NUMBERS ] );
			$config        = $config->with_group_numbers( in_array( $group_numbers, GroupNumbers::available(), true ) ? $group_numbers : null );
		}
		if ( isset( $posted[ PanelFields::PRINTSHOP ] ) ) {
			$printshop = self::text( $posted[ PanelFields::PRINTSHOP ] );
			$config    = $config->with_printshop( '' === $printshop ? null : $printshop );
		}
		if ( $config->personalise && $config->valid && null === $config->blank_id && $this->needs_blank( $config->printshop_id, $before ) ) {
			$config = $config->with_validity( false, InvalidReason::PRINTSHOP_NEEDS_BLANK );
		}

		/**
		 * Filters a product's or variation's Pixonaut configuration before the product screen saves it.
		 *
		 * Runs after the configuration was checked with Pixonaut: a filter
		 * that changes what is printed should also set its validity.
		 *
		 * @since 0.1.0
		 *
		 * @param ProductConfig $config  The checked configuration.
		 * @param WC_Product    $product The product or variation it is saved on.
		 *
		 * @example
		 * add_filter( 'pixonaut_wc_catalogue_product_config', function ( $config, $product ) {
		 *     if ( $product->get_meta( '_pixonaut_hold' ) ) {
		 *         return $config->with_validity( false, 'held' );
		 *     }
		 *     return $config;
		 * }, 10, 2 );
		 */
		$filtered = apply_filters( 'pixonaut_wc_catalogue_product_config', $config, $product );
		return $filtered instanceof ProductConfig ? $filtered : $config; // @phpstan-ignore instanceof.alwaysTrue (a filter may return anything.)
	}

	/**
	 * Saves a configuration `checked()` made on its product or variation and
	 * raises or clears its notice.
	 *
	 * @param WC_Product    $product The product or variation.
	 * @param ProductConfig $config  The checked configuration.
	 * @return ProductConfig The configuration it has now.
	 */
	public function store( WC_Product $product, ProductConfig $config ): ProductConfig {
		try {
			$config->save_to( $product );
		} catch ( LogicException $e ) {
			// A newer plugin version's configuration is kept as it is; the notice says why.
			$config = ProductConfig::stored_on( $product );
		}
		$this->note( $product, $config );
		return $config;
	}

	/**
	 * Drops a deleted product's notice.
	 *
	 * @param int $post_id The deleted post.
	 */
	public function forget( int $post_id ): void {
		( new InvalidProductNotice( $this->notices ) )->forget( $post_id );
		( new VariantNotice( $this->notices ) )->forget( $post_id );
	}

	/**
	 * @param string $hook_suffix The admin screen.
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) || 'product' !== get_post_type() ) {
			return;
		}
		$asset_file = dirname( $this->file ) . '/assets/build/admin-product.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset   = require $asset_file;
		$version = is_string( $asset['version'] ?? null ) ? $asset['version'] : Plugin::VERSION;
		wp_enqueue_style( self::SCRIPT_HANDLE, plugins_url( 'assets/build/admin-product.css', $this->file ), array(), $version );
		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			plugins_url( 'assets/build/admin-product.js', $this->file ),
			array_merge( is_array( $asset['dependencies'] ?? null ) ? $asset['dependencies'] : array(), array( 'jquery', 'selectWoo' ) ),
			$version,
			true
		);
	}

	/**
	 * Raises or clears the notice of a product or variation.
	 *
	 * @param WC_Product    $product The product or variation.
	 * @param ProductConfig $config  Its configuration as saved; a variation's applies only while it overrides.
	 */
	public function note( WC_Product $product, ProductConfig $config ): void {
		( new InvalidProductNotice( $this->notices ) )->note( $product, $config );
	}

	/**
	 * The Layout field's choices: a variation with none of its own follows
	 * its product, a product the store's default.
	 *
	 * @param WC_Product $product The product or variation.
	 * @return array<string, string>
	 */
	private function layouts( WC_Product $product ): array {
		$choices = self::choices( Layout::names(), Layout::available(), (string) $this->settings->get( Settings::DEFAULT_LAYOUT ) );
		if ( $product->is_type( 'variation' ) ) {
			/* translators: Option of a variation's Layout dropdown: the variation uses the layout of its product. Admin product page. */
			$choices[''] = __( 'As the product says', 'pixonaut-for-woocommerce' );
		}
		return $choices;
	}

	/**
	 * An override's choices: the store's default first, under the empty
	 * value, then each available choice.
	 *
	 * @param array<string, string> $names     Each choice's name.
	 * @param string[]              $available The choices whose feature is enabled.
	 * @param string                $current   The store's default.
	 * @return array<string, string>
	 */
	private static function choices( array $names, array $available, string $current ): array {
		$choices = array(
			/* translators: %s: the store's default choice, e.g. a layout or an approval mode. */
			'' => sprintf( __( 'Store setting: %s', 'pixonaut-for-woocommerce' ), $names[ $current ] ?? $current ),
		);
		foreach ( $available as $choice ) {
			$choices[ $choice ] = $names[ $choice ] ?? $choice;
		}
		return $choices;
	}

	/**
	 * Remembers, for the user saving it, the product's disclosures that
	 * were open, so the page the save leads to opens them again.
	 *
	 * @param WC_Product $product The product.
	 * @param string     $posted  The open disclosures, comma separated.
	 */
	private function remember_open( WC_Product $product, string $posted ): void {
		$open = array_values( array_filter( array_map( 'sanitize_key', explode( ',', $posted ) ) ) );
		$key  = self::open_key( $product );
		if ( array() === $open ) {
			delete_transient( $key );
			return;
		}
		set_transient( $key, $open, self::OPEN_TTL );
	}

	/**
	 * The disclosures open when the current user last saved the product.
	 *
	 * @param WC_Product $product The product or variation.
	 * @return list<string>
	 */
	private static function remembered_open( WC_Product $product ): array {
		$open = get_transient( self::open_key( $product ) );
		return is_array( $open ) ? array_values( array_filter( $open, 'is_string' ) ) : array();
	}

	private static function open_key( WC_Product $product ): string {
		return 'pixonaut_wc_open_' . get_current_user_id() . '_' . $product->get_id();
	}

	/**
	 * The printshop choices of a product without a blank: the workspace's
	 * default first, under the empty value, said to print only on a blank
	 * when it does, then each of the workspace's printshops, leaving out
	 * those that print only on a blank (a print partner's integration). A
	 * chosen printshop the workspace no longer has, could not be listed, or
	 * prints only on a blank, stays a choice, so saving again keeps it.
	 *
	 * @param ProductConfig $config The product's configuration.
	 * @return array<string, string> Names by printshop id; empty when no printshop of the workspace prints without a blank.
	 */
	private function printshops( ProductConfig $config ): array {
		$listed  = $this->blank->printshops();
		$default = null;
		$choices = array();
		$offered = null === $listed;
		foreach ( $listed ?? array() as $printshop ) {
			if ( $printshop['default'] ) {
				$default = $printshop;
			}
			if ( ! $printshop['needs_blank'] ) {
				$choices[ $printshop['id'] ] = $printshop['name'];
				$offered                     = true;
			}
		}
		if ( null === $default ) {
			/* translators: Choice of the printshop dropdown on the product edit page: send the product's orders to the default printshop of the Pixonaut workspace. */
			$choices = array( '' => __( 'Workspace default', 'pixonaut-for-woocommerce' ) ) + $choices;
		} elseif ( ! $default['needs_blank'] ) {
			/* translators: %s: the workspace's default printshop. */
			$choices = array( '' => sprintf( __( 'Workspace default (%s)', 'pixonaut-for-woocommerce' ), $default['name'] ) ) + $choices;
		} else {
			// Kept, so a product on it is not saved on another printshop; the panel says it cannot be bought.
			/* translators: %s: the workspace's default printshop, such as Gelato, that prints only on a blank. Choice of the printshop dropdown of a product without a blank. Admin product page. */
			$choices = array( '' => sprintf( __( 'Workspace default (%s): prints only on a blank', 'pixonaut-for-woocommerce' ), $default['name'] ) ) + $choices;
		}
		if ( null !== $config->printshop_id && ! isset( $choices[ $config->printshop_id ] ) ) {
			$needs_blank                      = self::named( $listed, $config->printshop_id );
			$choices[ $config->printshop_id ] = match ( true ) {
				null === $listed => __( 'The printshop chosen before', 'pixonaut-for-woocommerce' ),
				/* translators: %s: a printshop, such as Gelato, that prints only on a blank. Choice of the printshop dropdown of a product without a blank, kept because it was chosen before. Admin product page. */
				null !== $needs_blank => sprintf( __( '%s (prints only on a blank)', 'pixonaut-for-woocommerce' ), $needs_blank ),
				default => __( 'A printshop no longer connected to the workspace', 'pixonaut-for-woocommerce' ),
			};
		}
		return $offered || null !== $config->printshop_id ? $choices : array();
	}

	/**
	 * The name of a listed printshop; null when it is not listed.
	 *
	 * @param list<array{id: string, name: string, default: bool, needs_blank: bool}>|null $listed       The workspace's printshops.
	 * @param string                                                                       $printshop_id The printshop.
	 */
	private static function named( ?array $listed, string $printshop_id ): ?string {
		foreach ( $listed ?? array() as $printshop ) {
			if ( $printshop['id'] === $printshop_id ) {
				return $printshop['name'];
			}
		}
		return null;
	}

	/**
	 * Whether a product without a blank sends its orders to a printshop that
	 * prints only on a blank: the one it chose, or the workspace's default.
	 * When Pixonaut could not list the printshops, what was said before the
	 * save holds while the choice is the same, so an outage does not make
	 * such a product buyable; otherwise false.
	 *
	 * @param string|null   $printshop_id The printshop chosen; null for the workspace's default.
	 * @param ProductConfig $before       The configuration before the save.
	 */
	private function needs_blank( ?string $printshop_id, ProductConfig $before ): bool {
		$listed = $this->blank->printshops();
		if ( null === $listed ) {
			return ! $before->valid && InvalidReason::PRINTSHOP_NEEDS_BLANK === $before->invalid_reason && $before->printshop_id === $printshop_id;
		}
		foreach ( $listed as $printshop ) {
			if ( null === $printshop_id ? $printshop['default'] : $printshop['id'] === $printshop_id ) {
				return $printshop['needs_blank'];
			}
		}
		return false;
	}

	/**
	 * Why the product cannot be sold, empty when it can. A blank deleted in
	 * Pixonaut is said so before the re-check that stops its sales has run.
	 *
	 * @param ProductConfig             $config The product's configuration.
	 * @param array<string, mixed>|null $blank  The blank as `LinkedBlank::read()` gave it.
	 * @phpstan-param array{placements: list<array{id: string, text: string, maxWidthMm?: float, maxHeightMm?: float}>, printshop_id: string|null, printshop_source: string, deleted: bool}|null $blank
	 */
	private static function invalid( ProductConfig $config, ?array $blank ): string {
		if ( ! $config->personalise ) {
			return '';
		}
		if ( null !== $blank && $blank['deleted'] ) {
			return InvalidReason::message( InvalidReason::BLANK_DELETED );
		}
		return $config->valid ? '' : InvalidReason::message( $config->invalid_reason );
	}

	/**
	 * The blank's placements, from Pixonaut; the chosen ones alone when it cannot be asked.
	 *
	 * @param ProductConfig             $config The product's configuration.
	 * @param array<string, mixed>|null $blank  The blank as `LinkedBlank::read()` gave it.
	 * @phpstan-param array{placements: list<array{id: string, text: string, maxWidthMm?: float, maxHeightMm?: float}>, printshop_id: string|null, printshop_source: string, deleted: bool}|null $blank
	 * @return list<array{id: string, text: string, maxWidthMm?: float, maxHeightMm?: float}>
	 */
	private function placements( ProductConfig $config, ?array $blank ): array {
		if ( null !== $blank && array() !== $blank['placements'] ) {
			return $blank['placements'];
		}
		$options = array();
		foreach ( $config->sides as $side ) {
			if ( null !== $side->placement_id ) {
				$options[] = array(
					'id'   => $side->placement_id,
					'text' => $side->placement_name ?? $side->placement_id,
				);
			}
		}
		return $options;
	}

	/**
	 * A standing side's design per option, on a blank whose print areas
	 * change with one, as the panel previews them: the options they change
	 * with, and per row its values, the size it prints at, whether that is
	 * the row's usual size, and its design drawn on the blank, on the photo of
	 * the row's first variant: a row's design names no variant of its own, and
	 * a blank photographed per size would otherwise show another size. Each
	 * row says whether its layout was adjusted by hand, with the addresses
	 * that adjust it and reset it (`LayoutAdjustments`). Empty for any other
	 * side.
	 *
	 * @param Side|null $side       A stored side.
	 * @param int       $product_id The product it is stored on.
	 * @return array{options_by: string, options: list<array{value: string, size: string, usual: bool, image: string, adjusted: bool, adjust_url: string, reset_url: string}>}
	 */
	private function standing_options( ?Side $side, int $product_id = 0 ): array {
		$rows    = null !== $side && $side->is_standing() ? $side->standing_rows : array();
		$options = array();
		foreach ( $rows as $row ) {
			$options[] = array(
				'value'      => $row->label(),
				'size'       => null === $row->size ? '' : $row->size->label,
				// An adjusted row's size is its own.
				'usual'      => $row->usual && null === $row->adjusted,
				'image'      => null === $this->proofs || null === $row->proof_id ? '' : $this->proofs->url( $row->design_id, $row->proof_id, self::OPTION_PREVIEW_WIDTH, ProofPicture::on_blank( null, $row->variants[0] ?? null ) ),
				'adjusted'   => null !== $row->adjusted,
				'adjust_url' => LayoutAdjustments::url( LayoutAdjustments::ADJUST_ACTION, $product_id, $side->key, $row->design_id ),
				'reset_url'  => LayoutAdjustments::url( LayoutAdjustments::RESET_ACTION, $product_id, $side->key, $row->design_id ),
			);
		}
		$first = reset( $rows );
		return array(
			'options_by' => false === $first ? '' : implode( ', ', array_keys( $first->row ) ),
			'options'    => $options,
		);
	}

	/**
	 * A side's fields as the panel prints them; an empty side for null. A
	 * menu Pixonaut could not check yet is shown as it was entered.
	 *
	 * @param Side|null $side A stored side.
	 * @return array{key: string, label: string, placement_id: string, template_id: string, template_name: string, pinned: bool, version: int, versions: list<int>, mode: string, offers_properties: bool|null, misfit_warning: string, form_groups: string, canvas_ratio: string, sizes: list<array{kind: string, width: string, height: string, label: string, start: bool}>}
	 */
	private static function side_fields( ?Side $side ): array {
		$sizes = array();
		$menu  = null === $side ? array() : $side->pending_sizes ?? $side->print_sizes;
		$start = null !== $side && $side->start_size < count( $menu ) ? $side->start_size : 0;
		foreach ( $menu as $index => $size ) {
			$sizes[] = array( 'start' => $index === $start ) + ( null === $size ? self::default_size_fields() : array(
				'kind'   => $size->placement_default ? PostedSides::SIZE_PLACEMENT_DEFAULT : PostedSides::SIZE_CUSTOM,
				// The exact millimetres, so saving again checks the size that was checked before.
				'width'  => (string) $size->width_mm,
				'height' => (string) $size->height_mm,
				'label'  => $size->label,
			) );
		}
		return array(
			'key'               => $side->key ?? '',
			'label'             => $side->label ?? '',
			'placement_id'      => $side->placement_id ?? '',
			// The latest version: the field lists the template, the version picker says which version a pinned side stays on.
			'template_id'       => null === $side || null === $side->template_version ? '' : $side->template_base_id . '@' . $side->known_latest_version(),
			'template_name'     => $side->template_name ?? '',
			'pinned'            => $side->pinned ?? false,
			'version'           => $side->template_version ?? 0,
			'versions'          => null === $side ? array() : $side->versions(),
			'mode'              => $side->mode ?? Side::PERSONALISED,
			'offers_properties' => $side?->offers_properties,
			'misfit_warning'    => MisfitSizes::warning( $side->misfit_sizes ?? array() ),
			'form_groups'       => null === $side?->form_groups ? '' : (string) wp_json_encode( $side->form_groups->to_array() ),
			'canvas_ratio'      => CanvasRatio::posted( $side?->draw_at_template_ratio ),
			'sizes'             => array() === $sizes ? array( array( 'start' => true ) + self::default_size_fields() ) : $sizes,
		);
	}

	/**
	 * The posted sides, sanitised as text, each with its arrangement, posted
	 * as JSON text, decoded. `FormGroups` sanitises the arrangement itself:
	 * text sanitising would strip the percent-encoded octets of an
	 * attribute slug in another script.
	 *
	 * @param array<mixed> $rows The sides as posted.
	 * @return array<mixed>
	 */
	private static function sanitised_sides( array $rows ): array {
		$sanitised = array();
		foreach ( $rows as $index => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$groups = $row[ self::FORM_GROUPS_FIELD ] ?? null;
			unset( $row[ self::FORM_GROUPS_FIELD ] );
			$sanitised[ $index ] = map_deep( $row, 'sanitize_text_field' );
			if ( is_string( $groups ) ) {
				$sanitised[ $index ][ self::FORM_GROUPS_FIELD ] = json_decode( $groups, true );
			}
		}
		return $sanitised;
	}

	/**
	 * A size row set to the placement's default, not the one the designer starts on.
	 *
	 * @return array{kind: string, width: string, height: string, label: string, start: bool}
	 */
	public static function default_size_fields(): array {
		return array(
			'kind'   => PostedSides::SIZE_PLACEMENT_DEFAULT,
			'width'  => '',
			'height' => '',
			'label'  => '',
			'start'  => false,
		);
	}

	/**
	 * The views ticked, each once, at most `MAX_VIEWS`, and the view the
	 * designer starts on: one of them when any is ticked, else null for the
	 * first. Null when the list was not posted for `$blank_id`, whose own
	 * views then stay. The list names the blank it was listed for, so ticks
	 * made under a blank the product has just left are not taken as views of
	 * the new one.
	 *
	 * @param mixed       $posted   The views field as posted, unslashed: the blank first, then the ticked view ids and the starting view.
	 * @param string|null $blank_id The blank the product is saved with.
	 * @return array{0: list<string>, 1: string|null}|null
	 */
	private static function views( mixed $posted, ?string $blank_id ): ?array {
		if ( ! is_array( $posted ) || null === $blank_id || self::text( $posted[ self::VIEWS_BLANK_KEY ] ?? '' ) !== $blank_id ) {
			return null;
		}
		$ticked  = is_array( $posted[ self::VIEWS_TICKED_KEY ] ?? null ) ? $posted[ self::VIEWS_TICKED_KEY ] : array();
		$ids     = array_slice( array_values( array_unique( array_filter( array_map( array( self::class, 'text' ), $ticked ), static fn ( string $id ): bool => '' !== $id ) ) ), 0, self::MAX_VIEWS );
		$initial = self::text( $posted[ self::VIEWS_INITIAL_KEY ] ?? '' );
		return array( $ids, '' === $initial || ( array() !== $ids && ! in_array( $initial, $ids, true ) ) ? null : $initial );
	}

	/**
	 * A posted text field, sanitised.
	 *
	 * @param mixed $value The field as posted, unslashed.
	 */
	private static function text( mixed $value ): string {
		return is_string( $value ) ? sanitize_text_field( $value ) : '';
	}
}
