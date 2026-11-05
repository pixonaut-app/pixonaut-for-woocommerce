<?php
/**
 * The plugin's proof image route.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Proofs;

use Pixonaut\WooCommerce\Api\ApiError;
use Pixonaut\WooCommerce\Api\Designs;
use Pixonaut\WooCommerce\Api\ErrorCodes;
use Pixonaut\WooCommerce\Api\Logger;
use Pixonaut\WooCommerce\Plugin;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `GET /pixonaut/v1/proof/<designId>?v=<proof_id>&w=<width>[&p=<picture>]&sig=<hmac>`
 * serves a proof image to anyone holding a URL `ProofUrls` minted: from the
 * store's cache, else from Pixonaut with the store's key. Without `p` it is
 * the flat proof (a JPEG); with one, the proof on its blank's photo (a PNG,
 * `ProofPicture`). `v` names bytes that never change, so a browser may keep
 * the answer for good. A picture on the blank Pixonaut cannot draw, while
 * the proof is there, is answered with the flat proof for
 * `ProofCache::REFUSAL_SECONDS` instead, without asking Pixonaut again.
 */
final class ProofRoute {

	public const CACHE_CONTROL = 'public, max-age=31536000, immutable';

	/**
	 * An answer that is not the image may change, so nobody keeps it.
	 */
	private const NO_STORE = 'no-store';

	public const CONTENT_TYPE = 'image/jpeg';

	public const ON_BLANK_CONTENT_TYPE = 'image/png';

	/**
	 * The flat proof standing in for a picture on the blank Pixonaut could
	 * not draw is kept as long as the store remembers the refusal, so the
	 * picture is asked for again after it.
	 */
	public const STAND_IN_CACHE_CONTROL = 'public, max-age=' . ProofCache::REFUSAL_SECONDS;

	/**
	 * Pixonaut's answers that say the image is not there to serve.
	 */
	private const GONE = array( ErrorCodes::NOT_FOUND, ErrorCodes::PROOF_NOT_FOUND, ErrorCodes::PROOF_CHANGED );

	/**
	 * Pixonaut's answers that say the proof a picture on the blank draws is
	 * not there; any other refusal is about the photo, view or drawing, and
	 * the flat proof still stands in for it.
	 */
	private const PROOF_GONE = array( ErrorCodes::PROOF_NOT_FOUND, ErrorCodes::PROOF_CHANGED );

	private const STATUS_FORBIDDEN = 403;

	private const STATUS_NOT_FOUND = 404;

	private const STATUS_BAD_GATEWAY = 502;

	/**
	 * @param ProofUrls  $urls    Checks the request's signature.
	 * @param ProofCache $cache   The store's copies.
	 * @param Designs    $designs Fetches an image the cache lacks.
	 * @param Logger     $logger  Logs an image Pixonaut could not give.
	 */
	public function __construct(
		private readonly ProofUrls $urls,
		private readonly ProofCache $cache,
		private readonly Designs $designs,
		private readonly Logger $logger,
	) {
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
		add_filter( 'rest_pre_serve_request', array( $this, 'serve' ), 10, 3 );
	}

