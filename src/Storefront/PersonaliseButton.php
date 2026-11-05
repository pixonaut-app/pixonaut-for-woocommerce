<?php
/**
 * The Personalise button layout.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use Pixonaut\WooCommerce\Api\ApiError;
use Pixonaut\WooCommerce\Api\Logger;
use Pixonaut\WooCommerce\Cart\AddToCart;
use Pixonaut\WooCommerce\Cart\CartEdit;
use Pixonaut\WooCommerce\Cart\CartLine;
use Pixonaut\WooCommerce\Cart\LinePicture;
use Pixonaut\WooCommerce\Catalogue\MovedAttributes;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Connection\Connection;
use Pixonaut\WooCommerce\DesignerLanguage;
use Pixonaut\WooCommerce\Labels;
use Pixonaut\WooCommerce\Proofs\ProofCache;
use Pixonaut\WooCommerce\Proofs\ProofUrls;
use Pixonaut\WooCommerce\Templates;
use WC_Product;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_Block;

/**
 * On a product whose layout is the button, a Personalise button stands where
 * WooCommerce's add-to-cart button stands, in that button's classes, so any
 * theme lays it out as it does Add to cart. It posts the add-to-cart form here;
 * the plugin keeps the chosen quantity and attributes in the customer's session
 * and sends them to the product's designer link (`KeptDesignerLinks`), the same
 * for every customer.
 *
 * Approving sends them back with each side's proof token and the product the
 * link names. The designs are committed and kept under a key the address of the
 * product page they land on names (`PersonalisedDesigns`). That page shows the
 * design in place of the product image, a note that it is the customer's, with
 * a button to change it on Pixonaut's correction page (`HostedCorrection`), and
 * WooCommerce's own Add to cart button, which adds it as a line of its own with
 * the chosen quantity (`AddToCart`) and lands on that line's page. A plain visit
 * to the product is always fresh. No return adds to the cart by itself: only that
 * button does, as a return address carries nothing tying it to one customer's
 * trip. Cancelling sends them back to the product.
 *
 * A return with no trip in this session, in another browser or from an address
 * someone else opened, commits nothing: its tokens are kept
 * and the page offers a button to use the design, posted from the store's own
 * page. Only that button keeps it.
 *
 * A cart line links to its product page (`LINE_ARG`). A design a cart line holds
 * has no add-to-cart form on its page, whichever address opened it: the form
 * gives way to what the line is in the cart as, with a button to change it, one
 * to the cart and a link to the plain product page. Nothing there can add a line.
 *
 * On a variable product the button follows the chosen variation: the
 * storefront script hides it for a variation bought without a design or the one
 * the page shows the design of, which is then added to the cart, and says
 * personalisation is unavailable for one that cannot be personalised here. A
 * design belongs to the variation it was made for: choosing another shows that
 * one's state. It shows the chosen variation's price in place of the product's
 * range, and pressing the button before a variation is chosen asks for the
 * options still to choose.
 *
 * Both ends are handled at `wp_loaded`, before WooCommerce's own form
 * handler, where the customer's session and cart are loaded; a REST request
 * has neither for a signed-in customer arriving from another site.
 */
final class PersonaliseButton {

	/**
	 * The button's name: posted with the product id when it is pressed.
	 */
	public const START_FIELD = 'pixonaut_open_designer';

	/**
	 * The button that tries a kept return again: posted with its visit's key.
	 */
	public const RETRY_FIELD = 'pixonaut_add_design';

	/**
	 * Prefix of the variation attribute fields WooCommerce's form posts.
	 */
	private const ATTRIBUTE_PREFIX = 'attribute_';

	/**
	 * The query argument marking a return from the hosted designer.
	 */
	public const RETURN_ARG = 'pixonaut_designer';

	public const RETURN_VALUE = 'return';

	/**
	 * A variation's state when the page shows its design.
	 */
	private const DESIGNED = 'designed';

	/**
	 * The product and variation a designer link names in its return address.
	 */
	public const PRODUCT_ARG = 'pixonaut_product';

	public const VARIATION_ARG = 'pixonaut_variation';

	/**
	 * Parameters the hosted designer adds to the return address: `state`
	 * only for a link minted before links were kept per product.
	 */
	public const STATE_ARG = 'state';

	public const TOKEN_ARG = 'proofToken';

	/**
	 * The query argument naming the cart line whose product page opens with
	 * its design.
	 */
	public const LINE_ARG = 'pixonaut_line';

	/**
	 * Before WooCommerce's add-to-cart handler, which runs at 20.
	 */
	private const HANDLER_PRIORITY = 15;

	/**
	 * Right after WooCommerce's gallery, at 20, so its variation script keeps
	 * changing the first `.images` on the page, its own.
	 */
	private const DESIGN_GALLERY_PRIORITY = 21;

	/**
	 * The actions that print WooCommerce's add-to-cart form, in the classic
	 * template, the Add to Cart form block and a page builder's widget alike.
	 * WooCommerce hooks a function of the same name to each.
	 */
	private const FORM_ACTIONS = array( 'woocommerce_simple_add_to_cart', 'woocommerce_variable_add_to_cart' );

