<?php
/**
 * Corrections of a Personalise-button product's design on Pixonaut's
 * hosted correction page.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use Pixonaut\WooCommerce\Approval\OrderEdit;
use Pixonaut\WooCommerce\Cart\CartEdit;
use Pixonaut\WooCommerce\Cart\CorrectionTrips;
use Pixonaut\WooCommerce\Cart\EditRefused;
use Pixonaut\WooCommerce\Labels;
use WC_Session_Handler;

/**
 * A product on the Personalise button layout has no designer on its page,
 * so its design is corrected on Pixonaut's hosted correction page, the way
 * it was made on the hosted designer. Two trips go there:
 *
 * - From the product page: the note that the design is the customer's, or that
 *   it is in the cart, has a "change" button (one per side), which comes here with the design's key and
 *   side. The plugin keeps a single-use `state` for them in the customer's
 *   session (`CorrectionTrips`), mints a correction link returning to the
 *   store with it, and sends the customer there. Coming back with a
 *   `proofToken`, the design takes the correction, and a cart line holding it
 *   does too, as an update from the inline layout does (`CartEdit::update()`).
 *   When Pixonaut could not be reached, the trip is kept with its proof token,
 *   and the side's link tries saving it again instead of opening the hosted page.
 * - After asking for changes to a proof: the proof page's return comes here
 *   with the `state` the store signed for the order line, which travels to
 *   the hosted page and back as it is, and so opens it again until the line
 *   holds another revision. Coming back with a `proofToken`, the change is
 *   saved and approved with consent `edit` (`OrderEdit::save()`).
 *
 * Approving on the hosted page stands in for the approval box. Cancelling
 * returns to the product page, or to the order. Both ends are handled at
 * `wp_loaded`, where the customer's session and cart are loaded; the
 * return is a cross-site GET without a REST nonce.
 *
 * @phpstan-import-type Trip from CorrectionTrips
 */
final class HostedCorrection {

	/**
	 * The query argument that starts a trip: `product` or `order`.
	 */
	public const START_ARG = 'pixonaut_correct';

	/**
	 * The query argument marking a return from the hosted page: `product` or `order`.
	 */
	public const RETURN_ARG = 'pixonaut_corrected';

	public const PRODUCT = 'product';

	public const ORDER = 'order';

	/**
	 * The design's key and side a trip from the product page corrects.
	 */
	public const ITEM_ARG = 'item';

	public const SIDE_ARG = 'side';

	/**
	 * Parameters the hosted page adds to the return address, and the order trip's own.
	 */
	public const STATE_ARG = 'state';

	public const TOKEN_ARG = 'proofToken';

	/**
	 * Before WooCommerce's form handlers, as the Personalise button's own.
	 */
	private const HANDLER_PRIORITY = 15;

	/**
	 * Refusals of a design's correction that may pass when tried again: Pixonaut
	 * could not be reached or refused for a passing reason, or the store's
	 * connection is down for now.
	 */
	private const RETRYABLE = array( EditRefused::NOT_SAVED, EditRefused::UNAVAILABLE );

	/**
	 * @param CartEdit            $cart       Saves a design's correction, and a cart line's.
	 * @param OrderEdit           $order      Finds, saves and approves an order line's change.
	 * @param CorrectionMount     $correction Mints the hosted page's link.
	 * @param CorrectionTrips     $trips      The customer's open trips from the product page.
	 * @param Labels              $labels     Customer-facing text.
	 * @param PersonalisedDesigns $personalised The designs waiting on product pages.
	 */
	public function __construct(
		private readonly CartEdit $cart,
		private readonly OrderEdit $order,
		private readonly CorrectionMount $correction,
		private readonly CorrectionTrips $trips,
		private readonly Labels $labels,
		private readonly PersonalisedDesigns $personalised,
	) {
	}

	public function register(): void {
		add_action( 'wp_loaded', array( $this, 'handle' ), self::HANDLER_PRIORITY );
		add_action( 'woocommerce_before_thankyou', array( $this, 'print_notices' ) );
	}

	/**
	 * Shows the notices an order trip left on the order received page, which
	 * prints none of its own, in the classic template and the blocks alike.
	 */
	public function print_notices(): void {
		if ( wc_notice_count() > 0 ) {
			wc_print_notices();
		}
	}

