<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use Pixonaut\WooCommerce\Plugin;
use WC_Product;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * The server's reads for the sides a product's variations mount on another
 * template, blank or placement than the page printed reads for, which the
 * storefront asks for once the page is idle: a switch to such a variation
 * then mounts its side from them, as the page's first side mounts from the
 * reads printed in the form, with no read of its own to wait for.
 */
final class ReadsAhead {

	public const ROUTE = '/storefront/reads-ahead';

	/**
	 * The most targets read ahead for one product: each is a template, a
	 * blank's mockups and variants and a placement's constraints, which a
	 * product of many blanks would make a long wait for the first answer.
	 */
	public const MAX_TARGETS = 6;

	/**
	 * The mount options a target is told apart by, as `ServerReads` reads
	 * them.
	 */
	private const TARGET_KEYS = array( 'templateId', 'blankId', 'placementId' );

	public function __construct(
		private readonly InlineProducts $products,
		private readonly ServerReads $reads,
	) {
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
	}

	public static function url(): string {
		return rest_url( Plugin::REST_NAMESPACE . self::ROUTE );
	}

	public function register_route(): void {
		register_rest_route(
			Plugin::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => WP_REST_Server::READABLE,
				// Shoppers ask from public product pages, signed in or not; the answer is what the page itself would print.
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'handle' ),
				'args'                => array(
					'product' => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					),
				),
			)
		);
	}

	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$product  = wc_get_product( (int) $request['product'] );
		$designer = $product instanceof WC_Product ? $this->products->for_product( $product ) : null;
		if ( null === $designer ) {
			return new WP_REST_Response( array( 'error' => 'not_found' ), 404 );
		}
		$targets = self::targets( $designer );
		$reads   = $this->reads->for_mounts( $targets );
		$answer  = array();
		foreach ( $targets as $key => $target ) {
			if ( isset( $reads[ $key ] ) ) {
				$answer[] = array_intersect_key( $target, array_flip( self::TARGET_KEYS ) ) + array( 'reads' => ServerReads::decoded( $reads[ $key ] ) );
			}
		}
		return new WP_REST_Response( array( 'reads' => $answer ), 200 );
	}

	/**
	 * The variations' side targets the page's own mounts are not on, each
	 * once, in the variations' order, with the locale of the side they
	 * mount for; at most `MAX_TARGETS`.
	 *
	 * @param InlineDesigner $designer An available designer.
	 * @return array<string, array<string, mixed>> Mount options by target key.
	 */
	public static function targets( InlineDesigner $designer ): array {
		$targets = array();
		foreach ( $designer->mounts as $options ) {
			$targets[ self::key( $options ) ] = null;
		}
		foreach ( $designer->variations as $variation ) {
			$sides = isset( $variation['sides'] ) && is_object( $variation['sides'] ) ? (array) $variation['sides'] : array();
			foreach ( $sides as $side => $target ) {
				if ( ! is_array( $target ) || ! is_string( $target['templateId'] ?? null ) ) {
					continue;
				}
				$key = self::key( $target );
				if ( array_key_exists( $key, $targets ) ) {
					continue;
				}
				$locale          = $designer->mounts[ $side ]['locale'] ?? null;
				$targets[ $key ] = array_intersect_key( $target, array_flip( self::TARGET_KEYS ) ) + ( null === $locale ? array() : array( 'locale' => $locale ) );
			}
		}
		return array_slice( array_filter( $targets ), 0, self::MAX_TARGETS, true );
	}

	/**
	 * @param array<string, mixed> $options Mount options or a variation's side.
	 */
	private static function key( array $options ): string {
		$parts = array();
		foreach ( self::TARGET_KEYS as $name ) {
			$parts[] = is_string( $options[ $name ] ?? null ) ? $options[ $name ] : '';
		}
		return implode( '|', $parts );
	}
}
