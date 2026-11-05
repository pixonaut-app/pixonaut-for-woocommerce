<?php
/**
 * Editing a personalised cart line's design on its product page.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Cart;

use Closure;
use Pixonaut\WooCommerce\Api\ApiError;
use Pixonaut\WooCommerce\Api\Designs;
use Pixonaut\WooCommerce\Api\Logger;
use Pixonaut\WooCommerce\ApprovalMode;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Connection\Connection;
use Pixonaut\WooCommerce\Labels;
use Pixonaut\WooCommerce\Layout;
use Pixonaut\WooCommerce\Milliseconds;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Storefront\CorrectionMount;
use Pixonaut\WooCommerce\Storefront\PersonaliseButton;
use WC_Product;
use WC_Product_Variation;

/**
 * A personalised line's product link, in the classic cart, the Cart block
 * and the Mini-Cart, goes to its product page with `?pixonaut_edit=<cart
 * item key>` (the link is left out for a product hidden from the
 * catalogue). A line just added from the product page's form lands the
 * customer on that page, unless the store sends them to the cart after
 * adding. A product on the Personalise button layout links the line to its
 * product page, which shows the line's design and what it is in the cart as,
 * and offers to change it on Pixonaut's hosted correction page
 * (`PersonaliseButton`). On the inline layout's product page
 * each mounted side opens on its committed design through a
 * correction link minted for it, the variation and print size stay those
 * of the line, and the customer's update is saved as the design's next
 * revision (`update()`).
 */
final class CartEdit {

	/**
	 * The query argument naming the cart line being edited.
	 */
	public const PARAM = 'pixonaut_edit';

	private readonly Closure $clock;

	private readonly CorrectionMount $correction;

	/**
	 * Mount options of the line being edited, by cart item key and side keys, minted once a request.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $minted = array();

	/**
	 * The key of the personalised line this request added, for the redirect after it; null when none was.
	 *
	 * @var string|null
	 */
	private ?string $added = null;

	/**
	 * @param Connection   $connection The store's connection.
	 * @param Designs      $designs    Pixonaut's designs.
	 * @param Settings     $settings   The default layout and the designer's language.
	 * @param Labels       $labels     Customer-facing text.
	 * @param Logger       $logger     Logs links and updates Pixonaut refused.
	 * @param Closure|null $clock      `fn(): int` Unix time; `time()` when null.
	 */
	public function __construct(
		Connection $connection,
		private readonly Designs $designs,
		private readonly Settings $settings,
		private readonly Labels $labels,
		private readonly Logger $logger,
		?Closure $clock = null,
	) {
		$this->clock      = $clock ?? static fn (): int => time();
		$this->correction = new CorrectionMount( $connection, $designs, $logger, $settings );
	}

	public function register(): void {
		add_filter( 'woocommerce_cart_item_permalink', array( $this, 'permalink' ), 10, 3 );
		add_filter( 'woocommerce_product_single_add_to_cart_text', array( $this, 'button_text' ), 10, 2 );
		add_action( 'woocommerce_add_to_cart', array( $this, 'added' ), 10, 6 );
		add_filter( 'woocommerce_add_to_cart_redirect', array( $this, 'redirect' ) );
	}

	/**
	 * Remembers the personalised line an add put in the cart.
	 *
	 * @param mixed $cart_item_key  The cart item's key.
	 * @param mixed $product_id     Unused.
	 * @param mixed $quantity       Unused.
	 * @param mixed $variation_id   Unused.
	 * @param mixed $variation      Unused.
	 * @param mixed $cart_item_data The item's data.
	 */
	public function added( mixed $cart_item_key, mixed $product_id = 0, mixed $quantity = 1, mixed $variation_id = 0, mixed $variation = array(), mixed $cart_item_data = array() ): void {
		unset( $product_id, $quantity, $variation_id, $variation );
		if ( is_string( $cart_item_key ) && is_array( $cart_item_data ) && null !== CartLine::of( $cart_item_data ) ) {
			$this->added = $cart_item_key;
		}
	}

	/**
	 * Sends the customer to the page of the line the add-to-cart form just
	 * added, where WooCommerce would leave them on the product page.
	 * A store that sends customers to the cart after adding keeps doing so.
	 *
	 * @param mixed $url Where WooCommerce sends the customer; empty to stay.
	 */
	public function redirect( mixed $url ): mixed {
		// WooCommerce reads its own setting only when the filter leaves the URL empty.
		if ( ( is_string( $url ) && '' !== $url ) || null === $this->added || 'yes' === get_option( 'woocommerce_cart_redirect_after_add' ) ) {
			return $url;
		}
		$cart = WC()->cart;
		// Typed as always set, but null until WooCommerce loads the cart.
		$item = null === $cart ? array() : $cart->get_cart_item( $this->added ); // @phpstan-ignore identical.alwaysFalse
		if ( array() === $item ) {
			return $url;
		}
		return match ( $this->layout( $item ) ) {
			Layout::INLINE => $this->url( $item ) ?? $url,
			Layout::BUTTON => self::line_page( $item, $this->added ) ?? $url,
			default        => $url,
		};
	}

