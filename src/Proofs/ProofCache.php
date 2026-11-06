<?php
/**
 * The store's copies of proof images.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Proofs;

use Closure;
use Pixonaut\WooCommerce\Api\WpHttpTransport;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Schema;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Http\PreparedRequest;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Http\Result;

/**
 * One transient per design, served width and picture (the flat proof, or
 * the proof on its blank in a view), holding the image and the proof id
 * and picture it is. A read names the proof id and picture it wants, so an
 * entry for a proof the design no longer shows is a miss. The flat proof's
 * entries are one per width in `ProofUrls::WIDTHS`; the pictures on the
 * blank a design has entries for are listed in its index transient, so
 * `purge()` finds them without knowing the views.
 *
 * A picture on the blank Pixonaut refused is remembered for REFUSAL_SECONDS
 * (`refuse()`), per proof, width and picture: the route then serves the
 * flat proof for it without asking again, and a request that waited on the
 * failed fetch answers as the fetching one did.
 *
 * A design whose personal data was erased is marked for TTL_SECONDS
 * (`forget()`): while it is, nothing is cached for it or read, so a fetch
 * that was running during the erasure does not keep the image. One no
 * order line holds, in a cart, is also marked for good, until the design
 * is deleted: an order line keeps its erasure itself, a cart line cannot.
 *
 * Each fetch spends the tenant's render budget, so one request at a time
 * fetches an image (`lock()`); the others wait for it to land (`wait()`).
 */
final class ProofCache {

	private const PREFIX = Schema::PROOF_CACHE_PREFIX;

	/**
	 * Ends the transient name of a picture on the blank.
	 */
	private const ON_BLANK_SUFFIX = '_blank_';

	/**
	 * Ends the transient name of a refused picture on the blank.
	 */
	private const REFUSAL_SUFFIX = '_refused_';

	/**
	 * Ends the transient name of a design's index of pictures on the blank.
	 */
	private const INDEX_SUFFIX = '_pictures';

	/**
	 * `refusal()` of a picture on the blank Pixonaut could not draw while the
	 * proof is there: the flat proof stands in for it.
	 */
	public const STAND_IN = 'stand_in';

	/**
	 * `refusal()` of a picture on the blank whose proof is gone.
	 */
	public const GONE = 'gone';

	/**
	 * How long a refused picture on the blank is remembered, and a browser
	 * keeps the flat proof standing in for it: an hour. Not `HOUR_IN_SECONDS`,
	 * which is not defined outside WordPress.
	 */
	public const REFUSAL_SECONDS = 60 * 60;

	/**
	 * After this long a lock is taken to belong to a request that died: the
	 * client's timeout, and as long again for the cache write.
	 */
	public const LOCK_SECONDS = 2 * WpHttpTransport::TIMEOUT_SECONDS;

	/**
	 * How long a request waits for another's fetch: the client's timeout.
	 */
	public const WAIT_SECONDS = WpHttpTransport::TIMEOUT_SECONDS;

	/**
	 * Between two looks at the cache while waiting: a quarter of a second.
	 */
	private const WAIT_STEP_MICROSECONDS = 250_000;

	private const MICROSECONDS_PER_SECOND = 1_000_000;

	/**
	 * Index of the design's id among a design path's segments: `v1`, `designs`, the id.
	 */
	private const DESIGN_ID_SEGMENT = 2;

	/**
	 * How long an image is kept after it was fetched: a week. A proof's bytes
	 * never change; this only bounds what a store keeps for designs nobody
	 * looks at. Not `WEEK_IN_SECONDS`, which is not defined outside WordPress.
	 */
	public const TTL_SECONDS = 7 * 24 * 60 * 60;

	/**
	 * Per operation, the statuses after which a design's proof images are
	 * gone: `DELETE` deleted it now or before; an erasure erased it, or
	 * found it deleted.
	 */
	private const DELETE_DESIGN = 'designs.delete';

	private const ERASE_DESIGN = 'designs.erasePersonalData';

	private const GONE_STATUSES = array(
		self::DELETE_DESIGN => array( 204, 404 ),
		self::ERASE_DESIGN  => array( 200, 204 ),
	);

	private readonly Closure $sleep;

	private readonly Closure $clock;

	/**
	 * @param Closure|null $sleep `fn( int $microseconds ): void`; `usleep()` when null.
	 * @param Closure|null $clock `fn(): int` Unix time; `time()` when null.
	 */
	public function __construct( ?Closure $sleep = null, ?Closure $clock = null ) {
		$this->sleep = $sleep ?? static function ( int $microseconds ): void {
			usleep( $microseconds );
		};
		$this->clock = $clock ?? static fn (): int => time();
	}

