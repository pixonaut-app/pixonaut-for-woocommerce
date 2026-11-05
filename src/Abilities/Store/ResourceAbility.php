<?php
/**
 * One operation on one kind of thing in the store, as an ability.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities\Store;

use Pixonaut\WooCommerce\Abilities\Abilities;
use Pixonaut\WooCommerce\Abilities\Ability;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Lists, reads, creates, updates or deletes a resource through its REST
 * route, as the user chatting: the ability checks the capability WordPress
 * or WooCommerce checks for it, and the route then checks the item itself,
 * as it would for the user in the admin. A media file is created from a URL,
 * which the store downloads.
 */
final class ResourceAbility implements Ability {

	private const METHODS = array(
		StoreResource::LIST   => 'GET',
		StoreResource::GET    => 'GET',
		StoreResource::CREATE => 'POST',
		StoreResource::UPDATE => 'PUT',
		StoreResource::DELETE => 'DELETE',
	);

	private const MEDIA = 'media';

	/**
	 * The field of a media file's create that names where to download it from.
	 */
	private const SOURCE_URL = 'source_url';

	/**
	 * How long the store waits for a media file to download, in seconds.
	 */
	private const DOWNLOAD_TIMEOUT_SECONDS = 30;

	/**
	 * The extension `download_url()` leaves on a file whose type it could not name.
	 */
	private const TEMPORARY_EXTENSION = 'tmp';

	/**
	 * The headers a list's answer gives its totals in.
	 */
	private const TOTAL_HEADER = 'X-WP-Total';

	private const PAGES_HEADER = 'X-WP-TotalPages';

	private const BAD_REQUEST = 400;

	/**
	 * The statuses with which a route refuses the user, rather than the request.
	 */
	private const REFUSING_STATUSES = array( 401, 403 );

	/**
	 * @param StoreResource $kind      The resource.
	 * @param string        $operation One of `StoreResource::OPERATIONS`.
	 */
	public function __construct(
		private readonly StoreResource $kind,
		private readonly string $operation,
	) {
	}

	public function name(): string {
		$plural = StoreResource::LIST === $this->operation || ( StoreResource::UPDATE === $this->operation && $this->kind->replaces );
		return $this->operation . '-' . ( $plural ? $this->kind->plural : $this->kind->key );
	}

	/**
	 * The ability's name with its namespace.
	 */
	public function full_name(): string {
		return Abilities::name( $this );
	}