	/**
	 * The priority WooCommerce prints its form at on those actions.
	 */
	private const FORM_PRIORITY = 30;

	/**
	 * The block printing the form for itself, without those actions.
	 */
	private const FORM_BLOCK = 'woocommerce/add-to-cart-with-options';

	/**
	 * The arguments `render_block_*` passes.
	 */
	private const BLOCK_ARGS = 3;

	/**
	 * The products whose design gallery this page printed, by id.
	 *
	 * @var array<int, true>
	 */
	private array $gallery_printed = array();

	/**
	 * Whether the form action running now had WooCommerce's form taken off.
	 *
	 * @var bool
	 */
	private bool $form_replaced = false;

	/**
	 * The key of the kept design this request's page shows, if any.
	 *
	 * @var string|null
	 */
	private ?string $shown_key = null;

	/**
	 * @param ButtonProducts      $products  Which products show the button.
	 * @param Connection          $connection The store's connection.
	 * @param KeptDesignerLinks   $links    Each product's hosted designer link.
	 * @param DesignerVisits      $visits     The customer's open trips to the designer.
	 * @param AddToCart           $add        Commits the returned designs.
	 * @param Storefront          $storefront Loads the storefront styles.
	 * @param Templates           $templates  Renders the button.
	 * @param Labels              $labels     Customer-facing text.
	 * @param Logger              $logger     Logs a link Pixonaut would not mint.
	 * @param OpeningPrice        $opening    The product's own price when the page prints its opening variation's.
	 * @param PersonalisedDesigns $personalised The designs the customer brought back.
	 * @param ProofUrls           $proofs     Signs the design's pictures.
	 * @param LinePicture         $picture    Which picture of a design a product shows.
	 * @param ServerReads         $reads      Whether a template's background shows through.
	 */
	public function __construct(
		private readonly ButtonProducts $products,
		private readonly Connection $connection,
		private readonly KeptDesignerLinks $links,
		private readonly DesignerVisits $visits,
		private readonly AddToCart $add,
		private readonly Storefront $storefront,
		private readonly Templates $templates,
		private readonly Labels $labels,
		private readonly Logger $logger,
		private readonly OpeningPrice $opening,
		private readonly PersonalisedDesigns $personalised,
		private readonly ProofUrls $proofs,
		private readonly LinePicture $picture,
		private readonly ServerReads $reads,
	) {
	}

