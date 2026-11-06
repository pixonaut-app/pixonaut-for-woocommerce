<?php
/**
 * An update of a design the customer edits on a product page that was
 * refused.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Cart;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\ErrorCode;
use Pixonaut\WooCommerce\Labels;
use RuntimeException;

/**
 * Carries the reason the storefront script acts on, the HTTP status the
 * plugin's route answers with, and the label of the message the customer
 * sees.
 */
final class EditRefused extends RuntimeException {

	/**
	 * The cart line, or its side, is no longer in the cart.
	 */
	public const LINE_GONE = 'line_gone';

	/**
	 * The checkbox approval mode's box was not ticked.
	 */
	public const APPROVAL_REQUIRED = 'approval_required';

	/**
	 * The store is not connected, or its connection is paused.
	 */
	public const UNAVAILABLE = 'personalisation_unavailable';

	/**
	 * The design was corrected elsewhere since the page loaded: reload.
	 */
	public const REVISION_CHANGED = ErrorCode::REVISION_CHANGED;

	/**
	 * The correction's proof expired: approve it again.
	 */
	public const PROOF_EXPIRED = ErrorCode::PROOF_EXPIRED;

	/**
	 * The design takes no more corrections: editing closes.
	 */
	public const NOT_EDITABLE = ErrorCode::NOT_EDITABLE;

	/**
	 * The proof the customer asked to change shows an older revision than
	 * the order line holds: they were sent a newer one.
	 */
	public const PROOF_CHANGED = 'proof_changed';

	/**
	 * The order line's design was approved: it takes no more changes here.
	 */
	public const EDIT_CLOSED = 'edit_closed';

	/**
	 * Any other refusal, or Pixonaut could not be reached.
	 */
	public const NOT_SAVED = 'design_not_saved';

	/**
	 * Status and label key of each reason.
	 */
	private const ANSWERS = array(
		self::LINE_GONE         => array( 404, Labels::EDIT_LINE_GONE ),
		self::APPROVAL_REQUIRED => array( 400, Labels::APPROVAL_REQUIRED ),
		self::UNAVAILABLE       => array( 503, Labels::PERSONALISATION_UNAVAILABLE ),
		self::REVISION_CHANGED  => array( 409, Labels::DESIGN_CHANGED_ELSEWHERE ),
		self::PROOF_EXPIRED     => array( 410, Labels::DESIGN_EXPIRED ),
		self::NOT_EDITABLE      => array( 409, Labels::DESIGN_NOT_EDITABLE ),
		self::PROOF_CHANGED     => array( 409, Labels::PROOF_CHANGED ),
		self::EDIT_CLOSED       => array( 409, Labels::EDIT_CLOSED ),
		self::NOT_SAVED         => array( 502, Labels::DESIGN_NOT_SAVED ),
	);

	/**
	 * Pixonaut refusals the customer sees as no connection.
	 */
	private const UNAVAILABLE_ERRORS = array( ApiException::NOT_CONNECTED, ErrorCode::UNAUTHORIZED );

	/**
	 * @param string $reason One of the reason constants.
	 */
	public function __construct( public readonly string $reason ) {
		parent::__construct( sprintf( 'Design update refused: %s', $reason ) );
	}

	/**
	 * The refusal for a code Pixonaut refused a correction with.
	 *
	 * @param string $code The error code.
	 */
	public static function for_error( string $code ): self {
		if ( in_array( $code, array( self::REVISION_CHANGED, self::PROOF_EXPIRED, self::NOT_EDITABLE ), true ) ) {
			return new self( $code );
		}
		return new self( in_array( $code, self::UNAVAILABLE_ERRORS, true ) ? self::UNAVAILABLE : self::NOT_SAVED );
	}

	public function status(): int {
		return self::ANSWERS[ $this->reason ][0] ?? self::ANSWERS[ self::NOT_SAVED ][0];
	}

	/**
	 * The key from `Labels` of the message the customer sees.
	 */
	public function label(): string {
		return self::ANSWERS[ $this->reason ][1] ?? Labels::DESIGN_NOT_SAVED;
	}
}
