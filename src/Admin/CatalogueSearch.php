<?php
/**
 * The admin's searches of the Pixonaut catalogue.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Admin;

use Closure;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Api\Catalogue;
use Pixonaut\WooCommerce\Catalogue\CatalogueCache;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\SearchResults;
use Pixonaut\WooCommerce\Catalogue\TemplateForm;
use Pixonaut\WooCommerce\Plugin;
use WC_Product;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST routes the product screen's search fields call. They ask Pixonaut
 * with the store's integration key, which never reaches the browser, and
 * answer `{results: [{id, text, thumbnail}], pagination: {more}}`, as
 * selectWoo reads it. The placements route lists a blank's placements, for
 * the placement field once a blank is chosen, and the template route a
 * template's picture, for a template field shown with its choice. The
 * template groups route answers `{groups: [...]}` instead: a template's form
 * groups and fields, for a side's Groups setting (see `TemplateForm`).
 */
final class CatalogueSearch {

	public const TEMPLATES_ROUTE = '/admin/search/templates';

	public const BLANKS_ROUTE = '/admin/search/blanks';

	public const FITTING_TEMPLATES_ROUTE = '/admin/search/fitting-templates';

	public const PLACEMENTS_ROUTE = '/admin/search/placements';

	public const TEMPLATE_ROUTE = '/admin/search/template';

	public const TEMPLATE_GROUPS_ROUTE = '/admin/search/template-groups';