	/**
	 * Sends a personalised line's product link to its edit page, or for a
	 * product on the Personalise button layout, to its product page showing
	 * the line's design.
	 *
	 * @param mixed $permalink     The product's link; empty when the product is hidden.
	 * @param mixed $cart_item     The cart item.
	 * @param mixed $cart_item_key Its key.
	 */
	public function permalink( mixed $permalink, mixed $cart_item, mixed $cart_item_key = '' ): mixed {
		if ( ! is_string( $permalink ) || '' === $permalink || ! is_array( $cart_item ) || ! is_string( $cart_item_key ) || '' === $cart_item_key ) {
			return $permalink;
		}
		return match ( $this->layout( $cart_item ) ) {
			Layout::INLINE => add_query_arg( self::PARAM, rawurlencode( $cart_item_key ), $permalink ),
			Layout::BUTTON => self::line_page( $cart_item, $cart_item_key ) ?? $permalink,
			default        => $permalink,
		};
	}

	/**
	 * The page that edits a cart line's design, or null when the line cannot be edited.
	 *
	 * @param array<string, mixed> $cart_item The cart item, with its `key`.
	 */
	public function url( array $cart_item ): ?string {
		$line    = CartLine::of( $cart_item );
		$product = $cart_item['data'] ?? null;
		$key     = $cart_item['key'] ?? null;
		if ( null === $line || array() === $line->sides || ! $product instanceof WC_Product || ! is_string( $key ) || '' === $key || Layout::INLINE !== $this->layout( $cart_item ) ) {
			return null;
		}
		return add_query_arg( self::PARAM, rawurlencode( $key ), self::permalink_of( $product, $cart_item ) );
	}

	/**
	 * The product page of a line on the Personalise button layout, showing
	 * its design and what it is in the cart as, or null when it has no design to show.
	 *
	 * @param array<string, mixed> $cart_item The cart item.
	 * @param string               $key       Its key.
	 */
	public static function line_page( array $cart_item, string $key ): ?string {
		$line    = CartLine::of( $cart_item );
		$product = $cart_item['data'] ?? null;
		if ( null === $line || array() === $line->sides || ! $product instanceof WC_Product ) {
			return null;
		}
		return add_query_arg( PersonaliseButton::LINE_ARG, rawurlencode( $key ), self::permalink_of( $product, $cart_item ) );
	}

	/**
	 * A line's product page: a variation's selects the line's attributes,
	 * those it allows any value for included.
	 *
	 * @param WC_Product           $product   The line's product or variation.
	 * @param array<string, mixed> $cart_item The cart item.
	 */
	private static function permalink_of( WC_Product $product, array $cart_item ): string {
		return $product instanceof WC_Product_Variation ? $product->get_permalink( $cart_item ) : $product->get_permalink();
	}

	/**
	 * The product page's add-to-cart button reads "Update design" while it edits a line.
	 *
	 * @param mixed $text    The button's text.
	 * @param mixed $product The product.
	 */
	public function button_text( mixed $text, mixed $product = null ): mixed {
		return $product instanceof WC_Product && null !== $this->line_for( $product )
			? $this->labels->get( Labels::UPDATE_DESIGN, array( 'product_id' => $product->get_id() ) )
			: $text;
	}

