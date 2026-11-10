<?php
/**
 * The inline designer on the single product page.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use Pixonaut\WooCommerce\Admin\Notices;
use Pixonaut\WooCommerce\Approval\OrderEditRoute;
use Pixonaut\WooCommerce\ApprovalMode;
use Pixonaut\WooCommerce\Cart\AddToCart;
use Pixonaut\WooCommerce\Cart\CartEditRoute;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Catalogue\TemplateForm;
use Pixonaut\WooCommerce\Connection\WorkspaceSettings;
use Pixonaut\WooCommerce\DesignerLanguage;
use Pixonaut\WooCommerce\GroupNumbers;
use Pixonaut\WooCommerce\Labels;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Templates;
use WC_Product;
use WP_Block;

/**
 * On a product that personalises inline, the live preview takes the gallery's
 * place and the form goes into the add-to-cart form, which then carries each
 * side's proof token in its `pixonaut_designs[<side>]` field, as add to cart
 * reads them. Classic templates, the
 * classic template block and the gallery blocks are covered. Nothing is
 * printed or loaded for any other product, so the Add to Cart with Options
 * block keeps its Interactivity API add to cart there; on a personalised
 * product the field makes it post the form.
 *
 * A page shows one inline designer, since its targets are found by id: the
 * first product whose preview or form prints claims the page. Any other
 * product's form says personalisation is unavailable, and any other gallery
 * is kept.
 */
final class Storefront {

	public const SCRIPT_HANDLE = 'pixonaut-storefront';

	public const STYLE_HANDLE = 'pixonaut-storefront';

	/**
	 * The global the storefront script reads its page-wide settings from.
	 */
	public const SCRIPT_GLOBAL = 'pixonautStorefront';

	/**
	 * Gallery blocks the preview replaces; each takes the product from its block context.
	 */
	public const GALLERY_BLOCKS = array( 'woocommerce/product-image-gallery', 'woocommerce/product-gallery' );

	/**
	 * Where WooCommerce hooks the classic gallery into `woocommerce_before_single_product_summary`.
	 */
	private const CLASSIC_GALLERY_PRIORITY = 20;

	private const PART_PREVIEW = 'preview';

	private const PART_FORM = 'form';

	/**
	 * The block form's store of its quantity selector.
	 */
	private const BLOCK_QUANTITY_STORE = 'woocommerce/add-to-cart-with-options-quantity-selector';

	/**
	 * The build of `shop-parts-inline.ts`, printed inline after the form.
	 */
	private const PARTS_SCRIPT = 'storefront-parts.js';

	/**
	 * The product whose designer the page shows, once it printed a part.
	 *
	 * @var int|null
	 */
	private ?int $page_product = null;

	/**
	 * Parts of the page's designer printed so far, by part.
	 *
	 * @var array<string, true>
	 */
	private array $printed = array();

	/**
	 * The server's reads of the page's designer, once made, by product id.
	 *
	 * @var array<int, array<string, array<string, mixed>>>
	 */
	private array $reads = array();

	/**
	 * Whether a printed form stands above that the add-to-cart form's parts
	 * are still to be placed in.
	 *
	 * @var bool
	 */
	private bool $parts_to_place = false;

	/**
	 * @param InlineProducts    $products    Resolves a product to its designer.
	 * @param Templates         $templates   Renders the markup.
	 * @param Labels            $labels      Customer-facing text.
	 * @param Settings          $settings    The developer tools' SDK script and debug switch.
	 * @param string            $plugin_file Absolute path of the main plugin file.
	 * @param ServerReads       $server      Reads what the designer starts from, before the page prints.
	 * @param OpeningPrice|null $opening_price Prints a variable product's price as its opening variation's; null leaves the range.
	 */
	public function __construct(
		private readonly InlineProducts $products,
		private readonly Templates $templates,
		private readonly Labels $labels,
		private readonly Settings $settings,
		private readonly string $plugin_file,
		private readonly ServerReads $server,
		private readonly ?OpeningPrice $opening_price = null,
	) {
	}

