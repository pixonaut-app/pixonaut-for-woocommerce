<?php
/**
 * Why Pixonaut refused to retry an order, in words.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Fulfilment;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\ErrorCode;

/**
 * Every code `POST /v1/orders/{id}/retry` declares has words a merchant can
 * act on; a unit test keeps the list in step with the pinned spec. A code
 * this version does not know is named as it is, so a refused retry never
 * looks like nothing happened.
 */
final class RetryRefusal {

	/**
	 * Why the retry was refused, in words a merchant can act on.
	 *
	 * @param string $code Pixonaut's error code.
	 */
	public static function explain( string $code ): string {
		return self::words( $code ) ?? sprintf(
			/* translators: %s: the error code Pixonaut answered, e.g. order_at_printshop. */
			__( 'Pixonaut refused to place the order again with "%s". Nothing was changed.', 'pixonaut-for-woocommerce' ),
			$code
		);
	}

	/**
	 * The words for a code this version knows; null for any other.
	 *
	 * @param string $code Pixonaut's error code.
	 */
	public static function words( string $code ): ?string {
		return match ( $code ) {
			ErrorCode::PRINT_NOT_COMPLETED       => __( 'The print is still being made. Try again once it is finished.', 'pixonaut-for-woocommerce' ),
			ErrorCode::ORDER_NOT_RETRYABLE       => __( 'The printshop order is no longer failed in Pixonaut, so it was not placed again.', 'pixonaut-for-woocommerce' ),
			ErrorCode::ORDER_AT_PRINTSHOP        => __( 'The printshop has the order already, so it is not placed again.', 'pixonaut-for-woocommerce' ),
			ErrorCode::ORDER_SUPERSEDED          => __( 'The order was placed again already, by an earlier retry.', 'pixonaut-for-woocommerce' ),
			ErrorCode::PRINT_SUPERSEDED          => __( 'The design changed in Pixonaut since its print was made, so the print is no longer the design\'s. Check the design in Pixonaut.', 'pixonaut-for-woocommerce' ),
			ErrorCode::PRINT_FILES_DELETED       => __( 'The print\'s files were deleted in Pixonaut, so it cannot be sent to the printshop again.', 'pixonaut-for-woocommerce' ),
			ErrorCode::DESIGN_ALREADY_ORDERED    => __( 'The design has another printshop order in Pixonaut that this item did not place, for example one placed in the studio. Check that order in Pixonaut, and cancel it there or settle the item with the customer.', 'pixonaut-for-woocommerce' ),
			ErrorCode::PRINTSHOP_PAUSED          => __( 'The printshop is paused in Pixonaut. Resume it in Pixonaut, then retry.', 'pixonaut-for-woocommerce' ),
			ErrorCode::PRINT_TICKET_STALE,
			ErrorCode::STALE_UPDATE,
			ErrorCode::LOCK_TIMEOUT,
			ErrorCode::DB_ERROR                  => __( 'Pixonaut was busy with the order and changed nothing. Try again in a moment.', 'pixonaut-for-woocommerce' ),
			ErrorCode::NOT_FOUND,
			ErrorCode::ORDER_NOT_FOUND,
			ErrorCode::PRINT_NOT_FOUND           => __( 'Pixonaut no longer has the printshop order or its print. Check the order in Pixonaut.', 'pixonaut-for-woocommerce' ),
			ErrorCode::BAD_REQUEST,
			ErrorCode::PAYLOAD_TOO_LARGE         => __( 'Pixonaut did not accept the request the store sent. Check the Pixonaut log under WooCommerce > Status > Logs.', 'pixonaut-for-woocommerce' ),
			ErrorCode::FORBIDDEN,
			ErrorCode::ORIGIN_FORBIDDEN          => __( 'The store\'s key may not place orders again in Pixonaut. Check the key in Pixonaut, or reconnect the store.', 'pixonaut-for-woocommerce' ),
			ErrorCode::FEATURE_DISABLED          => __( 'Printshop orders are turned off for the workspace in Pixonaut.', 'pixonaut-for-woocommerce' ),
			ErrorCode::WORKSPACE_SUSPENDED       => __( 'The Pixonaut workspace is suspended. Check its billing in Pixonaut, then retry.', 'pixonaut-for-woocommerce' ),
			ErrorCode::PLAN_LIMIT_REACHED        => __( 'The workspace\'s plan in Pixonaut allows no more of this. Check the plan in Pixonaut, then retry.', 'pixonaut-for-woocommerce' ),
			ErrorCode::UNKNOWN_PRINTSHOP,
			ErrorCode::PRINTSHOP_UNAVAILABLE     => __( 'The printshop the order went to is no longer available in Pixonaut. Check the printshop in Pixonaut, then retry.', 'pixonaut-for-woocommerce' ),
			ErrorCode::PRINTSHOP_CANNOT_COMBINE,
			ErrorCode::SPOT_PLATE_REQUIRED,
			ErrorCode::PRINT_PROFILE_UNAVAILABLE,
			ErrorCode::PRINT_RASTER_TOO_LARGE,
			ErrorCode::TECHNIQUE_NOT_OFFERED,
			ErrorCode::TECHNIQUE_DIFFERS,
			ErrorCode::TECHNIQUE_UNKNOWN         => __( 'The printshop cannot print the item as it is: it does not offer the print\'s method or colour output, or the print file is too large for it. Check the printshop and the blank in Pixonaut, then retry.', 'pixonaut-for-woocommerce' ),
			ErrorCode::PLACEMENT_UNFILLED,
			ErrorCode::PLACEMENT_NOT_OF_BLANK,
			ErrorCode::PLACEMENT_TAKEN,
			ErrorCode::PLACEMENT_NAME_DIFFERS,
			ErrorCode::PRINT_WITHOUT_PLACEMENT,
			ErrorCode::DUPLICATE_PLACEMENT,
			ErrorCode::MIXED_BLANKS,
			ErrorCode::PRINTS_DISAGREE,
			ErrorCode::PRINT_COMMITTED_ELSEWHERE => __( 'The item\'s prints do not fit the blank\'s placements in Pixonaut: a placement the blank must print is empty, or prints share or miss one. Check the blank\'s placements in Pixonaut, then retry.', 'pixonaut-for-woocommerce' ),
			ErrorCode::REROUTE_BLANK_NOT_FOUND,
			ErrorCode::REROUTE_SAME_BLANK,
			ErrorCode::PRINT_NOT_OF_ORDER,
			ErrorCode::PRINT_NOT_MAPPED,
			ErrorCode::PRINT_MAPPED_TWICE        => __( 'Pixonaut refused moving the order to another blank. Place it again as it is, or move it in Pixonaut.', 'pixonaut-for-woocommerce' ),
			ErrorCode::POSTAL_CODE_REQUIRED      => __( 'The delivery address lacks the postcode the printshop needs. Add it to the order\'s shipping address, then retry.', 'pixonaut-for-woocommerce' ),
			ErrorCode::SAVED_ADDRESS_NOT_FOUND   => __( 'The saved address the order ships to is gone in Pixonaut. Check the order\'s shipping address, then retry.', 'pixonaut-for-woocommerce' ),
			ErrorCode::MISSING_FULFILMENT_KEYS   => NeedsAttention::explain( ErrorCode::FIELD_VALUE_MISSING ),
			ErrorCode::PRINT_EXCEEDS_POSITION,
			ErrorCode::PRINT_EXCEEDS_PLACEMENT,
			ErrorCode::PRINT_SHORT_OF_AREA       => NeedsAttention::explain( ErrorCode::PRINT_NOT_POSITION_SIZE ),
			ErrorCode::VARIANT_NOT_OF_DESIGN_ROW => NeedsAttention::explain( ErrorCode::VARIANT_NOT_OF_BLANK ),
			// Shared with placing the order the first time.
			ErrorCode::UNAUTHORIZED,
			ErrorCode::DESIGN_ERASED,
			ErrorCode::PRINT_NEEDS_PRINTSHOP,
			ErrorCode::PRINTSHOP_DIFFERS_FROM_BLANK,
			ErrorCode::PRINTSHOP_DISCONNECTED,
			ErrorCode::PRINT_NOT_POSITION_SIZE,
			ErrorCode::PRINT_EXCEEDS_MACHINE,
			ErrorCode::FIELD_VALUE_MISSING,
			ErrorCode::VARIANT_NOT_OF_BLANK,
			ErrorCode::VARIANT_RETIRED,
			ErrorCode::VARIANT_REQUIRED          => NeedsAttention::explain( $code ),
			default                               => null,
		};
	}
}
