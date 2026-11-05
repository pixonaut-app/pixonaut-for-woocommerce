<?php
/**
 * Which picture of a design's proof the proof route serves.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Proofs;

/**
 * A design's proof as the customer saw it flat (`flat()`), or drawn on its
 * blank's photo (`on_blank()`) in a view the store names or, when it names
 * none, the view the proof was previewed in, and on the photo of a variant
 * the store names or, when it names none, the variant the proof was
 * previewed on. A URL names it in its `p` query, which a flat proof leaves
 * out.
 */
final class ProofPicture {

	/**
	 * `p` of a picture on the blank; a view's id follows after `VIEW_SEPARATOR`,
	 * then, after another, a variant's ref (`blank::v_…` names a variant
	 * without a view).
	 */
	private const ON_BLANK = 'blank';

	/**
	 * Between `ON_BLANK` and a view's id: `ProofUrls::ID_PATTERN` holds no colon.
	 */
	private const VIEW_SEPARATOR = ':';

	/**
	 * `ON_BLANK`, a view and a variant.
	 */
	private const MOST_PARTS = 3;

	/**
	 * @param bool        $on_blank Whether the proof is drawn on the blank's photo.
	 * @param string|null $view_id  The view it is drawn in; null for the one the proof was previewed in.
	 * @param string|null $variant  The variant whose photo it is drawn on; null for the one the proof was previewed on.
	 */
	private function __construct( public readonly bool $on_blank, public readonly ?string $view_id, public readonly ?string $variant = null ) {
	}

	public static function flat(): self {
		return new self( false, null );
	}

	/**
	 * @param string|null $view_id One of the placement's views; null for the one the proof was previewed in.
	 * @param string|null $variant One of the blank's variants, `v_…`; null for the one the proof was previewed on.
	 */
	public static function on_blank( ?string $view_id = null, ?string $variant = null ): self {
		return new self( true, $view_id, $variant );
	}

	/**
	 * The `p` a URL carries; null for a flat proof.
	 */
	public function query(): ?string {
		if ( ! $this->on_blank ) {
			return null;
		}
		$parts = array( self::ON_BLANK );
		if ( null !== $this->view_id || null !== $this->variant ) {
			$parts[] = $this->view_id ?? '';
		}
		if ( null !== $this->variant ) {
			$parts[] = $this->variant;
		}
		return implode( self::VIEW_SEPARATOR, $parts );
	}

	/**
	 * The picture a `p` names; null when it names none.
	 *
	 * @param string|null $query The `p` a request carries; null when it carries none.
	 */
	public static function from_query( ?string $query ): ?self {
		if ( null === $query ) {
			return self::flat();
		}
		$parts = explode( self::VIEW_SEPARATOR, $query );
		if ( self::ON_BLANK !== $parts[0] || count( $parts ) > self::MOST_PARTS ) {
			return null;
		}
		$view_id = $parts[1] ?? null;
		$variant = $parts[2] ?? null;
		// A part named is an id; only a view left out before a variant is empty.
		if ( '' === $variant || ( '' === $view_id && null === $variant ) ) {
			return null;
		}
		foreach ( array( $view_id, $variant ) as $part ) {
			if ( null !== $part && '' !== $part && 1 !== preg_match( '/^' . ProofUrls::ID_PATTERN . '$/', $part ) ) {
				return null;
			}
		}
		return self::on_blank( '' === $view_id ? null : $view_id, $variant );
	}
}