	public function register(): void {
		add_action( 'wp', array( $this, 'forget_page' ) );
		add_action( 'wp', array( $this, 'replace_classic_gallery' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_on_product_page' ) );
		foreach ( self::GALLERY_BLOCKS as $block ) {
			add_filter( 'render_block_' . $block, array( $this, 'replace_gallery_block' ), 10, 3 );
		}
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'render_form' ) );
		add_action( 'woocommerce_after_add_to_cart_form', array( $this, 'print_parts_script' ) );
	}

	/**
	 * Lets the next product that prints a part claim the page; a main query
	 * starts a new page.
	 */
	public function forget_page(): void {
		$this->page_product = null;
		$this->printed      = array();
	}

	/**
	 * On a personalised product's page, swaps the classic gallery for the
	 * preview. Covers classic themes and the classic template block. On any
	 * other page, `[product_page]` may print a product: the swap is decided
	 * per product, as it prints.
	 */
	public function replace_classic_gallery(): void {
		if ( ! is_product() ) {
			// A theme that removed or moved the gallery keeps that choice.
			$priority = has_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_images' );
			if ( false === $priority ) {
				return;
			}
			remove_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_images', $priority );
			add_action( 'woocommerce_before_single_product_summary', array( $this, 'render_embedded_preview' ), $priority );
			return;
		}
		$designer = $this->page_designer();
		if ( null === $designer || ! $designer->available() ) {
			return;
		}
		remove_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_images', self::CLASSIC_GALLERY_PRIORITY );
		add_action( 'woocommerce_before_single_product_summary', array( $this, 'render_classic_preview' ), self::CLASSIC_GALLERY_PRIORITY );
	}

