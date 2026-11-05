<?php
/**
 * Error codes the client knows.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api;

/**
 * Pixonaut's error codes are an open set: a code missing here is still
 * passed on in `ApiError::$code`, and logged so it gets noticed. The area
 * class that handles a code adds it here.
 */
final class ErrorCodes {

	// Refusals any operation may answer (the spec's `x-refusals`).
	public const UNAUTHORIZED        = 'unauthorized';
	public const FORBIDDEN           = 'forbidden';
	public const SECRET_KEY_REQUIRED = 'secret_key_required';
	public const ORIGIN_FORBIDDEN    = 'origin_forbidden';
	public const FEATURE_DISABLED    = 'feature_disabled';
	public const PAYLOAD_TOO_LARGE   = 'payload_too_large';
	public const RATE_LIMITED        = 'rate_limited';
	public const DB_ERROR            = 'db_error';
	public const WORKSPACE_SUSPENDED = 'workspace_suspended';

	// `PUT /v1/connect/store-access`: the studio's request it answers is no longer the current one.
	public const STORE_ACCESS_REQUEST_STALE = 'store_access_request_stale';

	public const BAD_REQUEST       = 'bad_request';
	public const NOT_FOUND         = 'not_found';
	public const ORDER_NOT_FOUND   = 'order_not_found';
	public const OPERATION_REMOVED = 'operation_removed';

	// `POST /v1/connect/token`: the code is unknown, used, expired, or does not match the verifier or `redirect_uri`.
	public const INVALID_GRANT = 'invalid_grant';
	// `POST /v1/connect/token`: the workspace's plan connects no more stores (`limit` `connectedStores`, `max`, `plan`).
	public const PLAN_LIMIT_REACHED = 'plan_limit_reached';

	// Refusals of a design commit.
	public const PROOF_ALREADY_COMMITTED = 'proof_already_committed';
	public const PROOF_EXPIRED           = 'proof_expired';
	public const UNKNOWN_PROOF           = 'unknown_proof';

	// Refusals of a proof image: `v` names another proof than the design's, or its file is gone.
	public const PROOF_CHANGED   = 'proof_changed';
	public const PROOF_NOT_FOUND = 'proof_not_found';

	// A template picture's `showcase` names no set of the template.
	public const SHOWCASE_NOT_FOUND = 'showcase_not_found';

	// Printshop routing refusals: a printshop other than the blank's was named, the printshop is
	// disconnected, or a print on no blank has no printshop to go to.
	public const PRINTSHOP_DIFFERS_FROM_BLANK = 'printshop_differs_from_blank';
	public const PRINTSHOP_DISCONNECTED       = 'printshop_disconnected';
	public const PRINT_NEEDS_PRINTSHOP        = 'print_needs_printshop';

	// An order's variant (`variant` on `POST /v1/orders`).
	public const VARIANT_NOT_OF_BLANK = 'variant_not_of_blank';
	public const VARIANT_RETIRED      = 'variant_retired';
	public const VARIANT_REQUIRED     = 'variant_required';

	// A design refused for what it holds; each violation names its `rule`, such as a print size's `wrong_ratio`.
	public const CONSTRAINT_VIOLATION = 'constraint_violation';
	public const FIELD_VALUE_MISSING  = 'field_value_missing';

	// Refusals of an order's prints and address (`POST /v1/orders`): a print at a size its position
	// does not print, a print larger than the printshop's machine takes, or a saved address that is gone.
	public const PRINT_NOT_POSITION_SIZE = 'print_not_position_size';
	public const PRINT_EXCEEDS_MACHINE   = 'print_exceeds_machine';
	public const SAVED_ADDRESS_NOT_FOUND = 'saved_address_not_found';

	// `PATCH /v1/connect`: the brand asked for is not one of the workspace's.
	public const BRAND_NOT_FOUND = 'brand_not_found';

	// Refusals of a link: a size menu entry the side's position cannot print or a position the
	// blank does not have (designer links), a name another link holds, or a link that made as
	// many designs as its limit allows for now.
	public const PRINT_SIZE_ILLEGAL    = 'print_size_illegal';
	public const UNKNOWN_PLACEMENT     = 'unknown_placement';
	public const SHARE_LINK_NAME_TAKEN = 'share_link_name_taken';
	public const LINK_DESIGN_LIMIT     = 'link_design_limit';

