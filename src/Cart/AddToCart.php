<?php
/**
 * Commits a personalised product's designs when it is added to the cart.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Cart;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages here are reasons and log text, never printed as they are: whoever shows one escapes it.

use Closure;
use Pixonaut\WooCommerce\Admin\Notices;
use Pixonaut\WooCommerce\Api\Designs;
use Pixonaut\WooCommerce\ApprovalMode;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ProductVariant;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Connection\Connection;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use Pixonaut\WooCommerce\Labels;
use Pixonaut\WooCommerce\Milliseconds;
use Pixonaut\WooCommerce\Refs;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Storefront\PersonalisedDesigns;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\ErrorCode;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\Model\DesignsExternalRefUpdateRequest;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Pixonaut as Sdk;
use Pixonaut\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use WC_Product;

/**
 * `woocommerce_add_to_cart_validation` is the guard for every path into the
 * cart: the classic form, the Add to Cart with Options block (which falls
 * back to the form on personalised products) and the Store API. A
 * personalised product passes only with a proof token for each personalised
 * side, each committed and checked against the side's configuration, and, in
 * the checkbox approval mode, with its approval box ticked; the line's data
 * then joins the cart item through `woocommerce_add_cart_item_data`. A
 * standing side names its standing design and asks Pixonaut nothing, so a
 * product of standing sides alone adds as any other product does, on every
 * path, the Store API and order again included. A design made on the hosted
 * designer was committed as the customer came back (`commit_designed()`), and
 * kept in the customer's session (`PersonalisedDesigns`): the form posts its key
 * in place of tokens. Each add makes a line of its own: adding a design a line
 * already holds adds a copy of it (`copied()`), so two lines never hold one design.
 */
final class AddToCart {

	/**
	 * Refusals that say the design has no printshop to go to: the store
	 * cannot fix them by retrying, the merchant must in Pixonaut.
	 */
	private const ROUTING_REFUSALS = array( ErrorCode::PRINTSHOP_DIFFERS_FROM_BLANK, ErrorCode::PRINTSHOP_DISCONNECTED, ErrorCode::PRINT_NEEDS_PRINTSHOP );

	/**
	 * The form field holding the proof tokens: `pixonaut_designs[<side key>]`,
	 * or a single value for a product with one side.
	 */
	public const FIELD = 'pixonaut_designs';

	/**
	 * The form field holding the key of the kept design the page shows.
	 */
	public const KEPT_FIELD = 'pixonaut_personalised';

	/**
	 * The form field holding each side's field values as the storefront
	 * read them from the designer: `pixonaut_fields[<side key>]`, a JSON
	 * list of `FieldValue::to_array()` entries.
	 */
	public const FIELDS_FIELD = 'pixonaut_fields';

	/**
	 * The "I approve the preview" box, posted ticked in the checkbox approval mode.
	 */
	public const APPROVAL_FIELD = 'pixonaut_approve';

	/**
	 * The form field saying whether each side's preview was opened, as
	 * `pixonaut_preview_opened[<side key>]`: `1`, `0`, or empty for no answer.
	 */
	public const PREVIEW_OPENED_FIELD = 'pixonaut_preview_opened';

	/**
	 * What the preview field posts when the preview was opened.
	 */
	public const PREVIEW_OPENED = '1';

	/**
	 * What the preview field posts when the preview was not opened.
	 */
	public const PREVIEW_NOT_OPENED = '0';

	/**
	 * Notice key prefix for a product whose designs Pixonaut will not route
	 * to a printshop, followed by the product id.
	 */
	public const ROUTING_NOTICE_PREFIX = 'routing_refused_';

	/**
	 * The status of a design that is not yet approved, rejected or printed.
	 */
	private const STATUS_COMMITTED = 'committed';

	/**
	 * WooCommerce's check before any add to the cart.
	 */
	private const VALIDATION_HOOK = 'woocommerce_add_to_cart_validation';

	private readonly Closure $clock;

	/**
	 * Lines validated in this request, waiting for their cart item.
	 *
	 * @var array<string, CartLine>
	 */
	private array $pending = array();

	/**
	 * Copies made in this request that no cart item holds yet: WooCommerce or
	 * another extension may still refuse the add they were made for.
	 *
	 * @var list<array{ids: list<string>, tenant: string}>
	 */
	private array $unadded = array();

	/**
	 * Why the last add was refused, a `Labels` key; null when it was not, or
	 * when WooCommerce refused it (stock, purchasability).
	 *
	 * @var string|null
	 */
	private ?string $refused_with = null;

	/**
	 * Refusals the same proof tokens may get past when tried again: Pixonaut
	 * could not be reached, or did not answer as expected.
	 */
	private const RETRYABLE_REFUSALS = array( Labels::DESIGN_NOT_SAVED, Labels::PERSONALISATION_UNAVAILABLE );

