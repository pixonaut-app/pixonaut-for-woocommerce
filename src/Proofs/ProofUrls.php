<?php
/**
 * URLs of proof images, signed by the store.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Proofs;

use Pixonaut\WooCommerce\Plugin;

/**
 * A proof image is never shown from a Pixonaut URL: the cart, the Store API,
 * emails, My Account and the admin all link to the plugin's proof route,
 * which fetches the image with the store's key. The route is public (an
 * email is opened without a session), so each URL carries an HMAC of its
 * design, proof, width and picture, and only a URL minted here is served.
 */
final class ProofUrls {

	/**
	 * Path of the proof route below the plugin's namespace; the design id follows.
	 */
	public const ROUTE = '/proof';

	/**
	 * The widths, in pixels, a proof is served at. Every requested width is
	 * rounded up to one of them, so the cache holds a few sizes per design
	 * rather than one per width a theme asks for.
	 */
	public const WIDTHS = array( 160, 320, 640, self::LARGEST_WIDTH );

	/**
	 * The width a proof opened on its own is served at.
	 */
	public const LARGEST_WIDTH = 1280;

	/**
	 * What a design or proof id may hold: Pixonaut's ids are UUIDs or
	 * `prefix_hex`, which this covers without letting a path or a query in.
	 */
	public const ID_PATTERN = '[A-Za-z0-9_-]{1,64}';

	/**
	 * The `wp_salt()` scheme the signing key comes from. Not one of
	 * WordPress's own, so its key is derived from the site's secret key and
	 * differs from every other scheme's.
	 */
	public const SALT_SCHEME = 'pixonaut_proof';

	private const HMAC_ALGORITHM = 'sha256';

	/**
	 * @param string $key Secret the signatures are made with; a site salt.
	 */
	public function __construct( private readonly string $key ) {
	}

	/**
	 * The proof route's URL for a design's proof, at the smallest served
	 * width that is at least $width.
	 *
	 * @param string            $design_id The design.
	 * @param string            $proof_id  The design's `proof_id`, which names the image.
	 * @param int               $width     Width in pixels the image is drawn at; a picture on the blank's long edge.
	 * @param ProofPicture|null $picture   The picture; null for the flat proof.
	 */
	public function url( string $design_id, string $proof_id, int $width, ?ProofPicture $picture = null ): string {
		$served = self::served_width( $width );
		$query  = $picture?->query();
		return add_query_arg(
			array(
				'v'   => rawurlencode( $proof_id ),
				'w'   => $served,
				'p'   => null === $query ? null : rawurlencode( $query ),
				'sig' => $this->signature( $design_id, $proof_id, $served, $query ),
			),
			rest_url( Plugin::REST_NAMESPACE . self::ROUTE . '/' . rawurlencode( $design_id ) )
		);
	}

	/**
	 * Whether a request names a proof this store minted a URL for.
	 *
	 * @param string            $design_id The design.
	 * @param string            $proof_id  The `v` it carries.
	 * @param int               $width     The `w` it carries.
	 * @param string            $signature The `sig` it carries.
	 * @param ProofPicture|null $picture   The picture its `p` names; null for the flat proof.
	 */
	public function verify( string $design_id, string $proof_id, int $width, string $signature, ?ProofPicture $picture = null ): bool {
		return self::is_served_width( $width ) && hash_equals( $this->signature( $design_id, $proof_id, $width, $picture?->query() ), $signature );
	}

	/**
	 * The smallest served width at least $width; the largest for anything wider.
	 *
	 * @param int $width Width in pixels.
	 */
	public static function served_width( int $width ): int {
		foreach ( self::WIDTHS as $served ) {
			if ( $served >= $width ) {
				return $served;
			}
		}
		return self::LARGEST_WIDTH;
	}

	/**
	 * @param int $width Width in pixels.
	 */
	public static function is_served_width( int $width ): bool {
		return in_array( $width, self::WIDTHS, true );
	}

	/**
	 * @param string      $design_id The design.
	 * @param string      $proof_id  The proof.
	 * @param int         $width     Served width.
	 * @param string|null $picture   The picture's `p`; null for the flat proof, which signs as URLs did before pictures had one.
	 */
	private function signature( string $design_id, string $proof_id, int $width, ?string $picture ): string {
		// Newline-separated: no part can hold one, so no two sets sign alike.
		$signed = $design_id . "\n" . $proof_id . "\n" . $width . ( null === $picture ? '' : "\n" . $picture );
		return hash_hmac( self::HMAC_ALGORITHM, $signed, $this->key );
	}
}