	// A design that went on cannot be deleted any more.
	public const DESIGN_APPROVED = 'design_approved';
	public const DESIGN_PRINTED  = 'design_printed';
	// An order's `failureKind`: Pixonaut failed it while it waited for its prints.
	public const PRINT_FAILED     = 'print_failed';
	public const PRINTS_TIMED_OUT = 'prints_timed_out';
	// A write waited too long for a lock Pixonaut holds; the same request may succeed later.
	public const LOCK_TIMEOUT = 'lock_timeout';
	// The design's personal data was erased: it can no longer be printed or ordered.
	public const DESIGN_ERASED = 'design_erased';

	// Refusals of an approval: the design is not waiting for one at that revision, or changed since.
	public const NOT_COMMITTED    = 'not_committed';
	public const REVISION_CHANGED = 'revision_changed';

	// Refusals of a correction: the design takes none (rejected, or ordered), or the proof corrects another design.
	public const NOT_EDITABLE         = 'not_editable';
	public const PROOF_NOT_FOR_DESIGN = 'proof_not_for_design';

	// Refusals of a standing design's layout adjustment: the design is no standing design, the proof is no
	// layout adjustment's, or it adjusts a design of another key, template version, placement, size or row.
	public const NOT_STANDING          = 'not_standing';
	public const NOT_A_LAYOUT_PROOF    = 'not_a_layout_proof';
	public const LAYOUT_PROOF_MISMATCH = 'layout_proof_mismatch';

	// `POST /v1/webhooks/{id}/deliveries/redeliver-failed`: the endpoint's address did not answer when last checked.
	public const ENDPOINT_UNREACHABLE = 'endpoint_unreachable';
	// `GET /v1/events`: the `after` cursor is no event Pixonaut keeps.
	public const UNKNOWN_EVENT = 'unknown_event';
	// `POST /v1/orders`: the design has a live order (`orderId`) and the body did not ask for a `reorder`.
	public const DESIGN_ALREADY_ORDERED = 'design_already_ordered';
	// `POST /v1/orders/{id}/retry`: an earlier retry placed `newerOrderId`; or the order is not failed.
	public const ORDER_SUPERSEDED    = 'order_superseded';
	public const ORDER_NOT_RETRYABLE = 'order_not_retryable';
	// `POST /v1/orders/{id}/retry`: a print a reroute names is not finished (`status`; a plain retry draws a
	// failed print again and waits for one still being drawn), was drawn again since, or its files are gone;
	// the print or its ticket changed while the order was placed; or the printshop is paused (`reason`).
	public const PRINT_NOT_COMPLETED = 'print_not_completed';
	public const PRINT_NOT_FOUND     = 'print_not_found';
	public const PRINT_SUPERSEDED    = 'print_superseded';
	public const PRINT_FILES_DELETED = 'print_files_deleted';
	public const PRINT_TICKET_STALE  = 'print_ticket_stale';
	public const STALE_UPDATE        = 'stale_update';
	public const PRINTSHOP_PAUSED    = 'printshop_paused';
	// Placement refusals of an order and its retry: the printshop cannot take it as it is.
	public const UNKNOWN_PRINTSHOP         = 'unknown_printshop';
	public const PRINTSHOP_UNAVAILABLE     = 'printshop_unavailable';
	public const PRINTSHOP_CANNOT_COMBINE  = 'printshop_cannot_combine';
	public const MISSING_FULFILMENT_KEYS   = 'missing_fulfilment_keys';
	public const SPOT_PLATE_REQUIRED       = 'spot_plate_required';
	public const PRINT_PROFILE_UNAVAILABLE = 'print_profile_unavailable';
	public const PRINT_RASTER_TOO_LARGE    = 'print_raster_too_large';
	public const TECHNIQUE_NOT_OFFERED     = 'technique_not_offered';
	public const TECHNIQUE_DIFFERS         = 'technique_differs';
	public const TECHNIQUE_UNKNOWN         = 'technique_unknown';
	public const PRINT_EXCEEDS_POSITION    = 'print_exceeds_position';
	public const PRINT_EXCEEDS_PLACEMENT   = 'print_exceeds_placement';
	public const PRINT_SHORT_OF_AREA       = 'print_short_of_area';
	public const VARIANT_NOT_OF_DESIGN_ROW = 'variant_not_of_design_row';
	// Placement refusals of an order's prints and the blank's placements they go on.
	public const PLACEMENT_UNFILLED        = 'placement_unfilled';
	public const PLACEMENT_NOT_OF_BLANK    = 'placement_not_of_blank';
	public const PLACEMENT_TAKEN           = 'placement_taken';
	public const PLACEMENT_NAME_DIFFERS    = 'placement_name_differs';
	public const PRINT_WITHOUT_PLACEMENT   = 'print_without_placement';
	public const DUPLICATE_PLACEMENT       = 'duplicate_placement';
	public const MIXED_BLANKS              = 'mixed_blanks';
	public const PRINTS_DISAGREE           = 'prints_disagree';
	public const PRINT_COMMITTED_ELSEWHERE = 'print_committed_elsewhere';
	// A retry's `reroute` to another blank, which the store never sends.
	public const REROUTE_BLANK_NOT_FOUND = 'reroute_blank_not_found';
	public const REROUTE_SAME_BLANK      = 'reroute_same_blank';
	public const PRINT_NOT_OF_ORDER      = 'print_not_of_order';
	public const PRINT_NOT_MAPPED        = 'print_not_mapped';
	public const PRINT_MAPPED_TWICE      = 'print_mapped_twice';
	// `PATCH /v1/orders/{id}`: the order moved since its `statusMoves` were read; the reference is another order's.
	public const ORDER_STATUS_NOT_ALLOWED     = 'order_status_not_allowed';
	public const ORDER_REFERENCE_TAKEN        = 'order_reference_taken';
	public const ORDER_STATUS_REASON_REQUIRED = 'order_status_reason_required';
	// `POST /v1/orders/{id}/cancel`: the printshop has the order (`cause` says how far), its submission is unanswered, or the printshop could not be reached (`retryable` says whether asking again may work).
	public const ORDER_AT_PRINTSHOP = 'order_at_printshop';
	public const ORDER_IN_FLIGHT    = 'order_in_flight';
	public const CANCEL_UNAVAILABLE = 'cancel_unavailable';
	// `PATCH /v1/orders/{id}/delivery`: the order is too far along (`status` says where), its printshop takes no address changes (`printshop` names it), the address lacks the postcode its printshop needs, or the printshop could not be told (`retryable` says whether asking again may work).
	public const ORDER_PAST_ADDRESS_CHANGE   = 'order_past_address_change';
	public const ADDRESS_CHANGE_NOT_ACCEPTED = 'address_change_not_accepted';
	public const POSTAL_CODE_REQUIRED        = 'postal_code_required';
	public const ADDRESS_CHANGE_UNAVAILABLE  = 'address_change_unavailable';