	/**
	 * @param Connection               $connection  The store's connection.
	 * @param Designs                  $designs     Pixonaut's designs.
	 * @param Sdk                      $pixonaut Reads, copies and re-references designs.
	 * @param Settings                 $settings    The default approval mode.
	 * @param Labels                   $labels      Customer-facing text.
	 * @param Notices                  $notices     Admin notices.
	 * @param LoggerInterface          $logger      Logs refusals the customer cannot fix.
	 * @param OrderAgain               $order_again Makes the line of an order line ordered again.
	 * @param Closure|null             $clock       `fn(): int` Unix time; `time()` when null.
	 * @param CommitFailures|null      $failures    Tells the merchant when commits keep failing; null tells nobody.
	 * @param PersonalisedDesigns|null $personalised The designs waiting on product pages; null for none.
	 */
	public function __construct(
		private readonly Connection $connection,
		private readonly Designs $designs,
		private readonly Sdk $pixonaut,
		private readonly Settings $settings,
		private readonly Labels $labels,
		private readonly Notices $notices,
		private readonly LoggerInterface $logger,
		private readonly OrderAgain $order_again,
		?Closure $clock = null,
		private readonly ?CommitFailures $failures = null,
		private readonly ?PersonalisedDesigns $personalised = null,
	) {
		$this->clock = $clock ?? static fn (): int => time();
	}

	public function register(): void {
		add_filter( self::VALIDATION_HOOK, array( $this, 'validate' ), 10, 6 );
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_cart_item_data' ), 10, 3 );
		add_action( 'woocommerce_add_to_cart', array( $this, 'copy_added' ), 10, 6 );
		add_action( 'shutdown', array( $this, 'discard_unadded' ) );
	}

	/**
	 * Refuses a personalised product unless every side's design commits and
	 * matches what the product sells.
	 *
	 * @param mixed $passed       Whether earlier checks passed.
	 * @param mixed $product_id   The product (the parent of a variation).
	 * @param mixed $quantity       Quantity; unused.
	 * @param mixed $variation_id   The variation, or 0.
	 * @param mixed $variation      The variation's attributes; unused.
	 * @param mixed $cart_item_data Only the Store API and order again pass it.
	 */
	public function validate( mixed $passed, mixed $product_id, mixed $quantity = 1, mixed $variation_id = 0, mixed $variation = array(), mixed $cart_item_data = null ): bool {
		unset( $quantity, $variation );
		if ( ! $passed ) {
			return false;
		}
		$product = wc_get_product( (int) $variation_id > 0 ? (int) $variation_id : (int) $product_id );
		if ( ! $product instanceof WC_Product ) {
			return true;
		}
		$config = ProductConfig::config_for( $product );
		if ( ! $config->personalise ) {
			return true;
		}
		if ( array() === $config->personalised_sides() ) {
			try {
				$line = $this->standing_line( $product, $config );
			} catch ( AddRefused $refused ) {
				return $this->refuse( $refused->label, $product );
			}
			$reordered = OrderAgain::item_in( $cart_item_data );
			if ( null !== $reordered ) {
				$this->order_again->hold( $reordered, $line );
			}
			return true;
		}

		$mode = ApprovalMode::resolve( $config->approval_mode, $this->settings );
		if ( null !== $cart_item_data ) {
			// Order again makes its line from the order line's designs; it
			// joins the cart item there. The Store API filters the cart item
			// data before it validates, so a line committed here would never
			// reach its cart item.
			$reordered = OrderAgain::item_in( $cart_item_data );
			if ( null === $reordered ) {
				return $this->refuse( Labels::DESIGN_REQUIRED, $product );
			}
			try {
				list( $variant, $connection ) = $this->sellable( $product, $config );
				$line                         = $this->order_again->line( $reordered, $product, $config, $mode, $variant, self::standing_sides( $config, $variant ), $connection, self::cart_owner() );
			} catch ( AddRefused $refused ) {
				return $this->refuse( $refused->label, $product );
			}
		} else {
			// Sent before the form settled on the variation its design is for: WooCommerce asks for the options.
			if ( $product->is_type( 'variable' ) && '' !== self::posted_kept_key() ) {
				return true;
			}
			$waiting = $this->waiting_design( $product, $config );
			// Approving in the hosted designer is the customer's approval of the preview.
			if ( ApprovalMode::CHECKBOX === $mode && null === $waiting && ! self::approval_ticked() ) {
				return $this->refuse( Labels::APPROVAL_REQUIRED, $product );
			}
			try {
				$line = null === $waiting
					? $this->commit( $product, $config, $mode, $this->posted_tokens( $config ), self::posted_fields(), ApprovalMode::CHECKBOX === $mode ? self::posted_previews() : array() )
					: $this->checked_design( $product, $config, $mode, $waiting[0], $waiting[1] );
			} catch ( AddRefused $refused ) {
				return $this->refuse( $refused->label, $product );
			}

			// The AJAX add validates a variation by its own id, then adds it under its parent.
			$parent_id = $product->get_parent_id();
			$this->pending[ $parent_id > 0 ? self::pending_key( $parent_id, $product->get_id() ) : self::pending_key( $product->get_id(), 0 ) ] = $line;
		}

		$this->committed( $line, $product );

		return true;
	}