	public function register_route(): void {
		register_rest_route(
			Plugin::REST_NAMESPACE,
			ProofUrls::ROUTE . '/(?P<design>' . ProofUrls::ID_PATTERN . ')',
			array(
				'methods'             => WP_REST_Server::READABLE,
				// Emails and pages without a session show it; the signature is the permission.
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'handle' ),
				'args'                => array(
					'v'   => array(
						'type'     => 'string',
						'required' => true,
						'pattern'  => '^' . ProofUrls::ID_PATTERN . '$',
					),
					'w'   => array(
						'type'     => 'integer',
						'required' => true,
					),
					'p'   => array(
						'type'     => 'string',
						'required' => false,
					),
					'sig' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request The request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$design_id = (string) $request['design'];
		$proof_id  = (string) $request['v'];
		$width     = (int) $request['w'];
		$picture   = ProofPicture::from_query( is_string( $request['p'] ) ? $request['p'] : null );
		if ( null === $picture || ! $this->urls->verify( $design_id, $proof_id, $width, (string) $request['sig'], $picture ) ) {
			/* translators: Error message of the proof REST route, shown to the shopper who opened a proof link. */
			return self::refusal( self::STATUS_FORBIDDEN, 'pixonaut_proof_forbidden', __( 'This proof link is not valid.', 'pixonaut-for-woocommerce' ) );
		}

		if ( ProofCache::is_erased( $design_id ) ) {
			// Pixonaut deletes an erased design's proof; asking for it would only spend the render budget.
			return self::not_found();
		}
		if ( $picture->on_blank && ProofCache::STAND_IN === $this->cache->refusal( $design_id, $proof_id, $width, $picture ) ) {
			// Pixonaut refused it a moment ago; asking again would only spend the render budget.
			return $this->stand_in( $design_id, $proof_id, $width );
		}
		try {
			$image = $this->image_of( $design_id, $proof_id, $width, $picture );
		} catch ( ApiError $error ) {
			if ( in_array( $error->error_code, $picture->on_blank ? self::PROOF_GONE : self::GONE, true ) ) {
				return self::not_found();
			}
			$this->log( $design_id, $picture, $error );
			// The proof itself may still be there: it stands in until the picture can be drawn.
			return $picture->on_blank ? $this->stand_in( $design_id, $proof_id, $width ) : self::unavailable();
		}
		if ( null !== $image ) {
			return self::image( $image, $picture );
		}
		// The request this one waited for may have been refused the picture; answer as it did.
		$refusal = $picture->on_blank ? $this->cache->refusal( $design_id, $proof_id, $width, $picture ) : null;
		if ( ProofCache::GONE === $refusal ) {
			return self::not_found();
		}
		return ProofCache::STAND_IN === $refusal ? $this->stand_in( $design_id, $proof_id, $width ) : self::unavailable();
	}

	/**
	 * The flat proof, answered for a picture on the blank that could not be drawn.
	 *
	 * @param string $design_id The design.
	 * @param string $proof_id  The proof.
	 * @param int    $width     Served width.
	 */
	private function stand_in( string $design_id, string $proof_id, int $width ): WP_REST_Response {
		$flat = ProofPicture::flat();
		try {
			$image = $this->image_of( $design_id, $proof_id, $width, $flat );
		} catch ( ApiError $error ) {
			if ( in_array( $error->error_code, self::GONE, true ) ) {
				return self::not_found();
			}
			$this->log( $design_id, $flat, $error );
			return self::unavailable();
		}
		return null === $image ? self::unavailable() : self::image( $image, $flat, self::STAND_IN_CACHE_CONTROL );
	}

	/**
	 * The picture's bytes: from the cache, else fetched from Pixonaut and kept.
	 *
	 * @param string       $design_id The design.
	 * @param string       $proof_id  The proof.
	 * @param int          $width     Served width.
	 * @param ProofPicture $picture   The picture.
	 *
	 * @return string|null Null when another request was fetching it and it did not land in time.
	 *
	 * @throws ApiError When Pixonaut could not give it.
	 */
	private function image_of( string $design_id, string $proof_id, int $width, ProofPicture $picture ): ?string {
		$image = $this->cache->get( $design_id, $proof_id, $width, $picture );
		if ( null !== $image ) {
			return $image;
		}
		if ( ! $this->cache->lock( $design_id, $proof_id, $width, $picture ) ) {
			// Another request is fetching it; a second fetch would spend the render budget again.
			return $this->cache->wait( $design_id, $proof_id, $width, $picture );
		}
		try {
			// The request that held the lock before may have kept it meanwhile.
			$image = $this->cache->get( $design_id, $proof_id, $width, $picture );
			if ( null === $image ) {
				$image = $this->fetch( $design_id, $proof_id, $width, $picture );
				$this->cache->put( $design_id, $proof_id, $width, $image, $picture );
			}
			return $image;
		} finally {
			$this->cache->unlock( $design_id, $proof_id, $width, $picture );
		}
	}

	/**
	 * The picture's bytes from Pixonaut. A picture on the blank it refuses is
	 * remembered while the lock is still held, so a request waiting on it
	 * finds the refusal as the lock goes.
	 *
	 * @param string       $design_id The design.
	 * @param string       $proof_id  The proof.
	 * @param int          $width     Served width.
	 * @param ProofPicture $picture   The picture.
	 *
	 * @throws ApiError When Pixonaut could not give it.
	 */
	private function fetch( string $design_id, string $proof_id, int $width, ProofPicture $picture ): string {
		if ( ! $picture->on_blank ) {
			return $this->designs->proof( $design_id, $proof_id, $width );
		}
		try {
			return $this->designs->mockup( $design_id, $proof_id, $picture->view_id, $width, $picture->variant );
		} catch ( ApiError $error ) {
			$refusal = in_array( $error->error_code, self::PROOF_GONE, true ) ? ProofCache::GONE : ProofCache::STAND_IN;
			$this->cache->refuse( $design_id, $proof_id, $width, $picture, $refusal );
			throw $error;
		}
	}

	/**
	 * @param string       $design_id The design.
	 * @param ProofPicture $picture   The picture asked for.
	 * @param ApiError     $error     Why Pixonaut did not give it.
	 */
	private function log( string $design_id, ProofPicture $picture, ApiError $error ): void {
		$this->logger->warning(
			sprintf( $picture->on_blank ? 'Could not fetch design %s on its blank: %s' : 'Could not fetch the proof of design %s: %s', $design_id, $error->getMessage() ),
			array(
				'design_id' => $design_id,
				'code'      => $error->error_code,
			)
		);
	}

	private static function not_found(): WP_REST_Response {
		/* translators: Error message of the proof REST route, shown to the shopper who opened a proof link. */
		return self::refusal( self::STATUS_NOT_FOUND, 'pixonaut_proof_not_found', __( 'This proof is no longer available.', 'pixonaut-for-woocommerce' ) );
	}

	private static function unavailable(): WP_REST_Response {
		/* translators: Error message of the proof REST route, shown to the shopper who opened a proof link. */
		return self::refusal( self::STATUS_BAD_GATEWAY, 'pixonaut_proof_unavailable', __( 'This proof cannot be shown right now.', 'pixonaut-for-woocommerce' ) );
	}

	/**
	 * @param string       $image         The image's bytes.
	 * @param ProofPicture $picture       The picture they are.
	 * @param string       $cache_control How long a browser may keep them.
	 */
	private static function image( string $image, ProofPicture $picture, string $cache_control = self::CACHE_CONTROL ): WP_REST_Response {
		return new WP_REST_Response(
			$image,
			200,
			array(
				'Content-Type'           => $picture->on_blank ? self::ON_BLANK_CONTENT_TYPE : self::CONTENT_TYPE,
				'Content-Length'         => (string) strlen( $image ),
				'Cache-Control'          => $cache_control,
				'X-Content-Type-Options' => 'nosniff',
			)
		);
	}

	/**
	 * Sends the image's bytes as they are; the REST server would encode them as JSON.
	 *
	 * @param mixed $served  Whether the answer was sent already.
	 * @param mixed $result  The answer.
	 * @param mixed $request The request.
	 */
	public function serve( mixed $served, mixed $result, mixed $request ): mixed {
		if ( $served || ! $result instanceof WP_REST_Response || ! $request instanceof WP_REST_Request
			|| ! self::is_proof_route( $request->get_route() ) || 200 !== $result->get_status() || ! is_string( $result->get_data() ) ) {
			return $served;
		}
		// For a signed-in visitor the REST server sends its no-cache headers after
		// the answer's own, so the image's Cache-Control is sent again over them.
		if ( ! headers_sent() ) {
			header_remove( 'Expires' );
			header_remove( 'Pragma' );
			$cache_control = $result->get_headers()['Cache-Control'] ?? self::CACHE_CONTROL;
			header( 'Cache-Control: ' . ( is_string( $cache_control ) ? $cache_control : self::CACHE_CONTROL ) );
		}
		echo $result->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the image's bytes.
		return true;
	}

	private static function is_proof_route( string $route ): bool {
		return str_starts_with( $route, '/' . Plugin::REST_NAMESPACE . ProofUrls::ROUTE . '/' );
	}

	private static function refusal( int $status, string $code, string $message ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'code'    => $code,
				'message' => $message,
				'data'    => array( 'status' => $status ),
			),
			$status,
			array( 'Cache-Control' => self::NO_STORE )
		);
	}
}
