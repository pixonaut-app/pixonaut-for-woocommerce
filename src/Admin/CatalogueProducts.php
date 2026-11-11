<?php
/**
 * The product screen's pick of a printshop's catalogue product.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Admin;

use Pixonaut\WooCommerce\Api\PrintshopCatalogue;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * A product printed by an integrated printshop, such as Gelato, is linked
 * by picking the printshop's own product rather than a blank: the search
 * route lists the printshop's catalogue as selectWoo reads it, and the pick
 * route answers the blank that product is in Pixonaut: Pixonaut imports it,
 * or answers the blank it was already imported as through that printshop. The panel then shows
 * that blank as if it had been picked, and the variations are matched to
 * its variants as for any blank.
 */
final class CatalogueProducts {

	public const SEARCH_ROUTE = '/admin/search/catalogue-products';

	public const PICK_ROUTE = '/admin/catalogue-products/blank';

	/**
	 * Pixonaut's longest search text.
	 */
	private const MAX_QUERY_LENGTH = 200;

	/**
	 * Pixonaut's last page.
	 */
	private const MAX_PAGE = 100000;

	/**
	 * Answered when Pixonaut could not be asked.
	 */
	private const BAD_GATEWAY = 502;

	/**
	 * Answered when Pixonaut refused the import.
	 */
	private const UNPROCESSABLE = 422;

	/**
	 * Pixonaut's refusal of an import under a name the workspace has.
	 */
	private const DUPLICATE_NAME = 'duplicate_name';

	/**
	 * Pixonaut's refusal of a product with nothing to print on.
	 */
	private const NO_PRINTABLE_PLACEMENT = 'no_printable_placement';