	public const IDEMPOTENCY_KEY_IN_USE  = 'idempotency_key_in_use';
	public const IDEMPOTENCY_KEY_REUSED  = 'idempotency_key_reused';
	public const INVALID_IDEMPOTENCY_KEY = 'invalid_idempotency_key';

	// The client's own codes, for failures that never reached Pixonaut or got no usable answer.
	public const NOT_CONNECTED       = 'not_connected';
	public const TRANSPORT_FAILED    = 'transport_failed';
	public const UNEXPECTED_RESPONSE = 'unexpected_response';

	/**
	 * Codes that mean the plugin built a broken idempotent request: a bug,
	 * never fixed by sending again.
	 */
	public const IDEMPOTENCY_BUGS = array( self::IDEMPOTENCY_KEY_REUSED, self::INVALID_IDEMPOTENCY_KEY );

	/**
	 * Refusals that say the design has no printshop to go to: the store
	 * cannot fix them by retrying, the merchant must in Pixonaut.
	 */
	public const ROUTING_REFUSALS = array( self::PRINTSHOP_DIFFERS_FROM_BLANK, self::PRINTSHOP_DISCONNECTED, self::PRINT_NEEDS_PRINTSHOP );

	/**
	 * Codes the plugin understands. `UNEXPECTED_RESPONSE` is left out, so an
	 * answer that carried no code is logged like an unknown one.
	 */
	public const KNOWN = array(
		self::UNAUTHORIZED,
		self::FORBIDDEN,
		self::SECRET_KEY_REQUIRED,
		self::ORIGIN_FORBIDDEN,
		self::FEATURE_DISABLED,
		self::PAYLOAD_TOO_LARGE,
		self::RATE_LIMITED,
		self::WORKSPACE_SUSPENDED,
		self::DB_ERROR,
		self::BAD_REQUEST,
		self::NOT_FOUND,
		self::ORDER_NOT_FOUND,
		self::OPERATION_REMOVED,
		self::INVALID_GRANT,
		self::PLAN_LIMIT_REACHED,
		self::PROOF_ALREADY_COMMITTED,
		self::PROOF_EXPIRED,
		self::UNKNOWN_PROOF,
		self::PROOF_CHANGED,
		self::PROOF_NOT_FOUND,
		self::PRINTSHOP_DIFFERS_FROM_BLANK,
		self::PRINTSHOP_DISCONNECTED,
		self::PRINT_NEEDS_PRINTSHOP,
		self::VARIANT_NOT_OF_BLANK,
		self::VARIANT_RETIRED,
		self::VARIANT_REQUIRED,
		self::CONSTRAINT_VIOLATION,
		self::FIELD_VALUE_MISSING,
		self::PRINT_NOT_POSITION_SIZE,
		self::PRINT_EXCEEDS_MACHINE,
		self::SAVED_ADDRESS_NOT_FOUND,
		self::BRAND_NOT_FOUND,
		self::PRINT_SIZE_ILLEGAL,
		self::UNKNOWN_PLACEMENT,
		self::SHARE_LINK_NAME_TAKEN,
		self::LINK_DESIGN_LIMIT,
		self::DESIGN_APPROVED,
		self::DESIGN_PRINTED,
		self::PRINT_FAILED,
		self::PRINTS_TIMED_OUT,
		self::LOCK_TIMEOUT,
		self::DESIGN_ERASED,
		self::NOT_COMMITTED,
		self::REVISION_CHANGED,
		self::NOT_EDITABLE,
		self::PROOF_NOT_FOR_DESIGN,
		self::NOT_STANDING,
		self::NOT_A_LAYOUT_PROOF,
		self::LAYOUT_PROOF_MISMATCH,
		self::UNKNOWN_EVENT,
		self::DESIGN_ALREADY_ORDERED,
		self::ORDER_SUPERSEDED,
		self::ORDER_NOT_RETRYABLE,
		self::PRINT_NOT_COMPLETED,
		self::PRINT_NOT_FOUND,
		self::PRINT_SUPERSEDED,
		self::PRINT_FILES_DELETED,
		self::PRINT_TICKET_STALE,
		self::STALE_UPDATE,
		self::PRINTSHOP_PAUSED,
		self::UNKNOWN_PRINTSHOP,
		self::PRINTSHOP_UNAVAILABLE,
		self::PRINTSHOP_CANNOT_COMBINE,
		self::MISSING_FULFILMENT_KEYS,
		self::SPOT_PLATE_REQUIRED,
		self::PRINT_PROFILE_UNAVAILABLE,
		self::PRINT_RASTER_TOO_LARGE,
		self::TECHNIQUE_NOT_OFFERED,
		self::TECHNIQUE_DIFFERS,
		self::TECHNIQUE_UNKNOWN,
		self::PRINT_EXCEEDS_POSITION,
		self::PRINT_EXCEEDS_PLACEMENT,
		self::PRINT_SHORT_OF_AREA,
		self::VARIANT_NOT_OF_DESIGN_ROW,
		self::PLACEMENT_UNFILLED,
		self::PLACEMENT_NOT_OF_BLANK,
		self::PLACEMENT_TAKEN,
		self::PLACEMENT_NAME_DIFFERS,
		self::PRINT_WITHOUT_PLACEMENT,
		self::DUPLICATE_PLACEMENT,
		self::MIXED_BLANKS,
		self::PRINTS_DISAGREE,
		self::PRINT_COMMITTED_ELSEWHERE,
		self::REROUTE_BLANK_NOT_FOUND,
		self::REROUTE_SAME_BLANK,
		self::PRINT_NOT_OF_ORDER,
		self::PRINT_NOT_MAPPED,
		self::PRINT_MAPPED_TWICE,
		self::ORDER_STATUS_NOT_ALLOWED,
		self::ORDER_REFERENCE_TAKEN,
		self::ORDER_STATUS_REASON_REQUIRED,
		self::ORDER_AT_PRINTSHOP,
		self::ORDER_IN_FLIGHT,
		self::CANCEL_UNAVAILABLE,
		self::ORDER_PAST_ADDRESS_CHANGE,
		self::ADDRESS_CHANGE_NOT_ACCEPTED,
		self::POSTAL_CODE_REQUIRED,
		self::ADDRESS_CHANGE_UNAVAILABLE,
		self::IDEMPOTENCY_KEY_IN_USE,
		self::IDEMPOTENCY_KEY_REUSED,
		self::INVALID_IDEMPOTENCY_KEY,
		self::NOT_CONNECTED,
		self::TRANSPORT_FAILED,
	);

	/**
	 * Error statuses the pinned spec declares. A unit test keeps the list in
	 * step with `contracts/pixonaut/openapi.json`; any other status is
	 * passed on and logged.
	 */
	public const KNOWN_STATUSES = array( 400, 401, 402, 403, 404, 409, 410, 422, 429, 499, 500, 502, 503, 507 );

	public static function is_known_status( int $status ): bool {
		return in_array( $status, self::KNOWN_STATUSES, true );
	}

	public static function is_known( string $code ): bool {
		return in_array( $code, self::KNOWN, true );
	}
}
