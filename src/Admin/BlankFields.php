<?php
/**
 * The product screen's read of a blank picked but not saved yet.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Admin;

use Closure;
use Pixonaut\WooCommerce\Plugin;
use WC_Product;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Picking a blank in the Pixonaut tab shows what depends on it at once: the
 * printshop it prints at, which of its variants the product is, and the views
 * the product can be shown in. This route draws those parts of the panel
 * for the blank picked, as the panel draws them for a saved one, so the
 * screen does not wait for Update. Only someone who may edit the product
 * reads it.
 */
final class BlankFields {

	public const ROUTE = '/admin/blank-fields';

	/**
	 * Answered when the product is not one the panel configures.
	 */
	private const NOT_FOUND = 404;

	/**
	 * @param Closure $panel Gives the product panel, built when the route is asked.
	 * @phpstan-param Closure(): ProductPanel $panel
	 */
	public function __construct( private readonly Closure $panel ) {
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
	}

	public function register_route(): void {
		register_rest_route(
			Plugin::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'answer' ),
				'permission_callback' => array( $this, 'can_edit' ),
				'args'                => array(
					'product' => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					),
					'blank'   => array(
						'type'      => 'string',
						'required'  => true,
						'minLength' => 1,
					),
				),
			)
		);
	}

	/**
	 * Whether the user may edit the product asked about.
	 *
	 * @param WP_REST_Request $request The request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function can_edit( WP_REST_Request $request ): bool {
		return current_user_can( 'edit_post', (int) $request->get_param( 'product' ) );
	}

	/**
	 * The panel's parts for the blank picked.
	 *
	 * @param WP_REST_Request $request The request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function answer( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$product = wc_get_product( (int) $request->get_param( 'product' ) );
		if ( ! $product instanceof WC_Product || ! $product->is_type( array( 'simple', 'variable' ) ) ) {
			return new WP_Error( 'pixonaut_wc_no_product', __( 'No such product.', 'pixonaut-for-woocommerce' ), array( 'status' => self::NOT_FOUND ) );
		}
		$blank = sanitize_text_field( (string) $request->get_param( 'blank' ) );
		return new WP_REST_Response( ( $this->panel )()->blank_fields( $product, $blank ) );
	}
}