	/**
	 * The address that sends the customer to change a side of a kept design.
	 *
	 * @param string $key  The design's key.
	 * @param string $side The side.
	 */
	public static function product_url( string $key, string $side ): string {
		return add_query_arg(
			array(
				self::START_ARG => self::PRODUCT,
				self::ITEM_ARG  => rawurlencode( $key ),
				self::SIDE_ARG  => rawurlencode( $side ),
			),
			home_url( '/' )
		);
	}

	/**
	 * The address that sends the customer to change an order line's design.
	 *
	 * @param string $state The `state` the store signed for the line.
	 */
	public static function order_url( string $state ): string {
		return add_query_arg(
			array(
				self::START_ARG => self::ORDER,
				self::STATE_ARG => rawurlencode( $state ),
			),
			home_url( '/' )
		);
	}

	/**
	 * Where the hosted page sends the customer back to.
	 *
	 * @param string $trip `PRODUCT` or `ORDER`.
	 */
	public static function return_url( string $trip ): string {
		return add_query_arg( self::RETURN_ARG, $trip, home_url( '/' ) );
	}

	/**
	 * Sends the customer to the hosted page, or back from it.
	 */
	public function handle(): void {
		if ( is_admin() ) {
			return;
		}
		// Each end acts only on the customer's own cart or on a signed state; neither carries a nonce.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$start = isset( $_GET[ self::START_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::START_ARG ] ) ) : '';
		$back  = isset( $_GET[ self::RETURN_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::RETURN_ARG ] ) ) : '';
		$state = isset( $_GET[ self::STATE_ARG ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::STATE_ARG ] ) ) : '';
		$token = isset( $_GET[ self::TOKEN_ARG ] ) && is_string( $_GET[ self::TOKEN_ARG ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::TOKEN_ARG ] ) ) : null;
		if ( self::PRODUCT === $start ) {
			$location = $this->start_product(
				isset( $_GET[ self::ITEM_ARG ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::ITEM_ARG ] ) ) : '',
				isset( $_GET[ self::SIDE_ARG ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::SIDE_ARG ] ) ) : ''
			);
		} elseif ( self::ORDER === $start ) {
			$location = $this->start_order( $state );
		} elseif ( self::PRODUCT === $back ) {
			$location = $this->finish_product( $state, $token );
		} elseif ( self::ORDER === $back ) {
			$location = $this->finish_order( $state, $token );
		} else {
			return;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		// Pixonaut's hosted page is on another host, which wp_safe_redirect() refuses.
		wp_redirect( $location ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	/**
	 * Mints the hosted page's link for a side of a kept design, or saves
	 * again the correction a kept trip returned for it.
	 *
	 * @param string $key  The design's key.
	 * @param string $side The side.
	 *
	 * @return string Where to send the customer: the hosted page, or the product page with a notice.
	 */
	public function start_product( string $key, string $side ): string {
		$personalised = $this->personalised->get( $key );
		$design       = null === $personalised ? null : $personalised['line']->side( $side )?->design_id;
		if ( null === $personalised || null === $design ) {
			return $this->notice( Labels::CORRECTION_VISIT_UNKNOWN, wc_get_page_permalink( 'shop' ) );
		}
		$back    = PersonalisedDesigns::page_url( $personalised );
		$waiting = $this->trips->waiting_for( $key, $side );
		$trip    = null === $waiting ? null : $this->trips->take( $waiting );
		if ( null !== $waiting && null !== $trip && null !== $trip['token'] ) {
			return $this->save_product( $waiting, $trip, $trip['token'] );
		}
		$state = $this->trips->open( $key, $side );
		$page  = null === $state ? null : $this->correction->page( $design, self::return_url( self::PRODUCT ), $state );
		if ( null === $page ) {
			if ( null !== $state ) {
				$this->trips->take( $state );
			}
			return $this->notice( Labels::PERSONALISATION_UNAVAILABLE, $back );
		}
		return $page;
	}

	/**
	 * Takes the customer back from changing a kept design.
	 *
	 * @param string      $state The state the hosted page carried back.
	 * @param string|null $token The correction's proof token; null when the customer cancelled.
	 *
	 * @return string Where to send the customer: the product page.
	 */
	public function finish_product( string $state, ?string $token ): string {
		$trip         = $this->trips->take( $state );
		$personalised = null === $trip ? null : $this->personalised->get( $trip['key'] );
		if ( null === $trip || null === $personalised ) {
			return $this->notice( Labels::CORRECTION_VISIT_UNKNOWN, wc_get_page_permalink( 'shop' ) );
		}
		if ( null === $token || '' === $token ) {
			return PersonalisedDesigns::page_url( $personalised );
		}
		return $this->save_product( $state, $trip, $token );
	}

	/**
	 * Saves a returned correction on the design, and on the cart line holding
	 * it; keeps the trip with its proof token when the refusal may pass next time.
	 *
	 * @param string               $state The trip's state.
	 * @param array<string, mixed> $trip  The trip, taken out of the session.
	 * @param string               $token The correction's proof token.
	 * @phpstan-param Trip $trip
	 *
	 * @return string Where to send the customer: the product page.
	 */
	private function save_product( string $state, array $trip, string $token ): string {
		$personalised = $this->personalised->get( $trip['key'] );
		$side         = null === $personalised ? null : $personalised['line']->side( $trip['side'] );
		if ( null === $personalised || null === $side ) {
			return $this->notice( Labels::CORRECTION_VISIT_UNKNOWN, wc_get_page_permalink( 'shop' ) );
		}
		$back     = PersonalisedDesigns::page_url( $personalised );
		$cart_key = PersonalisedDesigns::cart_key_of( $personalised['line'] );
		try {
			// Approving on the hosted page stands in for the approval box.
			$line = null === $cart_key
				? $this->cart->corrected( $personalised['line'], $side, $token )
				: $this->cart->update( $cart_key, $trip['side'], $token, true );
		} catch ( EditRefused $refused ) {
			if ( in_array( $refused->reason, self::RETRYABLE, true ) ) {
				$this->trips->keep( $state, $trip, $token );
			}
			return $this->notice( $refused->label(), $back );
		}
		$this->personalised->update( $trip['key'], $line );
		return $this->notice( Labels::DESIGN_UPDATED, $back, 'success' );
	}

	/**
	 * Mints the hosted page's link for an order line whose customer asked
	 * for changes.
	 *
	 * @param string $state The `state` the store signed for the line.
	 *
	 * @return string Where to send the customer: the hosted page, their order with a notice, or the home page.
	 */
	public function start_order( string $state ): string {
		$line = '' === $state ? null : $this->order->find( $state );
		if ( null === $line ) {
			return home_url( '/' );
		}
		$received = $line->order->get_checkout_order_received_url();
		$closed   = $this->order->closed_message( $line );
		if ( null !== $closed ) {
			return $this->notice_text( $closed, $received );
		}
		$page = $this->correction->page( $line->design_id, self::return_url( self::ORDER ), $state );
		return $page ?? $this->notice( Labels::PERSONALISATION_UNAVAILABLE, $received );
	}

	/**
	 * Takes the customer back from changing an order line's design.
	 *
	 * @param string      $state The `state` the hosted page carried back.
	 * @param string|null $token The correction's proof token; null when the customer cancelled.
	 *
	 * @return string Where to send the customer: their order, or the home page.
	 */
	public function finish_order( string $state, ?string $token ): string {
		$line = '' === $state ? null : $this->order->find( $state );
		if ( null === $line ) {
			return home_url( '/' );
		}
		$received = $line->order->get_checkout_order_received_url();
		if ( null === $token || '' === $token ) {
			return $received;
		}
		try {
			// Approving on the hosted page stands in for the approval box.
			$this->order->save( $state, $line->item->get_id(), $line->side, $token, true );
		} catch ( EditRefused $refused ) {
			return $this->notice( $refused->label(), $received );
		}
		return $this->notice( Labels::DESIGN_UPDATED, $received, 'success' );
	}

	/**
	 * @param string $label    A key from `Labels`.
	 * @param string $location Where the customer goes next.
	 * @param string $type     The notice's type.
	 *
	 * @return string `$location`.
	 */
	private function notice( string $label, string $location, string $type = 'error' ): string {
		return $this->notice_text( $this->labels->get( $label ), $location, $type );
	}

	/**
	 * Adds a notice the customer sees on the page they go on to.
	 *
	 * @param string $text     The notice.
	 * @param string $location Where the customer goes next.
	 * @param string $type     The notice's type.
	 *
	 * @return string `$location`.
	 */
	private function notice_text( string $text, string $location, string $type = 'error' ): string {
		$session = WC()->session;
		// A guest arriving from an email has no session yet, and keeps a notice only once its cookie is set.
		if ( $session instanceof WC_Session_Handler && ! $session->has_session() ) {
			$session->set_customer_session_cookie( true );
		}
		wc_add_notice( $text, $type );
		return $location;
	}
}
