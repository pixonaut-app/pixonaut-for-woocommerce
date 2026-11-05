<?php
/**
 * The route a proof page sends the customer back to.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Approval;

use Pixonaut\WooCommerce\Plugin;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Takes a customer who asked for changes on the proof page to the product
 * page that edits the design (`OrderEdit`), or to Pixonaut's hosted
 * correction page for a product on the Personalise button layout, which
 * also says when the proof has changed since or the design was approved; a
 * product that no longer personalises takes them to their order. The `state` the page carries
 * back must be one the store signed for this order line and one of its
 * designs; anything else lands on the store's home page.
 */
final class ProofReturn {

	private const REDIRECT_STATUS = 302;

	/**
	 * @param OrderEdit $edit Verifies the `state` the page carries back and opens the design.
	 */
	public function __construct( private readonly OrderEdit $edit ) {
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
	}

	public function register_route(): void {
		register_rest_route(
			Plugin::REST_NAMESPACE,
			ProofLink::RETURN_ROUTE,
			array(
				'methods'             => WP_REST_Server::READABLE,
				// The customer arrives from Pixonaut's page, signed in or not; the signed state is the check.
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'handle' ),
				'args'                => array(
					'state' => array(
						'type'      => 'string',
						'required'  => true,
						'maxLength' => ProofState::MAX_LENGTH,
					),
				),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request The customer's return.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$response = new WP_REST_Response( null, self::REDIRECT_STATUS );
		$response->header( 'Location', $this->destination( (int) $request['item'], (string) $request['state'] ) );
		return $response;
	}

	/**
	 * The page that edits the design, or the customer's order, when the
	 * state is the store's for this line and design; else the home page.
	 *
	 * @param int    $item_id The order line in the address.
	 * @param string $state   The state the page carried back.
	 */
	private function destination( int $item_id, string $state ): string {
		$line = $this->edit->find( $state );
		if ( null === $line || $line->item->get_id() !== $item_id ) {
			return home_url( '/' );
		}
		return $this->edit->page_url( $line ) ?? $line->order->get_checkout_order_received_url();
	}
}