	public function register(): void {
		add_action( 'pixonaut_wc_api_after_request', array( $this, 'purge_gone' ), 10, 3 );
		add_action( 'pixonaut_wc_webhook_design_personal_data_erased', array( $this, 'purge_erased' ) );
	}

	/**
	 * The cached image, or null.
	 *
	 * @param string            $design_id The design.
	 * @param string            $proof_id  The proof the image must be.
	 * @param int               $width     Served width.
	 * @param ProofPicture|null $picture   The picture it must be; null for the flat proof.
	 */
	public function get( string $design_id, string $proof_id, int $width, ?ProofPicture $picture = null ): ?string {
		if ( self::is_erased( $design_id ) ) {
			return null;
		}
		$entry = get_transient( self::key( $design_id, $width, $picture ) );
		if ( ! is_array( $entry ) || ( $entry['proofId'] ?? null ) !== $proof_id || ( $entry['picture'] ?? null ) !== $picture?->query()
			|| ! is_string( $entry['jpeg'] ?? null ) ) {
			return null;
		}
		// Stored base64: an option value is text, and a database charset may mangle raw bytes.
		$image = base64_decode( $entry['jpeg'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- image bytes kept as text.
		return false === $image || '' === $image ? null : $image;
	}

	/**
	 * @param string            $design_id The design.
	 * @param string            $proof_id  The proof the image is.
	 * @param int               $width     Served width.
	 * @param string            $image     The image.
	 * @param ProofPicture|null $picture   The picture it is; null for the flat proof.
	 */
	public function put( string $design_id, string $proof_id, int $width, string $image, ?ProofPicture $picture = null ): void {
		if ( self::is_erased( $design_id ) ) {
			return;
		}
		set_transient(
			self::key( $design_id, $width, $picture ),
			array(
				'proofId' => $proof_id,
				'picture' => $picture?->query(),
				// Named for the flat proof's JPEG, which entries held before pictures on the blank.
				'jpeg'    => base64_encode( $image ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- image bytes kept as text.
			),
			self::TTL_SECONDS
		);
		$this->index( $design_id, $picture );
	}

	/**
	 * Remembers that Pixonaut refused a picture on the blank, for REFUSAL_SECONDS.
	 *
	 * @param string       $design_id The design.
	 * @param string       $proof_id  The proof.
	 * @param int          $width     Served width.
	 * @param ProofPicture $picture   The picture on the blank.
	 * @param string       $refusal   STAND_IN or GONE.
	 */
	public function refuse( string $design_id, string $proof_id, int $width, ProofPicture $picture, string $refusal ): void {
		if ( self::is_erased( $design_id ) ) {
			return;
		}
		set_transient(
			self::refusal_key( $design_id, $width, $picture ),
			array(
				'proofId' => $proof_id,
				'refusal' => $refusal,
				'since'   => ( $this->clock )(),
			),
			self::REFUSAL_SECONDS
		);
		$this->index( $design_id, $picture );
	}

	/**
	 * How Pixonaut refused the picture within REFUSAL_SECONDS: STAND_IN or GONE; null when it did not.
	 *
	 * @param string       $design_id The design.
	 * @param string       $proof_id  The proof.
	 * @param int          $width     Served width.
	 * @param ProofPicture $picture   The picture on the blank.
	 */
	public function refusal( string $design_id, string $proof_id, int $width, ProofPicture $picture ): ?string {
		$entry = get_transient( self::refusal_key( $design_id, $width, $picture ) );
		if ( ! is_array( $entry ) || ( $entry['proofId'] ?? null ) !== $proof_id || ! is_int( $entry['since'] ?? null )
			|| ( $this->clock )() - $entry['since'] >= self::REFUSAL_SECONDS ) {
			return null;
		}
		$refusal = $entry['refusal'] ?? null;
		return self::STAND_IN === $refusal || self::GONE === $refusal ? $refusal : null;
	}

	/**
	 * Lists a picture on the blank in its design's index, so `purge()` finds its entries.
	 *
	 * @param string            $design_id The design.
	 * @param ProofPicture|null $picture   The picture; null for the flat proof, which needs no listing.
	 */
	private function index( string $design_id, ?ProofPicture $picture ): void {
		$query = $picture?->query();
		if ( null === $query ) {
			return;
		}
		$queries = self::indexed( $design_id );
		if ( ! in_array( $query, $queries, true ) ) {
			$queries[] = $query;
		}
		// Kept as long as an entry it lists, from the last one written.
		set_transient( self::index_key( $design_id ), $queries, self::TTL_SECONDS );
	}

	/**
	 * The pictures on the blank a design's index lists.
	 *
	 * @param string $design_id The design.
	 *
	 * @return list<ProofPicture>
	 */
	private static function indexed_pictures( string $design_id ): array {
		$pictures = array();
		foreach ( self::indexed( $design_id ) as $query ) {
			$picture = ProofPicture::from_query( $query );
			if ( null !== $picture ) {
				$pictures[] = $picture;
			}
		}
		return $pictures;
	}

	/**
	 * @param string $design_id The design.
	 *
	 * @return list<string> The `p` of each picture on the blank the index lists.
	 */
	private static function indexed( string $design_id ): array {
		$queries = get_transient( self::index_key( $design_id ) );
		return is_array( $queries ) ? array_values( array_filter( $queries, 'is_string' ) ) : array();
	}

	/**
	 * Takes the right to fetch an image. `add_option()` inserts or fails
	 * atomically, so two requests never both hold it; a lock older than
	 * LOCK_SECONDS is taken over.
	 *
	 * @param string            $design_id The design.
	 * @param string            $proof_id  The proof.
	 * @param int               $width     Served width.
	 * @param ProofPicture|null $picture   The picture; null for the flat proof.
	 */
	public function lock( string $design_id, string $proof_id, int $width, ?ProofPicture $picture = null ): bool {
		$option = self::lock_option( $design_id, $proof_id, $width, $picture );
		$now    = ( $this->clock )();
		if ( add_option( $option, (string) $now, '', false ) ) {
			return true;
		}
		$held_since = get_option( $option );
		if ( is_numeric( $held_since ) && $now - (int) $held_since < self::LOCK_SECONDS ) {
			return false;
		}
		delete_option( $option );
		return add_option( $option, (string) $now, '', false );
	}

	/**
	 * @param string            $design_id The design.
	 * @param string            $proof_id  The proof.
	 * @param int               $width     Served width.
	 * @param ProofPicture|null $picture   The picture; null for the flat proof.
	 */
	public function unlock( string $design_id, string $proof_id, int $width, ?ProofPicture $picture = null ): void {
		delete_option( self::lock_option( $design_id, $proof_id, $width, $picture ) );
	}

	/**
	 * Waits for another request's fetch to land, up to WAIT_SECONDS.
	 *
	 * @param string            $design_id The design.
	 * @param string            $proof_id  The proof.
	 * @param int               $width     Served width.
	 * @param ProofPicture|null $picture   The picture; null for the flat proof.
	 *
	 * @return string|null The image, or null when it did not arrive or the fetch ended without one
	 *                     (for a picture on the blank, `refusal()` may tell why).
	 */
	public function wait( string $design_id, string $proof_id, int $width, ?ProofPicture $picture = null ): ?string {
		$option = self::lock_option( $design_id, $proof_id, $width, $picture );
		$key    = self::key( $design_id, $width, $picture );
		$keys   = null === $picture || ! $picture->on_blank ? array( $key ) : array( $key, self::refusal_key( $design_id, $width, $picture ) );
		for ( $waited = 0; $waited < self::WAIT_SECONDS * self::MICROSECONDS_PER_SECOND; $waited += self::WAIT_STEP_MICROSECONDS ) {
			( $this->sleep )( self::WAIT_STEP_MICROSECONDS );
			// The other request writes to the database; drop what this request remembers of it.
			wp_cache_delete( 'notoptions', 'options' );
			wp_cache_delete( $option, 'options' );
			foreach ( $keys as $transient ) {
				wp_cache_delete( '_transient_' . $transient, 'options' );
				wp_cache_delete( '_transient_timeout_' . $transient, 'options' );
			}
			$image = $this->get( $design_id, $proof_id, $width, $picture );
			if ( null !== $image || false === get_option( $option ) ) {
				return $image;
			}
		}
		return null;
	}

	/**
	 * Drops every cached image of a design.
	 *
	 * @param string $design_id The design.
	 */
	public function purge( string $design_id ): void {
		$pictures = self::indexed_pictures( $design_id );
		foreach ( ProofUrls::WIDTHS as $width ) {
			delete_transient( self::key( $design_id, $width ) );
			foreach ( $pictures as $picture ) {
				delete_transient( self::key( $design_id, $width, $picture ) );
				delete_transient( self::refusal_key( $design_id, $width, $picture ) );
			}
		}
		delete_transient( self::index_key( $design_id ) );
		delete_option( self::erased_cart_design_key( $design_id ) );
	}

	/**
	 * Drops every cached image of a design whose personal data was erased,
	 * and keeps any from being cached again for TTL_SECONDS: an image fetched
	 * before the erasure may still be on its way to `put()`.
	 *
	 * @param string $design_id The design.
	 */
	public function forget( string $design_id ): void {
		set_transient( self::erased_key( $design_id ), true, self::TTL_SECONDS );
		$this->purge( $design_id );
		if ( array() === LineState::for_design_id( $design_id ) ) {
			update_option( self::erased_cart_design_key( $design_id ), true, false );
		}
	}

	/**
	 * Purges a design Pixonaut confirmed deleted or erased, whichever code
	 * asked for it; an erased one is forgotten.
	 *
	 * @param mixed $request The request sent.
	 * @param mixed $result  Its successful answer, or null.
	 * @param mixed $error   Why it did not succeed, or null.
	 */
	public function purge_gone( mixed $request, mixed $result, mixed $error = null ): void {
		$status = $result instanceof Result ? $result->status : ( $error instanceof ApiException ? $error->status : null );
		if ( ! $request instanceof PreparedRequest || ! in_array( $status, self::GONE_STATUSES[ $request->operation ] ?? array(), true ) ) {
			return;
		}
		// The design's id is the path segment after `/v1/designs/`.
		$segments = explode( '/', trim( (string) wp_parse_url( $request->target, PHP_URL_PATH ), '/' ) );
		$id       = rawurldecode( $segments[ self::DESIGN_ID_SEGMENT ] ?? '' );
		if ( '' === $id ) {
			return;
		}
		if ( self::ERASE_DESIGN === $request->operation ) {
			$this->forget( $id );
			return;
		}
		$this->purge( $id );
	}

	/**
	 * Forgets a design whose personal data was erased, as
	 * `design.personal_data_erased` reports.
	 *
	 * @param mixed $payload The event's payload.
	 */
	public function purge_erased( mixed $payload ): void {
		$id = is_array( $payload ) ? ( $payload['designId'] ?? null ) : null;
		if ( is_string( $id ) && '' !== $id ) {
			$this->forget( $id );
		}
	}

	/**
	 * Whether `forget()` marked the design's personal data erased: within
	 * TTL_SECONDS, or for good when no order line held it. Its proof is
	 * gone, or about to be.
	 *
	 * @param string $design_id The design.
	 */
	public static function is_erased( string $design_id ): bool {
		return false !== get_transient( self::erased_key( $design_id ) ) || false !== get_option( self::erased_cart_design_key( $design_id ) );
	}

	private static function erased_cart_design_key( string $design_id ): string {
		return Schema::ERASED_CART_DESIGN_PREFIX . md5( $design_id );
	}

	private static function erased_key( string $design_id ): string {
		return Schema::PROOF_ERASED_PREFIX . md5( $design_id );
	}

	private static function lock_option( string $design_id, string $proof_id, int $width, ?ProofPicture $picture ): string {
		$query = $picture?->query();
		return Schema::PROOF_LOCK_OPTION_PREFIX . md5( $design_id . "\n" . $proof_id . "\n" . $width . ( null === $query ? '' : "\n" . $query ) );
	}

	/**
	 * The transient of a design's picture at a width: one for the flat
	 * proof, and one for each picture on the blank.
	 *
	 * @param string            $design_id The design.
	 * @param int               $width     Served width.
	 * @param ProofPicture|null $picture   The picture; null for the flat proof.
	 */
	private static function key( string $design_id, int $width, ?ProofPicture $picture = null ): string {
		$query = $picture?->query();
		// Hashed: a transient name has a length limit, and a design id and view id are Pixonaut's to shape.
		return self::PREFIX . md5( $design_id ) . '_' . $width . ( null === $query ? '' : self::ON_BLANK_SUFFIX . md5( $query ) );
	}

	/**
	 * The transient remembering Pixonaut refused a picture on the blank at a width.
	 *
	 * @param string       $design_id The design.
	 * @param int          $width     Served width.
	 * @param ProofPicture $picture   The picture on the blank.
	 */
	private static function refusal_key( string $design_id, int $width, ProofPicture $picture ): string {
		return self::PREFIX . md5( $design_id ) . '_' . $width . self::REFUSAL_SUFFIX . md5( (string) $picture->query() );
	}

	private static function index_key( string $design_id ): string {
		return self::PREFIX . md5( $design_id ) . self::INDEX_SUFFIX;
	}
}