	/**
	 * Who may search: whoever may edit products.
	 */
	public const CAPABILITY = 'edit_products';

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
	 * @param Catalogue      $catalogue Pixonaut's catalogue.
	 * @param CatalogueCache $cache     Keeps answers for a few minutes.
	 * @param SoldRows|null  $sold_rows Tells the rows of a blank a product sells, the only ones a fitting template's misfits name for it; null names every row.
	 */
	public function __construct(
		private readonly Catalogue $catalogue,
		private readonly CatalogueCache $cache,
		private readonly ?SoldRows $sold_rows = null,
	) {
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		$query = array(
			'type'      => 'string',
			'default'   => '',
			'maxLength' => self::MAX_QUERY_LENGTH,
		);
		$page  = array(
			'type'    => 'integer',
			'default' => 1,
			'minimum' => 1,
			'maximum' => self::MAX_PAGE,
		);
		$id    = array(
			'type'      => 'string',
			'required'  => true,
			'minLength' => 1,
		);
		register_rest_route(
			Plugin::REST_NAMESPACE,
			self::TEMPLATES_ROUTE,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'templates' ),
				'permission_callback' => array( $this, 'can_search' ),
				'args'                => array(
					'q'    => $query,
					'page' => $page,
				),
			)
		);
		register_rest_route(
			Plugin::REST_NAMESPACE,
			self::BLANKS_ROUTE,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'blanks' ),
				'permission_callback' => array( $this, 'can_search' ),
				'args'                => array(
					'q'    => $query,
					'page' => $page,
				),
			)
		);
		register_rest_route(
			Plugin::REST_NAMESPACE,
			self::FITTING_TEMPLATES_ROUTE,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'fitting_templates' ),
				'permission_callback' => array( $this, 'can_search' ),
				'args'                => array(
					'q'         => $query,
					'page'      => $page,
					'blank'     => $id,
					'placement' => $id,
					'product'   => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
		register_rest_route(
			Plugin::REST_NAMESPACE,
			self::PLACEMENTS_ROUTE,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'placements' ),
				'permission_callback' => array( $this, 'can_search' ),
				'args'                => array( 'blank' => $id ),
			)
		);
		register_rest_route(
			Plugin::REST_NAMESPACE,
			self::TEMPLATE_ROUTE,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'template' ),
				'permission_callback' => array( $this, 'can_search' ),
				'args'                => array( 'id' => $id ),
			)
		);
		register_rest_route(
			Plugin::REST_NAMESPACE,
			self::TEMPLATE_GROUPS_ROUTE,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'template_groups' ),
				'permission_callback' => array( $this, 'can_search' ),
				'args'                => array( 'id' => $id ),
			)
		);
	}

	public function can_search(): bool {
		return current_user_can( self::CAPABILITY );
	}

	/**
	 * @param WP_REST_Request $request The request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function templates( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$args = self::args( $request );
		return $this->answer(
			'templates',
			$args,
			function () use ( $args ): array {
				$found = $this->catalogue->templates( $args['q'], (int) $args['page'] );
				return self::page( SearchResults::templates( $found['rows'] ), $found['more'] );
			}
		);
	}

	/**
	 * @param WP_REST_Request $request The request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function blanks( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$args = self::args( $request );
		return $this->answer(
			'blanks',
			$args,
			function () use ( $args ): array {
				$found = $this->catalogue->blanks( $args['q'], (int) $args['page'] );
				return self::page( SearchResults::blanks( $found['rows'] ), $found['more'] );
			}
		);
	}

	/**
	 * @param WP_REST_Request $request The request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function fitting_templates( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$args     = self::args( $request ) + array(
			'blank'     => (string) $request->get_param( 'blank' ),
			'placement' => (string) $request->get_param( 'placement' ),
		);
		$response = $this->answer(
			'fitting-templates',
			$args,
			function () use ( $args ): array {
				$found = $this->catalogue->fitting_templates( $args['blank'], $args['placement'], $args['q'], (int) $args['page'] );
				// Pixonaut pages before the illegal templates are dropped: a page may list none and still have more.
				return self::page( SearchResults::fitting_templates( $found['rows'] ), $found['more'] );
			}
		);
		$product  = wc_get_product( absint( $request->get_param( 'product' ) ) );
		return $response instanceof WP_REST_Response && $product instanceof WC_Product ? $this->for_product( $response, $product, $args['blank'] ) : $response;
	}

	/**
	 * A fitting-templates answer for a product set up on the blank: each
	 * template names, of the sizes it cannot be printed at, only those the
	 * product sells. The answer as it is for a product set up on another
	 * blank, whose variants are not of this one.
	 *
	 * @param WP_REST_Response $response The answer naming every misfit size of the blank.
	 * @param WC_Product       $product  The product the search is for.
	 * @param string           $blank_id The blank searched.
	 */
	private function for_product( WP_REST_Response $response, WC_Product $product, string $blank_id ): WP_REST_Response {
		$config = ProductConfig::stored_on( $product );
		$answer = $response->get_data();
		if ( null === $this->sold_rows || $config->blank_id !== $blank_id || ! is_array( $answer ) || ! is_array( $answer['results'] ?? null ) ) {
			return $response;
		}
		foreach ( $answer['results'] as $index => $option ) {
			if ( is_array( $option ) && is_array( $option['misfits'] ?? null ) ) {
				$misfits                     = $this->sold_rows->misfits( $product, $config, array_values( array_filter( $option['misfits'], 'is_string' ) ) );
				$answer['results'][ $index ] = SearchResults::naming_misfits( $option, $misfits );
			}
		}
		$response->set_data( $answer );
		return $response;
	}

	/**
	 * A blank's placements, each with the photo of its first view.
	 *
	 * @param WP_REST_Request $request The request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function placements( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$blank_id = (string) $request->get_param( 'blank' );
		$linked   = new LinkedBlank( $this->catalogue, $this->cache );
		$blank    = $linked->read( $blank_id );
		if ( null === $blank ) {
			return new WP_Error( 'pixonaut_wc_search_failed', __( 'Pixonaut could not be searched. Try again in a moment.', 'pixonaut-for-woocommerce' ), array( 'status' => self::BAD_GATEWAY ) );
		}
		$pictures = LinkedBlank::placement_thumbnails( $linked->views( $blank_id ) );
		$results  = array();
		foreach ( $blank['placements'] as $placement ) {
			$results[] = $placement + array( 'thumbnail' => $pictures[ $placement['id'] ] ?? '' );
		}
		return new WP_REST_Response( self::page( $results, false ) );
	}

	/**
	 * A template version as the template field shows it chosen: its id, its
	 * picture, and whether its background shows through when Pixonaut says.
	 *
	 * @param WP_REST_Request $request The request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function template( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id = (string) $request->get_param( 'id' );
		try {
			$answer = $this->cache->remember(
				'template-preview',
				array( 'id' => $id ),
				function () use ( $id ): array {
					$state = $this->catalogue->template_state( $id );
					return array(
						'thumbnail'  => $state['preview_url'] ?? '',
						'background' => $state['background_see_through'],
					);
				}
			);
		} catch ( ApiException $e ) {
			return self::failed( $e );
		}
		return new WP_REST_Response(
			self::page(
				array(
					array(
						'id'        => $id,
						'thumbnail' => is_string( $answer['thumbnail'] ?? null ) ? $answer['thumbnail'] : '',
					) + ( is_bool( $answer['background'] ?? null ) ? array( 'backgroundSeeThrough' => $answer['background'] ) : array() ),
				),
				false
			)
		);
	}

	/**
	 * A template version's form groups and fields, as a side's Groups
	 * setting lists them. A version never changes, so the answer is kept as
	 * any search's is.
	 *
	 * @param WP_REST_Request $request The request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function template_groups( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id = (string) $request->get_param( 'id' );
		try {
			$answer = $this->cache->remember(
				'template-groups',
				array( 'id' => $id ),
				fn (): array => array( 'groups' => TemplateForm::groups( json_decode( $this->catalogue->template_answer( $id ), true ) ) )
			);
		} catch ( ApiException $e ) {
			return self::failed( $e );
		}
		return new WP_REST_Response( array( 'groups' => is_array( $answer['groups'] ?? null ) ? $answer['groups'] : array() ) );
	}

	/**
	 * What a search answers when Pixonaut could not be asked.
	 *
	 * @param ApiException $error Why.
	 */
	private static function failed( ApiException $error ): WP_Error {
		$message = ApiException::NOT_CONNECTED === $error->errorCode
			/* translators: Message in the blank search on the product edit page, shown to the merchant. */
			? __( 'Connect the store to Pixonaut to search its catalogue.', 'pixonaut-for-woocommerce' )
			: __( 'Pixonaut could not be searched. Try again in a moment.', 'pixonaut-for-woocommerce' );
		return new WP_Error( 'pixonaut_wc_search_failed', $message, array( 'status' => self::BAD_GATEWAY ) );
	}

	/**
	 * The search text and page every search takes.
	 *
	 * @param WP_REST_Request $request The request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return array{q: string, page: string}
	 */
	private static function args( WP_REST_Request $request ): array {
		return array(
			'q'    => (string) $request->get_param( 'q' ),
			'page' => (string) absint( $request->get_param( 'page' ) ),
		);
	}

	/**
	 * An answer as selectWoo reads it.
	 *
	 * @param list<array<string, mixed>> $results The options.
	 * @param bool                       $more    Whether a later page has more.
	 * @return array{results: list<array<string, mixed>>, pagination: array{more: bool}}
	 */
	private static function page( array $results, bool $more ): array {
		return array(
			'results'    => $results,
			'pagination' => array( 'more' => $more ),
		);
	}

	/**
	 * @param string                $what Which search, for the cache.
	 * @param array<string, string> $args What it was asked with.
	 * @param Closure               $load Asks Pixonaut.
	 * @phpstan-param Closure(): array<string, mixed> $load
	 */
	private function answer( string $what, array $args, Closure $load ): WP_REST_Response|WP_Error {
		try {
			$answer = $this->cache->remember( $what, $args, $load );
		} catch ( ApiException $e ) {
			return self::failed( $e );
		}

		/**
		 * Filters the options a catalogue search on the product screen
		 * lists: templates, blanks, or the templates that fit a blank's
		 * placement. Runs on every search, also one answered from the cache.
		 *
		 * @since 0.1.0
		 *
		 * @param list<array<string, mixed>> $results The options as selectWoo reads them: `id`, `text` and what the search adds.
		 * @param string                     $what    Which search: `templates`, `blanks` or `fitting-templates`.
		 * @param array<string, string>      $args    What it was asked with: `q`, `page`, and `blank` and `placement` for `fitting-templates`.
		 *
		 * @example
		 * add_filter( 'pixonaut_wc_catalogue_search_results', function ( $results, $what ) {
		 *     if ( 'blanks' !== $what ) {
		 *         return $results;
		 *     }
		 *     return array_values( array_filter( $results, fn ( $blank ) => ! str_contains( $blank['text'], 'Sample' ) ) );
		 * }, 10, 2 );
		 */
		$results = (array) apply_filters( 'pixonaut_wc_catalogue_search_results', $answer['results'] ?? array(), $what, $args );
		// A list, so selectWoo reads a JSON array also after a filter dropped some options.
		$answer['results'] = array_values( array_filter( $results, 'is_array' ) );
		return new WP_REST_Response( $answer );
	}
}
