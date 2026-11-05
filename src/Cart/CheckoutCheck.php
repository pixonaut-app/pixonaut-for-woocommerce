<?php
/**
 * Checks each personalised cart line's designs before checkout places the order.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Cart;

use Pixonaut\WooCommerce\Api\ApiError;
use Pixonaut\WooCommerce\Api\Designs;
use Pixonaut\WooCommerce\Api\ErrorCodes;
use Pixonaut\WooCommerce\Api\Logger;
use Pixonaut\WooCommerce\Connection\Connection;
use Pixonaut\WooCommerce\Labels;
use WC_Cart;
use WC_Product;
use WP_Error;
use WP_REST_Request;

/**
 * A design is checked when its line joins the cart, but it can be deleted
 * while it waits there: Pixonaut lets a design no order holds be deleted,
 * in the studio or by the store's own cart cleanup. So both checkouts ask
 * Pixonaut for every personalised line's designs before the order is made,
 * and stop on a line whose design is gone (`404`), rejected, or erased,
 * none of which can be printed.
 *
 * Only a definite answer stops checkout. When Pixonaut cannot be reached,
 * or answers anything else, checkout goes ahead without reading the rest,
 * so an outage costs one request's timeout, not one per design:
 * fulfilment waits for Pixonaut, and a design found deleted then needs the
 * merchant's attention.
 *
 * The classic checkout checks in `woocommerce_after_checkout_validation`.
 * The Store API runs `woocommerce_store_api_cart_errors` for every cart it
 * answers with, so the check runs there only while a checkout is being
 * placed (`POST /wc/store/v1/checkout`).
 */
final class CheckoutCheck {

	/**
	 * Error code of a line whose design cannot be ordered.
	 */
	public const ERROR = 'pixonaut_design_unavailable';

	/**
	 * A design rejected in Pixonaut, which is never printed.
	 */
	private const STATUS_REJECTED = 'rejected';

	/**
	 * The Store API route that places the order.
	 */
	private const CHECKOUT_ROUTE = '#^/wc/store(/v\d+)?/checkout$#';

	/**
	 * Whether the request being answered places a Store API checkout.
	 *
	 * @var bool
	 */
	private bool $placing = false;

	public function __construct(
		private readonly Connection $connection,
		private readonly Designs $designs,
		private readonly Labels $labels,
		private readonly Logger $logger,
	) {
	}

	public function register(): void {
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'classic' ), 10, 2 );
		add_filter( 'rest_request_before_callbacks', array( $this, 'watch_request' ), 10, 3 );
		add_action( 'woocommerce_store_api_cart_errors', array( $this, 'store_api' ), 10, 2 );
	}

	/**
	 * Adds an error to the classic checkout for each line that cannot be ordered.
	 *
	 * @param mixed $data   The posted checkout data; unused.
	 * @param mixed $errors The checkout's errors.
	 */
	public function classic( mixed $data, mixed $errors ): void {
		unset( $data );
		$cart = WC()->cart;
		// Typed as always set, but null until WooCommerce loads the cart.
		if ( $errors instanceof WP_Error && null !== $cart ) { // @phpstan-ignore notIdentical.alwaysTrue
			$this->check( $cart, $errors );
		}
	}

	/**
	 * Notes whether the Store API request places the order; passes the
	 * response through untouched.
	 *
	 * @param mixed $response The response so far.
	 * @param mixed $handler  The route's handler; unused.
	 * @param mixed $request  The request.
	 */
	public function watch_request( mixed $response, mixed $handler, mixed $request ): mixed {
		unset( $handler );
		$this->placing = $request instanceof WP_REST_Request
			&& 'POST' === $request->get_method()
			&& 1 === preg_match( self::CHECKOUT_ROUTE, $request->get_route() );
		return $response;
	}

	/**
	 * Adds an error to the Store API's cart for each line that cannot be
	 * ordered, while a checkout is being placed.
	 *
	 * @param mixed $errors The cart's errors.
	 * @param mixed $cart   The cart.
	 */
	public function store_api( mixed $errors, mixed $cart ): void {
		if ( $this->placing && $errors instanceof WP_Error && $cart instanceof WC_Cart ) {
			$this->check( $cart, $errors );
		}
	}

	/**
	 * @param WC_Cart  $cart   The cart.
	 * @param WP_Error $errors Where each line that cannot be ordered is added.
	 */
	private function check( WC_Cart $cart, WP_Error $errors ): void {
		$connection = $this->connection->current();
		if ( null === $connection || null !== $this->connection->paused() ) {
			return;
		}
		foreach ( $cart->get_cart() as $item ) {
			$line    = CartLine::of( $item );
			$product = $item['data'] ?? null;
			if ( null === $line || $line->tenant_id !== $connection->tenant_id || ! $product instanceof WC_Product ) {
				continue;
			}
			foreach ( $line->sides as $side ) {
				$gone = $this->gone( $side->design_id );
				if ( null === $gone ) {
					// Pixonaut did not answer: one wait is enough, checkout goes ahead without the rest.
					return;
				}
				if ( $gone ) {
					$errors->add( self::ERROR, sprintf( $this->labels->get( Labels::DESIGN_UNAVAILABLE, array( 'product_id' => $product->get_id() ) ), $product->get_name() ) );
					break;
				}
			}
		}
	}

	/**
	 * Whether Pixonaut says the design can no longer be ordered; null when
	 * it gave no such answer (no answer, or another refusal), which is logged.
	 *
	 * @param string $design_id The design.
	 */
	private function gone( string $design_id ): ?bool {
		try {
			$design = $this->designs->get( $design_id );
		} catch ( ApiError $error ) {
			if ( ErrorCodes::NOT_FOUND === $error->error_code ) {
				return true;
			}
			$this->logger->warning(
				sprintf( 'Could not check design %s at checkout: %s', $design_id, $error->getMessage() ),
				array( 'code' => $error->error_code )
			);
			return null;
		}
		return self::STATUS_REJECTED === ( $design['status'] ?? null ) || null !== ( $design[ Designs::PERSONAL_DATA_ERASED_AT ] ?? null );
	}
}