	/**
	 * Commits the designs the hosted designer returned proof tokens for, so
	 * they can wait on the product page; nothing joins the cart.
	 *
	 * @param WC_Product            $product The product or variation.
	 * @param array<string, string> $tokens  Proof token by side key.
	 *
	 * @return CartLine|null The line they will join the cart as; null when refused, and notices say why.
	 */
	public function commit_designed( WC_Product $product, array $tokens ): ?CartLine {
		$this->refused_with = null;
		$config             = ProductConfig::config_for( $product );
		try {
			// The hosted designer returns no field values: its line lists none.
			$line = $this->commit( $product, $config, ApprovalMode::resolve( $config->approval_mode, $this->settings ), $tokens );
		} catch ( AddRefused $refused ) {
			$this->refuse( $refused->label, $product );
			return null;
		}
		$this->committed( $line, $product );
		return $line;
	}

	/**
	 * @param CartLine   $line    The line's designs, refs and approval snapshot.
	 * @param WC_Product $product The product or variation added.
	 */
	private function committed( CartLine $line, WC_Product $product ): void {
		/**
		 * Fires when a personalised product's designs were committed and
		 * checked, before its line joins the cart; from the hosted designer,
		 * when the customer comes back, and again when they add it.
		 *
		 * @since 0.1.0
		 *
		 * @param CartLine   $line    The line's designs, refs and approval snapshot.
		 * @param WC_Product $product The product or variation added.
		 *
		 * @example
		 * add_action( 'pixonaut_wc_cart_designs_committed', function ( $line, $product ) {
		 *     error_log( sprintf( '%s: %d design(s) committed', $product->get_name(), count( $line->sides ) ) );
		 * }, 10, 2 );
		 */
		do_action( 'pixonaut_wc_cart_designs_committed', $line, $product );
	}

	/**
	 * A cart item joined the cart: a copy it holds is no longer waiting for it.
	 *
	 * @param mixed $cart_item_key  The cart item's key; unused.
	 * @param mixed $product_id     The product; unused.
	 * @param mixed $quantity       Quantity; unused.
	 * @param mixed $variation_id   The variation; unused.
	 * @param mixed $variation      The variation's attributes; unused.
	 * @param mixed $cart_item_data The cart item's data.
	 */
	public function copy_added( mixed $cart_item_key, mixed $product_id, mixed $quantity, mixed $variation_id, mixed $variation, mixed $cart_item_data ): void {
		unset( $cart_item_key, $product_id, $quantity, $variation_id, $variation );
		$line = is_array( $cart_item_data ) ? CartLine::of( $cart_item_data ) : null;
		if ( null === $line ) {
			return;
		}
		$this->unadded = array_values( array_filter( $this->unadded, static fn ( array $copy ): bool => ! $line->holds( $copy['ids'][0] ) ) );
	}

	/**
	 * Deletes the copies of adds that were refused after they were made.
	 */
	public function discard_unadded(): void {
		foreach ( $this->unadded as $copy ) {
			$this->personalised?->discard_copies( $copy['ids'], $copy['tenant'] );
		}
		$this->unadded = array();
	}

	/**
	 * The kept design the form posted the key of, while it is the design of
	 * what the form adds, unless the form brings designs of its own.
	 *
	 * @param WC_Product    $product The product or variation.
	 * @param ProductConfig $config  Its configuration.
	 *
	 * @return array{0: string, 1: CartLine}|null Its key and line.
	 */
	private function waiting_design( WC_Product $product, ProductConfig $config ): ?array {
		if ( null === $this->personalised || array() !== $this->posted_tokens( $config ) ) {
			return null;
		}
		$key    = self::posted_kept_key();
		$design = '' === $key ? null : $this->personalised->get( $key );
		$parent = $product->get_parent_id();
		$same   = null !== $design
			&& ( $parent > 0 ? $parent : $product->get_id() ) === $design['product_id']
			&& ( $parent > 0 ? $product->get_id() : 0 ) === $design['variation_id'];
		return $same ? array( $key, $design['line'] ) : null;
	}