	public function register(): void {
		// Directly before WooCommerce's button, after the quantity field.
		add_action( 'woocommerce_after_add_to_cart_quantity', array( $this, 'render' ) );
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'render_notices' ) );
		add_action( 'woocommerce_before_single_product_summary', array( $this, 'render_classic_gallery' ), self::DESIGN_GALLERY_PRIORITY );
		foreach ( Storefront::GALLERY_BLOCKS as $block ) {
			add_filter( 'render_block_' . $block, array( $this, 'after_gallery_block' ), 10, self::BLOCK_ARGS );
		}
		foreach ( self::FORM_ACTIONS as $action ) {
			add_action( $action, array( $this, 'replace_form' ), self::FORM_PRIORITY - 1 );
			add_action( $action, array( $this, 'restore_form' ), self::FORM_PRIORITY + 1 );
		}
		add_filter( 'render_block_' . self::FORM_BLOCK, array( $this, 'after_form_block' ), 10, self::BLOCK_ARGS );
		add_filter( 'woocommerce_quantity_input_args', array( $this, 'quantity_args' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_loaded', array( $this, 'handle' ), self::HANDLER_PRIORITY );
		add_action( 'woocommerce_add_to_cart', array( $this, 'added' ) );
	}

	/**
	 * The product's configuration when it shows the button, else null.
	 *
	 * @param WC_Product $product The product or variation.
	 */
	public function config_for( WC_Product $product ): ?ProductConfig {
		$config = ProductConfig::config_for( $product )->for_designer();
		return $this->products->shows_button( $config ) ? $config : null;
	}

	/**
	 * What the button does for each variation a customer can choose, by
	 * variation id: shows for a `personalised` one, says personalisation is
	 * unavailable for an `unavailable` one (it cannot be personalised now,
	 * or is on another layout), gives way to the add-to-cart button for
	 * a `plain` one, bought without a design, and for a `designed` one, whose
	 * design the page shows.
	 *
	 * @param array<int, ProductConfig> $variants Each variation's configuration, by variation id.
	 * @param array<int, mixed>         $designs  The design the page shows, by variation id.
	 * @return array<int, string>
	 */
	private function variation_states( array $variants, array $designs ): array {
		$states = array();
		foreach ( $variants as $variation_id => $variant ) {
			if ( ! $variant->personalise ) {
				$states[ $variation_id ] = VariationSwitches::PLAIN;
			} elseif ( $this->products->shows_button( $variant ) && $this->available( $variant ) ) {
				$states[ $variation_id ] = isset( $designs[ $variation_id ] ) ? self::DESIGNED : VariationSwitches::PERSONALISED;
			} else {
				$states[ $variation_id ] = VariationSwitches::UNAVAILABLE;
			}
		}
		return $states;
	}

	/**
	 * Each variation's price as WooCommerce formats it, by variation id.
	 *
	 * @param array<int, ProductConfig> $variants Each variation's configuration, by variation id.
	 * @return array<int, string>
	 */
	private static function variation_prices( array $variants ): array {
		$prices = array();
		foreach ( array_keys( $variants ) as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( $variation instanceof WC_Product ) {
				$prices[ $variation_id ] = $variation->get_price_html();
			}
		}
		return $prices;
	}

	/**
	 * Prints the button inside the add-to-cart form.
	 */
	public function render(): void {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$variants = $this->products->variants( $product );
		$config   = $this->products->page_config( $product, $variants );
		if ( null === $config ) {
			return;
		}
		$context    = array( 'product_id' => $product->get_id() );
		$designs    = $this->shown_designs( $product, $config, $variants );
		$variations = $this->variation_states( $variants, $designs );
		if ( array() === $variations ) {
			$this->storefront->enqueue_style();
		} else {
			// The script follows the chosen variation.
			$this->storefront->enqueue();
		}
		$this->templates->render(
			'storefront/button.php',
			array(
				'product_id'     => $product->get_id(),
				'field'          => self::START_FIELD,
				'shown'          => $config->personalise,
				'designed'       => isset( $designs[ $this->opening->opening_variation_id( $product ) ] ),
				'available'      => $config->personalise && $this->available( $config ),
				'variations'     => $variations,
				'prices'         => self::variation_prices( $variants ),
				'product_price'  => $this->opening->replaced_price( $product ),
				'opening_choice' => $this->opening->opening_choice( $product ),
				'first_side'     => $config->sides[0]->key ?? '',
				'button_class'   => wc_wp_theme_get_element_class_name( 'button' ),
				'label'          => $this->labels->get( Labels::PERSONALISE, $context ),
				'unavailable'    => $this->labels->get( Labels::PERSONALISATION_UNAVAILABLE, $context ),
				'retry_field'    => self::RETRY_FIELD,
				'retry_state'    => $this->visits->waiting_for( $product->get_id() ) ?? '',
				'retry_label'    => $this->labels->get( Labels::ADD_DESIGN_AGAIN, $context ),
			)
		);
	}

	/**
	 * The design this request's page shows, by variation id: while the product
	 * or variation can be personalised on this layout. At most one.
	 *
	 * @param WC_Product                $product  The product the page shows.
	 * @param ProductConfig             $config   Its configuration.
	 * @param array<int, ProductConfig> $variants Each variation's configuration, by variation id.
	 *
	 * @return array<int, array<string, mixed>>
	 * @phpstan-return array<int, array{key: string, product_id: int, variation_id: int, quantity: int, attributes: array<string, string>, line: \Pixonaut\WooCommerce\Cart\CartLine}>
	 */
	private function shown_designs( WC_Product $product, ProductConfig $config, array $variants ): array {
		$design = null === $this->shown_key ? null : $this->personalised->get( $this->shown_key );
		if ( null === $design || $product->get_id() !== $design['product_id'] ) {
			return array();
		}
		$variation_id = $design['variation_id'];
		$own          = 0 === $variation_id ? ( array() === $variants ? $config : null ) : ( $variants[ $variation_id ] ?? null );
		if ( null === $own || ! $own->personalise || ! $this->products->shows_button( $own ) || ! $this->available( $own ) ) {
			return array();
		}
		return array( $variation_id => $design );
	}

	/**
	 * The design a product page shows, by variation id; empty unless it is a
	 * product on the button layout.
	 *
	 * @param WC_Product $product The product.
	 *
	 * @return array<int, array<string, mixed>>
	 * @phpstan-return array<int, array{key: string, product_id: int, variation_id: int, quantity: int, attributes: array<string, string>, line: \Pixonaut\WooCommerce\Cart\CartLine}>
	 */
	private function page_designs( WC_Product $product ): array {
		$variants = $this->products->variants( $product );
		$config   = $this->products->page_config( $product, $variants );
		return null === $config ? array() : $this->shown_designs( $product, $config, $variants );
	}

	/**
	 * Prints the note of the design the page shows above the add-to-cart row,
	 * while the form holds its variation.
	 */
	public function render_notices(): void {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$designs = $this->page_designs( $product );
		$opening = $this->opening->opening_variation_id( $product );
		$context = array( 'product_id' => $product->get_id() );
		foreach ( $designs as $variation_id => $design ) {
			$this->templates->render(
				'storefront/personalised-notice.php',
				array(
					'design'   => PersonalisedDesigns::page_key( $product->get_id(), $variation_id ),
					'kept_key' => $design['key'],
					'field'    => AddToCart::KEPT_FIELD,
					'shown'    => $variation_id === $opening,
					'message'  => $this->labels->get( Labels::PERSONALISED, $context ),
					'links'    => $this->change_links( $product, $variation_id, $design['key'], $design['line'], Labels::CHANGE_DESIGN ),
				)
			);
		}
	}

	/**
	 * The buttons changing a design: one, or one per side named after the side.
	 *
	 * @param WC_Product $product      The product.
	 * @param int        $variation_id The variation, or 0.
	 * @param string     $key          The kept design's key.
	 * @param CartLine   $line         The kept design's line.
	 * @param string     $label        The label of the button of a design with one side.
	 *
	 * @return list<array{text: string, url: string}>
	 */
	private function change_links( WC_Product $product, int $variation_id, string $key, CartLine $line, string $label ): array {
		$context = array( 'product_id' => $product->get_id() );
		$links   = array();
		foreach ( $line->sides as $side ) {
			$links[] = array(
				'text' => 1 === count( $line->sides )
					? $this->labels->get( $label, $context )
					: sprintf( $this->labels->get( Labels::CHANGE_DESIGN_SIDE, $context ), self::side_label( $product, $variation_id, $side->key ) ),
				'url'  => HostedCorrection::product_url( $key, $side->key ),
			);
		}
		return $links;
	}

	/**
	 * Prints what the page's design is in the cart as, in place of WooCommerce's
	 * add-to-cart form, when a cart line holds it.
	 */
	public function replace_form(): void {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$html = $this->in_cart_html( $product );
		if ( '' !== $html ) {
			$action = (string) current_action();
			remove_action( $action, $action, self::FORM_PRIORITY );
			$this->form_replaced = true;
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the template.
		}
	}

	/**
	 * Puts WooCommerce's form back for the next product a page prints one for.
	 */
	public function restore_form(): void {
		if ( $this->form_replaced ) {
			$action = (string) current_action();
			// WooCommerce's function of the action's name.
			add_action( $action, $action, self::FORM_PRIORITY ); // @phpstan-ignore argument.type
			$this->form_replaced = false;
		}
	}

	/**
	 * Puts what the page's design is in the cart as in place of the form block
	 * of its product, when a cart line holds it.
	 *
	 * @param string               $content  The block's output.
	 * @param array<string, mixed> $block    The parsed block; unused.
	 * @param WP_Block|null        $instance The block instance, with its context.
	 */
	public function after_form_block( $content, $block, $instance = null ): string {
		unset( $block );
		$product = self::block_product( $instance );
		$html    = null === $product ? '' : $this->in_cart_html( $product );
		return '' === $html ? (string) $content : $html;
	}

	/**
	 * What the design a product's page shows is in the cart as; empty when no
	 * cart line holds one.
	 *
	 * @param WC_Product $product The product.
	 */
	private function in_cart_html( WC_Product $product ): string {
		foreach ( $this->page_designs( $product ) as $variation_id => $design ) {
			$cart_key = PersonalisedDesigns::cart_key_of( $design['line'] );
			if ( null === $cart_key ) {
				continue;
			}
			$item    = WC()->cart->get_cart()[ $cart_key ];
			$context = array( 'product_id' => $product->get_id() );
			$this->storefront->enqueue_style();
			return $this->templates->html(
				'storefront/in-cart.php',
				array(
					'message'       => $this->labels->get( Labels::IN_CART, $context ),
					'details'       => self::line_details( $item ),
					'links'         => $this->change_links( $product, $variation_id, $design['key'], $design['line'], Labels::CHANGE_LINE_DESIGN ),
					'cart_url'      => wc_get_cart_url(),
					'cart_label'    => $this->labels->get( Labels::VIEW_CART, $context ),
					'another_url'   => $product->get_permalink(),
					'another_label' => $this->labels->get( Labels::MAKE_ANOTHER, $context ),
					'button_class'  => wc_wp_theme_get_element_class_name( 'button' ),
				)
			);
		}
		return '';
	}

	/**
	 * What a cart line is bought as, in words: its options, when it has any,
	 * and its quantity.
	 *
	 * @param array<string, mixed> $item The cart item.
	 *
	 * @return list<string>
	 */
	private static function line_details( array $item ): array {
		$options  = is_array( $item['variation'] ?? null ) ? wc_get_formatted_variation( $item['variation'], true, false ) : '';
		$quantity = (int) ( $item['quantity'] ?? 1 );
		/* translators: %s: how many of the product are in the cart, as a number. Short for pieces, after the size of a cart item: 1 pc, 2 pcs. */
		$pieces = sprintf( _n( '%s pc', '%s pcs', $quantity, 'pixonaut-for-woocommerce' ), number_format_i18n( $quantity ) );
		return '' === $options ? array( $pieces ) : array( $options, $pieces );
	}

	/**
	 * A side's name as the merchant named it.
	 *
	 * @param WC_Product $product      The product.
	 * @param int        $variation_id The variation, or 0.
	 * @param string     $key          The side's key.
	 */
	private static function side_label( WC_Product $product, int $variation_id, string $key ): string {
		$own = $variation_id > 0 ? wc_get_product( $variation_id ) : $product;
		foreach ( ProductConfig::config_for( $own instanceof WC_Product ? $own : $product )->sides as $side ) {
			if ( $side->key === $key ) {
				return $side->label;
			}
		}
		return $key;
	}

	/**
	 * Prints a classic page's design galleries after WooCommerce's.
	 */
	public function render_classic_gallery(): void {
		// A block template fires the classic hooks too, from WooCommerce's
		// compatibility layer while its blocks render; there the block's filter prints it.
		global $product;
		if ( ! doing_filter( 'render_block' ) && $product instanceof WC_Product ) {
			echo $this->gallery_html( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the template.
		}
	}

	/**
	 * Puts a gallery block's product's design galleries after it.
	 *
	 * @param string               $content  The block's output.
	 * @param array<string, mixed> $block    The parsed block; unused.
	 * @param WP_Block|null        $instance The block instance, with its context.
	 */
	public function after_gallery_block( $content, $block, $instance = null ): string {
		unset( $block );
		$product = self::block_product( $instance );
		return (string) $content . ( null === $product ? '' : $this->gallery_html( $product ) );
	}

	/**
	 * The product a block is rendered for.
	 *
	 * @param WP_Block|null $instance The block instance, with its context.
	 */
	private static function block_product( ?WP_Block $instance ): ?WC_Product {
		$post_id = null !== $instance && is_numeric( $instance->context['postId'] ?? null ) ? (int) $instance->context['postId'] : (int) get_the_ID();
		$product = $post_id > 0 ? wc_get_product( $post_id ) : null;
		return $product instanceof WC_Product ? $product : null;
	}

	/**
	 * The gallery of the design the page shows, once a page; shown, it puts
	 * WooCommerce's gallery out of sight.
	 *
	 * @param WC_Product $product The product.
	 */
	private function gallery_html( WC_Product $product ): string {
		if ( isset( $this->gallery_printed[ $product->get_id() ] ) ) {
			return '';
		}
		$designs = $this->page_designs( $product );
		if ( array() === $designs ) {
			return '';
		}
		$this->gallery_printed[ $product->get_id() ] = true;
		$this->storefront->enqueue_style();
		$opening = $this->opening->opening_variation_id( $product );
		$html    = '';
		foreach ( $designs as $variation_id => $design ) {
			$bought   = $variation_id > 0 ? wc_get_product( $variation_id ) : $product;
			$blank    = ProductConfig::config_for( $bought instanceof WC_Product ? $bought : $product )->blank_id;
			$pictures = array();
			foreach ( $design['line']->sides as $side ) {
				if ( null === $side->proof_id || ProofCache::is_erased( $side->design_id ) ) {
					continue;
				}
				$picture    = $this->picture->of( $bought instanceof WC_Product ? $bought : $product, $side->key );
				$pictures[] = array(
					'src'    => $this->proofs->url( $side->design_id, $side->proof_id, ProofUrls::LARGEST_WIDTH, $picture ),
					'srcset' => implode(
						', ',
						array_map(
							fn ( int $width ): string => $this->proofs->url( $side->design_id, $side->proof_id, $width, $picture ) . ' ' . $width . 'w',
							ProofUrls::WIDTHS
						)
					),
					'alt'    => $this->labels->get( Labels::PROOF_ALT, array( 'product_id' => $product->get_id() ) ),
					// Display only: never in a print file or a stored design.
					'paper'  => null === $blank && true === $this->reads->background_see_through( $side->template_base_id . '@' . $side->template_version ),
				);
			}
			if ( array() !== $pictures ) {
				$html .= $this->templates->html(
					'storefront/design-gallery.php',
					array(
						'design'   => PersonalisedDesigns::page_key( $product->get_id(), $variation_id ),
						// A design in the cart has no form to choose its variation in.
						'shown'    => $variation_id === $opening || null !== PersonalisedDesigns::cart_key_of( $design['line'] ),
						'pictures' => $pictures,
					)
				);
			}
		}
		return $html;
	}

	/**
	 * The quantity field opens on what the customer chose before they left
	 * for the designer.
	 *
	 * @param mixed $args    The field's arguments.
	 * @param mixed $product The product.
	 */
	public function quantity_args( mixed $args, mixed $product ): mixed {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- reads whether WooCommerce's form posted one, which it then shows.
		if ( ! is_array( $args ) || ! $product instanceof WC_Product || ! is_product() || isset( $_POST['quantity'] ) ) {
			return $args;
		}
		$design = $this->page_designs( $product )[ $this->opening->opening_variation_id( $product ) ] ?? null;
		if ( null !== $design ) {
			$args['input_value'] = $design['quantity'];
		}
		return $args;
	}

	public function enqueue(): void {
		$product = is_product() ? wc_get_product( get_queried_object_id() ) : null;
		if ( $product instanceof WC_Product && null !== $this->products->page_config( $product, $this->products->variants( $product ) ) ) {
			$this->storefront->enqueue_style();
		}
	}

	/**
	 * Sends the customer to the hosted designer, or back from it.
	 */
	public function handle(): void {
		if ( is_admin() ) {
			return;
		}
		// The add-to-cart form carries no nonce; WooCommerce's own handler reads it the same way.
		// phpcs:disable WordPress.Security.NonceVerification
		if ( isset( $_POST[ self::START_FIELD ] ) ) {
			$location = $this->start(
				absint( wp_unslash( $_POST[ self::START_FIELD ] ) ),
				isset( $_POST['variation_id'] ) ? absint( wp_unslash( $_POST['variation_id'] ) ) : 0,
				isset( $_POST['quantity'] ) ? absint( wp_unslash( $_POST['quantity'] ) ) : 1,
				self::posted_attributes()
			);
		} elseif ( isset( $_POST[ self::RETRY_FIELD ] ) ) {
			$location = $this->retry( sanitize_text_field( wp_unslash( $_POST[ self::RETRY_FIELD ] ) ) );
		} elseif ( isset( $_GET[ self::LINE_ARG ] ) ) {
			// A link from the customer's own cart; the page it opens shows that line's design.
			$this->show_line( sanitize_text_field( wp_unslash( $_GET[ self::LINE_ARG ] ) ) );
			return;
		} elseif ( isset( $_POST[ AddToCart::KEPT_FIELD ] ) ) {
			// Stays on show when the add is refused; `added()` lets it go.
			$this->show( sanitize_text_field( wp_unslash( $_POST[ AddToCart::KEPT_FIELD ] ) ) );
			return;
		} elseif ( isset( $_GET[ PersonalisedDesigns::ARG ] ) ) {
			// Only finds a design in the customer's own session.
			$this->show( sanitize_text_field( wp_unslash( $_GET[ PersonalisedDesigns::ARG ] ) ) );
			return;
		} elseif ( self::RETURN_VALUE === ( isset( $_GET[ self::RETURN_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::RETURN_ARG ] ) ) : '' ) ) {
			$location = $this->finish(
				isset( $_GET[ self::PRODUCT_ARG ] ) ? absint( wp_unslash( $_GET[ self::PRODUCT_ARG ] ) ) : 0,
				isset( $_GET[ self::VARIATION_ARG ] ) ? absint( wp_unslash( $_GET[ self::VARIATION_ARG ] ) ) : 0,
				isset( $_GET[ self::TOKEN_ARG ] ) ? map_deep( wp_unslash( $_GET[ self::TOKEN_ARG ] ), 'sanitize_text_field' ) : null,
				isset( $_GET[ self::STATE_ARG ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::STATE_ARG ] ) ) : ''
			);
		} else {
			return;
		}
		// phpcs:enable WordPress.Security.NonceVerification
		// Pixonaut's hosted designer is on another host, which wp_safe_redirect() refuses.
		wp_redirect( $location ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	/**
	 * After an add the page no longer shows the design it added: the customer
	 * goes on to that line's page, or the cart (`CartEdit::redirect()`).
	 */
	public function added(): void {
		$this->shown_key = null;
	}

	/**
	 * Lets this request's page show a kept design; such a page is the
	 * customer's alone, and never cached.
	 *
	 * @param string $key The design's key.
	 */
	private function show( string $key ): void {
		if ( null !== $this->personalised->get( $key ) ) {
			$this->shown_key = $key;
			$this->price_as_line();
			nocache_headers();
		}
	}

	/**
	 * A design in the cart has no form to choose its variation in, so the page
	 * prints that variation's price whatever options its address holds.
	 */
	private function price_as_line(): void {
		$design  = null === $this->shown_key ? null : $this->personalised->get( $this->shown_key );
		$product = null === $design ? null : wc_get_product( $design['product_id'] );
		if ( null === $design || ! $product instanceof WC_Product || null === PersonalisedDesigns::cart_key_of( $design['line'] ) || array() === $this->page_designs( $product ) ) {
			return;
		}
		$variation = wc_get_product( $design['variation_id'] );
		if ( $variation instanceof WC_Product_Variation ) {
			$this->opening->open_on( $product->get_id(), $variation );
		}
	}

	/**
	 * Sends the customer to a product's designer link.
	 *
	 * @param int                   $product_id   The product (the parent of a variation).
	 * @param int                   $variation_id The variation, or 0.
	 * @param int                   $quantity     How many the product page offers to add on return.
	 * @param array<string, string> $attributes   The chosen `attribute_*` values, for a variation's "any" attributes.
	 *
	 * @return string Where to send the customer: the hosted designer, or back to the product with a notice.
	 */
	public function start( int $product_id, int $variation_id, int $quantity, array $attributes = array() ): string {
		$parent = wc_get_product( $product_id );
		if ( 0 === $variation_id && $parent instanceof WC_Product_Variable && self::on_show( $parent ) ) {
			return $this->ask_for_options( $parent, $attributes );
		}
		$product = self::sellable( $product_id, $variation_id );
		if ( null === $product ) {
			return home_url( '/' );
		}
		$back   = $product->get_permalink();
		$config = $this->config_for( $product );
		if ( null === $config ) {
			return $back;
		}
		try {
			$url = $this->available( $config ) ? $this->links->url_for( $product, $config, determine_locale() ) : null;
		} catch ( ApiError $error ) {
			$this->logger->warning( sprintf( 'Could not make a designer link for product %d: %s', $product->get_id(), $error->getMessage() ), array( 'code' => $error->error_code ) );
			$url = null;
		}
		if ( null === $url || null === $this->visits->open( $product_id, $variation_id, max( 1, $quantity ), $attributes ) ) {
			return $this->refuse( Labels::PERSONALISATION_UNAVAILABLE, $product, $back );
		}
		return $url;
	}

	/**
	 * Takes the customer back from the hosted designer.
	 *
	 * @param int    $product_id   The product the link names (the parent of a variation).
	 * @param int    $variation_id The variation it names, or 0.
	 * @param mixed  $tokens       The proof token, or tokens by side key; null when the customer cancelled.
	 * @param string $state        The state a link minted before links were kept per product carried back; empty otherwise.
	 *
	 * @return string Where to send the customer.
	 */
	public function finish( int $product_id, int $variation_id, mixed $tokens, string $state = '' ): string {
		if ( '' !== $state ) {
			$key     = $state;
			$visit   = $this->visits->take( $state );
			$product = null === $visit ? null : wc_get_product( $visit['variation_id'] > 0 ? $visit['variation_id'] : $visit['product_id'] );
			$product = $product instanceof WC_Product ? $product : null;
		} else {
			return $this->finish_kept( $product_id, $variation_id, $tokens );
		}
		if ( null === $visit || null === $product ) {
			return $this->unknown_visit();
		}
		if ( null === $tokens ) {
			return $product->get_permalink();
		}
		return $this->commit( $key, $visit, $product, self::tokens( ProductConfig::config_for( $product )->for_designer(), $tokens ) );
	}

	/**
	 * Takes the customer back from a product's kept designer link. The same
	 * return a second time is refused before the visit is taken, so it
	 * leaves a visit kept for another try, or opened since, as it is. A return
	 * this session opened no visit for waits for the customer to use it.
	 *
	 * @param int   $product_id   The product the link names (the parent of a variation).
	 * @param int   $variation_id The variation it names, or 0.
	 * @param mixed $tokens       The proof token, or tokens by side key; null when the customer cancelled.
	 *
	 * @return string Where to send the customer.
	 */
	private function finish_kept( int $product_id, int $variation_id, mixed $tokens ): string {
		$key     = DesignerVisits::key( $product_id, $variation_id );
		$product = self::sellable( $product_id, $variation_id );
		if ( null === $product ) {
			return $this->unknown_visit();
		}
		if ( null !== $tokens ) {
			$tokens = self::tokens( ProductConfig::config_for( $product )->for_designer(), $tokens );
			if ( ! $this->visits->first_return( $tokens ) ) {
				return $this->unknown_visit();
			}
		}
		$visit = $this->visits->take( $key );
		if ( null === $tokens ) {
			return $product->get_permalink();
		}
		if ( null !== $visit || array() === $tokens ) {
			return $this->commit( $key, $visit ?? $this->visits->unopened( $product_id, $variation_id ), $product, $tokens );
		}
		$this->visits->keep( $key, $this->visits->unopened( $product_id, $variation_id ), $tokens );
		wc_add_notice( $this->labels->get( Labels::DESIGN_READY, array( 'product_id' => $product->get_id() ) ), 'notice' );
		return $product->get_permalink();
	}

	/**
	 * Tries committing a kept return's designs again.
	 *
	 * @param string $state The kept visit's key.
	 *
	 * @return string Where to send the customer.
	 */
	public function retry( string $state ): string {
		$visit   = $this->visits->take( $state );
		$product = null === $visit ? null : wc_get_product( $visit['variation_id'] > 0 ? $visit['variation_id'] : $visit['product_id'] );
		if ( null === $visit || null === $visit['tokens'] || ! $product instanceof WC_Product ) {
			return $this->unknown_visit();
		}
		return $this->commit( $state, $visit, $product, $visit['tokens'] );
	}

	/**
	 * Commits the returned designs, which the product page they land on then
	 * shows; keeps them for another try when the refusal may pass next time.
	 *
	 * @param string                $state   The visit's key.
	 * @param array<string, mixed>  $visit   The visit, taken out of the session.
	 * @param WC_Product            $product The product or variation.
	 * @param array<string, string> $tokens  Proof token by side key.
	 * @phpstan-param array{product_id: int, variation_id: int, quantity: int, attributes: array<string, string>, tokens: array<string, string>|null, expires: int} $visit
	 *
	 * @return string Where to send the customer.
	 */
	private function commit( string $state, array $visit, WC_Product $product, array $tokens ): string {
		$line = $this->add->commit_designed( $product, $tokens );
		if ( null === $line ) {
			if ( $this->add->retryable() ) {
				$this->visits->keep( $state, $visit, $tokens );
			}
			return $product->get_permalink();
		}
		$attributes = MovedAttributes::translated( $visit['product_id'], $visit['attributes'] );
		$key        = $this->personalised->keep( $visit['product_id'], $visit['variation_id'], $line, $visit['quantity'], $attributes );
		return null === $key ? $product->get_permalink() : PersonalisedDesigns::page_url(
			array(
				'key'          => $key,
				'product_id'   => $visit['product_id'],
				'variation_id' => $visit['variation_id'],
				'quantity'     => $visit['quantity'],
				'attributes'   => $attributes,
				'line'         => $line,
			)
		);
	}

	/**
	 * Lets the page show a cart line's design, as when it was first made.
	 *
	 * @param string $key The cart item's key.
	 */
	private function show_line( string $key ): void {
		$edited = CartEdit::line( $key );
		if ( null === $edited || array() === $edited->line->sides ) {
			return;
		}
		$item            = WC()->cart->get_cart()[ $key ] ?? array();
		$this->shown_key = $this->personalised->keep( $edited->product_id, $edited->variation_id, $edited->line, 1, is_array( $item['variation'] ?? null ) ? array_filter( $item['variation'], 'is_string' ) : array() );
		$this->price_as_line();
		nocache_headers();
	}

	private function unknown_visit(): string {
		self::notify( $this->labels->get( Labels::DESIGNER_VISIT_UNKNOWN ) );
		return wc_get_page_permalink( 'shop' );
	}

	/**
	 * The product a customer may buy: published, not behind a password, and
	 * for a variable product one of its variations.
	 *
	 * @param int $product_id   The product (the parent of a variation).
	 * @param int $variation_id The variation, or 0.
	 */
	private static function sellable( int $product_id, int $variation_id ): ?WC_Product {
		$parent  = wc_get_product( $product_id );
		$product = $variation_id > 0 ? wc_get_product( $variation_id ) : $parent;
		if (
			! $parent instanceof WC_Product || ! $product instanceof WC_Product
			|| ! self::on_show( $parent )
			|| ( $variation_id > 0 && $product->get_parent_id() !== $parent->get_id() )
			|| $product->is_type( 'variable' ) || ! $product->is_purchasable()
		) {
			return null;
		}
		return $product;
	}

	/**
	 * Whether customers can see the product: published and not behind a password.
	 *
	 * @param WC_Product $product The product (the parent of a variation).
	 */
	private static function on_show( WC_Product $product ): bool {
		return 'publish' === $product->get_status() && ! post_password_required( $product->get_id() );
	}

	/**
	 * Sends the customer back to a variable product they pressed the button
	 * on before choosing a variation, saying which options are still to
	 * choose, with what they chose kept in the address for the form to open on.
	 *
	 * @param WC_Product_Variable   $product    The product.
	 * @param array<string, string> $attributes The chosen `attribute_*` values.
	 *
	 * @return string Where to send the customer.
	 */
	private function ask_for_options( WC_Product_Variable $product, array $attributes ): string {
		$names   = array();
		$missing = array();
		foreach ( array_keys( $product->get_variation_attributes() ) as $attribute ) {
			$name    = wc_attribute_label( (string) $attribute, $product );
			$names[] = $name;
			if ( '' === ( $attributes[ self::ATTRIBUTE_PREFIX . sanitize_title( (string) $attribute ) ] ?? '' ) ) {
				$missing[] = $name;
			}
		}
		$label = $this->labels->get( Labels::CHOOSE_OPTIONS, array( 'product_id' => $product->get_id() ) );
		// With every option chosen and still no variation, no variation sells that choice.
		self::notify( sprintf( $label, wp_sprintf_l( '%l', array() === $missing ? $names : $missing ) ) );
		// Empty ones too: absent from the address, an option the customer cleared opens on its default.
		return add_query_arg( urlencode_deep( $attributes ), $product->get_permalink() );
	}

	/**
	 * The variation attributes the add-to-cart form posted.
	 *
	 * @return array<string, string>
	 */
	private static function posted_attributes(): array {
		$attributes = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the add-to-cart form carries no nonce.
		foreach ( wp_unslash( $_POST ) as $name => $value ) {
			if ( is_string( $name ) && str_starts_with( $name, self::ATTRIBUTE_PREFIX ) && is_string( $value ) ) {
				$attributes[ sanitize_title( $name ) ] = sanitize_text_field( $value );
			}
		}
		return $attributes;
	}

	/**
	 * Whether the product can be personalised now.
	 *
	 * @param ProductConfig $config The product's configuration.
	 */
	private function available( ProductConfig $config ): bool {
		return null !== $this->connection->current()
			&& null === $this->connection->paused()
			&& null !== DesignerLinkBody::for_config( $config, '', DesignerLanguage::ENGLISH );
	}

	/**
	 * Proof tokens by side key: a single token is the only side's.
	 *
	 * @param ProductConfig $config The product's configuration.
	 * @param mixed         $tokens What the designer returned.
	 *
	 * @return array<string, string>
	 */
	private static function tokens( ProductConfig $config, mixed $tokens ): array {
		if ( is_string( $tokens ) ) {
			return 1 === count( $config->sides ) ? array( $config->sides[0]->key => $tokens ) : array();
		}
		$by_side = array();
		foreach ( is_array( $tokens ) ? $tokens : array() as $side => $token ) {
			if ( is_string( $side ) && is_string( $token ) ) {
				$by_side[ $side ] = $token;
			}
		}
		return $by_side;
	}

	/**
	 * @param string     $label   A key from `Labels`.
	 * @param WC_Product $product The product.
	 * @param string     $back    Where to send the customer.
	 */
	private function refuse( string $label, WC_Product $product, string $back ): string {
		self::notify( $this->labels->get( $label, array( 'product_id' => $product->get_id() ) ) );
		return $back;
	}

	/**
	 * Adds an error notice for the page the customer is sent to, keeping the
	 * session of a guest with an empty cart, which would otherwise lose it.
	 *
	 * @param string $text The notice.
	 */
	private static function notify( string $text ): void {
		DesignerVisits::persist( WC()->session );
		wc_add_notice( $text, 'error' );
	}
}
