<?php
/**
 * The routes a product page calls while it edits an order line's design.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Approval;

use Pixonaut\WooCommerce\Cart\EditRefused;
use Pixonaut\WooCommerce\Cart\FieldValue;
use Pixonaut\WooCommerce\Labels;
use Pixonaut\WooCommerce\Plugin;
use WC_Product;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `POST /pixonaut/v1/order-items/<item>/design` saves a side's change and
 * approves it; `POST /pixonaut/v1/order-items/<item>/design-link` mints
 * the side's correction link again, with its mount options, for a page
 * whose link lapsed. Each takes the signed `state` that opened the page as
 * `key`, the same field a cart line's edit sends its key in, so the
 * storefront script edits either alike.
 */
final class OrderEditRoute {

	public const UPDATE_ROUTE = '/order-items/(?P<item>\d+)/design';

	public const LINK_ROUTE = '/order-items/(?P<item>\d+)/design-link';

	private const ITEM_PATTERN = '(?P<item>\d+)';

	/**
	 * Longest side key a product configuration holds.
	 */
	private const SIDE_KEY_MAX_LENGTH = 64;

	/**
	 * @param OrderEdit $edit   Edits the line.
	 * @param Labels    $labels Customer-facing text.
	 */
	public function __construct(
		private readonly OrderEdit $edit,
		private readonly Labels $labels,
	) {
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		$state = array(
			'type'      => 'string',
			'required'  => true,
			'maxLength' => ProofState::MAX_LENGTH,
		);
		$side  = array(
			'type'      => 'string',
			'required'  => true,
			'maxLength' => self::SIDE_KEY_MAX_LENGTH,
		);
		register_rest_route(
			Plugin::REST_NAMESPACE,
			self::UPDATE_ROUTE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				// The customer arrives from a proof page, signed in or not; the signed state is the check.
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'update' ),
				'args'                => array(
					'key'        => $state,
					'side'       => $side,
					'proofToken' => array(
						'type'      => 'string',
						'required'  => true,
						'minLength' => 1,
					),
					'approved'   => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'fields'     => FieldValue::rest_arg(),
				),
			)
		);
		register_rest_route(
			Plugin::REST_NAMESPACE,
			self::LINK_ROUTE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'link' ),
				'args'                => array(
					'key'  => $state,
					'side' => $side,
				),
			)
		);
	}

	/**
	 * The routes' addresses for an order line, and the nonce, for the
	 * storefront script.
	 *
	 * @param int $item_id The order line.
	 *
	 * @return array{updateUrl: string, linkUrl: string, nonce: string}
	 */
	public static function settings( int $item_id ): array {
		return array(
			'updateUrl' => self::url( self::UPDATE_ROUTE, $item_id ),
			'linkUrl'   => self::url( self::LINK_ROUTE, $item_id ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
		);
	}

	/**
	 * @param WP_REST_Request $request The state, the side, its proof token and the approval.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try {
			$next = $this->edit->save( (string) $request['key'], (int) $request['item'], (string) $request['side'], (string) $request['proofToken'], (bool) $request['approved'], FieldValue::list_from( $request['fields'] ) );
		} catch ( EditRefused $refused ) {
			return $this->refusal( $refused );
		}
		return new WP_REST_Response( array( 'redirectUrl' => $next ) );
	}

	/**
	 * @param WP_REST_Request $request The state and the side.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function link( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$line    = $this->edit->find( (string) $request['key'] );
		$product = null === $line ? null : wc_get_product( $line->item->get_product_id() );
		if ( null === $line || $line->item->get_id() !== (int) $request['item'] || $line->side !== (string) $request['side'] || ! $product instanceof WC_Product ) {
			return $this->refusal( new EditRefused( EditRefused::NOT_EDITABLE ) );
		}
		$mounts = $this->edit->mounts( $line, $product );
		if ( array() !== $mounts ) {
			return new WP_REST_Response( array( 'mounts' => $mounts ) );
		}
		$reason = match ( $line->status ) {
			OrderEditLine::CHANGED => EditRefused::PROOF_CHANGED,
			OrderEditLine::CLOSED  => EditRefused::EDIT_CLOSED,
			default                => EditRefused::UNAVAILABLE,
		};
		return $this->refusal( new EditRefused( $reason ) );
	}

	private function refusal( EditRefused $refused ): WP_Error {
		return new WP_Error(
			$refused->reason,
			$this->labels->get( $refused->label() ),
			array( 'status' => $refused->status() )
		);
	}

	/**
	 * @param string $route   A route of this class.
	 * @param int    $item_id The order line.
	 */
	private static function url( string $route, int $item_id ): string {
		return rest_url( Plugin::REST_NAMESPACE . str_replace( self::ITEM_PATTERN, (string) $item_id, $route ) );
	}
}