	/**
	 * The key of the kept design the add-to-cart form posted.
	 */
	private static function posted_kept_key(): string {
		// The add-to-cart form carries no nonce; WooCommerce's own handler reads it the same way.
		return isset( $_POST[ self::KEPT_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::KEPT_FIELD ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	/**
	 * A kept design's line, once it still suits what the product sells: the
	 * same variant, workspace, sides and standing designs, and each design still
	 * matching its side. The merchant may have changed any of them since it was
	 * made, and no cart data hash protects a design outside the cart. It takes
	 * the product's approval mode now; adding it is the consent, as it is for a
	 * design from the form. One that no longer suits is forgotten. A design a
	 * line already holds joins the cart as a copy.
	 *
	 * @param WC_Product    $product The product or variation.
	 * @param ProductConfig $config  Its configuration.
	 * @param string        $mode    Its approval mode.
	 * @param string        $key     The kept design's key.
	 * @param CartLine      $line    The kept design's line.
	 *
	 * @throws AddRefused When it cannot join the cart.
	 */
	private function checked_design( WC_Product $product, ProductConfig $config, string $mode, string $key, CartLine $line ): CartLine {
		list( $variant, $connection ) = $this->sellable( $product, $config );
		$sides                        = $config->personalised_sides();
		$standing                     = self::standing_sides( $config, $variant );
		$same_sides                   = array_map( static fn ( CommittedSide $side ): string => $side->key, $line->sides ) === array_map( static fn ( Side $side ): string => $side->key, $sides );
		$same_standing                = array_map( static fn ( StandingSide $side ): array => $side->to_array(), $line->standing ) === array_map( static fn ( StandingSide $side ): array => $side->to_array(), $standing );
		if ( $line->variant !== $variant || $line->tenant_id !== $connection->tenant_id || ! $same_sides || ! $same_standing ) {
			$this->personalised?->forget( $key );
			throw new AddRefused( Labels::DESIGN_OUTDATED );
		}
		foreach ( $sides as $position => $side ) {
			$mismatch = DesignCheck::mismatch( $this->read( $line->sides[ $position ]->design_id ), $side, $config->blank_id );
			if ( null !== $mismatch ) {
				$this->personalised?->forget( $key );
				throw new AddRefused( DesignCheck::TEMPLATE_OUTDATED === $mismatch ? Labels::DESIGN_OUTDATED : Labels::DESIGN_MISMATCH );
			}
		}
		$own = $line;
		if ( null !== PersonalisedDesigns::cart_key_of( $line ) ) {
			$own             = $this->copied( $line, $connection );
			$this->unadded[] = array(
				'ids'    => array_map( static fn ( CommittedSide $side ): string => $side->design_id, $own->sides ),
				'tenant' => $own->tenant_id,
			);
		}
		return $own->with_approval( $mode, ApprovalMode::consent_in_cart( $mode, Milliseconds::of( ( $this->clock )() ) ) );
	}

	/**
	 * A line of its own for a design a cart line holds: a copy of each side's
	 * design under a new ref, so each line is corrected and removed alone. It
	 * keeps the key that committed its source, as a line ordered again does.
	 *
	 * @param CartLine         $line       The line holding the designs.
	 * @param StoredConnection $connection The store's connection.
	 *
	 * @throws AddRefused When Pixonaut would not copy them.
	 */
	private function copied( CartLine $line, StoredConnection $connection ): CartLine {
		$copies = array();
		$sides  = array();
		try {
			foreach ( $line->sides as $side ) {
				$copy                 = Designs::id( 'designs.duplicate', $this->pixonaut->designs()->duplicate( $side->design_id )->designId );
				$copies[ $side->key ] = $copy;
				$design               = $this->read( $copy );
				$sides[]              = new CommittedSide(
					$side->key,
					$copy,
					is_int( $design['revision'] ?? null ) ? $design['revision'] : 0,
					$side->template_base_id,
					$side->template_version,
					$side->commit_key,
					is_string( $design[ Designs::PROOF_ID ] ?? null ) && '' !== $design[ Designs::PROOF_ID ] ? $design[ Designs::PROOF_ID ] : null,
					$side->fields
				);
			}
			$line_key = Refs::line_key( $copies, self::cart_owner() );
			$ref      = Refs::cart_ref( $connection->site, $line_key );
			foreach ( $copies as $copy ) {
				$this->pixonaut->designs()->updateExternalRef( $copy, new DesignsExternalRefUpdateRequest( externalRef: $ref ) );
			}
		} catch ( ApiException $error ) {
			$this->logger->warning( sprintf( 'Could not copy a design to add it again: %s', $error->getMessage() ), array( 'code' => $error->errorCode ) );
			$this->personalised?->discard_copies( array_values( $copies ), $line->tenant_id );
			throw new AddRefused( Labels::DESIGN_NOT_SAVED );
		} catch ( AddRefused $refused ) {
			$this->personalised?->discard_copies( array_values( $copies ), $line->tenant_id );
			throw $refused;
		}
		return new CartLine( $line_key, $ref, $line->tenant_id, $line->approval_mode, $line->consent, $sides, $line->variant, $line->standing );
	}

	/**
	 * Whether the last refused commit may succeed with the same proof tokens:
	 * Pixonaut could not be reached; never after the designs were found not
	 * to match and deleted.
	 */
	public function retryable(): bool {
		return null === $this->refused_with || in_array( $this->refused_with, self::RETRYABLE_REFUSALS, true );
	}

	/**
	 * Tells the customer why the product did not join the cart.
	 *
	 * @param string     $label   A key from `Labels`.
	 * @param WC_Product $product The product or variation.
	 *
	 * @return false
	 */
	private function refuse( string $label, WC_Product $product ): bool {
		$this->refused_with = $label;
		wc_add_notice( $this->labels->get( $label, array( 'product_id' => $product->get_id() ) ), 'error' );
		return false;
	}

	/**
	 * Adds the validated line's data to its cart item.
	 *
	 * @param mixed $data         Cart item data.
	 * @param mixed $product_id   The product (the parent of a variation).
	 * @param mixed $variation_id The variation, or 0.
	 *
	 * @return array<string, mixed>
	 */
	public function add_cart_item_data( mixed $data, mixed $product_id, mixed $variation_id = 0 ): array {
		$data = is_array( $data ) ? $data : array();
		$key  = self::pending_key( (int) $product_id, (int) $variation_id );
		$line = $this->pending[ $key ] ?? $this->standing_line_of( (int) $variation_id > 0 ? (int) $variation_id : (int) $product_id );
		unset( $this->pending[ $key ] );
		if ( null === $line ) {
			return $data;
		}

		$data[ CartLine::KEY ] = self::line_data( $line, (int) $product_id, (int) $variation_id );
		return $data;
	}

	/**
	 * The data a cart item keeps for its line, filtered.
	 *
	 * @param CartLine $line         The line.
	 * @param int      $product_id   The product (the parent of a variation).
	 * @param int      $variation_id The variation, or 0.
	 *
	 * @return mixed
	 */
	public static function line_data( CartLine $line, int $product_id, int $variation_id ): mixed {
		/**
		 * Filters the Pixonaut data stored on a personalised cart item.
		 *
		 * It reaches the order line at checkout; data that no longer reads
		 * as a line (see `CartLine::from_array()`) leaves the order line
		 * untracked.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string, mixed> $line_data    `CartLine::to_array()`.
		 * @param int                  $product_id   The product (the parent of a variation).
		 * @param int                  $variation_id The variation, or 0.
		 *
		 * @example
		 * add_filter( 'pixonaut_wc_cart_item_data', function ( $line_data ) {
		 *     $line_data['consent']['at'] = time() * 1000;
		 *     return $line_data;
		 * } );
		 */
		return apply_filters( 'pixonaut_wc_cart_item_data', $line->to_array(), $product_id, $variation_id );
	}

	/**
	 * The line of a product whose sides are all standing, which the cart
	 * item takes when no validated line waits for it: on the Store API's
	 * path and order again, the cart item data comes before the validation.
	 * Null for any other product, or one that cannot be sold now, which the
	 * validation refuses.
	 *
	 * @param int $product_id The product, or the variation when one is added.
	 */
	private function standing_line_of( int $product_id ): ?CartLine {
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			return null;
		}
		$config = ProductConfig::config_for( $product );
		if ( ! $config->personalise || array() !== $config->personalised_sides() ) {
			return null;
		}
		try {
			return $this->standing_line( $product, $config );
		} catch ( AddRefused $refused ) {
			return null;
		}
	}

	/**
	 * The line of a product whose sides are all standing: nothing is
	 * committed, and Pixonaut is not asked. No approval is asked for either:
	 * the standing designs were approved when the product was saved. The
	 * same designs make the same line key, so adding again adds to the line.
	 *
	 * @param WC_Product    $product The product or variation.
	 * @param ProductConfig $config  Its configuration.
	 *
	 * @throws AddRefused When it cannot be sold now.
	 */
	private function standing_line( WC_Product $product, ProductConfig $config ): CartLine {
		list( $variant, $connection ) = $this->sellable( $product, $config );
		$standing                     = self::standing_sides( $config, $variant );
		$designs                      = array();
		foreach ( $standing as $side ) {
			$designs[ $side->key ] = $side->design_id;
		}
		$line_key = Refs::line_key( $designs, self::cart_owner() );
		return new CartLine( $line_key, Refs::cart_ref( $connection->site, $line_key ), $connection->tenant_id, ApprovalMode::ADD_TO_CART, null, array(), $variant, $standing );
	}

	/**
	 * The variant a product sells and the connection to sell it under.
	 *
	 * @param WC_Product    $product The product or variation.
	 * @param ProductConfig $config  Its configuration.
	 * @return array{0: string|null, 1: StoredConnection}
	 *
	 * @throws AddRefused When it cannot be sold now.
	 */
	private function sellable( WC_Product $product, ProductConfig $config ): array {
		if ( ! $config->valid || array() === $config->sides ) {
			throw new AddRefused( Labels::PERSONALISATION_UNAVAILABLE );
		}
		$variant = ProductVariant::sold_by( $product, $config );
		if ( null !== $config->blank_id && null === $variant ) {
			// No variant of the blank, or a retired one: Pixonaut would refuse the order.
			throw new AddRefused( Labels::PERSONALISATION_UNAVAILABLE );
		}
		$connection = $this->connection->current();
		if ( null === $connection || null !== $this->connection->paused() ) {
			throw new AddRefused( Labels::PERSONALISATION_UNAVAILABLE );
		}
		return array( $variant, $connection );
	}

	/**
	 * Each standing side with its design and its place among the product's
	 * sides: on a blank whose print areas vary, the design of the row the
	 * variant sold is in.
	 *
	 * @param ProductConfig $config  The product's configuration.
	 * @param string|null   $variant The blank variant sold; null without a blank.
	 * @return list<StandingSide>
	 *
	 * @throws AddRefused When a standing side has no design yet, which only a save can find.
	 */
	private static function standing_sides( ProductConfig $config, ?string $variant ): array {
		$standing = array();
		foreach ( $config->sides as $position => $side ) {
			if ( ! $side->is_standing() ) {
				continue;
			}
			$design_id = $side->standing_design_for( $variant );
			if ( null === $design_id ) {
				throw new AddRefused( Labels::PERSONALISATION_UNAVAILABLE );
			}
			$standing[] = new StandingSide( $side->key, $design_id, $position );
		}
		return $standing;
	}

	/**
	 * @param WC_Product            $product The product or variation.
	 * @param ProductConfig         $config  Its configuration.
	 * @param string                $mode    Its approval mode.
	 * @param array<string, string> $tokens  Proof token by personalised side key.
	 * @param array<string, array>  $fields  Field values by personalised side key.
	 * @param array<string, bool>   $previews Whether each side's preview was opened before the approval box was ticked, by side key; none where no box asked.
	 * @phpstan-param array<string, list<FieldValue>> $fields
	 *
	 * @throws AddRefused When the line cannot join the cart.
	 */
	private function commit( WC_Product $product, ProductConfig $config, string $mode, array $tokens, array $fields = array(), array $previews = array() ): CartLine {
		list( $variant, $connection ) = $this->sellable( $product, $config );
		$standing                     = self::standing_sides( $config, $variant );
		$ordered                      = array();
		foreach ( $config->personalised_sides() as $side ) {
			$ordered[ $side->key ] = $tokens[ $side->key ] ?? '';
			if ( '' === $ordered[ $side->key ] ) {
				throw new AddRefused( Labels::DESIGN_REQUIRED );
			}
		}

		$site     = $connection->site;
		$line_key = Refs::line_key( $ordered, self::cart_owner() );
		$ref      = Refs::cart_ref( $site, $line_key );
		$ours     = array();
		$sides    = array();
		try {
			foreach ( $config->personalised_sides() as $side ) {
				$opened     = $previews[ $side->key ] ?? null;
				$commit_key = Refs::commit_key( $site, $ordered[ $side->key ], $opened );
				$design_id  = $this->commit_side( $product, $ordered[ $side->key ], $ref, $commit_key, self::fulfilment_payload( $side ), $opened );
				$design     = $this->read( $design_id );
				if ( $this->held_elsewhere( $design_id, $design, $ref ) ) {
					throw new AddRefused( Labels::DESIGN_ALREADY_USED );
				}
				$ours[]   = $design_id;
				$mismatch = DesignCheck::mismatch( $design, $side, $config->blank_id );
				if ( null !== $mismatch ) {
					$this->logger->warning(
						sprintf( 'Refused a design for product %d: its %s does not match side "%s".', $product->get_id(), $mismatch, $side->key ),
						array(
							'design_id' => $design_id,
							'mismatch'  => $mismatch,
						)
					);
					throw new AddRefused( DesignCheck::TEMPLATE_OUTDATED === $mismatch ? Labels::DESIGN_OUTDATED : Labels::DESIGN_MISMATCH, true );
				}
				$sides[] = new CommittedSide(
					$side->key,
					$design_id,
					is_int( $design['revision'] ?? null ) ? $design['revision'] : 0,
					$side->template_base_id,
					(int) $design[ Designs::TEMPLATE_VERSION ],
					$commit_key,
					is_string( $design[ Designs::PROOF_ID ] ?? null ) && '' !== $design[ Designs::PROOF_ID ] ? $design[ Designs::PROOF_ID ] : null,
					$fields[ $side->key ] ?? array()
				);
			}
		} catch ( AddRefused $refused ) {
			// Only a mismatch condemns the line; after any other refusal the
			// same tokens replay these designs on the next try.
			if ( $refused->discard_designs ) {
				$this->delete( $ours );
			}
			throw $refused;
		}
		// Every side committed: one side replayed from an earlier try while another failed is no recovery.
		$this->failures?->succeeded();

		// Adding to the cart, or the box ticked for it, is the consent; proof by email takes it later.
		$consent = ApprovalMode::consent_in_cart( $mode, Milliseconds::of( ( $this->clock )() ) );

		return new CartLine( $line_key, $ref, $connection->tenant_id, $mode, $consent, $sides, $variant, $standing );
	}

	/**
	 * The fulfilment keys a side's commit carries: its placement, when it has
	 * one. The recipient waits for the order.
	 *
	 * @param Side $side The side.
	 *
	 * @return array<string, string>
	 */
	private static function fulfilment_payload( Side $side ): array {
		return null === $side->placement_id ? array() : array( 'placement' => $side->placement_id );
	}

	/**
	 * @param WC_Product            $product            The product or variation.
	 * @param string                $proof_token        The side's proof token.
	 * @param string                $ref                The line's cart ref.
	 * @param string                $commit_key         The commit's idempotency key.
	 * @param array<string, string> $fulfilment_payload Fulfilment keys known at commit.
	 * @param bool|null             $preview_opened     What the design records of its preview; null where no box asked.
	 *
	 * @throws AddRefused When Pixonaut refused the commit.
	 */
	private function commit_side( WC_Product $product, string $proof_token, string $ref, string $commit_key, array $fulfilment_payload, ?bool $preview_opened ): string {
		try {
			return $this->designs->commit( $proof_token, $ref, $commit_key, $fulfilment_payload, $preview_opened );
		} catch ( ApiException $error ) {
			if ( in_array( $error->errorCode, self::ROUTING_REFUSALS, true ) ) {
				$this->notice_routing( $product, $error->errorCode );
				throw new AddRefused( Labels::PERSONALISATION_UNAVAILABLE );
			}
			// Within a day of the first commit, the same proof sent from another cart reuses its key with another ref.
			if ( ErrorCode::IDEMPOTENCY_KEY_REUSED === $error->errorCode ) {
				throw new AddRefused( Labels::DESIGN_ALREADY_USED );
			}
			if ( ErrorCode::PROOF_EXPIRED === $error->errorCode ) {
				throw new AddRefused( Labels::DESIGN_EXPIRED );
			}
			// The connection's own notice tells the merchant.
			if ( in_array( $error->errorCode, array( ApiException::NOT_CONNECTED, ErrorCode::UNAUTHORIZED ), true ) ) {
				throw new AddRefused( Labels::PERSONALISATION_UNAVAILABLE );
			}
			$this->failures?->failed( $error );
			if ( ErrorCode::WORKSPACE_SUSPENDED === $error->errorCode ) {
				throw new AddRefused( Labels::PERSONALISATION_UNAVAILABLE );
			}
			$this->logger->warning( sprintf( 'Could not commit a design for product %d: %s', $product->get_id(), $error->getMessage() ), array( 'code' => $error->errorCode ) );
			throw new AddRefused( Labels::DESIGN_NOT_SAVED );
		}
	}

	/**
	 * @param string $design_id Design id.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws AddRefused When the design cannot be read; it is left alone, as
	 *                    nothing says whose it is.
	 */
	private function read( string $design_id ): array {
		try {
			return $this->pixonaut->designs()->get( $design_id )->raw();
		} catch ( ApiException $error ) {
			$this->logger->warning( sprintf( 'Could not read design %s after committing it: %s', $design_id, $error->getMessage() ), array( 'code' => $error->errorCode ) );
			throw new AddRefused( Labels::DESIGN_NOT_SAVED );
		}
	}

	/**
	 * Whether another line has the design: one in this cart, or one whose
	 * ref it carries (another cart, or an order). Such a design is never
	 * deleted here.
	 *
	 * @param string               $design_id Design id.
	 * @param array<string, mixed> $design    The design.
	 * @param string               $ref       This line's cart ref.
	 */
	private function held_elsewhere( string $design_id, array $design, string $ref ): bool {
		$held_ref = $design[ Designs::EXTERNAL_REF ] ?? null;
		if ( ( is_string( $held_ref ) && $held_ref !== $ref ) || self::STATUS_COMMITTED !== ( $design['status'] ?? null ) ) {
			return true;
		}
		$cart = WC()->cart;
		// Typed as always set, but null until WooCommerce loads the cart (cron, some REST requests).
		if ( null === $cart ) { // @phpstan-ignore identical.alwaysFalse
			return false;
		}
		foreach ( $cart->get_cart() as $item ) {
			if ( CartLine::of( $item )?->holds( $design_id ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param string[] $design_ids Designs this add committed.
	 */
	private function delete( array $design_ids ): void {
		foreach ( $design_ids as $design_id ) {
			try {
				$this->designs->delete( $design_id );
			} catch ( ApiException $error ) {
				$this->logger->warning( sprintf( 'Could not delete refused design %s: %s', $design_id, $error->getMessage() ), array( 'code' => $error->errorCode ) );
			}
		}
	}

	private function notice_routing( WC_Product $product, string $code ): void {
		$product_id = $product->get_parent_id() > 0 ? $product->get_parent_id() : $product->get_id();
		$this->notices->add(
			self::ROUTING_NOTICE_PREFIX . $product_id,
			sprintf(
				/* translators: 1: product name, 2: Pixonaut's refusal code */
				__( 'Customers cannot add "%1$s" to the cart: Pixonaut will not route its designs to a printshop (%2$s). Check the printshop of the product\'s blank in the Pixonaut studio.', 'pixonaut-for-woocommerce' ),
				esc_html( $product->get_name() ),
				esc_html( $code )
			),
			'error'
		);
	}

	/**
	 * The proof tokens the add-to-cart form posted, by side key.
	 *
	 * @param ProductConfig $config The product's configuration.
	 *
	 * @return array<string, string>
	 */
	private function posted_tokens( ProductConfig $config ): array {
		// The add-to-cart form carries no nonce; WooCommerce's own handler reads it the same way.
		$posted   = isset( $_POST[ self::FIELD ] ) ? wp_unslash( $_POST[ self::FIELD ] ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per token below.
		$designed = $config->personalised_sides();
		if ( is_string( $posted ) && 1 === count( $designed ) ) {
			$posted = array( $designed[0]->key => $posted );
		}
		$tokens = array();
		foreach ( is_array( $posted ) ? $posted : array() as $side => $token ) {
			if ( is_string( $side ) && is_string( $token ) ) {
				$tokens[ $side ] = sanitize_text_field( $token );
			}
		}
		return $tokens;
	}

	/**
	 * What the add-to-cart form posted of each side's preview, by side key: a
	 * side it gives no answer for, as a form from a page printed before this
	 * field gives none, is left out, and its design says nothing.
	 *
	 * @return array<string, bool>
	 */
	private static function posted_previews(): array {
		$posted   = isset( $_POST[ self::PREVIEW_OPENED_FIELD ] ) ? wp_unslash( $_POST[ self::PREVIEW_OPENED_FIELD ] ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only compared below.
		$previews = array();
		foreach ( is_array( $posted ) ? $posted : array() as $side => $answer ) {
			if ( is_string( $side ) && in_array( $answer, array( self::PREVIEW_OPENED, self::PREVIEW_NOT_OPENED ), true ) ) {
				$previews[ $side ] = self::PREVIEW_OPENED === $answer;
			}
		}
		return $previews;
	}

	/**
	 * The field values the add-to-cart form posted, by side key.
	 *
	 * @return array<string, list<FieldValue>>
	 */
	private static function posted_fields(): array {
		// The add-to-cart form carries no nonce; WooCommerce's own handler reads it the same way.
		$posted = isset( $_POST[ self::FIELDS_FIELD ] ) ? wp_unslash( $_POST[ self::FIELDS_FIELD ] ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- FieldValue cleans each value; it is escaped where shown.
		$fields = array();
		foreach ( is_array( $posted ) ? $posted : array() as $side => $json ) {
			if ( is_string( $side ) ) {
				$fields[ $side ] = FieldValue::list_from_json( $json );
			}
		}
		return $fields;
	}

	/**
	 * Whether the add-to-cart form posted the approval box ticked.
	 */
	private static function approval_ticked(): bool {
		// The add-to-cart form carries no nonce; WooCommerce's own handler reads it the same way.
		return isset( $_POST[ self::APPROVAL_FIELD ] ) && '' !== sanitize_text_field( wp_unslash( $_POST[ self::APPROVAL_FIELD ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Who the cart belongs to: the WooCommerce session's customer, so the
	 * same tokens sent from another session make another line.
	 */
	private static function cart_owner(): string {
		$session = WC()->session;
		// Typed as always set, but null until WooCommerce starts a session.
		return null === $session ? '' : (string) $session->get_customer_id(); // @phpstan-ignore identical.alwaysFalse
	}

	private static function pending_key( int $product_id, int $variation_id ): string {
		return $product_id . ':' . $variation_id;
	}
}