	public function definition(): array {
		return array(
			'label'               => $this->label(),
			'description'         => $this->description(),
			'input_schema'        => $this->input_schema(),
			'output_schema'       => $this->output_schema(),
			'category'            => Abilities::STORE_CATEGORY,
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'can_run' ),
			'annotations'         => array(
				'readonly'    => $this->reads(),
				// A coupon or an order is created only once the merchant says so, as a change is.
				'destructive' => StoreResource::UPDATE === $this->operation || StoreResource::DELETE === $this->operation || ( StoreResource::CREATE === $this->operation && $this->kind->create_asks ),
				'idempotent'  => StoreResource::CREATE !== $this->operation,
			),
		);
	}

	/**
	 * Whether the user chatting may run the operation: on this kind of
	 * thing, and on the item it names, as the route itself checks it.
	 *
	 * @param mixed $input The call's input.
	 */
	public function can_run( mixed $input = null ): bool {
		if ( ! current_user_can( $this->kind->capabilities[ $this->operation ] ) ) {
			return false;
		}
		$request = $this->request( is_array( $input ) ? $input : array() );
		// A call that names no item is refused by `execute()`, saying what to name.
		return null === $request || self::route_allows( $request );
	}

	/**
	 * @param mixed $input The call's input.
	 * @return mixed What the route answered: for a list `{items, total, pages}`.
	 */
	public function execute( mixed $input = null ): mixed {
		$input   = is_array( $input ) ? $input : array();
		$request = $this->request( $input );
		if ( null === $request ) {
			return new WP_Error(
				'pixonaut_wc_store_route_unnamed',
				/* translators: %s: the input fields a store ability needs, e.g. "product_id, id". */
				sprintf( __( 'Name %s.', 'pixonaut-for-woocommerce' ), implode( ', ', $this->kind->placeholders( $this->operation ) ) ),
				array( 'status' => self::BAD_REQUEST )
			);
		}
		if ( StoreResource::CREATE === $this->operation && self::MEDIA === $this->kind->key ) {
			return self::upload( $request->get_route(), is_array( $input['fields'] ?? null ) ? $input['fields'] : array() );
		}
		return $this->answer( rest_do_request( $request ) );
	}

	/**
	 * The request the call makes of the route, or null when the input does
	 * not name every id the route needs.
	 *
	 * @param array<mixed> $input The call's input.
	 */
	private function request( array $input ): ?WP_REST_Request {
		$path = $this->kind->path( $this->operation, $input );
		if ( null === $path ) {
			return null;
		}
		$request = new WP_REST_Request( self::METHODS[ $this->operation ], $path );
		$fields  = is_array( $input['fields'] ?? null ) ? $input['fields'] : array();
		switch ( $this->operation ) {
			case StoreResource::LIST:
				$request->set_query_params( is_array( $input['query'] ?? null ) ? $input['query'] : array() );
				break;
			case StoreResource::CREATE:
			case StoreResource::UPDATE:
				$body = $this->kind->replaces ? ( is_array( $input['items'] ?? null ) ? array_values( $input['items'] ) : array() ) : $fields;
				$request->set_header( 'Content-Type', 'application/json' );
				$request->set_body( (string) wp_json_encode( $body ) );
				break;
			case StoreResource::DELETE:
				if ( true === ( $input['force'] ?? false ) ) {
					$request->set_query_params( array( 'force' => true ) );
				}
				break;
		}
		return $request;
	}

	/**
	 * Whether the route's own permission check lets the request through, as
	 * the REST server would check it before running it. A route that says
	 * the item is not there, or the request is malformed, is left to answer
	 * that itself; only a refusal of the user (401 or 403) refuses.
	 *
	 * @param WP_REST_Request $request The request.
	 * @phpstan-param WP_REST_Request<array<mixed>> $request
	 */
	private static function route_allows( WP_REST_Request $request ): bool {
		foreach ( rest_get_server()->get_routes() as $route => $handlers ) {
			if ( 1 !== preg_match( '@^' . $route . '$@i', $request->get_route(), $matches ) ) {
				continue;
			}
			foreach ( $handlers as $handler ) {
				if ( empty( $handler['methods'][ $request->get_method() ] ) ) {
					continue;
				}
				$check = $handler['permission_callback'] ?? null;
				if ( ! is_callable( $check ) ) {
					return true;
				}
				$checked = clone $request;
				$checked->set_url_params( array_filter( $matches, 'is_string', ARRAY_FILTER_USE_KEY ) );
				$checked->set_attributes( $handler );
				$allowed = call_user_func( $check, $checked );
				if ( $allowed instanceof WP_Error ) {
					$data   = $allowed->get_error_data();
					$status = is_array( $data ) ? ( $data['status'] ?? null ) : null;
					return ! in_array( $status, self::REFUSING_STATUSES, true );
				}
				return true === $allowed;
			}
		}
		// No such route: running the call says so.
		return true;
	}

	private function reads(): bool {
		return StoreResource::LIST === $this->operation || StoreResource::GET === $this->operation;
	}

	private function label(): string {
		$resource = $this->kind;
		return match ( $this->operation ) {
			/* translators: %s: a kind of thing in the store, plural, e.g. "product categories". Name of an ability an AI assistant or an MCP client can use on the store. */
			StoreResource::LIST => sprintf( __( 'List %s', 'pixonaut-for-woocommerce' ), $resource->nouns ),
			/* translators: %s: a kind of thing in the store, e.g. "product category". Name of an ability an AI assistant or an MCP client can use on the store. */
			StoreResource::GET => sprintf( __( 'Read %s', 'pixonaut-for-woocommerce' ), $resource->noun ),
			/* translators: %s: a kind of thing in the store, e.g. "product category". Name of an ability an AI assistant or an MCP client can use on the store. */
			StoreResource::CREATE => sprintf( __( 'Create %s', 'pixonaut-for-woocommerce' ), $resource->noun ),
			/* translators: %s: a kind of thing in the store, e.g. "product category", or several, e.g. "shipping zone locations". Name of an ability an AI assistant or an MCP client can use on the store. */
			StoreResource::UPDATE => sprintf( __( 'Update %s', 'pixonaut-for-woocommerce' ), $resource->replaces ? $resource->nouns : $resource->noun ),
			/* translators: %s: a kind of thing in the store, e.g. "product category". Name of an ability an AI assistant or an MCP client can use on the store. */
			default => sprintf( __( 'Delete %s', 'pixonaut-for-woocommerce' ), $resource->noun ),
		};
	}

	private function description(): string {
		$resource = $this->kind;
		$route    = '`' . $resource->route_of( $this->operation ) . '`';
		$fields   = '' === $resource->fields ? '' : ' ' . sprintf(
			/* translators: 1: a few field names, e.g. "name, slug, parent"; 2: the name of the ability that describes every field. */
			__( 'Fields include %1$s; %2$s gives every field.', 'pixonaut-for-woocommerce' ),
			$resource->fields,
			Abilities::tool( DescribeResource::NAME )
		);
		$description = match ( $this->operation ) {
			/* translators: 1: a kind of thing in the store, plural, e.g. "product categories"; 2: a REST API route. */
			StoreResource::LIST => sprintf( __( 'Lists the store\'s %1$s through the REST route %2$s. `query` takes the route\'s query parameters, e.g. search, page, per_page, and _fields to read only some fields.', 'pixonaut-for-woocommerce' ), $resource->nouns, $route ),
			/* translators: 1: a kind of thing in the store, plural, e.g. "product categories"; 2: a REST API route. */
			StoreResource::GET => sprintf( __( 'Reads one of the store\'s %1$s by its id through the REST route %2$s.', 'pixonaut-for-woocommerce' ), $resource->nouns, $route ),
			/* translators: 1: a kind of thing in the store, plural, e.g. "product categories"; 2: a REST API route. */
			StoreResource::CREATE => sprintf( __( 'Creates one of the store\'s %1$s through the REST route %2$s, with `fields` as the route takes them.', 'pixonaut-for-woocommerce' ), $resource->nouns, $route ) . $fields,
			StoreResource::UPDATE => $resource->replaces
				/* translators: 1: a kind of thing in the store, plural, e.g. "shipping zone locations"; 2: a REST API route. */
				? sprintf( __( 'Replaces the %1$s through the REST route %2$s with `items`, the whole new list: what it leaves out is removed.', 'pixonaut-for-woocommerce' ), $resource->nouns, $route ) . $fields
				/* translators: 1: a kind of thing in the store, plural, e.g. "product categories"; 2: a REST API route. */
				: sprintf( __( 'Changes one of the store\'s %1$s through the REST route %2$s, with only the `fields` to change.', 'pixonaut-for-woocommerce' ), $resource->nouns, $route ) . $fields,
			/* translators: 1: a kind of thing in the store, plural, e.g. "product categories"; 2: a REST API route. */
			default => sprintf( __( 'Deletes one of the store\'s %1$s through the REST route %2$s. With `force` it is deleted for good where the route would otherwise move it to the trash.', 'pixonaut-for-woocommerce' ), $resource->nouns, $route ),
		};
		if ( StoreResource::CREATE === $this->operation && self::MEDIA === $resource->key ) {
			$description .= ' ' . __( 'The store downloads the file from `fields.source_url`, a public URL.', 'pixonaut-for-woocommerce' );
		}
		return $description;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function input_schema(): array {
		$properties = array();
		foreach ( $this->kind->placeholders( $this->operation ) as $placeholder ) {
			$properties[ $placeholder ] = $this->kind->placeholder_schema( $placeholder );
		}
		$required = array_keys( $properties );
		switch ( $this->operation ) {
			case StoreResource::LIST:
				$properties['query'] = array(
					'type'                 => 'object',
					'description'          => __( 'The route\'s query parameters, e.g. search, page and per_page.', 'pixonaut-for-woocommerce' ),
					'additionalProperties' => true,
				);
				break;
			case StoreResource::CREATE:
			case StoreResource::UPDATE:
				if ( $this->kind->replaces ) {
					$properties['items'] = array(
						'type'        => 'array',
						'description' => __( 'The whole new list.', 'pixonaut-for-woocommerce' ),
						'items'       => array(
							'type'                 => 'object',
							'additionalProperties' => true,
						),
					);
					$required[]          = 'items';
					break;
				}
				$properties['fields'] = array(
					'type'                 => 'object',
					'description'          => StoreResource::CREATE === $this->operation
						? __( 'The fields as the route takes them.', 'pixonaut-for-woocommerce' )
						: __( 'Only the fields to change, as the route takes them.', 'pixonaut-for-woocommerce' ),
					'additionalProperties' => true,
				);
				$required[]           = 'fields';
				break;
			case StoreResource::DELETE:
				$properties['force'] = array(
					'type'        => 'boolean',
					'description' => __( 'Delete it for good rather than move it to the trash.', 'pixonaut-for-woocommerce' ),
					'default'     => false,
				);
				break;
		}
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => $required,
			'additionalProperties' => false,
		);
	}

	/**
	 * What the route answers, which its own schema describes: a list's
	 * items with its totals, or else the item, or for a replacing update
	 * the whole new list. Dates carry their offset.
	 *
	 * @return array<string, mixed>
	 */
	private function output_schema(): array {
		$dates = __( 'Dates and times are RFC 3339, with their offset.', 'pixonaut-for-woocommerce' );
		if ( StoreResource::LIST !== $this->operation ) {
			return array(
				'type'        => array( 'object', 'array' ),
				/* translators: 1: a REST API route; 2: a sentence on how dates are written. */
				'description' => sprintf( __( 'What the REST route %1$s answers, without its links. %2$s', 'pixonaut-for-woocommerce' ), '`' . $this->kind->route_of( $this->operation ) . '`', $dates ),
			);
		}
		$count = array(
			'type'    => array( 'integer', 'null' ),
			'minimum' => 0,
		);
		return array(
			'type'        => 'object',
			'description' => $dates,
			'properties'  => array(
				'items' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
				'total' => $count,
				'pages' => $count,
			),
		);
	}

	/**
	 * The route's answer as the ability gives it, or why it refused.
	 *
	 * @param WP_REST_Response $response The route's answer.
	 * @return mixed
	 */
	private function answer( WP_REST_Response $response ): mixed {
		if ( $response->is_error() ) {
			return self::refusal( $response );
		}
		$data = self::data( $response );
		if ( StoreResource::LIST !== $this->operation ) {
			return $data;
		}
		$headers = $response->get_headers();
		return array(
			'items' => is_array( $data ) ? $data : array(),
			'total' => isset( $headers[ self::TOTAL_HEADER ] ) ? (int) $headers[ self::TOTAL_HEADER ] : null,
			'pages' => isset( $headers[ self::PAGES_HEADER ] ) ? (int) $headers[ self::PAGES_HEADER ] : null,
		);
	}

	/**
	 * What a route answered, as JSON reads it, so an object in it is an
	 * array like the rest, without the links some routes put in it, which
	 * only the REST API's own clients follow, and with the offset of each
	 * date its schema declares.
	 *
	 * @param WP_REST_Response $response A route's answer.
	 * @return mixed
	 */
	private static function data( WP_REST_Response $response ): mixed {
		return RouteDates::with_offsets( self::unlinked( json_decode( (string) wp_json_encode( $response->get_data() ), true ) ), $response );
	}

	/**
	 * @param mixed $value A route's answer, or a part of one.
	 * @return mixed
	 */
	private static function unlinked( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		unset( $value['_links'] );
		return array_map( array( self::class, 'unlinked' ), $value );
	}

	/**
	 * Why a route refused, as its answer says.
	 *
	 * @param WP_REST_Response $response A route's answer that is an error.
	 */
	public static function refusal( WP_REST_Response $response ): WP_Error {
		return $response->as_error() ?? new WP_Error( 'pixonaut_wc_store_route_refused', __( 'The store refused it.', 'pixonaut-for-woocommerce' ) );
	}

	/**
	 * Downloads a file and adds it to the media library through the route.
	 * WordPress's `download_url()` downloads it, which refuses a URL of the
	 * store's own network and names the file for its type when its URL
	 * names none.
	 *
	 * @param string       $path   The route.
	 * @param array<mixed> $fields `source_url`, and the attachment's other fields.
	 * @return mixed
	 */
	private static function upload( string $path, array $fields ): mixed {
		$url = $fields[ self::SOURCE_URL ] ?? null;
		if ( ! is_string( $url ) || ! in_array( wp_parse_url( $url, PHP_URL_SCHEME ), array( 'http', 'https' ), true ) ) {
			return new WP_Error( 'pixonaut_wc_store_media_url', __( 'Give the file\'s public URL as fields.source_url.', 'pixonaut-for-woocommerce' ), array( 'status' => self::BAD_REQUEST ) );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$file = download_url( $url, self::DOWNLOAD_TIMEOUT_SECONDS );
		if ( is_wp_error( $file ) ) {
			return self::download_refusal( $file );
		}
		try {
			$size = (int) filesize( $file );
			if ( $size > wp_max_upload_size() ) {
				/* translators: %s: the largest file the store takes, e.g. "64 MB". */
				return new WP_Error( 'pixonaut_wc_store_media_size', sprintf( __( 'The file is larger than the store takes, at most %s.', 'pixonaut-for-woocommerce' ), size_format( wp_max_upload_size() ) ), array( 'status' => self::BAD_REQUEST ) );
			}
			$name = sanitize_file_name( wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
			if ( '' === pathinfo( $name, PATHINFO_EXTENSION ) ) {
				// `download_url()` gave the file the extension of the type it was answered with.
				$extension = pathinfo( $file, PATHINFO_EXTENSION );
				$name      = ( '' === $name ? 'file' : $name ) . ( self::TEMPORARY_EXTENSION === $extension ? '' : '.' . $extension );
			}
			$type = wp_check_filetype( $name )['type'];

			unset( $fields[ self::SOURCE_URL ] );
			$request = new WP_REST_Request( 'POST', $path );
			$request->set_header( 'Content-Type', false === $type ? 'application/octet-stream' : $type );
			$request->set_header( 'Content-Disposition', 'attachment; filename="' . $name . '"' );
			$request->set_body( (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the downloaded temporary file.
			$request->set_query_params( $fields );
			$response = rest_do_request( $request );
			return $response->is_error() ? self::refusal( $response ) : self::data( $response );
		} finally {
			wp_delete_file( $file );
		}
	}

	/**
	 * Why a file could not be downloaded: the status its URL answered with,
	 * or WordPress's reason when no answer came.
	 *
	 * @param WP_Error $error What `download_url()` answered.
	 */
	private static function download_refusal( WP_Error $error ): WP_Error {
		$data   = $error->get_error_data();
		$status = is_array( $data ) && is_int( $data['code'] ?? null ) ? $data['code'] : null;
		if ( null === $status ) {
			return $error;
		}
		/* translators: %d: the HTTP status the file's URL answered with. */
		return new WP_Error( 'pixonaut_wc_store_media_download', sprintf( __( 'The file could not be downloaded: its URL answered %d.', 'pixonaut-for-woocommerce' ), $status ), array( 'status' => self::BAD_REQUEST ) );
	}
}