	public function render_classic_preview(): void {
		// A block template fires the classic hooks too, from WooCommerce's
		// compatibility layer while its blocks render; there the gallery
		// block is replaced instead.
		if ( doing_filter( 'render_block' ) ) {
			return;
		}
		$designer = $this->page_designer();
		if ( null === $designer ) {
			return;
		}
		if ( $this->claim( $designer, self::PART_PREVIEW ) ) {
			echo $this->preview_html( $designer, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the template.
		} else {
			woocommerce_show_product_images();
		}
	}

	/**
	 * The preview of a product printed outside its own page, by `[product_page]`
	 * in a post or a page builder's layout; the gallery of any other. The page
	 * is not a block template's single product, so unlike there this also runs
	 * inside a block's rendering.
	 */
	public function render_embedded_preview(): void {
		global $product;
		$designer = $product instanceof WC_Product ? $this->products->for_product( $product ) : null;
		if ( null !== $designer && $designer->available() && $this->claim( $designer, self::PART_PREVIEW ) ) {
			echo $this->preview_html( $designer, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the template.
		} else {
			woocommerce_show_product_images();
		}
	}

	/**
	 * Replaces a gallery block's output with the preview of its product.
	 *
	 * @param string               $content  The block's output.
	 * @param array<string, mixed> $block    The parsed block.
	 * @param WP_Block|null        $instance The block instance, with its context.
	 */
	public function replace_gallery_block( $content, $block, $instance = null ): string {
		$post_id  = $instance instanceof WP_Block && is_numeric( $instance->context['postId'] ?? null ) ? (int) $instance->context['postId'] : (int) get_the_ID();
		$product  = $post_id > 0 ? wc_get_product( $post_id ) : null;
		$designer = $product instanceof WC_Product ? $this->products->for_product( $product ) : null;
		if ( null === $designer || ! $designer->available() || ! $this->claim( $designer, self::PART_PREVIEW ) ) {
			return (string) $content;
		}
		return $this->preview_html( $designer, false );
	}

	/**
	 * Prints the form targets and the designs fields inside the add-to-cart form.
	 */
	public function render_form(): void {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$designer = $this->products->for_product( $product );
		if ( null === $designer ) {
			return;
		}
		$mounts                      = $designer->available() && $this->claim( $designer, self::PART_FORM ) ? $designer->mounts : array();
		$context                     = array( 'product_id' => $product->get_id() );
		list( $mounts, $variations ) = $this->fit_groups( $designer, $mounts );
		$approve                     = $this->labels->get( Labels::APPROVE_PREVIEW, $context );
		$this->parts_to_place        = array() !== $mounts;
		if ( null !== $this->opening_price && $this->opening_price->opens_with_quantity( $product ) ) {
			// Read when the block form's directives are printed, around this hook's output.
			wp_interactivity_state( self::BLOCK_QUANTITY_STORE, array( 'allowsQuantityChange' => true ) );
		}
		$this->enqueue();
		$this->templates->render(
			'storefront/form.php',
			array(
				'product_id'      => $product->get_id(),
				'mounts'          => $mounts,
				// Every side gets its field, mounted or not, so an unavailable
				// form still posts and add to cart refuses it.
				'sides'           => array_values( array_unique( array_merge( array_map( static fn ( Side $side ): string => $side->key, $designer->config->sides ), array_map( 'strval', array_keys( $mounts ) ) ) ) ),
				'field'           => AddToCart::FIELD,
				'unavailable'     => $designer->closed ?? $this->labels->get( Labels::PERSONALISATION_UNAVAILABLE, $context ),
				'approval_field'  => ApprovalMode::CHECKBOX === $designer->approval_mode ? AddToCart::APPROVAL_FIELD : '',
				'approve_preview' => Labels::without_link( $approve ),
				'approve_link'    => Labels::link_parts( $approve ),
				'open_proof'      => $this->labels->get( Labels::OPEN_PROOF, $context ),
				// A block theme styles its buttons by this class, not by the
				// element; empty under a classic theme.
				'button_class'    => wc_wp_theme_get_element_class_name( 'button' ),
				'side_labels'     => $designer->side_labels(),
				'sides_label'     => $this->labels->get( Labels::SIDES, $context ),
				'edit'            => self::edit_settings( $designer ),
				'variations'      => $variations,
				'variant'         => $designer->variant ?? '',
				'forms'           => array() === $mounts ? array() : $this->forms( $designer ),
				'reads'           => array() === $mounts ? '' : self::reads_json( $this->reads( $designer ) ),
				'read_at'         => time(),
				'product_price'   => null === $this->opening_price ? '' : $this->opening_price->replaced_price( $product ),
				'opening_choice'  => null === $this->opening_price ? array() : $this->opening_price->opening_choice( $product ),
				'look'            => ThemeLook::presets(),
				'group_numbers'   => GroupNumbers::shown( $designer->config, $this->settings ),
			)
		);
	}

	/**
	 * Once the add-to-cart form is printed, places its parts in the printed
	 * form as the page is read, so the first paint already shows them where
	 * the designer draws them. They are the theme's own elements, printed by
	 * WooCommerce around the printed form, so only a script can move them,
	 * and the storefront script is deferred. It does the same when this one
	 * did not run, such as under a policy against inline scripts.
	 */
	public function print_parts_script(): void {
		if ( ! $this->parts_to_place ) {
			return;
		}
		$this->parts_to_place = false;
		$file                 = dirname( $this->plugin_file ) . '/assets/build/' . self::PARTS_SCRIPT;
		if ( is_readable( $file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- This plugin's own build output, on disk.
			wp_print_inline_script_tag( (string) file_get_contents( $file ) );
		}
	}

	public function enqueue_on_product_page(): void {
		if ( null !== $this->page_designer() ) {
			$this->enqueue();
		}
	}

	/**
	 * Loads the storefront script and styles once. The SDK itself is loaded
	 * by the script, when the designer comes into view.
	 */
	public function enqueue(): void {
		if ( wp_script_is( self::SCRIPT_HANDLE, 'enqueued' ) ) {
			return;
		}
		$asset = $this->asset();
		$this->enqueue_style();
		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			plugins_url( 'assets/build/storefront.js', $this->plugin_file ),
			$asset['dependencies'],
			$asset['version'],
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_add_inline_script(
			self::SCRIPT_HANDLE,
			sprintf( 'window.%s = %s;', self::SCRIPT_GLOBAL, wp_json_encode( $this->script_settings() ) ),
			'before'
		);
	}

	/**
	 * Loads the storefront styles alone, for a page that needs no script.
	 */
	public function enqueue_style(): void {
		wp_enqueue_style( self::STYLE_HANDLE, plugins_url( 'assets/build/storefront.css', $this->plugin_file ), array(), $this->asset()['version'] );
	}

	/**
	 * The storefront build's script dependencies and version.
	 *
	 * @return array{dependencies: array<mixed>, version: string}
	 */
	private function asset(): array {
		$file  = dirname( $this->plugin_file ) . '/assets/build/storefront.asset.php';
		$asset = is_readable( $file ) ? require $file : array();
		return array(
			'dependencies' => is_array( $asset['dependencies'] ?? null ) ? $asset['dependencies'] : array(),
			'version'      => is_string( $asset['version'] ?? null ) ? $asset['version'] : Plugin::VERSION,
		);
	}

	/**
	 * What every designer on the page shares.
	 *
	 * @return array<string, mixed>
	 */
	private function script_settings(): array {
		return array(
			'sdk'       => Sdk::script( $this->settings ),
			'debug'     => true === $this->settings->get( Settings::SDK_DEBUG ) && current_user_can( Notices::CAPABILITY ),
			'labels'    => $this->labels->all(),
			'reportUrl' => UnavailableReports::url(),
		) + ( Accent::follows_theme( $this->settings ) ? array( 'themeAccent' => true ) : array() )
			+ ( AttributeOptions::swatches( $this->settings ) ? array( 'attributeSwatches' => true ) : array() );
	}

	/**
	 * What the storefront script needs to save the line the page edits; empty
	 * when it adds a new one.
	 *
	 * @param InlineDesigner $designer A designer.
	 *
	 * @return array<string, string>
	 */
	private static function edit_settings( InlineDesigner $designer ): array {
		if ( null !== $designer->order_edit ) {
			return array( 'key' => $designer->order_edit->state ) + OrderEditRoute::settings( $designer->order_edit->item->get_id() );
		}
		return null === $designer->edit ? array() : array( 'key' => $designer->edit->key ) + CartEditRoute::settings();
	}

	/**
	 * Whether the designer may print a part: its product claimed the page,
	 * or is the first to, and the part is not printed yet.
	 *
	 * @param InlineDesigner $designer An available designer.
	 * @param string         $part     `PART_PREVIEW` or `PART_FORM`.
	 */
	private function claim( InlineDesigner $designer, string $part ): bool {
		$product_id           = $designer->product->get_id();
		$this->page_product ??= $product_id;
		if ( $this->page_product !== $product_id || isset( $this->printed[ $part ] ) ) {
			return false;
		}
		$this->printed[ $part ] = true;
		return true;
	}

	/**
	 * The designer of the product page being shown, if any.
	 */
	private function page_designer(): ?InlineDesigner {
		if ( ! is_product() ) {
			return null;
		}
		$product = wc_get_product( get_queried_object_id() );
		return $product instanceof WC_Product ? $this->products->for_product( $product ) : null;
	}

	/**
	 * @param InlineDesigner $designer An available designer.
	 * @param bool           $classic  Whether it replaces the classic gallery.
	 */
	private function preview_html( InlineDesigner $designer, bool $classic ): string {
		$this->enqueue();
		return $this->templates->html(
			'storefront/preview.php',
			array(
				'product_id' => $designer->product->get_id(),
				'sides'      => array_keys( $designer->mounts ),
				'classic'    => $classic,
				'note'       => $this->labels->get( Labels::PREVIEW_NOTE, array( 'product_id' => $designer->product->get_id() ) ),
				'loading'    => $this->labels->get( Labels::DESIGNER_LOADING, array( 'product_id' => $designer->product->get_id() ) ),
				'layouts'    => $this->layouts( $designer ),
				'images'     => self::image_ids( $designer->product ),
			)
		);
	}

	/**
	 * The product's own images, its featured image first and then its
	 * gallery's, which the preview shows when its designer cannot load.
	 *
	 * @param WC_Product $product The product.
	 * @return int[] Attachment ids.
	 */
	private static function image_ids( WC_Product $product ): array {
		$ids = array_map( 'intval', array_merge( array( $product->get_image_id() ), $product->get_gallery_image_ids() ) );
		return array_values( array_unique( array_filter( $ids, static fn ( int $id ): bool => $id > 0 ) ) );
	}

	/**
	 * The server's reads of the designer's sides, made once per page.
	 *
	 * @param InlineDesigner $designer An available designer.
	 * @return array<string, array<string, mixed>>
	 */
	private function reads( InlineDesigner $designer ): array {
		$product_id                   = $designer->product->get_id();
		$this->reads[ $product_id ] ??= $this->server->for_mounts( $designer->mounts );
		return $this->reads[ $product_id ];
	}

	/**
	 * The reads as the form prints them; empty for none.
	 *
	 * @param array<string, array<string, mixed>> $reads The server's reads.
	 */
	private static function reads_json( array $reads ): string {
		return array() === $reads ? '' : ServerReads::script_json( $reads );
	}

	/**
	 * The mounts and the variations with each side's `formGroups` fitted to
	 * the template the page read for that side (`FormGroupsMount::fitted()`).
	 * A variation side on another template than the one read is read for
	 * that template, and the product's groups are carried to it
	 * (`FormGroupsMount::carried()`); without that read it shows the
	 * template's own form, unless the product's options stand in groups of
	 * their own, which every template takes. A correction has no read of its
	 * own, so its template is read for its groups alone.
	 *
	 * @param InlineDesigner                      $designer An available designer.
	 * @param array<string, array<string, mixed>> $mounts   Mount options by side key.
	 * @return array{0: array<string, array<string, mixed>>, 1: array<int, array<string, mixed>>}
	 */
	private function fit_groups( InlineDesigner $designer, array $mounts ): array {
		$variations = $designer->variations;
		if ( array() === $mounts ) {
			return array( $mounts, $variations );
		}
		// The layouts' reads too, kept for the request.
		$reads     = $this->reads( $designer );
		$groups    = array();
		$templates = array();
		foreach ( $mounts as $side => $options ) {
			$template           = ServerReads::decoded( $reads[ (string) $side ] ?? array() )['template'] ?? null;
			$templates[ $side ] = $template;
			$groups[ $side ]    = null === $template ? null : array_column( TemplateForm::groups( $template ), 'id' );
			if ( null === $template && isset( $options['formGroups'], $options['design'] ) ) {
				$groups[ $side ] = $this->correction_groups( $designer, (string) $side, $options );
			}
			$fitted          = FormGroupsMount::fitted( $options['formGroups'] ?? null, $groups[ $side ] );
			$mounts[ $side ] = self::with_groups( $options, $fitted );
		}
		foreach ( $variations as $id => $variation ) {
			if ( ! isset( $variation['sides'] ) || ! is_object( $variation['sides'] ) ) {
				continue;
			}
			$sides = (array) $variation['sides'];
			foreach ( $sides as $side => $target ) {
				if ( ! is_array( $target ) ) {
					continue;
				}
				$same = ( $target['templateId'] ?? null ) === ( $mounts[ $side ]['templateId'] ?? null );
				if ( $same ) {
					$sides[ $side ] = self::with_groups( $target, FormGroupsMount::fitted( $target['formGroups'] ?? null, $groups[ $side ] ?? null ) );
					continue;
				}
				$other          = FormGroupsMount::needs_template( $target['formGroups'] ?? null ) && is_string( $target['templateId'] ?? null ) ? $this->server->template_read( $target['templateId'], $mounts[ $side ]['locale'] ?? null ) : null;
				$carried        = null === $other ? $target['formGroups'] ?? null : FormGroupsMount::carried( $target['formGroups'], $templates[ $side ] ?? null, $other );
				$sides[ $side ] = self::with_groups( $target, FormGroupsMount::fitted( $carried, null === $other ? null : array_column( TemplateForm::groups( $other ), 'id' ) ) );
			}
			$variations[ $id ]['sides'] = (object) $sides;
		}
		return array( $mounts, $variations );
	}

	/**
	 * The ids of a correction's template's groups: its side's, which a
	 * correction takes `formGroups` from only while the design is on it.
	 *
	 * @param InlineDesigner       $designer An available designer.
	 * @param string               $side     The side.
	 * @param array<string, mixed> $options  Its mount options.
	 * @return list<string>|null Null when they could not be read.
	 */
	private function correction_groups( InlineDesigner $designer, string $side, array $options ): ?array {
		foreach ( $designer->config->sides as $candidate ) {
			if ( $candidate->key === $side && null !== $candidate->template_version ) {
				return $this->server->template_groups( InlineProducts::template_id( $candidate ), $options['locale'] ?? null );
			}
		}
		return null;
	}

	/**
	 * `$options` with `formGroups` set to `$groups`, or without it for null.
	 *
	 * @param array<string, mixed>      $options Mount options, or a variation's side.
	 * @param array<string, mixed>|null $groups  The arrangement.
	 * @return array<string, mixed>
	 */
	private static function with_groups( array $options, ?array $groups ): array {
		unset( $options['formGroups'] );
		return null === $groups ? $options : $options + array( 'formGroups' => $groups );
	}

	/**
	 * Each side's form as the designer draws it, printed until it does, by
	 * side key, in the words of the designer's language: the mount's
	 * `locale`, else its template's workspace's default language.
	 *
	 * @param InlineDesigner $designer An available designer.
	 * @return array<string, string>
	 */
	private function forms( InlineDesigner $designer ): array {
		return array_map(
			fn ( SideLayout $layout ): string => DesignerLanguage::words_in(
				$layout->language ?? WorkspaceSettings::default_language() ?? DesignerLanguage::ENGLISH,
				fn (): string => $this->templates->html( 'storefront/form-placeholder.php', array( 'layout' => $layout ) )
			),
			$this->layouts( $designer )
		);
	}

	/**
	 * Each side's layout, by side key.
	 *
	 * @param InlineDesigner $designer An available designer.
	 * @return array<string, SideLayout>
	 */
	private function layouts( InlineDesigner $designer ): array {
		// A correction's side has no reads of its own, but prints its design's shape and its blank's views.
		$reads   = $this->reads( $designer ) + $this->server->correction_views( $designer->mounts );
		$layouts = array();
		// As the form mounts them: arranged only as far as the template read allows.
		foreach ( $this->fit_groups( $designer, $designer->mounts )[0] as $side => $options ) {
			$layouts[ (string) $side ] = SideLayout::of( $options, ServerReads::decoded( $reads[ (string) $side ] ?? array() ), $designer->variant, self::has_options( $designer, $options ), 1 === count( $designer->mounts ) );
		}
		return $layouts;
	}

	/**
	 * Whether the side's designer draws the product's options in a step of
	 * their own: with one side, the storefront script hands it the
	 * variation fields of a variable product, and the designer adds its
	 * colour and size picker (a product without variations selling more
	 * than one variant) and its print size picker (with more than one
	 * size).
	 *
	 * @param InlineDesigner       $designer An available designer.
	 * @param array<string, mixed> $options  The side's mount options.
	 */
	private static function has_options( InlineDesigner $designer, array $options ): bool {
		if ( 1 !== count( $designer->mounts ) ) {
			return false;
		}
		$variants = is_array( $options['variants'] ?? null ) ? $options['variants'] : array();
		$sizes    = is_array( $options['printSizes'] ?? null ) ? $options['printSizes'] : array();
		// The designer offers a choice of one print size or variant not at all.
		return array() !== $designer->variations || count( $variants ) > 1 || count( $sizes ) > 1;
	}
}
