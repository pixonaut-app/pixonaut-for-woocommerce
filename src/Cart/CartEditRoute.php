<?php
/**
 * The routes a product page calls while it edits a cart line's design.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Cart;

use Pixonaut\WooCommerce\Labels;
use Pixonaut\WooCommerce\Plugin;
use WC_Product;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `POST /pixonaut/v1/cart/design` saves a side's correction on the line:
 * the proof token the correction mount's `approve()` resolved goes to
 * Pixonaut as the design's next revision, and the line takes it.
 * `POST /pixonaut/v1/cart/design-link` mints a side's correction link
 * again, with its mount options, for a page whose link lapsed (`401` from
 * `approve()`).
 *
 * Both act on the caller's own cart only, by its line's key, so they take
 * the customer's session as WooCommerce's cart does; a signed-in customer's
 * request carries the REST nonce so it is made as them.
 */
final class CartEditRoute {

	public const UPDATE_ROUTE = '/cart/design';

	public const LINK_ROUTE = '/cart/design-link';

	/**
	 * Longest side key a product configuration holds.
	 */
	private const SIDE_KEY_MAX_LENGTH = 64;

	/**
	 * @param CartEdit $edit   Edits the line.
	 * @param Labels   $labels Customer-facing text.
	 */
	public function __construct(
		private readonly CartEdit $edit,
		private readonly Labels $labels,
	) {
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		$key  = array(
			'type'     => 'string',
			'required' => true,
			'pattern'  => '^[a-f0-9]{32}$',
		);
		$side = array(
			'type'      => 'string',
			'required'  => true,
			'maxLength' => self::SIDE_KEY_MAX_LENGTH,
		);
		register_rest_route(
			Plugin::REST_NAMESPACE,
			self::UPDATE_ROUTE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				// A customer's own cart, signed in or not; the key finds the line in it.
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'update' ),
				'args'                => array(
					'key'        => $key,
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
					'key'  => $key,
					'side' => $side,
				),
			)
		);
	}

	/**
	 * The routes' addresses and nonce, for the storefront script.
	 *
	 * @return array{updateUrl: string, linkUrl: string, nonce: string}
	 */
	public static function settings(): array {
		return array(
			'updateUrl' => rest_url( Plugin::REST_NAMESPACE . self::UPDATE_ROUTE ),
			'linkUrl'   => rest_url( Plugin::REST_NAMESPACE . self::LINK_ROUTE ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
		);
	}

	/**
	 * @param WP_REST_Request $request The side, its proof token and the approval.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		self::load_cart();
		try {
			$line = $this->edit->update( (string) $request['key'], (string) $request['side'], (string) $request['proofToken'], (bool) $request['approved'], FieldValue::list_from( $request['fields'] ) );
		} catch ( EditRefused $refused ) {
			return $this->refusal( $refused );
		}
		$side = $line->side( (string) $request['side'] );
		return new WP_REST_Response(
			array(
				'revision'    => $side?->revision,
				'redirectUrl' => wc_get_cart_url(),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request The line's key.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function link( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		self::load_cart();
		$edited  = CartEdit::line( (string) $request['key'] );
		$side    = (string) $request['side'];
		$product = null === $edited ? null : wc_get_product( $edited->product_id );
		if ( null === $edited || null === $edited->line->side( $side ) || ! $product instanceof WC_Product ) {
			return $this->refusal( new EditRefused( EditRefused::LINE_GONE ) );
		}
		$mounts = $this->edit->mounts( $edited, $product, array( $side ) );
		return array() === $mounts
			? $this->refusal( new EditRefused( EditRefused::UNAVAILABLE ) )
			: new WP_REST_Response( array( 'mounts' => $mounts ) );
	}

	private function refusal( EditRefused $refused ): WP_Error {
		return new WP_Error(
			$refused->reason,
			$this->labels->get( $refused->label() ),
			array( 'status' => $refused->status() )
		);
	}

	/**
	 * WooCommerce loads the customer's session and cart only for its own
	 * requests; a REST route loads them itself.
	 */
	private static function load_cart(): void {
		if ( null === WC()->cart ) {
			wc_load_cart();
		}
	}
}