	/**
	 * The cart line this request edits on the page of `$product`, or null
	 * when it edits none of that product's.
	 *
	 * @param WC_Product $product The product the page shows.
	 */
	public function line_for( WC_Product $product ): ?EditedLine {
		// A link from the customer's own cart; it changes nothing by itself.
		$key    = isset( $_GET[ self::PARAM ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::PARAM ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$edited = '' === $key ? null : self::line( $key );
		return null !== $edited && $edited->product_id === $product->get_id() ? $edited : null;
	}

	/**
	 * Each mounted side's correction mount options, minted once a request;
	 * empty when the line cannot be edited now.
	 *
	 * @param EditedLine $edited  The line.
	 * @param WC_Product $product The product the page shows.
	 * @param string[]   $sides   The side keys the page mounts.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function mounts( EditedLine $edited, WC_Product $product, array $sides ): array {
		$minted = $edited->key . ':' . implode( ',', $sides );
		if ( isset( $this->minted[ $minted ] ) ) {
			return $this->minted[ $minted ];
		}
		$variation = $edited->variation_id > 0 ? wc_get_product( $edited->variation_id ) : null;
		$bought    = $variation instanceof WC_Product ? $variation : null;
		$config    = ProductConfig::config_for( $product, $bought );
		$mounts    = array();
		foreach ( $sides as $side_key ) {
			$side    = $edited->line->side( $side_key );
			$options = null === $side ? null : $this->correction->options( $side->design_id, $product, $side_key, $config, $bought );
			if ( null === $options ) {
				$mounts = array();
				break;
			}
			$mounts[ $side_key ] = $options;
		}
		$this->minted[ $minted ] = $mounts;
		return $mounts;
	}

	/**
	 * Saves a side's corrected design on the line: Pixonaut makes the
	 * correction's proof the design's next revision, and the line takes that
	 * revision, its proof and the consent the update gave.
	 *
	 * @param string $key         The cart item's key.
	 * @param string $side_key    The side corrected.
	 * @param string $proof_token The correction's proof token.
	 * @param bool   $approved    Whether the approval box was ticked, for the checkbox approval mode.
	 * @param array  $fields      The design's field values now; none from the hosted designer.
	 * @phpstan-param list<FieldValue> $fields
	 *
	 * @throws EditRefused When the update is refused.
	 */
	public function update( string $key, string $side_key, string $proof_token, bool $approved, array $fields = array() ): CartLine {
		$edited = self::line( $key );
		$side   = $edited?->line->side( $side_key );
		if ( null === $edited || null === $side ) {
			throw new EditRefused( EditRefused::LINE_GONE );
		}
		if ( ApprovalMode::CHECKBOX === $edited->line->approval_mode && ! $approved ) {
			throw new EditRefused( EditRefused::APPROVAL_REQUIRED );
		}
		$line = $this->corrected( $edited->line, $side, $proof_token, $fields );
		$cart = WC()->cart;
		$cart->cart_contents[ $key ][ CartLine::KEY ] = $line->to_array();
		$cart->set_session();

		/**
		 * Fires when a customer updated a design in their cart and the line
		 * took the design's new revision.
		 *
		 * @since 0.1.0
		 *
		 * @param CartLine $line     The line, with the side's new revision and proof and the consent the update gave.
		 * @param string   $key      The cart item's key.
		 * @param string   $side_key The side updated.
		 *
		 * @example
		 * add_action( 'pixonaut_wc_cart_design_updated', function ( $line, $key, $side_key ) {
		 *     error_log( sprintf( 'Cart line %s: side %s updated', $key, $side_key ) );
		 * }, 10, 3 );
		 */
		do_action( 'pixonaut_wc_cart_design_updated', $line, $key, $side_key );

		return $line;
	}

	/**
	 * Saves a side's corrected design in Pixonaut: its correction's proof
	 * becomes the design's next revision. Updating the design is the consent,
	 * as adding it was; proof by email takes it later.
	 *
	 * @param CartLine      $line        The line holding the design.
	 * @param CommittedSide $side        The side corrected.
	 * @param string        $proof_token The correction's proof token.
	 * @param array         $fields      The design's field values now; none from the hosted designer.
	 * @phpstan-param list<FieldValue> $fields
	 *
	 * @return CartLine The line with the side's new revision and proof and the consent given.
	 *
	 * @throws EditRefused When the correction is refused.
	 */
	public function corrected( CartLine $line, CommittedSide $side, string $proof_token, array $fields = array() ): CartLine {
		if ( ! $this->correction->available() ) {
			throw new EditRefused( EditRefused::UNAVAILABLE );
		}
		try {
			$corrected = $this->designs->correct( $side->design_id, $proof_token );
		} catch ( ApiError $error ) {
			$refusal = EditRefused::for_error( $error->error_code );
			if ( EditRefused::NOT_SAVED === $refusal->reason ) {
				$this->logger->warning( sprintf( 'Could not save the correction of design %s: %s', $side->design_id, $error->getMessage() ), array( 'code' => $error->error_code ) );
			}
			throw $refusal;
		}
		return $line->with_side(
			$side->corrected( $corrected['revision'], $corrected['proofId'], $fields ),
			ApprovalMode::consent_in_cart( $line->approval_mode, Milliseconds::of( ( $this->clock )() ) )
		);
	}

	/**
	 * The layout a personalised line's product personalises in now (a
	 * variation's own when it overrides its product's), or null when the
	 * line is not personalised or its product no longer is.
	 *
	 * @param array<string, mixed> $cart_item The cart item.
	 */
	private function layout( array $cart_item ): ?string {
		$product = $cart_item['data'] ?? null;
		if ( null === CartLine::of( $cart_item ) || ! $product instanceof WC_Product ) {
			return null;
		}
		// A line of standing sides alone has nothing to edit.
		$config = ProductConfig::config_for( $product )->for_designer();
		return $config->personalise ? Layout::resolve( $config, $this->settings ) : null;
	}

	/**
	 * The personalised line under a key in the customer's cart.
	 *
	 * @param string $key The cart item's key.
	 */
	public static function line( string $key ): ?EditedLine {
		$cart = WC()->cart;
		// Typed as always set, but null until WooCommerce loads the cart.
		if ( null === $cart ) { // @phpstan-ignore identical.alwaysFalse
			return null;
		}
		// `get_cart()` loads the cart from the session, which a REST request has not done yet.
		$item = $cart->get_cart()[ $key ] ?? array();
		$line = CartLine::of( $item );
		return null === $line ? null : new EditedLine( $key, (int) ( $item['product_id'] ?? 0 ), $line, (int) ( $item['variation_id'] ?? 0 ) );
	}
}