	/**
	 * @param PrintshopCatalogue $catalogue The printshops' catalogues.
	 */
	public function __construct( private readonly PrintshopCatalogue $catalogue ) {
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		$id = array(
			'type'      => 'string',
			'required'  => true,
			'minLength' => 1,
		);
		register_rest_route(
			Plugin::REST_NAMESPACE,
			self::SEARCH_ROUTE,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'search' ),
				'permission_callback' => array( $this, 'can_link' ),
				'args'                => array(
					'connection' => $id,
					'q'          => array(
						'type'      => 'string',
						'default'   => '',
						'maxLength' => self::MAX_QUERY_LENGTH,
					),
					'page'       => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
						'maximum' => self::MAX_PAGE,
					),
				),
			)
		);
		register_rest_route(
			Plugin::REST_NAMESPACE,
			self::PICK_ROUTE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'pick' ),
				'permission_callback' => array( $this, 'can_link' ),
				'args'                => array(
					'connection' => $id,
					'product'    => $id,
					'name'       => array(
						'type'      => 'string',
						'required'  => true,
						'maxLength' => self::MAX_QUERY_LENGTH,
					),
				),
			)
		);
	}

	/**
	 * Whoever may edit products may search and link: the import makes a
	 * blank in the workspace the store is connected to.
	 */
	public function can_link(): bool {
		return current_user_can( CatalogueSearch::CAPABILITY );
	}

	/**
	 * A page of the printshop's products whose name holds `q`, each with its
	 * picture, how many variants it is sold in, whether Pixonaut already
	 * has it, and the blank it is (`blank`, empty when it was not imported).
	 * Asked anew each time, so a product imported a moment ago says so.
	 *
	 * @param WP_REST_Request $request The request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function search( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try {
			$found = $this->catalogue->products( (string) $request->get_param( 'connection' ), (string) $request->get_param( 'q' ), absint( $request->get_param( 'page' ) ) );
		} catch ( ApiException $e ) {
			return new WP_Error(
				'pixonaut_wc_search_failed',
				ApiException::NOT_CONNECTED === $e->errorCode
					? __( 'Connect the store to Pixonaut to search its catalogue.', 'pixonaut-for-woocommerce' )
					: __( 'Pixonaut could not be searched. Try again in a moment.', 'pixonaut-for-woocommerce' ),
				array( 'status' => self::BAD_GATEWAY )
			);
		}
		$results = array();
		foreach ( $found['rows'] as $product ) {
			$results[] = array(
				'id'        => $product['id'],
				'text'      => $product['name'],
				'thumbnail' => $product['image'],
				'note'      => self::note( $product['variants'], null !== $product['blank'] ),
				'blank'     => $product['blank'] ?? '',
			);
		}
		return new WP_REST_Response(
			array(
				'results'    => $results,
				'pagination' => array( 'more' => $found['more'] ),
			)
		);
	}

	/**
	 * The blank a product is in Pixonaut: Pixonaut answers the one it was
	 * imported as, else imports it now. An import refused because the name
	 * is taken (a blank whose import failed, or one made by hand) says so.
	 *
	 * @param WP_REST_Request $request The request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error `{id, text, importing}`: the blank, its name, and whether Pixonaut is still filling it in.
	 */
	public function pick( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$connection = sanitize_text_field( (string) $request->get_param( 'connection' ) );
		$product    = sanitize_text_field( (string) $request->get_param( 'product' ) );
		$name       = sanitize_text_field( (string) $request->get_param( 'name' ) );
		try {
			$imported = $this->catalogue->import( $connection, $product );
			if ( null !== $imported ) {
				return self::picked( $imported['id'], $imported['name'], LinkedBlank::IMPORTING === $imported['status'] );
			}
			// Imported before, answered without a body: the catalogue names the blank.
			$blank = $this->catalogue->imported_blank( $connection, $product, $name );
			if ( null !== $blank ) {
				return self::picked( $blank, $name, false );
			}
			return self::import_failed( null );
		} catch ( ApiException $e ) {
			if ( self::DUPLICATE_NAME === $e->errorCode ) {
				// A blank of that name whose import failed, or one made by hand.
				return new WP_Error(
					'pixonaut_wc_import_refused',
					/* translators: %s: the name of a printshop's product, such as "Classic poster". Shown under the Printed on field of the product edit page. */
					sprintf( __( 'Pixonaut already has a blank called %s. Choose it under Your blanks in Pixonaut, or rename it in Pixonaut and pick this product again.', 'pixonaut-for-woocommerce' ), $name ),
					array( 'status' => self::UNPROCESSABLE )
				);
			}
			if ( self::NO_PRINTABLE_PLACEMENT === $e->errorCode ) {
				return new WP_Error( 'pixonaut_wc_import_refused', __( 'Pixonaut cannot print on this product: it has no print position.', 'pixonaut-for-woocommerce' ), array( 'status' => self::UNPROCESSABLE ) );
			}
			return self::import_failed( $e );
		}
	}

	/**
	 * What the pick route answers when Pixonaut could not be asked, or did
	 * not say which blank the product is.
	 *
	 * @param ApiException|null $error Why; null for an answer without the blank.
	 */
	private static function import_failed( ?ApiException $error ): WP_Error {
		return new WP_Error(
			'pixonaut_wc_import_failed',
			ApiException::NOT_CONNECTED === $error?->errorCode
				? __( 'Connect the store to Pixonaut to search its catalogue.', 'pixonaut-for-woocommerce' )
				: __( 'Pixonaut could not import this product. Try again in a moment.', 'pixonaut-for-woocommerce' ),
			array( 'status' => self::BAD_GATEWAY )
		);
	}

	/**
	 * The pick route's answer.
	 *
	 * @param string $blank_id  The blank.
	 * @param string $name      Its name.
	 * @param bool   $importing Whether Pixonaut is still filling it in.
	 */
	private static function picked( string $blank_id, string $name, bool $importing ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'id'        => $blank_id,
				'text'      => $name,
				'importing' => $importing,
			)
		);
	}

	/**
	 * What the picker shows under a product's name: how many variants it
	 * is sold in, and whether Pixonaut already has it.
	 *
	 * @param int|null $variants How many variants, when the printshop says.
	 * @param bool     $imported Whether it was imported.
	 */
	private static function note( ?int $variants, bool $imported ): string {
		$count = null === $variants
			/* translators: Under a printshop's product in the catalogue picker of the product edit page: the printshop did not say how many variants it is sold in. */
			? __( 'Variants not stated', 'pixonaut-for-woocommerce' )
			/* translators: %d: how many variants, such as sizes, a printshop's product is sold in. Under the product in the catalogue picker of the product edit page. */
			: sprintf( _n( '%d variant', '%d variants', $variants, 'pixonaut-for-woocommerce' ), $variants );
		return $imported
			/* translators: %s: how many variants the product is sold in, such as "30 variants". Under a printshop's product in the catalogue picker of the product edit page: Pixonaut already has it as a blank. */
			? sprintf( __( '%s · Imported', 'pixonaut-for-woocommerce' ), $count )
			: $count;
	}
}
